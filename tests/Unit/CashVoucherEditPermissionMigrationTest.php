<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/database/migrations/018_grant_cash_voucher_edit_permission.php';

use PHPUnit\Framework\TestCase;

final class CashVoucherEditPermissionMigrationTest extends TestCase
{
    public function testGrantsEditPermissionOnlyToReviewedGlobalRolesAndCanBeRetried(): void
    {
        $db = $this->database();
        $db->exec("INSERT INTO permissions VALUES (90, 'voucher.edit', NULL)");
        $db->exec("INSERT INTO roles VALUES
            (2, 'admin', NULL), (3, 'manager', NULL), (9, 'finance_officer', NULL),
            (10, 'branch_manager', NULL), (20, 'tenant_manager', 4)");
        $db->exec(
            'INSERT INTO role_permissions (role_id, permission_id, tenant_id) VALUES (2, 90, NULL)'
        );

        $migration = new GrantCashVoucherEditPermission($db);
        $migration->up();
        $migration->up();

        self::assertSame(
            [2, 3, 9],
            array_map(
                'intval',
                $db->query('SELECT role_id FROM role_permissions ORDER BY role_id')->fetchAll(PDO::FETCH_COLUMN)
            )
        );
    }

    private function database(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->sqliteCreateFunction('NOW', static fn (): string => date('Y-m-d H:i:s'));
        $db->exec('CREATE TABLE permissions (id INTEGER, name TEXT, tenant_id INTEGER)');
        $db->exec('CREATE TABLE roles (id INTEGER, name TEXT, tenant_id INTEGER)');
        $db->exec(
            'CREATE TABLE role_permissions (
                role_id INTEGER,
                permission_id INTEGER,
                created_at TEXT,
                tenant_id INTEGER,
                PRIMARY KEY (role_id, permission_id)
            )'
        );
        return $db;
    }
}
