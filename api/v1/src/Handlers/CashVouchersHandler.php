<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Services\MonologHandler;
use App\Services\BalanceCalculationService;
use App\Services\CostCenter\CostCenterService;
use App\Services\AccountingService;
use App\Services\CashVoucherService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use PDO;
use Throwable;
use Exception;
use DateTime;

class CashVouchersHandler extends BaseHandler
{
    private CostCenterService $costCenterService;
    private BalanceCalculationService $balanceCalcService;

    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->logger = MonologHandler::getInstance('cash_vouchers');
        $this->costCenterService = new CostCenterService($this->db, 'cash_vouchers');
        $this->balanceCalcService = new BalanceCalculationService($this->db);
    }

    public function getList(Request $request, Response $response, array $args = []): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                $this->logger->warning('Cash vouchers list - missing tenant ID');
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $filters = $this->normalizeListFilters($request->getQueryParams());
            $isExempt = $this->isCashierSessionExempt($request);

            // Non-exempt users must have branch_id filter
            if (!$isExempt && !$filters['branch_id']) {
                return $this->errorResponse($response, 'مطلوب تحديد الفرع (branch_id) لعرض السندات.', 400);
            }

            $sql = "
                SELECT
                    cv.*,
                    COALESCE(c.name, s.name, a.name, '-') AS account_name
                FROM cash_vouchers cv
                LEFT JOIN customers c
                    ON c.id = cv.customer_id
                    AND c.tenant_id = cv.tenant_id
                LEFT JOIN suppliers s
                    ON s.id = cv.supplier_id
                    AND s.tenant_id = cv.tenant_id
                LEFT JOIN accounts a
                    ON a.id = cv.account_id
                    AND a.tenant_id = cv.tenant_id
                WHERE cv.tenant_id = :tenant_id
            ";

            $binds = [':tenant_id' => (int) $tenantId];

            // Branch filtering: mandatory for non-exempt (checked above), optional for exempt
            if ($filters['branch_id']) {
                $sql .= " AND cv.branch_id = :branch_id";
                $binds[':branch_id'] = $filters['branch_id'];
            }

            if ($filters['type']) {
                $sql .= " AND cv.type = :type";
                $binds[':type'] = $filters['type'];
            }

            if ($filters['customer_id']) {
                $sql .= " AND cv.customer_id = :customer_id";
                $binds[':customer_id'] = (int) $filters['customer_id'];
            }

            if ($filters['supplier_id']) {
                $sql .= " AND cv.supplier_id = :supplier_id";
                $binds[':supplier_id'] = (int) $filters['supplier_id'];
            }

            if ($filters['date_from']) {
                $sql .= " AND cv.date >= :from";
                $binds[':from'] = $filters['date_from'] . ' 00:00:00';
            }

            if ($filters['date_to']) {
                $nextDay = date('Y-m-d', strtotime($filters['date_to'] . ' +1 day'));
                $sql .= " AND cv.date < :to";
                $binds[':to'] = $nextDay . ' 00:00:00';
            }

            if ($filters['search']) {
                $sql .= " AND (cv.reference LIKE :search OR cv.notes LIKE :search)";
                $binds[':search'] = '%' . $filters['search'] . '%';
            }

            $sql .= " ORDER BY cv.date DESC, cv.id DESC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($binds);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($items as &$item) {
                $this->decorateVoucherRow($item);
            }
            unset($item);

            return $this->jsonResponse($response, [
                'status' => 'success',
                'message' => '',
                'data' => [
                    'items' => $items
                ]
            ], 200);
        } catch (Throwable $e) {
            $this->logger->error('Cash vouchers list error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'tenant_id' => $tenantId ?? null
            ]);

            return $this->errorResponse($response, 'حدث خطأ أثناء جلب قائمة السندات', 500);
        }
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                $this->logger->warning('Cash voucher get - missing tenant ID');
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $id = isset($args['id']) ? (int) $args['id'] : 0;
            if ($id <= 0) {
                return $this->errorResponse($response, 'مطلوب رقم السند', 400);
            }

            $stmt = $this->db->prepare("
                SELECT
                    cv.*,
                    COALESCE(c.name, s.name, a.name, '-') AS account_name
                FROM cash_vouchers cv
                LEFT JOIN customers c
                    ON c.id = cv.customer_id
                    AND c.tenant_id = cv.tenant_id
                LEFT JOIN suppliers s
                    ON s.id = cv.supplier_id
                    AND s.tenant_id = cv.tenant_id
                LEFT JOIN accounts a
                    ON a.id = cv.account_id
                    AND a.tenant_id = cv.tenant_id
                WHERE cv.id = :id AND cv.tenant_id = :tenant_id
                LIMIT 1
            ");
            $stmt->execute([
                ':id' => $id,
                ':tenant_id' => (int) $tenantId
            ]);

            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$item) {
                return $this->errorResponse($response, 'لم يتم العثور على السند', 404);
            }

            $this->decorateVoucherRow($item);

            return $this->successResponse($response, $item, 200);
        } catch (Throwable $e) {
            $this->logger->error('Cash voucher get error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'tenant_id' => $tenantId ?? null,
                'voucher_id' => $id ?? null
            ]);

            return $this->errorResponse($response, 'حدث خطأ أثناء جلب بيانات السند', 500);
        }
    }

    public function create(Request $request, Response $response): Response
    {
        $tenantId       = null;
        $userId         = null;
        $idempotencyKey = null;
        $voucherType    = null;

        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $userId = $this->extractUserId($request);
            $svc    = new CashVoucherService($this->db, (int) $tenantId, $userId);

            $rawData = $this->parseRequestData($request);
            $data    = $svc->normalizeVoucherPayload($rawData);
            $data['created_by'] = $userId;

            $idempotencyKey = $data['idempotency_key'] ?? null;
            if ($idempotencyKey) {
                $existing = $svc->findVoucherByIdempotencyKey($idempotencyKey);
                if ($existing) {
                    return $this->jsonResponse($response, [
                        'status'  => 'success',
                        'message' => 'السند موجود بالفعل',
                        'data'    => ['id' => (int) $existing['id'], 'reference' => $existing['reference']],
                    ], 200);
                }
            }

            $validationError = $svc->validateVoucherPayload($data, false);
            if ($validationError) {
                return $this->errorResponse($response, $validationError, 400);
            }

            try {
                $this->applyDefaultCostCenter($data, $request);
            } catch (Throwable $e) {
                $this->logger->warning('applyDefaultCostCenter failed in create', [
                    'tenant_id' => $tenantId, 'message' => $e->getMessage(),
                ]);
            }

            if (empty($data['cost_center_id'])) {
                return $this->errorResponse(
                    $response,
                    'مركز التكلفة مطلوب لإنشاء سندات نقدية. يرجى التأكد من تحديد مركز التكلفة أو فرع للمستخدم.',
                    400
                );
            }

            if (!empty($data['sale_id'])) {
                if (!in_array($data['type'], ['receipt', 'قبض'], true)) {
                    return $this->errorResponse($response, 'ربط فاتورة مبيعات متاح فقط لسندات القبض.', 400);
                }
                $stmtChk = $this->db->prepare("SELECT id FROM sales WHERE id = ? AND customer_id = ? AND tenant_id = ? LIMIT 1");
                $stmtChk->execute([$data['sale_id'], $data['customer_id'], $tenantId]);
                if (!$stmtChk->fetchColumn()) {
                    return $this->errorResponse($response, 'فاتورة المبيعات المحددة غير موجودة أو لا تنتمي لهذا العميل.', 400);
                }
            }

            if (!empty($data['purchase_id'])) {
                if (!in_array($data['type'], ['payment', 'صرف'], true)) {
                    return $this->errorResponse($response, 'ربط فاتورة شراء متاح فقط لسندات الصرف.', 400);
                }
                $stmtChk = $this->db->prepare("SELECT id FROM purchases WHERE id = ? AND supplier_id = ? AND tenant_id = ? LIMIT 1");
                $stmtChk->execute([$data['purchase_id'], $data['supplier_id'], $tenantId]);
                if (!$stmtChk->fetchColumn()) {
                    return $this->errorResponse($response, 'فاتورة الشراء المحددة غير موجودة أو لا تنتمي لهذا المورد.', 400);
                }
            }

            $duplicateId = $svc->findDuplicateVoucher(
                $data['type'],
                $data['date'],
                (float) $data['amount'],
                $data['customer_id'],
                $data['supplier_id']
            );
            if ($duplicateId) {
                return $this->errorResponse($response, 'سند مطابق موجود بالفعل بنفس التاريخ والمبلغ والطرف.', 409);
            }

            // Cashier session — HTTP concern stays in handler
            $branchId        = $data['branch_id'];
            $paymentMethodId = $data['payment_method_id'];
            $amount          = (float) $data['amount'];
            $voucherType     = $data['type'];
            $sessionId       = null;

            $sessionsEnabled = $this->getBoolSetting((int) $tenantId, 'pos.sessions.enabled', false);
            $isCash          = $paymentMethodId !== null && $this->isCashMethod((int) $paymentMethodId, (int) $tenantId);
            $isExempt        = $this->isCashierSessionExempt($request);

            if ($sessionsEnabled && $isCash && $amount > 0 && !$branchId && !$isExempt) {
                return $this->errorResponse($response, 'حقل المخزن (branch_id) مطلوب لتسجيل سند نقدي وفق نظام جلسات الكاشير', 400);
            }

            if ($sessionsEnabled && $isCash && $amount > 0 && $branchId && !$isExempt) {
                try {
                    $sessionId = $this->requireOpenCashierSession((int) $tenantId, (int) $branchId, $userId);
                } catch (Exception $ex) {
                    $fallback = $this->findOpenCashierSession((int) $tenantId, (int) $branchId, null);
                    if ($fallback) {
                        $sessionId = $fallback;
                    } else {
                        throw $ex;
                    }
                }
            } elseif ($branchId && ($isExempt || !$sessionsEnabled)) {
                $sessionId = $this->findOpenCashierSession((int) $tenantId, (int) $branchId, null);
            }

            $svc->validateVoucherBusinessRules($data);

            $result = $svc->createVoucher($data, $sessionId);

            return $this->jsonResponse($response, [
                'status'  => 'success',
                'message' => 'تم إنشاء السند وترحيله إلى دفتر القيود بنجاح',
                'data'    => array_merge($data, $result),
            ], 201);

        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                try {
                    $this->db->rollBack();
                } catch (Throwable $rb) {
                    $this->logger->error('Error during rollback', ['message' => $rb->getMessage(), 'tenant_id' => $tenantId]);
                }
            }
            $msg = $e->getMessage();
            $this->logger->error('Cash voucher creation failed', [
                'message' => $msg, 'trace' => $e->getTraceAsString(),
                'tenant_id' => $tenantId, 'user_id' => $userId,
                'voucher_type' => $voucherType, 'idempotency_key' => $idempotencyKey,
            ]);
            if (strpos($msg, 'لا توجد جلسة كاشير مفتوحة') !== false) {
                return $this->errorResponse($response, $msg, 400);
            }
            return $this->errorResponse($response, 'فشل في إنشاء السند: ' . $msg, 400);
        }
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
        }

        $id = isset($args['id']) ? (int) $args['id'] : 0;
        if ($id <= 0) {
            return $this->errorResponse($response, 'مطلوب رقم السند', 400);
        }

        $stmtGet = $this->db->prepare("
            SELECT *
            FROM cash_vouchers
            WHERE id = :id AND tenant_id = :tenant_id
            LIMIT 1
        ");
        $stmtGet->execute([
            ':id' => $id,
            ':tenant_id' => (int) $tenantId
        ]);
        $oldVoucher = $stmtGet->fetch(PDO::FETCH_ASSOC);

        if (!$oldVoucher) {
            return $this->errorResponse($response, 'لم يتم العثور على السند', 404);
        }

        try {
            $userId = $this->extractUserId($request);
            $svc    = new CashVoucherService($this->db, (int) $tenantId, $userId);
            $data   = $this->parseRequestData($request);
            $data   = $svc->normalizeVoucherPayload($data, true, $oldVoucher);
            $data['created_by'] = $userId;

            try {
                $this->applyDefaultCostCenter($data, $request);
            } catch (Throwable $e) {
                $this->logger->warning('applyDefaultCostCenter failed in update', [
                    'tenant_id' => $tenantId,
                    'voucher_id' => $id,
                    'message' => $e->getMessage()
                ]);
            }

            $validationError = $svc->validateVoucherPayload($data, true);
            if ($validationError) {
                return $this->errorResponse($response, $validationError, 400);
            }

            if (empty($data['cost_center_id'])) {
                return $this->errorResponse($response, 'مركز التكلفة مطلوب لتحديث السند.', 400);
            }

            $svc->validateVoucherBusinessRules($data, $id);

            $this->db->beginTransaction();

            $svc->reverseJournalEntryByVoucherIdIfNeeded($id);

            $newVoucherData = array_merge($oldVoucher, $data);
            $newVoucherData['id'] = $id;

            $journalEntryId = $svc->postToJournal($newVoucherData, $id);
            if (!$journalEntryId) {
                throw new Exception('Failed to create new journal entry for the updated voucher.');
            }

            $notes = $newVoucherData['notes'] ?? ($newVoucherData['description'] ?? $oldVoucher['notes'] ?? null);

            $stmt = $this->db->prepare("
                UPDATE cash_vouchers SET
                    branch_id = :branch_id,
                    type = :type,
                    date = :date,
                    amount = :amount,
                    currency = :currency,
                    account_id = :account_id,
                    customer_id = :customer_id,
                    supplier_id = :supplier_id,
                    reference = :reference,
                    notes = :notes,
                    created_by = :created_by,
                    journal_entry_id = :journal_entry_id,
                    cost_center_id = :cost_center_id
                WHERE id = :id AND tenant_id = :tenant_id
            ");

            $stmt->execute([
                ':branch_id' => $newVoucherData['branch_id'] ?? null,
                ':type' => $newVoucherData['type'],
                ':date' => $newVoucherData['date'],
                ':amount' => $newVoucherData['amount'],
                ':currency' => $newVoucherData['currency'],
                ':account_id' => $newVoucherData['account_id'],
                ':customer_id' => $newVoucherData['customer_id'],
                ':supplier_id' => $newVoucherData['supplier_id'],
                ':reference' => $newVoucherData['reference'] ?? $oldVoucher['reference'],
                ':notes' => $notes,
                ':created_by' => $newVoucherData['created_by'] ?? $oldVoucher['created_by'],
                ':journal_entry_id' => (int) $journalEntryId,
                ':cost_center_id' => $newVoucherData['cost_center_id'],
                ':id' => $id,
                ':tenant_id' => (int) $tenantId
            ]);

            $stmtCashTrans = $this->db->prepare("
                UPDATE cash_transactions SET
                    customer_id = :customer_id,
                    supplier_id = :supplier_id,
                    amount = :amount,
                    type = :type,
                    description = :description,
                    payment_method_id = :payment_method_id,
                    created_by = :created_by,
                    created_at = :created_at,
                    notes = :notes,
                    journal_entry_id = :journal_entry_id,
                    cost_center_id = :cost_center_id
                WHERE reference_type = 'cash_voucher'
                  AND reference_id = :reference_id
                  AND tenant_id = :tenant_id
            ");

            $stmtCashTrans->execute([
                ':customer_id' => $newVoucherData['customer_id'],
                ':supplier_id' => $newVoucherData['supplier_id'],
                ':amount' => $newVoucherData['amount'],
                ':type' => $svc->mapVoucherTypeToCashTransactionType($newVoucherData['type']),
                ':description' => $notes,
                ':payment_method_id' => $newVoucherData['payment_method_id'] ?? null,
                ':created_by' => $userId,
                ':created_at' => $newVoucherData['date'],
                ':notes' => $notes,
                ':journal_entry_id' => (int) $journalEntryId,
                ':cost_center_id' => $newVoucherData['cost_center_id'],
                ':reference_id' => $id,
                ':tenant_id' => (int) $tenantId
            ]);

            $this->db->commit();

            return $this->jsonResponse($response, [
                'status' => 'success',
                'message' => 'تم تحديث السند بنجاح'
            ], 200);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            $this->logger->error('Voucher update failed', [
                'message' => $e->getMessage(),
                'voucher_id' => $id,
                'tenant_id' => $tenantId
            ]);

            return $this->errorResponse($response, 'فشل في تحديث السند', 500);
        }
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
        }

        $id = isset($args['id']) ? (int) $args['id'] : 0;
        if ($id <= 0) {
            return $this->errorResponse($response, 'مطلوب رقم السند', 400);
        }

        $stmtGet = $this->db->prepare("
            SELECT *
            FROM cash_vouchers
            WHERE id = :id AND tenant_id = :tenant_id
            LIMIT 1
        ");
        $stmtGet->execute([
            ':id' => $id,
            ':tenant_id' => (int) $tenantId
        ]);
        $voucher = $stmtGet->fetch(PDO::FETCH_ASSOC);

        if (!$voucher) {
            return $this->errorResponse($response, 'لم يتم العثور على السند', 404);
        }

        try {
            $userId = $this->extractUserId($request);
            $svc    = new CashVoucherService($this->db, (int) $tenantId, $userId);

            $this->db->beginTransaction();

            $svc->reverseJournalEntryByVoucherIdIfNeeded($id);

            $stmtCashTrans = $this->db->prepare("
                DELETE FROM cash_transactions
                WHERE reference_type = 'cash_voucher'
                  AND reference_id = :reference_id
                  AND tenant_id = :tenant_id
            ");
            $stmtCashTrans->execute([
                ':reference_id' => $id,
                ':tenant_id' => (int) $tenantId
            ]);

            $stmt = $this->db->prepare("
                DELETE FROM cash_vouchers
                WHERE id = :id AND tenant_id = :tenant_id
            ");
            $stmt->execute([
                ':id' => $id,
                ':tenant_id' => (int) $tenantId
            ]);

            $this->db->commit();

            return $this->jsonResponse($response, [
                'status' => 'success',
                'message' => 'تم حذف السند بنجاح'
            ], 200);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            $this->logger->error('Voucher deletion failed', [
                'message' => $e->getMessage(),
                'voucher_id' => $id,
                'tenant_id' => $tenantId
            ]);

            return $this->errorResponse($response, 'فشل في حذف السند', 500);
        }
    }

    private function normalizeListFilters(array $params): array
    {
        return [
            'type' => $params['type'] ?? null,
            'branch_id' => !empty($params['branch_id']) ? (int) $params['branch_id'] : null,
            'customer_id' => !empty($params['customer_id']) ? (int) $params['customer_id'] : null,
            'supplier_id' => !empty($params['supplier_id']) ? (int) $params['supplier_id'] : null,
            'date_from' => $params['start_date'] ?? ($params['from'] ?? null),
            'date_to' => $params['end_date'] ?? ($params['to'] ?? null),
            'search' => isset($params['search']) ? trim((string) $params['search']) : null,
        ];
    }

    private function parseRequestData(Request $request): array
    {
        $parsed = $request->getParsedBody();
        if (is_array($parsed)) {
            return $parsed;
        }

        $raw = (string) $request->getBody();
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new Exception('بيانات JSON غير صحيحة');
        }

        return $decoded;
    }

    private function decorateVoucherRow(array &$item): void
    {
        if (!isset($item['description'])) {
            $item['description'] = $item['notes'] ?? null;
        }

        if (empty($item['reference']) && !empty($item['date']) && !empty($item['id'])) {
            $item['reference'] = 'CV-' . date('Ymd', strtotime((string) $item['date'])) . '-' . $item['id'];
        }

        if (!isset($item['account_name']) || $item['account_name'] === null || $item['account_name'] === '') {
            $item['account_name'] = '-';
        }
    }
}
