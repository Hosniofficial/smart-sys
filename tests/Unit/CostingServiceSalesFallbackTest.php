<?php

declare(strict_types=1);

use App\Services\CostingService;
use PHPUnit\Framework\TestCase;

final class CostingServiceSalesFallbackTest extends TestCase
{
    public function testSaleItemPurchasePriceFallbackIsSharedWhenNoPurchaseHistoryExists(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE sales (
            id INTEGER PRIMARY KEY, tenant_id INTEGER, sale_date TEXT
        )');
        $db->exec('CREATE TABLE sales_items (
            id INTEGER PRIMARY KEY, sale_id INTEGER, tenant_id INTEGER, product_id INTEGER,
            quantity REAL, conversion_factor REAL, purchase_price REAL
        )');
        $db->exec('CREATE TABLE products (
            id INTEGER PRIMARY KEY, tenant_id INTEGER, product_type TEXT, purchase_price REAL
        )');
        $db->exec('CREATE TABLE purchases (
            id INTEGER PRIMARY KEY, tenant_id INTEGER, invoice_date TEXT, status TEXT
        )');
        $db->exec('CREATE TABLE purchase_items (
            id INTEGER PRIMARY KEY, purchase_id INTEGER, tenant_id INTEGER, product_id INTEGER,
            quantity REAL, cost REAL
        )');
        $db->exec('CREATE TABLE return_items (
            id INTEGER PRIMARY KEY, return_id INTEGER, tenant_id INTEGER, product_id INTEGER,
            quantity REAL, unit_price REAL
        )');
        $db->exec('CREATE TABLE returns (
            id INTEGER PRIMARY KEY, tenant_id INTEGER, return_type TEXT, return_date TEXT, status TEXT
        )');
        $db->exec("INSERT INTO sales VALUES (1, 5, '2026-10-09')");
        $db->exec("INSERT INTO products VALUES (1, 5, 'stock', 0)");
        $db->exec('INSERT INTO sales_items VALUES (1, 1, 5, 1, 2, 1, 450)');

        self::assertEquals(
            900,
            (new CostingService($db))->computeCOGSForSale(5, 1, '2026-10-09')
        );
    }
}
