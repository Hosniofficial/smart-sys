<?php

declare(strict_types=1);

require_once __DIR__ . '/../MigrationDatabase.php';

/** Add a distinct permission for revising posted cash vouchers. */
final class AddCashVoucherEditPermission
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function up(): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO permissions (name, description, category, created_at, tenant_id)
             SELECT 'voucher.edit', 'Edit cash vouchers', 'vouchers', NOW(), NULL
             WHERE NOT EXISTS (
                 SELECT 1 FROM permissions WHERE name = 'voucher.edit' AND tenant_id IS NULL
             )"
        );
        $stmt->execute();
        echo "Ensured voucher.edit permission exists; assign it only to roles allowed to edit vouchers.\n";
    }

    public function down(): void
    {
        echo "No rollback: permission assignments and audit history are retained.\n";
    }
}
