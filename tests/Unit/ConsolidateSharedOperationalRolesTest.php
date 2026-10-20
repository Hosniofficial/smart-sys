<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../database/migrations/019_consolidate_shared_operational_roles.php';

final class ConsolidateSharedOperationalRolesTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('PRAGMA foreign_keys = ON');
        $this->db->exec(
            'CREATE TABLE roles (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL,
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
                PRIMARY KEY (role_id, permission_id),
                FOREIGN KEY (role_id) REFERENCES roles(id),
                FOREIGN KEY (permission_id) REFERENCES permissions(id)
            )'
        );
        $this->db->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                role_id INTEGER NOT NULL,
                FOREIGN KEY (role_id) REFERENCES roles(id)
            )'
        );
        $this->db->exec(
            'CREATE TABLE users_role (
                user_id INTEGER NOT NULL,
                role_id INTEGER NOT NULL,
                created_at TEXT NULL,
                created_by INTEGER NULL,
                tenant_id INTEGER NULL,
                PRIMARY KEY (user_id, role_id),
                FOREIGN KEY (user_id) REFERENCES users(id),
                FOREIGN KEY (role_id) REFERENCES roles(id)
            )'
        );
    }

    public function testMergesPermissionUnionsAndMovesAssignmentsBeforeRemovingTenantRoles(): void
    {
        $this->db->exec(
            "INSERT INTO roles (id, name, tenant_id) VALUES
                (3, 'manager', NULL),
                (9, 'finance_officer', NULL),
                (10, 'branch_manager', NULL),
                (12, 'manager', 2),
                (13, 'finance_officer', 2),
                (14, 'manager', 3)"
        );
        $this->db->exec(
            "INSERT INTO permissions (id, name, tenant_id) VALUES
                (1, 'manager.base', NULL),
                (2, 'manager.custom', NULL),
                (3, 'finance.base', NULL),
                (4, 'finance.custom', NULL),
                (5, 'branch_manager.legacy', NULL)"
        );
        $this->db->exec(
            "INSERT INTO role_permissions (role_id, permission_id, tenant_id) VALUES
                (3, 1, NULL),
                (10, 5, NULL),
                (9, 3, NULL),
                (12, 1, NULL),
                (12, 2, NULL),
                (13, 3, NULL),
                (13, 4, NULL),
                (14, 2, NULL)"
        );
        $this->db->exec(
            "INSERT INTO users (id, tenant_id, role_id) VALUES
                (20, 2, 12),
                (21, 2, 13)"
        );
        $this->db->exec(
            "INSERT INTO users_role (user_id, role_id, tenant_id) VALUES
                (20, 3, 2),
                (20, 12, 2),
                (21, 13, 2)"
        );

        (new ConsolidateSharedOperationalRoles($this->db))->up();

        self::assertSame(
            [1, 2, 5],
            array_map('intval', $this->db->query(
                'SELECT permission_id FROM role_permissions WHERE role_id = 3 ORDER BY permission_id'
            )->fetchAll(PDO::FETCH_COLUMN))
        );
        self::assertSame(
            [3, 4],
            array_map('intval', $this->db->query(
                'SELECT permission_id FROM role_permissions WHERE role_id = 9 ORDER BY permission_id'
            )->fetchAll(PDO::FETCH_COLUMN))
        );
        self::assertSame(
            [3, 9, 10],
            array_map('intval', $this->db->query(
                'SELECT id FROM roles ORDER BY id'
            )->fetchAll(PDO::FETCH_COLUMN))
        );
        self::assertSame(
            [3, 9],
            array_map('intval', $this->db->query(
                'SELECT role_id FROM users ORDER BY id'
            )->fetchAll(PDO::FETCH_COLUMN))
        );
        self::assertSame(
            [[20, 3], [21, 9]],
            array_map(
                static fn (array $row): array => [(int) $row['user_id'], (int) $row['role_id']],
                $this->db->query(
                    'SELECT user_id, role_id FROM users_role ORDER BY user_id, role_id'
                )->fetchAll(PDO::FETCH_ASSOC)
            )
        );
    }

    public function testAbortsAndRollsBackWhenTenantScopedPermissionsWouldBeWidened(): void
    {
        $this->db->exec(
            "INSERT INTO roles (id, name, tenant_id) VALUES
                (3, 'manager', NULL),
                (9, 'finance_officer', NULL),
                (12, 'manager', 2)"
        );
        $this->db->exec("INSERT INTO permissions (id, name, tenant_id) VALUES (1, 'tenant.permission', 2)");
        $this->db->exec(
            'INSERT INTO role_permissions (role_id, permission_id, tenant_id) VALUES (12, 1, 2)'
        );

        try {
            (new ConsolidateSharedOperationalRoles($this->db))->up();
            self::fail('Expected tenant-scoped permissions to abort role consolidation.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Tenant-scoped permissions', $e->getMessage());
        }

        self::assertFalse($this->db->inTransaction());
        self::assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM roles WHERE id = 12')->fetchColumn());
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM role_permissions WHERE role_id = 3')->fetchColumn());
    }
}
