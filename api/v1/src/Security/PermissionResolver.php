<?php

declare(strict_types=1);

namespace App\Security;

use PDO;

/**
 * Resolves tenant-scoped role permissions and the role identity used in tokens.
 */
final class PermissionResolver
{
    /** Return the distinct permission names granted through the user's roles. */
    public static function getGrantedPermissionNames(PDO $db, int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $assignment = $db->prepare('SELECT 1 FROM users_role WHERE user_id = ? LIMIT 1');
        $assignment->execute([$userId]);
        $hasExplicitRoles = $assignment->fetchColumn() !== false;

        $roleJoin = $hasExplicitRoles
            ? 'JOIN users_role ur ON ur.user_id = u.id
               JOIN roles r ON r.id = ur.role_id'
            : 'JOIN roles r ON r.id = u.role_id';
        $assignmentScope = $hasExplicitRoles
            ? 'AND (ur.tenant_id IS NULL OR ur.tenant_id = u.tenant_id)'
            : '';

        $stmt = $db->prepare(
            "SELECT DISTINCT p.name
             FROM users u
             {$roleJoin}
             JOIN role_permissions rp ON rp.role_id = r.id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE u.id = ?
               {$assignmentScope}
               AND (
                   (r.tenant_id = u.tenant_id AND LOWER(r.name) NOT IN ('super_admin', 'administrator'))
                   OR (
                       r.tenant_id IS NULL
                       AND (
                           (
                               LOWER(r.name) = 'super_admin'
                               AND COALESCE(u.tenant_id, 0) = 0
                           )
                           OR (
                               (
                                   r.is_system_role = 1
                                   OR LOWER(r.name) IN ('manager', 'cashier', 'finance_officer', 'inventory_clerk')
                               )
                               AND LOWER(r.name) NOT IN ('super_admin', 'administrator')
                           )
                       )
                   )
               )
               AND (rp.tenant_id IS NULL OR rp.tenant_id = u.tenant_id)
               AND (p.tenant_id IS NULL OR p.tenant_id = u.tenant_id)"
        );
        $stmt->execute([$userId]);

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /**
     * Resolve the role identity exposed to authentication clients.
     * Explicit assignments take precedence over users.role_id.
     *
     * @return array{id:int,name:string}|null
     */
    public static function getEffectiveRole(PDO $db, int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $userStmt = $db->prepare(
            'SELECT tenant_id, role_id, is_owner FROM users WHERE id = ? LIMIT 1'
        );
        $userStmt->execute([$userId]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            return null;
        }

        $tenantId = (int) ($user['tenant_id'] ?? 0);
        $primaryRoleId = (int) ($user['role_id'] ?? 0);

        if ($tenantId > 0) {
            $assignment = $db->prepare('SELECT 1 FROM users_role WHERE user_id = ? LIMIT 1');
            $assignment->execute([$userId]);
            $hasExplicitRoles = $assignment->fetchColumn() !== false;

            if ($hasExplicitRoles) {
                $roleStmt = $db->prepare(
                    "SELECT r.id, r.name
                     FROM users_role ur
                     JOIN roles r ON r.id = ur.role_id
                     WHERE ur.user_id = ?
                       AND (ur.tenant_id IS NULL OR ur.tenant_id = ?)
                       AND (r.tenant_id IS NULL OR r.tenant_id = ?)
                       AND LOWER(r.name) NOT IN ('super_admin', 'administrator')
                     ORDER BY
                       CASE
                           WHEN ? = 1 AND LOWER(r.name) = 'admin' THEN 0
                           WHEN r.id = ? THEN 1
                           ELSE 2
                       END,
                       r.id
                     LIMIT 1"
                );
                $roleStmt->execute([
                    $userId,
                    $tenantId,
                    $tenantId,
                    (int) ($user['is_owner'] ?? 0),
                    $primaryRoleId,
                ]);
                $role = $roleStmt->fetch(PDO::FETCH_ASSOC);

                return $role
                    ? ['id' => (int) $role['id'], 'name' => (string) $role['name']]
                    : null;
            }

            $roleStmt = $db->prepare(
                "SELECT r.id, r.name
                 FROM roles r
                 WHERE r.id = ?
                   AND (r.tenant_id IS NULL OR r.tenant_id = ?)
                   AND LOWER(r.name) NOT IN ('super_admin', 'administrator')
                 LIMIT 1"
            );
            $roleStmt->execute([$primaryRoleId, $tenantId]);
        } else {
            $roleStmt = $db->prepare(
                "SELECT r.id, r.name
                 FROM roles r
                 WHERE r.id = ?
                   AND r.tenant_id IS NULL
                   AND LOWER(r.name) = 'super_admin'
                 LIMIT 1"
            );
            $roleStmt->execute([$primaryRoleId]);
        }

        $role = $roleStmt->fetch(PDO::FETCH_ASSOC);
        return $role
            ? ['id' => (int) $role['id'], 'name' => (string) $role['name']]
            : null;
    }

    /** Check whether the resolved roles grant this exact permission name. */
    public static function hasPermission(PDO $db, int $userId, string $permissionName): bool
    {
        return in_array(
            $permissionName,
            self::getGrantedPermissionNames($db, $userId),
            true
        );
    }
}
