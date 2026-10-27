<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../database/migrations/034_manage_branch_categories.php';

final class ManageBranchCategoriesMigrationTest extends TestCase
{
    public function testEnsuresCategoryPermissionsAndGrantsThemToAdminAndManager(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec(
            'CREATE TABLE permissions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                description TEXT,
                category TEXT NOT NULL,
                created_at TEXT,
                tenant_id INTEGER NULL
            )'
        );
        $db->exec(
            'CREATE TABLE roles (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL,
                tenant_id INTEGER NULL
            )'
        );
        $db->exec(
            'CREATE TABLE role_permissions (
                role_id INTEGER NOT NULL,
                permission_id INTEGER NOT NULL,
                created_at TEXT,
                tenant_id INTEGER NULL,
                PRIMARY KEY (role_id, permission_id)
            )'
        );
        $db->exec(
            "INSERT INTO roles (id, name, tenant_id) VALUES
                (2, 'admin', NULL),
                (3, 'manager', NULL),
                (4, 'cashier', NULL)"
        );
        $db->exec(
            "INSERT INTO permissions (name, description, category, tenant_id)
             VALUES ('category.view', 'View product categories', 'catalog', NULL)"
        );

        $migration = new ManageBranchCategories($db);
        $migration->up();
        $migration->up();

        self::assertSame(4, (int)$db->query(
            "SELECT COUNT(*) FROM permissions WHERE name LIKE 'category.%' AND tenant_id IS NULL"
        )->fetchColumn());
        self::assertSame(8, (int)$db->query(
            'SELECT COUNT(*) FROM role_permissions WHERE role_id IN (2, 3)'
        )->fetchColumn());
        self::assertSame(0, (int)$db->query(
            'SELECT COUNT(*) FROM role_permissions WHERE role_id = 4'
        )->fetchColumn());
    }
}
