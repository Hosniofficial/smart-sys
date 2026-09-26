<?php

declare(strict_types=1);

namespace App\Security;

use PDO;

// Restricts user-role assignments to roles available to the user's tenant.
// Tenant-owned roles are assignable only within their owning tenant. Global
// system-role templates are assignable to tenant users, except super_admin,
// which must never be granted through tenant user administration. Unscoped
// non-system roles are treated as orphaned configuration and are not assignable.
final class TenantRoleAssignmentPolicy
{
    public static function isReservedRoleName(string $roleName): bool
    {
        return in_array(strtolower(trim($roleName)), ['super_admin', 'admin'], true);
    }

    // Returns normalized role IDs, or null when any ID is invalid/unassignable.
    public static function validate(PDO $db, array $roleIds, int $tenantId, bool $allowSuperAdmin = false): ?array
    {
        if ($tenantId <= 0) {
            return null;
        }

        $normalized = [];
        foreach ($roleIds as $roleId) {
            if (!is_int($roleId) && !(is_string($roleId) && ctype_digit($roleId))) {
                return null;
            }

            $roleId = (int) $roleId;
            if ($roleId <= 0) {
                return null;
            }
            $normalized[$roleId] = $roleId;
        }
        $normalized = array_values($normalized);

        if ($normalized === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($normalized), '?'));
        $reservedNameCondition = $allowSuperAdmin
            ? "(LOWER(name) <> 'admin' AND (LOWER(name) <> 'super_admin' OR (tenant_id IS NULL AND is_system_role = 1)))"
            : "LOWER(name) NOT IN ('admin', 'super_admin')";
        $globalRoleCondition = $allowSuperAdmin
            ? "(LOWER(name) NOT IN ('admin', 'manager', 'branch_manager', 'finance_officer', 'super_admin')\n                   OR (LOWER(name) = 'super_admin' AND tenant_id IS NULL AND is_system_role = 1))"
            : "LOWER(name) NOT IN ('admin', 'manager', 'branch_manager', 'finance_officer', 'super_admin')";
        $stmt = $db->prepare(
            "SELECT id FROM roles
             WHERE id IN ({$placeholders})
               AND {$reservedNameCondition}
               AND (
                   tenant_id = ?
                   OR (tenant_id IS NULL AND is_system_role = 1 AND {$globalRoleCondition})
               )"
        );
        $stmt->execute([...$normalized, $tenantId]);
        $assignableIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $expectedIds = $normalized;
        sort($expectedIds);
        sort($assignableIds);

        return $expectedIds === $assignableIds ? $normalized : null;
    }
}
