<?php

declare(strict_types=1);

use App\Services\SalesService;
use PHPUnit\Framework\TestCase;

final class SaleCancellationSqlitePdo extends PDO
{
    public function __construct()
    {
        parent::__construct('sqlite::memory:');
        $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->sqliteCreateFunction('NOW', static fn (): string => date('Y-m-d H:i:s'));
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
    }
}

final class SaleCancellationSerialRestorationTest extends TestCase
{
    public function testCancellationRestoresSoldSerialToBranchInventory(): void
    {
        $db = new SaleCancellationSqlitePdo();
        $db->exec("
            CREATE TABLE sales (
                id INTEGER PRIMARY KEY, tenant_id INTEGER, status TEXT, branch_id INTEGER,
                paid_amount REAL, journal_entry_id INTEGER, approval_note TEXT, updated_at TEXT
            );
            CREATE TABLE payments (tenant_id INTEGER, sale_id INTEGER, status TEXT, type TEXT, amount REAL, is_draft INTEGER);
            CREATE TABLE returns (tenant_id INTEGER, sale_id INTEGER, return_type TEXT);
            CREATE TABLE return_credit_allocations (tenant_id INTEGER, sale_id INTEGER);
            CREATE TABLE products (id INTEGER, tenant_id INTEGER, product_type TEXT, has_serial_number INTEGER);
            CREATE TABLE sales_items (
                sale_id INTEGER, tenant_id INTEGER, product_id INTEGER, quantity REAL,
                conversion_factor REAL, purchase_price REAL, unit_id INTEGER,
                batch_number TEXT, expiry_date TEXT, serial TEXT
            );
            CREATE TABLE branch_products (
                tenant_id INTEGER, branch_id INTEGER, product_id INTEGER, quantity REAL,
                quantity_cost REAL, gl_reconciled INTEGER
            );
            CREATE TABLE inventory_transactions (
                id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, product_id INTEGER,
                unit_id INTEGER, branch_from INTEGER, branch_to INTEGER, quantity REAL,
                unit_cost REAL, total_cost REAL, movement_type TEXT, reference_type TEXT,
                reference_id INTEGER, user_id INTEGER, movement_date TEXT,
                batch_number TEXT, expiry_date TEXT
            );
            CREATE TABLE product_serials (
                tenant_id INTEGER, product_id INTEGER, branch_id INTEGER,
                serial_number TEXT, status TEXT, transaction_id INTEGER
            );
            CREATE TABLE journal_entries (
                id INTEGER, tenant_id INTEGER, reference_type TEXT, reference_id INTEGER
            );
        ");
        $db->exec("INSERT INTO sales VALUES (11, 4, 'approved', 6, 0, NULL, NULL, NULL)");
        $db->exec("INSERT INTO products VALUES (7, 4, 'stock', 1)");
        $db->exec("INSERT INTO sales_items VALUES (11, 4, 7, 1, 1, 10, 2, NULL, NULL, 'SN-001')");
        $db->exec("INSERT INTO branch_products VALUES (4, 6, 7, 0, 0, 1)");
        $db->exec("INSERT INTO product_serials VALUES (4, 7, 6, 'SN-001', 'sold', 81)");

        $result = (new SalesService($db, 3, 4))->deleteSale(4, 11);

        self::assertSame('canceled', $result['status']);
        self::assertSame('in_stock', $db->query(
            "SELECT status FROM product_serials WHERE serial_number = 'SN-001'"
        )->fetchColumn());
        self::assertNull($db->query(
            "SELECT transaction_id FROM product_serials WHERE serial_number = 'SN-001'"
        )->fetchColumn());
        self::assertSame(1.0, (float) $db->query(
            'SELECT quantity FROM branch_products WHERE product_id = 7'
        )->fetchColumn());
        self::assertSame('sale_cancel', $db->query(
            'SELECT movement_type FROM inventory_transactions'
        )->fetchColumn());
    }
}
