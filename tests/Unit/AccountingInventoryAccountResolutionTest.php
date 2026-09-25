<?php

declare(strict_types=1);

use App\Services\AccountingService;
use PHPUnit\Framework\TestCase;

final class AccountingInventoryAccountResolutionTest extends TestCase
{
    public function testBranchAccountOverridesTenantSettings(): void
    {
        $db = $this->database();
        $db->exec("INSERT INTO branches (id, tenant_id, account_id) VALUES (7, 2, 7001)");
        $this->setting($db, 2, 'branch.7.inventory_account_id', 7002);
        $this->setting($db, 2, 'inventory_account_id', 7003);

        self::assertSame(7001, (new AccountingService($db))->resolveInventoryAccountForBranch(2, 7));
    }

    public function testBranchSpecificSettingOverridesTenantSetting(): void
    {
        $db = $this->database();
        $db->exec('INSERT INTO branches (id, tenant_id, account_id) VALUES (7, 2, NULL)');
        $this->setting($db, 2, 'branch.7.inventory_account_id', 7002);
        $this->setting($db, 2, 'inventory_account_id', 7003);

        self::assertSame(7002, (new AccountingService($db))->resolveInventoryAccountForBranch(2, 7));
    }

    public function testAlternateBranchSettingOverridesTenantSetting(): void
    {
        $db = $this->database();
        $db->exec('INSERT INTO branches (id, tenant_id, account_id) VALUES (7, 2, NULL)');
        $this->setting($db, 2, 'inventory.branch.7.account_id', 7002);
        $this->setting($db, 2, 'inventory_account_id', 7003);

        self::assertSame(7002, (new AccountingService($db))->resolveInventoryAccountForBranch(2, 7));
    }

    public function testTenantSettingOverridesAccount1301Fallback(): void
    {
        $db = $this->database();
        $this->setting($db, 2, 'inventory_account_id', 7003);
        $db->exec("INSERT INTO accounts (id, tenant_id, code) VALUES (1301, 2, '1301')");

        self::assertSame(7003, (new AccountingService($db))->resolveInventoryAccountForBranch(2, null));
    }

    public function testUsesAccount1301OnlyWhenNoBranchOrTenantSettingExists(): void
    {
        $db = $this->database();
        $db->exec("INSERT INTO accounts (id, tenant_id, code) VALUES (1301, 2, '1301')");

        self::assertSame(1301, (new AccountingService($db))->resolveInventoryAccountForBranch(2, 7));
    }

    private function database(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE branches (id INTEGER PRIMARY KEY, tenant_id INTEGER, account_id INTEGER NULL)');
        $db->exec('CREATE TABLE settings (tenant_id INTEGER NULL, key_name TEXT NOT NULL, value TEXT, updated_at TEXT)');
        $db->exec('CREATE TABLE accounts (id INTEGER PRIMARY KEY, tenant_id INTEGER NULL, code TEXT NOT NULL)');

        return $db;
    }

    private function setting(PDO $db, ?int $tenantId, string $key, int $accountId): void
    {
        $stmt = $db->prepare('INSERT INTO settings (tenant_id, key_name, value, updated_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)');
        $stmt->execute([$tenantId, $key, (string) $accountId]);
    }
}
