<?php

declare(strict_types=1);

use App\Services\CostingService;
use App\Services\ReturnService;
use PHPUnit\Framework\TestCase;

final class ServiceProductWorkflowTest extends TestCase
{
    public function testServiceItemsDoNotContributeInventoryCogs(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE sales (id INTEGER, tenant_id INTEGER, sale_date TEXT)');
        $db->exec('CREATE TABLE products (id INTEGER, tenant_id INTEGER, product_type TEXT, purchase_price NUMERIC)');
        $db->exec('CREATE TABLE sales_items (
            sale_id INTEGER, product_id INTEGER, tenant_id INTEGER, quantity NUMERIC,
            conversion_factor NUMERIC, purchase_price NUMERIC
        )');
        $db->exec("INSERT INTO sales VALUES (1, 7, '2026-10-01')");
        $db->exec("INSERT INTO products VALUES (10, 7, 'service', 25)");
        $db->exec('INSERT INTO sales_items VALUES (1, 10, 7, 2, 1, 25)');

        $costing = new CostingService($db);

        self::assertSame(0.0, $costing->computeCOGSForSale(7, 1));
    }

    public function testServiceReturnDoesNotRequireOrCreateInventoryRows(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE products (id INTEGER, tenant_id INTEGER, product_type TEXT)');
        $db->exec("INSERT INTO products VALUES (10, 7, 'service')");

        $returns = new ReturnService($db, 7);
        $returns->updateStock(10, 1, 1, 20, 30, 'sale');

        self::assertSame(0, (int) $db->query(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'inventory_transactions'"
        )->fetchColumn());
    }
}