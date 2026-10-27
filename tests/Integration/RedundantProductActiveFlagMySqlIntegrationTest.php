<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/database/migrations/044_remove_redundant_product_active_flag.php';

use PHPUnit\Framework\TestCase;
use Tests\Integration\Support\MySqlSchemaDumpImporter;

final class RedundantProductActiveFlagMySqlIntegrationTest extends TestCase
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
        self::$schema = 'smartsys_active_flag_it_' . bin2hex(random_bytes(8));
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

    protected function setUp(): void
    {
        self::assertInstanceOf(PDO::class, self::$db);
        self::$db->exec('DELETE FROM products');
    }

    public function testRefusesMismatchedValuesAndThenRemovesOnlyTheDuplicateFlag(): void
    {
        self::assertInstanceOf(PDO::class, self::$db);
        self::$db->exec(
            "INSERT INTO products (id, name, sale_price, product_code, active, is_active_product)
             VALUES (94001, 'Migration test active product', 10, 'MIG-94001', 1, 0)"
        );

        $migration = new RemoveRedundantProductActiveFlag(self::$db);
        try {
            $migration->up();
            self::fail('Migration should refuse to remove a flag that differs from active.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('differ from products.active', $error->getMessage());
        }

        self::$db->exec('UPDATE products SET is_active_product = active WHERE id = 94001');
        $migration->up();
        $migration->up();

        self::assertSame(
            0,
            (int) self::$db->query(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'products' AND COLUMN_NAME = 'is_active_product'"
            )->fetchColumn()
        );
        self::assertSame(
            0,
            (int) self::$db->query(
                "SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'products' AND INDEX_NAME = 'idx_is_active_product'"
            )->fetchColumn()
        );
        $product = self::$db->query(
            'SELECT active FROM products WHERE id = 94001'
        )->fetchColumn();
        self::assertSame(1, (int) $product);
    }

    private static function dropTemporarySchema(): void
    {
        if (self::$server instanceof mysqli && self::$schema !== null) {
            self::$server->query('DROP DATABASE IF EXISTS `' . self::$schema . '`');
            self::$server->close();
        }
    }
}
