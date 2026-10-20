<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../database/migrations/032_create_repair_orders.php';

final class CreateRepairOrdersMigrationTest extends TestCase
{
    public function testMigrationCreatesRepairTablesIdempotentlyAndCanRollBack(): void
    {
        $db = new PDO('sqlite::memory:');
        $migration = new CreateRepairOrders($db);

        $migration->up();
        $migration->up();

        $tables = $db->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name IN ('repair_orders', 'repair_order_parts')"
        )->fetchAll(PDO::FETCH_COLUMN);
        self::assertEqualsCanonicalizing(['repair_orders', 'repair_order_parts'], $tables);

        $columns = $db->query('PRAGMA table_info(repair_orders)')->fetchAll(PDO::FETCH_ASSOC);
        self::assertContains('invoice_id', array_column($columns, 'name'));
        self::assertContains('status', array_column($columns, 'name'));

        $migration->down();
        $remaining = $db->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name IN ('repair_orders', 'repair_order_parts')"
        )->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame([], $remaining);
    }
}
