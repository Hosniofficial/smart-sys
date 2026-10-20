<?php

declare(strict_types=1);

require_once __DIR__ . '/../MigrationDatabase.php';

/** Backfill zero-cost stock adjustment movements when their posted journal entry proves the value. */
final class ReconcileLegacyStockAdjustmentCosts
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function up(): void
    {
        $this->pdo->beginTransaction();
        try {
            $candidates = $this->pdo->query(
                "SELECT it.id, it.tenant_id, it.quantity, it.journal_entry_id
                 FROM inventory_transactions it
                 INNER JOIN journal_entries je
                   ON je.id = it.journal_entry_id
                  AND je.tenant_id = it.tenant_id
                 WHERE it.reference_type = 'adjustment'
                   AND it.movement_type IN ('adjustment_in', 'adjustment_out')
                   AND it.journal_entry_id IS NOT NULL
                   AND COALESCE(it.unit_cost, 0) = 0
                   AND COALESCE(it.total_cost, 0) = 0
                   AND it.quantity > 0
                   AND je.reference_type = 'inventory_adjustment'
                   AND je.status = 'posted'
                 ORDER BY it.id"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $lines = $this->pdo->prepare(
                'SELECT COALESCE(SUM(debit_amount), 0) AS debit_total,
                        COALESCE(SUM(credit_amount), 0) AS credit_total
                 FROM journal_entry_lines
                 WHERE journal_entry_id = ? AND tenant_id = ?'
            );
            $update = $this->pdo->prepare(
                "UPDATE inventory_transactions
                 SET unit_cost = ?, total_cost = ?
                 WHERE id = ? AND tenant_id = ?
                   AND reference_type = 'adjustment'
                   AND movement_type IN ('adjustment_in', 'adjustment_out')
                   AND COALESCE(unit_cost, 0) = 0
                   AND COALESCE(total_cost, 0) = 0"
            );

            $reconciled = 0;
            $skipped = 0;
            foreach ($candidates as $candidate) {
                $tenantId = (int) $candidate['tenant_id'];
                $quantity = (float) $candidate['quantity'];
                $lines->execute([(int) $candidate['journal_entry_id'], $tenantId]);
                $totals = $lines->fetch(PDO::FETCH_ASSOC) ?: [];
                $debit = (float) ($totals['debit_total'] ?? 0);
                $credit = (float) ($totals['credit_total'] ?? 0);

                if (
                    $quantity <= 0
                    || $debit <= 0
                    || abs($debit - $credit) > 0.001
                ) {
                    $skipped++;
                    continue;
                }

                $unitCost = $debit / $quantity;
                $update->execute([
                    $unitCost,
                    $debit,
                    (int) $candidate['id'],
                    $tenantId,
                ]);
                $reconciled += $update->rowCount();
            }

            $this->pdo->commit();
            echo "Reconciled {$reconciled} legacy stock adjustment movement(s); skipped {$skipped} unbalanced or valueless entry(s).\n";
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function down(): void
    {
        echo "No rollback: corrected historical stock movement valuations are retained.\n";
    }
}
