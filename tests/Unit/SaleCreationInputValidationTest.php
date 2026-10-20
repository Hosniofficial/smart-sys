<?php

declare(strict_types=1);

use App\Services\SaleCreationService;
use PHPUnit\Framework\TestCase;

final class SaleCreationInputValidationTest extends TestCase
{
    public function testRejectsNegativeQuantityBeforeCreatingSale(): void
    {
        $db = $this->createDatabase();
        $db->exec("INSERT INTO payment_methods (id, tenant_id, kind) VALUES (1, 1, 'cash')");

        try {
            (new SaleCreationService($db, 1))->createSale($this->saleData([
                'items' => [['quantity' => -2]],
            ]));
            self::fail('Negative sale quantity should be rejected.');
        } catch (DomainException $exception) {
            self::assertSame('كمية الصنف يجب أن تكون أكبر من صفر.', $exception->getMessage());
        }

        self::assertSame(0, (int) $db->query('SELECT COUNT(*) FROM sales')->fetchColumn());
        self::assertFalse($db->inTransaction());
    }

    public function testRejectsInvalidExplicitPaymentMethodInsteadOfFallingBack(): void
    {
        $db = $this->createDatabase();
        $db->exec("INSERT INTO payment_methods (id, tenant_id, kind) VALUES (1, 1, 'cash')");

        try {
            (new SaleCreationService($db, 1))->createSale($this->saleData([
                'payment_method_id' => 999,
            ]));
            self::fail('An invalid explicit payment method should be rejected.');
        } catch (DomainException $exception) {
            self::assertSame('طريقة الدفع المحددة غير صالحة لهذا المستأجر.', $exception->getMessage());
        }

        self::assertSame(0, (int) $db->query('SELECT COUNT(*) FROM sales')->fetchColumn());
    }

    public function testUsesDatabaseProductUnitFactorInsteadOfClientValue(): void
    {
        $db = $this->createDatabase();
        $db->sqliteCreateFunction('NOW', static fn (): string => date('Y-m-d H:i:s'));
        $db->exec("INSERT INTO payment_methods (id, tenant_id, kind) VALUES (1, 1, 'cash')");
        $db->exec("INSERT INTO settings (tenant_id, key_name, value) VALUES (1, 'pos.require_approval', '1')");
        $db->exec('INSERT INTO cost_centers (id, tenant_id) VALUES (10, 1)');
        $db->exec("INSERT INTO products (id, tenant_id, sale_price, purchase_price, product_type, active)
                   VALUES (20, 1, 50, 0, 'service', 1)");
        $db->exec('INSERT INTO product_units (product_id, unit_id, conversion_factor, tenant_id)
                   VALUES (20, 30, 12, 1)');

        (new SaleCreationService($db, 1))->createSale($this->saleData([
            'cost_center_id' => 10,
            'invoice_number' => 'UNIT-SALE-1',
            'items' => [[
                'product_id' => 20,
                'unit_id' => 30,
                'quantity' => 2,
                'sale_price' => 600,
                'conversion_factor' => 0.01,
            ]],
        ]));

        self::assertSame(12.0, (float) $db->query(
            'SELECT conversion_factor FROM sales_items WHERE sale_id = 1'
        )->fetchColumn());
        self::assertSame(2.0, (float) $db->query(
            'SELECT quantity FROM sales_items WHERE sale_id = 1'
        )->fetchColumn());
    }

    private function createDatabase(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("
            CREATE TABLE payment_methods (id INTEGER, tenant_id INTEGER, kind TEXT, is_default INTEGER DEFAULT 0);
            CREATE TABLE users (
                id INTEGER PRIMARY KEY, tenant_id INTEGER, role_id INTEGER, is_owner INTEGER DEFAULT 0
            );
            CREATE TABLE roles (id INTEGER PRIMARY KEY, tenant_id INTEGER, name TEXT);
            CREATE TABLE users_role (user_id INTEGER, role_id INTEGER, tenant_id INTEGER);
            CREATE TABLE settings (
                tenant_id INTEGER, key_name TEXT, value TEXT, updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE cost_centers (id INTEGER PRIMARY KEY, tenant_id INTEGER);
            CREATE TABLE branches (id INTEGER, tenant_id INTEGER, cost_center_id INTEGER);
            CREATE TABLE products (
                id INTEGER, tenant_id INTEGER, sale_price REAL, purchase_price REAL,
                min_sale_price REAL DEFAULT 0, fixed_discount_percentage REAL DEFAULT 0,
                product_type TEXT, active INTEGER DEFAULT 1
            );
            CREATE TABLE product_units (
                product_id INTEGER, unit_id INTEGER, conversion_factor REAL, tenant_id INTEGER
            );
            CREATE TABLE product_branch_gl_mapping (
                tenant_id INTEGER, product_id INTEGER, branch_id INTEGER, activation_status TEXT
            );
            CREATE TABLE sales (
                id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, customer_id INTEGER,
                sale_date TEXT, total_amount REAL, net_total_amount REAL, paid_amount REAL,
                discount_type TEXT, discount_value REAL, status TEXT, user_id INTEGER, notes TEXT,
                total_items INTEGER, total_profit REAL, branch_id INTEGER, invoice_number TEXT,
                payment_method_id INTEGER, tax_rate REAL, tax_amount REAL, session_id INTEGER,
                cost_center_id INTEGER, updated_at TEXT
            );
            CREATE TABLE sales_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT, sale_id INTEGER, product_id INTEGER,
                unit_id INTEGER, conversion_factor REAL, quantity REAL, sale_price REAL,
                purchase_price REAL, discount_type TEXT, discount_value REAL, net_total REAL,
                batch_number TEXT, expiry_date TEXT, serial TEXT, tenant_id INTEGER,
                created_at TEXT, created_by INTEGER
            );
            CREATE TABLE invoice_approvals (
                id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, sale_id INTEGER,
                action TEXT, previous_status TEXT, new_status TEXT, note TEXT, action_by INTEGER
            );
        ");

        return $db;
    }

    private function saleData(array $overrides = []): array
    {
        $defaults = [
            'tenant_id' => 1,
            'branch_id' => 1,
            'payment_method_id' => 1,
            'invoice_number' => 'UNIT-SALE',
            'cost_center_id' => 10,
            'items' => [[
                'product_id' => 20,
                'unit_id' => 30,
                'quantity' => 1,
                'sale_price' => 50,
                'conversion_factor' => 1,
            ]],
        ];

        $data = array_replace($defaults, $overrides);
        if (isset($overrides['items'])) {
            $data['items'] = array_map(
                static fn (array $item): array => array_replace($defaults['items'][0], $item),
                $overrides['items']
            );
        }

        return $data;
    }
}
