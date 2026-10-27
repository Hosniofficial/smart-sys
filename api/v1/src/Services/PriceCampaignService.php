<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

final class PriceCampaignService
{
    public static function findActivePrice(
        PDO $db,
        int $tenantId,
        int $branchId,
        int $productId,
        int $unitId
    ): ?array {
        $stmt = $db->prepare(
            "SELECT c.id AS campaign_id, c.name AS campaign_name, i.campaign_price
             FROM sales_price_campaign_items i
             JOIN sales_price_campaigns c ON c.id = i.campaign_id AND c.tenant_id = i.tenant_id
             WHERE c.tenant_id = ?
               AND i.product_id = ?
               AND i.unit_id = ?
               AND c.status = 'active'
               AND c.start_date <= CURRENT_DATE
               AND c.end_date >= CURRENT_DATE
               AND (c.branch_id IS NULL OR c.branch_id = ?)
             ORDER BY (c.branch_id IS NOT NULL) DESC, c.priority DESC, c.id DESC
             LIMIT 1"
        );
        $stmt->execute([$tenantId, $productId, $unitId, $branchId]);
        $campaign = $stmt->fetch(PDO::FETCH_ASSOC);
        return $campaign ?: null;
    }

    public static function findActivePrices(
        PDO $db,
        int $tenantId,
        int $branchId,
        array $productUnits
    ): array {
        if ($productUnits === []) {
            return [];
        }

        $conditions = [];
        $params = [$tenantId, $branchId];
        foreach ($productUnits as $productId => $unitId) {
            $conditions[] = '(i.product_id = ? AND i.unit_id = ?)';
            $params[] = (int) $productId;
            $params[] = (int) $unitId;
        }
        $stmt = $db->prepare(
            "SELECT c.id AS campaign_id, c.name AS campaign_name, i.product_id, i.unit_id, i.campaign_price
             FROM sales_price_campaign_items i
             JOIN sales_price_campaigns c ON c.id = i.campaign_id AND c.tenant_id = i.tenant_id
             WHERE c.tenant_id = ?
               AND c.status = 'active'
               AND c.start_date <= CURRENT_DATE
               AND c.end_date >= CURRENT_DATE
               AND (c.branch_id IS NULL OR c.branch_id = ?)
               AND (" . implode(' OR ', $conditions) . ")
             ORDER BY (c.branch_id IS NOT NULL) DESC, c.priority DESC, c.id DESC"
        );
        $stmt->execute($params);

        $prices = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $key = (int) $row['product_id'] . ':' . (int) $row['unit_id'];
            if (!isset($prices[$key])) {
                $prices[$key] = [
                    'campaign_id' => (int) $row['campaign_id'],
                    'campaign_name' => (string) $row['campaign_name'],
                    'campaign_price' => (float) $row['campaign_price'],
                ];
            }
        }
        return $prices;
    }
}
