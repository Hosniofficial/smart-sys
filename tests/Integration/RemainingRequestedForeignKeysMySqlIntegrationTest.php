<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/database/migrations/043_add_remaining_requested_foreign_keys.php';

use PHPUnit\Framework\TestCase;
use Tests\Integration\Support\MySqlSchemaDumpImporter;

final class RemainingRequestedForeignKeysMySqlIntegrationTest extends TestCase
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
        self::$schema = 'smartsys_fk043_it_' . bin2hex(random_bytes(8));
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

    public function testAddsReviewedRelationshipsAndAlignsSupplierBranchType(): void
    {
        self::assertInstanceOf(PDO::class, self::$db);
        $migration = new AddRemainingRequestedForeignKeys(self::$db);
        $migration->up();
        $migration->up();

        $foreignKeys = self::$db->query(
            "SELECT TABLE_NAME, REFERENCED_TABLE_NAME
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND REFERENCED_TABLE_NAME IS NOT NULL
               AND ((TABLE_NAME = 'suppliers' AND COLUMN_NAME = 'branch_id')
                    OR (TABLE_NAME = 'product_units' AND COLUMN_NAME = 'tenant_id')
                    OR (TABLE_NAME = 'sales_items' AND COLUMN_NAME = 'tenant_id')
                    OR (TABLE_NAME = 'rate_limiting_tracking' AND COLUMN_NAME = 'rule_id'))
             ORDER BY TABLE_NAME"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        self::assertSame([
            'product_units' => 'tenants',
            'rate_limiting_tracking' => 'rate_limiting_rules',
            'sales_items' => 'tenants',
            'suppliers' => 'branches',
        ], $foreignKeys);

        $supplierBranchType = self::$db->query(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'suppliers' AND COLUMN_NAME = 'branch_id'"
        )->fetchColumn();
        self::assertSame('int(11)', strtolower((string) $supplierBranchType));

        $this->expectException(PDOException::class);
        self::$db->exec(
            "INSERT INTO suppliers (name, email, address, branch_id)
             VALUES ('Migration FK Test', 'migration-fk@example.test', 'Test address', 999999)"
        );
    }

    private static function dropTemporarySchema(): void
    {
        if (self::$server instanceof mysqli && self::$schema !== null) {
            self::$server->query('DROP DATABASE IF EXISTS `' . self::$schema . '`');
            self::$server->close();
        }
    }
}
