<?php

declare(strict_types=1);

namespace App\Handlers;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Services\MonologHandler;
use App\Services\LabelService;
use App\Utils\PaginationHelper;
use App\Utils\SuperAdminHelper;

/**
 * Lists tenant payment records and customer receipts.
 *
 * Uses PDO for filtered, paginated payment queries, PaginationHelper for page
 * bounds, and LabelService for localized reference/status labels. The payment
 * routes apply financial/report permissions; the general list also enforces
 * branch scope for non-exempt users.
 */
class PaymentsHandler extends BaseHandler
{
    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->logger = MonologHandler::getInstance('payments');
    }

    /** Return paginated payment records with optional filters and display labels. */
    public function list(Request $request, Response $response): Response
    {
        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
        }

        try {
            $qp = $request->getQueryParams();

            $customerId = $qp['customer_id'] ?? $qp['contact_id'] ?? null;
            $supplierId = $qp['supplier_id'] ?? null;
            $saleId = $qp['sale_id'] ?? null;
            $purchaseId = $qp['purchase_id'] ?? null;
            $type = $qp['type'] ?? null;
            $status = $qp['status'] ?? ($qp['status_code'] ?? null);
            $paymentMethodId = $qp['payment_method_id'] ?? null;
            $amount = isset($qp['amount']) ? (float) $qp['amount'] : null;
            $amountMin = isset($qp['amount_min']) ? (float) $qp['amount_min'] : null;
            $amountMax = isset($qp['amount_max']) ? (float) $qp['amount_max'] : null;
            $dateFrom = $qp['date_from'] ?? ($qp['start_date'] ?? null);
            $dateTo = $qp['date_to'] ?? ($qp['end_date'] ?? null);
            [$page, $perPage, $offset] = PaginationHelper::fromArray($qp, 50);
            $createdBy = $qp['created_by'] ?? ($qp['created_by_id'] ?? null);
            $costCenterId = $qp['cost_center_id'] ?? null;
            $branchId = isset($qp['branch_id']) && $qp['branch_id'] !== ''
                ? filter_var($qp['branch_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
                : null;
            if ($branchId === false) {
                return $this->errorResponse($response, 'معرف الفرع غير صالح.', 400);
            }

            $requestUser = $request->getAttribute('user');
            $requestUser = is_array($requestUser) ? $requestUser : null;
            $isBranchExempt = SuperAdminHelper::isAdminOrAbove($requestUser)
                || strtolower((string) ($requestUser['role'] ?? '')) === 'manager';
            if (!$isBranchExempt) {
                $userBranchId = (int) ($requestUser['branch_id'] ?? 0);
                if ($userBranchId <= 0) {
                    return $this->errorResponse($response, 'لم يتم تعيين فرع لحسابك.', 403);
                }
                if ($branchId !== null && (int) $branchId !== $userBranchId) {
                    return $this->errorResponse($response, 'غير مصرح لك بعرض بيانات هذا الفرع.', 403);
                }
                $branchId = $userBranchId;
            }

            $where = ["p.tenant_id = :tenant_id"];
            $params = [':tenant_id' => (int) $tenantId];
            // Zero-value return adjustments are not cash movements; the return itself
            // remains available in the returns history.
            $where[] = "(COALESCE(p.type, '') <> 'return_payment' OR p.amount > 0)";

            if ($branchId !== null) {
                $branchStmt = $this->db->prepare('SELECT id FROM branches WHERE id = ? AND tenant_id = ? LIMIT 1');
                $branchStmt->execute([(int) $branchId, (int) $tenantId]);
                if (!$branchStmt->fetchColumn()) {
                    return $this->errorResponse($response, 'الفرع غير موجود.', 404);
                }
                $where[] = '(
                    p.branch_id = :payment_branch_id
                    OR EXISTS (
                        SELECT 1 FROM sales branch_sale
                        WHERE branch_sale.id = p.sale_id
                          AND branch_sale.tenant_id = p.tenant_id
                          AND branch_sale.branch_id = :sale_branch_id
                    )
                    OR EXISTS (
                        SELECT 1 FROM purchases branch_purchase
                        WHERE branch_purchase.id = p.purchase_id
                          AND branch_purchase.tenant_id = p.tenant_id
                          AND branch_purchase.branch_id = :purchase_branch_id
                    )
                    OR EXISTS (
                        SELECT 1 FROM returns branch_return
                        WHERE branch_return.id = p.return_id
                          AND branch_return.tenant_id = p.tenant_id
                          AND branch_return.branch_id = :return_branch_id
                    )
                )';
                $params[':payment_branch_id'] = (int) $branchId;
                $params[':sale_branch_id'] = (int) $branchId;
                $params[':purchase_branch_id'] = (int) $branchId;
                $params[':return_branch_id'] = (int) $branchId;
            }

            if (!empty($costCenterId)) {
                $where[] = 'p.cost_center_id = :cost_center_id';
                $params[':cost_center_id'] = (int) $costCenterId;
            }

            if (!empty($status)) {
                $where[] = 'p.status = :status';
                $params[':status'] = (string) $status;
            } else {
                $where[] = "p.status = 'completed'";
            }

            if (!empty($customerId)) {
                $where[] = 'p.customer_id = :customer_id';
                $params[':customer_id'] = (int) $customerId;
            }

            if (!empty($supplierId)) {
                $where[] = 'p.supplier_id = :supplier_id';
                $params[':supplier_id'] = (int) $supplierId;
            }

            if (!empty($saleId)) {
                $where[] = 'p.sale_id = :sale_id';
                $params[':sale_id'] = (int) $saleId;
            }

            if (!empty($purchaseId)) {
                $where[] = 'p.purchase_id = :purchase_id';
                $params[':purchase_id'] = (int) $purchaseId;
            }

            if (!empty($dateFrom)) {
                $where[] = 'p.payment_date >= :date_from';
                $params[':date_from'] = $dateFrom . ' 00:00:00';
            }

            if (!empty($dateTo)) {
                $nextDay = date('Y-m-d', strtotime($dateTo . ' +1 day'));
                $where[] = 'p.payment_date < :date_to';
                $params[':date_to'] = $nextDay . ' 00:00:00';
            }

            if (!empty($paymentMethodId)) {
                $where[] = 'p.payment_method_id = :pm_id';
                $params[':pm_id'] = (int) $paymentMethodId;
            }

            if ($amount !== null) {
                $where[] = 'p.amount = :amount';
                $params[':amount'] = $amount;
            }

            if ($amountMin !== null) {
                $where[] = 'p.amount >= :amount_min';
                $params[':amount_min'] = $amountMin;
            }

            if ($amountMax !== null) {
                $where[] = 'p.amount <= :amount_max';
                $params[':amount_max'] = $amountMax;
            }

            if (!empty($createdBy)) {
                $where[] = 'p.created_by = :created_by';
                $params[':created_by'] = (int) $createdBy;
            }

            if (!empty($type)) {
                $t = strtolower((string) $type);
                if ($t === 'receipt') {
                    $where[] = "p.customer_id IS NOT NULL AND p.return_id IS NULL AND COALESCE(p.type, '') <> 'return_payment'";
                } elseif ($t === 'payment') {
                    $where[] = 'p.supplier_id IS NOT NULL AND p.return_id IS NULL';
                }
            }

            $whereSql = ' WHERE ' . implode(' AND ', $where) . ' ';

            $countSql = 'SELECT COUNT(*) FROM payments p' . $whereSql;
            $stmt = $this->db->prepare($countSql);
            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v);
            }
            $stmt->execute();
            $total = (int) $stmt->fetchColumn();

            $sql = "
                SELECT
                    p.*,
                    pm.name AS payment_method_name,
                    u.name AS created_by_name,
                    c.name AS customer_name,
                    s.name AS supplier_name
                FROM payments p
                LEFT JOIN payment_methods pm
                    ON pm.id = p.payment_method_id
                   AND pm.tenant_id = p.tenant_id
                LEFT JOIN users u ON u.id = p.created_by
                LEFT JOIN customers c
                    ON c.id = p.customer_id
                   AND c.tenant_id = p.tenant_id
                LEFT JOIN suppliers s
                    ON s.id = p.supplier_id
                   AND s.tenant_id = p.tenant_id
                {$whereSql}
                ORDER BY p.payment_date DESC, p.id DESC
                LIMIT :limit OFFSET :offset
            ";

            $stmt = $this->db->prepare($sql);
            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v);
            }
            $stmt->bindValue(':limit', (int) $perPage, PDO::PARAM_INT);
            $stmt->bindValue(':offset', (int) $offset, PDO::PARAM_INT);
            $stmt->execute();
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $acceptLang = $request->getHeaderLine('Accept-Language');
            $locale = (stripos($acceptLang, 'ar') === 0) ? 'ar' : 'en';

            foreach ($items as &$p) {
                $paymentType = strtolower((string) ($p['type'] ?? ''));
                $isReturnSettlement = !empty($p['return_id']) || $paymentType === 'return_payment';
                $isRefund = $paymentType === 'return_payment'
                    && (float) ($p['amount'] ?? 0) > 0;
                if ($isReturnSettlement) {
                    $refType = $isRefund ? 'return_payment' : 'sales_return';
                    $p['reference'] = 'return#' . ($p['return_id'] ?? $p['id'] ?? '');
                } elseif ($paymentType === 'refund') {
                    $refType = 'refund';
                    $p['reference'] = 'refund#' . ($p['id'] ?? '');
                } else {
                    $isReceipt = !empty($p['customer_id']) || !empty($p['sale_id']);
                    $refType = $isReceipt ? 'receipt' : 'payment';
                    $p['reference'] = $refType . '#' . ($p['id'] ?? '');
                }

                $p['reference_type'] = $refType;
                $p['reference_id'] = $isReturnSettlement
                    ? ($p['return_id'] ?? null)
                    : ($p['id'] ?? null);
                $p['reference_label'] = $this->refLabel($refType, $locale);
                $p['status_code'] = $p['status'] ?? 'completed';
                $p['status_label'] = $this->statusLabel($p['status_code'], $locale);
                $p['cost_center_id'] = $p['cost_center_id'] ?? null;
            }
            unset($p);

            return $this->successResponse($response, [
                'items' => $items,
                'meta' => [
                    'total' => $total,
                    'page' => $page,
                    'per_page' => $perPage
                ]
            ], 200);
        } catch (\Throwable $e) {
            $this->logger->error('Error listing payments', [
                'error' => $e->getMessage(),
                'tenant_id' => $tenantId
            ]);

            return $this->errorResponse($response, 'حدث خطأ أثناء جلب السندات', 500);
        }
    }

    /** Resolve a localized label for a payment reference type. */
    private function refLabel(?string $type, string $locale = 'ar'): string
    {
        return LabelService::refLabel($type, $locale);
    }

    /** Resolve a localized label for a payment status. */
    private function statusLabel(?string $code, string $locale = 'ar'): string
    {
        return LabelService::statusLabel($code, $locale);
    }

    /** Return completed receipts for one customer within an optional date range. */
    public function listReceipts(Request $request, Response $response): Response
    {
        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
        }

        try {
            $qp = $request->getQueryParams();
            $customerId = $qp['customer_id'] ?? $qp['contact_id'] ?? $qp['party_id'] ?? null;

            if (!$customerId) {
                return $this->successResponse($response, [], 200);
            }

            [$page, $perPage, $offset] = PaginationHelper::fromArray($qp, 50);

            $dateFrom = $qp['date_from'] ?? ($qp['start_date'] ?? null);
            $dateTo   = $qp['date_to']   ?? ($qp['end_date']   ?? null);

            $where = [
                "p.tenant_id = :tenant_id",
                "p.status = 'completed'",
                'p.customer_id = :customer_id'
            ];
            $params = [
                ':tenant_id' => (int) $tenantId,
                ':customer_id' => (int) $customerId
            ];

            if (!empty($dateFrom)) {
                $where[] = 'DATE(p.payment_date) >= :date_from';
                $params[':date_from'] = $dateFrom;
            }

            if (!empty($dateTo)) {
                $where[] = 'DATE(p.payment_date) <= :date_to';
                $params[':date_to'] = $dateTo;
            }

            $whereSql = ' WHERE ' . implode(' AND ', $where) . ' ';

            $countSql = 'SELECT COUNT(*) FROM payments p' . $whereSql;
            $stmt = $this->db->prepare($countSql);
            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v);
            }
            $stmt->execute();
            $total = (int) $stmt->fetchColumn();

            $sql = "
                SELECT
                    p.*,
                    pm.name AS payment_method_name,
                    u.name AS created_by_name
                FROM payments p
                LEFT JOIN payment_methods pm
                    ON pm.id = p.payment_method_id
                   AND pm.tenant_id = p.tenant_id
                LEFT JOIN users u ON u.id = p.created_by
                {$whereSql}
                ORDER BY p.payment_date ASC, p.id ASC
                LIMIT :limit OFFSET :offset
            ";

            $stmt = $this->db->prepare($sql);
            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v);
            }
            $stmt->bindValue(':limit', (int) $perPage, PDO::PARAM_INT);
            $stmt->bindValue(':offset', (int) $offset, PDO::PARAM_INT);
            $stmt->execute();
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            return $this->successResponse($response, [
                'items' => $items,
                'meta' => [
                    'total' => $total,
                    'page' => $page,
                    'per_page' => $perPage
                ]
            ], 200);
        } catch (\Throwable $e) {
            $this->logger->error('Error listing receipts', [
                'error' => $e->getMessage(),
                'tenant_id' => $tenantId
            ]);

            return $this->errorResponse($response, 'حدث خطأ أثناء جلب سندات القبض', 500);
        }
    }
}
