<?php

declare(strict_types=1);

namespace App\Handlers;

use PDO;
use App\Services\MonologHandler;
use App\Services\CostCenter\CostCenterService;
use App\Services\AccountManagementService;
use App\Services\CurrencyService;
use App\Services\CustomerPaymentAllocationService;
use App\Handlers\AuditHandler;
use App\Repositories\SaleRepository;
use App\Repositories\PaymentRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Handles customer CRUD, account statements, and payments.
 *
 * Inherits contact-account utilities from BaseContactHandler. Payment creation
 * also coordinates accounting, cashier sessions, cost centers, currency,
 * idempotency, and FIFO invoice allocation; app/routes/customers.php applies
 * endpoint permissions.
 */
class CustomersHandler extends BaseContactHandler
{
    protected string $contactType = 'customer';
    private CostCenterService $costCenterService;

    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->logger = MonologHandler::getInstance('customers');
        $this->costCenterService = new CostCenterService($db);
    }

    /**
     * Resolve the customer's account and delegate statement generation.
     */
    public function getStatement(Request $request, Response $response, array $args): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                $this->logger->warning('Customer statement - missing tenant ID');
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $customerId = isset($args['id']) ? (int) $args['id'] : 0;
            if ($customerId <= 0) {
                $this->logger->warning('Customer statement - missing customer ID', ['tenant_id' => $tenantId]);
                return $this->errorResponse($response, 'مطلوب رقم العميل', 400);
            }

            $this->logger->info('Customer statement request', [
                'tenant_id' => $tenantId,
                'customer_id' => $customerId
            ]);

            $stmt = $this->db->prepare("
                SELECT account_id
                FROM customers
                WHERE id = :id AND tenant_id = :tenant_id
                LIMIT 1
            ");
            $stmt->execute([
                ':id' => $customerId,
                ':tenant_id' => $tenantId
            ]);

            $accountId = $stmt->fetchColumn();

            if (!$accountId) {
                $this->logger->warning('Customer statement - account not found', [
                    'tenant_id' => $tenantId,
                    'customer_id' => $customerId
                ]);

                return $this->errorResponse($response, 'لم يتم العثور على كشف حساب العميل', 404);
            }

            $this->logger->info('Customer statement account resolved', [
                'tenant_id' => $tenantId,
                'customer_id' => $customerId,
                'account_id' => $accountId
            ]);

            $accountStatementHandler = new AccountStatementHandler($this->db);

            return $accountStatementHandler->getStatement($request, $response, [
                'account_id' => $accountId,
                'party_type' => 'customer',
                'party_id' => $customerId
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Customer statement error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'tenant_id' => $tenantId ?? 'unknown',
                'customer_id' => $customerId ?? 'unknown'
            ]);

            return $this->errorResponse($response, 'حدث خطأ أثناء جلب كشف حساب العميل', 500);
        }
    }

    /** Write customer activity to the application log and audit trail. */
    private function logAction(string $action, array $details = []): void
    {
        try {
            $tenantId = isset($details['tenant_id']) ? (int) $details['tenant_id'] : null;
            $userId   = isset($details['user_id']) ? (int) $details['user_id'] : null;
            $entityId = isset($details['customer_id']) ? (int) $details['customer_id'] : null;

            $this->logger->info('Customer action logged', [
                'action'      => $action,
                'tenant_id'   => $tenantId,
                'user_id'     => $userId,
                'customer_id' => $entityId,
            ]);

            $this->audit->logAction(
                $action,
                'customers',
                $entityId,
                $details,
                $tenantId,
                $userId
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to log customer action', [
                'action' => $action,
                'error'  => $e->getMessage(),
            ]);
        }
    }

    /** Resolve the tenant's configured currency for payment records. */
    private function getCompanyCurrency(int $tenantId): string
    {
        return (new CurrencyService($this->db))->getCompanyCurrency($tenantId);
    }

    /** List active tenant customers, optionally filtered by branch, with ledger balances. */
    public function getCustomers(Request $request, Response $response): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                $this->logger->warning('Customers list - missing tenant ID');
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $this->logger->info('Customers list request', ['tenant_id' => $tenantId]);

            $queryParams = $request->getQueryParams();
            $branchId = isset($queryParams['branch_id']) && $queryParams['branch_id'] !== ''
                ? (int) $queryParams['branch_id']
                : null;

            $sql = "
                SELECT
                    c.id,
                    c.tenant_id,
                    c.branch_id,
                    c.name,
                    c.phone,
                    c.tax_number,
                    c.credit_limit,
                    c.address,
                    c.email,
                    c.active,
                    c.account_id,
                    (
                        COALESCE((
                            SELECT SUM(jel.debit_amount - jel.credit_amount)
                            FROM journal_entry_lines jel
                            WHERE jel.account_id = c.account_id
                              AND jel.tenant_id = c.tenant_id
                        ), 0)
                    ) AS balance
                FROM customers c
                WHERE c.active = 1
                  AND c.tenant_id = :tenant_id
            ";

            $bindings = [':tenant_id' => $tenantId];

            if ($branchId !== null) {
                $sql .= ' AND c.branch_id = :branch_id';
                $bindings[':branch_id'] = $branchId;
            }

            $stmt = $this->db->prepare($sql);
            $stmt->execute($bindings);

            $customers = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($customers as &$c) {
                $c['balance'] = (float)($c['balance'] ?? 0);
            }
            unset($c);

            $this->logger->info('Customers list retrieved successfully', [
                'tenant_id' => $tenantId,
                'count' => count($customers)
            ]);

            return $this->successResponse($response, $customers, 200);
        } catch (\Throwable $e) {
            $this->logger->error('Customers list error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'tenant_id' => $tenantId ?? 'unknown'
            ]);

            return $this->errorResponse($response, 'حدث خطأ أثناء جلب قائمة العملاء', 500);
        }
    }

    /** Fetch a tenant customer with its ledger balance and approved sales returns total. */
    public function getCustomer(Request $request, Response $response, array $args): Response
    {
        $id = isset($args['id']) ? (int) $args['id'] : 0;
        $tenantId = $this->extractTenantId($request);

        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
        }
        if ($id <= 0) {
            return $this->errorResponse($response, 'مطلوب رقم العميل', 400);
        }

        try {
            $stmt = $this->db->prepare("
                SELECT
                    c.id, c.tenant_id, c.branch_id, c.name, c.phone,
                    c.tax_number, c.credit_limit, c.address, c.email,
                    c.active, c.account_id,
                    (
                        COALESCE((
                            SELECT SUM(jel.debit_amount - jel.credit_amount)
                            FROM journal_entry_lines jel
                            WHERE jel.account_id = c.account_id
                              AND jel.tenant_id = c.tenant_id
                        ), 0)
                    ) AS balance,
                    (
                        COALESCE((
                            SELECT SUM(r.grand_total)
                            FROM returns r
                            WHERE r.customer_id = c.id
                              AND r.tenant_id = c.tenant_id
                              AND r.return_type = 'sale'
                              AND r.status IN ('approved', 'completed')
                        ), 0)
                    ) AS total_returns
                FROM customers c
                WHERE c.id = :id AND c.tenant_id = :tenant_id
                LIMIT 1
            ");
            $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$customer) {
                return $this->errorResponse($response, 'لم يتم العثور على العميل', 404);
            }

            $customer['balance'] = (float)($customer['balance'] ?? 0);
            $customer['total_returns'] = (float)($customer['total_returns'] ?? 0);
            return $this->successResponse($response, $customer, 200);
        } catch (\Throwable $e) {
            $this->logger->error('Get customer error', [
                'message' => $e->getMessage(),
                'customer_id' => $id,
                'tenant_id' => $tenantId
            ]);
            return $this->errorResponse($response, 'حدث خطأ أثناء جلب بيانات العميل', 500);
        }
    }

    /** Create a customer and its accounting sub-account in one transaction. */
    public function createCustomer(Request $request, Response $response): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                $this->logger->warning('Customer create - missing tenant ID');
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $data = $request->getParsedBody() ?? [];
            $data = is_array($data) ? $data : [];
            $userId = $this->extractUserId($request);

            if (empty($data['name'])) {
                return $this->errorResponse($response, 'اسم العميل مطلوب', 400);
            }

            $this->logger->info('Customer creation request', [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'customer_name' => $data['name'],
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null
            ]);

            $this->db->beginTransaction();

            $accountMgmt = new AccountManagementService($this->db);
            $accountId = $accountMgmt->createPartyAccount('customer', (string) $data['name'], (int) $tenantId);
            if (!$accountId) {
                throw new \Exception('Failed to create a sub-account for the customer.');
            }

            $this->logger->debug('Customer account created', [
                'tenant_id' => $tenantId,
                'account_id' => $accountId,
                'customer_name' => $data['name']
            ]);

            $branchId = isset($data['branch_id']) && $data['branch_id'] !== '' && $data['branch_id'] !== null
                ? (int) $data['branch_id']
                : null;

            $sql = "
                INSERT INTO customers (
                    tenant_id,
                    branch_id,
                    name,
                    phone,
                    tax_number,
                    credit_limit,
                    address,
                    email,
                    active,
                    account_id
                ) VALUES (
                    :tenant_id,
                    :branch_id,
                    :name,
                    :phone,
                    :tax_number,
                    :credit_limit,
                    :address,
                    :email,
                    :active,
                    :account_id
                )
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':tenant_id' => $tenantId,
                ':branch_id' => $branchId,
                ':name' => $data['name'],
                ':phone' => $data['phone'] ?? null,
                ':tax_number' => $data['tax_number'] ?? null,
                ':credit_limit' => $data['credit_limit'] ?? 0,
                ':address' => $data['address'] ?? null,
                ':email' => $data['email'] ?? null,
                ':active' => $data['active'] ?? 1,
                ':account_id' => $accountId
            ]);

            $data['id'] = (int) $this->db->lastInsertId();
            $data['account_id'] = (int) $accountId;

            $this->db->commit();

            $this->logAction('customer_created', [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'customer_id' => $data['id'],
                'customer_name' => $data['name'],
                'account_id' => $accountId
            ]);

            $this->logger->info('Customer created successfully', [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'customer_id' => $data['id'],
                'customer_name' => $data['name'],
                'account_id' => $accountId
            ]);

            return $this->successResponse($response, $data, 201);
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
                $this->logger->warning('Customer creation transaction rolled back', [
                    'tenant_id' => $tenantId ?? 'unknown',
                    'customer_name' => $data['name'] ?? 'unknown'
                ]);
            }

            $this->logger->error('Customer creation failed', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'tenant_id' => $tenantId ?? 'unknown',
                'user_id' => $userId ?? 'unknown',
                'customer_name' => $data['name'] ?? 'unknown'
            ]);

            return $this->errorResponse($response, 'حدث خطأ أثناء إنشاء العميل', 500);
        }
    }

    /** Replace the customer's contact and branch fields; the linked account is unchanged. */
    public function updateCustomer(Request $request, Response $response, array $args): Response
    {
        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
        }

        $id = isset($args['id']) ? (int) $args['id'] : 0;
        if ($id <= 0) {
            return $this->errorResponse($response, 'مطلوب رقم العميل', 400);
        }

        $data = $request->getParsedBody() ?? [];
        $data = is_array($data) ? $data : [];
        $userId = $this->extractUserId($request);

        try {
            $this->db->beginTransaction();

            $branchId = isset($data['branch_id']) && $data['branch_id'] !== '' && $data['branch_id'] !== null
                ? (int) $data['branch_id']
                : null;

            $sql = "
                UPDATE customers
                SET
                    branch_id = :branch_id,
                    name = :name,
                    phone = :phone,
                    tax_number = :tax_number,
                    credit_limit = :credit_limit,
                    address = :address,
                    email = :email,
                    active = :active
                WHERE id = :id
                  AND tenant_id = :tenant_id
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':branch_id' => $branchId,
                ':name' => $data['name'] ?? null,
                ':phone' => $data['phone'] ?? null,
                ':tax_number' => $data['tax_number'] ?? null,
                ':credit_limit' => $data['credit_limit'] ?? 0,
                ':address' => $data['address'] ?? null,
                ':email' => $data['email'] ?? null,
                ':active' => $data['active'] ?? 1,
                ':id' => $id,
                ':tenant_id' => $tenantId
            ]);

            $this->db->commit();

            try {
                $this->logAction('customer_updated', [
                    'customer_id' => $id,
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'customer_name' => $data['name'] ?? null
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('logAction failed', ['message' => $e->getMessage()]);
            }

            $this->logger->info('Customer updated successfully', [
                'tenant_id' => $tenantId,
                'customer_id' => $id,
                'user_id' => $userId
            ]);

            return $this->successResponse($response, $data, 200);
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                try {
                    $this->db->rollBack();
                    $this->logger->info('Customer update transaction rolled back successfully', [
                        'tenant_id' => $tenantId,
                        'customer_id' => $id
                    ]);
                } catch (\Throwable $rollbackError) {
                    $this->logger->error('Error during rollback', [
                        'message' => $rollbackError->getMessage()
                    ]);
                }
            }

            $this->logger->error('Customer update failed', [
                'message' => $e->getMessage(),
                'tenant_id' => $tenantId,
                'customer_id' => $id
            ]);

            return $this->errorResponse($response, 'فشل في تحديث العميل', 500);
        }
    }

    /** Delete the customer and sub-account only when no journal lines reference the account. */
    public function deleteCustomer(Request $request, Response $response, array $args): Response
    {
        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
        }

        try {
            $id = isset($args['id']) ? (int) $args['id'] : 0;
            if ($id <= 0) {
                return $this->errorResponse($response, 'مطلوب رقم العميل', 400);
            }

            $this->db->beginTransaction();

            $stmt = $this->db->prepare("
                SELECT account_id
                FROM customers
                WHERE id = :id AND tenant_id = :tenant_id
            ");
            $stmt->execute([
                ':id' => $id,
                ':tenant_id' => $tenantId
            ]);

            $customerAccountId = $stmt->fetchColumn();

            if (!$customerAccountId) {
                throw new \Exception('لم يتم العثور على العميل');
            }

            $stmt = $this->db->prepare("
                SELECT COUNT(*)
                FROM journal_entry_lines
                WHERE account_id = :account_id
                  AND tenant_id = :tenant_id
            ");
            $stmt->execute([
                ':account_id' => $customerAccountId,
                ':tenant_id' => $tenantId
            ]);

            $count = (int) $stmt->fetchColumn();

            if ($count > 0) {
                $this->db->rollBack();
                return $this->errorResponse(
                    $response,
                    'لا يمكن حذف العميل لوجود حركات محاسبية مرتبطة به. سدّد الرصيد المستحق إن وُجد، ثم عطّل العميل بدلًا من حذفه للحفاظ على السجل المالي.',
                    409
                );
            }

            $stmt = $this->db->prepare("
                DELETE FROM accounts
                WHERE id = :account_id
                  AND tenant_id = :tenant_id
            ");
            $stmt->execute([
                ':account_id' => $customerAccountId,
                ':tenant_id' => $tenantId
            ]);

            $stmt = $this->db->prepare("
                DELETE FROM customers
                WHERE id = :id
                  AND tenant_id = :tenant_id
            ");
            $stmt->execute([
                ':id' => $id,
                ':tenant_id' => $tenantId
            ]);

            $this->db->commit();

            try {
                $this->logAction('customer_deleted', [
                    'customer_id' => $id,
                    'tenant_id' => $tenantId
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('logAction failed', ['message' => $e->getMessage()]);
            }

            return $this->successResponse($response, [], 200);
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                try {
                    $this->db->rollBack();
                    $this->logger->info('Customer deletion transaction rolled back successfully', [
                        'tenant_id' => $tenantId,
                        'customer_id' => $id ?? null
                    ]);
                } catch (\Throwable $rollbackError) {
                    $this->logger->error('Error during rollback', [
                        'message' => $rollbackError->getMessage(),
                        'tenant_id' => $tenantId
                    ]);
                }
            }

            $this->logger->error('Customer deletion failed', [
                'message' => $e->getMessage(),
                'tenant_id' => $tenantId,
                'customer_id' => $id ?? null
            ]);

            return $this->errorResponse($response, 'حدث خطأ أثناء حذف العميل', 500);
        }
    }

    /**
     * Record a customer payment, optionally linked to a sale.
     *
     * The operation posts the accounting entry, allocates unlinked payments to
     * open sales, and records cash-session activity when the payment is cash.
     */
    public function addPayment(Request $request, Response $response, array $args = []): Response
    {
        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
        }

        $customerId = isset($args['id']) ? (int) $args['id'] : 0;
        if ($customerId <= 0) {
            return $this->errorResponse($response, 'مطلوب رقم العميل', 400);
        }

        $data = $request->getParsedBody();
        $data = is_array($data) ? $data : [];

        $idempotencyKey = trim($request->getHeaderLine('Idempotency-Key'));
        if (strlen($idempotencyKey) > 128) {
            return $this->errorResponse($response, 'مفتاح منع التكرار أطول من الحد المسموح.', 400);
        }

        foreach (['amount', 'payment_method_id'] as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                return $this->errorResponse($response, "حقل مطلوب: {$field}", 400);
            }
        }
        if (
            !is_scalar($data['amount'])
            || !is_numeric($data['amount'])
            || !is_scalar($data['payment_method_id'])
            || !is_numeric($data['payment_method_id'])
            || (isset($data['payment_date']) && !is_scalar($data['payment_date']))
            || (isset($data['reference_number']) && !is_scalar($data['reference_number']))
            || (isset($data['notes']) && !is_scalar($data['notes']))
            || (isset($data['branch_id']) && $data['branch_id'] !== '' && (!is_scalar($data['branch_id']) || !is_numeric($data['branch_id'])))
            || (isset($data['sale_id']) && $data['sale_id'] !== '' && (!is_scalar($data['sale_id']) || !is_numeric($data['sale_id'])))
            || (isset($data['cost_center_id']) && $data['cost_center_id'] !== '' && (!is_scalar($data['cost_center_id']) || !is_numeric($data['cost_center_id'])))
        ) {
            return $this->errorResponse($response, 'بيانات الدفعة غير صالحة.', 400);
        }

        $amount = (float) $data['amount'];
        if (!is_finite($amount) || $amount <= 0) {
            return $this->errorResponse($response, 'قيمة الدفعة يجب أن تكون أكبر من صفر.', 400);
        }
        $amount = round($amount, 2);
        if ($amount <= 0) {
            return $this->errorResponse($response, 'قيمة الدفعة يجب أن تكون أكبر من صفر بعد التقريب.', 400);
        }
        $paymentMethodId = (int) $data['payment_method_id'];
        if ($paymentMethodId <= 0) {
            return $this->errorResponse($response, 'طريقة الدفع غير صالحة.', 400);
        }
        $paymentDate = (string) ($data['payment_date'] ?? date('Y-m-d'));
        $branchId = !empty($data['branch_id']) ? (int) $data['branch_id'] : null;
        $saleId = !empty($data['sale_id']) ? (int) $data['sale_id'] : null;
        $referenceNumber = isset($data['reference_number'])
            ? (string) $data['reference_number']
            : 'CP-' . date('ymdHis');
        $notes = (string) ($data['notes'] ?? '');

        $fingerprintJson = json_encode([
            'customer_id' => $customerId,
            'amount' => $amount,
            'payment_date' => $paymentDate,
            'payment_method_id' => $paymentMethodId,
            'branch_id' => $branchId,
            'sale_id' => $saleId,
            'cost_center_id' => isset($data['cost_center_id']) ? (int) $data['cost_center_id'] : null,
            'reference_number' => isset($data['reference_number']) ? (string) $data['reference_number'] : null,
            'notes' => $notes,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($fingerprintJson === false) {
            return $this->errorResponse($response, 'بيانات الدفعة غير صالحة.', 400);
        }
        $requestFingerprint = hash('sha256', $fingerprintJson);

        $idempotency = $idempotencyKey !== ''
            ? new \App\Services\IdempotencyService($this->db)
            : null;
        if ($idempotency !== null) {
            $cached = $idempotency->checkStrict((int) $tenantId, $idempotencyKey);
            if ($cached !== null) {
                if (!hash_equals($requestFingerprint, (string) ($cached['_request_fingerprint'] ?? ''))) {
                    return $this->errorResponse($response, 'مفتاح منع التكرار مستخدم لبيانات دفعة مختلفة.', 409);
                }
                unset($cached['_request_fingerprint']);
                return $this->jsonResponse($response, $cached, 200);
            }
        }

        $stmt = $this->db->prepare("
            SELECT c.*, c.account_id AS customer_account_id
            FROM customers c
            WHERE c.id = ? AND c.tenant_id = ?
        ");
        $stmt->execute([$customerId, $tenantId]);
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$customer) {
            return $this->errorResponse($response, 'لم يتم العثور على العميل', 404);
        }

        if ($saleId !== null) {
            $stmtChk = $this->db->prepare(
                "SELECT id FROM sales WHERE id = ? AND customer_id = ? AND tenant_id = ? LIMIT 1"
            );
            $stmtChk->execute([$saleId, $customerId, $tenantId]);
            if (!$stmtChk->fetchColumn()) {
                return $this->errorResponse($response, 'فاتورة المبيعات المحددة غير موجودة أو لا تنتمي لهذا العميل.', 400);
            }
        }

        $userId          = $this->extractUserId($request);
        $idempotencyLockName = $idempotency !== null
            && $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? 'smartsys_payment:' . substr(hash('sha256', (int) $tenantId . ':' . $idempotencyKey), 0, 40)
            : null;
        $idempotencyLockAcquired = false;

        try {
            if ($idempotencyLockName !== null) {
                $lock = $this->db->prepare('SELECT GET_LOCK(?, 10)');
                $lock->execute([$idempotencyLockName]);
                if ((int) $lock->fetchColumn() !== 1) {
                    throw new \RuntimeException('تعذر حجز مفتاح منع التكرار للدفعة.');
                }
                $idempotencyLockAcquired = true;
            }

            $this->db->beginTransaction();
            if ($idempotency !== null) {
                $cached = $idempotency->claimStrict(
                    (int) $tenantId,
                    $idempotencyKey,
                    $requestFingerprint
                );
                if ($cached !== null) {
                    $cachedFingerprint = $cached['_request_fingerprint'] ?? null;
                    $matches = is_string($cachedFingerprint)
                        && hash_equals($requestFingerprint, $cachedFingerprint)
                        && empty($cached['_processing']);
                    $this->db->commit();
                    if (!$matches) {
                        return $this->errorResponse($response, 'مفتاح منع التكرار مستخدم لبيانات دفعة مختلفة.', 409);
                    }
                    unset($cached['_request_fingerprint']);
                    return $this->jsonResponse($response, $cached, 200);
                }
            }

            $forUpdate = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $customerLock = $this->db->prepare(
                "SELECT id FROM customers WHERE id = ? AND tenant_id = ? LIMIT 1{$forUpdate}"
            );
            $customerLock->execute([$customerId, $tenantId]);
            if (!$customerLock->fetchColumn()) {
                throw new \App\Exceptions\NotFoundException('لم يتم العثور على العميل');
            }

            $isSessionsEnabled = $this->isSessionsEnabled((int) $tenantId);
            $isExempt          = $this->isCashierSessionExempt($request);
            $isCash            = $this->isCashMethod($paymentMethodId, (int) $tenantId);
            $sessionId         = null;

            if ($isSessionsEnabled && $isCash && $amount > 0 && !$isExempt) {
                if (!$branchId) {
                    throw new \Exception('يجب تحديد المخزن لإتمام الدفعة النقدية للكاشير.');
                }
                $sessionId = $this->requireOpenCashierSession(
                    (int) $tenantId,
                    (int) $branchId,
                    $userId ? (int) $userId : null
                );
            } elseif (($isExempt || !$isSessionsEnabled) && $branchId) {
                $sessionId = $this->findOpenCashierSession((int) $tenantId, (int) $branchId, null);
            }

            try {
                $costCenterId = $this->costCenterService->resolve(
                    (int) $tenantId,
                    $userId ? (int) $userId : null,
                    isset($data['cost_center_id']) ? (int) $data['cost_center_id'] : null,
                    $branchId ? (int) $branchId : null
                );
            } catch (\Exception $e) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                return $this->errorResponse(
                    $response,
                    'لا توجد مراكز تكلفة. يرجى إنشاء مركز تكلفة واحد على الأقل.',
                    400
                );
            }

            // The posting method and payment type depend on whether a sale is selected.
            $paymentType = $saleId !== null ? 'sale' : 'customer';
            $currency    = $this->getCompanyCurrency((int) $tenantId);

            $insertPayment = function (?int $journalEntryId) use (
                $tenantId,
                $customerId,
                $saleId,
                $branchId,
                $amount,
                $paymentDate,
                $paymentMethodId,
                $referenceNumber,
                $notes,
                $userId,
                $paymentType,
                $costCenterId,
                $currency,
                $sessionId
            ): int {
                $stmt = $this->db->prepare("
                INSERT INTO payments (
                    tenant_id,
                    customer_id,
                    sale_id,
                    amount,
                    payment_date,
                    payment_method_id,
                    reference_number,
                    notes,
                    created_by,
                    is_draft,
                    status,
                    type,
                    created_at,
                    cost_center_id,
                    currency,
                    session_id,
                    branch_id,
                    journal_entry_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 'completed', ?, NOW(), ?, ?, ?, ?, ?)
            ");
                $stmt->execute([
                    (int) $tenantId,
                    $customerId,
                    $saleId,
                    $amount,
                    $paymentDate,
                    $paymentMethodId,
                    $referenceNumber,
                    $notes,
                    $userId,
                    $paymentType,
                    $costCenterId,
                    $currency,
                    $sessionId,
                    $branchId,
                    $journalEntryId,
                ]);

                return (int) $this->db->lastInsertId();
            };

            if ($saleId !== null) {
                $paymentId = $insertPayment(null);
                if ($paymentId <= 0) {
                    throw new \Exception('فشل إنشاء سجل دفعة العميل.');
                }

                $jeId = $this->accounting->postSalePaymentJournalEntry(
                    (int) $tenantId,
                    $saleId,
                    $paymentId,
                    $amount,
                    $paymentDate,
                    $paymentMethodId,
                    $userId,
                    $costCenterId,
                    $idempotencyKey !== '' ? $idempotencyKey : null
                );
                $this->db->prepare(
                    'UPDATE payments SET journal_entry_id = ? WHERE id = ? AND tenant_id = ?'
                )->execute([$jeId, $paymentId, (int) $tenantId]);
            } else {
                $customerAccountId = $customer['account_id'] ?? null;
                if (!$customerAccountId) {
                    throw new \Exception('لم يتم العثور على حساب العميل.');
                }

                $paymentId = $insertPayment(null);
                if ($paymentId <= 0) {
                    throw new \Exception('فشل إنشاء سجل دفعة العميل.');
                }

                $jeId = $this->accounting->postDebtPaymentJournalEntry(
                    (int) $tenantId,
                    $customerId,
                    (int) $customerAccountId,
                    $amount,
                    $paymentDate,
                    $paymentMethodId,
                    $userId,
                    $costCenterId,
                    $paymentId,
                    $idempotencyKey !== '' ? $idempotencyKey : null
                );
                $this->db->prepare(
                    'UPDATE payments SET journal_entry_id = ? WHERE id = ? AND tenant_id = ?'
                )->execute([$jeId, $paymentId, (int) $tenantId]);
            }

            if (!$jeId) {
                throw new \Exception('فشل في تسجيل القيد المحاسبي للدفعة.');
            }

            $allocationSaleIds = [];
            if ($saleId === null) {
                $allocationSaleIds = (new CustomerPaymentAllocationService($this->db))->allocatePayment(
                    (int) $tenantId,
                    $customerId,
                    $paymentId,
                    $amount,
                    $branchId ? (int) $branchId : null
                );
            }

            // Keep cash-session and liquidity reports aligned with cash payments.
            if ($isCash) {
                $refType = $saleId !== null ? 'sale' : 'debt_payment';
                $refId   = $saleId !== null ? $saleId : $paymentId;
                $this->db->prepare("
                    INSERT INTO cash_transactions (
                        customer_id, amount, type,
                        reference_type, reference_id,
                        payment_method_id, created_by, created_at,
                        tenant_id, status,
                        session_id, cost_center_id, journal_entry_id
                    ) VALUES (?, ?, 'income', ?, ?, ?, ?, NOW(), ?, 'completed', ?, ?, ?)
                ")->execute([
                    $customerId, $amount,
                    $refType, $refId,
                    $paymentMethodId, $userId,
                    (int) $tenantId,
                    $sessionId, $costCenterId, $jeId,
                ]);
            }

            if ($saleId !== null) {
                $statusResult = $this->accounting->recalculateSaleStatus((int) $tenantId, $saleId);
            }
            foreach ($allocationSaleIds as $allocatedSaleId) {
                $this->accounting->recalculateSaleStatus((int) $tenantId, $allocatedSaleId);
            }

            $responseData = [
                'status' => 'success',
                'message' => 'تم تسجيل الدفعة بنجاح',
                'data' => [
                    'payment_id' => $paymentId,
                    'journal_entry_id' => $jeId,
                    'customer_id' => $customerId,
                    'amount' => $amount,
                    'sale_id' => $saleId,
                    'allocated_sale_ids' => $allocationSaleIds,
                    'reference_number' => $referenceNumber,
                    'notes' => $notes,
                ],
            ];
            if ($idempotency !== null) {
                $cachedResponse = $responseData;
                $cachedResponse['_request_fingerprint'] = $requestFingerprint;
                $idempotency->storeStrict((int) $tenantId, $idempotencyKey, $paymentId, $cachedResponse);
            }

            $this->db->commit();

            try {
                $this->audit->logAction(
                    'customer_payment_added',
                    'customers',
                    $customerId,
                    [
                        'tenant_id'          => (int) $tenantId,
                        'user_id'            => $userId ?? null,
                        'branch_id'          => $branchId ?? null,
                        'session_id'         => $sessionId ?? null,
                        'customer_id'        => $customerId,
                        'sale_id'            => $saleId,
                        'payment_id'         => $paymentId,
                        'amount'             => $amount,
                        'payment_method_id'  => $paymentMethodId,
                        'payment_date'       => $paymentDate,
                        'journal_entry_id'   => $jeId,
                        'payment_type'       => $paymentType,
                    ],
                    (int) $tenantId,
                    $userId
                );
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to log audit trail for customer payment', [
                    'payment_id'  => $paymentId,
                    'customer_id' => $customerId,
                    'error'       => $e->getMessage()
                ]);
            }

            return $this->jsonResponse($response, $responseData, 201);

        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logger->error('Customer payment failed', [
                'message'     => $e->getMessage(),
                'tenant_id'   => $tenantId,
                'customer_id' => $customerId,
            ]);
            return $this->errorResponse($response, 'فشل تسجيل الدفعة: ' . $e->getMessage(), 500);
        } finally {
            if ($idempotencyLockAcquired && $idempotencyLockName !== null) {
                try {
                    $release = $this->db->prepare('SELECT RELEASE_LOCK(?)');
                    $release->execute([$idempotencyLockName]);
                    if ((int) $release->fetchColumn() !== 1) {
                        $this->logger->warning('Failed to release customer payment idempotency lock', [
                            'tenant_id' => $tenantId,
                        ]);
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('Failed to release customer payment idempotency lock', [
                        'tenant_id' => $tenantId,
                        'message' => $e->getMessage(),
                    ]);
                }
            }
        }
    }
}
