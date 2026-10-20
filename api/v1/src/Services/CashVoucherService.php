<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use Throwable;
use DateTime;
use App\Services\AccountingService;
use App\Services\CurrencyService;
use App\Services\BalanceCalculationService;
use App\Services\MonologHandler;

/**
 * Creates and reverses tenant-scoped cash vouchers and their linked accounting, payment, and cash records.
 */
class CashVoucherService
{
    private PDO $db;
    private int $tenantId;
    private ?int $userId;
    private AccountingService $accounting;
    private BalanceCalculationService $balanceCalc;
    private $logger;

    public function __construct(PDO $db, int $tenantId, ?int $userId = null)
    {
        $this->db         = $db;
        $this->tenantId   = $tenantId;
        $this->userId     = $userId;
        $this->accounting = new AccountingService($db);
        $this->balanceCalc = new BalanceCalculationService($db);
        $this->logger     = MonologHandler::getInstance('cash_vouchers');
    }

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Persist the voucher, journal entry, cash transaction, and any linked payment in one transaction.
     *
     * @throws \Throwable when validation or persistence fails
     */
    public function createVoucher(array $data, ?int $sessionId = null): array
    {
        $saleId     = $data['sale_id']          ?? null;
        $purchaseId = $data['purchase_id']       ?? null;
        $requestHash = !empty($data['idempotency_key'])
            ? $this->getVoucherRequestHash($data)
            : null;

        // Propagate session_id into $data so postToJournal() can use it
        // for the cash-negative-intraday check in LiquidityPolicyService
        if ($sessionId !== null) {
            $data['session_id'] = $sessionId;
        }

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $paymentMethodId = (int) ($data['payment_method_id'] ?? 0);
            if ($paymentMethodId <= 0) {
                throw new \Exception('يجب تحديد طريقة الدفع قبل تسجيل السند.');
            }
            $linkedAccountId = $this->accounting->resolveLiquidityAccount($paymentMethodId, $this->tenantId);
            if ($linkedAccountId === null) {
                throw new \Exception('طريقة الدفع الآجلة لا تصلح لسند نقدي.');
            }
            if (!empty($data['account_id']) && (int) $data['account_id'] !== $linkedAccountId) {
                throw new \Exception('الحساب المحدد في السند لا يطابق الحساب المرتبط بطريقة الدفع.');
            }
            $data['account_id'] = $linkedAccountId;

            $journalEntryId = $this->postToJournal($data, null);
            if (!$journalEntryId) {
                throw new \Exception('Failed to create journal entry.');
            }

            $voucherReference = $this->generateVoucherReference($data['date'], (int) $journalEntryId);
            $notes = $data['notes'] ?? ($data['description'] ?? null);

            $stmt = $this->db->prepare("
                INSERT INTO cash_vouchers (
                    tenant_id, branch_id, type, date, amount, currency,
                    customer_id, supplier_id, account_id, reference, notes,
                    created_by, journal_entry_id, cost_center_id, idempotency_key, request_hash
                ) VALUES (
                    :tenant_id, :branch_id, :type, :date, :amount, :currency,
                    :customer_id, :supplier_id, :account_id, :reference, :notes,
                    :created_by, :journal_entry_id, :cost_center_id, :idempotency_key, :request_hash
                )
            ");
            $stmt->execute([
                ':tenant_id'        => $this->tenantId,
                ':branch_id'        => $data['branch_id'] ?? null,
                ':type'             => $data['type'],
                ':date'             => $data['date'],
                ':amount'           => $data['amount'],
                ':currency'         => $data['currency'],
                ':customer_id'      => $data['customer_id'],
                ':supplier_id'      => $data['supplier_id'],
                ':account_id'       => $data['account_id'],
                ':reference'        => $voucherReference,
                ':notes'            => $notes,
                ':created_by'       => $this->userId,
                ':journal_entry_id' => (int) $journalEntryId,
                ':cost_center_id'   => $data['cost_center_id'],
                ':idempotency_key'  => $data['idempotency_key'] ?? null,
                ':request_hash'     => $requestHash,
            ]);

            $voucherId = (int) $this->db->lastInsertId();

            $this->db->prepare("
                UPDATE journal_entries
                SET reference_id = :reference_id, status = 'posted'
                WHERE id = :id AND tenant_id = :tenant_id
            ")->execute([':reference_id' => $voucherId, ':id' => (int) $journalEntryId, ':tenant_id' => $this->tenantId]);

            $this->recordCashTransaction($voucherId, $journalEntryId, $sessionId, $data, $notes);

            // ── ربط بالفاتورة داخل نفس الـ transaction لضمان الـ atomicity ──
            // إذا فشل linkToSale/linkToPurchase يتم rollback للكل
            if ($saleId !== null && !empty($data['customer_id'])) {
                $this->linkToSale((int) $saleId, $data, $voucherReference, (int) $journalEntryId);
            } elseif (!empty($data['customer_id']) && in_array($data['type'], ['receipt', 'قبض'], true)) {
                $this->linkToCustomer((int) $data['customer_id'], $data, $voucherReference, (int) $journalEntryId);
            }
            if ($purchaseId !== null && !empty($data['supplier_id'])) {
                $this->linkToPurchase((int) $purchaseId, $data, $voucherReference, (int) $journalEntryId);
            }

            if ($ownTransaction) {
                $this->db->commit();
            }

            return [
                'id'               => $voucherId,
                'reference'        => $voucherReference,
                'notes'            => $notes,
                'journal_entry_id' => (int) $journalEntryId,
                'session_id'       => $sessionId,
            ];
        } catch (\Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                try {
                    $this->db->rollBack();
                } catch (\Throwable $rollbackError) {
                    $this->logger->error('Cash voucher transaction rollback failed', [
                        'tenant_id' => $this->tenantId,
                        'message' => $rollbackError->getMessage(),
                    ]);
                }
            }
            throw $e;
        }
    }

