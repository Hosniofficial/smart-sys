<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use PDOException;
use Exception;
use Throwable;
use App\Services\AccountingService;
use App\Services\MonologHandler;
use App\Services\FinancialCalculationService;
use App\Services\CostCenter\CostCenterService;
use App\Repositories\SettingsRepository;
use App\Repositories\PurchaseRepository;
use App\Repositories\PaymentRepository;
use App\Utils\DateHelper;

/**
 * Coordinates tenant purchase balances, inventory movements, supplier payments, and accounting entries.
 */
class PurchaseService
{
    private PDO $db;
    private int $tenantId;
    private ?int $userId;
    private AccountingService $accounting;
    private FinancialCalculationService $financialCalcService;
    private CostCenterService $costCenterService;
    private SettingsRepository $settingsRepo;
    private PurchaseRepository $purchaseRepo;
    private PaymentRepository $paymentRepo;
    private $logger;

    public function __construct(PDO $db, int $tenantId, ?int $userId = null)
    {
        $this->db                   = $db;
        $this->tenantId             = $tenantId;
        $this->userId               = $userId;
        $this->accounting           = new AccountingService($db);
        $this->financialCalcService = new FinancialCalculationService($db);
        $this->costCenterService    = new CostCenterService($db, 'purchases');
        $this->settingsRepo         = new SettingsRepository($db);
        $this->purchaseRepo         = new PurchaseRepository($db);
        $this->paymentRepo          = new PaymentRepository($db);
        $this->logger               = MonologHandler::getInstance('purchases');
    }

    /** Derive invoice status from its net total, completed payments, and return credits. */
    public function determinePurchaseStatus(float $netTotal, float $paidAmount, float $returnAmount = 0.0): string
    {
        if ($netTotal <= 0) {
            return 'paid';
        }
        if ($returnAmount >= $netTotal) {
            return 'returned';
        }

        $outstanding = max(0.0, round($netTotal - $paidAmount - $returnAmount, 2));
        if ($outstanding < 0.01) {
            if ($returnAmount > 0.01 && $paidAmount > 0.01) {
                return 'settled_mixed';
            }
            return $returnAmount > 0.01 ? 'closed_by_return' : 'paid';
        }
        if ($paidAmount <= 0) {
            return 'due';
        }
        return 'partial';
    }

