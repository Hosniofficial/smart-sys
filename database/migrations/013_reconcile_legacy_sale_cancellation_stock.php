<?php

declare(strict_types=1);

require_once __DIR__ . '/../MigrationDatabase.php';

/** Reconcile legacy sale-cancellation movements that restored quantity but not stock value. */
final class ReconcileLegacySaleCancellationStock
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
            $stmt = $this->pdo->query(
                "SELECT it.id, it.tenant_id, it.branch_to AS branch_id, it.product_id,
                        it.reference_id AS sale_id, it.quantity, it.unit_cost, it.total_cost
                 FROM inventory_transactions it
                 INNER JOIN sales s
                   ON s.id = it.reference_id AND s.tenant_id = it.tenant_id
                 WHERE it.movement_type = 'sale_cancel'
                   AND LOWER(COALESCE(s.status, '')) IN ('canceled', 'cancelled')
                 ORDER BY it.tenant_id, it.branch_to, it.product_id, it.reference_id, it.id"
            );
            $groups = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $key = implode(':', [
                    (int) $row['tenant_id'],
                    (int) $row['branch_id'],
                    (int) $row['product_id'],
                    (int) $row['sale_id'],
                ]);
                $groups[$key][] = $row;
            }

            $originalStmt = $this->pdo->prepare(
                "SELECT COALESCE(SUM(quantity), 0) AS quantity,
                        COALESCE(SUM(COALESCE(total_cost, quantity * COALESCE(unit_cost, 0))), 0) AS total_cost
                 FROM inventory_transactions
                 WHERE tenant_id = ? AND product_id = ? AND branch_from = ?
                   AND reference_type = 'sale' AND reference_id = ? AND movement_type = 'out'"
            );
            $stockStmt = $this->pdo->prepare(
                'SELECT quantity, quantity_cost FROM branch_products
                 WHERE tenant_id = ? AND branch_id = ? AND product_id = ? LIMIT 1'
            );
            $updateStock = $this->pdo->prepare(
                'UPDATE branch_products
                 SET quantity = quantity + ?,
                     quantity_cost = COALESCE(quantity_cost, 0) + ?,
                     gl_reconciled = 0,
                     last_gl_posting_date = NULL,
                     last_update = CURRENT_TIMESTAMP
                 WHERE tenant_id = ? AND branch_id = ? AND product_id = ?'
            );
            $updateMovement = $this->pdo->prepare(
                'UPDATE inventory_transactions
                 SET quantity = ?, unit_cost = ?, total_cost = ?
                 WHERE id = ? AND movement_type = \'sale_cancel\'
                   AND unit_cost IS NULL AND total_cost IS NULL'
            );

            $reconciled = 0;
            $skipped = 0;
            foreach ($groups as $rows) {
                $first = $rows[0];
                $legacyRows = array_values(array_filter(
                    $rows,
                    static fn (array $row): bool => $row['unit_cost'] === null && $row['total_cost'] === null
                ));
                if ($legacyRows === []) {
                    continue;
                }

                $knownQuantity = 0.0;
                $knownCost = 0.0;
                foreach ($rows as $row) {
                    if ($row['unit_cost'] !== null || $row['total_cost'] !== null) {
                        $knownQuantity += (float) $row['quantity'];
                        $knownCost += $row['total_cost'] !== null
                            ? (float) $row['total_cost']
                            : (float) $row['quantity'] * (float) ($row['unit_cost'] ?? 0);
                    }
                }
                $legacyQuantity = array_sum(array_map(static fn (array $row): float => (float) $row['quantity'], $legacyRows));

                $originalStmt->execute([
                    (int) $first['tenant_id'],
                    (int) $first['product_id'],
                    (int) $first['branch_id'],
                    (int) $first['sale_id'],
                ]);
                $original = $originalStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $targetLegacyQuantity = (float) ($original['quantity'] ?? 0) - $knownQuantity;
                $targetLegacyCost = (float) ($original['total_cost'] ?? 0) - $knownCost;

                if (
                    $legacyQuantity <= 0
                    || $targetLegacyQuantity <= 0
                    || $targetLegacyCost < -0.0001
                    || abs($targetLegacyQuantity - $legacyQuantity) > max(0.0001, $targetLegacyQuantity * 0.0001)
                ) {
                    $skipped++;
                    continue;
                }

                $stockStmt->execute([
                    (int) $first['tenant_id'],
                    (int) $first['branch_id'],
                    (int) $first['product_id'],
                ]);
                if (!$stockStmt->fetch(PDO::FETCH_ASSOC)) {
                    $skipped++;
                    continue;
                }

                $unitCost = $targetLegacyCost / $targetLegacyQuantity;
                $remainingCost = $targetLegacyCost;
                foreach ($legacyRows as $index => $row) {
                    $quantity = (float) $row['quantity'] * ($targetLegacyQuantity / $legacyQuantity);
                    $totalCost = $index === count($legacyRows) - 1
                        ? $remainingCost
                        : round($quantity * $unitCost, 4);
                    $remainingCost -= $totalCost;
                    $updateMovement->execute([
                        $quantity,
                        $unitCost,
                        $totalCost,
                        (int) $row['id'],
                    ]);
                }

                $updateStock->execute([
                    $targetLegacyQuantity - $legacyQuantity,
                    $targetLegacyCost,
                    (int) $first['tenant_id'],
                    (int) $first['branch_id'],
                    (int) $first['product_id'],
                ]);
                $reconciled++;
            }

            $this->pdo->commit();
            echo "Reconciled {$reconciled} legacy sale-cancellation group(s); skipped {$skipped} ambiguous or unmatched group(s).\n";
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function down(): void
    {
        echo "No rollback: corrected historical stock movements and valuations are retained.\n";
    }
}
