<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/database/migrations/042_finalize_schema_requirement_cleanup.php';

use PHPUnit\Framework\TestCase;
use Tests\Integration\Support\MySqlSchemaDumpImporter;

final class SchemaRequirementCleanupMySqlIntegrationTest extends TestCase
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
        self::$schema = 'smartsys_schema_cleanup_it_' . bin2hex(random_bytes(8));
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
        self::$db->exec(
            "DELETE FROM permissions
             WHERE name IN (
                 'accounting.owner_contribution.create',
                 'accounting.owner_contribution.view',
                 'accounting.owner_contribution.void',
                 'product.purchase_price.view',
                 'sale.discount'
             )"
        );
        self::$db->exec(
            "ALTER TABLE `cash_vouchers`
             MODIFY COLUMN `cost_center_id` INT NULL DEFAULT NULL COMMENT 'مركز التكلفة (اختياري)'"
        );
    }

    public function testStandardizesDescriptionsAndRemovesOnlyTheExpectedComment(): void
    {
        self::assertInstanceOf(PDO::class, self::$db);
        $descriptions = [
            'accounting.owner_contribution.create' =>
                'إنشاء وتسجيل مساهمة مالك (رأس مال، قرض مالك، رصيد افتتاحي)',
            'accounting.owner_contribution.view' => 'عرض وقراءة سجلات مساهمات المالك',
            'accounting.owner_contribution.void' => 'إلغاء مساهمة مالك موجودة (عكس القيد المحاسبي)',
            'product.purchase_price.view' => 'عرض سعر شراء المنتج ومعلومات التكلفة',
            'sale.discount' => 'السماح بتطبيق خصم على البيع ضمن حدود المنتج',
        ];
        $insert = self::$db->prepare(
            'INSERT INTO permissions (name, description, category) VALUES (?, ?, ?)'
        );
        foreach ($descriptions as $name => $description) {
            $insert->execute([$name, $description, 'test']);
        }

        $migration = new FinalizeSchemaRequirementCleanup(self::$db);
        $migration->up();
        $migration->up();

        $updatedDescriptions = self::$db->query(
            "SELECT name, description FROM permissions
             WHERE name IN (
                 'accounting.owner_contribution.create',
                 'accounting.owner_contribution.view',
                 'accounting.owner_contribution.void',
                 'product.purchase_price.view',
                 'sale.discount'
             )
             ORDER BY name"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        self::assertSame([
            'accounting.owner_contribution.create' =>
                'Create and record an owner contribution (capital, owner loan, opening balance)',
            'accounting.owner_contribution.view' => 'View owner contribution records',
            'accounting.owner_contribution.void' =>
                'Void an existing owner contribution (reverse the journal entry)',
            'product.purchase_price.view' => 'View product purchase prices and cost information',
            'sale.discount' => 'Apply sales discounts within product limits',
        ], $updatedDescriptions);

        $columnMetadata = self::$db->query(
            "SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_COMMENT
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'cash_vouchers' AND COLUMN_NAME = 'cost_center_id'"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame('int(11)', strtolower($columnMetadata['COLUMN_TYPE']));
        self::assertSame('YES', $columnMetadata['IS_NULLABLE']);
        self::assertTrue(in_array($columnMetadata['COLUMN_DEFAULT'], [null, 'NULL'], true));
        self::assertSame('', $columnMetadata['COLUMN_COMMENT']);
    }

    public function testRefusesToOverwriteAnUnexpectedPermissionDescription(): void
    {
        self::assertInstanceOf(PDO::class, self::$db);
        self::$db->exec(
            "INSERT INTO permissions (name, description, category)
             VALUES ('accounting.owner_contribution.create', 'Reviewed custom description', 'test')"
        );
        self::$db->exec(
            "INSERT INTO permissions (name, description, category)
             VALUES ('accounting.owner_contribution.view', 'عرض وقراءة سجلات مساهمات المالك', 'test'),
                    ('accounting.owner_contribution.void', 'إلغاء مساهمة مالك موجودة (عكس القيد المحاسبي)', 'test'),
                    ('product.purchase_price.view', 'عرض سعر شراء المنتج ومعلومات التكلفة', 'test'),
                    ('sale.discount', 'السماح بتطبيق خصم على البيع ضمن حدود المنتج', 'test')"
        );

        try {
            (new FinalizeSchemaRequirementCleanup(self::$db))->up();
            self::fail('Migration should refuse to overwrite an unexpected description.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('accounting.owner_contribution.create', $error->getMessage());
        }

        $comment = self::$db->query(
            "SELECT COLUMN_COMMENT
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'cash_vouchers' AND COLUMN_NAME = 'cost_center_id'"
        )->fetchColumn();
        self::assertSame('مركز التكلفة (اختياري)', $comment);
    }

    private static function dropTemporarySchema(): void
    {
        if (self::$server instanceof mysqli && self::$schema !== null) {
            self::$server->query('DROP DATABASE IF EXISTS `' . self::$schema . '`');
            self::$server->close();
        }
    }
}
