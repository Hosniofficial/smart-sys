<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/database/migrations/015_normalize_sale_approval_status.php';

use PHPUnit\Framework\TestCase;

final class SaleApprovalStatusMigrationTest extends TestCase
{
    public function testNormalizesOnlyLegacyApprovalStatusesAndIsIdempotent(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE sales (id INTEGER PRIMARY KEY, status TEXT)');
        $db->exec("INSERT INTO sales (status) VALUES
            ('pending'), ('awaiting_approval'), (' PENDING '), ('draft'), ('pending_payment')");

        $migration = new NormalizeSaleApprovalStatus($db);
        $migration->up();
        $migration->up();

        self::assertSame(
            ['pending_approval', 'pending_approval', 'pending_approval', 'draft', 'pending_payment'],
            $db->query('SELECT status FROM sales ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)
        );
    }
}
