<?php

declare(strict_types=1);

/** Keep owner-contribution access available to tenant admins and platform super-admins. */
final class GrantOwnerContributionPermissionsToAdminRoles
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function up(): void
    {
        $permissionStmt = $this->pdo->prepare(
            "SELECT id FROM permissions
             WHERE tenant_id IS NULL AND name LIKE 'accounting.owner_contribution.%'
             ORDER BY name"
        );
        $permissionStmt->execute();
        $permissionIds = array_map('intval', $permissionStmt->fetchAll(PDO::FETCH_COLUMN));
        if (count($permissionIds) !== 3) {
            throw new RuntimeException(
                'Expected all three global owner-contribution permissions before assigning them.'
            );
        }

        $roleStmt = $this->pdo->prepare(
            'SELECT id FROM roles WHERE tenant_id IS NULL AND LOWER(name) = ? LIMIT 2'
        );
        $roleIds = [];
        foreach (['admin', 'super_admin'] as $roleName) {
            $roleStmt->execute([$roleName]);
            $matches = $roleStmt->fetchAll(PDO::FETCH_COLUMN);
            if (count($matches) !== 1) {
                throw new RuntimeException("Expected one global {$roleName} role.");
            }
            $roleIds[$roleName] = (int) $matches[0];
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO role_permissions (role_id, permission_id, created_at, tenant_id)
             VALUES (?, ?, NOW(), NULL)
             ON DUPLICATE KEY UPDATE tenant_id = NULL'
        );

        $startedTransaction = !$this->pdo->inTransaction();
        if ($startedTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            foreach ($roleIds as $roleId) {
                foreach ($permissionIds as $permissionId) {
                    $insert->execute([$roleId, $permissionId]);
                }
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

        echo "Granted owner-contribution permissions to global admin and super_admin roles.\n";
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Migration 030 is forward-only; removing these role grants requires a reviewed access change.'
        );
    }
}
