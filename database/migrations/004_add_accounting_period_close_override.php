<?php

declare(strict_types=1);

require_once __DIR__ . '/../MigrationDatabase.php';


class AddAccountingPeriodCloseOverride
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? MigrationDatabase::connect($_ENV);
    }

    public function up(): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO permissions (name, description, category)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE description = VALUES(description), category = VALUES(category)'
        );
        $stmt->execute([
            'accounting.period.close.override',
            'Override unreconciled inventory or pending journal blockers when closing an accounting period',
            'accounting',
        ]);
    }

    public function down(): void
    {
        // Keep the permission row so existing role assignments are not orphaned.
    }
}
