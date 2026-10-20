<?php

declare(strict_types=1);

require_once __DIR__ . '/../MigrationDatabase.php';

/** Grant voucher editing to the reviewed administrative, managerial, and finance roles. */
final class GrantCashVoucherEditPermission
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function up(): void
    {
        $permissionStmt = $this->pdo->prepare(
            "SELECT id FROM permissions WHERE name = 'voucher.edit' AND tenant_id IS NULL LIMIT 1"
        );
        $permissionStmt->execute();
        $permissionId = $permissionStmt->fetchColumn();
        if ($permissionId === false) {
            throw new RuntimeException('Global voucher.edit permission is missing; run migration 017 first.');
        }

        $rolesStmt = $this->pdo->query(
            "SELECT id, name FROM roles
             WHERE tenant_id IS NULL AND name IN ('admin', 'manager', 'finance_officer')"
        );
        $roles = $rolesStmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        $missingRoles = array_diff(['admin', 'manager', 'finance_officer'], array_values($roles));
        if ($missingRoles !== []) {
            throw new RuntimeException(
                'Required global roles are missing: ' . implode(', ', $missingRoles)
            );
        }

        $exists = $this->pdo->prepare(
            'SELECT 1 FROM role_permissions
             WHERE role_id = ? AND permission_id = ? AND tenant_id IS NULL LIMIT 1'
        );
        $insert = $this->pdo->prepare(
            'INSERT INTO role_permissions (role_id, permission_id, created_at, tenant_id)
             VALUES (?, ?, NOW(), NULL)'
        );

        $added = 0;
        $this->pdo->beginTransaction();
        try {
            foreach ($roles as $roleId => $roleName) {
                $exists->execute([(int) $roleId, (int) $permissionId]);
                if ($exists->fetchColumn()) {
                    continue;
                }
                $insert->execute([(int) $roleId, (int) $permissionId]);
                $added++;
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        echo sprintf("Granted voucher.edit to admin, manager, and finance_officer (%d new assignment(s)).\n", $added);
    }

    public function down(): void
    {
        echo "No rollback: reviewed role assignments are retained for auditability.\n";
    }
}
