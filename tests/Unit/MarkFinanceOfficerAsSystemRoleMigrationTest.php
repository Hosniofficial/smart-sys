<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/database/migrations/020_mark_finance_officer_as_system_role.php';
require_once dirname(__DIR__, 2) . '/api/v1/src/Security/TenantRoleAssignmentPolicy.php';

use App\Security\TenantRoleAssignmentPolicy;
use PHPUnit\Framework\TestCase;

final class MarkFinanceOfficerAsSystemRoleMigrationTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec(
            'CREATE TABLE roles (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL,
                tenant_id INTEGER NULL,
                is_system_role INTEGER NOT NULL
            )'
        );
        $this->db->exec(
            'CREATE TABLE permissions (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL
            )'
        );
        $this->db->exec(
            'CREATE TABLE role_permissions (
                role_id INTEGER NOT NULL,
                permission_id INTEGER NOT NULL,
                PRIMARY KEY (role_id, permission_id)
            )'
        );
    }

    public function testMarksOnlyTheGlobalFinanceRoleAndPreservesPermissions(): void
    {
        $this->db->exec(
            "INSERT INTO roles (id, name, tenant_id, is_system_role) VALUES
                (3, 'manager', NULL, 1),
                (4, 'cashier', NULL, 1),
                (5, 'inventory_clerk', NULL, 1),
                (9, 'finance_officer', NULL, 0),
                (19, 'finance_officer', 7, 0)"
        );
        $this->db->exec(
            "INSERT INTO permissions (id, name) VALUES
                (21, 'sale.void'),
                (43, 'return.approve'),
                (67, 'accounting.je.delete'),
                (68, 'accounting.je.reverse')"
        );
        $this->db->exec(
            'INSERT INTO role_permissions (role_id, permission_id) VALUES
                (9, 21), (9, 43), (9, 67), (9, 68)'
        );

        (new MarkFinanceOfficerAsSystemRole($this->db))->up();

        self::assertSame(
            1,
            (int) $this->db->query('SELECT is_system_role FROM roles WHERE id = 9')->fetchColumn()
        );
        self::assertSame(
            0,
            (int) $this->db->query('SELECT is_system_role FROM roles WHERE id = 19')->fetchColumn()
        );
        self::assertSame(
            [[9, 21], [9, 43], [9, 67], [9, 68]],
            array_map(
                static fn (array $row): array => [(int) $row['role_id'], (int) $row['permission_id']],
                $this->db->query('SELECT role_id, permission_id FROM role_permissions ORDER BY permission_id')
                    ->fetchAll(PDO::FETCH_ASSOC)
            )
        );
        self::assertSame([9], TenantRoleAssignmentPolicy::validate($this->db, [9], 42));
    }

    public function testFailsWithoutChangingDataWhenGlobalFinanceRoleIsMissing(): void
    {
        $this->db->exec("INSERT INTO roles (id, name, tenant_id, is_system_role) VALUES (19, 'finance_officer', 7, 0)");

        try {
            (new MarkFinanceOfficerAsSystemRole($this->db))->up();
            self::fail('Expected migration to reject a missing global finance role.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('exactly one shared global finance_officer', $exception->getMessage());
        }

        self::assertSame(
            0,
            (int) $this->db->query('SELECT is_system_role FROM roles WHERE id = 19')->fetchColumn()
        );
    }

    public function testFailsWhenGlobalFinanceRoleIsAmbiguous(): void
    {
        $this->db->exec(
            "INSERT INTO roles (id, name, tenant_id, is_system_role) VALUES
                (9, 'finance_officer', NULL, 0),
                (90, 'FINANCE_OFFICER', NULL, 0)"
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exactly one shared global finance_officer');

        (new MarkFinanceOfficerAsSystemRole($this->db))->up();
    }
}
