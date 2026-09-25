<?php

declare(strict_types=1);

namespace App\Handlers;

use PDO;
use PDOException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Services\MonologHandler;

/**
 * BranchInventoryReportHandler
 *
 * Read-only inventory reporting for branches, extracted from BranchHandler:
 *   - branchesAccountCoverage()    — branches missing account_id
 *   - inventoryMovementsReport()   — movement history with balance
 *   - inventoryMovementsExport()   — CSV export of movements
 *   - getLowStockItems()           — items at or below min_quantity
 *   - inventoryValueReport()       — value by product across all branches
 *   - inventoryValueBybranch()     — value per branch
 *   - getBranchStock()             — stock list for a single branch
 */
class BranchInventoryReportHandler extends BaseHandler
{
    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->logger = MonologHandler::getInstance('branch');
    }

    // =========================================================================
    // Private Helpers
    // =========================================================================

    private function normalizeMovementType(string $referenceType, string $movementType): string
    {
        $ref = strtolower($referenceType);
        if (in_array($ref, ['purchase', 'sale', 'return'], true)) {
            return $ref;
        }
        if (strpos($movementType, 'adjustment') !== false) {
            return 'adjustment';
        }
        return $ref ?: $movementType;
    }

    /**
     * Single Source of Truth لاستعلام حركات المخزون.
     * يُستخدم من inventoryMovementsReport و inventoryMovementsExport.
     */
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
            } elseif (in_array($type, ['purchase', 'sale', 'return'], true)) {
                $where[]  = 'it.reference_type = ?';
                $params[] = $type;
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

        // الرصيد الافتتاحي: صافي حركة كل صنف قبل بداية الفترة.
        // يُحسب أيضاً عند عرض جميع الأصناف، مع احترام فلتر الفرع.
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
            $isIn  = strpos((string) $r['movement_type'], 'in')  !== false;
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

    // =========================================================================
    // Public Endpoints
    // =========================================================================

    /**
     * تقرير تغطية الحسابات — المخازن التي تفتقر إلى account_id
     */
    public function branchesAccountCoverage(Request $request, Response $response, array $args): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            // جلب كل الفروع أولاً — تصفية PHP للتوافق مع منطق الأصل
            $stmtAll = $this->db->prepare(
                "SELECT id, name, location, phone, email, description,
                        COALESCE(account_id, 0) AS account_id,
                        cost_center_id, active, created_at
                 FROM branches
                 WHERE tenant_id = ?
                 ORDER BY name ASC"
            );
            $stmtAll->execute([$tenantId]);
            $all           = $stmtAll->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $totalBranches = count($all);

            // فلترة الفروع التي تفتقر لـ account_id (كما في الأصل)
            $missingBranches = array_values(array_filter($all, fn ($b) => empty($b['account_id']) || (int)$b['account_id'] === 0));
            $missingCount    = count($missingBranches);

            return $this->successResponse($response, [
                // مفاتيح متوافقة مع الأصل
                'missing_branches' => $missingBranches,
                'missing_count'    => $missingCount,
                // مفاتيح إضافية للواجهات الجديدة
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

    /**
     * تقرير حركة المخزون خلال فترة محددة
     */
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

    /**
     * تصدير حركات المخزون CSV
     */
    public function inventoryMovementsExport(Request $request, Response $response, array $args): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $data  = $this->getInventoryMovementsData($tenantId, $request->getQueryParams());
            $items = $data['items'] ?? [];

            $fh = fopen('php://temp', 'w+');
            fprintf($fh, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM
            fputcsv($fh, ['date', 'reference', 'product', 'type', 'in', 'out', 'balance', 'notes', 'cost', 'movement_value']);
            foreach ($items as $it) {
                $movementValue = ((float) ($it['in'] ?? 0) - (float) ($it['out'] ?? 0)) * (float) ($it['cost'] ?? 0);
                fputcsv($fh, [
                    $it['date'] ?? '', $it['reference'] ?? '', $it['product'] ?? '',
                    $it['type'] ?? '', $it['in'] ?? 0, $it['out'] ?? 0,
                    $it['balance'] ?? 0, $it['notes'] ?? '', $it['cost'] ?? 0, $movementValue,
                ]);
            }
            rewind($fh);
            $csv = stream_get_contents($fh);
            fclose($fh);

            $filename = 'inventory_movements_' . date('Ymd_His') . '.csv';
            $response = $response
                ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
                ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
            $response->getBody()->write($csv);
            return $response;
        } catch (\Throwable $e) {
            $this->logger->error('خطأ في تصدير حركات المخزون: ' . $e->getMessage());
            return $this->errorResponse($response, 'فشل في تصدير حركات المخزون', 500);
        }
    }

    /**
     * المنتجات التي وصلت أو تجاوزت حد الحد الأدنى للمخزون
     */
    public function getLowStockItems(Request $request, Response $response, array $args): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            $branchId = (int) ($args['id'] ?? 0);

            $stmt = $this->db->prepare("
                SELECT wp.product_id, p.name, p.barcode,
                       p.product_code AS sku, wp.quantity,
                       p.min_quantity, p.maximum_quantity AS max_quantity
                FROM branch_products wp
                JOIN products p ON p.id = wp.product_id AND p.tenant_id = wp.tenant_id
                WHERE wp.tenant_id = ? AND wp.branch_id = ?
                  AND p.min_quantity IS NOT NULL
                  AND wp.quantity <= p.min_quantity
                  AND p.active = 1
                ORDER BY (p.min_quantity - wp.quantity) ASC, p.name ASC
            ");
            $stmt->execute([$tenantId, $branchId]);
            return $this->successResponse($response, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], 200);
        } catch (\Throwable $e) {
            $this->logger->error('خطأ في جلب عناصر المخزون المنخفض: ' . $e->getMessage());
            return $this->errorResponse($response, 'فشل في جلب عناصر المخزون المنخفض', 400);
        }
    }

    /**
     * تقرير قيمة المخزون على مستوى المنتج (مجمّع عبر جميع المستودعات)
     */
    public function inventoryValueReport(Request $request, Response $response, array $args): Response
    {
        try {
            $tenantId   = $this->extractTenantId($request);
            $qp         = $request->getQueryParams();
            $useCost    = strtolower((string) ($qp['valuation'] ?? 'sale')) === 'cost';
            $categoryId = isset($qp['category_id']) ? (int) $qp['category_id'] : null;
            $search     = isset($qp['search']) ? trim((string) $qp['search']) : '';
            $label      = $useCost ? 'purchase_price' : 'sale_price';

            $where  = ['p.tenant_id = ?', 'p.active = 1'];
            $params = [$tenantId];

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
                LEFT JOIN branch_products wp ON wp.product_id = p.id AND wp.tenant_id = p.tenant_id
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
                // ⚠️ إصلاح: كانت قيمة التكلفة تُحسب دائماً كـ qty × purchase_price اللحظي
                // (سعر واحد ثابت لكل الفروع)، بينما quantity_cost يحمل التكلفة الفعلية
                // المُرحَّلة فعلياً لكل فرع (قد تختلف بين فرع وآخر). نفس نمط
                // getInventoryMovementsData في هذا الملف بالضبط — تم توحيدهما الآن.
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

    /**
     * تقرير قيمة المخزون مُجمَّع حسب المستودع
     */
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

    /**
     * مخزون فرع معين (مع بحث وإحصاءات)
     */
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

            // ── الإحصاءات الإجمالية: بدون فلتر البحث (كما في الأصل) ──────
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

            // ── الصفوف: مع فلتر البحث ─────────────────────────────────────
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

            // تطبيع min/max_quantity — صفر يُعامَل كـ null (كما في الأصل)
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

            // مفتاحا الـ response متوافقان مع الأصل (rows/stats) + الجديد (items/totals)
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
