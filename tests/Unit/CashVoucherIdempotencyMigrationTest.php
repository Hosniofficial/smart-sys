<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/database/migrations/021_add_cash_voucher_idempotency.php';

use PHPUnit\Framework\TestCase;

final class CashVoucherIdempotencyMigrationTest extends TestCase
{
    public function testAddsTenantScopedUniqueKeyAndRequestHashAndCanBeRetried(): void
    {
        $db = $this->database();
        $migration = new AddCashVoucherIdempotency($db);

        $migration->up();
        $migration->up();
        $db->exec("INSERT INTO cash_vouchers (tenant_id, idempotency_key) VALUES (4, 'request-a')");
        $db->exec("INSERT INTO cash_vouchers (tenant_id, idempotency_key) VALUES (5, 'request-a')");

        $this->expectException(PDOException::class);
        $db->exec("INSERT INTO cash_vouchers (tenant_id, idempotency_key) VALUES (4, 'request-a')");
    }

    public function testRefusesExistingDuplicateKeysBeforeAddingUniqueIndex(): void
    {
        $db = $this->database();
        $db->exec("INSERT INTO cash_vouchers (tenant_id, idempotency_key) VALUES (4, 'duplicate')");
        $db->exec("INSERT INTO cash_vouchers (tenant_id, idempotency_key) VALUES (4, 'duplicate')");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('duplicate tenant/key values already exist');
        (new AddCashVoucherIdempotency($db))->up();
    }

    public function testRollbackRefusesToRemoveKeysAlreadyUsed(): void
    {
        $db = $this->database();
        $migration = new AddCashVoucherIdempotency($db);
        $migration->up();
        $db->exec("INSERT INTO cash_vouchers (tenant_id, idempotency_key) VALUES (4, 'request-a')");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('after keys have been used');
        $migration->down();
    }

    private function database(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec(
            'CREATE TABLE cash_vouchers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                idempotency_key TEXT NULL
            )'
        );
        return $db;
    }
}