    // =========================================================================
    // Validation & Normalization
    // =========================================================================

    /** Merge update data and normalize voucher field types and defaults. */
    public function normalizeVoucherPayload(array $data, bool $isUpdate = false, array $existing = []): array
    {
        $merged = $isUpdate ? array_merge($existing, $data) : $data;

        $merged['type']     = isset($merged['type']) ? trim((string) $merged['type']) : null;
        $merged['date']     = !empty($merged['date']) ? (string) $merged['date'] : date('Y-m-d H:i:s');
        $merged['amount']   = isset($merged['amount']) ? round((float) $merged['amount'], 2) : 0;
        $merged['currency'] = !empty($merged['currency']) ? (string) $merged['currency'] : $this->getCompanyCurrency();

        foreach (['customer_id','supplier_id','account_id','expense_account_id','branch_id',
                  'payment_method_id','cost_center_id','return_id','purchase_id','sale_id'] as $k) {
            $merged[$k] = !empty($merged[$k]) ? (int) $merged[$k] : null;
        }

        $merged['notes'] = array_key_exists('notes', $merged)
            ? $merged['notes']
            : ($merged['description'] ?? null);

        return $merged;
    }

    /** Return a validation message for malformed voucher fields, or null when valid. */
    public function validateVoucherPayload(array $data, bool $isUpdate = false): ?string
    {
        if (empty($data['type'])) {
            return 'حقل type مطلوب';
        }
        if (!in_array($data['type'], ['قبض','receipt','صرف','payment'], true)) {
            return 'نوع السند غير مدعوم';
        }
        if (empty($data['date'])) {
            return 'حقل date مطلوب';
        }
        if (!isset($data['amount']) || (float) $data['amount'] <= 0) {
            return 'قيمة amount يجب أن تكون أكبر من صفر';
        }
        if (($data['type'] === 'قبض' || $data['type'] === 'receipt') && empty($data['customer_id'])) {
            return 'مطلوب رقم العميل لسندات القبض';
        }
        if (($data['type'] === 'صرف' || $data['type'] === 'payment') && empty($data['supplier_id']) && empty($data['expense_account_id'])) {
            return 'مطلوب رقم المورد أو حساب المصروف لسندات الصرف';
        }
        if (!$isUpdate && empty($data['payment_method_id'])) {
            return 'مطلوب تحديد طريقة الدفع';
        }
        return null;
    }

