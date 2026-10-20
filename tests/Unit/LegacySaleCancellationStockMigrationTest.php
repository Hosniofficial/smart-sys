<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/database/migrations/013_reconcile_legacy_sale_cancellation_stock.php';

use PHPUnit\Framework\TestCase;

final class LegacySaleCancellationStockMigrationTest extends TestCase
{
    public function testBackfillsLegacyCancellationMovementAndBranchValuationOnce(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE sales (id INTEGER, tenant_id INTEGER, status TEXT)');
        $db->exec('CREATE TABLE branch_products (
            tenant_id INTEGER, branch_id INTEGER, product_id INTEGER,
            quantity REAL, quantity_cost REAL, gl_reconciled INTEGER, last_gl_posting_date TEXT,
            last_update TEXT
        )');
        $db->exec('CREATE TABLE inventory_transactions (
            id INTEGER PRIMARY KEY, tenant_id INTEGER, product_id INTEGER,
            branch_from INTEGER, branch_to INTEGER, quantity REAL,
            unit_cost REAL, total_cost REAL, movement_type TEXT,
            reference_type TEXT, reference_id INTEGER
        )');
        $db->exec("INSERT INTO sales VALUES (11, 4, 'canceled')");
        $db->exec('INSERT INTO branch_products VALUES (4, 4, 7, 10, 960, 1, NULL, NULL)');
        $db->exec("INSERT INTO inventory_transactions VALUES
            (1, 4, 7, 4, NULL, 4, 10, 40, 'out', 'sale', 11),
            (2, 4, 7, NULL, 4, 2, NULL, NULL, 'sale_cancel', 'sale', 11),
            (3, 4, 7, NULL, 4, 2, NULL, NULL, 'sale_cancel', 'sale', 11)");

        $migration = new ReconcileLegacySaleCancellationStock($db);
        $migration->up();
        $migration->up();

        $stock = $db->query('SELECT quantity, quantity_cost, gl_reconciled FROM branch_products')->fetch(PDO::FETCH_ASSOC);
        self::assertSame(10.0, (float) $stock['quantity']);
        self::assertSame(1000.0, (float) $stock['quantity_cost']);
        self::assertSame(0, (int) $stock['gl_reconciled']);

        $movements = $db->query(
            "SELECT quantity, unit_cost, total_cost FROM inventory_transactions WHERE movement_type = 'sale_cancel' ORDER BY id"
        )->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(2, $movements);
        foreach ($movements as $movement) {
            self::assertSame(10.0, (float) $movement['unit_cost']);
            self::assertSame(20.0, (float) $movement['total_cost']);
        }
    }
}