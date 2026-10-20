<?php

declare(strict_types=1);

/**
 * Consolidate tenant-owned manager/finance roles into shared global roles.
 *
 * Permission sets are merged as a union because role_permissions is keyed by
 * (role_id, permission_id), so it cannot preserve tenant-specific overlays on
 * a shared role. Tenant-scoped permissions or permission links abort the
 * migration rather than being widened to every tenant.
 */
final class ConsolidateSharedOperationalRoles
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function up(): void
    {
        $startedTransaction = !$this->pdo->inTransaction();
        if ($startedTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $globalRoleIds = $this->globalRoleIds();
            $sourceRoles = $this->sourceRoles();
            $legacyBranchManagerRoleId = $this->legacyBranchManagerRoleId();
            $this->assertGlobalPermissionScopes($globalRoleIds, $legacyBranchManagerRoleId);
            $this->assertSourcePermissionScopes($sourceRoles);
            $this->assertTenantReferences($sourceRoles);

            if ($legacyBranchManagerRoleId !== null) {
                $this->mergePermissions($legacyBranchManagerRoleId, $globalRoleIds['manager']);
            }

            foreach ($sourceRoles as $sourceRole) {
                $targetRoleId = $globalRoleIds[(string) $sourceRole['name']];
                $this->mergePermissions((int) $sourceRole['id'], $targetRoleId);
                $this->migrateAssignments($sourceRole, $targetRoleId);
                $this->migratePrimaryRoleReferences($sourceRole, $targetRoleId);
            }

            $deletePermissions = $this->pdo->prepare('DELETE FROM role_permissions WHERE role_id = ?');
            $deleteRole = $this->pdo->prepare('DELETE FROM roles WHERE id = ?');
            foreach ($sourceRoles as $sourceRole) {
                $roleId = (int) $sourceRole['id'];
                $deletePermissions->execute([$roleId]);
                $deleteRole->execute([$roleId]);
            }

            if ($startedTransaction) {
                $this->pdo->commit();
            }
        } catch (Throwable $e) {
            if ($startedTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        echo sprintf(
            "Consolidated %d tenant-specific operational role(s) into shared global roles.\n",
            count($sourceRoles)
        );
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Migration 019 is forward-only; restoring tenant role copies requires a reviewed backup.'
        );
    }

    private function globalRoleIds(): array
    {
        $stmt = $this->pdo->query(
            "SELECT id, LOWER(name) AS name
             FROM roles
             WHERE tenant_id IS NULL
               AND LOWER(name) IN ('manager', 'finance_officer')"
        );
        $roleIds = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $role) {
            $roleIds[(string) $role['name']] = (int) $role['id'];
        }

        foreach (['manager', 'finance_officer'] as $requiredName) {
            if (!isset($roleIds[$requiredName])) {
                throw new RuntimeException("Required shared global role '{$requiredName}' is missing.");
            }
        }

        return $roleIds;
    }

    private function sourceRoles(): array
    {
        $stmt = $this->pdo->query(
            "SELECT id, LOWER(name) AS name, tenant_id
             FROM roles
             WHERE tenant_id IS NOT NULL
               AND LOWER(name) IN ('manager', 'finance_officer')
             ORDER BY id"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function legacyBranchManagerRoleId(): ?int
    {
        $stmt = $this->pdo->query(
            "SELECT id FROM roles
             WHERE tenant_id IS NULL AND LOWER(name) = 'branch_manager'
             LIMIT 1"
        );
        $roleId = $stmt->fetchColumn();
        return $roleId !== false ? (int) $roleId : null;
    }

    private function assertGlobalPermissionScopes(array $globalRoleIds, ?int $legacyBranchManagerRoleId): void
    {
        $roleIds = array_values($globalRoleIds);
        if ($legacyBranchManagerRoleId !== null) {
            $roleIds[] = $legacyBranchManagerRoleId;
        }
        $placeholders = implode(',', array_fill(0, count($roleIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*)
             FROM role_permissions rp
             JOIN permissions p ON p.id = rp.permission_id
             WHERE rp.role_id IN ({$placeholders})
               AND (rp.tenant_id IS NOT NULL OR p.tenant_id IS NOT NULL)"
        );
        $stmt->execute($roleIds);
        if ((int) $stmt->fetchColumn() !== 0) {
            throw new RuntimeException('A shared role already has tenant-scoped permission links.');
        }
    }

    private function assertSourcePermissionScopes(array $sourceRoles): void
    {
        if ($sourceRoles === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($sourceRoles), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*)
             FROM role_permissions rp
             JOIN permissions p ON p.id = rp.permission_id
             WHERE rp.role_id IN ({$placeholders})
               AND (rp.tenant_id IS NOT NULL OR p.tenant_id IS NOT NULL)"
        );
        $stmt->execute(array_map(static fn (array $role): int => (int) $role['id'], $sourceRoles));
        if ((int) $stmt->fetchColumn() !== 0) {
            throw new RuntimeException(
                'Tenant-scoped permissions exist on operational role copies; refusing to widen them globally.'
            );
        }
    }

    private function assertTenantReferences(array $sourceRoles): void
    {
        $primaryRefs = $this->pdo->prepare(
            'SELECT COUNT(*) FROM users WHERE role_id = ? AND (tenant_id IS NULL OR tenant_id <> ?)'
        );
        $assignments = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM users_role ur
             JOIN users u ON u.id = ur.user_id
             WHERE ur.role_id = ?
               AND (u.tenant_id IS NULL OR u.tenant_id <> ?
                    OR (ur.tenant_id IS NOT NULL AND ur.tenant_id <> ?))'
        );

        foreach ($sourceRoles as $role) {
            $roleId = (int) $role['id'];
            $tenantId = (int) $role['tenant_id'];
            $primaryRefs->execute([$roleId, $tenantId]);
            if ((int) $primaryRefs->fetchColumn() !== 0) {
                throw new RuntimeException("Role {$roleId} is assigned as a primary role across tenant boundaries.");
            }
            $assignments->execute([$roleId, $tenantId, $tenantId]);
            if ((int) $assignments->fetchColumn() !== 0) {
                throw new RuntimeException("Role {$roleId} has role assignments across tenant boundaries.");
            }
        }
    }

    private function mergePermissions(int $sourceRoleId, int $targetRoleId): void
    {
        $sourcePermissions = $this->pdo->prepare(
            'SELECT permission_id, created_at, created_by
             FROM role_permissions
             WHERE role_id = ?'
        );
        $sourcePermissions->execute([$sourceRoleId]);

        $exists = $this->pdo->prepare(
            'SELECT 1 FROM role_permissions WHERE role_id = ? AND permission_id = ?'
        );
        $insert = $this->pdo->prepare(
            'INSERT INTO role_permissions (role_id, permission_id, created_at, created_by, tenant_id)
             VALUES (?, ?, ?, ?, NULL)'
        );

        foreach ($sourcePermissions->fetchAll(PDO::FETCH_ASSOC) as $permission) {
            $permissionId = (int) $permission['permission_id'];
            $exists->execute([$targetRoleId, $permissionId]);
            if ($exists->fetchColumn() !== false) {
                continue;
            }
            $insert->execute([
                $targetRoleId,
                $permissionId,
                $permission['created_at'],
                $permission['created_by'],
            ]);
        }
    }

    private function migrateAssignments(array $sourceRole, int $targetRoleId): void
    {
        $sourceRoleId = (int) $sourceRole['id'];
        $tenantId = (int) $sourceRole['tenant_id'];
        $stmt = $this->pdo->prepare(
            'SELECT ur.user_id, ur.created_at, ur.created_by, ur.tenant_id
             FROM users_role ur
             WHERE ur.role_id = ?'
        );
        $stmt->execute([$sourceRoleId]);

        $exists = $this->pdo->prepare(
            'SELECT 1 FROM users_role WHERE user_id = ? AND role_id = ?'
        );
        $insert = $this->pdo->prepare(
            'INSERT INTO users_role (user_id, role_id, created_at, created_by, tenant_id)
             VALUES (?, ?, ?, ?, ?)'
        );
        $delete = $this->pdo->prepare(
            'DELETE FROM users_role WHERE user_id = ? AND role_id = ?'
        );

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $assignment) {
            $userId = (int) $assignment['user_id'];
            $exists->execute([$userId, $targetRoleId]);
            if ($exists->fetchColumn() === false) {
                $insert->execute([
                    $userId,
                    $targetRoleId,
                    $assignment['created_at'],
                    $assignment['created_by'],
                    $tenantId,
                ]);
            }
            $delete->execute([$userId, $sourceRoleId]);
        }
    }

    private function migratePrimaryRoleReferences(array $sourceRole, int $targetRoleId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET role_id = ? WHERE role_id = ? AND tenant_id = ?'
        );
        $stmt->execute([
            $targetRoleId,
            (int) $sourceRole['id'],
            (int) $sourceRole['tenant_id'],
        ]);
    }

}
