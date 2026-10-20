<?php

declare(strict_types=1);

use App\Services\ReturnCostingService;
use PHPUnit\Framework\TestCase;

final class ReturnCostingServiceTest extends TestCase
{
    public function testPrefersPersistedReturnCostThenFallsBackToOriginalSaleItemCost(): void
    {
        $db = $this->createDatabase();
        $db->exec("INSERT INTO returns VALUES (1, 3, 10), (2, 3, 11)");
        $db->exec("INSERT INTO return_items VALUES
            (1, 1, 3, 6, 7, 2),
            (2, 2, 3, 7, 7, 2)");
        $db->exec("INSERT INTO sales_items VALUES
            (10, 3, 6, 7, 2, 8),
            (11, 3, 7, 7, 2, 7)");
        $db->exec("INSERT INTO inventory_transactions VALUES
            (1, 3, 6, 7, 1, 12, 'in', 'return', 1)");

        $details = (new ReturnCostingService($db))->calculateDetailsForReturns(3, [1, 2]);

        self::assertSame(34.0, $details['cost_amount']);
        self::assertSame(0, $details['unresolved_line_count']);
        self::assertSame(0.0, $details['unresolved_quantity']);
    }

    public function testFlagsMissingHistoricalCostInsteadOfUsingCurrentProductPrice(): void
    {
        $db = $this->createDatabase();
        $db->exec("INSERT INTO returns VALUES (1, 3, 10), (2, 3, 11), (3, 3, 12)");
        $db->exec("INSERT INTO return_items VALUES
            (1, 1, 3, 6, 7, 2),
            (2, 2, 3, 7, 7, 3),
            (3, 3, 3, 8, 7, 4)");
        $db->exec("INSERT INTO sales_items VALUES
            (10, 3, 6, 7, 2, 5),
            (11, 3, 7, 7, 3, NULL),
            (12, 3, 8, 7, 4, NULL)");
        $db->exec("INSERT INTO inventory_transactions VALUES
            (1, 3, 8, 7, 1, 6, 'in', 'return', 3)");
        $db->exec("INSERT INTO products VALUES (3, 6, 999), (3, 7, 888), (3, 8, 777)");

        $service = new ReturnCostingService($db);
        $details = $service->calculateDetailsForReturns(3, [1, 2, 3]);

        self::assertSame(16.0, $details['cost_amount']);
        self::assertSame(2, $details['unresolved_line_count']);
        self::assertSame(6.0, $details['unresolved_quantity']);
        self::assertSame(16.0, $service->calculateForReturns(3, [1, 2, 3]));
    }

    private function createDatabase(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE returns (
            id INTEGER PRIMARY KEY, tenant_id INTEGER, sale_id INTEGER
        )');
        $db->exec('CREATE TABLE return_items (
            id INTEGER PRIMARY KEY, return_id INTEGER, tenant_id INTEGER,
            product_id INTEGER, unit_id INTEGER, quantity REAL
        )');
        $db->exec('CREATE TABLE sales_items (
            sale_id INTEGER, tenant_id INTEGER, product_id INTEGER,
            unit_id INTEGER, quantity REAL, purchase_price REAL NULL
        )');
        $db->exec('CREATE TABLE inventory_transactions (
            id INTEGER PRIMARY KEY, tenant_id INTEGER, product_id INTEGER, unit_id INTEGER,
            quantity REAL, total_cost REAL, movement_type TEXT, reference_type TEXT,
            reference_id INTEGER
        )');
        $db->exec('CREATE TABLE products (
            tenant_id INTEGER, id INTEGER, purchase_price REAL
        )');

        return $db;
    }
}
