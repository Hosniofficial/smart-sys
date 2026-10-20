<?php

declare(strict_types=1);

namespace App\Security;

use PDO;

/** Validates role IDs against tenant-owned and supported shared roles. */
final class TenantRoleAssignmentPolicy
{
    /** Return whether a role name is reserved for platform administration. */
    public static function isReservedRoleName(string $roleName): bool
    {
        return in_array(strtolower(trim($roleName)), ['super_admin', 'admin', 'administrator'], true);
    }

    /**
     * Return unique, normalized assignable role IDs, or null if any are invalid.
     *
     * @return int[]|null
     */
    public static function validate(PDO $db, array $roleIds, int $tenantId): ?array
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
        $stmt = $db->prepare(
            "SELECT id FROM roles
             WHERE id IN ({$placeholders})
               AND LOWER(name) NOT IN ('admin', 'super_admin', 'administrator')
               AND (
                   tenant_id = ?
                   OR (
                       tenant_id IS NULL
                         AND (
                             (
                                 is_system_role = 1
                                 AND LOWER(name) NOT IN ('admin', 'manager', 'branch_manager', 'finance_officer', 'super_admin')
                             )
                             OR LOWER(name) IN ('manager', 'cashier', 'finance_officer', 'inventory_clerk')
                         )
                     )
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
