<?php

declare(strict_types=1);

namespace App\Handlers;

use PDO;
use Exception;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Services\SalesService;
use App\Services\AccountingService;
use App\Services\MonologHandler;
use App\Services\LabelService;
use App\Services\CurrencyService;
use App\Handlers\AuditHandler;
use App\Utils\ApiResponseEnvelope;
use App\Utils\PaginationHelper;
use App\Repositories\SaleRepository;

/**
 * Exposes tenant-scoped sale, approval, status, and payment endpoints.
 *
 * Reads and branch scoping use PDO directly; mutations delegate to the
 * sale-creation, sales, approval, and payment services provided by BaseHandler.
 * The handler also resolves dynamic sale status through AccountingService
 * and checks approval permissions through RBACHandler. Routes and permissions
 * are defined in app/routes/sales.php.
 */
class SalesHandler extends BaseHandler
{
    private $rbac;

    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->logger = MonologHandler::getInstance('sales');
        $this->rbac   = new RBACHandler($db);
    }

    /** Use the accounting status policy for the calculated sale status. */
    private function determineSaleStatus(float $grandTotal, float $paidAmount, float $returnAmount = 0.0, float $returnCredits = 0.0, $createdAt = null, $firstPaymentDate = null, bool $hasDirectReturn = false): string
    {
        return (new AccountingService($this->db))
            ->computeSaleStatus($grandTotal, $paidAmount, $returnAmount, $returnCredits, $hasDirectReturn);
    }

    private function resolveSalesBranchScope(Request $request, int $tenantId): ?int
    {
        $requestUser = $request->getAttribute('user');
        $isBranchPrivileged = \App\Utils\SuperAdminHelper::isAdminOrAbove(
            is_array($requestUser) ? $requestUser : null
        ) || (
            is_array($requestUser)
            && (int) ($requestUser['tenant_id'] ?? 0) > 0
            && strtolower((string) ($requestUser['role'] ?? '')) === 'manager'
        );

        $branchId = null;
        if ($isBranchPrivileged) {
            $qp = $request->getQueryParams();
            if (isset($qp['branch_id']) && trim((string) $qp['branch_id']) !== '') {
                $requestedBranchId = trim((string) $qp['branch_id']);
                if (!ctype_digit($requestedBranchId) || (int) $requestedBranchId <= 0) {
                    throw new \DomainException('معرف الفرع غير صالح.', 400);
                }
                $branchId = (int) $requestedBranchId;
            }
        } else {
            $userId = $this->extractUserId($request);
            if (!$userId) {
                throw new \DomainException('تعذر تحديد المستخدم الحالي.', 403);
            }

            $userBranchStmt = $this->db->prepare(
                'SELECT branch_id FROM users WHERE id = ? AND tenant_id = ? LIMIT 1'
            );
            $userBranchStmt->execute([$userId, $tenantId]);
            $assignedBranchId = $userBranchStmt->fetchColumn();
            if (!$assignedBranchId || (int) $assignedBranchId <= 0) {
                throw new \DomainException('لم يتم تعيين فرع لحسابك.', 403);
            }
            $branchId = (int) $assignedBranchId;
        }

        if ($branchId !== null) {
            $branchStmt = $this->db->prepare(
                'SELECT 1 FROM branches WHERE id = ? AND tenant_id = ? LIMIT 1'
            );
            $branchStmt->execute([$branchId, $tenantId]);
            if (!$branchStmt->fetchColumn()) {
                throw new \DomainException('الفرع المحدد غير متاح لهذا المستأجر.', 403);
            }
        }

        return $branchId;
    }

    /**
     * List sales with filtering, pagination and optional totals.
     *
     * Query parameters supported: q, customer_id, status, date_from, date_to,
     * branch_id, sort, order, include_totals, page, per_page
     *
     * @param Request $request PSR-7 request
     * @param Response $response PSR-7 response
     * @return Response
     */
    public function list(Request $request, Response $response): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $branchId = $this->resolveSalesBranchScope($request, (int) $tenantId);
            $qp = $request->getQueryParams();

            [$page, $perPage, $offset] = PaginationHelper::fromRequest($request);

            $where = ["s.tenant_id = ?"];
            $params = [$tenantId];

            $search = trim((string) ($qp['q'] ?? $qp['search'] ?? ''));
            if ($search !== '') {
                $where[] = "(s.id = ? OR c.name LIKE ? OR s.invoice_number LIKE ?)";
                $params[] = ctype_digit($search) ? (int) $search : 0;
                $params[] = "%{$search}%";
                $params[] = "%{$search}%";
            }

            if (!empty($qp['customer_id'])) {
                $where[] = "s.customer_id = ?";
                $params[] = $qp['customer_id'];
            }

            if (!empty($qp['status'])) {
                $where[] = "s.status = ?";
                $params[] = $qp['status'];
            }

            $dateFrom = $qp['date_from'] ?? ($qp['start_date'] ?? null);
            $dateTo = $qp['date_to'] ?? ($qp['end_date'] ?? null);

            if (!empty($dateFrom)) {
                $where[] = "DATE(s.created_at) >= ?";
                $params[] = $dateFrom;
            }

            if (!empty($dateTo)) {
                $where[] = "DATE(s.created_at) <= ?";
                $params[] = $dateTo;
            }

            if ($branchId !== null) {
                $where[] = "s.branch_id = ?";
                $params[] = $branchId;
            }

            $sort = strtolower((string) ($qp['sort'] ?? 'created_at'));
            $order = strtolower((string) ($qp['order'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

            $allowedSort = [
                'id' => 's.id',
                'created_at' => 's.created_at',
                'total_amount' => 's.total_amount',
                'net_total_amount' => 's.net_total_amount',
                'status' => 's.status',
            ];

            $sortCol = $allowedSort[$sort] ?? 's.created_at';

            $from = "
                FROM sales s
                LEFT JOIN customers c ON s.customer_id = c.id AND c.tenant_id = s.tenant_id
                LEFT JOIN users u ON s.user_id = u.id AND u.tenant_id = s.tenant_id
            ";

            $whereSql = " WHERE " . implode(' AND ', $where) . " ";

            $stmt = $this->db->prepare("SELECT COUNT(*)" . $from . $whereSql);
            $stmt->execute($params);
            $total = (int) $stmt->fetchColumn();

            $stmt = $this->db->prepare(
                "SELECT s.*,
                        c.name AS customer_name,
                        u.name AS created_by_name,
                        COALESCE((SELECT SUM(
                                      CASE WHEN pm.type = 'return_payment' THEN -pm.amount ELSE pm.amount END
                                  ) FROM payments pm
                                  WHERE pm.sale_id = s.id AND pm.tenant_id = s.tenant_id
                                    AND pm.status = 'completed' AND COALESCE(pm.is_draft, 0) = 0), 0)
                        + COALESCE((SELECT SUM(cpa.allocated_amount)
                                    FROM customer_payment_allocations cpa
                                    JOIN payments ap ON ap.id = cpa.payment_id AND ap.tenant_id = cpa.tenant_id
                                    WHERE cpa.sale_id = s.id AND cpa.tenant_id = s.tenant_id
                                      AND ap.status = 'completed' AND COALESCE(ap.is_draft, 0) = 0), 0)
                        AS actual_paid_amount,
                        COALESCE((SELECT SUM(r.grand_total) FROM returns r
                                  WHERE r.sale_id = s.id AND r.tenant_id = s.tenant_id
                                    AND r.return_type = 'sale'), 0)  AS return_amount,
                        COALESCE((SELECT SUM(rca.allocated_amount) FROM return_credit_allocations rca
                                  WHERE rca.sale_id = s.id AND rca.tenant_id = s.tenant_id), 0) AS return_credits,
                        (SELECT MIN(pm.payment_date) FROM payments pm
                         WHERE pm.sale_id = s.id AND pm.tenant_id = s.tenant_id
                          AND pm.status = 'completed' AND COALESCE(pm.is_draft, 0) = 0 LIMIT 1) AS first_payment_date,
                        (SELECT GROUP_CONCAT(r.id) FROM returns r
                         WHERE r.sale_id = s.id AND r.tenant_id = s.tenant_id
                           AND r.return_type = 'sale') AS return_ids
                 " . $from . $whereSql . "
                 ORDER BY {$sortCol} {$order}
                 LIMIT {$perPage} OFFSET {$offset}"
            );
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $items = [];
            foreach ($rows as $row) {
                $grandTotal    = round((float)($row['net_total_amount'] ?? 0) + (float)($row['tax_amount'] ?? 0), 2);
                $actualPaid    = round((float)$row['actual_paid_amount'], 2);
                $returnAmount  = round((float)($row['return_amount'] ?? 0), 2);
                $returnCredits = round((float)($row['return_credits'] ?? 0), 2);
                $isReturned    = $grandTotal > 0 && $returnAmount >= $grandTotal;
                $hasDirectReturn = !empty($row['return_ids']);  // Determine if this invoice has direct returns

                $row['actual_paid_amount']  = $actualPaid;
                $row['return_amount']       = $returnAmount;
                $row['remaining_balance'] = $isReturned ? 0.0 : max(0, round($grandTotal - $actualPaid - $returnCredits, 2));
                $row['dynamic_status'] = in_array(strtolower((string) $row['status']), ['canceled', 'cancelled'], true)
                    ? 'canceled'
                    : $this->determineSaleStatus($grandTotal, $actualPaid, $returnAmount, $returnCredits, $row['created_at'], $row['first_payment_date'], $hasDirectReturn);
                $items[] = $row;
            }

            $summary = null;
            $includeTotals = isset($qp['include_totals']) && (string) $qp['include_totals'] !== '0';

            if ($includeTotals) {
                $stmt = $this->db->prepare(
                    "SELECT
                        COALESCE(SUM(s.net_total_amount), 0) AS sum_net_total,
                        COALESCE(SUM(s.tax_amount), 0) AS sum_tax,
                        COALESCE(SUM(COALESCE(
                            (SELECT SUM(si.discount_value)
                             FROM sales_items si
                             WHERE si.sale_id = s.id AND si.tenant_id = s.tenant_id),
                            CASE
                                WHEN s.discount_type = 'percentage' THEN s.total_amount * s.discount_value / 100
                                ELSE s.discount_value
                            END
                        )), 0) AS sum_discount
                     " . $from . $whereSql . " AND s.status NOT IN ('canceled', 'cancelled')"
                );
                $stmt->execute($params);
                $summary = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
                    'sum_net_total' => 0,
                    'sum_tax' => 0,
                    'sum_discount' => 0
                ];
            }

            return $this->successResponse($response, [
                'items'      => $items,
                'total'      => $total,
                'summary'    => $summary,
                'pagination' => PaginationHelper::buildMeta($total, $page, $perPage),
            ], 200);
        } catch (\DomainException $e) {
            return $this->errorResponse($response, $e->getMessage(), (int) $e->getCode() ?: 403);
        } catch (\Throwable $e) {
            $this->logger->error('Sales list error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->errorResponse($response, 'Failed to retrieve sales list', 500);
        }
    }

    /**
     * Get single sale details including items, payments and dynamic status.
     *
     * @param Request $request
     * @param Response $response
     * @param array $args Route arguments (expects 'id')
     * @return Response
     */
    public function get(Request $request, Response $response, array $args = []): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $id = $args['id'] ?? null;
            if (!$id) {
                return $this->errorResponse($response, 'Sale ID is required', 400);
            }

            $branchId = $this->resolveSalesBranchScope($request, (int) $tenantId);
            $branchCondition = $branchId !== null ? ' AND s.branch_id = ?' : '';
            $stmt = $this->db->prepare("
                SELECT s.*,
                       c.name AS customer_name,
                       u.name AS created_by_name,
                       b.name     AS branch_name,
                       b.location AS branch_location,
                       b.phone    AS branch_phone,
                       b.email    AS branch_email,
                       pm.name    AS payment_method_name,
                       pm.kind    AS payment_method_kind
                FROM sales s
                LEFT JOIN customers c       ON s.customer_id = c.id         AND c.tenant_id  = s.tenant_id
                LEFT JOIN users u           ON s.user_id     = u.id         AND u.tenant_id  = s.tenant_id
                LEFT JOIN branches b        ON s.branch_id   = b.id         AND b.tenant_id  = s.tenant_id
                LEFT JOIN payment_methods pm ON s.payment_method_id = pm.id AND pm.tenant_id = s.tenant_id
                WHERE s.id = ? AND s.tenant_id = ?{$branchCondition}
            ");
            $queryParams = [$id, $tenantId];
            if ($branchId !== null) {
                $queryParams[] = $branchId;
            }
            $stmt->execute($queryParams);
            $sale = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$sale) {
                return $this->errorResponse($response, 'Sale not found', 403);
            }

            $stmt = $this->db->prepare("
                SELECT si.*, p.name AS product_name, p.barcode, c.name AS category_name
                FROM sales_items si
                JOIN products p ON si.product_id = p.id
                LEFT JOIN categories c ON p.category_id = c.id
                WHERE si.sale_id = ? AND si.tenant_id = ?
            ");
            $stmt->execute([$id, $tenantId]);
            $sale['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $stmtPaid = $this->db->prepare(
                "SELECT COALESCE(SUM(CASE WHEN type = 'return_payment' THEN -amount ELSE amount END), 0)
                 FROM payments
                 WHERE sale_id = ? AND tenant_id = ? AND status = 'completed'
                   AND COALESCE(is_draft, 0) = 0"
            );
            $stmtPaid->execute([$id, $tenantId]);
            $actualPaid  = (float) $stmtPaid->fetchColumn();
            $stmtAllocatedPaid = $this->db->prepare(
                "SELECT COALESCE(SUM(cpa.allocated_amount), 0)
                 FROM customer_payment_allocations cpa
                 JOIN payments p ON p.id = cpa.payment_id AND p.tenant_id = cpa.tenant_id
                 WHERE cpa.sale_id = ? AND cpa.tenant_id = ?
                   AND p.status = 'completed' AND COALESCE(p.is_draft, 0) = 0"
            );
            $stmtAllocatedPaid->execute([$id, $tenantId]);
            $actualPaid = round($actualPaid + (float) $stmtAllocatedPaid->fetchColumn(), 2);
            $grandTotal  = round((float)($sale['net_total_amount'] ?? 0) + (float)($sale['tax_amount'] ?? 0), 2);

            $stmtPayments = $this->db->prepare(
                "SELECT
                    (SELECT COUNT(*) FROM payments
                     WHERE sale_id = ? AND tenant_id = ? AND amount > 0
                       AND status = 'completed' AND COALESCE(is_draft, 0) = 0)
                    + (SELECT COUNT(*) FROM customer_payment_allocations cpa
                       JOIN payments p ON p.id = cpa.payment_id AND p.tenant_id = cpa.tenant_id
                       WHERE cpa.sale_id = ? AND cpa.tenant_id = ?
                         AND p.status = 'completed' AND COALESCE(p.is_draft, 0) = 0)"
            );
            $stmtPayments->execute([$id, $tenantId, $id, $tenantId]);
            $sale['has_payment_records'] = (int) $stmtPayments->fetchColumn() > 0;

            $stmtReturn = $this->db->prepare(
                "SELECT COALESCE(SUM(grand_total), 0) FROM returns
                 WHERE sale_id = ? AND tenant_id = ? AND return_type = 'sale'"
            );
            $stmtReturn->execute([$id, $tenantId]);
            $returnAmount = round((float) $stmtReturn->fetchColumn(), 2);

            $stmtReturnCredits = $this->db->prepare(
                "SELECT COALESCE(SUM(allocated_amount), 0) FROM return_credit_allocations
                 WHERE sale_id = ? AND tenant_id = ?"
            );
            $stmtReturnCredits->execute([$id, $tenantId]);
            $returnCredits = round((float) $stmtReturnCredits->fetchColumn(), 2);

            // Determine if this invoice has direct returns
            $stmtDirectReturns = $this->db->prepare(
                "SELECT COUNT(*) FROM returns
                 WHERE sale_id = ? AND tenant_id = ? AND return_type = 'sale'"
            );
            $stmtDirectReturns->execute([$id, $tenantId]);
            $hasDirectReturn = (int)$stmtDirectReturns->fetchColumn() > 0;

            // Get first payment date for accurate status determination
            $stmtFirstPayment = $this->db->prepare(
                "SELECT MIN(payment_date) FROM payments
                 WHERE sale_id = ? AND tenant_id = ? AND status = 'completed'
                   AND COALESCE(is_draft, 0) = 0 LIMIT 1"
            );
            $stmtFirstPayment->execute([$id, $tenantId]);
            $firstPaymentDate = $stmtFirstPayment->fetchColumn();

            $isReturned   = $grandTotal > 0 && $returnAmount >= $grandTotal;

            $sale['actual_paid_amount'] = $actualPaid;
            $sale['return_amount']      = $returnAmount;
            $sale['return_credit_amount'] = $returnCredits;
            $sale['remaining_balance'] = $isReturned ? 0.0 : max(0, round($grandTotal - $actualPaid - $returnCredits, 2));
            $sale['dynamic_status'] = in_array(strtolower((string) $sale['status']), ['canceled', 'cancelled'], true)
                ? 'canceled'
                : $this->determineSaleStatus($grandTotal, $actualPaid, $returnAmount, $returnCredits, $sale['created_at'], $firstPaymentDate, $hasDirectReturn);

            return $this->successResponse($response, $sale, 200);
        } catch (\DomainException $e) {
            return $this->errorResponse($response, $e->getMessage(), (int) $e->getCode() ?: 403);
        } catch (\Throwable $e) {
            $this->logger->error('Sales get error', [
                'message' => $e->getMessage(),
                'sale_id' => $id ?? 'unknown'
            ]);

            return $this->errorResponse($response, 'Failed to retrieve sale details', 500);
        }
    }

    /**
     * Return sales that are pending approval.
     * Supports pagination and includes payment method resolution.
     *
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    public function pendingApprovals(Request $request, Response $response): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $branchId = $this->resolveSalesBranchScope($request, (int) $tenantId);
            [$page, $perPage, $offset] = PaginationHelper::fromRequest($request);

            $countSql = "
                SELECT COUNT(*)
                FROM sales
                WHERE tenant_id = ?
                  AND status = 'pending_approval'
            ";
            $countParams = [$tenantId];
            if ($branchId !== null) {
                $countSql .= " AND branch_id = ?";
                $countParams[] = $branchId;
            }
            $stmt = $this->db->prepare($countSql);
            $stmt->execute($countParams);
            $total = (int) $stmt->fetchColumn();

            $listSql = "
            SELECT
                s.*,
                c.name AS customer_name,
                u.name AS created_by_name,
                (
                    SELECT pm.name
                    FROM payment_methods pm
                    WHERE pm.id = COALESCE(
                        s.payment_method_id,
                        (
                            SELECT p.payment_method_id
                            FROM payments p
                            WHERE p.sale_id = s.id
                              AND p.tenant_id = ?
                              AND p.is_draft = 1
                            ORDER BY p.id DESC
                            LIMIT 1
                        )
                    )
                      AND pm.tenant_id = ?
                    LIMIT 1
                ) AS payment_method_name,
                (
                    SELECT pm.kind
                    FROM payment_methods pm
                    WHERE pm.id = COALESCE(
                        s.payment_method_id,
                        (
                            SELECT p.payment_method_id
                            FROM payments p
                            WHERE p.sale_id = s.id
                              AND p.tenant_id = ?
                              AND p.is_draft = 1
                            ORDER BY p.id DESC
                            LIMIT 1
                        )
                    )
                      AND pm.tenant_id = ?
                    LIMIT 1
                ) AS payment_method_kind
            FROM sales s
            LEFT JOIN customers c
                ON s.customer_id = c.id AND c.tenant_id = s.tenant_id
            LEFT JOIN users u
                ON s.user_id = u.id AND u.tenant_id = s.tenant_id
            WHERE s.tenant_id = ?
              AND s.status = 'pending_approval'
            ";
            $listParams = [$tenantId, $tenantId, $tenantId, $tenantId, $tenantId];
            if ($branchId !== null) {
                $listSql .= " AND s.branch_id = ?";
                $listParams[] = $branchId;
            }
            $listSql .= "
            ORDER BY s.created_at DESC
            LIMIT {$perPage} OFFSET {$offset}
            ";
            $stmt = $this->db->prepare($listSql);
            $stmt->execute($listParams);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            return $this->successResponse($response, [
                'items'      => $items,
                'total'      => $total,
                'pagination' => PaginationHelper::buildMeta($total, $page, $perPage),
            ], 200);
        } catch (\Throwable $e) {
            $this->logger->error('Pending approvals error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->errorResponse($response, 'Failed to retrieve pending approvals', 500);
        }
    }

    /**
     * Create a new sale (invoice).
     * Validates tenant context, applies default cost centers and delegates to service layer.
     *
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    public function create(Request $request, Response $response): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                $this->logger->warning('Sales create - missing tenant ID');
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $userId = $this->extractUserId($request) ?? null;
            $data = $request->getParsedBody() ?? [];
            $data['tenant_id'] = (int) $tenantId;

            $this->logger->info('Sales invoice creation request', [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'customer_id' => $data['customer_id'] ?? null,
                'branch_id' => $data['branch_id'] ?? null,
                'total_amount' => $data['total_amount'] ?? 0
            ]);

            $this->applyDefaultCostCenter($data, $request);

            $sessionsEnabled = $this->isSessionsEnabled((int) $tenantId);
            $sessionExempt = $this->isCashierSessionExempt($request);
            $data['cashier_session_exempt'] = $sessionExempt;
            $paidAmount = (float) ($data['paid_amount'] ?? 0);
            if ($paidAmount > 0 && !empty($data['payment_method_id'])
                && $this->isCashMethod((int) $data['payment_method_id'], (int) $tenantId)) {
                $branchId = !empty($data['branch_id']) ? (int) $data['branch_id'] : null;
                if ($sessionsEnabled && !$sessionExempt) {
                    if (!$branchId) {
                        return $this->errorResponse($response, 'يجب تحديد الفرع لفتح جلسة الكاشير قبل تسجيل البيع النقدي.', 400);
                    }
                    try {
                        $data['session_id'] = $this->requireOpenCashierSession((int) $tenantId, $branchId, $userId);
                    } catch (\Exception $e) {
                        return $this->errorResponse($response, $e->getMessage(), 400);
                    }
                } elseif ($branchId) {
                    $data['session_id'] = $this->findOpenCashierSession((int) $tenantId, $branchId, null);
                }
            }

            $svc    = $this->services->saleCreation((int) $tenantId, $userId);
            $result = $svc->createSale($data);

            $saleId = $result['sale_id'] ?? $result['id'] ?? null;

            $this->logger->info('Sales invoice created successfully', [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'sale_id' => $saleId,
                'invoice_number' => $result['invoice_number'] ?? null,
                'total_amount' => $result['total_amount'] ?? 0
            ]);

            return $this->successResponse($response, array_merge($result, [
                'message' => 'تم إنشاء الفاتورة بنجاح',
                'id'      => $saleId,
            ]), 201);
        } catch (\App\Exceptions\InsufficientStockException $e) {
            // Handle insufficient stock error with specific logging
            $this->logger->warning('Sales invoice creation failed - insufficient stock', [
                'product_id' => $e->productId,
                'available_qty' => $e->availableQty,
                'requested_qty' => $e->requestedQty,
                'tenant_id' => $tenantId ?? 'unknown',
                'user_id' => $userId ?? 'unknown'
            ]);

            $response = $response->withStatus(409)
                ->withHeader('Content-Type', 'application/json');
            $response->getBody()->write(json_encode(
                ApiResponseEnvelope::normalize($e->toArray(), 409),
                JSON_UNESCAPED_UNICODE
            ));
            return $response;
        } catch (\DomainException $e) {
            return $this->errorResponse($response, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->logger->error('Sales invoice creation failed', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'tenant_id' => $tenantId ?? 'unknown',
                'user_id' => $userId ?? 'unknown',
                'customer_id' => $data['customer_id'] ?? 'unknown'
            ]);

            return $this->errorResponse($response, 'فشل في إنشاء الفاتورة', 400);
        }
    }

    /**
     * Approve a sale invoice. Requires RBAC permission 'sales.approval.approve'.
     * Delegates to service layer and ensures COGS journal posting after approval.
     *
     * @param Request $request
     * @param Response $response
     * @param array $args expects ['id'] => sale_id
     * @return Response
     */
    public function approve(Request $request, Response $response, array $args = []): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $saleId = (int) ($args['id'] ?? 0);
            if ($saleId <= 0) {
                return $this->errorResponse($response, 'مطلوب رقم الفاتورة', 400);
            }

            $userId = $this->extractUserId($request) ?? null;

            if (!$userId || !$this->rbac || !$this->rbac->hasPermission($userId, 'sales.approval.approve')) {
                $this->logger->warning('Sales approve - permission denied', [
                    'tenant_id' => $tenantId,
                    'sale_id'   => $saleId,
                    'user_id'   => $userId,
                ]);
                return $this->errorResponse($response, 'ليس لديك صلاحية لاعتماد الفواتير', 403);
            }

            $body            = $request->getParsedBody() ?? [];
            $note            = $body['note'] ?? null;
            $paymentOverride = [];
            if (!empty($body['payment_method_id'])) {
                $paymentOverride['payment_method_id'] = (int) $body['payment_method_id'];
            }
            if (isset($body['paid_amount']) && $body['paid_amount'] !== '') {
                $paymentOverride['paid_amount'] = (float) $body['paid_amount'];
            }

            $sessionRequired = $this->isSessionsEnabled((int) $tenantId)
                && !$this->isCashierSessionExempt($request);

            $this->logger->info('Sales invoice approval request', [
                'tenant_id'        => $tenantId,
                'sale_id'          => $saleId,
                'user_id'          => $userId,
                'note'             => $note,
                'payment_override' => $paymentOverride,
            ]);

            // The sale journal posted by approveSale includes COGS; keep stock and both journal effects in its transaction.
            $svc = $this->services->saleApproval((int) $tenantId, $userId);

            try {
                $res = $svc->approveSale($tenantId, $saleId, $note, $paymentOverride, $sessionRequired);

                $this->logger->info('Sales invoice approved with COGS posted in the sale journal', [
                    'tenant_id' => $tenantId,
                    'sale_id'   => $saleId,
                    'user_id'   => $userId,
                ]);

            } catch (\Throwable $e) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }

                if ($e instanceof \DomainException) {
                    return $this->errorResponse($response, $e->getMessage(), 422);
                }

                $this->logger->error('Sales approval failed (COGS or approval error)', [
                    'message'   => $e->getMessage(),
                    'tenant_id' => $tenantId,
                    'sale_id'   => $saleId,
                    'user_id'   => $userId,
                ]);

                // Return environment-appropriate error message (verbose for dev, generic for production)
                $userMessage = (($_ENV['APP_ENV'] ?? 'production') === 'development')
                    ? 'فشل اعتماد الفاتورة: ' . $e->getMessage()
                    : 'فشل اعتماد الفاتورة. يُرجى التحقق من إعدادات الحسابات المحاسبية (COGS / المخزون) ثم المحاولة مجدداً.';

                return $this->errorResponse($response, $userMessage, 400);
            }

            return $this->successResponse($response, [
                'message' => 'تم اعتماد الفاتورة بنجاح',
                'data'    => $res,
            ], 200);

        } catch (\App\Exceptions\InsufficientStockException $e) {
            // Handle insufficient stock error on approval with specific logging
            $this->logger->warning('Sales approval failed - insufficient stock', [
                'product_id' => $e->productId,
                'available_qty' => $e->availableQty,
                'requested_qty' => $e->requestedQty,
                'tenant_id' => $tenantId ?? 'unknown',
                'sale_id' => $saleId ?? 'unknown',
                'user_id' => $userId ?? 'unknown'
            ]);

            $response = $response->withStatus(409)
                ->withHeader('Content-Type', 'application/json');
            $response->getBody()->write(json_encode(
                ApiResponseEnvelope::normalize($e->toArray(), 409),
                JSON_UNESCAPED_UNICODE
            ));
            return $response;
        } catch (\DomainException $e) {
            return $this->errorResponse($response, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->logger->error('Sales invoice approval failed (outer)', [
                'message'   => $e->getMessage(),
                'tenant_id' => $tenantId ?? 'unknown',
                'sale_id'   => $saleId   ?? 'unknown',
                'user_id'   => $userId   ?? 'unknown',
            ]);
            return $this->errorResponse($response, 'فشل في الموافقة على الفاتورة', 400);
        }
    }

    /**
     * Reject a sale invoice. Requires RBAC permission 'sales.approval.reject'.
     *
     * @param Request $request
     * @param Response $response
     * @param array $args expects ['id'] => sale_id
     * @return Response
     */
    public function reject(Request $request, Response $response, array $args = []): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                $this->logger->warning('Sales reject - missing tenant ID');
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $saleId = (int) ($args['id'] ?? 0);
            if ($saleId <= 0) {
                $this->logger->warning('Sales reject - missing sale ID', ['tenant_id' => $tenantId]);
                return $this->errorResponse($response, 'مطلوب رقم الفاتورة', 400);
            }

            $userId = $this->extractUserId($request) ?? null;

            if (!$userId || !$this->rbac || !$this->rbac->hasPermission($userId, 'sales.approval.reject')) {
                $this->logger->warning('Sales reject - permission denied', [
                    'tenant_id' => $tenantId,
                    'sale_id'   => $saleId,
                    'user_id'   => $userId
                ]);

                return $this->errorResponse($response, 'ليس لديك صلاحية لرفض الفواتير', 403);
            }

            $body = $request->getParsedBody() ?? [];
            $note = $body['note'] ?? null;

            $this->logger->info('Sales invoice rejection request', [
                'tenant_id' => $tenantId,
                'sale_id' => $saleId,
                'user_id' => $userId,
                'note' => $note
            ]);

            $svc = $this->services->saleApproval((int) $tenantId, $userId);
            $res = $svc->rejectSale($tenantId, $saleId, $note);

            $this->logger->info('Sales invoice rejected successfully', [
                'tenant_id' => $tenantId,
                'sale_id' => $saleId,
                'user_id' => $userId
            ]);

            return $this->successResponse($response, [
                'message' => 'تم رفض الفاتورة بنجاح',
                'data' => $res
            ], 200);
        } catch (\Throwable $e) {
            $this->logger->error('Sales invoice rejection failed', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'tenant_id' => $tenantId ?? 'unknown',
                'sale_id' => $saleId ?? 'unknown',
                'user_id' => $userId ?? 'unknown'
            ]);

            return $this->errorResponse($response, 'فشل في رفض الفاتورة', 400);
        }
    }

    public function approveSale(Request $request, Response $response, array $args = []): Response
    {
        return $this->approve($request, $response, $args);
    }

    public function rejectSale(Request $request, Response $response, array $args = []): Response
    {
        return $this->reject($request, $response, $args);
    }

    /**
     * Update sale invoice data (partial updates supported).
     * Delegates validation and business rules to service layer.
     *
     * @param Request $request
     * @param Response $response
     * @param array $args expects ['id'] => sale_id
     * @return Response
     */
    public function update(Request $request, Response $response, array $args = []): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                $this->logger->warning('Sales update - missing tenant ID');
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $saleId = (int) ($args['id'] ?? 0);
            if ($saleId <= 0) {
                $this->logger->warning('Sales update - missing sale ID', ['tenant_id' => $tenantId]);
                return $this->errorResponse($response, 'Sale ID is required', 400);
            }

            $userId = $this->extractUserId($request) ?? null;
            $data = $request->getParsedBody() ?? [];

            $this->logger->info('Sales invoice update request', [
                'tenant_id' => $tenantId,
                'sale_id' => $saleId,
                'user_id' => $userId,
                'update_fields' => array_keys($data)
            ]);

            $this->applyDefaultCostCenter($data, $request);

            $svc = $this->services->sales((int) $tenantId, $userId);
            $res = $svc->updateSale($tenantId, $saleId, $data);

            $this->logger->info('Sales invoice updated successfully', [
                'tenant_id' => $tenantId,
                'sale_id' => $saleId,
                'user_id' => $userId
            ]);

            return $this->successResponse($response, [
                'message' => 'تم تحديث الفاتورة بنجاح',
                'data' => $res
            ], 200);
        } catch (\Throwable $e) {
            $this->logger->error('Sales invoice update failed', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'tenant_id' => $tenantId ?? 'unknown',
                'sale_id' => $saleId ?? 'unknown',
                'user_id' => $userId ?? 'unknown'
            ]);

            if ($e instanceof \DomainException) {
                return $this->errorResponse($response, $e->getMessage(), 422);
            }
            return $this->errorResponse($response, 'فشل في تحديث حالة الفاتورة', 400);
        }
    }

    /**
     * Delete (cancel) a sale invoice. Performs RBAC checks and delegates
     * business logic to service layer.
     *
     * @param Request $request
     * @param Response $response
     * @param array $args expects ['id'] => sale_id
     * @return Response
     */
    public function delete(Request $request, Response $response, array $args = []): Response
    {
        $tenantId = null;
        $saleId = null;
        $userId = null;

        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                $this->logger->warning('Sales delete - missing tenant ID');
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $saleId = (int) ($args['id'] ?? 0);
            if ($saleId <= 0) {
                $this->logger->warning('Sales delete - missing sale ID', [
                    'tenant_id' => $tenantId
                ]);

                return $this->errorResponse($response, 'Sale ID is required', 400);
            }

            $userId = $this->extractUserId($request) ?? null;
            $body = $request->getParsedBody() ?? [];
            $note = $body['note'] ?? null;

            $this->logger->info('Sales invoice deletion request', [
                'tenant_id' => $tenantId,
                'sale_id' => $saleId,
                'user_id' => $userId,
                'note' => $note
            ]);

            $svc = $this->services->sales((int) $tenantId, $userId);
            $res = $svc->deleteSale($tenantId, $saleId, $note);

            $this->logger->info('Sales invoice deleted successfully', [
                'tenant_id' => $tenantId,
                'sale_id' => $saleId,
                'user_id' => $userId
            ]);

            return $this->successResponse($response, [
                'message' => 'تم إلغاء الفاتورة بنجاح',
                'data' => $res
            ], 200);
        } catch (\Throwable $e) {
            $this->logger->error('Sales invoice deletion failed', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'tenant_id' => $tenantId ?? 'unknown',
                'sale_id' => $saleId ?? 'unknown',
                'user_id' => $userId ?? 'unknown'
            ]);

            if ($e instanceof \DomainException) {
                return $this->errorResponse($response, $e->getMessage(), 422);
            }
            return $this->errorResponse($response, 'فشل في حذف الفاتورة', 400);
        }
    }

    /**
     * Pay outstanding customer debt across multiple invoices.
     * Supports idempotency via `Idempotency-Key` header and validates
     * outstanding balance with a FOR UPDATE lock.
     *
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    public function payDebt(Request $request, Response $response): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            $userId   = $this->extractUserId($request);
            $data     = $request->getParsedBody() ?? [];

            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $customerId      = isset($data['customer_id']) ? (int) $data['customer_id'] : null;
            $rawAmount       = $data['amount'] ?? null;
            $paymentMethodId = isset($data['payment_method_id']) ? (int) $data['payment_method_id'] : null;
            $paymentDate     = $data['payment_date'] ?? null;
            $branchId        = isset($data['branch_id']) ? (int) $data['branch_id'] : null;

            if (
                !$customerId
                || !is_scalar($rawAmount)
                || !is_numeric($rawAmount)
                || !$paymentMethodId
                || ($paymentDate !== null && !is_scalar($paymentDate))
            ) {
                return $this->errorResponse($response, 'customer_id و amount و payment_method_id مطلوبة', 400);
            }
            $amount = (float) $rawAmount;
            if (!is_finite($amount) || $amount <= 0) {
                return $this->errorResponse($response, 'مبلغ الدفعة يجب أن يكون رقمًا أكبر من صفر.', 400);
            }

            $idemKey = trim($request->getHeaderLine('Idempotency-Key'));
            if (strlen($idemKey) > 128) {
                return $this->errorResponse($response, 'مفتاح منع التكرار أطول من الحد المسموح.', 400);
            }

            $amount = round($amount, 2);
            if ($amount <= 0) {
                return $this->errorResponse($response, 'مبلغ الدفعة يجب أن يكون أكبر من صفر بعد التقريب.', 400);
            }
            $paymentDateFingerprint = isset($data['payment_date'])
                ? (string) $data['payment_date']
                : null;
            $fingerprintJson = json_encode([
                'customer_id' => $customerId,
                'amount' => $amount,
                'payment_method_id' => $paymentMethodId,
                'payment_date' => $paymentDateFingerprint,
                'branch_id' => $branchId,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($fingerprintJson === false) {
                return $this->errorResponse($response, 'بيانات الدفعة غير صالحة.', 400);
            }
            $requestFingerprint = hash('sha256', $fingerprintJson);
            $idem = $idemKey !== '' ? new \App\Services\IdempotencyService($this->db) : null;

            $this->db->beginTransaction();
            try {
                $forUpdate = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
                $customerLock = $this->db->prepare(
                    "SELECT id FROM customers WHERE id = ? AND tenant_id = ? LIMIT 1{$forUpdate}"
                );
                $customerLock->execute([$customerId, $tenantId]);
                if (!$customerLock->fetchColumn()) {
                    throw new \DomainException('لم يتم العثور على العميل.', 404);
                }

                if ($idem !== null) {
                    $cached = $idem->claimStrict((int) $tenantId, $idemKey, $requestFingerprint);
                    if ($cached !== null) {
                        $cachedFingerprint = $cached['_request_fingerprint'] ?? null;
                        $matches = is_string($cachedFingerprint)
                            && hash_equals($requestFingerprint, $cachedFingerprint)
                            && empty($cached['_processing']);
                        $this->db->commit();
                        if (!$matches) {
                            return $this->errorResponse(
                                $response,
                                'مفتاح منع التكرار مستخدم لبيانات دفعة مختلفة.',
                                409
                            );
                        }

                        unset($cached['_request_fingerprint']);
                        return $this->jsonResponse($response, $cached, 200);
                    }
                }

                $sessionsEnabled = $this->isSessionsEnabled((int) $tenantId);
                $sessionExempt = $this->isCashierSessionExempt($request);
                $sessionRequired = $sessionsEnabled && !$sessionExempt;
                $sessionId = null;
                if ($this->isCashMethod($paymentMethodId, (int) $tenantId)) {
                    if ($sessionRequired && !$branchId) {
                        throw new \DomainException(
                            'يجب تحديد الفرع لفتح جلسة الكاشير قبل تسجيل التحصيل النقدي.',
                            400
                        );
                    }
                    if ($branchId) {
                        $sessionId = $sessionRequired
                            ? $this->requireOpenCashierSession((int) $tenantId, $branchId, $userId)
                            : $this->findOpenCashierSession((int) $tenantId, $branchId, null);
                    }
                }

                $svc = $this->services->salePayment($userId);
                $result = $svc->payCustomerDebt(
                    (int) $tenantId,
                    $customerId,
                    $amount,
                    $paymentMethodId,
                    $paymentDate !== null ? (string) $paymentDate : null,
                    $branchId,
                    $sessionId,
                    $sessionRequired,
                    $idemKey !== '' ? $idemKey : null
                );

                $responseData = [
                    'status'  => 'success',
                    'message' => 'تم تسجيل الدفعة بنجاح',
                    'data'    => $result,
                ];

                if ($idem !== null) {
                    $cachedResponse = $responseData;
                    $cachedResponse['_request_fingerprint'] = $requestFingerprint;
                    $idem->storeStrict(
                        (int) $tenantId,
                        $idemKey,
                        (int) ($result['payment_id'] ?? 0),
                        $cachedResponse
                    );
                }

                $this->db->commit();
                return $this->jsonResponse($response, $responseData, 200);
            } catch (\Throwable $e) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                throw $e;
            }
        } catch (\DomainException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $statusCode = in_array((int) $e->getCode(), [400, 404, 422], true)
                ? (int) $e->getCode()
                : 422;
            return $this->errorResponse($response, $e->getMessage(), $statusCode);
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logger->error('Pay customer debt failed', [
                'message'     => $e->getMessage(),
                'tenant_id'   => $tenantId ?? 'unknown',
                'user_id'     => $userId   ?? 'unknown',
                'customer_id' => $customerId ?? 'unknown',
            ]);
            return $this->errorResponse($response, 'فشل في تسجيل الدفعة: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Add a payment to a specific sale (invoice).
     * Validates the payment amount against remaining balance and delegates
     * to payment service which creates payment and accounting journal.
     *
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    public function addSalesPayment(Request $request, Response $response): Response
    {
        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
        }

        try {
            $data = $this->extractAndValidateRequestData($request, [
                'sale_id',
                'amount',
                'payment_date',
                'payment_method_id'
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($response, $e->getMessage(), 400);
        }

        // A payment request must have a stable client key so network retries can
        // never be confused with a second, legitimate receipt.
        $idemKey = trim($request->getHeaderLine('Idempotency-Key'));
        if ($idemKey === '') {
            return $this->errorResponse($response, 'مفتاح منع التكرار (Idempotency-Key) مطلوب لتسجيل الدفعة.', 400);
        }
        if (strlen($idemKey) > 128) {
            return $this->errorResponse($response, 'مفتاح منع التكرار أطول من الحد المسموح.', 400);
        }

        $paymentRequestPayload = json_encode([
            'sale_id' => (int) $data['sale_id'],
            'amount' => round((float) $data['amount'], 2),
            'payment_date' => (string) $data['payment_date'],
            'payment_method_id' => (int) $data['payment_method_id'],
            'customer_id' => isset($data['customer_id']) ? (int) $data['customer_id'] : null,
            'cost_center_id' => isset($data['cost_center_id']) ? (int) $data['cost_center_id'] : null,
            'reference_number' => $data['reference_number'] ?? null,
            'notes' => (string) ($data['notes'] ?? ''),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($paymentRequestPayload === false) {
            return $this->errorResponse($response, 'بيانات الدفعة غير صالحة.', 400);
        }
        $paymentRequestFingerprint = hash('sha256', $paymentRequestPayload);
        $idem = new \App\Services\IdempotencyService($this->db);
        $cached = $idem->checkStrict((int) $tenantId, $idemKey);
        if ($cached !== null) {
            if (!hash_equals($paymentRequestFingerprint, (string) ($cached['_request_fingerprint'] ?? ''))) {
                return $this->errorResponse($response, 'مفتاح منع التكرار مستخدم لبيانات دفعة مختلفة.', 409);
            }
            unset($cached['_request_fingerprint']);
            $this->logger->info('addSalesPayment: returning cached idempotent response', [
                'tenant_id'       => $tenantId,
                'idempotency_key' => $idemKey,
            ]);
            return $this->jsonResponse($response, $cached, 200);
        }

        try {
            $userId = $this->extractUserId($request) ?? null;

            // Verify sale exists and validate payment amount against remaining balance
            $stmt = $this->db->prepare("
            SELECT branch_id, customer_id, status,
                   ROUND(net_total_amount + IFNULL(tax_amount, 0), 2) AS grand_total,
                   ROUND(IFNULL(paid_amount, 0), 2) AS paid_amount
            FROM sales
            WHERE id = ? AND tenant_id = ?
        ");
            $stmt->execute([$data['sale_id'], $tenantId]);
            $saleRow = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$saleRow) {
                return $this->errorResponse($response, 'لم يتم العثور على الفاتورة', 400);
            }

            $branchScope = $this->resolveSalesBranchScope($request, (int) $tenantId);
            if ($branchScope !== null && (int) ($saleRow['branch_id'] ?? 0) !== $branchScope) {
                return $this->errorResponse($response, 'لا تملك صلاحية التحصيل على فاتورة من فرع آخر.', 403);
            }

            try {
                \App\Security\SaleWorkflowPolicy::assertPaymentAllowed((string) ($saleRow['status'] ?? ''));
            } catch (\DomainException $e) {
                return $this->errorResponse($response, $e->getMessage(), 422);
            }

            $paymentAmt    = round((float) $data['amount'], 2);

            // Calculate return_credit_allocations to prevent double-paying settled invoices
            // Example: Invoice=1000, paid_amount=0, return_credits=1000 → saleRemaining should be 0, not 1000
            $stmtCredits = $this->db->prepare(
                "SELECT COALESCE(SUM(allocated_amount), 0) AS return_credits
             FROM return_credit_allocations
             WHERE sale_id = ? AND tenant_id = ?"
            );
            $stmtCredits->execute([$data['sale_id'], $tenantId]);
            $returnCredits = round((float) $stmtCredits->fetchColumn(), 2);

            $saleRemaining = max(0, round(
                (float) $saleRow['grand_total'] - (float) $saleRow['paid_amount'] - $returnCredits,
                2
            ));

            if ($paymentAmt > $saleRemaining + 0.01) {
                return $this->errorResponse($response, "الدفعة ({$paymentAmt}) تتجاوز المبلغ المتبقي ({$saleRemaining})", 400);
            }
            $data['amount'] = min($paymentAmt, $saleRemaining);

            $branchId = $saleRow['branch_id'] ?? null;

            $sessionsEnabled = $this->isSessionsEnabled((int) $tenantId);
            $sessionExempt = $this->isCashierSessionExempt($request);
            $sessionRequired = $sessionsEnabled && !$sessionExempt;
            $sessionId = null;
            if ($this->isCashMethod((int) $data['payment_method_id'], (int) $tenantId)) {
                if ($sessionRequired && !$branchId) {
                    return $this->errorResponse($response, 'يجب تحديد فرع الفاتورة لفتح جلسة الكاشير قبل تسجيل الدفعة النقدية.', 400);
                }
                if ($branchId) {
                    try {
                        $sessionId = $sessionRequired
                            ? $this->requireOpenCashierSession((int) $tenantId, (int) $branchId, $userId)
                            : $this->findOpenCashierSession((int) $tenantId, (int) $branchId, null);
                    } catch (\Exception $e) {
                        return $this->errorResponse($response, $e->getMessage(), 400);
                    }
                }
            }

            // Delegate to service layer for payment creation and accounting entries
            $svc    = $this->services->salePayment($userId);
            $this->db->beginTransaction();
            try {
                if (!empty($saleRow['customer_id'])) {
                    $forUpdate = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
                        ? ' FOR UPDATE'
                        : '';
                    $customerLock = $this->db->prepare(
                        "SELECT id FROM customers WHERE id = ? AND tenant_id = ? LIMIT 1{$forUpdate}"
                    );
                    $customerLock->execute([(int) $saleRow['customer_id'], (int) $tenantId]);
                    if (!$customerLock->fetchColumn()) {
                        throw new \DomainException('لم يتم العثور على العميل المرتبط بالفاتورة.', 404);
                    }
                }

                $cached = $idem->claimStrict(
                    (int) $tenantId,
                    $idemKey,
                    $paymentRequestFingerprint
                );
                if ($cached !== null) {
                    $cachedFingerprint = $cached['_request_fingerprint'] ?? null;
                    $matches = is_string($cachedFingerprint)
                        && hash_equals($paymentRequestFingerprint, $cachedFingerprint)
                        && empty($cached['_processing']);
                    $this->db->commit();
                    if (!$matches) {
                        return $this->errorResponse(
                            $response,
                            'مفتاح منع التكرار مستخدم لبيانات دفعة مختلفة.',
                            409
                        );
                    }

                    unset($cached['_request_fingerprint']);
                    return $this->jsonResponse($response, $cached, 200);
                }

                $result = $svc->addSalePayment(
                    (int) $tenantId,
                    (int) $data['sale_id'],
                    (float) $data['amount'],
                    (string) $data['payment_date'],
                    (int) $data['payment_method_id'],
                    isset($data['customer_id']) ? (int) $data['customer_id'] : null,
                    $branchId ? (int) $branchId : null,
                    isset($data['cost_center_id']) ? (int) $data['cost_center_id'] : null,
                    $idemKey,
                    $sessionId,
                    $sessionRequired,
                    isset($data['reference_number']) ? (string) $data['reference_number'] : null,
                    isset($data['notes']) ? (string) $data['notes'] : null
                );

                $responseData = [
                    'status'  => 'success',
                    'message' => 'تم تسجيل الدفعة بنجاح',
                    'data'    => [
                        'payment_id'       => $result['payment_id'],
                        'journal_entry_id' => $result['journal_entry_id'] ?? null,
                        'amount'           => $data['amount'],
                        'payment_date'     => $data['payment_date'],
                        'reference_number' => $data['reference_number'] ?? null,
                        'notes'            => $data['notes'] ?? null,
                    ],
                ];

                $cachedResponse = $responseData;
                $cachedResponse['_request_fingerprint'] = $paymentRequestFingerprint;
                $idem->storeStrict(
                    (int) $tenantId,
                    $idemKey,
                    (int) ($result['payment_id'] ?? 0),
                    $cachedResponse
                );
                $this->db->commit();
            } catch (\Throwable $e) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                throw $e;
            }

            return $this->jsonResponse($response, $responseData, 201);
        } catch (\DomainException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $statusCode = in_array((int) $e->getCode(), [400, 403], true)
                ? (int) $e->getCode()
                : 422;
            return $this->errorResponse($response, $e->getMessage(), $statusCode);
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logger->error('Add sales payment error', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
            return $this->errorResponse($response, 'فشل في إضافة الدفع', 400);
        }
    }
}
