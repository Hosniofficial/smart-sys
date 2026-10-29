<?php

declare(strict_types=1);

/** Add stable role identity and presentation ordering without changing role IDs. */
final class AddRoleCodesAndSortOrder
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function up(): void
    {
        $this->ensureColumn('role_code', 'VARCHAR(100) NULL');
        $this->ensureColumn('sort_order', 'INT NOT NULL DEFAULT 1000');

        $sharedRoles = [
            'super_admin' => 10,
            'admin' => 20,
            'manager' => 30,
            'cashier' => 40,
            'inventory_clerk' => 50,
            'finance_officer' => 60,
            'branch_manager' => 70,
        ];

        $updateShared = $this->pdo->prepare(
            'UPDATE roles
             SET role_code = ?, sort_order = ?
             WHERE tenant_id IS NULL AND LOWER(name) = ?'
        );
        foreach ($sharedRoles as $name => $sortOrder) {
            $updateShared->execute([$name, $sortOrder, $name]);
        }

        $customRoles = $this->pdo->query(
            "SELECT id, tenant_id
             FROM roles
             WHERE role_code IS NULL OR role_code = ''"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $updateCustom = $this->pdo->prepare(
            'UPDATE roles SET role_code = ?, sort_order = 1000 WHERE id = ?'
        );
        foreach ($customRoles as $role) {
            $tenantId = (int) ($role['tenant_id'] ?? 0);
            $code = $tenantId > 0
                ? 'tenant_' . $tenantId . '_role_' . (int) $role['id']
                : 'role_' . (int) $role['id'];
            $updateCustom->execute([$code, (int) $role['id']]);
        }

        $index = $this->pdo->prepare(
            "SELECT 1 FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = 'roles'
               AND index_name = 'idx_roles_scope_order'
             LIMIT 1"
        );
        $index->execute();
        if ($index->fetchColumn() === false) {
            $this->pdo->exec(
                'CREATE INDEX idx_roles_scope_order ON roles (tenant_id, sort_order, id)'
            );
        }
    }

    private function ensureColumn(string $name, string $definition): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
        );
        $stmt->execute(['roles', $name]);
        if ($stmt->fetchColumn() === false) {
            $this->pdo->exec("ALTER TABLE roles ADD COLUMN {$name} {$definition}");
        }
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Migration 046 is forward-only to preserve stable role identity history.'
        );
    }
}