    /** Enforce receipt-balance rules using the customer's posted journal activity. */
    public function validateVoucherBusinessRules(array $data, ?int $excludeVoucherId = null): void
    {
        $type   = $data['type'];
        $amount = (float) $data['amount'];
        $date   = $data['date'];

        if ($type === 'قبض' || $type === 'receipt') {
            $customerId        = (int) $data['customer_id'];
            $customerAccountId = $this->getPartyAccountId('customer', $customerId);
            if (!$customerAccountId) {
                throw new \Exception('لم يتم العثور على حساب العميل لترحيل سند القبض');
            }
            $asOfDate       = DateTime::createFromFormat('Y-m-d', $date) ?: new DateTime($date);
            $currentBalance = $this->balanceCalc->getAccountBalanceFromJournalEntries(
                $customerAccountId,
                $this->tenantId,
                'customer',
                $asOfDate
            );
            if ($currentBalance <= 0) {
                throw new \Exception('لا يمكن إنشاء سند قبض لهذا العميل لأنه لا توجد عليه مديونية حاليًا (رصيده صفر). استخدم سند صرف إذا كنت ترد مبلغًا للعميل.');
            }
            if ($amount > $currentBalance && !$excludeVoucherId) {
                $this->logger->warning('Receipt amount exceeds current customer balance', [
                    'tenant_id' => $this->tenantId, 'customer_id' => $customerId,
                    'balance' => $currentBalance, 'amount' => $amount,
                ]);
            }
        }
    }

