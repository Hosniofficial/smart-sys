<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/database/migrations/024_reconcile_legacy_stock_adjustment_costs.php';

final class ReconcileLegacyStockAdjustmentCostsTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE journal_entries (
                id INTEGER PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                reference_type TEXT NOT NULL,
                status TEXT NOT NULL
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE journal_entry_lines (
                id INTEGER PRIMARY KEY,
                journal_entry_id INTEGER NOT NULL,
                tenant_id INTEGER NOT NULL,
                debit_amount NUMERIC NOT NULL,
                credit_amount NUMERIC NOT NULL
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE inventory_transactions (
                id INTEGER PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                quantity NUMERIC NOT NULL,
                unit_cost NUMERIC,
                total_cost NUMERIC,
                journal_entry_id INTEGER,
                reference_type TEXT NOT NULL,
                movement_type TEXT NOT NULL
            )'
        );
    }

    public function testBackfillsOnlyZeroCostMovementsWithBalancedPostedAdjustmentEntries(): void
    {
        $this->pdo->exec(
            "INSERT INTO journal_entries VALUES
                (10, 1, 'inventory_adjustment', 'posted'),
                (11, 1, 'inventory_adjustment', 'posted'),
                (12, 1, 'inventory_adjustment', 'draft'),
                (13, 1, 'stock_transfer', 'posted')"
        );
        $this->pdo->exec(
            'INSERT INTO journal_entry_lines VALUES
                (1, 10, 1, 1000, 0), (2, 10, 1, 0, 1000),
                (3, 11, 1, 1000, 0), (4, 11, 1, 0, 900),
                (5, 12, 1, 500, 0), (6, 12, 1, 0, 500),
                (7, 13, 1, 200, 0), (8, 13, 1, 0, 200)'
        );
        $this->pdo->exec(
            "INSERT INTO inventory_transactions VALUES
                (90, 1, 1, 0, 0, 10, 'adjustment', 'adjustment_in'),
                (91, 1, 1, 0, 0, 10, 'adjustment', 'adjustment_out'),
                (92, 1, 1, 0, 0, 11, 'adjustment', 'adjustment_in'),
                (93, 1, 1, 0, 0, 12, 'adjustment', 'adjustment_in'),
                (94, 1, 1, 0, 0, 13, 'transfer', 'transfer_in'),
                (95, 1, 1, 25, 25, 10, 'adjustment', 'adjustment_in')"
        );

        ob_start();
        (new ReconcileLegacyStockAdjustmentCosts($this->pdo))->up();
        ob_end_clean();

        $rows = $this->pdo->query(
            'SELECT id, unit_cost, total_cost FROM inventory_transactions ORDER BY id'
        )->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);

        self::assertSame(1000.0, (float) $rows[90]['unit_cost']);
        self::assertSame(1000.0, (float) $rows[90]['total_cost']);
        self::assertSame(1000.0, (float) $rows[91]['unit_cost']);
        self::assertSame(1000.0, (float) $rows[91]['total_cost']);
        self::assertSame(0.0, (float) $rows[92]['unit_cost']);
        self::assertSame(0.0, (float) $rows[93]['unit_cost']);
        self::assertSame(0.0, (float) $rows[94]['unit_cost']);
        self::assertSame(25.0, (float) $rows[95]['unit_cost']);
    }
}
