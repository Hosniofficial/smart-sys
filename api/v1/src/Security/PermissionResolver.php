<?php

declare(strict_types=1);

namespace App\Security;

use PDO;

/**
 * Resolves tenant-scoped role permissions and the role identity used in tokens.
 */
final class PermissionResolver
{
    /** Return effective permission names after tenant role and user overrides. */
    public static function getGrantedPermissionNames(PDO $db, int $userId, bool $applyUserOverrides = true): array
    {
        if ($userId <= 0) {
            return [];
        }

        $userStmt = $db->prepare('SELECT tenant_id FROM users WHERE id = ? LIMIT 1');
        $userStmt->execute([$userId]);
        $tenantValue = $userStmt->fetchColumn();
        if ($tenantValue === false) {
            return [];
        }
        $tenantId = (int) ($tenantValue ?? 0);

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
            "SELECT DISTINCT p.name, r.id AS role_id
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
               AND COALESCE(p.is_active, 1) = 1
               AND (rp.tenant_id IS NULL OR rp.tenant_id = u.tenant_id)
               AND (p.tenant_id IS NULL OR p.tenant_id = u.tenant_id)"
        );
        $stmt->execute([$userId]);

        $grantedByRole = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $roleId = (int) $row['role_id'];
            $grantedByRole[$roleId][(string) $row['name']] = true;
        }

        $assignedRoles = $db->prepare(
            "SELECT DISTINCT r.id
             FROM users u
             {$roleJoin}
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
               )"
        );
        $assignedRoles->execute([$userId]);
        $roleIds = array_map('intval', $assignedRoles->fetchAll(PDO::FETCH_COLUMN) ?: []);
        foreach ($roleIds as $roleId) {
            $grantedByRole[$roleId] ??= [];
        }

        if ($tenantId > 0 && $roleIds !== []) {
            $overrides = self::getOverrides($db, $tenantId, 'role', $roleIds);
            foreach ($overrides as $override) {
                $roleId = (int) $override['subject_id'];
                $name = (string) $override['name'];
                if ($override['effect'] === 'allow') {
                    $grantedByRole[$roleId][$name] = true;
                } else {
                    unset($grantedByRole[$roleId][$name]);
                }
            }
        }

        $granted = [];
        foreach ($grantedByRole as $permissions) {
            foreach ($permissions as $name => $_) {
                $granted[$name] = true;
            }
        }

        if ($applyUserOverrides && $tenantId > 0) {
            foreach (self::getOverrides($db, $tenantId, 'user', [$userId]) as $override) {
                $name = (string) $override['name'];
                if ($override['effect'] === 'allow') {
                    $granted[$name] = true;
                } else {
                    unset($granted[$name]);
                }
            }
        }

        $names = array_keys($granted);
        sort($names, SORT_STRING);
        return $names;
    }

    /** Return one role's effective permissions within the given tenant. */
    public static function getRoleGrantedPermissionNames(PDO $db, int $tenantId, int $roleId): array
    {
        if ($tenantId <= 0 || $roleId <= 0) {
            return [];
        }

        $stmt = $db->prepare(
            'SELECT DISTINCT p.name
             FROM role_permissions rp
             JOIN permissions p ON p.id = rp.permission_id
             WHERE rp.role_id = ?
               AND (rp.tenant_id IS NULL OR rp.tenant_id = ?)
               AND (p.tenant_id IS NULL OR p.tenant_id = ?)
               AND COALESCE(p.is_active, 1) = 1'
        );
        $stmt->execute([$roleId, $tenantId, $tenantId]);
        $granted = array_fill_keys(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []), true);

        foreach (self::getOverrides($db, $tenantId, 'role', [$roleId]) as $override) {
            $name = (string) $override['name'];
            if ($override['effect'] === 'allow') {
                $granted[$name] = true;
            } else {
                unset($granted[$name]);
            }
        }

        $names = array_keys($granted);
        sort($names, SORT_STRING);
        return $names;
    }

    /** @return array<int, array{subject_id:string,name:string,effect:string}> */
    private static function getOverrides(PDO $db, int $tenantId, string $subjectType, array $subjectIds): array
    {
        if ($subjectIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($subjectIds), '?'));
        $stmt = $db->prepare(
            "SELECT po.subject_id, p.name, po.effect
             FROM permission_overrides po
             JOIN permissions p ON p.id = po.permission_id
             WHERE po.tenant_id = ?
               AND po.subject_type = ?
               AND po.subject_id IN ({$placeholders})
               AND (p.tenant_id IS NULL OR p.tenant_id = ?)
               AND COALESCE(p.is_active, 1) = 1"
        );
        $stmt->execute(array_merge([$tenantId, $subjectType], $subjectIds, [$tenantId]));
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
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
