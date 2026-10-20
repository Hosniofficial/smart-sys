<?php

declare(strict_types=1);

/** Keep opening-balance history while recording its stock and GL reversal. */
final class TrackOpeningBalanceReversals
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function up(): void
    {
        $this->addColumnIfMissing('reversed_at', 'DATETIME NULL');
        $this->addColumnIfMissing('reversed_by', 'INT(11) NULL');
        $this->addColumnIfMissing('reversal_journal_entry_id', 'INT(11) NULL');
        $this->addColumnIfMissing('reversal_inventory_transaction_id', 'INT(11) NULL');
        $this->addColumnIfMissing('inventory_transaction_id', 'INT(11) NULL');

        $this->backfillInventoryTransactions();

        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $this->pdo->exec(
                'CREATE INDEX IF NOT EXISTS idx_opening_balances_active_lookup
                 ON opening_balances (tenant_id, product_id, branch_id, reversed_at)'
            );
            return;
        }

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = ?
               AND index_name = ?'
        );
        $stmt->execute(['opening_balances', 'idx_opening_balances_active_lookup']);
        if ((int) $stmt->fetchColumn() === 0) {
            $this->pdo->exec(
                'CREATE INDEX idx_opening_balances_active_lookup
                 ON opening_balances (tenant_id, product_id, branch_id, reversed_at)'
            );
        }
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Migration 027 is forward-only; opening-balance reversal audit history must be retained.'
        );
    }

    private function addColumnIfMissing(string $column, string $definition): void
    {
        if ($this->columnExists($column)) {
            return;
        }

        $this->pdo->exec("ALTER TABLE opening_balances ADD COLUMN {$column} {$definition}");
    }

    private function columnExists(string $column): bool
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            foreach ($this->pdo->query('PRAGMA table_info(opening_balances)')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (($row['name'] ?? null) === $column) {
                    return true;
                }
            }
            return false;
        }

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = ?
               AND column_name = ?'
        );
        $stmt->execute(['opening_balances', $column]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function backfillInventoryTransactions(): void
    {
        $this->pdo->exec("
            UPDATE opening_balances
            SET inventory_transaction_id = (
                SELECT it.id
                FROM inventory_transactions it
                WHERE it.product_id = opening_balances.product_id
                  AND it.branch_to = opening_balances.branch_id
                  AND it.movement_type IN (
                      'opening_balance', 'initial_stock',
                      'opening_balance_manual', 'opening_balance_bulk'
                  )
                  AND ABS(it.quantity - opening_balances.quantity) <= 0.0001
                  AND ABS(COALESCE(it.unit_cost, 0) - opening_balances.unit_cost) <= 0.0001
                ORDER BY it.id DESC
                LIMIT 1
            )
            WHERE inventory_transaction_id IS NULL
        ");
    }
}
