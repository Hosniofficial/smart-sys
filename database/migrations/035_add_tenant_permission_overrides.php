<?php

declare(strict_types=1);

/** Add tenant-scoped role and user permission overrides without editing shared role templates. */
final class AddTenantPermissionOverrides
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function up(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS permission_overrides (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                tenant_id INT NOT NULL,
                subject_type ENUM('role', 'user') NOT NULL,
                subject_id INT NOT NULL,
                permission_id INT NOT NULL,
                effect ENUM('allow', 'deny') NOT NULL,
                reason VARCHAR(500) NOT NULL,
                created_by INT NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_permission_override (tenant_id, subject_type, subject_id, permission_id),
                KEY idx_permission_override_subject (tenant_id, subject_type, subject_id),
                KEY idx_permission_override_permission (permission_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $this->ensurePermissionColumn('display_name', 'VARCHAR(255) NULL');
        $this->ensurePermissionColumn('is_active', 'TINYINT(1) NOT NULL DEFAULT 1');

        $managementPermissions = [
            'role.view' => ['View roles', 'عرض الأدوار', 'rbac'],
            'role.edit' => ['Edit roles', 'تعديل الأدوار', 'rbac'],
            'permission.view' => ['View permissions', 'عرض الصلاحيات', 'rbac'],
            'permission.assign' => ['Assign permissions', 'إسناد الصلاحيات', 'rbac'],
        ];

        $find = $this->pdo->prepare('SELECT id, tenant_id FROM permissions WHERE name = ? LIMIT 1');
        $insert = $this->pdo->prepare(
            'INSERT INTO permissions (name, display_name, description, category, tenant_id, created_at)
             VALUES (?, ?, ?, ?, NULL, CURRENT_TIMESTAMP)'
        );
        $permissionIds = [];
        foreach ($managementPermissions as $name => [$description, $displayName, $category]) {
            $find->execute([$name]);
            $existing = $find->fetch(PDO::FETCH_ASSOC);
            if ($existing === false) {
                $insert->execute([$name, $displayName, $description, $category]);
                $permissionId = $this->pdo->lastInsertId();
            } else {
                if ($existing['tenant_id'] !== null) {
                    throw new RuntimeException("Permission '{$name}' exists only in a tenant scope.");
                }
                $permissionId = $existing['id'];
            }
            $permissionIds[$name] = (int) $permissionId;
        }

        $roles = $this->pdo->prepare(
            "SELECT id, tenant_id
             FROM roles
             WHERE LOWER(name) = 'admin'
               AND (tenant_id IS NULL OR tenant_id > 0)"
        );
        $roles->execute();
        $insertGrant = $this->pdo->prepare(
            'INSERT IGNORE INTO role_permissions (role_id, permission_id, created_at, tenant_id)
             VALUES (?, ?, CURRENT_TIMESTAMP, ?)'
        );

        foreach ($roles->fetchAll(PDO::FETCH_ASSOC) as $role) {
            foreach ($permissionIds as $permissionId) {
                $insertGrant->execute([
                    (int) $role['id'],
                    $permissionId,
                    $role['tenant_id'] === null ? null : (int) $role['tenant_id'],
                ]);
            }
        }
    }

    private function ensurePermissionColumn(string $name, string $definition): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
        );
        $stmt->execute(['permissions', $name]);
        if ($stmt->fetchColumn() === false) {
            $this->pdo->exec("ALTER TABLE permissions ADD COLUMN {$name} {$definition}");
        }
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Migration 035 is forward-only to preserve permission audit history and overrides.'
        );
    }
}
