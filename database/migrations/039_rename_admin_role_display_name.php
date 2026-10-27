<?php

declare(strict_types=1);

/** Clarify the tenant-wide administrator role without changing its permissions. */
final class RenameAdminRoleDisplayName
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function up(): void
    {
        $roleCount = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM roles
             WHERE tenant_id IS NULL AND LOWER(name) = 'admin'"
        )->fetchColumn();
        if ($roleCount !== 1) {
            throw new RuntimeException('Expected exactly one global admin role.');
        }

        $stmt = $this->pdo->prepare(
            "UPDATE roles
             SET display_name = 'مسؤول المنشأة'
             WHERE tenant_id IS NULL
               AND LOWER(name) = 'admin'
               AND COALESCE(display_name, '') <> 'مسؤول المنشأة'"
        );
        $stmt->execute();
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Migration 039 is forward-only; reverting the role title requires a reviewed decision.'
        );
    }
}