    /** Sum purchase-return totals linked to this tenant's invoice. */
    public function getReturnedAmount(int $purchaseId): float
    {
        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(grand_total), 0) FROM returns
             WHERE purchase_id = ? AND tenant_id = ? AND return_type = 'purchase'"
        );
        $stmt->execute([$purchaseId, $this->tenantId]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    /** Recalculate paid_amount and status from recorded payments, invoice total, and returns. */
    public function recalculateBalance(int $purchaseId): void
    {
        $totalPaid    = $this->paymentRepo->getTotalPaidForPurchase($purchaseId, $this->tenantId);
        $totalAmount  = $this->purchaseRepo->getTotalAmount($purchaseId, $this->tenantId);
        $returnAmount = $this->getReturnedAmount($purchaseId);
        $status       = $this->determinePurchaseStatus($totalAmount, $totalPaid, $returnAmount);
        $this->purchaseRepo->updateBalance($purchaseId, $this->tenantId, $totalPaid, $status);
    }

    /** Resolve the tenant supplier's linked ledger account. */
    public function getSupplierAccountId(int $supplierId): int
    {
        $stmt = $this->db->prepare(
            "SELECT account_id FROM suppliers WHERE id = ? AND tenant_id = ? LIMIT 1"
        );
        $stmt->execute([$supplierId, $this->tenantId]);
        $accountId = (int) $stmt->fetchColumn();

        if ($accountId <= 0) {
            throw new Exception('لم يتم العثور على حساب المورد.');
        }

        return $accountId;
    }

    /** Resolve the inventory ledger account for the requested branch. */
    public function getInventoryAccountId(?int $branchId = null): int
    {
        $id = $this->accounting->resolveInventoryAccountForBranch($this->tenantId, $branchId) ?? 0;
        if ($id > 0) {
            return $id;
        }
        throw new Exception('لم يتم تحديد حساب المخزون المناسب.');
    }

    /** Resolve the configured input-tax account, falling back to account code 2201. */
    public function getVatInputAccountId(): int
    {
        $id = $this->settingsRepo->getInt($this->tenantId, 'vat.input_account_id', 0);
        if ($id > 0) {
            return $id;
        }

        $id = $this->accounting->getAccountByCode($this->tenantId, '2201') ?? 0;
        if ($id > 0) {
            return $id;
        }

        throw new Exception('حساب ضريبة المدخلات غير معرف (vat.input_account_id أو الحساب 2201).');
    }

    /** Resolve the liquidity ledger account linked to a payment method. */
    public function resolveLiquidityAccountId(int $paymentMethodId): int
    {
        $accountId = $this->accounting->resolveLiquidityAccount($paymentMethodId, $this->tenantId);

        if ($accountId === null) {
            throw new Exception('طريقة الدفع آجلة (credit) — لا يوجد حساب سيولة لفاتورة الشراء.');
        }

        return $accountId;
    }

    /** Validate an outgoing purchase payment before its journal entry is posted. */
    public function validatePaymentLiquidity(int $paymentMethodId, float $amount, ?int $sessionId): void
    {
        if ($amount <= 0) {
            return;
        }

        try {
            $liquidityPolicy = new \App\Services\LiquidityPolicyService($this->db, $this->logger);
            $validation = $liquidityPolicy->validateLiquidityBalance(
                tenantId: $this->tenantId,
                paymentMethodId: $paymentMethodId,
                debitAmount: $amount,
                cashierSessionId: $sessionId
            );

            if ($validation['allowed']) {
                return;
            }

            $currentBalance = isset($validation['current_balance'])
                ? (float) $validation['current_balance']
                : null;
            $shortfall = $validation['shortfall'] ?? ($currentBalance !== null
                ? max(0.0, round($amount - $currentBalance, 2))
                : null);
            $policyReason = match ($validation['reason']) {
                'Cash account cannot go negative (setting disabled)' => 'إعداد السماح بأن يصبح رصيد الصندوق سالبًا غير مفعّل.',
                'Cash account cannot go negative when cashier sessions are disabled' => 'لا يمكن أن يصبح رصيد الصندوق سالبًا عند تعطيل إلزام جلسات الكاشير.',
                'Cash account requires active cashier session for negative balance' => 'السماح بالسالب مفعّل، لكن يلزم فتح جلسة كاشير للفرع المختار قبل تسجيل الدفعة.',
                'Cashier session not open (required for intra-day negative balance)' => 'جلسة الكاشير غير مفتوحة أو أُغلقت. افتح جلسة للفرع المختار ثم أعد المحاولة.',
                'Payment method not found or inactive' => 'طريقة الدفع المحددة غير موجودة أو غير مفعّلة.',
                'Payment method has no linked liquidity account' => 'طريقة الدفع المحددة غير مربوطة بحساب سيولة.',
                'Payment method account type does not match its kind' => 'تصنيف حساب طريقة الدفع لا يطابق نوعها. راجع إعدادات طريقة الدفع.',
                'Liquidity account must be an asset account' => 'الحساب المرتبط بطريقة الدفع ليس حساب أصل صالحًا للصندوق أو البنك.',
                'Credit method has no liquidity account' => 'طريقة الدفع آجلة ولا تمثل دفعة نقدية للمورد.',
                'Debit amount must be greater than zero' => 'قيمة الدفعة يجب أن تكون أكبر من صفر.',
                default => 'سياسة الحساب المرتبط بطريقة الدفع لا تسمح بإتمام هذه الدفعة.',
            };
            $message = $currentBalance === null
                ? sprintf('تعذر تسجيل دفعة الشراء. %s لم يُرحّل قيد الفاتورة ولم تُسجل الدفعة.', $policyReason)
                : sprintf(
                    'تعذّر تسجيل دفعة الشراء. %s الرصيد النقدي قبل العملية: %s، المبلغ المطلوب: %s، العجز: %s.',
                    $policyReason,
                    number_format($currentBalance, 2),
                    number_format($amount, 2),
                    number_format((float) $shortfall, 2)
                );

            throw new \App\Exceptions\InsufficientLiquidityException(
                $message,
                402,
                [
                    'current_balance'     => $currentBalance,
                    'required_amount'     => $amount,
                    'shortfall'           => $validation['shortfall'] ?? $shortfall,
                    'account_id'          => $validation['account_id'] ?? null,
                    'payment_method_id'   => $paymentMethodId,
                    'payment_method_kind' => $validation['payment_method_kind'] ?? null,
                    'policy_reason'       => $validation['reason'],
                ]
            );
        } catch (\App\Exceptions\InsufficientLiquidityException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('Liquidity validation error — payment blocked', [
                'amount'            => $amount,
                'payment_method_id' => $paymentMethodId,
                'error'             => $e->getMessage(),
            ]);
            throw new \Exception(
                'تعذّر التحقق من رصيد الحساب قبل تسجيل الدفعة: ' . $e->getMessage()
            );
        }
    }

    /** Generate a tenant/day invoice number; the caller must provide transaction scope for row locking. */
    public function generateInvoiceNumber(?string $invoiceDate = null): string
    {
        if (!$this->db->inTransaction()) {
            throw new \LogicException('Purchase invoice numbers must be generated inside a transaction.');
        }

        $tenantLock = $this->db->prepare('SELECT id FROM tenants WHERE id = ? FOR UPDATE');
        $tenantLock->execute([$this->tenantId]);
        if ($tenantLock->fetchColumn() === false) {
            throw new \RuntimeException('Cannot generate a purchase invoice number for an unknown tenant.');
        }

        $invoiceDateTime = DateHelper::normalize($invoiceDate);
        $day      = date('Y-m-d', strtotime($invoiceDateTime));
        $dayShort = date('ymd', strtotime($invoiceDateTime));

        $maxAttempts = 3;
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            try {
                $attempt++;

                $stmt = $this->db->prepare(
                    "SELECT COUNT(*) FROM purchases
                     WHERE tenant_id = ? AND DATE(invoice_date) = ?"
                );
                $stmt->execute([
                    $this->tenantId,
                    $day
                ]);

                $count = (int) $stmt->fetchColumn() + 1;
                $invoiceNumber = sprintf('PUR-%s-%03d', $dayShort, $count);

                return $invoiceNumber;

            } catch (PDOException $e) {
                $msg = $e->getMessage();
                if (($e->getCode() === '23000' || strpos($msg, 'Duplicate entry') !== false) && $attempt < $maxAttempts) {
                    usleep(100000);
                    continue;
                }
                throw $e;
            }
        }

        throw new PDOException('Failed to generate unique invoice number after ' . $maxAttempts . ' attempts');
    }

    /** Post the purchase journal entry through AccountingService and link it to the invoice. */
    public function createJournalEntry(array $purchaseData, int $supplierAccountId): int
    {
        $purchaseId      = (int) ($purchaseData['id'] ?? 0);
        $invoiceNumber   = (string) ($purchaseData['invoice_number'] ?? 'غير معروف');
        $invoiceDate     = (string) ($purchaseData['invoice_date'] ?? date('Y-m-d H:i:s'));
        $branchId        = !empty($purchaseData['branch_id']) ? (int) $purchaseData['branch_id'] : null;
        $userId          = isset($purchaseData['user_id']) ? (int) $purchaseData['user_id'] : $this->userId;
        $costCenterId    = !empty($purchaseData['cost_center_id'])
            ? (int) $purchaseData['cost_center_id']
            // Use the invoice branch rather than the user's default branch.
            : (int) $this->costCenterService->resolve($this->tenantId, $userId, null, $branchId);
        $totalAmount     = round((float) ($purchaseData['total_amount'] ?? 0), 2);
        $paidAmount      = round((float) ($purchaseData['paid_amount'] ?? 0), 2);
        $taxAmount       = round((float) ($purchaseData['tax_amount'] ?? 0), 2);
        $paymentMethodId = (int) ($purchaseData['payment_method_id'] ?? 0);

        if (empty($paymentMethodId)) {
            throw new Exception('طريقة الدفع مطلوبة لإنشاء القيد المحاسبي للمشتريات.');
        }

        $inventoryAccountId = $this->getInventoryAccountId($branchId);

        $journalEntryId = $this->accounting->postPurchaseJournalEntry(
            $purchaseId,
            $this->tenantId,
            $invoiceNumber,
            $invoiceDate,
            $supplierAccountId,
            $inventoryAccountId,
            $totalAmount,
            $paidAmount,
            $taxAmount,
            $paymentMethodId,
            $userId,
            $costCenterId
        );

        $this->purchaseRepo->setJournalEntryId($purchaseId, $this->tenantId, $journalEntryId);

        return $journalEntryId;
    }

    /** Return the tenant's configured company currency, defaulting to EGP. */
    public function getCompanyCurrency(): string
    {
        return $this->settingsRepo->get($this->tenantId, 'company.currency', 'EGP') ?: 'EGP';
    }

    /** Persist calculated line items and optionally record their stock receipts. */
    public function insertPurchaseItems(
        int $purchaseId,
        array $items,
        float $grossTotal,
        float $discountAmount,
        ?int $branchId,
        ?string $notes,
        bool $includeTracking = true
    ): void {
        $stmt = $this->db->prepare(
            "INSERT INTO purchase_items (
                tenant_id, purchase_id, product_id, unit_id, quantity, price, cost, total,
                discount_amount, tax_rate, tax_amount, subtotal, batch_number, expiry_date,
                serial, branch_id, category_id, created_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        );

        foreach ($items as $item) {
            $itemTotal     = round((float) $item['quantity'] * (float) $item['cost'], 2);
            $discountShare = $grossTotal > 0 ? round(($itemTotal / $grossTotal) * $discountAmount, 2) : 0.0;
            $netPrice      = round($itemTotal - $discountShare, 2);
            $itemTaxRate   = (float) ($item['tax_rate'] ?? 0);
            $taxAmountItem = $itemTaxRate > 0 ? round($netPrice * $itemTaxRate / 100, 2) : 0.0;
            $subtotal      = round($netPrice + $taxAmountItem, 2);

            $stmt->execute([
                $this->tenantId,
                $purchaseId,
                (int) $item['product_id'],
                (int) ($item['unit_id'] ?? 1),
                (float) $item['quantity'],
                (float) $item['price'],
                (float) $item['cost'],
                $itemTotal,
                (float) ($item['discount_amount'] ?? 0),
                $itemTaxRate,
                $taxAmountItem,
                $subtotal,
                $item['batch_number'] ?: null,
                ($item['expiry_date'] !== '' && $item['expiry_date'] !== null) ? $item['expiry_date'] : null,
                $item['serial'] ?: null,
                $branchId,
                $item['category_id'] ?? null,
            ]);

            if ($includeTracking) {
                $this->updateStock(
                    (int) $item['product_id'],
                    (int) ($item['unit_id'] ?? 1),
                    (float) $item['quantity'],
                    $branchId,
                    $purchaseId,
                    'in',
                    $notes,
                    (float) ($item['cost'] ?? 0),
                    $item['batch_number'] ?: null,
                    ($item['expiry_date'] !== '' && $item['expiry_date'] !== null) ? $item['expiry_date'] : null,
                    $item['serial'] ?: null
                );
            }
        }
    }

    /** Record a purchase-linked stock movement and update branch quantity, valuation, and tracking data. */
    public function updateStock(
        int $productId,
        int $unitId,
        float $quantity,
        ?int $branchId,
        int $purchaseId,
        string $type = 'in',
        ?string $notes = null,
        float $unitCost = 0,
        ?string $batchNumber = null,
        ?string $expiryDate = null,
        ?string $serial = null
    ): void {
        if (!$branchId) {
            throw new \Exception('يجب تحديد المخزن لتحديث حركة المخزون.');
        }

        if ($type === 'in') {
            ProductBranchActivationGuard::assertActiveForStock(
                $this->db,
                $this->tenantId,
                $branchId,
                $productId
            );
        }

        $expiryDate = ($expiryDate !== '' && $expiryDate !== null) ? $expiryDate : null;
        $batchNumber = ($batchNumber !== '') ? $batchNumber : null;
        $serial = ($serial !== '') ? $serial : null;

        $totalCost  = round($quantity * $unitCost, 2);
        $branchFrom = $type === 'out' ? $branchId : null;
        $branchTo   = $type === 'in' ? $branchId : null;

        $this->db->prepare(
            "INSERT INTO inventory_transactions (
                tenant_id, product_id, unit_id, branch_from, branch_to, quantity,
                unit_cost, total_cost, movement_type, movement_date, batch_number,
                expiry_date, serial, reference_type, reference_id, notes, user_id, created_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, NOW())"
        )->execute([
            $this->tenantId, $productId, $unitId, $branchFrom, $branchTo, $quantity,
            $unitCost, $totalCost, $type, $batchNumber, $expiryDate, $serial,
            'purchase', $purchaseId, $notes, $this->userId,
        ]);

        $this->db->prepare(
            "INSERT INTO branch_products (tenant_id, branch_id, product_id, quantity, last_update, quantity_cost, gl_reconciled)
             VALUES (?, ?, ?, ?, NOW(), ?, 0)
             ON DUPLICATE KEY UPDATE
                 quantity      = quantity + VALUES(quantity),
                 quantity_cost = quantity_cost + VALUES(quantity_cost),
                 last_update   = NOW()"
        )->execute([$this->tenantId, $branchId, $productId, $quantity, $totalCost]);
        BranchInventoryReconciliationService::markUnreconciled(
            $this->db,
            $this->tenantId,
            $branchId,
            $productId
        );

        if ($type === 'in' && $expiryDate) {
            $chk = $this->db->prepare(
                "SELECT has_expiry_date FROM products WHERE id = ? AND tenant_id = ?"
            );
            $chk->execute([$productId, $this->tenantId]);
            if ((int) $chk->fetchColumn()) {
                $this->db->prepare(
                    "INSERT INTO product_expiry (tenant_id, product_id, branch_id, expiry_date, quantity, batch_number)
                     VALUES (?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE quantity = quantity + ?"
                )->execute([$this->tenantId, $productId, $branchId, $expiryDate, $quantity, $batchNumber, $quantity]);
            }
        }

        if ($type === 'in' && $serial && $quantity > 0) {
            $chk = $this->db->prepare(
                "SELECT has_serial_number FROM products WHERE id = ? AND tenant_id = ?"
            );
            $chk->execute([$productId, $this->tenantId]);
            if ((int) $chk->fetchColumn()) {
                $this->db->prepare(
                    "INSERT INTO product_serials (tenant_id, product_id, branch_id, serial_number, status, transaction_id)
                     VALUES (?, ?, ?, ?, 'in_stock', NULL)
                     ON DUPLICATE KEY UPDATE status = 'in_stock'"
                )->execute([$this->tenantId, $productId, $branchId, $serial]);
            }
        }
    }

    /** Persist and post a purchase payment; the caller owns its transaction. */
    public function recordPayment(
        int $purchaseId,
        int $supplierId,
        float $amount,
        string $paymentDate,
        int $paymentMethodId,
        ?string $referenceNumber,
        ?int $branchId,
        ?int $costCenterId,
        ?int $supplierAccountId,
        ?int $sessionId = null,
        bool $purchaseJeAlreadyCreated = false,
        bool $liquidityAlreadyValidated = false
    ): array {
        if ($amount <= 0) {
            throw new \Exception('قيمة الدفعة يجب أن تكون أكبر من صفر.');
        }

        if (!$liquidityAlreadyValidated) {
            $this->validatePaymentLiquidity($paymentMethodId, $amount, $sessionId);
        }

        $referenceNumber = $referenceNumber ?: ('PP-' . date('ymd-His'));
        $currency        = $this->getCompanyCurrency();

        $this->db->prepare(
            "INSERT INTO payments (
                tenant_id, purchase_id, supplier_id, amount, payment_date,
                payment_method_id, reference_number, created_by, is_draft,
                status, type, created_at, cost_center_id, session_id, currency
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 'completed', 'purchase', NOW(), ?, ?, ?)"
        )->execute([
            $this->tenantId, $purchaseId, $supplierId, $amount, $paymentDate,
            $paymentMethodId, $referenceNumber, $this->userId,
            $costCenterId, $sessionId, $currency,
        ]);

        $paymentId = (int) $this->db->lastInsertId();

        if (!$this->paymentRepo->insertApplication(
            $this->tenantId,
            $paymentId,
            'purchase',
            $purchaseId,
            $amount,
            $this->userId
        )) {
            throw new \RuntimeException('تعذر ربط دفعة المورد بفاتورة الشراء.');
        }

        $journalEntryId = null;

        if ($purchaseJeAlreadyCreated) {
            $stmtPJe = $this->db->prepare(
                "SELECT journal_entry_id FROM purchases WHERE id = ? AND tenant_id = ? LIMIT 1"
            );
            $stmtPJe->execute([$purchaseId, $this->tenantId]);
            $inheritedJeId = (int) $stmtPJe->fetchColumn();
            if ($inheritedJeId <= 0) {
                throw new \RuntimeException('قيد فاتورة الشراء غير موجود لربط الدفعة به.');
            }
            if (!$this->paymentRepo->setJournalEntryId($paymentId, $this->tenantId, $inheritedJeId)) {
                throw new \RuntimeException('تعذر ربط دفعة المورد بقيد فاتورة الشراء.');
            }
            $journalEntryId = $inheritedJeId;
        } else {
            try {
                $liquidityAccountId = $this->resolveLiquidityAccountId($paymentMethodId);
                $desc               = 'دفعة مورد لفاتورة شراء #' . $purchaseId;

                $journalEntryId = $this->accounting->postPayment(
                    $this->tenantId,
                    $paymentId,
                    $amount,
                    'supplier_payment',
                    null,
                    $purchaseId,
                    null,
                    $this->userId,
                    $costCenterId,
                    $supplierAccountId,
                    $liquidityAccountId,
                    $desc
                );

                if (!$journalEntryId) {
                    throw new \RuntimeException('فشل ترحيل قيد دفعة المورد.');
                }
                if (!$this->paymentRepo->setJournalEntryId($paymentId, $this->tenantId, $journalEntryId)) {
                    throw new \RuntimeException('تعذر ربط دفعة المورد بقيدها المحاسبي.');
                }
            } catch (Throwable $e) {
                $this->logger->error('Failed to post purchase payment to journal', [
                    'payment_id' => $paymentId, 'error' => $e->getMessage(),
                ]);
                throw new \RuntimeException('تعذر ترحيل القيد المحاسبي لدفعة المورد.', 0, $e);
            }
        }

        if ($this->accounting->isCashMethod($paymentMethodId, $this->tenantId)) {
            $this->db->prepare(
                "INSERT INTO cash_transactions (
                    tenant_id, supplier_id, amount, type, reference_type, reference_id,
                    payment_method_id, description, created_by, created_at, status, notes,
                    journal_entry_id, session_id, branch_id, cost_center_id
                 ) VALUES (?, ?, ?, 'expense', 'purchase', ?, ?, ?, ?, NOW(), 'completed', ?, ?, ?, ?, ?)"
            )->execute([
                $this->tenantId,
                $supplierId,
                $amount,
                $purchaseId,
                $paymentMethodId,
                'سداد فاتورة شراء #' . $purchaseId,
                $this->userId,
                $referenceNumber,
                $journalEntryId,
                $sessionId,
                $branchId,
                $costCenterId,
            ]);
        }

        return [
            'payment_id'       => $paymentId,
            'journal_entry_id' => $journalEntryId,
            'session_id'       => $sessionId,
        ];
    }

    /** Write an audit event without allowing audit-storage failures to abort the purchase flow. */
    public function auditSafe(string $action, string $entityType, int $entityId, array $payload): void
    {
        try {
            (new \App\Handlers\AuditHandler($this->db))->logAction(
                $action,
                $entityType,
                $entityId,
                $payload,
                $this->tenantId,
                $this->userId
            );
        } catch (Throwable $e) {
            $this->logger->warning('Purchase audit logging failed', [
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Replace an unpaid, unreturned invoice's lines, stock movements, and journal entry.
     *
     * The caller must provide a transaction for the changes and MySQL row lock.
     */
    public function updatePurchase(
        int    $purchaseId,
        array  $purchase,
        array  $data,
        array  $totals,
        string $invoiceDate,
        ?int   $costCenterId = null,
        ?int   $sessionId = null
    ): array {
        $forUpdate = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $purchaseStmt = $this->db->prepare(
            "SELECT * FROM purchases WHERE id = ? AND tenant_id = ? LIMIT 1{$forUpdate}"
        );
        $purchaseStmt->execute([$purchaseId, $this->tenantId]);
        $lockedPurchase = $purchaseStmt->fetch(PDO::FETCH_ASSOC);
        if (!$lockedPurchase) {
            throw new \App\Exceptions\NotFoundException('Purchase not found');
        }
        $purchase = $lockedPurchase;
        if (in_array(strtolower((string) ($purchase['status'] ?? '')), ['canceled', 'cancelled', 'rejected'], true)) {
            throw new \InvalidArgumentException('لا يمكن تعديل فاتورة شراء ملغاة أو مرفوضة.');
        }

        $paymentStmt = $this->db->prepare(
            "SELECT COUNT(*) FROM payments
             WHERE purchase_id = ? AND tenant_id = ? AND status = 'completed' AND is_draft = 0"
        );
        $paymentStmt->execute([$purchaseId, $this->tenantId]);
        if ((int) $paymentStmt->fetchColumn() > 0) {
            throw new \InvalidArgumentException(
                'لا يمكن تعديل فاتورة عليها دفعة مكتملة؛ استخدم مرتجعًا أو سجل دفعة مستقلة.'
            );
        }
        $returnStmt = $this->db->prepare(
            "SELECT COUNT(*) FROM returns
             WHERE purchase_id = ? AND tenant_id = ? AND return_type = 'purchase'"
        );
        $returnStmt->execute([$purchaseId, $this->tenantId]);
        if ((int) $returnStmt->fetchColumn() > 0) {
            throw new \InvalidArgumentException('لا يمكن تعديل فاتورة لها مرتجع مرتبط.');
        }

        // A posted journal is immutable; use the explicit cancel-and-recreate flow.
        if (!empty($purchase['journal_entry_id'])) {
            $journalStatusStmt = $this->db->prepare(
                'SELECT status FROM journal_entries WHERE id = ? AND tenant_id = ? LIMIT 1'
            );
            $journalStatusStmt->execute([(int) $purchase['journal_entry_id'], $this->tenantId]);
            $journalStatus = $journalStatusStmt->fetchColumn();
            if ($journalStatus === false) {
                throw new \RuntimeException('القيد المحاسبي المرتبط بفاتورة الشراء غير موجود.');
            }
            if (in_array($journalStatus, ['posted', 'reversed'], true)) {
                throw new \InvalidArgumentException(
                    'لا يمكن تعديل فاتورة شراء مرحّلة؛ ألغِها بالطريقة الآمنة وأنشئ فاتورة بديلة.'
                );
            }
            if (!$this->accounting->deleteJournalEntry($this->tenantId, (int) $purchase['journal_entry_id'])) {
                throw new \RuntimeException('تعذر حذف القيد المحاسبي السابق لفاتورة الشراء.');
            }
        }

        $stmtOldItems = $this->db->prepare(
            "SELECT * FROM purchase_items WHERE purchase_id = ? AND tenant_id = ?"
        );
        $stmtOldItems->execute([$purchaseId, $this->tenantId]);
        $oldItems = $stmtOldItems->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($oldItems as $item) {
            $this->updateStock(
                (int) $item['product_id'],
                (int) $item['unit_id'],
                -(float) $item['quantity'],
                (int) $purchase['branch_id'],
                $purchaseId,
                'out',
                null,
                (float) ($item['cost'] ?? 0),
                $item['batch_number'] ?? null,
                $item['expiry_date']  ?? null,
                $item['serial']       ?? null
            );
        }

        $this->db->prepare(
            "DELETE FROM purchase_items WHERE purchase_id = ? AND tenant_id = ?"
        )->execute([$purchaseId, $this->tenantId]);

        $this->db->prepare("
            UPDATE purchases
            SET supplier_id       = ?,
                invoice_date      = ?,
                total_amount      = ?,
                paid_amount       = ?,
                payment_method_id = ?,
                discount_type     = ?,
                discount_value    = ?,
                tax_rate          = ?,
                tax_amount        = ?,
                notes             = ?,
                status            = ?,
                branch_id         = ?,
                cost_center_id    = ?,
                total_items       = ?,
                updated_at        = NOW()
            WHERE id = ? AND tenant_id = ?
        ")->execute([
            (int) $data['supplier_id'],
            $invoiceDate,
            $totals['net_total'],
            $totals['paid_amount'],
            (int) $data['payment_method_id'],
            $totals['discount_type'],
            $totals['discount_amount'],
            $totals['tax_rate'],
            $totals['tax_amount'],
            $data['notes'] ?? null,
            $totals['status'],
            (int) $data['branch_id'],
            $costCenterId,
            $totals['total_items'],
            $purchaseId,
            $this->tenantId,
        ]);

        $this->insertPurchaseItems(
            $purchaseId,
            $totals['items'],
            $totals['gross_total'],
            $totals['discount_amount'],
            (int) $data['branch_id'],
            $data['notes'] ?? null,
            true
        );

        $supplierAccountId = $this->getSupplierAccountId((int) $data['supplier_id']);
        if ((float) $totals['paid_amount'] > 0) {
            $this->validatePaymentLiquidity(
                (int) $data['payment_method_id'],
                (float) $totals['paid_amount'],
                $sessionId
            );
        }
        $this->createJournalEntry([
            'id'                => $purchaseId,
            'invoice_number'    => (string) ($purchase['invoice_number'] ?? ''),
            'invoice_date'      => $invoiceDate,
            'total_amount'      => $totals['net_total'],
            'paid_amount'       => $totals['paid_amount'],
            'tax_amount'        => $totals['tax_amount'],
            'payment_method_id' => (int) $data['payment_method_id'],
            'supplier_id'       => (int) $data['supplier_id'],
            'user_id'           => $this->userId,
            'branch_id'         => (int) $data['branch_id'],
            'cost_center_id'    => $costCenterId,
        ], $supplierAccountId);

        if ((float) $totals['paid_amount'] > 0) {
            $this->recordPayment(
                $purchaseId,
                (int) $data['supplier_id'],
                (float) $totals['paid_amount'],
                $invoiceDate,
                (int) $data['payment_method_id'],
                $data['reference_number'] ?? null,
                (int) $data['branch_id'],
                $costCenterId,
                $supplierAccountId,
                $sessionId,
                true,
                true
            );
        }

        return [
            'id'             => $purchaseId,
            'invoice_number' => (string) ($purchase['invoice_number'] ?? ''),
        ];
    }

    /**
     * Cancel a purchase while preserving its document, reversing its journal, and removing available stock.
     *
     * Refuses invoices with payments, returns, tracked items, or insufficient stock.
     * Owns the transaction only when the caller has not already started one.
     */
    public function deletePurchase(int $purchaseId): void
    {
        $ownTransaction = !$this->db->inTransaction();
        try {
            if ($ownTransaction) {
                $this->db->beginTransaction();
            }

            $forUpdate = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $stmt = $this->db->prepare(
                "SELECT * FROM purchases WHERE id = ? AND tenant_id = ? LIMIT 1{$forUpdate}"
            );
            $stmt->execute([$purchaseId, $this->tenantId]);
            $purchase = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$purchase) {
                throw new \App\Exceptions\NotFoundException('Purchase not found');
            }
            if (in_array(strtolower((string) ($purchase['status'] ?? '')), ['canceled', 'cancelled', 'rejected'], true)) {
                throw new \InvalidArgumentException('فاتورة الشراء ملغاة أو مرفوضة بالفعل.');
            }

            // Keep the payment trail intact; reversing an invoice alone would leave cash movements posted.
            $stmtPay = $this->db->prepare(
                "SELECT COUNT(*) FROM payments
                 WHERE purchase_id = ? AND tenant_id = ?
                   AND COALESCE(type, '') <> 'payment_for_return'"
            );
            $stmtPay->execute([$purchaseId, $this->tenantId]);
            if ((int) $stmtPay->fetchColumn() > 0) {
                throw new \InvalidArgumentException(
                    'لا يمكن إلغاء فاتورة مرتبطة بمدفوعات؛ عالجها من خلال مسار مرتجع المشتريات للحفاظ على أثر الدفعات.'
                );
            }
            $stmtReturns = $this->db->prepare(
                "SELECT COUNT(*) FROM returns
                 WHERE purchase_id = ? AND tenant_id = ? AND return_type = 'purchase'"
            );
            $stmtReturns->execute([$purchaseId, $this->tenantId]);
            if ((int) $stmtReturns->fetchColumn() > 0) {
                throw new \InvalidArgumentException('لا يمكن حذف فاتورة لها مرتجع مرتبط.');
            }

            $stmtItems = $this->db->prepare(
                "SELECT * FROM purchase_items WHERE purchase_id = ? AND tenant_id = ?"
            );
            $stmtItems->execute([$purchaseId, $this->tenantId]);
            $items = $stmtItems->fetchAll(\PDO::FETCH_ASSOC);

            $this->assertPurchaseCancellationStockAvailable($purchase, $items);

            $journalEntryId = (int) ($purchase['journal_entry_id'] ?? 0);
            if ($journalEntryId <= 0) {
                throw new \RuntimeException('لا يمكن إلغاء فاتورة شراء دون قيد محاسبي مرتبط.');
            }
            $journalStatusStmt = $this->db->prepare(
                'SELECT status FROM journal_entries WHERE id = ? AND tenant_id = ? LIMIT 1'
            );
            $journalStatusStmt->execute([$journalEntryId, $this->tenantId]);
            if ($journalStatusStmt->fetchColumn() !== 'posted') {
                throw new \RuntimeException('لا يمكن إلغاء فاتورة شراء دون قيد محاسبي مرحّل.');
            }
            $this->accounting->reverseJournalEntry($journalEntryId, $this->tenantId, $this->userId);

            // Preserve the original invoice and lines for audit.
            foreach ($items as $item) {
                $this->updateStock(
                    (int) $item['product_id'],
                    (int) $item['unit_id'],
                    -(float) $item['quantity'],
                    (int) $purchase['branch_id'],
                    $purchaseId,
                    'out',
                    null,
                    (float) ($item['cost'] ?? 0),
                    $item['batch_number'] ?? null,
                    $item['expiry_date']  ?? null,
                    $item['serial']       ?? null
                );
            }

            $this->db->prepare(
                "UPDATE purchases SET status = 'cancelled', updated_at = NOW()
                 WHERE id = ? AND tenant_id = ?"
            )->execute([$purchaseId, $this->tenantId]);

            if ($ownTransaction) {
                $this->db->commit();
            }

        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Ensure cancelling the purchase will not remove stock that has already
     * been consumed or whose batch/serial tracking cannot be safely reversed.
     */
    private function assertPurchaseCancellationStockAvailable(array $purchase, array $items): void
    {
        $requiredByProduct = [];
        $productStmt = $this->db->prepare(
            'SELECT has_batch_number, has_expiry_date, has_serial_number
             FROM products WHERE id = ? AND tenant_id = ? LIMIT 1'
        );

        foreach ($items as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $productStmt->execute([$productId, $this->tenantId]);
            $tracking = $productStmt->fetch(PDO::FETCH_ASSOC);
            if (!$tracking) {
                throw new \RuntimeException('تعذر التحقق من تتبع صنف بفاتورة الشراء.');
            }
            if (
                !empty($tracking['has_batch_number'])
                || !empty($tracking['has_expiry_date'])
                || !empty($tracking['has_serial_number'])
                || !empty($item['batch_number'])
                || !empty($item['expiry_date'])
                || !empty($item['serial'])
            ) {
                throw new \InvalidArgumentException(
                    'لا يمكن إلغاء الفاتورة آليًا لوجود أصناف متتبعة بدفعة أو صلاحية أو رقم مسلسل؛ استخدم مسار المرتجع.'
                );
            }

            $requiredByProduct[$productId] = ($requiredByProduct[$productId] ?? 0.0)
                + (float) ($item['quantity'] ?? 0);
        }

        $forUpdate = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $stockStmt = $this->db->prepare(
            "SELECT quantity FROM branch_products
             WHERE tenant_id = ? AND branch_id = ? AND product_id = ?
             LIMIT 1{$forUpdate}"
        );
        foreach ($requiredByProduct as $productId => $required) {
            $stockStmt->execute([
                $this->tenantId,
                (int) ($purchase['branch_id'] ?? 0),
                (int) $productId,
            ]);
            $available = $stockStmt->fetchColumn();
            if ($available === false || (float) $available + 0.0001 < $required) {
                throw new \InvalidArgumentException(
                    'لا يمكن إلغاء الفاتورة لأن كامل كمية أحد الأصناف لم تعد متاحة في المخزون.'
                );
            }
        }
    }

    /**
     * Add a payment up to the invoice's outstanding balance and recalculate its status.
     *
     * The caller must provide a transaction for the MySQL row lock and related writes.
     */
    public function addPaymentToInvoice(
        int    $purchaseId,
        array  $purchase,
        float  $amount,
        string $paymentDate,
        int    $paymentMethodId,
        ?string $referenceNumber = null,
        ?int   $branchId         = null,
        ?int   $costCenterId     = null,
        ?int   $sessionId        = null
    ): array {
        $forUpdate = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $purchaseLock = $this->db->prepare(
            "SELECT status FROM purchases WHERE id = ? AND tenant_id = ? LIMIT 1{$forUpdate}"
        );
        $purchaseLock->execute([$purchaseId, $this->tenantId]);
        $purchaseStatus = $purchaseLock->fetchColumn();
        if ($purchaseStatus === false) {
            throw new \App\Exceptions\NotFoundException('Purchase not found');
        }
        if (in_array(strtolower((string) $purchaseStatus), ['canceled', 'cancelled', 'rejected'], true)) {
            throw new \InvalidArgumentException('لا يمكن تسجيل دفعة على فاتورة شراء ملغاة أو مرفوضة.');
        }

        $actualPaidSoFar = $this->paymentRepo->getTotalPaidForPurchase($purchaseId, $this->tenantId);

        $stmtRet = $this->db->prepare(
            "SELECT COALESCE(SUM(grand_total), 0) FROM returns
             WHERE purchase_id = ? AND tenant_id = ? AND return_type = 'purchase'"
        );
        $stmtRet->execute([$purchaseId, $this->tenantId]);
        $returnAmt = round((float) $stmtRet->fetchColumn(), 2);

        $remaining = PurchaseReturnSettlementPolicy::outstandingAmount(
            (float) $purchase['total_amount'],
            $actualPaidSoFar,
            $returnAmt
        );

        if ($amount > $remaining + 0.01) {
            throw new \Exception(
                "الدفعة ({$amount}) تتجاوز المبلغ المتبقي ({$remaining})"
            );
        }
        $amount = min($amount, $remaining);

        $supplierAccountId = (int) ($purchase['supplier_account_id'] ?? 0);

        $paymentResult = $this->recordPayment(
            $purchaseId,
            (int) $purchase['supplier_id'],
            $amount,
            $paymentDate,
            $paymentMethodId,
            $referenceNumber,
            $branchId,
            $costCenterId,
            $supplierAccountId,
            $sessionId,
            false
        );

        $this->recalculateBalance($purchaseId);

        $stmtReload = $this->db->prepare(
            "SELECT paid_amount, status FROM purchases WHERE id = ? AND tenant_id = ? LIMIT 1"
        );
        $stmtReload->execute([$purchaseId, $this->tenantId]);
        $reloaded = $stmtReload->fetch(\PDO::FETCH_ASSOC);

        return array_merge($paymentResult, [
            'remaining' => $remaining - $amount,
            'new_paid'  => (float) ($reloaded['paid_amount'] ?? 0),
            'status'    => (string) ($reloaded['status'] ?? 'due'),
        ]);
    }

    /**
     * Record an unallocated supplier payment and post its accounting entry.
     *
     * Starts and commits a transaction only when the caller does not already own one.
     */
    public function recordSupplierDebtPayment(
        int $supplierId,
        float $amount,
        string $paymentDate,
        string $notes,
        int $paymentMethodId,
        ?int $branchId = null,
        ?int $costCenterId = null,
        ?int $sessionId = null
    ): array {
        if (!is_finite($amount) || $amount <= 0) {
            throw new \InvalidArgumentException('قيمة الدفعة يجب أن تكون أكبر من صفر.');
        }

        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $supplierAccountId = $this->getSupplierAccountId($supplierId);
            $liquidityAccountId = $this->accounting->resolveLiquidityAccount($paymentMethodId, $this->tenantId);
            if ($liquidityAccountId === null) {
                throw new \InvalidArgumentException('لا يمكن تسجيل دفعة مورد بطريقة دفع آجلة.');
            }

            $policy = new \App\Services\LiquidityPolicyService($this->db, $this->logger);
            $validation = $policy->validateLiquidityBalance(
                tenantId: $this->tenantId,
                paymentMethodId: $paymentMethodId,
                debitAmount: $amount,
                cashierSessionId: $sessionId
            );
            if (!$validation['allowed']) {
                throw new \App\Exceptions\InsufficientLiquidityException(
                    $validation['reason'],
                    402,
                    $validation + ['required_amount' => $amount]
                );
            }

            if ($costCenterId === null) {
                $costCenterId = $this->costCenterService->resolve(
                    $this->tenantId,
                    $this->userId,
                    null,
                    $branchId
                );
            }

            $stmt = $this->db->prepare(
                "INSERT INTO payments (
                    tenant_id, supplier_id, amount, payment_date, payment_method_id,
                    reference_number, created_by, is_draft, status, type, created_at,
                    cost_center_id, currency, session_id
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, 0, 'completed', 'supplier', NOW(), ?, ?, ?)"
            );
            $stmt->execute([
                $this->tenantId,
                $supplierId,
                $amount,
                $paymentDate,
                $paymentMethodId,
                $notes !== '' ? $notes : null,
                $this->userId,
                $costCenterId,
                $this->getCompanyCurrency(),
                $sessionId,
            ]);
            $paymentId = (int) $this->db->lastInsertId();

            $journalEntryId = $this->accounting->postPayment(
                tenantId: $this->tenantId,
                paymentId: $paymentId,
                amount: $amount,
                paymentType: 'supplier_payment',
                userId: $this->userId,
                costCenterId: $costCenterId,
                debitAccountId: $supplierAccountId,
                creditAccountId: $liquidityAccountId,
                description: "سداد دين مورد #{$supplierId}",
                paymentMethodId: $paymentMethodId
            );
            if (!$journalEntryId) {
                throw new \RuntimeException('فشل ترحيل قيد سداد المورد.');
            }

            if ($this->accounting->isCashMethod($paymentMethodId, $this->tenantId)) {
                $this->db->prepare(
                    "INSERT INTO cash_transactions (
                        tenant_id, supplier_id, amount, type, reference_type, reference_id,
                        payment_method_id, description, created_by, status, notes,
                        journal_entry_id, session_id, branch_id, cost_center_id
                     ) VALUES (?, ?, ?, 'expense', 'supplier_payment', ?, ?, ?, ?, 'completed', ?, ?, ?, ?, ?)"
                )->execute([
                    $this->tenantId,
                    $supplierId,
                    $amount,
                    $paymentId,
                    $paymentMethodId,
                    $notes,
                    $this->userId,
                    $notes,
                    $journalEntryId,
                    $sessionId,
                    $branchId,
                    $costCenterId,
                ]);
            }

            if ($ownsTransaction) {
                $this->db->commit();
            }

            return [
                'payment_id' => $paymentId,
                'journal_entry_id' => $journalEntryId,
                'session_id' => $sessionId,
            ];
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
