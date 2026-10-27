<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/database/migrations/045_drop_confirmed_unused_tables.php';

use PHPUnit\Framework\TestCase;
use Tests\Integration\Support\MySqlSchemaDumpImporter;

final class ConfirmedUnusedTablesMySqlIntegrationTest extends TestCase
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
        self::$schema = 'smartsys_unused_tables_it_' . bin2hex(random_bytes(8));
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

    public function testRefusesPopulatedTargetsThenDropsOnlyTheConfirmedEmptyTables(): void
    {
        self::assertInstanceOf(PDO::class, self::$db);
        self::$db->exec(
            "INSERT INTO users (id, username, password, name)
             VALUES (94002, 'unused-table-test-user', 'not-a-real-password', 'Migration Test')"
        );
        self::$db->exec(
            'INSERT INTO user_preferences (user_id) VALUES (94002)'
        );

        $migration = new DropConfirmedUnusedTables(self::$db);
        try {
            $migration->up();
            self::fail('Migration should refuse to drop a target table containing data.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('user_preferences; it contains data', $error->getMessage());
        }

        self::assertSame(4, $this->countTargetTables());
        self::$db->exec('DELETE FROM users WHERE id = 94002');

        $migration->up();
        $migration->up();

        self::assertSame(0, $this->countTargetTables());
        self::assertSame(
            1,
            (int) self::$db->query(
                "SELECT COUNT(*)
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'device_fingerprints'"
            )->fetchColumn()
        );
    }

    private function countTargetTables(): int
    {
        return (int) self::$db->query(
            "SELECT COUNT(*)
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME IN (
                   'documents', 'payment_transactions',
                   'user_preferences', 'account_device_associations'
               )"
        )->fetchColumn();
    }

    private static function dropTemporarySchema(): void
    {
        if (self::$server instanceof mysqli && self::$schema !== null) {
            self::$server->query('DROP DATABASE IF EXISTS `' . self::$schema . '`');
            self::$server->close();
        }
    }
}
