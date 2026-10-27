<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../database/migrations/033_consolidate_branch_manager_role.php';

final class ConsolidateBranchManagerRoleTest extends TestCase
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
                display_name TEXT NOT NULL,
                tenant_id INTEGER NULL
            )'
        );
        $this->db->exec(
            'CREATE TABLE permissions (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL,
                tenant_id INTEGER NULL
            )'
        );
        $this->db->exec(
            'CREATE TABLE role_permissions (
                role_id INTEGER NOT NULL,
                permission_id INTEGER NOT NULL,
                created_at TEXT NULL,
                created_by INTEGER NULL,
                tenant_id INTEGER NULL,
                PRIMARY KEY (role_id, permission_id)
            )'
        );
        $this->db->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                role_id INTEGER NOT NULL
            )'
        );
        $this->db->exec(
            'CREATE TABLE users_role (
                user_id INTEGER NOT NULL,
                role_id INTEGER NOT NULL,
                created_at TEXT NULL,
                created_by INTEGER NULL,
                tenant_id INTEGER NULL,
                PRIMARY KEY (user_id, role_id)
            )'
        );
    }

    public function testMergesPermissionsAssignmentsAndUpdatesManagerDisplayName(): void
    {
        $this->db->exec(
            "INSERT INTO roles (id, name, display_name, tenant_id) VALUES
                (3, 'manager', 'مشرف', NULL),
                (10, 'branch_manager', 'مدير الفرع', NULL)"
        );
        $this->db->exec(
            "INSERT INTO permissions (id, name) VALUES
                (1, 'manager.permission'),
                (2, 'branch_manager.permission')"
        );
        $this->db->exec(
            "INSERT INTO role_permissions (role_id, permission_id, tenant_id) VALUES
                (3, 1, NULL),
                (10, 2, NULL)"
        );
        $this->db->exec(
            "INSERT INTO users (id, role_id) VALUES (20, 10), (21, 3)"
        );
        $this->db->exec(
            "INSERT INTO users_role (user_id, role_id, tenant_id) VALUES
                (20, 10, 7),
                (21, 3, 7)"
        );

        (new ConsolidateBranchManagerRole($this->db))->up();

        self::assertSame('مدير الفرع', $this->db->query(
            "SELECT display_name FROM roles WHERE id = 3"
        )->fetchColumn());
        self::assertSame(
            [1, 2],
            array_map('intval', $this->db->query(
                'SELECT permission_id FROM role_permissions WHERE role_id = 3 ORDER BY permission_id'
            )->fetchAll(PDO::FETCH_COLUMN))
        );
        self::assertSame(
            [3],
            array_map('intval', $this->db->query(
                'SELECT id FROM roles ORDER BY id'
            )->fetchAll(PDO::FETCH_COLUMN))
        );
        self::assertSame(
            [3, 3],
            array_map('intval', $this->db->query(
                'SELECT role_id FROM users ORDER BY id'
            )->fetchAll(PDO::FETCH_COLUMN))
        );
        self::assertSame(
            [[20, 3, 7], [21, 3, 7]],
            array_map(
                static fn (array $row): array => [
                    (int) $row['user_id'],
                    (int) $row['role_id'],
                    (int) $row['tenant_id'],
                ],
                $this->db->query(
                    'SELECT user_id, role_id, tenant_id FROM users_role ORDER BY user_id'
                )->fetchAll(PDO::FETCH_ASSOC)
            )
        );
    }

    public function testUpdatesProductionManagerNameWhenNoDuplicateRoleExists(): void
    {
        $this->db->exec("INSERT INTO roles (id, name, display_name) VALUES (3, 'manager', 'مشرف')");

        (new ConsolidateBranchManagerRole($this->db))->up();

        self::assertSame(0, (int) $this->db->query(
            "SELECT COUNT(*) FROM roles WHERE LOWER(name) = 'branch_manager'"
        )->fetchColumn());
        self::assertSame('مدير الفرع', $this->db->query(
            "SELECT display_name FROM roles WHERE id = 3"
        )->fetchColumn());
    }

    public function testRefusesToMergeTenantScopedPermissions(): void
    {
        $this->db->exec(
            "INSERT INTO roles (id, name, display_name) VALUES
                (3, 'manager', 'مشرف'),
                (10, 'branch_manager', 'مدير الفرع')"
        );
        $this->db->exec("INSERT INTO permissions (id, name, tenant_id) VALUES (2, 'tenant.permission', 7)");
        $this->db->exec(
            'INSERT INTO role_permissions (role_id, permission_id, tenant_id) VALUES (10, 2, 7)'
        );

        try {
            (new ConsolidateBranchManagerRole($this->db))->up();
            self::fail('Expected tenant-scoped permissions to abort role consolidation.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Tenant-scoped permissions', $e->getMessage());
        }

        self::assertFalse($this->db->inTransaction());
        self::assertSame(1, (int) $this->db->query(
            "SELECT COUNT(*) FROM roles WHERE LOWER(name) = 'branch_manager'"
        )->fetchColumn());
        self::assertSame(0, (int) $this->db->query(
            'SELECT COUNT(*) FROM role_permissions WHERE role_id = 3'
        )->fetchColumn());
    }
}
