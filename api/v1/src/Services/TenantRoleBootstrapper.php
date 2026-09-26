<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Creates tenant-owned, editable copies of operational role templates.
 *
 * The global manager/branch_manager and finance_officer records are templates
 * only. Tenant users receive tenant-scoped roles so their administrator can
 * safely edit permissions without changing another tenant's access.
 */
final class TenantRoleBootstrapper
{
    public static function ensureDefaults(PDO $pdo, int $tenantId): array
    {
        if ($tenantId <= 0) {
            throw new \InvalidArgumentException('A valid tenant ID is required.');
        }

        $definitions = [
            'manager' => [
                'display_name' => 'Branch Supervisor',
                'description' => 'Tenant-editable branch supervisor role. Combines legacy manager and branch manager access.',
                'templates' => ['manager', 'branch_manager'],
                'extra_permissions' => ['sale.discount', 'product.purchase_price.view'],
            ],
            'finance_officer' => [
                'display_name' => 'Finance Officer',
                'description' => 'Tenant-editable finance role; assign only when required by the tenant.',
                'templates' => ['finance_officer'],
                'extra_permissions' => [],
            ],
        ];

        $roles = [];
        foreach ($definitions as $roleName => $definition) {
            $roleId = self::findTenantRole($pdo, $tenantId, $roleName);
            if ($roleId === null) {
                $insert = $pdo->prepare(
                    "INSERT INTO roles
                        (name, display_name, description, is_system_role, tenant_id, created_at, updated_at)
                     VALUES (?, ?, ?, 0, ?, NOW(), NOW())"
                );
                $insert->execute([
                    $roleName,
                    $definition['display_name'],
                    $definition['description'],
                    $tenantId,
                ]);
                $roleId = (int) $pdo->lastInsertId();
            }

            self::copyTemplatePermissions($pdo, $roleId, $definition['templates']);
            self::addPermissionsByName($pdo, $roleId, $definition['extra_permissions']);
            $roles[$roleName] = $roleId;
        }

        return $roles;
    }

    private static function findTenantRole(PDO $pdo, int $tenantId, string $name): ?int
    {
        $stmt = $pdo->prepare(
            'SELECT id FROM roles WHERE tenant_id = ? AND LOWER(name) = LOWER(?) LIMIT 1'
        );
        $stmt->execute([$tenantId, $name]);
        $roleId = $stmt->fetchColumn();
        return $roleId !== false ? (int) $roleId : null;
    }

    private static function copyTemplatePermissions(PDO $pdo, int $targetRoleId, array $templateNames): void
    {
        if ($templateNames === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($templateNames), '?'));
        $stmt = $pdo->prepare(
            "SELECT DISTINCT rp.permission_id
             FROM role_permissions rp
             JOIN roles template ON template.id = rp.role_id
             WHERE template.tenant_id IS NULL
               AND LOWER(template.name) IN ({$placeholders})"
        );
        $stmt->execute(array_map('strtolower', $templateNames));
        self::insertPermissionIds($pdo, $targetRoleId, $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private static function addPermissionsByName(PDO $pdo, int $targetRoleId, array $permissionNames): void
    {
        if ($permissionNames === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($permissionNames), '?'));
        $activeFilter = self::hasActiveFlag($pdo) ? ' AND is_active = 1' : '';
        $stmt = $pdo->prepare(
            "SELECT id FROM permissions WHERE LOWER(name) IN ({$placeholders}){$activeFilter}"
        );
        $stmt->execute(array_map('strtolower', $permissionNames));
        self::insertPermissionIds($pdo, $targetRoleId, $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private static function hasActiveFlag(PDO $pdo): bool
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'permissions'
               AND column_name = 'is_active'"
        );
        $stmt->execute();
        return (int) $stmt->fetchColumn() > 0;
    }

    private static function insertPermissionIds(PDO $pdo, int $roleId, array $permissionIds): void
    {
        if ($permissionIds === []) {
            return;
        }

        $insert = $pdo->prepare(
            'INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)'
        );
        foreach ($permissionIds as $permissionId) {
            $insert->execute([$roleId, (int) $permissionId]);
        }
    }
}
