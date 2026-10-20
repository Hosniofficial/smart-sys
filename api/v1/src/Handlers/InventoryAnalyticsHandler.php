<?php

declare(strict_types=1);

namespace App\Handlers;

use PDO;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use App\Services\MonologHandler;

/**
 * Produces tenant inventory levels and movement summaries, with optional
 * branch-scoped reporting. Also provides an internal stock forecast helper.
 *
 * Uses PDO for inventory, product, and branch data; analytics routes enforce
 * report permissions.
 */
class InventoryAnalyticsHandler extends BaseHandler
{
    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->logger = MonologHandler::getInstance('analytics');
    }

    /** Return branch-level stock conditions and inventory movement totals. */
    public function analyzeInventory(Request $request, Response $response): Response
    {
        $params    = $request->getQueryParams();
        $tenantId  = $this->extractTenantId($request);

        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 400);
        }

        $branchId = null;
        if (array_key_exists('branch_id', $params) && $params['branch_id'] !== '') {
            $branchId = filter_var($params['branch_id'], FILTER_VALIDATE_INT);
            if ($branchId === false || $branchId < 1) {
                return $this->errorResponse($response, 'معرف الفرع غير صالح.', 400);
            }

            $branchStmt = $this->db->prepare(
                'SELECT 1 FROM branches WHERE id = ? AND tenant_id = ? LIMIT 1'
            );
            $branchStmt->execute([$branchId, $tenantId]);
            if (!$branchStmt->fetchColumn()) {
                return $this->errorResponse($response, 'الفرع المحدد غير موجود.', 404);
            }
        }

        $where       = [];
        $queryParams = [];

        if (!empty($params['category_id'])) {
            $where[]       = 'p.category_id = ?';
            $queryParams[] = $params['category_id'];
        }

        $extraWhere = !empty($where) ? ' AND ' . implode(' AND ', $where) : '';
        $branchProductJoin = 'INNER JOIN branch_products wp ON wp.product_id = p.id AND wp.tenant_id = ?'
            . ($branchId !== null ? ' AND wp.branch_id = ?' : '');
        $branchMovementJoin = $branchId !== null
            ? ' AND (it.branch_from = ? OR it.branch_to = ?)'
            : '';

        // Keep all-branch alerts branch-specific so stock in one branch cannot hide
        // an out-of-stock or low-stock condition in another branch.
        $stmt = $this->db->prepare("
            SELECT p.id, p.name, p.product_code,
                   wp.branch_id, b.name AS branch_name,
                   COALESCE(wp.quantity, 0) as quantity,
                   COALESCE(wp.minimum_quantity, p.min_quantity, 0) AS min_quantity,
                   p.maximum_quantity,
                   CASE
                       WHEN COALESCE(wp.quantity, 0) <= 0 THEN 'out_of_stock'
                       WHEN COALESCE(wp.minimum_quantity, p.min_quantity, 0) > 0
                            AND COALESCE(wp.quantity, 0) <= COALESCE(wp.minimum_quantity, p.min_quantity, 0) THEN 'low'
                       WHEN p.maximum_quantity > 0
                            AND COALESCE(wp.quantity, 0) >= p.maximum_quantity THEN 'high'
                       ELSE 'normal'
                   END as stock_status
            FROM products p
            {$branchProductJoin}
            INNER JOIN branches b ON b.id = wp.branch_id AND b.tenant_id = wp.tenant_id
            WHERE p.tenant_id = ? AND p.active = 1 {$extraWhere}
            GROUP BY p.id, p.name, p.product_code, wp.branch_id, b.name,
                     wp.quantity, wp.minimum_quantity, p.min_quantity, p.maximum_quantity
            ORDER BY
                CASE
                    WHEN COALESCE(wp.quantity, 0) <= 0 THEN 0
                    WHEN COALESCE(wp.minimum_quantity, p.min_quantity, 0) > 0
                         AND COALESCE(wp.quantity, 0) <= COALESCE(wp.minimum_quantity, p.min_quantity, 0) THEN 1
                    WHEN p.maximum_quantity > 0
                         AND COALESCE(wp.quantity, 0) >= p.maximum_quantity THEN 3
                    ELSE 2
                END, p.name, b.name
        ");
        $stockParams = [$tenantId];
        if ($branchId !== null) {
            $stockParams[] = $branchId;
        }
        $stmt->execute(array_merge($stockParams, [$tenantId], $queryParams));
        $stockLevels = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Scope movement totals to transactions touching the selected branch.
        $stmt = $this->db->prepare("
            SELECT p.id, p.name, p.product_code,
                   COUNT(DISTINCT CASE WHEN it.movement_type = 'in'  THEN it.id END) as total_restocks,
                   COUNT(DISTINCT CASE WHEN it.movement_type = 'out' THEN it.id END) as total_withdrawals,
                   SUM(CASE WHEN it.movement_type = 'in'  THEN it.quantity ELSE 0 END) as total_in,
                   SUM(CASE WHEN it.movement_type = 'out' THEN it.quantity ELSE 0 END) as total_out,
                   AVG(CASE WHEN it.movement_type = 'out' THEN it.quantity END) as avg_withdrawal
            FROM products p
            LEFT JOIN inventory_transactions it ON it.product_id = p.id AND it.tenant_id = ? {$branchMovementJoin}
            WHERE p.tenant_id = ? AND p.active = 1 {$extraWhere}
            GROUP BY p.id, p.name, p.product_code
            ORDER BY total_out DESC
        ");
        $movementParams = [$tenantId];
        if ($branchId !== null) {
            $movementParams[] = $branchId;
            $movementParams[] = $branchId;
        }
        $stmt->execute(array_merge($movementParams, [$tenantId], $queryParams));
        $stockMovement = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $this->successResponse($response, [
            'branch_products' => $stockLevels,
            'stock_movement'  => $stockMovement,
        ], 200);
    }

    /**
     * Forecast tenant-wide stock from recent sales and daily consumption variance.
     *
     * @return list<array<string, int|float|bool|string>> Daily forecast rows.
     */
    public function forecastInventory(int $productId, int $tenantId, int $days = 30): array
    {
        if (!$tenantId) {
            $this->logger->warning('forecastInventory called without tenantId', ['product_id' => $productId]);
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT AVG(daily_quantity) as avg_daily_consumption
            FROM (
                SELECT DATE(s.date) as date, SUM(si.quantity) as daily_quantity
                FROM sales s
                JOIN sales_items si ON si.sale_id = s.id AND si.tenant_id = s.tenant_id
                WHERE si.product_id = ? AND s.tenant_id = ? AND s.date >= DATE_SUB(NOW(), INTERVAL 90 DAY)
                GROUP BY DATE(s.date)
            ) daily_sales
        ");
        $stmt->execute([$productId, $tenantId]);
        $avgConsumption = (float) ($stmt->fetchColumn() ?? 0);

        $stmt = $this->db->prepare("
            SELECT STDDEV(daily_quantity) as consumption_stddev
            FROM (
                SELECT DATE(s.date) as date, SUM(si.quantity) as daily_quantity
                FROM sales s
                JOIN sales_items si ON si.sale_id = s.id AND si.tenant_id = s.tenant_id
                WHERE si.product_id = ? AND s.tenant_id = ? AND s.date >= DATE_SUB(NOW(), INTERVAL 90 DAY)
                GROUP BY DATE(s.date)
            ) daily_sales
        ");
        $stmt->execute([$productId, $tenantId]);
        $stdDev = (float) ($stmt->fetchColumn() ?? 0);

        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(bp.quantity), 0) as current_stock,
                   p.min_quantity, p.maximum_quantity, 
                   COALESCE(p.lead_time_days, 5) as lead_time_days
            FROM products p
            LEFT JOIN branch_products bp ON bp.product_id = p.id AND bp.tenant_id = ?
            WHERE p.id = ? AND p.tenant_id = ?
            GROUP BY p.id, p.min_quantity, p.maximum_quantity, p.lead_time_days
        ");
        $stmt->execute([$tenantId, $productId, $tenantId]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$product) {
            $this->logger->warning('Product not found for forecast', [
                'product_id' => $productId,
                'tenant_id' => $tenantId
            ]);
            return [];
        }

        $safetyStock  = $stdDev * sqrt((float) ($product['lead_time_days'] ?? 5));
        $currentStock = (float) $product['current_stock'];
        $minQuantity  = (float) ($product['min_quantity'] ?? 0);
        $forecast     = [];

        for ($i = 1; $i <= $days; $i++) {
            $expectedStock = $currentStock - ($avgConsumption * $i);
            $reorderPoint  = $minQuantity + $safetyStock;

            $forecast[] = [
                'day'                  => $i,
                'date'                 => date('Y-m-d', strtotime("+{$i} days")),
                'expected_stock'       => round($expectedStock, 2),
                'expected_consumption' => round($avgConsumption, 2),
                'needs_reorder'        => $expectedStock <= $reorderPoint,
                'reorder_point'        => round($reorderPoint, 2),
                'safety_stock'         => round($safetyStock, 2),
            ];
        }

        return $forecast;
    }
}
