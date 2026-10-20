<?php

declare(strict_types=1);

namespace App\Handlers;

use PDO;
use PDOException;
use Throwable;
use Exception;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Services\MonologHandler;
use App\Services\FinancialCalculationService;
use App\Services\LabelService;
use App\Services\LocaleService;
use App\Services\PurchaseReturnSettlementPolicy;
use App\Utils\PaginationHelper;
use App\Utils\DateHelper;

/**
 * Handles tenant-scoped purchase invoices and supplier payments.
 *
 * The handler validates request data, builds invoice totals, and coordinates
 * persistence through purchase services. It also shapes localized list/detail
 * responses and applies cashier-session and idempotency rules for payments.
 */
class PurchasesHandler extends BaseHandler
{
    private FinancialCalculationService $financialCalcService;

    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->logger = MonologHandler::getInstance('purchases');
        $this->financialCalcService = new FinancialCalculationService($db);
    }

    private function getLocale(Request $request): string
    {
        return LocaleService::fromRequest($request);
    }

    private function refLabel(?string $type, string $locale = 'ar'): string
    {
        return LabelService::refLabel($type, $locale);
    }

    private function statusLabel(?string $code, string $locale = 'ar'): string
    {
        return LabelService::statusLabel($code, $locale);
    }

    private function normalizeDateTime(?string $value, bool $endOfDay = false): string
    {
        return DateHelper::normalize($value, $endOfDay);
    }

    private function determinePurchaseStatus(float $netTotal, float $paidAmount, float $returnAmount = 0.0, ?string $createdAt = null, ?string $firstPaymentDate = null): string
    {
        if ($netTotal <= 0) {
            return 'paid';
        }
        if ($returnAmount >= $netTotal) {
            return 'returned';
        }
        if ($paidAmount <= 0) {
            return 'due';
        }

        // Returns reduce the outstanding amount alongside recorded payments.
        $totalSettled = round($paidAmount + $returnAmount, 2);
        $isFullySettled = $totalSettled >= $netTotal - 0.01;

        // Full settlement via cash payment (possibly combined with return)
        if ($isFullySettled) {
            if ($createdAt && $firstPaymentDate) {
                $createdDate = (new \DateTime($createdAt))->format('Y-m-d');
                $paymentDate = (new \DateTime($firstPaymentDate))->format('Y-m-d');
                if ($createdDate === $paymentDate) {
                    return 'paid';
                } else {
                    return 'settled';
                }
            }
            return 'paid';
        }

        return 'partial';
    }

    private function calculatePurchaseTotals(array $data): array
    {
        $items = $data['items'] ?? [];
        if (!is_array($items) || count($items) === 0) {
            throw new Exception('يجب إضافة أصناف للفاتورة.');
        }

        $normalizedItems = [];
        $grossTotal = 0.0;

        foreach ($items as $item) {
            $item = array_merge([
                'unit_id' => 1,
                'price' => 0,
                'cost' => 0,
                'discount_amount' => 0,
                'tax_rate' => 0,
                'batch_number' => null,
                'expiry_date' => null,
                'serial' => null,
                'category_id' => null,
            ], is_array($item) ? $item : []);

            // Sanitize: empty strings are not valid dates/values for DB columns
            if (($item['expiry_date'] ?? null) === '') $item['expiry_date'] = null;
            if (($item['batch_number'] ?? null) === '') $item['batch_number'] = null;
            if (($item['serial'] ?? null) === '')       $item['serial']       = null;

            $qty = (float) ($item['quantity'] ?? 0);
            $cost = (float) ($item['cost'] ?? 0);

            if (($item['product_id'] ?? null) === null) {
                throw new Exception('كل عنصر يجب أن يحتوي على product_id.');
            }

            if ($qty <= 0 || $cost <= 0) {
                throw new Exception('يجب أن تكون الكمية والتكلفة لكل منتج أكبر من صفر.');
            }

            $itemTotal = round($qty * $cost, 2);
            $item['quantity'] = $qty;
            $item['cost'] = $cost;
            $item['price'] = $cost;
            $item['total'] = $itemTotal;

            $grossTotal += $itemTotal;
            $normalizedItems[] = $item;
        }

        $discountType = (string) ($data['discount_type'] ?? 'fixed');
        $discountValue = (float) ($data['discount_value'] ?? 0);
        $taxRate = (float) ($data['tax_rate'] ?? 0);
        $paidAmount = (float) ($data['paid_amount'] ?? 0);

        $discountAmount = $this->financialCalcService->calculateDiscount($grossTotal, $discountValue, $discountType);

        $totalAfterDiscount = round($grossTotal - $discountAmount, 2);
        $taxAmount = $this->financialCalcService->calculateTax($totalAfterDiscount, $taxRate);
        $netTotal = round($totalAfterDiscount + $taxAmount, 2);

        $paidAmount = round($paidAmount, 2);
        if ($paidAmount > $netTotal) {
            throw new Exception("المبلغ المدفوع ({$paidAmount}) لا يمكن أن يتجاوز إجمالي الفاتورة ({$netTotal}).");
        }

        return [
            'items' => $normalizedItems,
            'gross_total' => round($grossTotal, 2),
            'discount_amount' => $discountAmount,
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'tax_rate' => $taxRate,
            'tax_amount' => $taxAmount,
            'net_total' => $netTotal,
            'paid_amount' => $paidAmount,
            'status' => $this->determinePurchaseStatus($netTotal, $paidAmount),
            'total_items' => count($normalizedItems),
        ];
    }

    private function getSupplierAccountId(int $supplierId): int
    {
        return $this->services->purchase((int) $this->tenantId)->getSupplierAccountId($supplierId);
    }

    private function generatePurchaseInvoiceNumber(?string $invoiceDate = null): string
    {
        return $this->services->purchase((int) $this->tenantId)->generateInvoiceNumber($invoiceDate);
    }

    private function insertPurchaseItems(
        int $purchaseId,
        array $items,
        float $grossTotal,
        float $discountAmount,
        ?int $branchId,
        ?string $notes,
        ?int $userId,
        bool $includeTracking = true
    ): void {
        $this->services->purchase((int) $this->tenantId, $userId)->insertPurchaseItems(
            $purchaseId,
            $items,
            $grossTotal,
            $discountAmount,
            $branchId,
            $notes,
            $includeTracking
        );
    }

    private function createJournalEntryForPurchase(array $purchaseData, int $supplierAccountIdFromDb): int
    {
        $userId = isset($purchaseData['user_id']) ? (int) $purchaseData['user_id'] : null;
        return $this->services->purchase((int) $this->tenantId, $userId)->createJournalEntry($purchaseData, $supplierAccountIdFromDb);
    }

    private function recordPurchasePayment(
        int $purchaseId,
        int $supplierId,
        float $amount,
        string $paymentDate,
        int $paymentMethodId,
        ?string $referenceNumber,
        ?int $userId,
        ?int $branchId,
        ?int $costCenterId,
        ?int $supplierAccountId,
        bool $enforceCashierSession,
        ?Request $request = null,
        bool $purchaseJeAlreadyCreated = false,
        ?int $resolvedSessionId = null,
        bool $sessionAlreadyResolved = false,
        bool $liquidityAlreadyValidated = false
    ): array {
        $sessionId = $sessionAlreadyResolved
            ? $resolvedSessionId
            : $this->resolvePurchasePaymentSession(
                $paymentMethodId,
                $branchId,
                $userId,
                $enforceCashierSession,
                $request
            );

        return $this->services->purchase((int) $this->tenantId, $userId)->recordPayment(
            $purchaseId,
            $supplierId,
            $amount,
            $paymentDate,
            $paymentMethodId,
            $referenceNumber,
            $branchId,
            $costCenterId,
            $supplierAccountId,
            $sessionId,
            $purchaseJeAlreadyCreated,
            $liquidityAlreadyValidated
        );
    }

    private function resolvePurchasePaymentSession(
        int $paymentMethodId,
        ?int $branchId,
        ?int $userId,
        bool $enforceCashierSession,
        ?Request $request
    ): ?int {
        if (!$enforceCashierSession || !$request) {
            return null;
        }

        $tenantId = (int) $this->tenantId;
        $sessionsEnabled = $this->isSessionsEnabled($tenantId);
        $isExempt = $this->isCashierSessionExempt($request);
        $isCash = $this->isCashMethod($paymentMethodId, $tenantId);

        if ($sessionsEnabled && $isCash && !$isExempt) {
            if (!$branchId) {
                throw new Exception('يجب تحديد المخزن لإتمام الدفعة النقدية للكاشير.');
            }
            return $this->requireOpenCashierSession(
                $tenantId,
                (int) $branchId,
                $userId ? (int) $userId : null
            );
        }

        if (($isExempt || !$sessionsEnabled) && $branchId) {
            return $this->findOpenCashierSession($tenantId, (int) $branchId, null);
        }

        return null;
    }

    private function auditSafe(
        string $action,
        string $entityType,
        int $entityId,
        array $payload,
        ?int $userId = null
    ): void {
        $this->services->purchase((int) $this->tenantId, $userId)->auditSafe(
            $action,
            $entityType,
            $entityId,
            $payload
        );
    }

    /** Return the next invoice number in the current tenant context. */
    public function getNextInvoiceNumber(Request $request, Response $response): Response
    {
        try {
            $this->requireTenantContext($request);
        } catch (Throwable $e) {
            return $this->errorResponse($response, 'غير مصرح', 403);
        }

        try {
            $this->db->beginTransaction();
            $invoiceNumber = $this->generatePurchaseInvoiceNumber();
            $this->db->commit();

            return $this->successResponse($response, [
                'invoice_number' => $invoiceNumber
            ], 200);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logger->error('Failed to generate next purchase invoice number', [
                'tenant_id' => $this->tenantId,
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse($response, 'تعذر إنشاء رقم فاتورة الشراء', 500);
        }
    }

    /** List tenant purchase invoices with pagination, filters, computed balances, and optional totals. */
    public function list(Request $request, Response $response): Response
    {
        try {
            $ctx = $this->requireTenantContext($request);
            $tenantId = $ctx['tenant_id'];

            $qp = $request->getQueryParams();
            [$page, $perPage, $offset] = PaginationHelper::fromArray($qp, 10);
            $search = trim((string) ($qp['q'] ?? $qp['search'] ?? ''));
            $supplierId = !empty($qp['supplier_id']) ? (int) $qp['supplier_id'] : null;
            $branchId = !empty($qp['branch_id']) ? (int) $qp['branch_id'] : null;
            $status = !empty($qp['status']) ? (string) $qp['status'] : null;
            $dateFrom = $qp['date_from'] ?? ($qp['start_date'] ?? null);
            $dateTo = $qp['date_to'] ?? ($qp['end_date'] ?? null);

            $sort = strtolower((string) ($qp['sort'] ?? 'invoice_date'));
            $order = strtolower((string) ($qp['order'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
            $allowedSort = [
                'id' => 'p.id',
                'invoice_date' => 'p.invoice_date',
                'total_amount' => 'p.total_amount',
                'status' => 'p.status'
            ];
            $sortCol = $allowedSort[$sort] ?? 'p.invoice_date';

            $where = ["p.tenant_id = ?", "p.invoice_number NOT LIKE 'OB-%'"];
            $params = [$tenantId];

            if ($search !== '') {
                $where[] = "(p.invoice_number LIKE ? OR s.name LIKE ?)";
                $params[] = "%{$search}%";
                $params[] = "%{$search}%";
            }

            if ($supplierId) {
                $where[] = "p.supplier_id = ?";
                $params[] = $supplierId;
            }

            if ($branchId) {
                $where[] = "p.branch_id = ?";
                $params[] = $branchId;
            }

            if ($status) {
                $where[] = "p.status = ?";
                $params[] = $status;
            }

            if ($dateFrom) {
                $where[] = "p.invoice_date >= ?";
                $params[] = $this->normalizeDateTime($dateFrom, false);
            }

            if ($dateTo) {
                $where[] = "p.invoice_date <= ?";
                $params[] = $this->normalizeDateTime($dateTo, true);
            }

            $from = "
                FROM purchases p
                LEFT JOIN suppliers s ON p.supplier_id = s.id AND s.tenant_id = p.tenant_id
                LEFT JOIN users u ON p.user_id = u.id AND u.tenant_id = p.tenant_id
                LEFT JOIN journal_entries je ON je.id = p.journal_entry_id AND je.tenant_id = p.tenant_id
            ";
            $whereSql = " WHERE " . implode(' AND ', $where);

            $stmt = $this->db->prepare("SELECT COUNT(*) " . $from . $whereSql);
            $stmt->execute($params);
            $total = (int) $stmt->fetchColumn();

            $itemsSql = "
                SELECT p.*,
                    s.name AS supplier_name,
                    u.name AS created_by_name,
                    je.status AS journal_status,
                    COALESCE((SELECT SUM(
                                  CASE WHEN pm.type = 'payment_for_return' THEN -pm.amount ELSE pm.amount END
                              ) FROM payments pm
                              WHERE pm.purchase_id = p.id AND pm.tenant_id = p.tenant_id
                                AND pm.status = 'completed' AND pm.is_draft = 0), 0) AS actual_paid_amount,
                    (SELECT COUNT(*) FROM payments pm
                     WHERE pm.purchase_id = p.id AND pm.tenant_id = p.tenant_id
                       AND COALESCE(pm.type, '') <> 'payment_for_return') AS linked_payment_count,
                    (SELECT COUNT(*) FROM returns r
                     WHERE r.purchase_id = p.id AND r.tenant_id = p.tenant_id
                       AND r.return_type = 'purchase') AS linked_return_count,
                    COALESCE((SELECT SUM(r.grand_total) FROM returns r
                              WHERE r.purchase_id = p.id AND r.tenant_id = p.tenant_id
                                AND r.return_type = 'purchase'), 0) AS return_amount,
                    (SELECT MIN(pm.payment_date) FROM payments pm
                     WHERE pm.purchase_id = p.id AND pm.tenant_id = p.tenant_id
                       AND pm.status = 'completed' AND pm.is_draft = 0
                        AND COALESCE(pm.type, '') <> 'payment_for_return' LIMIT 1) AS first_payment_date
                $from
                $whereSql
                ORDER BY $sortCol $order
                LIMIT $perPage OFFSET $offset
            ";
            $stmt = $this->db->prepare($itemsSql);
            $stmt->execute($params);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $locale = $this->getLocale($request);
            foreach ($items as &$it) {
                $it['reference_type'] = 'purchase';
                $it['reference_id'] = $it['id'] ?? null;
                $it['reference'] = 'purchase#' . ($it['id'] ?? '');
                $it['reference_label'] = $this->refLabel('purchase', $locale);
                $actualPaid   = round((float) ($it['actual_paid_amount'] ?? $it['paid_amount'] ?? 0), 2);
                $totalAmt     = round((float) ($it['total_amount'] ?? 0), 2);
                $returnAmount = round((float) ($it['return_amount'] ?? 0), 2);
                $storedStatus = strtolower((string) ($it['status'] ?? ''));
                $it['actual_paid_amount'] = $actualPaid;
                $it['return_amount']      = $returnAmount;
                $it['remaining_balance'] = in_array($storedStatus, ['canceled', 'cancelled', 'rejected'], true)
                    ? 0.0
                    : PurchaseReturnSettlementPolicy::outstandingAmount($totalAmt, $actualPaid, $returnAmount);
                $it['dynamic_status'] = in_array($storedStatus, ['canceled', 'cancelled', 'rejected'], true)
                    ? $storedStatus
                    : $this->determinePurchaseStatus($totalAmt, $actualPaid, $returnAmount, $it['created_at'] ?? null, $it['first_payment_date'] ?? null);
                $it['status_label'] = $this->statusLabel($it['dynamic_status'], $locale);
            }
            unset($it);

            $summary = null;
            $includeTotals = isset($qp['include_totals']) && (string) $qp['include_totals'] !== '0';
            if ($includeTotals) {
                $summaryWhereSql = $whereSql . " AND LOWER(COALESCE(p.status, '')) NOT IN ('canceled', 'cancelled', 'rejected')";
                $sumSql = "
                    SELECT
                        COALESCE(SUM(p.total_amount), 0) AS sum_total_amount,
                        COALESCE(SUM(p.tax_amount), 0) AS sum_tax,
                        COALESCE(SUM(p.discount_value), 0) AS sum_discount,
                        COALESCE(SUM(COALESCE((SELECT SUM(
                            CASE WHEN pm.type = 'payment_for_return' THEN -pm.amount ELSE pm.amount END
                        ) FROM payments pm
                        WHERE pm.purchase_id = p.id AND pm.tenant_id = p.tenant_id
                          AND pm.status = 'completed' AND pm.is_draft = 0), 0)), 0) AS sum_paid_amount,
                        COALESCE(SUM(GREATEST(
                            p.total_amount
                            - COALESCE((SELECT SUM(
                                CASE WHEN pm.type = 'payment_for_return' THEN -pm.amount ELSE pm.amount END
                            ) FROM payments pm
                            WHERE pm.purchase_id = p.id AND pm.tenant_id = p.tenant_id
                              AND pm.status = 'completed' AND pm.is_draft = 0), 0)
                            - COALESCE((SELECT SUM(r.grand_total) FROM returns r
                                WHERE r.purchase_id = p.id AND r.tenant_id = p.tenant_id
                                  AND r.return_type = 'purchase'), 0),
                            0
                        )), 0) AS sum_balance_due
                    $from
                    $summaryWhereSql
                ";
                $stmt = $this->db->prepare($sumSql);
                $stmt->execute($params);
                $summary = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
                    'sum_total_amount' => 0,
                    'sum_tax' => 0,
                    'sum_discount' => 0,
                    'sum_paid_amount' => 0,
                    'sum_balance_due' => 0
                ];
            }

            return $this->jsonResponse($response, [
                'status'     => 'success',
                'items'      => $items,
                'total'      => $total,
                'summary'    => $summary,
                'pagination' => PaginationHelper::buildMeta($total, $page, $perPage),
            ], 200);
        } catch (Throwable $e) {
            $this->logger->error('Purchases list error', [
                'message' => $e->getMessage(),
                'tenant_id' => $this->tenantId ?? null
            ]);

            return $this->errorResponse($response, 'حدث خطأ أثناء جلب قائمة المشتريات', 500);
        }
    }

    /** Return one tenant purchase invoice with line items, payments, and computed balance. */
    public function get(Request $request, Response $response, array $args = []): Response
    {
        try {
            $ctx = $this->requireTenantContext($request);
            $tenantId = $ctx['tenant_id'];
            $id = !empty($args['id']) ? (int) $args['id'] : 0;

            if ($id <= 0) {
                return $this->errorResponse($response, 'مطلوب رقم فاتورة الشراء', 400);
            }

            $stmt = $this->db->prepare("
                SELECT
                    p.*,
                    s.name AS supplier_name,
                    s.phone AS supplier_phone,
                    u.name AS created_by_name,
                    je.status AS journal_status
                FROM purchases p
                LEFT JOIN suppliers s ON p.supplier_id = s.id AND s.tenant_id = p.tenant_id
                LEFT JOIN users u ON p.user_id = u.id AND u.tenant_id = p.tenant_id
                LEFT JOIN journal_entries je ON je.id = p.journal_entry_id AND je.tenant_id = p.tenant_id
                WHERE p.id = ? AND p.tenant_id = ?
                LIMIT 1
            ");
            $stmt->execute([$id, $tenantId]);
            $purchase = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$purchase) {
                return $this->errorResponse($response, 'لم يتم العثور على فاتورة الشراء', 404);
            }

            $stmt = $this->db->prepare("
                SELECT
                    pi.*,
                    p.name AS product_name,
                    p.barcode,
                    u.name AS unit_name,
                    u.code AS unit_code
                FROM purchase_items pi
                LEFT JOIN products p ON pi.product_id = p.id AND p.tenant_id = pi.tenant_id
                LEFT JOIN units u ON pi.unit_id = u.id AND (u.tenant_id = pi.tenant_id OR u.tenant_id IS NULL)
                WHERE pi.purchase_id = ? AND pi.tenant_id = ?
            ");
            $stmt->execute([$id, $tenantId]);
            $purchase['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmt = $this->db->prepare("
                SELECT p.*, u.name AS created_by_name
                FROM payments p
                LEFT JOIN users u ON p.created_by = u.id AND u.tenant_id = p.tenant_id
                WHERE p.purchase_id = ? AND p.tenant_id = ? AND p.status = 'completed' AND p.is_draft = 0
                ORDER BY p.payment_date DESC
            ");
            $stmt->execute([$id, $tenantId]);
            $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $locale = $this->getLocale($request);
            foreach ($payments as &$payment) {
                $payment['reference_type'] = 'payment';
                $payment['reference_id'] = $payment['id'] ?? null;
                $payment['reference'] = 'payment#' . ($payment['id'] ?? '');
                $payment['reference_label'] = $this->refLabel('payment', $locale);
                $payment['status_label'] = $this->statusLabel($payment['status'] ?? 'completed', $locale);
            }
            unset($payment);

            $stmtPaid = $this->db->prepare("
                SELECT COALESCE(SUM(
                    CASE WHEN type = 'payment_for_return' THEN -amount ELSE amount END
                ), 0)
                FROM payments
                WHERE purchase_id = ? AND tenant_id = ?
                  AND status = 'completed' AND is_draft = 0
            ");
            $stmtPaid->execute([$id, $tenantId]);
            $actualPaid = round((float) $stmtPaid->fetchColumn(), 2);
            $totalAmt   = round((float) ($purchase['total_amount'] ?? 0), 2);

            $stmtRet = $this->db->prepare("
                SELECT COALESCE(SUM(grand_total), 0)
                FROM returns
                WHERE purchase_id = ? AND tenant_id = ? AND return_type = 'purchase'
            ");
            $stmtRet->execute([$id, $tenantId]);
            $returnAmount = round((float) $stmtRet->fetchColumn(), 2);

            $stmtFirstPayment = $this->db->prepare("
                SELECT MIN(payment_date) FROM payments
                WHERE purchase_id = ? AND tenant_id = ? AND status = 'completed'
                  AND is_draft = 0 AND COALESCE(type, '') <> 'payment_for_return'
                LIMIT 1
            ");
            $stmtFirstPayment->execute([$id, $tenantId]);
            $firstPaymentDate = $stmtFirstPayment->fetchColumn();

            $purchase['reference_type']   = 'purchase';
            $purchase['reference_id']     = $purchase['id'] ?? null;
            $purchase['reference']        = 'purchase#' . ($purchase['id'] ?? '');
            $purchase['reference_label']  = $this->refLabel('purchase', $locale);
            $storedStatus = strtolower((string) ($purchase['status'] ?? ''));
            $purchase['actual_paid_amount'] = $actualPaid;
            $purchase['return_amount']      = $returnAmount;
            $purchase['remaining_balance'] = in_array($storedStatus, ['canceled', 'cancelled', 'rejected'], true)
                ? 0.0
                : PurchaseReturnSettlementPolicy::outstandingAmount($totalAmt, $actualPaid, $returnAmount);
            $purchase['dynamic_status'] = in_array($storedStatus, ['canceled', 'cancelled', 'rejected'], true)
                ? $storedStatus
                : $this->determinePurchaseStatus($totalAmt, $actualPaid, $returnAmount, $purchase['created_at'] ?? null, $firstPaymentDate);
            $purchase['status_label']     = $this->statusLabel($purchase['dynamic_status'], $locale);
            $purchase['payments']         = $payments;

            return $this->jsonResponse($response, [
                'status' => 'success',
                'message' => '',
                'data' => $purchase
            ], 200);
        } catch (Throwable $e) {
            $this->logger->error('Purchase get error', [
                'message' => $e->getMessage(),
                'tenant_id' => $this->tenantId ?? null,
                'purchase_id' => $args['id'] ?? null
            ]);

            return $this->errorResponse($response, 'حدث خطأ أثناء جلب تفاصيل فاتورة الشراء', 500);
        }
    }

    /**
     * Create an invoice, line items, accounting entries, and an optional initial payment.
     * Requires an Idempotency-Key; the key and request fingerprint are stored with the invoice.
     */
    public function create(Request $request, Response $response): Response
    {
        $idempotencyKey = '';
        $requestHash = '';
        $tenantIdForReplay = 0;
        $userIdForReplay = 0;
        $purchaseTransactionStarted = false;

        try {
            $ctx = $this->requireTenantContext($request);
            $userId = $ctx['user_id'];
            $tenantIdForReplay = (int) $this->tenantId;
            $userIdForReplay = (int) $userId;

            $idempotencyKey = trim($request->getHeaderLine('Idempotency-Key'));
            if ($idempotencyKey === '' || strlen($idempotencyKey) > 128) {
                return $this->errorResponse($response, 'مطلوب مفتاح طلب صالح لمنع إنشاء فاتورة شراء مكررة.', 400);
            }

            $data = $request->getParsedBody();
            $data = is_array($data) ? $data : [];
            $requestHash = \App\Services\PurchaseRequestFingerprint::fromPayload($data);

            foreach (['supplier_id', 'branch_id', 'items', 'payment_method_id'] as $field) {
                if (empty($data[$field])) {
                    return $this->errorResponse($response, "حقل مطلوب: {$field}", 400);
                }
            }

            $data = array_merge([
                'invoice_date' => date('Y-m-d H:i:s'),
                'discount_type' => 'fixed',
                'discount_value' => 0,
                'paid_amount' => 0,
                'notes' => '',
                'tax_rate' => 0,
            ], $data);

            $replayedResponse = $this->existingPurchaseCreationResponse(
                $response,
                (int) $this->tenantId,
                (int) $userId,
                $idempotencyKey,
                $requestHash
            );
            if ($replayedResponse !== null) {
                return $replayedResponse;
            }

            $totals = $this->calculatePurchaseTotals($data);
            $invoiceDate = $this->normalizeDateTime((string) $data['invoice_date']);
            $this->applyDefaultCostCenter($data, $request);
            $costCenterId = $data['cost_center_id'] ?? 1;

            $this->db->beginTransaction();
            $purchaseTransactionStarted = true;
            $invoiceNumber = !empty($data['invoice_number'])
                ? (string) $data['invoice_number']
                : $this->generatePurchaseInvoiceNumber($invoiceDate);

            $stmt = $this->db->prepare("
                INSERT INTO purchases (
                    tenant_id, supplier_id, invoice_number, invoice_date, total_amount,
                    paid_amount, discount_value, discount_type, tax_rate, tax_amount,
                    notes, payment_method_id, user_id, total_items, status, branch_id,
                    cost_center_id, created_at, idempotency_key, request_hash
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?)
            ");

            $maxAttempts = 5;
            $attempt = 0;

            while (true) {
                try {
                    $attempt++;

                    $stmt->execute([
                        $this->tenantId,
                        (int) $data['supplier_id'],
                        $invoiceNumber,
                        $invoiceDate,
                        $totals['net_total'],
                        $totals['paid_amount'],
                        $totals['discount_amount'],
                        $totals['discount_type'],
                        $totals['tax_rate'],
                        $totals['tax_amount'],
                        $data['notes'] ?? null,
                        (int) $data['payment_method_id'],
                        $userId,
                        $totals['total_items'],
                        $totals['status'],
                        (int) $data['branch_id'],
                        $costCenterId,
                        $idempotencyKey,
                        $requestHash
                    ]);
                    break;
                } catch (\Throwable $e) {
                    if ($this->isDuplicateKeyException($e)) {
                        if ($this->db->inTransaction()) {
                            $this->db->rollBack();
                        }
                        $replayedResponse = $this->existingPurchaseCreationResponse(
                            $response,
                            (int) $this->tenantId,
                            (int) $userId,
                            $idempotencyKey,
                            $requestHash
                        );
                        if ($replayedResponse !== null) {
                            return $replayedResponse;
                        }
                        if ($attempt >= $maxAttempts) {
                            throw $e;
                        }
                        $this->db->beginTransaction();
                    }

                    if ($attempt >= $maxAttempts) {
                        throw $e;
                    }

                    $invoiceNumber = $this->generatePurchaseInvoiceNumber($invoiceDate) . '-' . date('His') . '-' . mt_rand(100, 999);
                    $this->logger->warning('Duplicate invoice detected, regenerating', [
                        'attempt' => $attempt,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            $purchaseId = (int) $this->db->lastInsertId();

            $this->insertPurchaseItems(
                $purchaseId,
                $totals['items'],
                $totals['gross_total'],
                $totals['discount_amount'],
                (int) $data['branch_id'],
                $data['notes'] ?? null,
                $userId,
                true
            );

            $supplierAccountId = $this->getSupplierAccountId((int) $data['supplier_id']);

            $initialPaymentSessionId = null;
            if ($totals['paid_amount'] > 0) {
                $initialPaymentSessionId = $this->resolvePurchasePaymentSession(
                    (int) $data['payment_method_id'],
                    (int) $data['branch_id'],
                    $userId,
                    true,
                    $request
                );
                $this->services->purchase((int) $this->tenantId, $userId)->validatePaymentLiquidity(
                    (int) $data['payment_method_id'],
                    (float) $totals['paid_amount'],
                    $initialPaymentSessionId
                );
            }

            $this->createJournalEntryForPurchase([
                'id' => $purchaseId,
                'invoice_number' => $invoiceNumber,
                'invoice_date' => $invoiceDate,
                'total_amount' => $totals['net_total'],
                'paid_amount' => $totals['paid_amount'],
                'tax_amount' => $totals['tax_amount'],
                'payment_method_id' => (int) $data['payment_method_id'],
                'supplier_id' => (int) $data['supplier_id'],
                'user_id' => $userId,
                'branch_id' => (int) $data['branch_id'],
                'cost_center_id' => $costCenterId,
            ], $supplierAccountId);

            $initialPaymentId = null;
            $initialPaymentJeId = null;
            $sessionId = null;

            if ($totals['paid_amount'] > 0) {
                $paymentResult = $this->recordPurchasePayment(
                    $purchaseId,
                    (int) $data['supplier_id'],
                    (float) $totals['paid_amount'],
                    $invoiceDate,
                    (int) $data['payment_method_id'],
                    $data['reference_number'] ?? null,
                    $userId,
                    (int) $data['branch_id'],
                    $costCenterId,
                    $supplierAccountId,
                    true,
                    $request,
                    true,
                    $initialPaymentSessionId,
                    true,
                    true
                );

                $initialPaymentId = $paymentResult['payment_id'] ?? null;
                $initialPaymentJeId = $paymentResult['journal_entry_id'] ?? null;
                $sessionId = $paymentResult['session_id'] ?? null;
            }

            $this->db->commit();

            $this->auditSafe('purchase_created', 'purchases', $purchaseId, [
                'tenant_id' => (int) $this->tenantId,
                'user_id' => $userId,
                'supplier_id' => (int) $data['supplier_id'],
                'branch_id' => (int) $data['branch_id'],
                'invoice_number' => $invoiceNumber,
                'invoice_date' => $invoiceDate,
                'total_amount' => (float) $totals['net_total'],
                'tax_amount' => (float) $totals['tax_amount'],
                'discount_value' => (float) $totals['discount_amount'],
                'paid_amount' => (float) $totals['paid_amount'],
                'payment_method_id' => (int) $data['payment_method_id'],
                'total_items' => (int) $totals['total_items'],
                'status' => $totals['status']
            ], $userId);

            if ($initialPaymentId) {
                $this->auditSafe('purchase_payment_added', 'purchases', $purchaseId, [
                    'tenant_id' => (int) $this->tenantId,
                    'user_id' => $userId,
                    'supplier_id' => (int) $data['supplier_id'],
                    'branch_id' => (int) $data['branch_id'],
                    'session_id' => $sessionId,
                    'purchase_id' => $purchaseId,
                    'payment_id' => (int) $initialPaymentId,
                    'journal_entry_id' => $initialPaymentJeId,
                    'amount' => (float) $totals['paid_amount'],
                    'payment_method_id' => (int) $data['payment_method_id'],
                    'payment_date' => $invoiceDate
                ], $userId);
            }

            return $this->jsonResponse($response, [
                'status' => 'success',
                'message' => 'تم إنشاء الفاتورة بنجاح',
                'data' => [
                    'id' => $purchaseId,
                    'invoice_number' => $invoiceNumber
                ]
            ], 201);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            if ($purchaseTransactionStarted && $idempotencyKey !== '' && $requestHash !== '') {
                try {
                    $replayedResponse = $this->existingPurchaseCreationResponse(
                        $response,
                        $tenantIdForReplay,
                        $userIdForReplay,
                        $idempotencyKey,
                        $requestHash
                    );
                    if ($replayedResponse !== null) {
                        return $replayedResponse;
                    }
                } catch (Throwable $verificationError) {
                    $this->logger->error('Unable to determine purchase-create outcome after transaction error', [
                        'tenant_id' => $tenantIdForReplay,
                        'user_id' => $userIdForReplay,
                        'idempotency_key' => $idempotencyKey,
                        'error' => $verificationError->getMessage(),
                    ]);
                    return $this->errorResponse(
                        $response,
                        'تعذر تأكيد نتيجة إنشاء الفاتورة. أعد المحاولة بنفس الطلب ولا تنشئ فاتورة جديدة.',
                        503
                    );
                }
            }

            return $this->errorResponse($response, $e->getMessage() ?: 'فشل في إنشاء فاتورة الشراء',
                ($e instanceof \App\Exceptions\InsufficientLiquidityException) ? 402
                    : (($e instanceof \DomainException) ? 422 : 400),
                ($e instanceof \App\Exceptions\InsufficientLiquidityException)
                    ? ['error_code' => 'insufficient_liquidity', 'details' => $e->getContext()]
                    : []
            );
        }
    }

    /** Return the matching committed invoice or a conflict for a key reused by another request. */
    private function existingPurchaseCreationResponse(
        Response $response,
        int $tenantId,
        int $userId,
        string $idempotencyKey,
        string $requestHash
    ): ?Response {
        $stmt = $this->db->prepare(
            'SELECT id, invoice_number, request_hash, user_id FROM purchases
             WHERE tenant_id = ? AND idempotency_key = ? LIMIT 1'
        );
        $stmt->execute([$tenantId, $idempotencyKey]);
        $purchase = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$purchase) {
            return null;
        }

        if ((int) $purchase['user_id'] !== $userId || !hash_equals((string) $purchase['request_hash'], $requestHash)) {
            return $this->errorResponse(
                $response,
                'مفتاح الطلب مستخدم لبيانات شراء مختلفة. تحقق من الفواتير قبل بدء طلب جديد.',
                409
            );
        }

        return $this->jsonResponse($response, [
            'status' => 'success',
            'message' => 'تم إنشاء الفاتورة مسبقًا لهذا الطلب',
            'data' => [
                'id' => (int) $purchase['id'],
                'invoice_number' => (string) $purchase['invoice_number'],
            ],
        ], 200);
    }

    private function isDuplicateKeyException(Throwable $exception): bool
    {
        return $exception instanceof PDOException
            && ((int) ($exception->errorInfo[1] ?? 0) === 1062 || (string) $exception->getCode() === '23000');
    }

    /** Recalculate and update an invoice through the tenant purchase service. */
    public function update(Request $request, Response $response, array $args = []): Response
    {
        try {
            $ctx = $this->requireTenantContext($request);
            $userId = $ctx['user_id'];
            $id = !empty($args['id']) ? (int) $args['id'] : 0;

            if ($id <= 0) {
                return $this->errorResponse($response, 'مطلوب رقم فاتورة الشراء', 400);
            }

            $stmt = $this->db->prepare(
                "SELECT * FROM purchases WHERE id = ? AND tenant_id = ? LIMIT 1"
            );
            $stmt->execute([$id, $this->tenantId]);
            $purchase = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$purchase) {
                return $this->errorResponse($response, 'لم يتم العثور على فاتورة الشراء', 404);
            }

            $data = $request->getParsedBody();
            $data = is_array($data) ? $data : [];

            foreach (['supplier_id', 'branch_id', 'items', 'payment_method_id'] as $field) {
                if (empty($data[$field])) {
                    return $this->errorResponse($response, "حقل مطلوب: {$field}", 400);
                }
            }

            $data = array_merge([
                'invoice_date'   => $purchase['invoice_date'] ?? date('Y-m-d H:i:s'),
                'discount_type'  => $purchase['discount_type']  ?? 'fixed',
                'discount_value' => $purchase['discount_value'] ?? 0,
                'paid_amount'    => $purchase['paid_amount']    ?? 0,
                'notes'          => $purchase['notes']          ?? '',
                'tax_rate'       => $purchase['tax_rate']       ?? 0,
            ], $data);

            $totals      = $this->calculatePurchaseTotals($data);
            $invoiceDate = $this->normalizeDateTime((string) $data['invoice_date']);
            $this->applyDefaultCostCenter($data, $request);
            $costCenterId = $data['cost_center_id'] ?? 1;

            $this->db->beginTransaction();

            $svc    = $this->services->purchase((int) $this->tenantId, $userId);
            $sessionId = (float) $totals['paid_amount'] > 0
                ? $this->resolvePurchasePaymentSession(
                    (int) $data['payment_method_id'],
                    (int) $data['branch_id'],
                    $userId,
                    true,
                    $request
                )
                : null;
            $result = $svc->updatePurchase(
                $id,
                $purchase,
                $data,
                $totals,
                $invoiceDate,
                $costCenterId,
                $sessionId
            );

            $this->db->commit();

            $this->auditSafe('purchase_updated', 'purchases', $id, [
                'tenant_id'        => (int) $this->tenantId,
                'user_id'          => $userId,
                'branch_id'        => (int) $data['branch_id'],
                'purchase_id'      => $id,
                'supplier_id'      => (int) $data['supplier_id'],
                'total_amount'     => (float) $totals['net_total'],
                'paid_amount'      => (float) $totals['paid_amount'],
                'tax_amount'       => (float) $totals['tax_amount'],
                'payment_method_id' => (int) $data['payment_method_id'],
                'status'           => $totals['status'],
            ], $userId);

            return $this->jsonResponse($response, [
                'status'  => 'success',
                'message' => 'تم تحديث فاتورة الشراء بنجاح',
                'data'    => $result,
            ], 200);
        } catch (\App\Exceptions\InsufficientLiquidityException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return $this->errorResponse($response, $e->getMessage(), 402, [
                'error_code' => 'insufficient_liquidity',
                'details' => $e->getContext(),
            ]);
        } catch (\InvalidArgumentException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return $this->errorResponse($response, $e->getMessage(), 409);
        } catch (\DomainException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return $this->errorResponse($response, $e->getMessage(), 422);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return $this->errorResponse($response, 'فشل تحديث فاتورة الشراء', 400);
        }
    }


    /** Cancel an invoice through the purchase service while preserving its source record. */
    public function delete(Request $request, Response $response, array $args = []): Response
    {
        try {
            $ctx    = $this->requireTenantContext($request);
            $userId = $ctx['user_id'];
            $id     = !empty($args['id']) ? (int) $args['id'] : 0;

            if ($id <= 0) {
                return $this->errorResponse($response, 'Purchase ID is required', 400);
            }

            $stmt = $this->db->prepare(
                "SELECT * FROM purchases WHERE id = ? AND tenant_id = ? LIMIT 1"
            );
            $stmt->execute([$id, $this->tenantId]);
            $purchase = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$purchase) {
                return $this->errorResponse($response, 'Purchase not found', 404);
            }

            $this->db->beginTransaction();

            $svc = $this->services->purchase((int) $this->tenantId, $userId);
            $svc->deletePurchase($id);

            $this->db->commit();

            $this->auditSafe('purchase_cancelled', 'purchases', $id, [
                'tenant_id'      => (int) $this->tenantId,
                'user_id'        => $userId,
                'branch_id'      => $purchase['branch_id']      ?? null,
                'supplier_id'    => $purchase['supplier_id']    ?? null,
                'invoice_number' => $purchase['invoice_number'] ?? null,
            ], $userId);

            return $this->jsonResponse($response, [
                'status'  => 'success',
                'message' => 'تم إلغاء فاتورة الشراء وحفظ أثرها المحاسبي بنجاح',
            ], 200);
        } catch (\App\Exceptions\NotFoundException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return $this->errorResponse($response, $e->getMessage(), 404);
        } catch (\InvalidArgumentException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return $this->errorResponse($response, $e->getMessage(), 409);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return $this->errorResponse($response, $e->getMessage() ?: 'فشل حذف فاتورة الشراء', 400);
        }
    }

    /** Record an invoice payment with optional idempotency and cashier-session checks. */
    public function addPayment(Request $request, Response $response, array $args = []): Response
    {
        try {
            $ctx        = $this->requireTenantContext($request);
            $userId     = $ctx['user_id'];
            $purchaseId = !empty($args['id']) ? (int) $args['id'] : 0;

            if ($purchaseId <= 0) {
                return $this->errorResponse($response, 'مطلوب رقم فاتورة الشراء', 400);
            }

            $data = $request->getParsedBody();
            $data = is_array($data) ? $data : [];

            foreach (['amount', 'payment_date', 'payment_method_id'] as $field) {
                if (!isset($data[$field]) || $data[$field] === '') {
                    return $this->errorResponse($response, "حقل مطلوب: {$field}", 400);
                }
            }

            $requestFingerprint = \App\Services\PurchaseRequestFingerprint::fromPayload([
                'purchase_id' => $purchaseId,
                'amount' => round((float) $data['amount'], 2),
                'payment_date' => (string) $data['payment_date'],
                'payment_method_id' => (int) $data['payment_method_id'],
                'reference_number' => $data['reference_number'] ?? null,
                'branch_id' => $data['branch_id'] ?? null,
                'cost_center_id' => $data['cost_center_id'] ?? null,
            ]);

            $idemKey = trim($request->getHeaderLine('Idempotency-Key'));
            if (strlen($idemKey) > 128) {
                return $this->errorResponse($response, 'مفتاح منع التكرار أطول من الحد المسموح.', 400);
            }
            $idem    = null;
            if ($idemKey !== '') {
                $idem   = new \App\Services\IdempotencyService($this->db);
                $cached = $idem->check((int) $this->tenantId, $idemKey);
                if ($cached !== null) {
                    $this->logger->info('addPayment (purchase): returning cached idempotent response', [
                        'tenant_id'       => $this->tenantId,
                        'idempotency_key' => $idemKey,
                    ]);
                    return $this->existingInvoicePaymentResponse($response, $cached, $requestFingerprint);
                }
            }

            $stmt = $this->db->prepare("
                SELECT p.*, s.account_id AS supplier_account_id
                FROM purchases p
                INNER JOIN suppliers s ON s.id = p.supplier_id AND s.tenant_id = p.tenant_id
                WHERE p.id = ? AND p.tenant_id = ?
                LIMIT 1
            ");
            $stmt->execute([$purchaseId, $this->tenantId]);
            $purchase = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$purchase) {
                return $this->errorResponse($response, 'لم يتم العثور على فاتورة الشراء', 404);
            }

            $amount = round((float) $data['amount'], 2);
            if ($amount <= 0) {
                return $this->errorResponse($response, 'قيمة الدفعة يجب أن تكون أكبر من صفر', 400);
            }

            $paymentDate     = $this->normalizeDateTime((string) $data['payment_date']);
            $paymentMethodId = (int) $data['payment_method_id'];
            $referenceNumber = $data['reference_number'] ?? null;
            $branchId        = !empty($data['branch_id']) ? (int) $data['branch_id'] : (int) ($purchase['branch_id'] ?? 0);
            $this->applyDefaultCostCenter($data, $request);
            $costCenterId = $data['cost_center_id'] ?? 1;

            $sessionId = null;
            $isCash    = $this->isCashMethod($paymentMethodId, (int) $this->tenantId);
            if ($isCash) {
                $isSessionsEnabled = $this->isSessionsEnabled((int) $this->tenantId);
                $isExempt          = $this->isCashierSessionExempt($request);
                if ($isSessionsEnabled && !$isExempt) {
                    if (!$branchId) {
                        throw new \InvalidArgumentException('يجب تحديد الفرع لتسجيل الدفعة النقدية.');
                    }
                    $sessionId = $this->requireOpenCashierSession((int) $this->tenantId, $branchId, $userId);
                } elseif (($isExempt || !$isSessionsEnabled) && $branchId) {
                    $sessionId = $this->findOpenCashierSession((int) $this->tenantId, $branchId, null);
                }
            }

            $this->db->beginTransaction();

            $forUpdate = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $lockedPurchaseStmt = $this->db->prepare("
                SELECT p.*, s.account_id AS supplier_account_id
                FROM purchases p
                INNER JOIN suppliers s ON s.id = p.supplier_id AND s.tenant_id = p.tenant_id
                WHERE p.id = ? AND p.tenant_id = ?
                LIMIT 1{$forUpdate}
            ");
            $lockedPurchaseStmt->execute([$purchaseId, $this->tenantId]);
            $purchase = $lockedPurchaseStmt->fetch(PDO::FETCH_ASSOC);
            if (!$purchase) {
                throw new \App\Exceptions\NotFoundException('لم يتم العثور على فاتورة الشراء');
            }

            if ($idemKey !== '' && $idem !== null) {
                $cached = $idem->claimStrict(
                    (int) $this->tenantId,
                    $idemKey,
                    $requestFingerprint
                );
                if ($cached !== null) {
                    $this->db->commit();
                    return $this->existingInvoicePaymentResponse($response, $cached, $requestFingerprint);
                }
            }

            $svc    = $this->services->purchase((int) $this->tenantId, $userId);
            $result = $svc->addPaymentToInvoice(
                $purchaseId,
                $purchase,
                $amount,
                $paymentDate,
                $paymentMethodId,
                $referenceNumber,
                $branchId,
                $costCenterId,
                $sessionId
            );

            $responseData = [
                'status'  => 'success',
                'message' => 'تم تسجيل الدفعة بنجاح',
                'data'    => [
                    'payment_id'       => $result['payment_id']       ?? null,
                    'journal_entry_id' => $result['journal_entry_id'] ?? null,
                    'purchase_id'      => $purchaseId,
                    'status'           => $result['status']           ?? null,
                    'paid_amount'      => $result['new_paid']         ?? null,
                ],
            ];

            // Commit the idempotency record atomically with the payment.
            if ($idemKey !== '' && $idem !== null) {
                $idempotentResponse = $responseData;
                $idempotentResponse['_request_fingerprint'] = $requestFingerprint;
                $idem->storeStrict(
                    (int) $this->tenantId,
                    $idemKey,
                    (int) ($result['payment_id'] ?? 0),
                    $idempotentResponse
                );
            }

            $this->db->commit();

            $this->auditSafe('purchase_payment_added', 'purchases', $purchaseId, [
                'tenant_id'         => (int) $this->tenantId,
                'user_id'           => $userId,
                'branch_id'         => $branchId,
                'session_id'        => $result['session_id']  ?? null,
                'purchase_id'       => $purchaseId,
                'payment_id'        => (int) ($result['payment_id'] ?? 0),
                'amount'            => $amount,
                'payment_method_id' => $paymentMethodId,
                'payment_date'      => $paymentDate,
                'status'            => $result['status']      ?? null,
                'paid_amount_total' => $result['new_paid']    ?? null,
            ], $userId);

            return $this->jsonResponse($response, $responseData, 201);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($e instanceof \App\Exceptions\InsufficientLiquidityException) {
                $message = $e->getMessage() === 'Cash account cannot go negative when cashier sessions are disabled'
                    ? 'لا يمكن تسجيل الدفعة لأن الرصيد النقدي في الصندوق غير كافٍ. عند تعطيل إلزام جلسات الكاشير لا يُسمح بتجاوز رصيد الصندوق إلى السالب.'
                    : $e->getMessage();
                return $this->errorResponse($response, $message, 402, [
                    'error_code' => 'insufficient_liquidity',
                    'details' => $e->getContext(),
                ]);
            }

            return $this->errorResponse($response, $e->getMessage() ?: 'فشل تسجيل الدفعة', 400);
        }
    }

    /** Return a cached payment response only when the retry payload matches its original request. */
    private function existingInvoicePaymentResponse(
        Response $response,
        array $cached,
        string $requestFingerprint
    ): Response {
        $cachedFingerprint = $cached['_request_fingerprint'] ?? null;
        if (
            !is_string($cachedFingerprint)
            || !hash_equals($cachedFingerprint, $requestFingerprint)
            || !empty($cached['_processing'])
        ) {
            return $this->errorResponse(
                $response,
                'مفتاح منع التكرار مستخدم لبيانات دفعة مختلفة. استخدم مفتاحًا جديدًا بعد التحقق من الدفعات.',
                409
            );
        }

        unset($cached['_request_fingerprint']);
        return $this->jsonResponse($response, $cached, 200);
    }

    /** Record a supplier debt payment, optionally allocating it across specified invoices. */
    public function paySupplierDebt(Request $request, Response $response): Response
    {
        $tenantId = null;
        $userId = null;
        try {
            $ctx      = $this->requireTenantContext($request);
            $tenantId = $ctx['tenant_id'];
            $userId   = $ctx['user_id'];

            $data        = $request->getParsedBody();
            $data        = is_array($data) ? $data : [];
            $supplierId  = !empty($data['supplier_id']) ? (int) $data['supplier_id'] : 0;
            $amount      = isset($data['amount']) ? round((float) $data['amount'], 2) : 0;
            $paymentDate = $this->normalizeDateTime($data['payment_date'] ?? date('Y-m-d'));
            $notes       = (string) ($data['notes'] ?? '');
            $paymentMethodId = !empty($data['payment_method_id']) ? (int) $data['payment_method_id'] : 0;
            $branchId = !empty($data['branch_id']) ? (int) $data['branch_id'] : null;
            $allocations = $data['purchases'] ?? null;

            if ($supplierId <= 0 || !is_finite($amount) || $amount <= 0 || $paymentMethodId <= 0) {
                return $this->errorResponse($response, 'المورد والمبلغ وطريقة الدفع مطلوبة.', 400);
            }

            try {
                $normalizedAllocations = $allocations === null
                    ? []
                    : \App\Services\SupplierPaymentAllocationPolicy::normalize($allocations, $amount);
            } catch (\InvalidArgumentException $e) {
                return $this->errorResponse($response, $e->getMessage(), 400);
            }

            $requestFingerprint = hash('sha256', json_encode([
                'supplier_id' => $supplierId,
                'amount' => round($amount, 2),
                'payment_date' => $paymentDate,
                'payment_method_id' => $paymentMethodId,
                'branch_id' => $branchId,
                'cost_center_id' => isset($data['cost_center_id']) ? (int) $data['cost_center_id'] : null,
                'reference_number' => $data['reference_number'] ?? null,
                'notes' => $notes,
                'purchases' => $normalizedAllocations,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            $idempotencyKey = trim($request->getHeaderLine('Idempotency-Key'));
            if (strlen($idempotencyKey) > 128) {
                return $this->errorResponse($response, 'مفتاح منع التكرار أطول من الحد المسموح.', 400);
            }
            $idempotency = $idempotencyKey !== ''
                ? new \App\Services\IdempotencyService($this->db)
                : null;
            if ($idempotency !== null) {
                $cached = $idempotency->check((int) $tenantId, $idempotencyKey);
                if ($cached !== null) {
                    if (!hash_equals($requestFingerprint, (string) ($cached['_request_fingerprint'] ?? ''))) {
                        return $this->errorResponse($response, 'مفتاح منع التكرار مستخدم لبيانات دفعة مختلفة.', 409);
                    }
                    unset($cached['_request_fingerprint']);
                    return $this->jsonResponse($response, $cached, 200);
                }
            }

            $this->db->beginTransaction();
            $forUpdate = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $stmt = $this->db->prepare(
                "SELECT id FROM suppliers WHERE id = ? AND tenant_id = ? LIMIT 1{$forUpdate}"
            );
            $stmt->execute([$supplierId, $tenantId]);
            if (!$stmt->fetch()) {
                throw new \App\Exceptions\NotFoundException('Supplier not found or does not belong to this tenant');
            }
            if ($idempotency !== null) {
                $cached = $idempotency->checkStrict((int) $tenantId, $idempotencyKey);
                if ($cached !== null) {
                    $this->db->commit();
                    if (!hash_equals($requestFingerprint, (string) ($cached['_request_fingerprint'] ?? ''))) {
                        return $this->errorResponse($response, 'مفتاح منع التكرار مستخدم لبيانات دفعة مختلفة.', 409);
                    }
                    unset($cached['_request_fingerprint']);
                    return $this->jsonResponse($response, $cached, 200);
                }
            }

            $sessionId = null;
            if ($this->isCashMethod($paymentMethodId, (int) $tenantId)) {
                $sessionsEnabled = $this->isSessionsEnabled((int) $tenantId);
                $isExempt = $this->isCashierSessionExempt($request);
                if ($sessionsEnabled && !$isExempt) {
                    if (!$branchId) {
                        throw new \InvalidArgumentException('يجب تحديد الفرع لتسجيل الدفعة النقدية.');
                    }
                    $sessionId = $this->requireOpenCashierSession((int) $tenantId, $branchId, $userId);
                } elseif ($branchId) {
                    $sessionId = $this->findOpenCashierSession((int) $tenantId, $branchId, null);
                }
            }

            $svc = $this->services->purchase((int) $tenantId, $userId);
            $costCenterId = isset($data['cost_center_id']) ? (int) $data['cost_center_id'] : null;
            if ($normalizedAllocations !== []) {
                $payments = \App\Services\SupplierInvoicePaymentAllocator::allocate(
                    $this->db,
                    $svc,
                    (int) $tenantId,
                    $supplierId,
                    $normalizedAllocations,
                    $paymentDate,
                    $paymentMethodId,
                    $data['reference_number'] ?? ($notes !== '' ? $notes : null),
                    $branchId,
                    $costCenterId,
                    $sessionId
                );
                $responseData = [
                    'status' => 'success',
                    'message' => 'تم تسجيل الدفعة وتخصيصها على الفواتير المحددة.',
                    'data' => [
                        'supplier_id' => $supplierId,
                        'total_paid' => round($amount, 2),
                        'payments' => $payments,
                        'session_id' => $sessionId,
                    ],
                ];
            } else {
                $result = $svc->recordSupplierDebtPayment(
                    $supplierId,
                    $amount,
                    $paymentDate,
                    $notes,
                    $paymentMethodId,
                    $branchId,
                    $costCenterId,
                    $sessionId
                );
                $responseData = [
                    'status' => 'success',
                    'message' => 'دفعة تم تسجيلها بنجاح',
                    'data' => [
                        'payment_id' => $result['payment_id'],
                        'supplier_id' => $supplierId,
                        'amount' => round($amount, 2),
                        'payment_date' => $paymentDate,
                        'session_id' => $sessionId,
                    ],
                ];
            }

            if ($idempotency !== null) {
                $paymentId = $normalizedAllocations !== []
                    ? (int) ($payments[0]['payment_id'] ?? 0)
                    : (int) ($result['payment_id'] ?? 0);
                $idempotentResponse = $responseData;
                $idempotentResponse['_request_fingerprint'] = $requestFingerprint;
                $idempotency->storeStrict((int) $tenantId, $idempotencyKey, $paymentId, $idempotentResponse);
            }

            $this->db->commit();

            $this->auditSafe('supplier_payment_recorded', 'suppliers', $supplierId, [
                'tenant_id'    => (int) $tenantId,
                'supplier_id'  => $supplierId,
                'amount'       => (float) $amount,
                'payment_date' => $paymentDate,
                'notes'        => $notes,
                'allocations'  => $normalizedAllocations,
            ], $userId);

            return $this->jsonResponse($response, $responseData, 201);
        } catch (\App\Exceptions\InsufficientLiquidityException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return $this->errorResponse($response, $e->getMessage(), 402, [
                'error_code' => 'insufficient_liquidity',
                'details' => $e->getContext(),
            ]);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logger->error('Failed to record supplier payment', [
                'error'     => $e->getMessage(),
                'tenant_id' => $tenantId ?? 'unknown',
            ]);
            return $this->errorResponse($response, 'فشل تسجيل الدفعة', 400);
        }
    }
}
