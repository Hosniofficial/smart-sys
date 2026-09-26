<?php

declare(strict_types=1);

require_once __DIR__ . '/../../api/v1/src/Services/TenantRoleBootstrapper.php';

use App\Services\TenantRoleBootstrapper;

/**
 * Migration: 006_tenant_scoped_operational_roles
 *
 * Replaces the shared manager/branch_manager and finance_officer assignments
 * with editable tenant-owned roles. Manager permissions are the union of both
 * legacy supervisor templates. Existing custom permissions on tenant-local
 * roles are preserved; global templates remain in place for historical rows.
 *
 * This migration is intentionally forward-only because reversing user-role
 * assignments would require knowing which legacy role each user originally had.
 */
final class TenantScopedOperationalRoles
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function up(): void
    {
        $this->ensureTenantScopedRoleNames();

        $startedTransaction = !$this->pdo->inTransaction();
        if ($startedTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $this->ensurePermissions();

            $tenantIds = $this->pdo->query('SELECT id FROM tenants ORDER BY id')
                ->fetchAll(PDO::FETCH_COLUMN);
            $legacyRoleIds = $this->findLegacyRoleIds();
            $adminRoleId = $this->findGlobalRoleId('admin');
            $permissionIds = $this->permissionIds([
                'sale.discount',
                'product.purchase_price.view',
            ]);

            if ($adminRoleId !== null) {
                $this->insertRolePermissions($adminRoleId, $permissionIds);
            }
            $this->removeSensitivePermissionsFromGlobalCashier($permissionIds);

            foreach ($tenantIds as $tenantId) {
                $tenantId = (int) $tenantId;
                $roles = TenantRoleBootstrapper::ensureDefaults($this->pdo, $tenantId);
                $this->migrateAssignments($tenantId, $roles, $legacyRoleIds);
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
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Migration 006 is forward-only; restore a reviewed backup to roll back role assignments.'
        );
    }

    /**
     * Tenant-owned role templates reuse canonical names, so role names must be
     * unique within a tenant rather than globally. A generated scope key keeps
     * global (NULL tenant) roles unique on MariaDB, where NULLs in a composite
     * unique index would otherwise allow duplicate system templates.
     */
    private function ensureTenantScopedRoleNames(): void
    {
        $columnStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'roles'
               AND column_name = 'tenant_scope_id'"
        );
        $columnStmt->execute();
        if ((int) $columnStmt->fetchColumn() === 0) {
            $this->pdo->exec(
                'ALTER TABLE roles ADD COLUMN tenant_scope_id INT AS (IFNULL(tenant_id, 0)) PERSISTENT'
            );
        }

        $indexRows = $this->pdo->query('SHOW INDEX FROM roles')->fetchAll(PDO::FETCH_ASSOC);
        $indexes = [];
        foreach ($indexRows as $indexRow) {
            $keyName = (string) $indexRow['Key_name'];
            $indexes[$keyName]['unique'] = (int) $indexRow['Non_unique'] === 0;
            $indexes[$keyName]['columns'][(int) $indexRow['Seq_in_index']] = (string) $indexRow['Column_name'];
        }

        foreach ($indexes as $keyName => $index) {
            $columns = $index['columns'];
            ksort($columns);
            if ($index['unique'] && array_values($columns) === ['name']) {
                $quotedKeyName = '`' . str_replace('`', '``', $keyName) . '`';
                $this->pdo->exec("ALTER TABLE roles DROP INDEX {$quotedKeyName}");
            }
        }

        $indexStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = 'roles'
               AND index_name = 'uq_roles_name_tenant_scope'"
        );
        $indexStmt->execute();
        if ((int) $indexStmt->fetchColumn() === 0) {
            $duplicates = (int) $this->pdo->query(
                'SELECT COUNT(*) FROM (
                    SELECT name, tenant_scope_id
                    FROM roles
                    GROUP BY name, tenant_scope_id
                    HAVING COUNT(*) > 1
                ) duplicate_role_scopes'
            )->fetchColumn();
            if ($duplicates > 0) {
                throw new RuntimeException('Cannot scope role names: duplicate names already exist within a tenant.');
            }
            $this->pdo->exec(
                'ALTER TABLE roles ADD UNIQUE INDEX uq_roles_name_tenant_scope (name, tenant_scope_id)'
            );
        }
    }

    private function ensurePermissions(): void
    {
        $permissions = [
            [
                'name' => 'sale.discount',
                'display_name' => 'Apply Sale Discount',
                'description' => 'السماح بتطبيق خصم على البيع ضمن حدود المنتج',
                'category' => 'sales_management',
                'resource' => 'sales',
                'action' => 'discount',
                'is_active' => 1,
            ],
            [
                'name' => 'product.purchase_price.view',
                'display_name' => 'View Product Purchase Price',
                'description' => 'عرض سعر شراء المنتج ومعلومات التكلفة',
                'category' => 'product_pricing',
                'resource' => 'products',
                'action' => 'view_purchase_price',
                'is_active' => 1,
            ],
        ];

        $availableColumns = array_flip(array_column(
            $this->pdo->query('SHOW COLUMNS FROM permissions')->fetchAll(PDO::FETCH_ASSOC),
            'Field'
        ));
        if (!isset($availableColumns['name'], $availableColumns['description'], $availableColumns['category'])) {
            throw new RuntimeException('The permissions table is missing a required name, description, or category column.');
        }

        foreach ($permissions as $permission) {
            $columns = array_values(array_intersect(array_keys($permission), array_keys($availableColumns)));
            $updates = array_values(array_filter($columns, static fn (string $column): bool => $column !== 'name'));
            $quotedColumns = array_map(static fn (string $column): string => "`{$column}`", $columns);
            $placeholders = implode(', ', array_fill(0, count($columns), '?'));
            $updateClause = implode(', ', array_map(
                static fn (string $column): string => "`{$column}` = VALUES(`{$column}`)",
                $updates
            ));
            $sql = 'INSERT INTO permissions (' . implode(', ', $quotedColumns) . ") VALUES ({$placeholders})";
            if ($updateClause !== '') {
                $sql .= ' ON DUPLICATE KEY UPDATE ' . $updateClause;
            }
            $this->pdo->prepare($sql)->execute(array_map(
                static fn (string $column): mixed => $permission[$column],
                $columns
            ));
        }
    }

    private function findLegacyRoleIds(): array
    {
        $stmt = $this->pdo->query(
            "SELECT id, LOWER(name) AS name
             FROM roles
             WHERE tenant_id IS NULL
               AND LOWER(name) IN ('manager', 'branch_manager', 'finance_officer')"
        );
        $ids = ['manager' => [], 'branch_manager' => [], 'finance_officer' => []];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $role) {
            $ids[$role['name']][] = (int) $role['id'];
        }
        return $ids;
    }

    private function findGlobalRoleId(string $name): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM roles WHERE tenant_id IS NULL AND LOWER(name) = LOWER(?) LIMIT 1'
        );
        $stmt->execute([$name]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int) $id : null;
    }

    private function permissionIds(array $names): array
    {
        if ($names === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($names), '?'));
        $activeFilter = $this->hasPermissionActiveFlag() ? ' AND is_active = 1' : '';
        $stmt = $this->pdo->prepare(
            "SELECT id FROM permissions WHERE LOWER(name) IN ({$placeholders}){$activeFilter}"
        );
        $stmt->execute(array_map('strtolower', $names));
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function hasPermissionActiveFlag(): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'permissions'
               AND column_name = 'is_active'"
        );
        $stmt->execute();
        return (int) $stmt->fetchColumn() > 0;
    }

    private function insertRolePermissions(int $roleId, array $permissionIds): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)'
        );
        foreach ($permissionIds as $permissionId) {
            $stmt->execute([$roleId, $permissionId]);
        }
    }

    private function removeSensitivePermissionsFromGlobalCashier(array $permissionIds): void
    {
        $cashierRoleId = $this->findGlobalRoleId('cashier');
        if ($cashierRoleId === null || $permissionIds === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($permissionIds), '?'));
        $stmt = $this->pdo->prepare(
            "DELETE FROM role_permissions
             WHERE role_id = ? AND permission_id IN ({$placeholders})"
        );
        $stmt->execute(array_merge([$cashierRoleId], $permissionIds));
    }

    private function migrateAssignments(int $tenantId, array $tenantRoles, array $legacyRoleIds): void
    {
        $managerIds = array_values(array_unique(array_merge(
            $legacyRoleIds['manager'],
            $legacyRoleIds['branch_manager']
        )));
        $financeIds = $legacyRoleIds['finance_officer'];
        $legacyIds = array_values(array_unique(array_merge($managerIds, $financeIds)));
        if ($legacyIds === []) {
            return;
        }

        $rolePlaceholders = implode(',', array_fill(0, count($legacyIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT u.id, u.role_id, ur.role_id AS assigned_role_id, ur.created_by
             FROM users u
             LEFT JOIN users_role ur
               ON ur.user_id = u.id
              AND (ur.tenant_id = u.tenant_id OR ur.tenant_id IS NULL)
             WHERE u.tenant_id = ?
               AND (u.role_id IN ({$rolePlaceholders}) OR ur.role_id IN ({$rolePlaceholders}))"
        );
        $stmt->execute(array_merge([$tenantId], $legacyIds, $legacyIds));
        $users = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $userId = (int) $row['id'];
            $users[$userId] ??= [
                'primary_role_id' => (int) $row['role_id'],
                'manager' => false,
                'finance_officer' => false,
                'created_by' => $row['created_by'] !== null ? (int) $row['created_by'] : $userId,
            ];
            foreach ([$row['role_id'], $row['assigned_role_id']] as $roleId) {
                if ($roleId === null) {
                    continue;
                }
                $roleId = (int) $roleId;
                if (in_array($roleId, $managerIds, true)) {
                    $users[$userId]['manager'] = true;
                }
                if (in_array($roleId, $financeIds, true)) {
                    $users[$userId]['finance_officer'] = true;
                }
            }
        }

        $insert = $this->pdo->prepare(
            'INSERT IGNORE INTO users_role (user_id, role_id, created_by, tenant_id)
             VALUES (?, ?, ?, ?)'
        );
        $updatePrimaryRole = $this->pdo->prepare(
            'UPDATE users SET role_id = ? WHERE id = ? AND tenant_id = ?'
        );

        foreach ($users as $userId => $assignment) {
            if ($assignment['manager']) {
                $insert->execute([$userId, $tenantRoles['manager'], $assignment['created_by'], $tenantId]);
            }
            if ($assignment['finance_officer']) {
                $insert->execute([$userId, $tenantRoles['finance_officer'], $assignment['created_by'], $tenantId]);
            }

            if (in_array($assignment['primary_role_id'], $managerIds, true)) {
                $updatePrimaryRole->execute([$tenantRoles['manager'], $userId, $tenantId]);
            } elseif (in_array($assignment['primary_role_id'], $financeIds, true)) {
                $updatePrimaryRole->execute([$tenantRoles['finance_officer'], $userId, $tenantId]);
            }
        }

        $delete = $this->pdo->prepare(
            "DELETE ur FROM users_role ur
             JOIN users u ON u.id = ur.user_id
             WHERE u.tenant_id = ?
               AND ur.role_id IN ({$rolePlaceholders})"
        );
        $delete->execute(array_merge([$tenantId], $legacyIds));
    }
}
