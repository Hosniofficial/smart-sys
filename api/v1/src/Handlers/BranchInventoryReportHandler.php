<?php

declare(strict_types=1);

namespace App\Handlers;

use PDO;
use PDOException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Services\MonologHandler;

/**
 * Serves tenant-scoped branch inventory reports and movement exports.
 *
 * Uses PDO for inventory/account queries; routes and report permissions are
 * registered in app/routes/branches.php.
 */
class BranchInventoryReportHandler extends BaseHandler
{
    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->logger = MonologHandler::getInstance('branch');
    }

    /** Normalize movement labels for report and export output. */
    private function normalizeMovementType(string $referenceType, string $movementType): string
    {
        if (strtolower($movementType) === 'sale_cancel') {
            return 'sale_cancel';
        }
        $ref = strtolower($referenceType);
        if (in_array($ref, ['purchase', 'sale', 'return'], true)) {
            return $ref;
        }
        if (strpos($movementType, 'adjustment') !== false) {
            return 'adjustment';
        }
        return $ref ?: $movementType;
    }

    /** Build filtered movements, running balances, opening balances, and stock valuation. */
    private function getInventoryMovementsData(int $tenantId, array $queryParams): array
    {
        $start     = $queryParams['start_date']  ?? date('Y-m-d', strtotime('-7 days'));
        $end       = $queryParams['end_date']    ?? date('Y-m-d');
        $type      = isset($queryParams['type']) ? strtolower((string) $queryParams['type']) : 'all';
        $productId = $queryParams['product_id']  ?? null;
        $branchId  = $queryParams['branch_id']   ?? null;

        $where  = ['it.tenant_id = ?', 'it.movement_date >= ?', 'it.movement_date <= ?'];
        $params = [$tenantId, $start, $end . ' 23:59:59'];

        if ($productId) {
            $where[]  = 'it.product_id = ?';
            $params[] = $productId;
        }
        if ($branchId) {
            $where[]  = '(it.branch_from = ? OR it.branch_to = ?)';
            $params[] = $branchId;
            $params[] = $branchId;
        }
        if ($type && $type !== 'all') {
            if ($type === 'adjustment') {
                $where[] = "(it.reference_type = 'adjustment' OR it.movement_type LIKE 'adjustment_%')";
            } elseif ($type === 'sale_cancel') {
                $where[] = "it.movement_type = 'sale_cancel'";
            } elseif (in_array($type, ['purchase', 'sale', 'return'], true)) {
                $where[]  = $type === 'sale'
                    ? "it.reference_type = 'sale' AND it.movement_type <> 'sale_cancel'"
                    : 'it.reference_type = ?';
                if ($type !== 'sale') {
                    $params[] = $type;
                }
            }
        }

        $sql = "
            SELECT it.id,
                   it.movement_date AS date,
                   it.reference_type, it.reference_id, it.movement_type,
                   it.quantity, it.notes,
                   p.id AS product_id, p.name AS product,
                   COALESCE(it.unit_cost, p.purchase_price, 0) AS cost
            FROM inventory_transactions it
            JOIN products p ON p.id = it.product_id AND p.tenant_id = it.tenant_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY it.movement_date ASC, it.id ASC
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Opening balances include each product's net movements before the selected period.
        $openingWhere = ['it.tenant_id = ?', 'it.movement_date < ?'];
        $openingParams = [$tenantId, $start];
        if ($productId) {
            $openingWhere[] = 'it.product_id = ?';
            $openingParams[] = $productId;
        }
        if ($branchId) {
            $openingWhere[] = '(it.branch_from = ? OR it.branch_to = ?)';
            $openingParams[] = $branchId;
            $openingParams[] = $branchId;
        }

        $obSql = "SELECT it.product_id,
                                                 COALESCE(SUM(CASE
                                                     WHEN it.movement_type = 'sale_cancel' THEN it.quantity
                           WHEN it.movement_type LIKE '%in%'  THEN it.quantity
                           WHEN it.movement_type LIKE '%out%' THEN -it.quantity
                           ELSE 0 END), 0) AS opening_balance
                  FROM inventory_transactions it
                  WHERE " . implode(' AND ', $openingWhere) . "
                  GROUP BY it.product_id";
        $obStmt = $this->db->prepare($obSql);
        $obStmt->execute($openingParams);

        $openingByProduct = [];
        foreach ($obStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $openingRow) {
            $openingByProduct[(int) $openingRow['product_id']] = (float) $openingRow['opening_balance'];
        }

        $openingBalance = array_sum($openingByProduct);
        $balancesByProduct = $openingByProduct;
        $items        = [];
        $totalIn      = 0.0;
        $totalOut     = 0.0;

        foreach ($rows as $r) {
            $isIn  = (string) $r['movement_type'] === 'sale_cancel'
                || strpos((string) $r['movement_type'], 'in') !== false;
            $isOut = strpos((string) $r['movement_type'], 'out') !== false;
            $inQty  = $isIn ? (float) $r['quantity'] : 0.0;
            $outQty = $isOut ? (float) $r['quantity'] : 0.0;
            $movementProductId = (int) $r['product_id'];
            $balancesByProduct[$movementProductId] = ($balancesByProduct[$movementProductId] ?? 0.0) + $inQty - $outQty;
            $productBalance = $balancesByProduct[$movementProductId];
            $totalIn  += $inQty;
            $totalOut += $outQty;

            $ref = $r['reference_type'];
            if (!empty($r['reference_id'])) {
                $ref .= '#' . $r['reference_id'];
            }

            $items[] = [
                'id'        => (int) $r['id'],
                'date'      => $r['date'],
                'reference' => $ref,
                'product'   => $r['product'],
                'type'      => $this->normalizeMovementType((string) $r['reference_type'], (string) $r['movement_type']),
                'in'        => $inQty,
                'out'       => $outQty,
                'balance'   => $productBalance,
                'notes'     => $r['notes'],
                'cost'      => (float) $r['cost'],
            ];
        }

        $valuationWhere = ['bp.tenant_id = ?'];
        $valuationParams = [$tenantId];
        if ($productId) {
            $valuationWhere[] = 'bp.product_id = ?';
            $valuationParams[] = $productId;
        }
        if ($branchId) {
            $valuationWhere[] = 'bp.branch_id = ?';
            $valuationParams[] = $branchId;
        }

        $valuationStmt = $this->db->prepare(
            "SELECT COALESCE(SUM(COALESCE(bp.quantity_cost, bp.quantity * COALESCE(p.purchase_price, 0))), 0)
             FROM branch_products bp
             JOIN products p ON p.id = bp.product_id AND p.tenant_id = bp.tenant_id
             WHERE " . implode(' AND ', $valuationWhere)
        );
        $valuationStmt->execute($valuationParams);
        $valuation = (float) ($valuationStmt->fetchColumn() ?: 0);
        $finalBalance = array_sum($balancesByProduct);

        return [
            'items'           => $items,
            'opening_balance' => $openingBalance,
            'total_in'        => $totalIn,
            'total_out'       => $totalOut,
            'final_balance'   => $finalBalance,
            'valuation'       => $valuation,
        ];
    }

    /** Report tenant branches missing linked ledger accounts, optionally for one branch. */
    public function branchesAccountCoverage(Request $request, Response $response, array $args): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }
            $params = $request->getQueryParams();
            $branchId = null;
            if (isset($params['branch_id']) && $params['branch_id'] !== '') {
                $branchId = filter_var($params['branch_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($branchId === false) {
                    return $this->errorResponse($response, 'معرف الفرع غير صالح.', 400);
                }
                $branchCheck = $this->db->prepare('SELECT id FROM branches WHERE id = ? AND tenant_id = ? LIMIT 1');
                $branchCheck->execute([$branchId, $tenantId]);
                if (!$branchCheck->fetchColumn()) {
                    return $this->errorResponse($response, 'الفرع غير موجود.', 404);
                }
                $branchId = (int) $branchId;
            }

            // Filter in PHP to preserve the existing response semantics.
            $whereBranch = $branchId !== null ? ' AND id = ?' : '';
            $stmtAll = $this->db->prepare(
                "SELECT id, name, location, phone, email, description,
                        COALESCE(account_id, 0) AS account_id,
                        cost_center_id, active, created_at
                 FROM branches
                 WHERE tenant_id = ?{$whereBranch}
                 ORDER BY name ASC"
            );
            $branchId === null
                ? $stmtAll->execute([$tenantId])
                : $stmtAll->execute([$tenantId, $branchId]);
            $all           = $stmtAll->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $totalBranches = count($all);

            $missingBranches = array_values(array_filter($all, fn ($b) => empty($b['account_id']) || (int)$b['account_id'] === 0));
            $missingCount    = count($missingBranches);

            return $this->successResponse($response, [
                'missing_branches' => $missingBranches,
                'missing_count'    => $missingCount,
                'branches_without_account' => $missingBranches,
                'count'          => $missingCount,
                'total_branches' => $totalBranches,
                'coverage_pct'   => $totalBranches > 0
                    ? round((($totalBranches - $missingCount) / $totalBranches) * 100, 1)
                    : 100,
            ], 200);
        } catch (\Throwable $e) {
            $this->logger->error('خطأ في تقرير تغطية الحسابات: ' . $e->getMessage());
            return $this->errorResponse($response, 'فشل في جلب تقرير تغطية الحسابات', 500);
        }
    }

    /** Return filtered stock movements with opening and running balances. */
    public function inventoryMovementsReport(Request $request, Response $response, array $args): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }
            $data = $this->getInventoryMovementsData($tenantId, $request->getQueryParams());
            return $this->successResponse($response, $data, 200);
        } catch (\Throwable $e) {
            $this->logger->error('خطأ في تقرير حركة المخزون: ' . $e->getMessage());
            return $this->errorResponse($response, 'فشل في جلب تقرير حركة المخزون', 500);
        }
    }

    /** Aggregate active product stock and value, optionally filtered by branch/category/search. */
    public function inventoryValueReport(Request $request, Response $response, array $args): Response
    {
        try {
            $tenantId   = $this->extractTenantId($request);
            $qp         = $request->getQueryParams();
            $branchId   = null;
            if (isset($qp['branch_id']) && $qp['branch_id'] !== '') {
                $branchId = filter_var($qp['branch_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($branchId === false) {
                    return $this->errorResponse($response, 'معرف الفرع غير صالح.', 400);
                }
                $branchStmt = $this->db->prepare('SELECT id FROM branches WHERE id = ? AND tenant_id = ? LIMIT 1');
                $branchStmt->execute([$branchId, $tenantId]);
                if (!$branchStmt->fetchColumn()) {
                    return $this->errorResponse($response, 'الفرع غير موجود.', 404);
                }
                $branchId = (int) $branchId;
            }
            $useCost    = strtolower((string) ($qp['valuation'] ?? 'sale')) === 'cost';
            $categoryId = isset($qp['category_id']) ? (int) $qp['category_id'] : null;
            $search     = isset($qp['search']) ? trim((string) $qp['search']) : '';
            $label      = $useCost ? 'purchase_price' : 'sale_price';

            $where  = ['p.tenant_id = ?', 'p.active = 1'];
            $branchJoin = '';
            $params = [];
            if ($branchId !== null) {
                $branchJoin = ' AND wp.branch_id = ?';
                $params[] = $branchId;
                $where[] = 'wp.product_id IS NOT NULL';
            }
            $params[] = $tenantId;

            if ($categoryId) {
                $where[]  = 'p.category_id = ?';
                $params[] = $categoryId;
            }
            if ($search !== '') {
                $like     = '%' . $search . '%';
                $where[]  = '(p.name LIKE ? OR p.barcode LIKE ? OR p.product_code LIKE ?)';
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
            }

            $stmt = $this->db->prepare("
                SELECT p.id, p.name, p.barcode, p.product_code,
                       p.category_id, c.name AS category_name,
                       COALESCE(SUM(wp.quantity), 0)  AS quantity,
                       COALESCE(p.purchase_price, 0)  AS cost_price,
                       COALESCE(p.sale_price, 0)       AS sale_price,
                       COALESCE(p.min_quantity, 0)     AS min_quantity,
                       COALESCE(SUM(COALESCE(wp.quantity_cost, wp.quantity * COALESCE(p.purchase_price, 0))), 0) AS total_cost_value
                FROM products p
                LEFT JOIN categories c       ON c.id = p.category_id AND c.tenant_id = p.tenant_id
                LEFT JOIN branch_products wp ON wp.product_id = p.id AND wp.tenant_id = p.tenant_id{$branchJoin}
                WHERE " . implode(' AND ', $where) . "
                GROUP BY p.id, p.name, p.barcode, p.product_code, p.category_id,
                         c.name, p.purchase_price, p.sale_price, p.min_quantity
                ORDER BY p.name ASC
            ");
            $stmt->execute($params);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $totals  = ['total_qty' => 0.0, 'total_value' => 0.0, 'total_products' => 0, 'low_stock_count' => 0, 'out_of_stock_count' => 0, 'valuation_basis' => $label];
            $out     = [];
            $catMap  = [];

            foreach ($items as $it) {
                $qty   = (float) ($it['quantity']   ?? 0);
                $cost  = (float) ($it['cost_price'] ?? 0);
                $sale  = (float) ($it['sale_price'] ?? 0);
                // Prefer persisted branch cost, with product purchase price as a legacy fallback.
                $costValue = (float) ($it['total_cost_value'] ?? ($qty * $cost));
                $value = $useCost ? $costValue : ($qty * $sale);
                $minQ  = (float) ($it['min_quantity'] ?? 0);

                $out[] = [
                    'id'                => (int) $it['id'],
                    'name'              => (string) $it['name'],
                    'barcode'           => $it['barcode'],
                    'sku'               => $it['product_code'],
                    'categoryId'        => isset($it['category_id']) ? (int) $it['category_id'] : null,
                    'quantity'          => $qty,
                    'costPrice'         => $cost,
                    'salePrice'         => $sale,
                    'totalValue'        => (float) $value,
                    'lowStockThreshold' => $minQ,
                ];

                $totals['total_qty']     += $qty;
                $totals['total_value']   += $value;
                $totals['total_products']++;
                if ($qty == 0.0) {
                    $totals['out_of_stock_count']++;
                }
                if ($qty > 0 && $minQ > 0 && $qty <= $minQ) {
                    $totals['low_stock_count']++;
                }

                if (!empty($it['category_id'])) {
                    $cid = (int) $it['category_id'];
                    $catMap[$cid] ??= ['id' => $cid, 'name' => (string) ($it['category_name'] ?? 'غير مصنف')];
                }
            }

            return $this->successResponse($response, [
                'items'      => $out,
                'categories' => array_values($catMap),
                'totals'     => $totals,
            ], 200);
        } catch (\Throwable $e) {
            $this->logger->error('خطأ في تقرير قيمة المخزون: ' . $e->getMessage());
            return $this->errorResponse($response, 'فشل في جلب تقرير قيمة المخزون', 400);
        }
    }

    /** Aggregate stock quantity and valuation by branch for the tenant. */
    public function inventoryValueBybranch(Request $request, Response $response, array $args): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            $qp       = $request->getQueryParams();
            $useCost  = strtolower((string) ($qp['valuation'] ?? 'cost')) === 'cost';
            $label    = $useCost ? 'purchase_price' : 'sale_price';

            $stmt = $this->db->prepare("
                SELECT b.id AS branch_id, b.name AS branch_name,
                       COUNT(DISTINCT wp.product_id) AS items_count,
                       SUM(CASE WHEN wp.quantity = 0 THEN 1 ELSE 0 END) AS out_of_stock_count,
                       SUM(CASE WHEN p.min_quantity IS NOT NULL AND wp.quantity > 0 AND wp.quantity <= p.min_quantity THEN 1 ELSE 0 END) AS low_stock_count,
                       COALESCE(SUM(wp.quantity), 0) AS total_qty,
                       COALESCE(SUM(
                           CASE
                               WHEN ? = 'sale' THEN wp.quantity * COALESCE(p.sale_price, 0)
                               ELSE COALESCE(wp.quantity_cost, wp.quantity * COALESCE(p.purchase_price, 0))
                           END
                       ), 0) AS total_value
                FROM branches b
                LEFT JOIN branch_products wp ON wp.branch_id  = b.id AND wp.tenant_id = b.tenant_id
                LEFT JOIN products p          ON p.id         = wp.product_id AND p.tenant_id = b.tenant_id
                WHERE b.tenant_id = ?
                GROUP BY b.id, b.name
                ORDER BY b.name ASC
            ");
            $stmt->execute([$label === 'sale_price' ? 'sale' : 'cost', $tenantId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $uniqueProductsStmt = $this->db->prepare(
                "SELECT COUNT(DISTINCT wp.product_id)
                 FROM branch_products wp
                 JOIN products p ON p.id = wp.product_id AND p.tenant_id = wp.tenant_id
                 WHERE wp.tenant_id = ?"
            );
            $uniqueProductsStmt->execute([$tenantId]);
            $uniqueProducts = (int) $uniqueProductsStmt->fetchColumn();

            $totals = ['total_qty' => 0.0, 'total_value' => 0.0, 'total_products' => $uniqueProducts, 'low_stock_count' => 0, 'out_of_stock_count' => 0, 'valuation_basis' => $label];
            foreach ($rows as $r) {
                $totals['total_qty']          += (float) $r['total_qty'];
                $totals['total_value']        += (float) $r['total_value'];
                $totals['low_stock_count']    += (int)   $r['low_stock_count'];
                $totals['out_of_stock_count'] += (int)   $r['out_of_stock_count'];
            }

            return $this->successResponse($response, ['rows' => $rows, 'totals' => $totals], 200);
        } catch (\Throwable $e) {
            $this->logger->error('خطأ في تقرير قيمة المخزون حسب الفرع: ' . $e->getMessage());
            return $this->errorResponse($response, 'فشل في جلب تقرير قيمة المخزون', 400);
        }
    }

    /** Return a branch's stock rows and unfiltered aggregate counts, with optional row search. */
    public function getBranchStock(Request $request, Response $response, array $args): Response
    {
        $tenantId = $this->extractTenantId($request);
        $branchId = (int) ($args['id'] ?? 0);

        $branchCheck = $this->db->prepare(
            "SELECT id FROM branches WHERE id = ? AND tenant_id = ?"
        );
        $branchCheck->execute([$branchId, $tenantId]);
        if (!$branchCheck->fetch()) {
            return $this->errorResponse($response, 'الفرع غير موجود أو لا ينتمي لهذا المستأجر', 404);
        }

        try {
            $qp     = $request->getQueryParams();
            $search = isset($qp['search']) ? trim((string) $qp['search']) : '';

            // Aggregate totals remain independent of the row search filter.
            $statsStmt = $this->db->prepare("
                SELECT
                    COUNT(*)                                                          AS total_products,
                    COALESCE(SUM(wp.quantity), 0)                                     AS total_quantity,
                    COALESCE(SUM(COALESCE(wp.quantity_cost, wp.quantity * COALESCE(p.purchase_price, 0))), 0) AS total_value,
                    SUM(CASE WHEN wp.quantity <= 0 THEN 1 ELSE 0 END)                 AS out_of_stock_count,
                    SUM(CASE WHEN p.min_quantity IS NOT NULL AND wp.quantity > 0
                                  AND wp.quantity <= p.min_quantity THEN 1 ELSE 0 END) AS low_stock_count
                FROM branch_products wp
                JOIN products p ON p.id = wp.product_id AND p.tenant_id = wp.tenant_id
                WHERE wp.tenant_id = ? AND wp.branch_id = ?
            ");
            $statsStmt->execute([$tenantId, $branchId]);
            $statsRow = $statsStmt->fetch(PDO::FETCH_ASSOC);

            $stats = [
                'total_products'     => (int)   ($statsRow['total_products']     ?? 0),
                'total_quantity'     => (float) ($statsRow['total_quantity']     ?? 0),
                'total_value'        => (float) ($statsRow['total_value']        ?? 0),
                'low_stock'          => (int)   ($statsRow['low_stock_count']    ?? 0),
                'low_stock_count'    => (int)   ($statsRow['low_stock_count']    ?? 0),
                'out_of_stock_count' => (int)   ($statsRow['out_of_stock_count'] ?? 0),
            ];

            $where  = ['wp.tenant_id = ?', 'wp.branch_id = ?'];
            $params = [$tenantId, $branchId];

            if ($search !== '') {
                $where[]  = '(p.name LIKE ? OR p.barcode LIKE ? OR p.product_code LIKE ?)';
                $like     = '%' . $search . '%';
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
            }

            $stmt = $this->db->prepare("
                SELECT wp.product_id,
                       p.name          AS product_name,
                       p.barcode,
                       p.product_code  AS sku,
                       wp.quantity,
                       wp.quantity_cost,
                       COALESCE(p.purchase_price, 0) AS cost_price,
                       COALESCE(p.sale_price, 0)      AS sale_price,
                       p.min_quantity,
                       p.maximum_quantity AS max_quantity,
                       wp.last_update
                FROM branch_products wp
                JOIN products p ON p.id = wp.product_id AND p.tenant_id = wp.tenant_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY p.name ASC
            ");
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // Treat zero minimum/maximum thresholds as unset.
            foreach ($rows as &$r) {
                $r['min_quantity'] = (!empty($r['min_quantity']) && (float)$r['min_quantity'] > 0)
                    ? (float) $r['min_quantity'] : null;
                $r['max_quantity'] = (!empty($r['max_quantity']) && (float)$r['max_quantity'] > 0)
                    ? (float) $r['max_quantity'] : null;
                $r['total_value']  = isset($r['quantity_cost']) && $r['quantity_cost'] !== null
                    ? (float) $r['quantity_cost']
                    : (float) $r['quantity'] * (float) $r['cost_price'];
            }
            unset($r);

            $this->logger->info('Branch stock retrieved', [
                'tenant_id'     => $tenantId,
                'branch_id'     => $branchId,
                'product_count' => count($rows),
                'total_value'   => $stats['total_value'],
            ]);

            return $this->successResponse($response, [
                'rows'   => $rows,
                'items'  => $rows,
                'stats'  => $stats,
                'totals' => $stats,
            ], 200);
        } catch (\Throwable $e) {
            $this->logger->error('خطأ في جلب مخزون الفرع: ' . $e->getMessage());
            return $this->errorResponse($response, 'فشل في جلب بيانات المخزون', 500);
        }
    }
}
