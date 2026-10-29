<?php

declare(strict_types=1);

require_once __DIR__ . '/../MigrationDatabase.php';

/** Adds the narrowly scoped permission used to override accounting-period close blockers. */
class AddAccountingPeriodCloseOverride
{
    public const REQUIRES_BACKUP_CONFIRMATION = true;

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? MigrationDatabase::connect($_ENV);
    }

    /** Add or refresh the permission definition; deliberately does not assign it to any role. */
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

    /**
     * This permission is intentionally retained to preserve role links and audit semantics.
     * Refuse to mark the migration rolled back while its schema data remains installed.
     */
    public function down(): void
    {
        throw new RuntimeException(
            'Migration 004 is intentionally irreversible; retain its ledger entry and permission row.'
        );
    }
}
