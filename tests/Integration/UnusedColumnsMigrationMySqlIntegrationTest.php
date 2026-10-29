<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/database/migrations/041_remove_reviewed_unused_columns.php';

use PHPUnit\Framework\TestCase;
use Tests\Integration\Support\MySqlSchemaDumpImporter;

final class UnusedColumnsMigrationMySqlIntegrationTest extends TestCase
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
        self::$schema = 'smartsys_unused_columns_it_' . bin2hex(random_bytes(8));
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

    public function testRefusesDataLossThenRemovesOnlyReviewedColumns(): void
    {
        self::assertInstanceOf(PDO::class, self::$db);
        self::$db->exec(
            "INSERT INTO device_fingerprints
             (fingerprint_hash, ip_address, user_agent_hash, associated_accounts)
             VALUES ('migration-test-fingerprint', '127.0.0.1', 'test-agent-hash', '[]')"
        );

        $migration = new RemoveReviewedUnusedColumns(self::$db);
        try {
            $migration->up();
            self::fail('Migration should refuse to drop a column that contains data.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('associated_accounts', $error->getMessage());
        }

        self::$db->exec('DELETE FROM device_fingerprints');
        self::$db->exec(
            'CREATE PROCEDURE migration_test_unused_column_reference()
             SELECT close_reason FROM cashier_sessions LIMIT 1'
        );
        try {
            $migration->up();
            self::fail('Migration should refuse to drop a column referenced by a stored routine.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('routine migration_test_unused_column_reference', $error->getMessage());
        } finally {
            self::$db->exec('DROP PROCEDURE IF EXISTS migration_test_unused_column_reference');
        }

        $migration->up();
        $migration->up();

        $remainingColumns = self::$db->query(
            "SELECT COUNT(*)
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND (
                   (TABLE_NAME = 'account_device_associations'
                    AND COLUMN_NAME IN (
                        'first_associated_at', 'last_associated_at', 'association_count', 'blocked_reason'
                    ))
                   OR (TABLE_NAME = 'cashier_sessions' AND COLUMN_NAME = 'close_reason')
                   OR (TABLE_NAME = 'device_fingerprints'
                       AND COLUMN_NAME IN ('associated_accounts', 'last_seen'))
                   OR (TABLE_NAME = 'product_activation_log' AND COLUMN_NAME = 'event_description')
                   OR (TABLE_NAME = 'subscriptions' AND COLUMN_NAME = 'cancelled_at')
               )"
        );
        self::assertSame(0, (int) $remainingColumns->fetchColumn());

        $preservedColumns = self::$db->query(
            "SELECT COUNT(*)
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND ((TABLE_NAME = 'device_fingerprints' AND COLUMN_NAME = 'last_seen_at')
                    OR (TABLE_NAME = 'subscriptions' AND COLUMN_NAME = 'status')
                    OR (TABLE_NAME = 'cashier_sessions' AND COLUMN_NAME = 'status'))"
        );
        self::assertSame(3, (int) $preservedColumns->fetchColumn());
    }

    private static function dropTemporarySchema(): void
    {
        if (self::$server instanceof mysqli && self::$schema !== null) {
            self::$server->query('DROP DATABASE IF EXISTS `' . self::$schema . '`');
            self::$server->close();
        }
    }
}
