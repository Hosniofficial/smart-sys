<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/database/migrations/040_add_missing_data_integrity_constraints.php';

use PHPUnit\Framework\TestCase;
use Tests\Integration\Support\MySqlSchemaDumpImporter;

final class DataIntegrityMigrationMySqlIntegrationTest extends TestCase
{
    private static ?mysqli $server = null;
    private static ?PDO $db = null;
    private static ?string $schema = null;

    public static function setUpBeforeClass(): void
    {
        if (getenv('RUN_MYSQL_MIGRATION_TESTS') !== '1') {
            self::markTestSkipped(
                'Set RUN_MYSQL_MIGRATION_TESTS=1 to create an isolated temporary MySQL schema.'
            );
        }

        \Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
        $host = (string) ($_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: 'localhost');
        if (!in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true)) {
            self::fail('Refusing migration integration tests unless DB_HOST is local.');
        }

        $port = (int) ($_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: 3306);
        $username = (string) ($_ENV['DB_USERNAME'] ?? getenv('DB_USERNAME') ?: 'root');
        $password = (string) ($_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?: '');
        self::$schema = 'smartsys_integrity_it_' . bin2hex(random_bytes(8));
        self::$server = new mysqli($host, $username, $password, '', $port);
        self::$server->set_charset('utf8mb4');
        self::$server->query(
            'CREATE DATABASE `' . self::$schema . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );
        self::$server->select_db(self::$schema);

        try {
            MySqlSchemaDumpImporter::import(
                self::$server,
                __DIR__ . '/Fixtures/mysql_schema.sql'
            );
            self::$db = new PDO(
                "mysql:host={$host};port={$port};dbname=" . self::$schema . ';charset=utf8mb4',
                $username,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (Throwable $error) {
            self::dropTemporarySchema();
            throw $error;
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::dropTemporarySchema();
    }

    public function testAddsReviewedConstraintsAndPreservesExpectedData(): void
    {
        self::assertInstanceOf(PDO::class, self::$db);
        self::$db->exec(
            "INSERT INTO users (id, username, password, name)
             VALUES (4, 'migration-test-user', 'not-a-real-password', 'Migration Test')"
        );
        self::$db->exec(
            "INSERT INTO security_events (id, event_type, description, user_id)
             VALUES
                 (30, 'user.login', 'Known orphan event', 3),
                 (31, 'user.login', 'Valid linked event', 4)"
        );

        $migration = new AddMissingDataIntegrityConstraints(self::$db);
        $migration->up();
        $migration->up();

        $events = self::$db->query(
            'SELECT id, user_id FROM security_events WHERE id IN (30, 31) ORDER BY id'
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        self::assertNull($events[30]);
        self::assertSame(4, (int) $events[31]);

        $constraints = self::$db->query(
            "SELECT COUNT(*)
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND ((TABLE_NAME = 'account_device_associations'
                     AND COLUMN_NAME = 'tenant_id' AND REFERENCED_TABLE_NAME = 'tenants')
                    OR (TABLE_NAME = 'cashier_sessions'
                        AND COLUMN_NAME = 'shift_id' AND REFERENCED_TABLE_NAME = 'cashier_shifts')
                    OR (TABLE_NAME = 'payment_idempotency_keys'
                        AND COLUMN_NAME = 'tenant_id' AND REFERENCED_TABLE_NAME = 'tenants')
                    OR (TABLE_NAME = 'warranty_requests'
                        AND COLUMN_NAME = 'invoice_id' AND REFERENCED_TABLE_NAME = 'sales'))"
        );
        self::assertSame(4, (int) $constraints->fetchColumn());

        $polymorphicConstraints = self::$db->query(
            "SELECT COUNT(*)
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND ((TABLE_NAME = 'payment_applications' AND COLUMN_NAME = 'reference_id')
                    OR (TABLE_NAME = 'permission_overrides' AND COLUMN_NAME = 'subject_id')
                    OR (TABLE_NAME = 'payment_idempotency_keys' AND COLUMN_NAME = 'payment_id'))
               AND REFERENCED_TABLE_NAME IS NOT NULL"
        );
        self::assertSame(0, (int) $polymorphicConstraints->fetchColumn());

        $remainingComments = self::$db->query(
            "SELECT COUNT(*)
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME IN (
                   'branch_products', 'inventory_cost_snapshot', 'journal_entries',
                   'migrations', 'notifications', 'notification_batches',
                   'notification_preferences', 'owner_contributions', 'payments',
                   'payment_applications', 'payment_methods', 'products',
                   'product_activation_log', 'purchases', 'returns',
                   'security_events', 'stock_transfers'
               )
               AND COLUMN_COMMENT <> ''"
        );
        self::assertSame(0, (int) $remainingComments->fetchColumn());

        $shiftType = self::$db->query(
            "SELECT COLUMN_TYPE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'cashier_sessions' AND COLUMN_NAME = 'shift_id'"
        );
        self::assertSame('bigint(20) unsigned', strtolower((string) $shiftType->fetchColumn()));

        $columnMetadata = self::$db->query(
            "SELECT COLUMN_NAME, COLUMN_TYPE, COLUMN_DEFAULT
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND ((TABLE_NAME = 'notification_preferences' AND COLUMN_NAME = 'delivery_mode')
                    OR (TABLE_NAME = 'notifications' AND COLUMN_NAME = 'priority')
                    OR (TABLE_NAME = 'warranty_requests' AND COLUMN_NAME = 'customer_id'))"
        )->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
        self::assertSame("'batched'", $columnMetadata['delivery_mode']['COLUMN_DEFAULT']);
        self::assertSame("'normal'", $columnMetadata['priority']['COLUMN_DEFAULT']);
        self::assertSame('int(11)', strtolower($columnMetadata['customer_id']['COLUMN_TYPE']));
    }

    private static function dropTemporarySchema(): void
    {
        if (self::$server instanceof mysqli && self::$schema !== null) {
            self::$server->query('DROP DATABASE IF EXISTS `' . self::$schema . '`');
            self::$server->close();
        }
    }
}
