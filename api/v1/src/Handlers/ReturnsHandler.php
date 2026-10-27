<?php

declare(strict_types=1);

namespace App\Handlers;

use PDO;
use Exception;
use PDOException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Services\MonologHandler;
use App\Services\CostCenter\CostCenterService;
use App\Services\LabelService;
use App\Security\Permissions;
use App\Utils\PaginationHelper;
use App\Utils\SuperAdminHelper;

/**
 * Handles sales and purchase return reads, creation, status updates, and invoice lookup.
 *
 * ReturnService performs return persistence, inventory, and accounting work;
 * this handler validates HTTP input, resolves branch/session/cost-center context,
 * and builds localized responses. Return endpoints are registered in returns.php.
 */
class ReturnsHandler extends BaseHandler
{
    protected $logger;
    private $costCenterService;

    /** Cache service instances by tenant and user for this handler request. */
    private array $returnServiceCache = [];

    public function __construct($pdo, $logger = null)
    {
        parent::__construct($pdo);
        $this->logger = $logger ?: MonologHandler::getInstance('returns');
        $this->costCenterService = new CostCenterService($pdo, 'returns');
    }

    /** Resolve the tenant/user-scoped return service, reusing the local cache. */
    private function returnService(int $tenantId, ?int $userId): \App\Services\ReturnService
    {
        $key = $tenantId . ':' . ($userId ?? 'null');
        return $this->returnServiceCache[$key]
            ??= $this->services->returns($tenantId, $userId);
    }

    private function refLabel(?string $type, string $locale = 'ar'): string
    {
        return LabelService::refLabel($type, $locale);
    }

    private function statusLabel(?string $code, string $locale = 'ar'): string
    {
        return LabelService::statusLabel($code, $locale);
    }

    /** Calculate original, returned, and remaining item quantities for an invoice. */
    public function getReturnedQuantities(Request $request, Response $response): Response
    {
        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
        }

        $params = $request->getQueryParams();
        $type = $params['type'] ?? null;
        $invoiceId = isset($params['invoice_id']) ? (int) $params['invoice_id'] : 0;

        if (!$type || !in_array($type, ['sale', 'purchase'], true)) {
            return $this->errorResponse($response, 'قيمة النوع (type) يجب أن تكون sale أو purchase', 400);
        }

        if ($invoiceId <= 0) {
            return $this->errorResponse($response, 'حقل invoice_id مطلوب ويجب أن يكون رقمًا صحيحًا موجبًا', 400);
        }

