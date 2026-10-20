<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/database/migrations/027_track_opening_balance_reversals.php';

use PHPUnit\Framework\TestCase;

final class OpeningBalanceReversalMigrationTest extends TestCase
{
    public function testAddsReversalAuditFieldsAndBackfillsTheOriginalMovementIdempotently(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is not available.');
        }

        $db = new PDO('sqlite::memory:');
        $db->exec("
            CREATE TABLE opening_balances (
                id INTEGER PRIMARY KEY,
                tenant_id INTEGER,
                product_id INTEGER,
                branch_id INTEGER,
                quantity NUMERIC,
                unit_cost NUMERIC,
                entry_date TEXT
            )
        ");
        $db->exec("
            CREATE TABLE inventory_transactions (
                id INTEGER PRIMARY KEY,
                tenant_id INTEGER,
                product_id INTEGER,
                branch_to INTEGER,
                quantity NUMERIC,
                unit_cost NUMERIC,
                movement_type TEXT
            )
        ");
        $db->exec("INSERT INTO opening_balances VALUES (1, 2, 7, 3, 4, 2.5, '2026-10-01')");
        $db->exec("INSERT INTO inventory_transactions VALUES (11, NULL, 7, 3, 4, 2.5, 'opening_balance_manual')");

        $migration = new TrackOpeningBalanceReversals($db);
        $migration->up();
        $migration->up();

        $columns = array_column(
            $db->query('PRAGMA table_info(opening_balances)')->fetchAll(PDO::FETCH_ASSOC),
            'name'
        );
        self::assertContains('reversed_at', $columns);
        self::assertContains('reversed_by', $columns);
        self::assertContains('reversal_journal_entry_id', $columns);
        self::assertContains('reversal_inventory_transaction_id', $columns);
        self::assertContains('inventory_transaction_id', $columns);

        $row = $db->query(
            'SELECT inventory_transaction_id, reversed_at FROM opening_balances WHERE id = 1'
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame(11, (int) $row['inventory_transaction_id']);
        self::assertNull($row['reversed_at']);
    }
}
