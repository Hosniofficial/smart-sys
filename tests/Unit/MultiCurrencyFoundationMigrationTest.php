<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/database/migrations/026_add_multi_currency_foundation.php';

use PHPUnit\Framework\TestCase;

final class MultiCurrencyFoundationMigrationTest extends TestCase
{
    public function testAddsRateTableAndSnapshotsIdempotently(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is not available.');
        }

        $db = new PDO('sqlite::memory:');
        $db->exec('CREATE TABLE tenants (id INTEGER PRIMARY KEY)');
        foreach (['sales', 'purchases', 'returns', 'payments', 'cash_vouchers'] as $table) {
            $db->exec("CREATE TABLE {$table} (id INTEGER PRIMARY KEY)");
        }

        $migration = new AddMultiCurrencyFoundation($db);
        $migration->up();
        $migration->up();

        self::assertSame(
            1,
            (int) $db->query(
                "SELECT COUNT(*) FROM sqlite_master
                 WHERE type = 'table' AND name = 'currency_exchange_rates'"
            )->fetchColumn()
        );

        foreach (['sales', 'purchases', 'returns', 'payments', 'cash_vouchers'] as $table) {
            $columns = array_column($db->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC), 'name');
            self::assertContains('transaction_currency_code', $columns);
            self::assertContains('base_currency_code', $columns);
            self::assertContains('exchange_rate', $columns);
            self::assertContains('base_amount', $columns);
            self::assertContains('foreign_total_amount', $columns);
        }
    }
}
