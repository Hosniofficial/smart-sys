<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/** Calculates inventory cost for tenant-scoped return batches. */
class ReturnCostingService
{
    public function __construct(private PDO $db)
    {
    }

    /** Sum the costs that can be established from return movements or original sale items. */
    public function calculateForReturns(int $tenantId, array $returnIds): float
    {
        return $this->calculateDetailsForReturns($tenantId, $returnIds)['cost_amount'];
    }

    /**
     * Return known cost and explicitly count return lines whose original cost is unavailable.
     *
     * A zero cost is treated as unresolved because this data path cannot distinguish
     * a genuinely free item from a missing historical cost.
     *
     * @return array{cost_amount: float, unresolved_line_count: int, unresolved_quantity: float}
     */
    public function calculateDetailsForReturns(int $tenantId, array $returnIds): array
    {
        $returnIds = array_values(array_unique(array_filter(array_map('intval', $returnIds))));
        if ($returnIds === []) {
            return [
                'cost_amount' => 0.0,
                'unresolved_line_count' => 0,
                'unresolved_quantity' => 0.0,
            ];
        }

        $placeholders = implode(',', array_fill(0, count($returnIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT
                 COALESCE(SUM(CASE
                     WHEN COALESCE(it.movement_quantity, 0) > 0
                      AND COALESCE(it.total_cost, 0) > 0 THEN
                         (CASE WHEN ri.quantity < it.movement_quantity
                             THEN ri.quantity ELSE it.movement_quantity END)
                             * it.total_cost / it.movement_quantity
                         + CASE WHEN ri.quantity > it.movement_quantity
                              AND COALESCE(sale_cost.unit_cost, 0) > 0
                             THEN (ri.quantity - it.movement_quantity) * sale_cost.unit_cost
                             ELSE 0
                         END
                     WHEN COALESCE(sale_cost.unit_cost, 0) > 0 THEN ri.quantity * sale_cost.unit_cost
                     ELSE 0
                 END), 0) AS cost_amount,
                 COALESCE(SUM(CASE
                     WHEN COALESCE(it.movement_quantity, 0) > 0
                      AND COALESCE(it.total_cost, 0) > 0
                      AND ri.quantity > it.movement_quantity
                      AND COALESCE(sale_cost.unit_cost, 0) <= 0 THEN ri.line_count
                     WHEN (COALESCE(it.movement_quantity, 0) <= 0
                        OR COALESCE(it.total_cost, 0) <= 0)
                      AND COALESCE(sale_cost.unit_cost, 0) <= 0 THEN ri.line_count
                     ELSE 0
                 END), 0) AS unresolved_line_count,
                 COALESCE(SUM(CASE
                     WHEN COALESCE(it.movement_quantity, 0) > 0
                      AND COALESCE(it.total_cost, 0) > 0
                      AND ri.quantity > it.movement_quantity
                      AND COALESCE(sale_cost.unit_cost, 0) <= 0
                         THEN ri.quantity - it.movement_quantity
                     WHEN (COALESCE(it.movement_quantity, 0) <= 0
                        OR COALESCE(it.total_cost, 0) <= 0)
                      AND COALESCE(sale_cost.unit_cost, 0) <= 0 THEN ri.quantity
                     ELSE 0
                 END), 0) AS unresolved_quantity
             FROM (
                 SELECT return_id, tenant_id, product_id, unit_id,
                        SUM(quantity) AS quantity, COUNT(*) AS line_count
                 FROM return_items
                 WHERE return_id IN ($placeholders) AND tenant_id = ?
                 GROUP BY return_id, tenant_id, product_id, unit_id
             ) ri
             INNER JOIN returns r
                 ON r.id = ri.return_id
                 AND r.tenant_id = ri.tenant_id
             LEFT JOIN (
                 SELECT tenant_id, reference_id, product_id, unit_id,
                        SUM(quantity) AS movement_quantity, SUM(total_cost) AS total_cost
                 FROM inventory_transactions
                 WHERE reference_type = 'return' AND movement_type = 'in'
                 GROUP BY tenant_id, reference_id, product_id, unit_id
             ) it
                 ON it.tenant_id = ri.tenant_id
                 AND it.reference_id = ri.return_id
                 AND it.product_id = ri.product_id
                 AND it.unit_id = ri.unit_id
             LEFT JOIN (
                 SELECT sale_id, tenant_id, product_id, unit_id,
                        SUM(quantity * purchase_price) / NULLIF(SUM(quantity), 0) AS unit_cost
                 FROM sales_items
                 GROUP BY sale_id, tenant_id, product_id, unit_id
             ) sale_cost
                 ON sale_cost.sale_id = r.sale_id
                 AND sale_cost.product_id = ri.product_id
                 AND sale_cost.unit_id = ri.unit_id
                 AND sale_cost.tenant_id = ri.tenant_id"
        );
        $stmt->execute(array_merge($returnIds, [$tenantId]));
        $result = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'cost_amount' => round((float) ($result['cost_amount'] ?? 0), 2),
            'unresolved_line_count' => (int) ($result['unresolved_line_count'] ?? 0),
            'unresolved_quantity' => round((float) ($result['unresolved_quantity'] ?? 0), 4),
        ];
    }
}
