<?php

declare(strict_types=1);

/** Ensure branch category management permissions exist and are available to admins/managers. */
final class ManageBranchCategories
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function up(): void
    {
        $permissions = [
            'category.view' => 'View product categories',
            'category.create' => 'Create product categories',
            'category.edit' => 'Edit product categories',
            'category.delete' => 'Delete product categories',
        ];

        $startedTransaction = !$this->pdo->inTransaction();
        if ($startedTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $findPermission = $this->pdo->prepare(
                'SELECT id, tenant_id FROM permissions WHERE name = ? LIMIT 1'
            );
            $insertPermission = $this->pdo->prepare(
                'INSERT INTO permissions (name, description, category, created_at, tenant_id)
                 VALUES (?, ?, ?, CURRENT_TIMESTAMP, NULL)'
            );
            $permissionIds = [];
            foreach ($permissions as $name => $description) {
                $findPermission->execute([$name]);
                $existing = $findPermission->fetch(PDO::FETCH_ASSOC);
                if ($existing !== false) {
                    if ($existing['tenant_id'] !== null) {
                        throw new RuntimeException("Permission '{$name}' exists only in a tenant scope.");
                    }
                    $permissionIds[$name] = (int)$existing['id'];
                    continue;
                }

                $insertPermission->execute([$name, $description, 'catalog']);
                $permissionIds[$name] = (int)$this->pdo->lastInsertId();
            }

            $roleStmt = $this->pdo->query(
                "SELECT id, LOWER(name) AS name
                 FROM roles
                 WHERE tenant_id IS NULL AND LOWER(name) IN ('admin', 'manager')"
            );
            $roleIds = [];
            foreach ($roleStmt->fetchAll(PDO::FETCH_ASSOC) as $role) {
                $roleIds[(string)$role['name']] = (int)$role['id'];
            }
            foreach (['admin', 'manager'] as $requiredRole) {
                if (!isset($roleIds[$requiredRole])) {
                    throw new RuntimeException("Required global role '{$requiredRole}' is missing.");
                }
            }

            $exists = $this->pdo->prepare(
                'SELECT tenant_id FROM role_permissions
                 WHERE role_id = ? AND permission_id = ? LIMIT 1'
            );
            $insertGrant = $this->pdo->prepare(
                'INSERT INTO role_permissions (role_id, permission_id, created_at, tenant_id)
                 VALUES (?, ?, CURRENT_TIMESTAMP, NULL)'
            );
            foreach ($roleIds as $roleId) {
                foreach ($permissionIds as $permissionId) {
                    $exists->execute([$roleId, $permissionId]);
                    $existingGrantScope = $exists->fetchColumn();
                    if ($existingGrantScope !== false && $existingGrantScope !== null) {
                        throw new RuntimeException(
                            'A tenant-scoped category grant exists on a shared role.'
                        );
                    }
                    if ($existingGrantScope === false) {
                        $insertGrant->execute([$roleId, $permissionId]);
                    }
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

        echo "Ensured branch category permissions for admin and manager roles.\n";
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Migration 034 is forward-only; permission grants require a reviewed access change.'
        );
    }
}
