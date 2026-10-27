<?php

declare(strict_types=1);

/**
 * Keep manager as the single operational manager role and use its user-facing
 * title consistently across existing installations.
 */
final class ConsolidateBranchManagerRole
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
            $managerRoleId = $this->globalManagerRoleId();
            $this->assertNoTenantBranchManagerRoles();
            $branchManagerRoleIds = $this->globalBranchManagerRoleIds();

            $roleIds = [$managerRoleId, ...$branchManagerRoleIds];
            $this->assertGlobalPermissionScopes($roleIds);

            foreach ($branchManagerRoleIds as $branchManagerRoleId) {
                $this->mergePermissions($branchManagerRoleId, $managerRoleId);
                $this->migrateAssignments($branchManagerRoleId, $managerRoleId);
                $this->pdo->prepare('UPDATE users SET role_id = ? WHERE role_id = ?')
                    ->execute([$managerRoleId, $branchManagerRoleId]);
                $this->pdo->prepare('DELETE FROM role_permissions WHERE role_id = ?')
                    ->execute([$branchManagerRoleId]);
                $this->pdo->prepare('DELETE FROM roles WHERE id = ?')
                    ->execute([$branchManagerRoleId]);
            }

            $this->pdo->exec(
                "UPDATE roles SET display_name = 'مدير الفرع' WHERE LOWER(name) = 'manager'"
            );

            if ($startedTransaction) {
                $this->pdo->commit();
            }
        } catch (Throwable $e) {
            if ($startedTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Migration 033 is forward-only; restoring the legacy role requires a reviewed backup.'
        );
    }

    private function globalManagerRoleId(): int
    {
        $stmt = $this->pdo->query(
            "SELECT id FROM roles WHERE tenant_id IS NULL AND LOWER(name) = 'manager'"
        );
        $roleIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        if (count($roleIds) !== 1) {
            throw new RuntimeException('Expected exactly one global manager role.');
        }

        return $roleIds[0];
    }

    private function assertNoTenantBranchManagerRoles(): void
    {
        $stmt = $this->pdo->query(
            "SELECT id FROM roles WHERE tenant_id IS NOT NULL AND LOWER(name) = 'branch_manager' LIMIT 1"
        );
        if ($stmt->fetchColumn() !== false) {
            throw new RuntimeException(
                'Tenant-scoped branch_manager roles require a tenant-specific migration review.'
            );
        }
    }

    /**
     * @return int[]
     */
    private function globalBranchManagerRoleIds(): array
    {
        $stmt = $this->pdo->query(
            "SELECT id FROM roles WHERE tenant_id IS NULL AND LOWER(name) = 'branch_manager' ORDER BY id"
        );
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @param int[] $roleIds
     */
    private function assertGlobalPermissionScopes(array $roleIds): void
    {
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
            throw new RuntimeException(
                'Tenant-scoped permissions exist on a shared manager role; refusing to widen them globally.'
            );
        }
    }

    private function mergePermissions(int $sourceRoleId, int $targetRoleId): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT permission_id, created_at, created_by
             FROM role_permissions WHERE role_id = ?'
        );
        $stmt->execute([$sourceRoleId]);

        $exists = $this->pdo->prepare(
            'SELECT 1 FROM role_permissions WHERE role_id = ? AND permission_id = ?'
        );
        $insert = $this->pdo->prepare(
            'INSERT INTO role_permissions (role_id, permission_id, created_at, created_by, tenant_id)
             VALUES (?, ?, ?, ?, NULL)'
        );
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $permission) {
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

    private function migrateAssignments(int $sourceRoleId, int $targetRoleId): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT user_id, created_at, created_by, tenant_id
             FROM users_role WHERE role_id = ?'
        );
        $stmt->execute([$sourceRoleId]);
        $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

        foreach ($assignments as $assignment) {
            $userId = (int) $assignment['user_id'];
            $exists->execute([$userId, $targetRoleId]);
            if ($exists->fetchColumn() === false) {
                $insert->execute([
                    $userId,
                    $targetRoleId,
                    $assignment['created_at'],
                    $assignment['created_by'],
                    $assignment['tenant_id'],
                ]);
            }
            $delete->execute([$userId, $sourceRoleId]);
        }
    }
}
