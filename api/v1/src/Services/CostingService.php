<?php

namespace App\Services;

use PDO;

/**
 * Calculates tenant-scoped weighted-average inventory costs and sale COGS.
 */
class CostingService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Returns a product's weighted-average cost, falling back to its catalog cost.
     */
    public function getWeightedAverageCost(int $tenantId, int $productId, ?string $upToDate = null): ?float
    {
        $wacMap = $this->computeBatchWAC($tenantId, [$productId], $upToDate);
        $wac    = $wacMap[$productId] ?? null;

        if ($wac !== null) {
            return $wac;
        }

        $p = $this->pdo->prepare("SELECT COALESCE(purchase_price,0) FROM products WHERE id = ? AND tenant_id = ?");
        $p->execute([$productId, $tenantId]);
        $fallback = (float)$p->fetchColumn();
        return $fallback > 0 ? $fallback : null;
    }

    /**
     * Calculates weighted-average costs for multiple products using purchase and return history.
     *
     * A supplied date is inclusive; null results indicate that no net purchase quantity is available.
     *
     * @return array<int, float|null> Product ID to weighted-average cost.
     */
    public function computeBatchWAC(int $tenantId, array $productIds, ?string $upToDate = null): array
    {
        if (empty($productIds)) {
            return [];
        }

        $productIds   = array_values(array_unique(array_map('intval', $productIds)));
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $nextDay = date('Y-m-d', strtotime(($upToDate ?? '') . ' +1 day'));

        $dateFilter = '';
        $dateParam  = [];
        if ($upToDate) {
            $dateFilter   = ' AND p.invoice_date < ?';
            $dateParam    = [$nextDay . ' 00:00:00'];
        }

        // Aggregate purchase quantities and costs by product.
        $purchaseSql = "
            SELECT pi.product_id,
                   COALESCE(SUM(pi.quantity * pi.cost), 0) AS total_cost,
                   COALESCE(SUM(pi.quantity), 0)           AS total_qty
            FROM purchase_items pi
            INNER JOIN purchases p ON p.id = pi.purchase_id AND p.tenant_id = pi.tenant_id
            WHERE pi.tenant_id = ?
              AND pi.product_id IN ($placeholders)
              $dateFilter
              AND (p.status IS NULL OR p.status NOT IN ('canceled','cancelled'))
            GROUP BY pi.product_id
        ";
        $stmt = $this->pdo->prepare($purchaseSql);
        $stmt->execute(array_merge([$tenantId], $productIds, $dateParam));
        $purchases = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $purchases[(int)$row['product_id']] = [
                'cost' => (float)$row['total_cost'],
                'qty'  => (float)$row['total_qty'],
            ];
        }

        // Subtract purchase returns from the purchase totals.
        $returnDateFilter = '';
        $returnDateParam  = [];
        if ($upToDate) {
            $returnDateFilter = ' AND r.return_date < ?';
            $returnDateParam  = [$nextDay . ' 00:00:00'];
        }
        $returnSql = "
            SELECT ri.product_id,
                   COALESCE(SUM(ri.quantity * ri.unit_price), 0) AS total_cost,
                   COALESCE(SUM(ri.quantity), 0)                 AS total_qty
            FROM return_items ri
            INNER JOIN returns r ON r.id = ri.return_id AND r.tenant_id = ri.tenant_id
            WHERE ri.tenant_id = ?
              AND ri.product_id IN ($placeholders)
              AND r.return_type = 'purchase'
              $returnDateFilter
              AND (r.status IS NULL OR r.status NOT IN ('canceled','cancelled'))
            GROUP BY ri.product_id
        ";
        $stmt2 = $this->pdo->prepare($returnSql);
        $stmt2->execute(array_merge([$tenantId], $productIds, $returnDateParam));
        $returns = [];
        foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $returns[(int)$row['product_id']] = [
                'cost' => (float)$row['total_cost'],
                'qty'  => (float)$row['total_qty'],
            ];
        }

        $result = [];
        foreach ($productIds as $pid) {
            $pCost = $purchases[$pid]['cost'] ?? 0.0;
            $pQty  = $purchases[$pid]['qty']  ?? 0.0;
            $rCost = $returns[$pid]['cost']   ?? 0.0;
            $rQty  = $returns[$pid]['qty']    ?? 0.0;

            $netCost = max(0.0, $pCost - $rCost);
            $netQty  = max(0.0, $pQty  - $rQty);

            $result[$pid] = ($netQty > 0.0000001) ? ($netCost / $netQty) : null;
        }

        return $result;
    }

    /**
     * Calculates sale COGS from as-of-date WAC, with stored item and catalog cost fallbacks.
     */
    public function computeCOGSForSale(int $tenantId, int $saleId, ?string $saleDate = null): float
    {
        $stmt = $this->pdo->prepare("SELECT sale_date FROM sales WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$saleId, $tenantId]);
        $date = $saleDate ?: $stmt->fetchColumn();

                $itemsStmt = $this->pdo->prepare(
                        "SELECT si.product_id, si.quantity, si.conversion_factor, si.purchase_price
                         FROM sales_items si
                         INNER JOIN products p ON p.id = si.product_id AND p.tenant_id = si.tenant_id
                         WHERE si.sale_id = ? AND si.tenant_id = ?
                             AND COALESCE(p.product_type, 'stock') = 'stock'"
                );
        $itemsStmt->execute([$saleId, $tenantId]);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (empty($items)) {
            return 0.0;
        }

        // Batch WAC keeps the query count independent of the number of sale items.
        $productIds = array_values(array_unique(array_map(fn ($it) => (int)$it['product_id'], $items)));
        $wacMap     = $this->computeBatchWAC($tenantId, $productIds, $date);

        $nullPids = array_values(array_filter($productIds, fn ($pid) => $wacMap[$pid] === null));
        if (!empty($nullPids)) {
            $ph  = implode(',', array_fill(0, count($nullPids), '?'));
            $fbS = $this->pdo->prepare("SELECT id, COALESCE(purchase_price,0) FROM products WHERE id IN ($ph) AND tenant_id = ?");
            $fbS->execute(array_merge($nullPids, [$tenantId]));
            foreach ($fbS->fetchAll(PDO::FETCH_KEY_PAIR) as $pid => $price) {
                $wacMap[(int)$pid] = (float)$price > 0 ? (float)$price : null;
            }
        }

        $total = 0.0;
        foreach ($items as $it) {
            $pid     = (int)$it['product_id'];
            $qty     = (float)$it['quantity'];
            $conv    = (float)($it['conversion_factor'] ?? 1.0);
            $baseQty = $qty * ($conv > 0 ? $conv : 1.0);
            if ($baseQty <= 0) {
                continue;
            }
            $wac = $wacMap[$pid] ?? null;
            if ($wac === null) {
                $saleItemCost = (float) ($it['purchase_price'] ?? 0);
                if ($saleItemCost > 0) {
                    $total += $qty * $saleItemCost;
                }
                continue;
            }
            $total += $baseQty * $wac;
        }

        return $total;
    }
}