    /** Find a voucher retry candidate within this service's tenant. */
    public function findVoucherByIdempotencyKey(string $idempotencyKey): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT id, reference, request_hash
             FROM cash_vouchers
             WHERE tenant_id = ? AND idempotency_key = ?
             LIMIT 1"
        );
        $stmt->execute([$this->tenantId, $idempotencyKey]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Hash the normalized business fields used to identify an idempotent request. */
    public function getVoucherRequestHash(array $data): string
    {
        $fingerprint = [];
        foreach ([
            'type', 'date', 'amount', 'currency', 'customer_id', 'supplier_id',
            'expense_account_id', 'branch_id', 'payment_method_id',
            'cost_center_id', 'return_id', 'purchase_id', 'sale_id', 'notes',
        ] as $field) {
            $fingerprint[$field] = $data[$field] ?? null;
        }

        $json = json_encode(
            $fingerprint,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
        if ($json === false) {
            throw new \InvalidArgumentException('تعذر التحقق من بصمة طلب السند.');
        }

        return hash('sha256', $json);
    }

    /** Build the display reference from the voucher date and journal-entry ID. */
    public function generateVoucherReference(string $date, int $journalEntryId): string
    {
        return 'CV-' . date('Ymd', strtotime($date)) . '-' . $journalEntryId;
    }

    /** Map receipt/payment voucher types to the cash transaction income/expense values. */
    public function mapVoucherTypeToCashTransactionType(string $type): string
    {
        return ($type === 'قبض' || $type === 'receipt') ? 'income' : 'expense';
    }

    /** Reverse the linked journal entry, refusing legacy reversals without a consistent link. */
    public function reverseJournalEntryByVoucherIdIfNeeded(int $voucherId): void
    {
        $stmt = $this->db->prepare(
            "SELECT journal_entry_id FROM cash_vouchers WHERE id = ? AND tenant_id = ? LIMIT 1"
        );
        $stmt->execute([$voucherId, $this->tenantId]);
        $journalEntryId = (int) $stmt->fetchColumn();
        if ($journalEntryId <= 0) {
            throw new \RuntimeException('لا يمكن إلغاء سند دون قيد محاسبي مرتبط.');
        }

        $original = $this->db->prepare(
            'SELECT status, reversal_of FROM journal_entries WHERE id = ? AND tenant_id = ? LIMIT 1'
        );
        $original->execute([$journalEntryId, $this->tenantId]);
        $originalEntry = $original->fetch(PDO::FETCH_ASSOC);
        if (!$originalEntry) {
            throw new \RuntimeException('القيد المحاسبي المرتبط بالسند غير موجود.');
        }

        $legacyReversal = $this->db->prepare(
            "SELECT id FROM journal_entries
             WHERE tenant_id = ? AND reference_type = 'reversal' AND reference_id = ?
             LIMIT 1"
        );
        $legacyReversal->execute([$this->tenantId, $journalEntryId]);
        if ($legacyReversal->fetchColumn() &&
            ($originalEntry['status'] !== 'reversed' || (int) ($originalEntry['reversal_of'] ?? 0) <= 0)) {
            throw new \DomainException(
                'للسند قيد عكس قديم غير مرتبط؛ يلزم تسوية محاسبية قبل تعديله أو إلغائه.'
            );
        }

        $this->accounting->reverseJournalEntry($journalEntryId, $this->tenantId, $this->userId);
    }

    /** Resolve voucher accounts and post its balanced journal entry. */
    public function postToJournal(array $voucherData, ?int $voucherId): int|false
    {
        if ($voucherId) {
            $stmtEx = $this->db->prepare(
                "SELECT id FROM journal_entries WHERE reference_type = 'cash_voucher' AND reference_id = ? AND tenant_id = ? LIMIT 1"
            );
            $stmtEx->execute([$voucherId, $this->tenantId]);
            $existingJeId = $stmtEx->fetchColumn();
            if ($existingJeId) {
                $this->logger->info('Journal entry already exists for this voucher', [
                    'tenant_id' => $this->tenantId, 'voucher_id' => $voucherId, 'existing_je_id' => $existingJeId,
                ]);
                return (int) $existingJeId;
            }
        }

        $type   = $voucherData['type']   ?? '';
        $amount = (float) ($voucherData['amount'] ?? 0);
        $date   = $voucherData['date']   ?? date('Y-m-d');
        $notes  = (string) ($voucherData['notes'] ?? '');

        $paymentMethodId = (int) ($voucherData['payment_method_id'] ?? 0);
        if ($paymentMethodId <= 0) {
            throw new \Exception('يجب تحديد طريقة الدفع قبل ترحيل السند.');
        }

        $cashBankAccountId = $this->accounting->resolveLiquidityAccount(
            $paymentMethodId,
            $this->tenantId
        );
        if ($cashBankAccountId === null) {
            throw new \Exception('طريقة الدفع الآجلة لا تصلح لسند نقدي.');
        }

        if (!empty($voucherData['account_id']) && (int) $voucherData['account_id'] !== $cashBankAccountId) {
            throw new \Exception('الحساب المحدد في السند لا يطابق الحساب المرتبط بطريقة الدفع.');
        }

        $debitAccountId  = null;
        $creditAccountId = null;
        $description     = '';

        if ($type === 'قبض' || $type === 'receipt') {
            $customerId = $voucherData['customer_id'] ?? null;
            if (!$customerId) {
                $this->logger->error('Failed to post receipt voucher - customer ID missing');
                return false;
            }
            $debitAccountId  = $cashBankAccountId;
            $creditAccountId = $this->getPartyAccountId('customer', (int) $customerId);
            $description     = "سند قبض من عميل رقم {$customerId} - {$notes}";
        } elseif ($type === 'صرف' || $type === 'payment') {
            $supplierId       = $voucherData['supplier_id']        ?? null;
            $expenseAccountId = $voucherData['expense_account_id'] ?? null;
            if (!$supplierId && !$expenseAccountId) {
                $this->logger->error('Failed to post payment voucher - missing supplier/expense');
                return false;
            }
            if ($supplierId) {
                $debitAccountId = $this->getPartyAccountId('supplier', (int) $supplierId);
                $description    = "سند صرف لمورد رقم {$supplierId} - {$notes}";
            } else {
                $debitAccountId = (int) $expenseAccountId;
                $description    = "سند صرف مصروفات - {$notes}";
            }
            $creditAccountId = $cashBankAccountId;
            // ✅ LIQUIDITY VALIDATION (Phase 5): Expense voucher is an OUTFLOW operation
            // Validate liquidity BEFORE posting journal entry
            $liquidityPolicy = new \App\Services\LiquidityPolicyService($this->db, $this->logger);
            $validation = $liquidityPolicy->validateLiquidityBalance(
                tenantId: $this->tenantId,
                paymentMethodId: $paymentMethodId,
                debitAmount: $amount,
                cashierSessionId: $voucherData['session_id'] ?? null
            );

            if (!$validation['allowed']) {
                throw new \App\Exceptions\InsufficientLiquidityException(
                    $validation['reason'],
                    402,
                    $validation
                );
            }
        } else {
            $this->logger->error('Unsupported voucher type', ['type' => $type]);
            return false;
        }

        if (!$debitAccountId || !$creditAccountId) {
            $this->logger->error('Journal entry failed - party account IDs missing', ['tenant_id' => $this->tenantId]);
            return false;
        }

        try {
            $jeId = $this->accounting->postJournalEntry(
                $this->tenantId,
                'cash_voucher',
                $voucherId,
                $description,
                [
                    ['account_id' => $debitAccountId,  'debit' => $amount, 'credit' => 0,       'description' => "[Dr] {$description}"],
                    ['account_id' => $creditAccountId, 'debit' => 0,       'credit' => $amount, 'description' => "[Cr] {$description}"],
                ],
                $date,
                $this->userId,
                $voucherData['cost_center_id']  ?? null,
                $voucherData['idempotency_key'] ?? null
            );
            if (!$jeId) {
                $this->logger->error('Failed to post cash voucher to journal');
                return false;
            }
            return $jeId;
        } catch (\Throwable $e) {
            $this->logger->error('Error posting cash voucher journal entry', ['voucher_id' => $voucherId, 'message' => $e->getMessage()]);
            throw $e;
        }
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function linkToSale(int $saleId, array $data, string $voucherReference, int $journalEntryId): void
    {
        $forUpdate = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $saleStmt = $this->db->prepare(
            "SELECT id, customer_id, status, branch_id, net_total_amount, COALESCE(tax_amount, 0) AS tax_amount
             FROM sales
             WHERE id = ? AND tenant_id = ?{$forUpdate}"
        );
        $saleStmt->execute([$saleId, $this->tenantId]);
        $sale = $saleStmt->fetch(PDO::FETCH_ASSOC);
        if (!$sale || (int) $sale['customer_id'] !== (int) $data['customer_id']) {
            throw new \DomainException('فاتورة المبيعات المحددة غير موجودة أو لا تنتمي لهذا العميل.');
        }
        if (!in_array(strtolower((string) $sale['status']), [
            'pending_payment', 'partial', 'due',
        ], true)) {
            throw new \DomainException('لا يمكن ربط سند قبض بفاتورة ملغاة أو غير معتمدة.');
        }
        if (!empty($data['branch_id']) && !empty($sale['branch_id'])
            && (int) $data['branch_id'] !== (int) $sale['branch_id']) {
            throw new \DomainException('لا يمكن ربط سند قبض من فرع بفاتورة تخص فرعًا آخر.');
        }

        $paidStmt = $this->db->prepare(
            "SELECT COALESCE(SUM(CASE WHEN type = 'return_payment' THEN -amount ELSE amount END), 0)
             FROM payments
             WHERE tenant_id = ? AND sale_id = ? AND status = 'completed' AND COALESCE(is_draft, 0) = 0"
        );
        $paidStmt->execute([$this->tenantId, $saleId]);
        $allocatedStmt = $this->db->prepare(
            "SELECT COALESCE(SUM(cpa.allocated_amount), 0)
             FROM customer_payment_allocations cpa
             JOIN payments p ON p.id = cpa.payment_id AND p.tenant_id = cpa.tenant_id
             WHERE cpa.tenant_id = ? AND cpa.sale_id = ?
               AND p.status = 'completed' AND COALESCE(p.is_draft, 0) = 0"
        );
        $allocatedStmt->execute([$this->tenantId, $saleId]);
        $returnCreditStmt = $this->db->prepare(
            'SELECT COALESCE(SUM(allocated_amount), 0)
             FROM return_credit_allocations WHERE tenant_id = ? AND sale_id = ?'
        );
        $returnCreditStmt->execute([$this->tenantId, $saleId]);
        $outstanding = max(0.0, round(
            (float) $sale['net_total_amount'] + (float) $sale['tax_amount']
            - (float) $paidStmt->fetchColumn()
            - (float) $allocatedStmt->fetchColumn()
            - (float) $returnCreditStmt->fetchColumn(),
            2
        ));
        if ((float) $data['amount'] > $outstanding + 0.01) {
            throw new \DomainException(sprintf(
                'مبلغ سند القبض (%.2f) يتجاوز المتبقي على الفاتورة (%.2f).',
                (float) $data['amount'],
                $outstanding
            ));
        }

        $currency = $this->getCompanyCurrency();
        $this->db->prepare("
            INSERT INTO payments (tenant_id, sale_id, customer_id, amount, payment_date,
                payment_method_id, reference_number, created_by, is_draft, branch_id,
                status, type, created_at, cost_center_id, journal_entry_id, currency)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, 'completed', 'voucher', NOW(), ?, ?, ?)
        ")->execute([
            $this->tenantId, $saleId, $data['customer_id'], $data['amount'], $data['date'],
            $data['payment_method_id'], $voucherReference, $this->userId,
            $data['branch_id'] ?? null, $data['cost_center_id'], $journalEntryId, $currency,
        ]);

        // Recalculate from posted payments and return-credit allocations.
        $this->accounting->recalculateSaleStatus($this->tenantId, $saleId);
    }

    private function linkToCustomer(int $customerId, array $data, string $voucherReference, int $journalEntryId): void
    {
        $paymentStmt = $this->db->prepare("
            INSERT INTO payments (
                tenant_id, customer_id, amount, payment_date, payment_method_id,
                reference_number, created_by, is_draft, branch_id, status, type,
                created_at, cost_center_id, journal_entry_id, currency
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, 'completed', 'voucher', NOW(), ?, ?, ?)
        ");
        $paymentStmt->execute([
            $this->tenantId,
            $customerId,
            $data['amount'],
            $data['date'],
            $data['payment_method_id'],
            $voucherReference,
            $this->userId,
            $data['branch_id'] ?? null,
            $data['cost_center_id'],
            $journalEntryId,
            $this->getCompanyCurrency(),
        ]);
        $paymentId = (int) $this->db->lastInsertId();
        $saleIds = (new CustomerPaymentAllocationService($this->db))->allocatePayment(
            $this->tenantId,
            $customerId,
            $paymentId,
            (float) $data['amount'],
            !empty($data['branch_id']) ? (int) $data['branch_id'] : null
        );
        foreach ($saleIds as $saleId) {
            $this->accounting->recalculateSaleStatus($this->tenantId, $saleId);
        }
    }

    private function linkToPurchase(int $purchaseId, array $data, string $voucherReference, int $journalEntryId): void
    {
        $currency = $this->getCompanyCurrency();
        $this->db->prepare("
            INSERT INTO payments (tenant_id, purchase_id, supplier_id, amount, payment_date,
                payment_method_id, reference_number, created_by, is_draft,
                status, type, created_at, cost_center_id, journal_entry_id, currency)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 'completed', 'voucher', NOW(), ?, ?, ?)
        ")->execute([
            $this->tenantId, $purchaseId, $data['supplier_id'], $data['amount'], $data['date'],
            $data['payment_method_id'], $voucherReference, $this->userId,
            $data['cost_center_id'], $journalEntryId, $currency,
        ]);

        // Keep purchase paid amount and status calculations centralized.
        (new PurchaseService($this->db, $this->tenantId, $this->userId))->recalculateBalance($purchaseId);
    }

    private function recordCashTransaction(
        int $voucherId,
        int $journalEntryId,
        ?int $sessionId,
        array $data,
        ?string $notes
    ): void {
        $stmt = $this->db->prepare("
            INSERT INTO cash_transactions (
                tenant_id, customer_id, supplier_id, amount, type,
                reference_type, reference_id, payment_method_id, description,
                created_by, created_at, return_id, status, notes,
                journal_entry_id, session_id, cost_center_id, branch_id
            ) VALUES (
                :tenant_id, :customer_id, :supplier_id, :amount, :type,
                :reference_type, :reference_id, :payment_method_id, :description,
                :created_by, :created_at, :return_id, :status, :notes,
                :journal_entry_id, :session_id, :cost_center_id, :branch_id
            )
        ");
        $stmt->execute([
            ':tenant_id'         => $this->tenantId,
            ':customer_id'       => $data['customer_id'],
            ':supplier_id'       => $data['supplier_id'],
            ':amount'            => $data['amount'],
            ':type'              => $this->mapVoucherTypeToCashTransactionType($data['type']),
            ':reference_type'    => 'cash_voucher',
            ':reference_id'      => $voucherId,
            ':payment_method_id' => $data['payment_method_id'],
            ':description'       => $notes,
            ':created_by'        => $this->userId,
            ':created_at'        => $data['date'],
            ':return_id'         => $data['return_id'],
            ':status'            => 'completed',
            ':notes'             => $notes,
            ':journal_entry_id'  => $journalEntryId,
            ':session_id'        => $sessionId,
            ':cost_center_id'    => $data['cost_center_id'],
            ':branch_id'         => $data['branch_id'],
        ]);
    }

    private function getPartyAccountId(string $type, int $partyId): ?int
    {
        $table   = $type === 'customer' ? 'customers' : 'suppliers';
        $fallback = $type === 'customer' ? '1101' : '2101';
        $prefix   = $type === 'customer' ? '110' : '210';
        $name     = $type === 'customer' ? 'العملاء' : 'الموردون';

        $stmt = $this->db->prepare("SELECT account_id FROM {$table} WHERE id = ? AND tenant_id = ? LIMIT 1");
        $stmt->execute([$partyId, $this->tenantId]);
        $accId = $stmt->fetchColumn();

        if ($accId) {
            $chk = $this->db->prepare("SELECT code FROM accounts WHERE id = ? AND tenant_id = ?");
            $chk->execute([$accId, $this->tenantId]);
            $code = $chk->fetchColumn();
            if (!$code || strpos((string)$code, $prefix) !== 0) {
                $accId = null;
            }
        }
        if (!$accId) {
            $accId = $this->getAccountIdByCode($fallback) ?: $this->getAccountIdByName($name);
        }
        return $accId ? (int) $accId : null;
    }

    private function getAccountIdByCode(string $code): ?int
    {
        $stmt = $this->db->prepare("SELECT id FROM accounts WHERE code = ? AND tenant_id = ? AND is_active = 1");
        $stmt->execute([$code, $this->tenantId]);
        $result = $stmt->fetchColumn();
        if (!$result) {
            $this->logger->warning('Account not found by code', ['code' => $code, 'tenant_id' => $this->tenantId]);
        }
        return $result ? (int) $result : null;
    }

    private function getAccountIdByName(string $name): ?int
    {
        $stmt = $this->db->prepare("SELECT id FROM accounts WHERE name = ? AND tenant_id = ? AND is_active = 1");
        $stmt->execute([$name, $this->tenantId]);
        $result = $stmt->fetchColumn();
        return $result ? (int) $result : null;
    }

    private function getCompanyCurrency(): string
    {
        return (new CurrencyService($this->db))->getCompanyCurrency($this->tenantId);
    }
}
