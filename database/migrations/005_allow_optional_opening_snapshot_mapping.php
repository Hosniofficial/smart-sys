<?php

declare(strict_types=1);

require_once __DIR__ . '/../MigrationDatabase.php';

/**
 * Makes the optional product/branch GL mapping reference nullable on cost snapshots.
 * The foreign key remains in place for non-NULL references.
 */
final class AllowOptionalOpeningSnapshotMapping
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? MigrationDatabase::connect($_ENV);
    }

    public function up(): void
    {
        $nullable = $this->mappingColumnIsNullable();
        if ($nullable === null) {
            throw new RuntimeException('inventory_cost_snapshot.product_branch_mapping_id does not exist.');
        }
        if ($nullable) {
            return;
        }

        $this->pdo->exec(
            'ALTER TABLE inventory_cost_snapshot MODIFY COLUMN product_branch_mapping_id INT(11) NULL'
        );
    }

    public function down(): void
    {
        $nullable = $this->mappingColumnIsNullable();
        if ($nullable === null || !$nullable) {
            return;
        }

        $nullCount = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM inventory_cost_snapshot WHERE product_branch_mapping_id IS NULL'
        )->fetchColumn();
        if ($nullCount > 0) {
            throw new RuntimeException(
                'Cannot restore NOT NULL while cost snapshots have no GL mapping; preserve the optional-reference migration.'
            );
        }

        $this->pdo->exec(
            'ALTER TABLE inventory_cost_snapshot MODIFY COLUMN product_branch_mapping_id INT(11) NOT NULL'
        );
    }

    private function mappingColumnIsNullable(): ?bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute(['inventory_cost_snapshot', 'product_branch_mapping_id']);
        $nullable = $stmt->fetchColumn();

        if ($nullable === false) {
            return null;
        }

        return $nullable === 'YES';
    }
}