        if ($type === 'sale') {
            $stmt = $this->db->prepare("SELECT id, status FROM sales WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$invoiceId, $tenantId]);
            $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$invoice) {
                return $this->errorResponse($response, 'لم يتم العثور على فاتورة المبيعات المطلوبة', 403);
            }
            try {
                \App\Security\SaleWorkflowPolicy::assertReturnAllowed((string) $invoice['status']);
            } catch (\DomainException $e) {
                return $this->errorResponse($response, $e->getMessage(), 400);
            }

            $stmtOrig = $this->db->prepare("
                SELECT product_id, unit_id, COALESCE(SUM(quantity), 0) AS original_qty
                FROM sales_items
                WHERE sale_id = ? AND tenant_id = ?
                GROUP BY product_id, unit_id
            ");
            $stmtOrig->execute([$invoiceId, $tenantId]);
            $rows = $stmtOrig->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stmt = $this->db->prepare("SELECT id FROM purchases WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$invoiceId, $tenantId]);

            if (!$stmt->fetchColumn()) {
                return $this->errorResponse($response, 'لم يتم العثور على فاتورة المشتريات المطلوبة', 403);
            }

            $stmtOrig = $this->db->prepare("
                SELECT product_id, unit_id, COALESCE(SUM(quantity), 0) AS original_qty
                FROM purchase_items
                WHERE purchase_id = ? AND tenant_id = ?
                GROUP BY product_id, unit_id
            ");
            $stmtOrig->execute([$invoiceId, $tenantId]);
            $rows = $stmtOrig->fetchAll(PDO::FETCH_ASSOC);
        }

        $stmtProd = $this->db->prepare("
            SELECT 
                p.name, 
                p.barcode,
                COALESCE(u.name, 'قطعة') AS unit_name
            FROM products p
            LEFT JOIN product_units pu ON p.id = pu.product_id AND pu.is_main_unit = 1 AND pu.tenant_id = ?
            LEFT JOIN units u ON pu.unit_id = u.id
            WHERE p.id = ? AND p.tenant_id = ?
        ");

        if ($type === 'sale') {
            $stmtPrev = $this->db->prepare("
                SELECT COALESCE(SUM(ri.quantity), 0)
                FROM return_items ri
                JOIN returns r ON r.id = ri.return_id
                WHERE r.tenant_id = ?
                  AND r.return_type = 'sale'
                  AND r.sale_id = ?
                  AND ri.product_id = ?
                  AND COALESCE(ri.unit_id, 0) = COALESCE(?, 0)
            ");
        } else {
            $stmtPrev = $this->db->prepare("
                SELECT COALESCE(SUM(ri.quantity), 0)
                FROM return_items ri
                JOIN returns r ON r.id = ri.return_id
                WHERE r.tenant_id = ?
                  AND r.return_type = 'purchase'
                  AND r.purchase_id = ?
                  AND ri.product_id = ?
                  AND COALESCE(ri.unit_id, 0) = COALESCE(?, 0)
            ");
        }

        $result = [];

        foreach ($rows as $r) {
            $productId = (int) $r['product_id'];
            $originalQty = (float) $r['original_qty'];

            $stmtProd->execute([$tenantId, $productId, $tenantId]);
            $prod = $stmtProd->fetch(PDO::FETCH_ASSOC) ?: [
                'name' => null,
                'barcode' => null,
                'unit_name' => 'قطعة'
            ];

            $unitId = (int) ($r['unit_id'] ?? 0);
            $stmtPrev->execute([$tenantId, $invoiceId, $productId, $unitId]);
            $returnedQty = (float) $stmtPrev->fetchColumn();

            $remainingQty = max(0.0, $originalQty - $returnedQty);

            $result[] = [
                'product_id' => $productId,
                'unit_id' => $unitId,
                'product_name' => $prod['name'],
                'barcode' => $prod['barcode'],
                'unit' => $prod['unit_name'],
                'original_qty' => $originalQty,
                'returned_qty' => $returnedQty,
                'remaining_qty' => $remainingQty
            ];
        }

        return $this->successResponse($response, $result, 200);
    }

    /** List tenant returns with branch scope, filters, pagination, and localized labels. */
    public function list(Request $request, Response $response): Response
    {
        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
        }

        $queryParams = $request->getQueryParams();
        $branchId = $queryParams['branch_id'] ?? null;
        $user = $request->getAttribute('user');

        if (!$this->canViewAllBranches(is_array($user) ? $user : null)) {
            $userBranchId = is_array($user) ? (int) ($user['branch_id'] ?? 0) : 0;
            if ($userBranchId <= 0) {
                return $this->errorResponse(
                    $response,
                    'حسابك غير مرتبط بفرع. يرجى التواصل مع الإدارة لتعيين فرع لحسابك.',
                    403
                );
            }
            $branchId = $userBranchId;
        } elseif ($branchId !== null && $branchId !== '') {
            $branchId = filter_var($branchId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($branchId === false) {
                return $this->errorResponse($response, 'معرف الفرع غير صالح.', 400);
            }
            $branchStmt = $this->db->prepare(
                'SELECT 1 FROM branches WHERE id = ? AND tenant_id = ? LIMIT 1'
            );
            $branchStmt->execute([(int) $branchId, (int) $tenantId]);
            if (!$branchStmt->fetchColumn()) {
                return $this->errorResponse($response, 'الفرع المحدد غير موجود.', 404);
            }
        }

        [$page, $limit, $offset] = PaginationHelper::fromArray($queryParams, 10);
        $search = trim((string) ($queryParams['search'] ?? ''));
        $type = $queryParams['type'] ?? null;
        $startDate = $queryParams['start_date'] ?? null;
        $endDate = $queryParams['end_date'] ?? null;

        $query = "
            SELECT
                r.*,
                r.return_number,
                r.invoice_number,
                r.return_date,
                r.grand_total AS total_amount,
                r.status,
                r.notes,
                r.payment_method_id,
                r.is_cash,
                r.branch_id,
                r.created_by,
                r.sale_id,
                r.purchase_id,
                r.customer_id,
                r.supplier_id,
                CASE
                    WHEN r.return_type = 'sale' THEN c.name
                    WHEN r.return_type = 'purchase' THEN s.name
                END AS party_name,
                u.name AS created_by_name,
                (
                    SELECT COUNT(*)
                    FROM return_items ri
                    WHERE ri.return_id = r.id AND ri.tenant_id = r.tenant_id
                ) AS items_count
            FROM returns r
            LEFT JOIN customers c
                ON r.return_type = 'sale' AND r.customer_id = c.id AND c.tenant_id = ?
            LEFT JOIN suppliers s
                ON r.return_type = 'purchase' AND r.supplier_id = s.id AND s.tenant_id = ?
            LEFT JOIN users u
                ON r.created_by = u.id AND (u.tenant_id = r.tenant_id OR u.tenant_id IS NULL)
            WHERE r.tenant_id = ?
        ";

        $params = [$tenantId, $tenantId, $tenantId];
        $countParams = [$tenantId, $tenantId, $tenantId];

        $countQuery = "
            SELECT COUNT(*)
            FROM returns r
            LEFT JOIN customers c
                ON r.return_type = 'sale' AND r.customer_id = c.id AND c.tenant_id = ?
            LEFT JOIN suppliers s
                ON r.return_type = 'purchase' AND r.supplier_id = s.id AND s.tenant_id = ?
            WHERE r.tenant_id = ?
        ";

        if ($branchId !== null && $branchId !== '') {
            $query .= " AND r.branch_id = ?";
            $countQuery .= " AND r.branch_id = ?";
            $params[] = (int) $branchId;
            $countParams[] = (int) $branchId;
        }

        if ($search !== '') {
            $query .= " AND (r.return_number LIKE ? OR c.name LIKE ? OR s.name LIKE ?)";
            $countQuery .= " AND (r.return_number LIKE ? OR c.name LIKE ? OR s.name LIKE ?)";

            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";

            $countParams[] = "%{$search}%";
            $countParams[] = "%{$search}%";
            $countParams[] = "%{$search}%";
        }

        if ($type) {
            $query .= " AND r.return_type = ?";
            $countQuery .= " AND r.return_type = ?";
            $params[] = $type;
            $countParams[] = $type;
        }

        if ($startDate) {
            $query .= " AND r.created_at >= ?";
            $countQuery .= " AND r.created_at >= ?";
            $params[] = $startDate . ' 00:00:00';
            $countParams[] = $startDate . ' 00:00:00';
        }

        if ($endDate) {
            $nextDay = date('Y-m-d', strtotime($endDate . ' +1 day'));
            $query .= " AND r.created_at < ?";
            $countQuery .= " AND r.created_at < ?";
            $params[] = $nextDay . ' 00:00:00';
            $countParams[] = $nextDay . ' 00:00:00';
        }

        $query .= " ORDER BY r.created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        $returns = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $acceptLang = $request->getHeaderLine('Accept-Language');
        $locale = (stripos($acceptLang, 'ar') === 0) ? 'ar' : 'en';

        foreach ($returns as &$r) {
            $rt = strtolower((string) ($r['return_type'] ?? '')) === 'purchase'
                ? 'purchase_return'
                : 'sales_return';

            $r['reference_type'] = $rt;
            $r['reference_id'] = $r['id'] ?? null;
            $r['reference'] = $rt . '#' . ($r['id'] ?? '');
            $r['reference_label'] = $this->refLabel($rt, $locale);
            $r['status_label'] = $this->statusLabel($r['status'] ?? null, $locale);
        }
        unset($r);

        $stmt = $this->db->prepare($countQuery);
        $stmt->execute($countParams);
        $total = (int) $stmt->fetchColumn();

        return $this->successResponse($response, [
            'items' => $returns,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'total_pages' => (int) ceil($total / $limit)
        ], 200);
    }

    /** Return one tenant return with items and cash/credit settlement details. */
    public function get(Request $request, Response $response, array $args): Response
    {
        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
        }

        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            return $this->errorResponse($response, 'معرّف المرتجع غير صالح', 400);
        }

        $stmt = $this->db->prepare("
            SELECT
                r.*,
                CASE
                    WHEN r.return_type = 'sale' THEN c.name
                    WHEN r.return_type = 'purchase' THEN s.name
                END AS party_name,
                CASE
                    WHEN r.return_type = 'sale' THEN c.phone
                    WHEN r.return_type = 'purchase' THEN s.phone
                END AS party_phone,
                u.name AS created_by_name,
                CASE
                    WHEN r.return_type = 'sale' THEN sa.invoice_number
                    WHEN r.return_type = 'purchase' THEN pu.invoice_number
                END AS original_invoice_number,
                CASE
                    WHEN r.return_type = 'sale' THEN 'مرتجع مبيعات'
                    WHEN r.return_type = 'purchase' THEN 'مرتجع مشتريات'
                END AS return_type_label
            FROM returns r
            LEFT JOIN customers c
                ON r.return_type = 'sale' AND r.customer_id = c.id AND c.tenant_id = ?
            LEFT JOIN suppliers s
                ON r.return_type = 'purchase' AND r.supplier_id = s.id AND s.tenant_id = ?
            LEFT JOIN users u
                ON r.created_by = u.id AND (u.tenant_id = r.tenant_id OR u.tenant_id IS NULL)
            LEFT JOIN sales sa
                ON r.return_type = 'sale' AND r.sale_id = sa.id AND sa.tenant_id = ?
            LEFT JOIN purchases pu
                ON r.return_type = 'purchase' AND r.purchase_id = pu.id AND pu.tenant_id = ?
            WHERE r.id = ? AND r.tenant_id = ?
        ");
        $stmt->execute([$tenantId, $tenantId, $tenantId, $tenantId, $id, $tenantId]);
        $return = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$return) {
            return $this->errorResponse($response, 'لم يتم العثور على المرتجع', 404);
        }

        $settlementTotal = max(0.0, (float) ($return['grand_total'] ?? $return['total_amount'] ?? 0));
        $cashRefundAmount = min($settlementTotal, max(0.0, (float) ($return['paid_amount'] ?? 0)));
        if (($return['return_type'] ?? null) === 'purchase') {
            // Purchase return policy applies the amount not refunded in cash to the supplier account.
            $offsetAmount = max(0.0, $settlementTotal - $cashRefundAmount);
        } else {
            // Sales-return offsets are persisted as allocations; use actual allocations rather than infer from the total.
            $offsetStmt = $this->db->prepare(
                'SELECT COALESCE(SUM(allocated_amount), 0) FROM return_credit_allocations WHERE return_id = ? AND tenant_id = ?'
            );
            $offsetStmt->execute([$id, $tenantId]);
            $offsetAmount = min($settlementTotal, max(0.0, (float) $offsetStmt->fetchColumn()));
        }
        $return['cash_refund_amount'] = number_format($cashRefundAmount, 2, '.', '');
        $return['offset_amount'] = number_format($offsetAmount, 2, '.', '');
        $return['net_settlement_amount'] = number_format($cashRefundAmount + $offsetAmount, 2, '.', '');

        $stmt = $this->db->prepare("
            SELECT
                ri.*,
                p.name AS product_name,
                p.barcode,
                u.name AS unit_name,
                u.code AS unit_code
            FROM return_items ri
            LEFT JOIN products p ON ri.product_id = p.id AND p.tenant_id = ri.tenant_id
            LEFT JOIN units u ON ri.unit_id = u.id
            WHERE ri.return_id = ? AND ri.tenant_id = ?
        ");
        $stmt->execute([$id, $tenantId]);
        $return['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $acceptLang = $request->getHeaderLine('Accept-Language');
        $locale = (stripos($acceptLang, 'ar') === 0) ? 'ar' : 'en';
        $rt = strtolower((string) ($return['return_type'] ?? '')) === 'purchase'
            ? 'purchase_return'
            : 'sales_return';

        $return['reference_type'] = $rt;
        $return['reference_id'] = $return['id'] ?? null;
        $return['reference'] = $rt . '#' . ($return['id'] ?? '');
        $return['reference_label'] = $this->refLabel($rt, $locale);
        $return['status_label'] = $this->statusLabel($return['status'] ?? null, $locale);

        return $this->successResponse($response, $return, 200);
    }

    /** List only sales returns by forwarding to the shared list implementation. */
    public function listSale(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $params['type'] = 'sale';
        $request = $request->withQueryParams($params);

        return $this->list($request, $response);
    }

    /** List only purchase returns by forwarding to the shared list implementation. */
    public function listPurchase(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $params['type'] = 'purchase';
        $request = $request->withQueryParams($params);

        return $this->list($request, $response);
    }

    /** Fetch a sales return through the shared detail implementation. */
    public function getSale(Request $request, Response $response, array $args): Response
    {
        $args['type'] = 'sale';
        return $this->get($request, $response, $args);
    }

    /** Fetch a purchase return through the shared detail implementation. */
    public function getPurchase(Request $request, Response $response, array $args): Response
    {
        $args['type'] = 'purchase';
        return $this->get($request, $response, $args);
    }

    /** Validate and create a sale or purchase return through ReturnService. */
    public function create(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();

        if (!$data) {
            return $this->errorResponse($response, 'بيانات الطلب غير صالحة', 400);
        }
        $refundMode = strtolower(trim((string) ($data['refund_mode'] ?? 'auto')));
        $salePayoutModes = ['auto', 'cash', 'deduct_and_return', 'refund_excess'];
        if (in_array($refundMode, $salePayoutModes, true) && ($data['return_type'] ?? null) === 'sale') {
            if (!$this->can($request, Permissions::RETURN_APPROVE)) {
                return $this->errorResponse($response, 'لا تملك صلاحية اعتماد صرف مبالغ مرتجعات المبيعات.', 403);
            }
        }
        if ($refundMode === 'refund_excess' && ($data['return_type'] ?? null) !== 'sale') {
            return $this->errorResponse($response, 'خيار صرف فائض المرتجع متاح لمرتجعات المبيعات فقط.', 400);
        }

        $requiredFields = ['return_type', 'return_date', 'items', 'paid_amount', 'payment_method_id', 'invoice_id'];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || (!in_array($field, ['paid_amount', 'discount_value'], true) && empty($data[$field]))) {
                return $this->errorResponse($response, "الحقل المطلوب مفقود: {$field}", 400);
            }
        }

        if (!in_array($data['return_type'], ['sale', 'purchase'], true)) {
            return $this->errorResponse($response, 'نوع المرتجع غير صالح (return_type)، يجب أن يكون sale أو purchase', 400);
        }

        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
        }

        try {
            $this->applyDefaultCostCenter($data, $request);
        } catch (\Throwable $e) {
            $this->logger->warning('applyDefaultCostCenter failed in ReturnsHandler::create', ['message' => $e->getMessage()]);
        }

        // Resolve the customer or supplier from the source invoice when omitted.
        if (empty($data['party_id'])) {
            if ($data['return_type'] === 'sale') {
                $stmt = $this->db->prepare("SELECT customer_id FROM sales WHERE id = ? AND tenant_id = ?");
                $stmt->execute([$data['invoice_id'], $tenantId]);
                $data['party_id'] = $stmt->fetchColumn() ?: null;
            } else {
                $stmt = $this->db->prepare("SELECT supplier_id FROM purchases WHERE id = ? AND tenant_id = ?");
                $stmt->execute([$data['invoice_id'], $tenantId]);
                $data['party_id'] = $stmt->fetchColumn() ?: null;
            }
        }

        if ($data['return_type'] === 'sale' && $refundMode === 'cash') {
            if (!empty($data['party_id'])) {
                return $this->errorResponse($response, 'استخدم خيار صرف فائض المرتجع لفاتورة مرتبطة بعميل.', 400);
            }
            $methodStmt = $this->db->prepare(
                'SELECT kind FROM payment_methods WHERE id = ? AND tenant_id = ? LIMIT 1'
            );
            $methodStmt->execute([(int) $data['payment_method_id'], $tenantId]);
            $methodKind = strtolower((string) ($methodStmt->fetchColumn() ?: ''));
            if ($methodKind === '' || $methodKind === 'credit') {
                return $this->errorResponse($response, 'اختر طريقة دفع غير آجلة لرد قيمة المرتجع.', 400);
            }
        }

        if (!is_array($data['items']) || count($data['items']) === 0) {
            return $this->errorResponse($response, 'قائمة الأصناف (items) يجب أن تكون مصفوفة غير فارغة', 400);
        }

        $requiredItemFields = ['product_id', 'unit_id', 'quantity', 'unit_price', 'subtotal'];
        foreach ($data['items'] as $index => $item) {
            foreach ($requiredItemFields as $field) {
                if (!isset($item[$field]) || $item[$field] === '' || $item[$field] === null) {
                    return $this->errorResponse($response, "حقل مفقود أو غير صالح '{$field}' في العنصر رقم {$index}", 400);
                }
            }
        }

        if (!is_numeric($data['paid_amount']) || $data['paid_amount'] < 0) {
            return $this->errorResponse($response, 'paid_amount يجب أن يكون رقمًا غير سالب', 400);
        }

        if (!is_numeric($data['payment_method_id']) || $data['payment_method_id'] <= 0) {
            return $this->errorResponse($response, 'payment_method_id يجب أن يكون رقمًا موجبًا صالحًا', 400);
        }

        $returnDateRaw = trim((string) ($data['return_date'] ?? ''));
        $timestamp     = strtotime($returnDateRaw);
        if ($timestamp === false) {
            return $this->errorResponse($response, 'تاريخ المرتجع غير صالح. يرجى استخدام الصيغة YYYY-MM-DD.', 400);
        }
        $data['return_date'] = date('Y-m-d', $timestamp);

        $userId = $request->getAttribute('user_id') ?? $this->extractUserId($request) ?? 1;

        try {
            $data['cost_center_id'] = $this->costCenterService->resolve($tenantId, $userId, $data['cost_center_id'] ?? null);
        } catch (\Exception $e) {
            $this->logger->error('Cost center resolution failed for return', [
                'tenant_id' => $tenantId, 'user_id' => $userId, 'error' => $e->getMessage(),
            ]);
            return $this->errorResponse($response, 'فشل في إنشاء المرتجع', 400);
        }

        // Session requirements depend on the payment method and source invoice branch.
        $sessionId       = null;
        $methodIsCash    = $this->isCashMethod((int) $data['payment_method_id'], (int) $tenantId);
        $isCash          = $methodIsCash && (
            $data['paid_amount'] > 0
            || (($data['refund_mode'] ?? null) === 'refund_excess' && $data['return_type'] === 'sale')
        );
        $isSessionsEnabled = $this->isSessionsEnabled((int) $tenantId);
        $isExempt        = $this->isCashierSessionExempt($request);

        // Resolve the session branch from the source invoice.
        $branchIdForSession = null;
        if ($data['return_type'] === 'sale') {
            $stmtBr = $this->db->prepare("SELECT branch_id FROM sales WHERE id = ? AND tenant_id = ? LIMIT 1");
            $stmtBr->execute([$data['invoice_id'], $tenantId]);
            $branchIdForSession = $stmtBr->fetchColumn() ?: null;
        } else {
            $stmtBr = $this->db->prepare("SELECT branch_id FROM purchases WHERE id = ? AND tenant_id = ? LIMIT 1");
            $stmtBr->execute([$data['invoice_id'], $tenantId]);
            $branchIdForSession = $stmtBr->fetchColumn() ?: null;
        }

        if ($isSessionsEnabled && $isCash && !$isExempt) {
            if (empty($branchIdForSession)) {
                return $this->errorResponse($response, 'يجب تحديد المخزن لإتمام الدفعة النقدية للكاشير.', 400);
            }
            try {
                $sessionId = $this->requireOpenCashierSession((int) $tenantId, (int) $branchIdForSession, $userId);
            } catch (\Exception $ex) {
                return $this->errorResponse($response, $ex->getMessage(), 400);
            }
        } elseif (($isExempt || !$isSessionsEnabled) && !empty($branchIdForSession)) {
            $sessionId = $this->findOpenCashierSession((int) $tenantId, (int) $branchIdForSession, null);
        }

        try {
            if (!$this->db->inTransaction()) {
                if (
                    $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
                    && $data['return_type'] === 'sale'
                ) {
                    // Branch-scoped liquidity reads use nested source-row lookups that must see committed returns.
                    $this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
                }
                $this->db->beginTransaction();
            }

            $svc    = $this->returnService((int) $tenantId, $userId);
            $result = $svc->createReturn($data, $tenantId, $userId, $sessionId);

            try {
                $this->audit->logAction('return_created', 'returns', $result['return_id'], [
                    'tenant_id'        => $tenantId,
                    'user_id'          => $userId,
                    'return_id'        => $result['return_id'],
                    'return_type'      => $data['return_type'],
                    'return_number'    => $result['return_number'],
                    'invoice_id'       => $data['invoice_id']        ?? null,
                    'return_date'      => $data['return_date']        ?? null,
                    'paid_amount'      => $data['paid_amount'],
                    'payment_method_id' => $data['payment_method_id'],
                    'session_id'       => $sessionId,
                    'cost_center_id'   => $data['cost_center_id']    ?? null,
                    'journal_entry_id' => $result['journal_entry_id'] ?? null,
                ], $tenantId, $userId);
            } catch (\Throwable $auditError) {
                $this->logger->warning('Failed to log return_created to audit', ['error' => $auditError->getMessage()]);
            }

            $this->db->commit();

            return $this->successResponse($response, [
                'return_id' => $result['return_id'],
                'message'   => 'تم إنشاء المرتجع بنجاح.',
            ], 200);

        } catch (\PDOException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logger->error('Return creation database error', ['message' => $e->getMessage(), 'tenant_id' => $tenantId]);
            return $this->errorResponse($response, 'حدث خطأ في قاعدة البيانات أثناء إنشاء المرتجع', 500);
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logger->error('Return creation failed', ['message' => $e->getMessage(), 'tenant_id' => $tenantId]);

            if ($e instanceof \App\Exceptions\InsufficientLiquidityException) {
                $context = $e->getContext();
                $balanceDetails = isset($context['current_balance'], $context['shortfall'])
                    ? sprintf(
                        ' الرصيد النقدي الحالي: %s، والعجز المتوقع: %s.',
                        number_format((float) $context['current_balance'], 2),
                        number_format((float) $context['shortfall'], 2)
                    )
                    : '';
                $message = match ($e->getMessage()) {
                    'Cash account cannot go negative (setting disabled)' =>
                        'لا يمكن رد المبلغ نقدًا لأن رصيد الصندوق سيصبح سالبًا وإعداد السماح بالسالب غير مفعّل.' . $balanceDetails,
                    'Cash account requires active cashier session for negative balance' =>
                        'لا يمكن رد المبلغ نقدًا لأن الرصيد المتاح أقل من مبلغ الاسترداد. افتح جلسة كاشير نشطة للفرع ثم أعد المحاولة.' . $balanceDetails,
                    'Cash account cannot go negative when cashier sessions are disabled' =>
                        'لا يمكن رد المبلغ نقدًا لأن رصيد الصندوق سيصبح سالبًا وإلزام جلسات الكاشير معطل.' . $balanceDetails,
                    'Cashier session not open (required for intra-day negative balance)' =>
                        'جلسة الكاشير غير مفتوحة أو أُغلقت. افتح جلسة للفرع المختار ثم أعد المحاولة.' . $balanceDetails,
                    default => $e->getMessage(),
                };
                return $this->errorResponse($response, $message, 402, [
                    'error_code' => 'insufficient_liquidity',
                    'details' => $context,
                ]);
            }
            if ($e instanceof \DomainException) {
                return $this->errorResponse($response, $e->getMessage(), 422);
            }

            $msg = $e->getMessage();
            if (strpos($msg, 'لا يمكن') !== false || strpos($msg, 'فشل') !== false) {
                return $this->errorResponse($response, $msg, 400);
            }
            return $this->errorResponse($response, 'فشل في إنشاء المرتجع', 400);
        }
    }

    /** Search eligible source invoices with branch, date, party, and settlement data. */
    public function searchInvoice(Request $request, Response $response): Response
    {
        $tenantId = $this->extractTenantId($request);

        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
        }

        $queryParams = $request->getQueryParams();
        $invoiceNumber = trim((string)($queryParams['q'] ?? ''));
        $returnType = trim((string)($queryParams['type'] ?? ''));
        $fromDate = trim((string)($queryParams['from_date'] ?? ''));
        $toDate = trim((string)($queryParams['to_date'] ?? ''));
        $branchId = null;
        if (isset($queryParams['branch_id']) && $queryParams['branch_id'] !== '') {
            if (!ctype_digit((string) $queryParams['branch_id']) || (int) $queryParams['branch_id'] <= 0) {
                return $this->errorResponse($response, 'معرف الفرع غير صالح.', 400);
            }
            $branchId = (int) $queryParams['branch_id'];
            $branchStmt = $this->db->prepare(
                'SELECT 1 FROM branches WHERE id = ? AND tenant_id = ? LIMIT 1'
            );
            $branchStmt->execute([$branchId, $tenantId]);
            if (!$branchStmt->fetchColumn()) {
                return $this->errorResponse($response, 'الفرع المحدد غير موجود.', 404);
            }
        }
        $user = $request->getAttribute('user');
        if (!$this->canViewAllBranches(is_array($user) ? $user : null)) {
            $userBranchId = is_array($user) ? (int) ($user['branch_id'] ?? 0) : 0;
            if ($userBranchId <= 0) {
                return $this->errorResponse(
                    $response,
                    'حسابك غير مرتبط بفرع. يرجى التواصل مع الإدارة لتعيين فرع لحسابك.',
                    403
                );
            }
            $branchId = $userBranchId;
        }

        if (!in_array($returnType, ['sale', 'purchase'], true)) {
            return $this->errorResponse($response, 'نوع المرتجع غير صالح، يجب أن يكون sale أو purchase', 400);
        }

        $isSale = $returnType === 'sale';
        $table = $isSale ? 'sales' : 'purchases';
        $dateField = $isSale ? 'sale_date' : 'created_at';

        $partySelect = $isSale
            ? 'c.name AS customer_name, c.id AS customer_id'
            : 's.name AS supplier_name, s.id AS supplier_id';

        $partyJoin = $isSale
            ? 'LEFT JOIN customers c ON i.customer_id = c.id AND c.tenant_id = :tenant_id_party'
            : 'LEFT JOIN suppliers s ON i.supplier_id = s.id AND s.tenant_id = :tenant_id_party';

        if ($isSale) {
            $actualPaidAmount = '(COALESCE(spt.direct_paid_amount, 0) + COALESCE(apt.allocated_paid_amount, 0))';
            $selectFields = "
            i.total_amount,
            i.discount_type,
            i.discount_value,
            i.tax_amount,
            i.net_total_amount,
            {$actualPaidAmount} AS paid_amount,
            {$actualPaidAmount} AS actual_paid_amount,
            (i.net_total_amount + IFNULL(i.tax_amount, 0)) AS grand_total,
            COALESCE(SUM(rca.allocated_amount), 0) AS return_credits_applied,
            ((i.net_total_amount + IFNULL(i.tax_amount, 0)) - {$actualPaidAmount}) AS outstanding,
            ((i.net_total_amount + IFNULL(i.tax_amount, 0)) - {$actualPaidAmount}) AS outstanding_amount,
            GREATEST(0, (i.net_total_amount + IFNULL(i.tax_amount, 0)) - {$actualPaidAmount} - COALESCE(SUM(rca.allocated_amount), 0)) AS net_outstanding,
            CASE
                WHEN {$actualPaidAmount} <= 0 AND COALESCE(SUM(rca.allocated_amount), 0) > 0 THEN 'credit'
                WHEN {$actualPaidAmount} >= (i.net_total_amount + IFNULL(i.tax_amount, 0)) THEN 'cash'
                WHEN {$actualPaidAmount} > 0
                     AND {$actualPaidAmount} < (i.net_total_amount + IFNULL(i.tax_amount, 0)) THEN 'partial'
                ELSE 'unknown'
            END AS payment_status,
            CASE
                WHEN IFNULL(i.discount_value, 0) > 0 THEN 1
                ELSE 0
            END AS has_discount,
            i.branch_id,
            i.payment_method_id,
            pm.name AS payment_method_name,
            pm.kind AS payment_method_kind,
            (
                SELECT COALESCE(SUM(jel.debit_amount - jel.credit_amount), 0)
                FROM customers cb
                LEFT JOIN journal_entry_lines jel
                    ON jel.account_id = cb.account_id
                   AND jel.tenant_id = cb.tenant_id
                WHERE cb.id = i.customer_id
                  AND cb.tenant_id = :tenant_id_customer_balance
            ) AS customer_account_balance,
            (
                SELECT COALESCE(SUM(GREATEST(
                    0,
                    cs.net_total_amount + IFNULL(cs.tax_amount, 0)
                    - COALESCE((
                        SELECT SUM(CASE WHEN cp.type = 'return_payment' THEN -cp.amount ELSE cp.amount END)
                        FROM payments cp
                        WHERE cp.sale_id = cs.id AND cp.tenant_id = cs.tenant_id
                          AND cp.is_draft = 0 AND cp.status = 'completed'
                    ), 0)
                    - COALESCE((
                        SELECT SUM(cpa.allocated_amount)
                        FROM customer_payment_allocations cpa
                        INNER JOIN payments ap ON ap.id = cpa.payment_id AND ap.tenant_id = cpa.tenant_id
                        WHERE cpa.sale_id = cs.id AND cpa.tenant_id = cs.tenant_id
                          AND ap.is_draft = 0 AND ap.status = 'completed'
                    ), 0)
                    - COALESCE((
                        SELECT SUM(rca.allocated_amount)
                        FROM return_credit_allocations rca
                        WHERE rca.sale_id = cs.id AND rca.tenant_id = cs.tenant_id
                    ), 0)
                )), 0)
                FROM sales cs
                WHERE cs.customer_id = i.customer_id
                  AND cs.tenant_id = i.tenant_id
                  AND LOWER(COALESCE(cs.status, '')) NOT IN ('cancelled', 'canceled')
            ) AS customer_total_outstanding,
            (SELECT COUNT(*) FROM sales_items WHERE sale_id = i.id AND tenant_id = :tenant_id_subquery) AS items_count
        ";
        } else {
            $selectFields = "
            i.total_amount,
            NULL AS discount_type,
            IFNULL(i.discount_value, 0) AS discount_value,
            i.tax_amount,
            (i.total_amount - IFNULL(i.discount_value, 0)) AS net_total_amount,
            IFNULL(i.paid_amount, 0) AS paid_amount,
            (i.total_amount + IFNULL(i.tax_amount, 0) - IFNULL(i.discount_value, 0)) AS grand_total,
            0 AS return_credits_applied,
            (
                (i.total_amount + IFNULL(i.tax_amount, 0) - IFNULL(i.discount_value, 0))
                - IFNULL(i.paid_amount, 0)
            ) AS outstanding,
            (
                (i.total_amount + IFNULL(i.tax_amount, 0) - IFNULL(i.discount_value, 0))
                - IFNULL(i.paid_amount, 0)
            ) AS outstanding_amount,
            (
                (i.total_amount + IFNULL(i.tax_amount, 0) - IFNULL(i.discount_value, 0))
                - IFNULL(i.paid_amount, 0)
            ) AS net_outstanding,
            CASE
                WHEN IFNULL(i.paid_amount, 0) <= 0 THEN 'credit'
                WHEN IFNULL(i.paid_amount, 0) >= (i.total_amount + IFNULL(i.tax_amount, 0) - IFNULL(i.discount_value, 0)) THEN 'cash'
                WHEN IFNULL(i.paid_amount, 0) > 0
                     AND IFNULL(i.paid_amount, 0) < (i.total_amount + IFNULL(i.tax_amount, 0) - IFNULL(i.discount_value, 0)) THEN 'partial'
                ELSE 'unknown'
            END AS payment_status,
            CASE
                WHEN IFNULL(i.discount_value, 0) > 0 THEN 1
                ELSE 0
            END AS has_discount,
            i.branch_id,
            i.payment_method_id,
            pm.name AS payment_method_name,
            pm.kind AS payment_method_kind,
            (SELECT COUNT(*) FROM purchase_items WHERE purchase_id = i.id AND tenant_id = :tenant_id_subquery) AS items_count
        ";
        }

        $sql = "
        SELECT
            i.id,
            i.invoice_number,
            i.{$dateField} AS invoice_date,
            i.status,
            {$selectFields},
            {$partySelect},
            b.name AS branch_name
        FROM {$table} i
        {$partyJoin}
        LEFT JOIN payment_methods pm
            ON i.payment_method_id = pm.id
           AND pm.tenant_id = :tenant_id_payment_method
        LEFT JOIN branches b
            ON i.branch_id = b.id
           AND b.tenant_id = :tenant_id_branch
        " . ($isSale ? "LEFT JOIN (
            SELECT tenant_id, sale_id,
                   SUM(CASE WHEN type = 'return_payment' THEN -amount ELSE amount END) AS direct_paid_amount
            FROM payments
            WHERE is_draft = 0 AND status = 'completed' AND sale_id IS NOT NULL
            GROUP BY tenant_id, sale_id
        ) spt ON spt.sale_id = i.id AND spt.tenant_id = i.tenant_id
        LEFT JOIN (
            SELECT cpa.tenant_id, cpa.sale_id, SUM(cpa.allocated_amount) AS allocated_paid_amount
            FROM customer_payment_allocations cpa
            INNER JOIN payments ap ON ap.id = cpa.payment_id AND ap.tenant_id = cpa.tenant_id
            WHERE ap.is_draft = 0 AND ap.status = 'completed'
            GROUP BY cpa.tenant_id, cpa.sale_id
        ) apt ON apt.sale_id = i.id AND apt.tenant_id = i.tenant_id
        LEFT JOIN return_credit_allocations rca
            ON i.id = rca.sale_id
           AND i.tenant_id = rca.tenant_id" : "") . "
        WHERE i.tenant_id = :tenant_id_where
    ";

        $sql .= $isSale
            ? " AND LOWER(COALESCE(i.status, '')) NOT IN ('draft', 'pending', 'pending_approval', 'awaiting_approval', 'rejected', 'canceled', 'cancelled')"
            : " AND LOWER(COALESCE(i.status, '')) NOT IN ('draft', 'rejected', 'canceled', 'cancelled')";

        $params = [
            ':tenant_id_where' => $tenantId,
            ':tenant_id_party' => $tenantId,
            ':tenant_id_payment_method' => $tenantId,
            ':tenant_id_branch' => $tenantId,
            ':tenant_id_subquery' => $tenantId
        ];
        if ($isSale) {
            $params[':tenant_id_customer_balance'] = $tenantId;
        }
        if ($branchId !== null) {
            $sql .= ' AND i.branch_id = :branch_id';
            $params[':branch_id'] = $branchId;
        }

        if ($invoiceNumber !== '') {
            $sql .= " AND (i.invoice_number LIKE :invoice_number";

            if (ctype_digit($invoiceNumber)) {
                $sql .= " OR i.id = :invoice_id";
                $params[':invoice_id'] = (int)$invoiceNumber;
            }

            if ($isSale) {
                $sql .= " OR (c.name IS NOT NULL AND c.name LIKE :customer_name)";
                $sql .= " OR (c.phone IS NOT NULL AND c.phone LIKE :phone)";
                $params[':customer_name'] = "%{$invoiceNumber}%";
                $params[':phone'] = "%{$invoiceNumber}%";
            } else {
                $sql .= " OR (s.name IS NOT NULL AND s.name LIKE :supplier_name)";
                $sql .= " OR (s.phone IS NOT NULL AND s.phone LIKE :phone)";
                $params[':supplier_name'] = "%{$invoiceNumber}%";
                $params[':phone'] = "%{$invoiceNumber}%";
            }

            $sql .= ")";
            $params[':invoice_number'] = "%{$invoiceNumber}%";
        }

        if ($fromDate !== '') {
            $sql .= " AND DATE(i.{$dateField}) >= :from_date";
            $params[':from_date'] = $fromDate;
        }

        if ($toDate !== '') {
            $sql .= " AND DATE(i.{$dateField}) <= :to_date";
            $params[':to_date'] = $toDate;
        }

        if ($isSale) {
            $sql .= " GROUP BY i.id, i.invoice_number, i.{$dateField}, i.status, i.total_amount, i.discount_type, i.discount_value, i.tax_amount, i.net_total_amount, i.paid_amount, i.branch_id, i.payment_method_id, pm.name, pm.kind, c.name, c.id, c.phone, b.name, spt.direct_paid_amount, apt.allocated_paid_amount";
        }

        $sql .= " ORDER BY i.{$dateField} DESC LIMIT 50";

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);

            return $this->successResponse(
                $response,
                $stmt->fetchAll(PDO::FETCH_ASSOC),
                200
            );
        } catch (\Throwable $e) {
            $this->logger->error('Error in searchInvoice', [
                'tenant_id' => $tenantId,
                'return_type' => $returnType,
                'invoice_number' => $invoiceNumber,
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'message' => $e->getMessage()
            ]);

            return $this->errorResponse($response, 'حدث خطأ أثناء البحث عن الفاتورة', 500);
        }
    }

    /** Return item rows and invoice metadata used to prepare a return. */
    public function getInvoiceItems(Request $request, Response $response): Response
    {
        $tenantId = $this->extractTenantId($request);

        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
        }

        $queryParams = $request->getQueryParams();
        $invoiceId = (int)($queryParams['invoice_id'] ?? 0);
        $returnType = trim((string)($queryParams['type'] ?? ''));

        if ($invoiceId <= 0 || !in_array($returnType, ['sale', 'purchase'], true)) {
            return $this->errorResponse($response, 'معرّف الفاتورة أو نوع المرتجع غير صالح', 400);
        }

        try {
            if ($returnType === 'sale') {
                $itemsStmt = $this->db->prepare("
                SELECT
                    si.id,
                    p.id          AS product_id,
                    p.name        AS product_name,
                    p.barcode,
                    p.product_code,
                    si.unit_id,
                    si.quantity,
                    si.sale_price AS unit_price,
                    si.discount_value AS discount,
                    si.discount_value AS discount_amount,
                    si.net_total  AS subtotal,
                    NULL          AS batch_number,
                    NULL          AS expiry_date
                FROM sales_items si
                JOIN products p
                    ON si.product_id = p.id
                   AND p.tenant_id = :tenant_id_product
                WHERE si.sale_id = :invoice_id
                  AND si.tenant_id = :tenant_id_items
            ");

                $invoiceStmt = $this->db->prepare("
                SELECT
                    customer_id,
                    invoice_number,
                    sale_date AS invoice_date
                FROM sales
                WHERE id = :invoice_id
                  AND tenant_id = :tenant_id
            ");
            } else {
                $itemsStmt = $this->db->prepare("
                SELECT
                    pi.id,
                    p.id AS product_id,
                    p.name AS product_name,
                    p.barcode,
                    p.product_code,
                    pi.unit_id,
                    pi.quantity,
                    pi.cost AS unit_price,
                    pi.tax_rate,
                    pi.tax_amount,
                    pi.discount_amount AS discount,
                    pi.discount_amount AS discount_amount,
                    pi.subtotal,
                    pi.batch_number,
                    pi.expiry_date
                FROM purchase_items pi
                JOIN products p
                    ON pi.product_id = p.id
                   AND p.tenant_id = :tenant_id_product
                WHERE pi.purchase_id = :invoice_id
                  AND pi.tenant_id = :tenant_id_items
            ");

                $invoiceStmt = $this->db->prepare("
                SELECT
                    supplier_id,
                    invoice_number,
                    created_at AS invoice_date
                FROM purchases
                WHERE id = :invoice_id
                  AND tenant_id = :tenant_id
            ");
            }

            $itemsStmt->execute([
                ':invoice_id' => $invoiceId,
                ':tenant_id_product' => $tenantId,
                ':tenant_id_items' => $tenantId,
            ]);
            $invoiceStmt->execute([
                ':invoice_id' => $invoiceId,
                ':tenant_id' => $tenantId,
            ]);

            $invoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC);
            if (!$invoice) {
                return $this->errorResponse($response, 'الفاتورة غير موجودة', 404);
            }

            return $this->successResponse($response, [
                'items' => $itemsStmt->fetchAll(PDO::FETCH_ASSOC),
                'invoice' => $invoice
            ], 200);
        } catch (\Throwable $e) {
            $this->logger->error('Error in getInvoiceItems', [
                'tenant_id' => $tenantId,
                'invoice_id' => $invoiceId,
                'return_type' => $returnType,
                'message' => $e->getMessage()
            ]);

            return $this->errorResponse($response, 'حدث خطأ أثناء جلب بيانات الفاتورة', 500);
        }
    }

    /** Determine whether the request user may bypass the default branch filter. */
    private function canViewAllBranches(?array $user): bool
    {
        if ($user === null) {
            return false;
        }

        $roles = [];
        if (isset($user['roles']) && is_array($user['roles'])) {
            $roles = array_map(
                static fn ($role): string => strtolower((string) $role),
                $user['roles']
            );
        } elseif (isset($user['role']) && is_string($user['role'])) {
            $roles = [strtolower($user['role'])];
        }

        return SuperAdminHelper::isAdminOrAbove($user)
            || in_array('manager', $roles, true);
    }
}
