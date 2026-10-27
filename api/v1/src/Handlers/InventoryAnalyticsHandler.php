<?php

declare(strict_types=1);

namespace App\Handlers;

use PDO;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use App\Services\MonologHandler;

/**
 * Produces tenant inventory levels and movement summaries, with optional
 * branch-scoped reporting.
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


}
