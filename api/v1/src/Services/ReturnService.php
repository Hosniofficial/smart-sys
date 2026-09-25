<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use Throwable;
use PDOException;
use App\Services\AccountingService;
use App\Services\CostingService;
use App\Services\LabelService;
use App\Services\MonologHandler;
use App\Repositories\SettingsRepository;

/**
 * ReturnService
 *
 * Centralises return (مرتجع) business logic extracted from ReturnsHandler.
 * Handlers should delegate to this service instead of containing SQL/accounting logic.
 */
class ReturnService
{
    private PDO $db;
    private int $tenantId;
    private ?int $userId;
    private AccountingService $accounting;
    private SettingsRepository $settingsRepo;
    private FinancialCalculationService $financialCalcService;
    private $logger;

    public function __construct(PDO $db, int $tenantId, ?int $userId = null)
    {
        $this->db           = $db;
        $this->tenantId     = $tenantId;
        $this->userId       = $userId;
        $this->accounting   = new AccountingService($db);
        $this->settingsRepo = new SettingsRepository($db);
        $this->financialCalcService = new FinancialCalculationService();
        $this->logger       = MonologHandler::getInstance('returns');
    }

    // -------------------------------------------------------------------------
    // Account resolution
    // -------------------------------------------------------------------------

    /**
     * Resolve account ID via settings key then account code fallback.
     * Delegates to AccountingService (single source of truth).
     */
    public function resolveAccountId(string $settingKeyName, string $fallbackCode): ?int
    {
        return $this->accounting->resolveAccountId($this->tenantId, $settingKeyName, $fallbackCode);
    }

    /** Resolve a branch inventory account using the shared accounting priority. */
    public function resolveBranchInventoryAccountId(int $branchId): ?int
    {
        return $this->accounting->resolveInventoryAccountForBranch($this->tenantId, $branchId);
    }

    /**
     * Get company currency.
     */
    public function getCompanyCurrency(): string
    {
        return $this->settingsRepo->get($this->tenantId, 'company.currency', 'EGP') ?: 'EGP';
    }

    /**
     * Adjust stock for a return item.
     *
     * For sale returns  → stock increases (in)
     * For purchase returns → stock decreases (out)
     *
     * @throws \Exception on invalid product/unit combination or return type
     */
    public function updateStock(
        int $productId,
        int $unitId,
        float $quantity,
        int $branchId,
        int $returnId,
        string $returnType,
        ?string $batchNumber = null,
        ?string $expiryDate = null,
        ?string $serial = null
    ): void {
        $quantity = round($quantity, 4);
        $userId   = $this->userId ?? 1;

        // Resolve conversion factor
        $stmt = $this->db->prepare(
            "SELECT conversion_factor FROM product_units
             WHERE product_id = ? AND unit_id = ? AND tenant_id = ? LIMIT 1"
        );
        $stmt->execute([$productId, $unitId, $this->tenantId]);
        $conversionFactor = $stmt->fetchColumn();

        if ($conversionFactor === false || (float) $conversionFactor <= 0) {
            throw new \Exception('Invalid product unit combination');
        }

        $conversionFactor = (float) $conversionFactor;
        $baseQuantity     = round($quantity * $conversionFactor, 4);

        if ($baseQuantity <= 0) {
            throw new \Exception('Invalid quantity after conversion');
        }

        if ($returnType === 'sale') {
            $change       = $baseQuantity;
            $movementType = 'in';
            $branchFrom   = null;
            $branchTo     = $branchId;
        } elseif ($returnType === 'purchase') {
            $change       = -$baseQuantity;
            $movementType = 'out';
            $branchFrom   = $branchId;
            $branchTo     = null;
        } else {
            throw new \Exception('Invalid return type');
        }

        // Calculate WAC cost
        $unitCost  = 0.0;
        $totalCost = 0.0;
        try {
            $costing        = new CostingService($this->db);
            $wacPerBaseUnit = $costing->getWeightedAverageCost($this->tenantId, $productId, date('Y-m-d H:i:s'));
            if ($wacPerBaseUnit !== null && (float) $wacPerBaseUnit > 0) {
                $unitCost  = round((float) $wacPerBaseUnit * $conversionFactor, 4);
                $totalCost = round($unitCost * $quantity, 4);
            }
        } catch (Throwable $e) {
        }

        $costAdjustment = $totalCost * ($returnType === 'sale' ? 1 : -1);

        // Update branch_products
        $this->db->prepare(
            "INSERT INTO branch_products (tenant_id, product_id, branch_id, quantity, quantity_cost, gl_reconciled)
             VALUES (?, ?, ?, ?, ?, 0)
             ON DUPLICATE KEY UPDATE
                 quantity      = quantity + VALUES(quantity),
                 quantity_cost = quantity_cost + VALUES(quantity_cost)"
        )->execute([$this->tenantId, $productId, $branchId, $change, $costAdjustment]);
        BranchInventoryReconciliationService::markUnreconciled(
            $this->db,
            $this->tenantId,
            $branchId,
            $productId
        );

        // Insert inventory transaction
        $this->db->prepare(
            "INSERT INTO inventory_transactions (
                tenant_id, product_id, unit_id, quantity, unit_cost, total_cost,
                movement_type, movement_date, batch_number, expiry_date, serial,
                reference_type, reference_id, notes, created_at, user_id, branch_from, branch_to
             ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, 'return', ?, ?, NOW(), ?, ?, ?)"
        )->execute([
            $this->tenantId, $productId, $unitId, $quantity, $unitCost, $totalCost,
            $movementType, $batchNumber, $expiryDate, $serial,
            $returnId, 'Return stock movement', $userId, $branchFrom, $branchTo,
        ]);

        $inventoryTransactionId = (int) $this->db->lastInsertId();

        // Handle expiry tracking for sale returns
        if ($returnType === 'sale' && !empty($expiryDate)) {
            $chk = $this->db->prepare(
                "SELECT has_expiry_date FROM products WHERE id = ? AND tenant_id = ?"
            );
            $chk->execute([$productId, $this->tenantId]);
            if ((int) $chk->fetchColumn()) {
                $this->db->prepare(
                    "INSERT INTO product_expiry (tenant_id, product_id, branch_id, expiry_date, quantity, batch_number)
                     VALUES (?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)"
                )->execute([$this->tenantId, $productId, $branchId, $expiryDate, $baseQuantity, $batchNumber]);
            }
        }

        // Handle serial tracking for sale returns
        if ($returnType === 'sale' && !empty($serial)) {
            $chk = $this->db->prepare(
                "SELECT has_serial_number FROM products WHERE id = ? AND tenant_id = ?"
            );
            $chk->execute([$productId, $this->tenantId]);
            if ((int) $chk->fetchColumn()) {
                $this->db->prepare(
                    "UPDATE product_serials
                     SET status = 'instock', transaction_id = NULL
                     WHERE product_id = ? AND branch_id = ? AND serial_number = ?
                       AND tenant_id = ? AND status = 'sold'"
                )->execute([$productId, $branchId, $serial, $this->tenantId]);
            }
        }

        // Handle serial tracking for purchase returns
        if ($returnType === 'purchase' && !empty($serial)) {
            $chk = $this->db->prepare(
                "SELECT has_serial_number FROM products WHERE id = ? AND tenant_id = ?"
            );
            $chk->execute([$productId, $this->tenantId]);
            if ((int) $chk->fetchColumn()) {
                $this->db->prepare(
                    "UPDATE product_serials
                     SET status = 'damaged', transaction_id = ?
                     WHERE product_id = ? AND branch_id = ? AND serial_number = ?
                       AND tenant_id = ?"
                )->execute([$inventoryTransactionId, $productId, $branchId, $serial, $this->tenantId]);
            }
        }
    }

    // -------------------------------------------------------------------------
    // allocateCustomerBalance
    // -------------------------------------------------------------------------

    /**
     * توزيع مبلغ خصم من رصيد العميل (من مرتجع مبيعات) على فواتير المبيعات المستحقة.
     *
     * المنطق الصحيح:
     * - المرتجع يخصم الدين (outstanding)، لا يعني أموال دُفعت
     * - paid_amount = الأموال المدفوعة فعلاً (نقود أو تحويل)
     * - المرتجع يقلل الدين فقط، بدون تحديث paid_amount
     *
     * الأولوية: الفاتورة الأصلية المرتبطة بالمرتجع أولاً، ثم أقدم الفواتير المفتوحة.
     *
     * @param int        $customerId            معرف العميل
     * @param float      $amountToAllocate      المبلغ المراد توزيعه (المرتجع)
     * @param int|null   $originalSaleId        الفاتورة الأصلية للمرتجع (أولوية عليا)
     * @param int|null   $paymentId             معرف الدفعة المرتبطة (لتسجيل payment_applications)
     * @param int|null   $createdBy             معرف المستخدم المنفّذ
     */
    public function allocateCustomerBalance(
        int   $customerId,
        float $amountToAllocate,
        ?int  $originalSaleId = null,
        ?int  $paymentId      = null,
        ?int  $createdBy      = null,
        ?int  $returnId       = null
    ): void {
        if ($amountToAllocate <= 0) {
            return;
        }

        $tenantId   = $this->tenantId;
        $toAllocate = $amountToAllocate;
        $settledInvoiceIds = []; // Track invoices that became fully paid

        try {
            /**
             * IMPORTANT: We do NOT update sales.paid_amount
             *
             * Why? A return is a CREDIT NOTE that reduces the debt (outstanding),
             * not an actual payment. paid_amount should only increase when real money
             * is received (cash or bank transfer).
             *
             * We only record payment_applications for audit trail purposes.
             */

            // 1. الفاتورة الأصلية أولاً
            if ($originalSaleId !== null) {
                // ✅ FIXED: Lock actual row with FOR UPDATE on sales table directly
                $stmt = $this->db->prepare("
                    SELECT s.id,
                           s.net_total_amount + IFNULL(s.tax_amount, 0) AS grand_total,
                           s.paid_amount
                    FROM sales s
                    WHERE s.id = ? AND s.tenant_id = ?
                    FOR UPDATE
                ");
                $stmt->execute([$originalSaleId, $tenantId]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);

                if ($row) {
                    // ✅ FIXED: Calculate return_credits separately (no duplication, safe calculation)
                    $rcStmt = $this->db->prepare("
                        SELECT COALESCE(SUM(allocated_amount), 0) AS return_credits
                        FROM return_credit_allocations
                        WHERE sale_id = ? AND tenant_id = ?
                    ");
                    $rcStmt->execute([$originalSaleId, $tenantId]);
                    $rcRow = $rcStmt->fetch(\PDO::FETCH_ASSOC);
                    $returnCredits = (float) ($rcRow['return_credits'] ?? 0.0);

                    $outstanding = max(0, (float) $row['grand_total'] - (float) $row['paid_amount'] - $returnCredits);

                    // For returns (returnId !== null), allocate to the original invoice
                    // BUT: only allocate up to the outstanding amount
                    // If outstanding=0 (fully paid), don't allocate here; let excess go to other invoices
                    // This ensures proper FIFO distribution when return has excess amount
                    if ($returnId !== null) {
                        $apply = min($toAllocate, max(0, $outstanding));
                    } else {
                        $apply = min($toAllocate, $outstanding);
                    }

                    if ($apply > 0) {
                        // ✓ Record the allocation for audit trail ONLY
                        // ✗ DO NOT update paid_amount
                        // The return reduces the debt, not the payment amount

                        // ✅ Check if this is a return credit or cash payment
                        if ($returnId !== null) {
                            // Return credit allocation — register in return_credit_allocations
                            $this->insertReturnCreditAllocation((int) $returnId, (int) $originalSaleId, $apply, $createdBy);
                        } else {
                            // Cash payment allocation — register in payment_applications
                            $this->insertPaymentApplication($paymentId, 'sale', $originalSaleId, $apply, $createdBy);
                        }

                        $toAllocate -= $apply;

                        // ✅ Track if invoice became fully paid
                        // Use the calculated value directly - don't re-query payment_applications
                        $newOutstanding = $outstanding - $apply;
                        if ($newOutstanding <= 0.01) {
                            $settledInvoiceIds[] = [
                                'id' => $originalSaleId,
                                'newOutstanding' => $newOutstanding
                            ];
                        }
                    }
                }
            }

            // 2. باقي الفواتير المفتوحة بالترتيب الزمني
            if ($toAllocate > 0) {
                // ✅ FIXED: Query locks actual sales rows (not derived table)
                // Removed subquery to ensure FOR UPDATE locks real rows
                $stmt = $this->db->prepare("
                    SELECT s.id,
                           s.net_total_amount + IFNULL(s.tax_amount, 0) AS grand_total,
                           s.paid_amount,
                           s.sale_date
                    FROM sales s
                    WHERE s.customer_id = ?
                      AND s.tenant_id = ?
                      AND (s.net_total_amount + IFNULL(s.tax_amount, 0) - IFNULL(s.paid_amount, 0)) > 0
                    ORDER BY s.sale_date ASC, s.id ASC
                    FOR UPDATE
                ");
                $stmt->execute([$customerId, $tenantId]);
                $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

                foreach ($rows as $r) {
                    if ($toAllocate <= 0) {
                        break;
                    }

                    // تخطّي الفاتورة الأصلية (تمت معالجتها أعلاه)
                    if ($originalSaleId !== null && (int) $r['id'] === $originalSaleId) {
                        continue;
                    }

                    // ✅ FIXED: Calculate return_credits for this invoice separately (single point of calculation)
                    $rcStmt = $this->db->prepare("
                        SELECT COALESCE(SUM(allocated_amount), 0) AS return_credits
                        FROM return_credit_allocations
                        WHERE sale_id = ? AND tenant_id = ?
                    ");
                    $rcStmt->execute([$r['id'], $tenantId]);
                    $rcRow = $rcStmt->fetch(\PDO::FETCH_ASSOC);
                    $returnCredits = (float) ($rcRow['return_credits'] ?? 0.0);

                    $outstanding = max(0, (float) $r['grand_total'] - (float) $r['paid_amount'] - $returnCredits);
                    if ($outstanding <= 0) {
                        continue;
                    }

                    $apply = min($toAllocate, $outstanding);
                    if ($apply <= 0) {
                        continue;
                    }

                    // ✓ Record the allocation for audit trail
                    // If returnId is provided, register in return_credit_allocations (NOT payment_applications)
                    // Otherwise, use payment_applications for cash payments
                    if ($returnId !== null) {
                        // Return credit allocation — separate from cash payments
                        $this->insertReturnCreditAllocation((int) $returnId, (int) $r['id'], $apply, $createdBy);
                    } else {
                        // Cash payment allocation
                        $this->insertPaymentApplication($paymentId, 'sale', (int) $r['id'], $apply, $createdBy);
                    }

                    $toAllocate -= $apply;

                    // ✅ Track if invoice became fully paid
                    // Use the calculated value directly - don't re-query payment_applications
                    $newOutstanding = $outstanding - $apply;
                    if ($newOutstanding <= 0.01) {
                        $settledInvoiceIds[] = [
                            'id' => (int) $r['id'],
                            'newOutstanding' => $newOutstanding
                        ];
                    }
                }
            }

            // ✅ Update status for fully settled invoices
            // Use the calculated outstanding values - DO NOT re-query payment_applications
            // Payment applications may not exist if $paymentId is null (audit-only)
            if (!empty($settledInvoiceIds)) {
                try {
                    // 🔑 CRITICAL: Determine settlement type
                    // - If paymentId is null OR payment is a refund → 'closed_by_return' (settled by credit note)
                    // - If paymentId is actual payment (type='payment') → 'paid' (actual cash received)
                    $settlementType = 'closed_by_return'; // Default: settled by return credit

                    if ($paymentId !== null) {
                        $stmt = $this->db->prepare(
                            "SELECT payment_type FROM payments WHERE id = ? AND tenant_id = ?"
                        );
                        $stmt->execute([$paymentId, $tenantId]);
                        $paymentType = $stmt->fetchColumn();

                        // Only mark as 'paid' if this is an actual cash/bank payment
                        if ($paymentType === 'payment') {
                            $settlementType = 'paid';
                        }
                        // If payment_type='refund', stay with 'closed_by_return'
                    }

                    foreach ($settledInvoiceIds as $invoiceData) {
                        $invoiceId = $invoiceData['id'];
                        $newOutstanding = $invoiceData['newOutstanding'];

                        if ($newOutstanding <= 0.01) {
                            // Get invoice grand total for UPDATE
                            $stmt = $this->db->prepare(
                                "SELECT net_total_amount, tax_amount FROM sales WHERE id = ? AND tenant_id = ?"
                            );
                            $stmt->execute([$invoiceId, $tenantId]);
                            $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

                            if ($invoice) {
                                // ✅ CRITICAL ACCOUNTING RULE:
                                // Only update STATUS, NEVER update paid_amount
                                //
                                // paid_amount = actual cash/bank received only
                                // return_credit_allocations = credit notes (separate tracking)
                                //
                                // Settlement is determined by:
                                //   outstanding = grand_total - paid_amount - return_credits <= 0
                                //
                                // NOT by updating paid_amount to grandTotal
                                // That would incorrectly claim all money was paid in cash

                                $this->db->prepare(
                                    "UPDATE sales SET status = ? WHERE id = ? AND tenant_id = ?"
                                )->execute([$settlementType, $invoiceId, $tenantId]);
                            }

                            (MonologHandler::getInstance('returns'))->info('Invoice settled', [
                                'tenant_id' => $tenantId,
                                'sale_id' => $invoiceId,
                                'customer_id' => $customerId,
                                'settlement_type' => $settlementType,
                                'new_outstanding' => $newOutstanding,
                            ]);
                        }
                    }
                } catch (\Throwable $e) {
                    (MonologHandler::getInstance('returns'))->warning('Failed to update invoice status after allocation', [
                        'tenant_id' => $tenantId,
                        'message' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // نُسجّل الخطأ دون إيقاف العملية الرئيسية
            (MonologHandler::getInstance('returns'))->warning('allocateCustomerBalance: failed', [
                'tenant_id'   => $tenantId,
                'customer_id' => $customerId,
                'payment_id'  => $paymentId,
                'message'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * Helper: إدراج سجل payment_application — best-effort (لا يوقف العملية عند الفشل).
     */
    private function insertPaymentApplication(
        ?int   $paymentId,
        string $refType,
        int    $refId,
        float  $amount,
        ?int   $createdBy
    ): void {
        if ($paymentId === null) {
            return;
        }
        try {
            $this->db->prepare("
                INSERT INTO payment_applications
                    (tenant_id, payment_id, reference_type, reference_id, amount, created_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ")->execute([$this->tenantId, $paymentId, $refType, $refId, $amount, $createdBy ?? 1]);
        } catch (\Throwable $e) {
            (MonologHandler::getInstance('returns'))->warning('insertPaymentApplication: failed', [
                'payment_id' => $paymentId,
                'ref_id'     => $refId,
                'message'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * Helper: تسجيل تطبيق return credit على فاتورة في جدول return_credit_allocations.
     * هذا منفصل تماماً عن payment_applications الذي يخص الدفعات النقدية فقط.
     *
     * @param int $returnId معرف المرتجع
     * @param int $saleId معرف الفاتورة
     * @param float $allocatedAmount المبلغ المطبق
     * @param int|null $createdBy معرف المستخدم الذي نفّذ العملية
     */
    private function insertReturnCreditAllocation(
        int    $returnId,
        int    $saleId,
        float  $allocatedAmount,
        ?int   $createdBy
    ): void {
        try {
            $this->db->prepare("
                INSERT INTO return_credit_allocations
                    (tenant_id, return_id, sale_id, allocated_amount, created_by, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    allocated_amount = VALUES(allocated_amount),
                    created_at = NOW()
            ")->execute([$this->tenantId, $returnId, $saleId, $allocatedAmount, $createdBy ?? 1]);
        } catch (\Throwable $e) {
            (MonologHandler::getInstance('returns'))->warning('insertReturnCreditAllocation: failed', [
                'return_id' => $returnId,
                'sale_id'   => $saleId,
                'message'   => $e->getMessage(),
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // getDetails — جلب تفاصيل المرتجع الكاملة (منقولة من ReturnsHandler::getReturnDetails)
    // -------------------------------------------------------------------------

    /**
     * جلب تفاصيل مرتجع واحد كاملاً مع القيود المحاسبية والمدفوعات.
     * يُصلح تلقائياً reference_type في journal_entries إذا كانت غير صحيحة.
     *
     * @return array|null  بيانات المرتجع أو null إذا لم يوجد
     */
    public function getDetails(int $returnId, string $locale = 'ar'): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM returns WHERE id = ? AND tenant_id = ?"
        );
        $stmt->execute([$returnId, $this->tenantId]);
        $return = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$return) {
            return null;
        }

        // طريقة الدفع
        if (!empty($return['payment_method_id'])) {
            $s = $this->db->prepare(
                "SELECT name FROM payment_methods WHERE id = ? AND tenant_id = ? LIMIT 1"
            );
            $s->execute([$return['payment_method_id'], $this->tenantId]);
            $return['payment_method_name'] = $s->fetchColumn() ?: null;
        } else {
            $return['payment_method_name'] = null;
        }

        // اسم العميل
        $return['customer_name'] = null;
        if (!empty($return['customer_id'])) {
            $s = $this->db->prepare(
                "SELECT name FROM customers WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL)
                 ORDER BY (tenant_id IS NULL) ASC LIMIT 1"
            );
            $s->execute([$return['customer_id'], $this->tenantId]);
            $return['customer_name'] = $s->fetchColumn() ?: null;
        }

        // اسم المورد
        $return['supplier_name'] = null;
        if (!empty($return['supplier_id'])) {
            $s = $this->db->prepare(
                "SELECT name FROM suppliers WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL)
                 ORDER BY (tenant_id IS NULL) ASC LIMIT 1"
            );
            $s->execute([$return['supplier_id'], $this->tenantId]);
            $return['supplier_name'] = $s->fetchColumn() ?: null;
        }

        // اسم المنشئ
        $return['created_by_name'] = null;
        if (!empty($return['created_by'])) {
            $s = $this->db->prepare(
                "SELECT name FROM users WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL)
                 ORDER BY (tenant_id IS NULL) ASC LIMIT 1"
            );
            $s->execute([$return['created_by'], $this->tenantId]);
            $return['created_by_name'] = $s->fetchColumn() ?: null;
        }

        // عناصر المرتجع
        $s = $this->db->prepare(
            "SELECT ri.*, p.name AS product_name, p.product_code
             FROM return_items ri
             JOIN products p ON ri.product_id = p.id AND p.tenant_id = ri.tenant_id
             WHERE ri.return_id = ? AND ri.tenant_id = ?"
        );
        $s->execute([$returnId, $this->tenantId]);
        $return['items'] = $s->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        // المدفوعات (cash_transactions)
        $s = $this->db->prepare(
            "SELECT ct.id, ct.amount, ct.type, ct.payment_method_id,
                    pm.name AS payment_method_name,
                    ct.description, ct.created_at, ct.status, ct.journal_entry_id
             FROM cash_transactions ct
             LEFT JOIN payment_methods pm
               ON pm.id = ct.payment_method_id
              AND (pm.tenant_id = ct.tenant_id OR pm.tenant_id IS NULL)
             WHERE ct.tenant_id = ? AND ct.return_id = ?
               AND ct.type = 'return_payment'
               AND (ct.status IS NULL OR ct.status IN ('completed', 'approved'))
             ORDER BY ct.created_at ASC"
        );
        $s->execute([$this->tenantId, $returnId]);
        $payments = $s->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        foreach ($payments as &$pFmt) {
            if (isset($pFmt['amount'])) {
                $pFmt['amount'] = number_format((float) $pFmt['amount'], 2, '.', '');
            }
        }
        unset($pFmt);

        $paymentsTotal = 0.0;
        foreach ($payments as $p) {
            $paymentsTotal += (float) ($p['amount'] ?? 0);
        }

        $return['payments']       = $payments;
        $return['payments_total'] = number_format($paymentsTotal, 2, '.', '');

        $grandTotal = isset($return['grand_total'])
            ? (float) $return['grand_total']
            : ((float) ($return['total_amount'] ?? 0) + (float) ($return['tax_amount'] ?? 0));

        $return['net_due'] = number_format($grandTotal - $paymentsTotal, 2, '.', '');

        foreach (['total_amount', 'tax_amount', 'discount_amount', 'grand_total', 'paid_amount'] as $key) {
            if (isset($return[$key])) {
                $return[$key] = number_format((float) $return[$key], 2, '.', '');
            }
        }

        // القيد المحاسبي
        $this->attachJournalEntry($return, $returnId);

        // labels للمدفوعات
        if (!empty($return['payments'])) {
            foreach ($return['payments'] as &$payment) {
                $payment['reference_type']  = 'return_payment';
                $payment['reference_id']    = $payment['id'] ?? null;
                $payment['reference']       = 'return_payment#' . ($payment['id'] ?? '');
                $payment['reference_label'] = LabelService::refLabel('return_payment', $locale);
                $payment['status_label']    = LabelService::statusLabel($payment['status'] ?? 'completed', $locale);
                $payment['journal_entry']               = null;
                $payment['journal_reference_type']      = null;
                $payment['journal_reference_id']        = null;

                if (!empty($payment['journal_entry_id'])) {
                    $pJe = $this->fetchJournalEntryWithLines((int) $payment['journal_entry_id']);
                    if ($pJe) {
                        $payment['journal_entry']          = $pJe;
                        $payment['journal_reference_type'] = $pJe['reference_type'] ?? null;
                        $payment['journal_reference_id']   = $pJe['reference_id']   ?? null;
                    }
                }
            }
            unset($payment);
        }

        return $return;
    }

    /**
     * جلب قيد محاسبي مع سطوره — helper داخلي.
     */
    private function fetchJournalEntryWithLines(int $jeId): ?array
    {
        $s = $this->db->prepare(
            "SELECT je.* FROM journal_entries je WHERE je.id = ? AND je.tenant_id = ?"
        );
        $s->execute([$jeId, $this->tenantId]);
        $je = $s->fetch(\PDO::FETCH_ASSOC) ?: null;

        if (!$je) {
            return null;
        }

        $s = $this->db->prepare(
            "SELECT jel.*, a.name AS account_name, a.code AS account_code
             FROM journal_entry_lines jel
             JOIN accounts a ON a.id = jel.account_id
             WHERE jel.journal_entry_id = ?"
        );
        $s->execute([$je['id']]);
        $je['lines'] = $s->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        return $je;
    }

    /**
     * يربط القيد المحاسبي بمصفوفة المرتجع — يُصلح reference_type إذا لزم.
     */
    private function attachJournalEntry(array &$return, int $returnId): void
    {
        if (!empty($return['journal_entry_id'])) {
            $je = $this->fetchJournalEntryWithLines((int) $return['journal_entry_id']);
            if ($je) {
                $je['date'] = $je['entry_date'] ?? $return['return_date'] ?? null;
            }
            $return['journal_entry']          = $je;
            $return['journal_reference_type'] = $je['reference_type'] ?? null;
            $return['journal_reference_id']   = $je['reference_id']   ?? null;
            return;
        }

        // بحث عن قيد مرتبط عبر reference_type الصحيح أو 'return'
        $desiredRefType = match ($return['return_type'] ?? '') {
            'sale'     => 'sale_return',
            'purchase' => 'purchase_return',
            default    => null,
        };

        $jeRef = null;
        if ($desiredRefType) {
            $s = $this->db->prepare(
                "SELECT je.* FROM journal_entries je
                 WHERE je.tenant_id = ? AND je.reference_type = ? AND je.reference_id = ?
                 ORDER BY je.id DESC LIMIT 1"
            );
            $s->execute([$this->tenantId, $desiredRefType, $returnId]);
            $jeRef = $s->fetch(\PDO::FETCH_ASSOC) ?: null;
        }

        if (!$jeRef) {
            $s = $this->db->prepare(
                "SELECT je.* FROM journal_entries je
                 WHERE je.tenant_id = ? AND je.reference_type = 'return' AND je.reference_id = ?
                 ORDER BY je.id DESC LIMIT 1"
            );
            $s->execute([$this->tenantId, $returnId]);
            $jeRef = $s->fetch(\PDO::FETCH_ASSOC) ?: null;
        }

        if ($jeRef) {
            $s = $this->db->prepare(
                "SELECT jel.*, a.name AS account_name, a.code AS account_code
                 FROM journal_entry_lines jel
                 JOIN accounts a ON a.id = jel.account_id
                 WHERE jel.journal_entry_id = ?"
            );
            $s->execute([$jeRef['id']]);
            $jeRef['lines'] = $s->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            $jeRef['date']  = $jeRef['entry_date'] ?? $return['return_date'] ?? null;

            $return['journal_entry']          = $jeRef;
            $return['journal_reference_type'] = $jeRef['reference_type'] ?? null;
            $return['journal_reference_id']   = $jeRef['reference_id']   ?? null;
            $return['journal_entry_id']       = $jeRef['id'];

            // إصلاح journal_entry_id على المرتجع
            try {
                $this->db->prepare(
                    "UPDATE returns SET journal_entry_id = ?
                     WHERE id = ? AND tenant_id = ?
                       AND (journal_entry_id IS NULL OR journal_entry_id = 0)"
                )->execute([$jeRef['id'], $returnId, $this->tenantId]);
            } catch (\Throwable $e) {
            }

            // إصلاح reference_type على القيد
            if ($desiredRefType && ($jeRef['reference_type'] ?? null) !== $desiredRefType) {
                try {
                    $this->db->prepare(
                        "UPDATE journal_entries SET reference_type = ? WHERE id = ? AND tenant_id = ?"
                    )->execute([$desiredRefType, $jeRef['id'], $this->tenantId]);
                    $return['journal_reference_type']              = $desiredRefType;
                    $return['journal_entry']['reference_type']     = $desiredRefType;
                } catch (\Throwable $e) {
                }
            }
        } else {
            $return['journal_entry']          = null;
            $return['journal_reference_type'] = null;
            $return['journal_reference_id']   = null;
        }
    }

    // =========================================================================
    // createReturn — full return creation flow extracted from ReturnsHandler
    // =========================================================================

    /**
     * إنشاء مرتجع كامل (مبيعات أو مشتريات).
     *
     * @param  array  $data       بيانات المرتجع (return_type, items, paid_amount, ...)
     * @param  int    $tenantId
     * @param  int    $userId
     * @param  int|null $sessionId  جلسة الكاشير (تُحسب في الـ Handler)
     * @return array  ['return_id' => int, 'return_number' => string]
     * @throws \Exception on validation or DB error
     */
    public function createReturn(array $data, int $tenantId, int $userId, ?int $sessionId = null): array
    {
        // ── حساب الإجماليات ───────────────────────────────────────────────────
        $grossTotal = 0.0;
        $taxTotal   = 0.0;
        foreach ($data['items'] as $item) {
            $grossTotal += (float) ($item['subtotal'] ?? 0);
            if (isset($item['tax_amount'])) {
                $taxTotal += (float) $item['tax_amount'];
            }
        }

        $totalAfterDiscount    = $grossTotal;
        $providedDiscountValue = isset($data['discount_value']) ? (float) $data['discount_value'] : null;
        $providedDiscountType  = $data['discount_type'] ?? 'fixed';
        $discountAmount        = 0.0;

        if ($providedDiscountValue !== null && $providedDiscountValue > 0) {
            // ⚠️ إصلاح تناسق: خامس نسخة مستقلة من نفس صيغة الخصم في النظام. تم توحيدها مع
            // FinancialCalculationService (المستخدمة الآن في المشتريات والمبيعات).
            $discountAmount = $this->financialCalcService->calculateDiscount(
                $grossTotal,
                $providedDiscountValue,
                (string) $providedDiscountType
            );
            $totalAfterDiscount = max(0, $grossTotal - $discountAmount);
        }

        $grandTotal = $totalAfterDiscount + $taxTotal;

        if ($data['paid_amount'] > $grandTotal) {
            throw new \Exception('paid_amount cannot exceed total amount');
        }

        // ── رقم المرتجع ───────────────────────────────────────────────────────
        $timestamp = strtotime((string) $data['return_date']);
        $datePart  = date('ymd', $timestamp);
        $prefix    = $data['return_type'] === 'sale' ? 'SR' : 'PR';

        $stmt = $this->db->prepare("
            SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(return_number, '-', -1) AS UNSIGNED)), 0) + 1 AS next_number
            FROM returns
            WHERE return_type = ? AND return_number LIKE ? AND tenant_id = ?
        ");
        $stmt->execute([$data['return_type'], "{$prefix}-{$datePart}-%", $tenantId]);
        $returnNumber = sprintf('%s-%s-%03d', $prefix, $datePart, $stmt->fetchColumn());

        // ── بيانات الفاتورة الأصلية ───────────────────────────────────────────
        $saleId      = null;
        $purchaseId  = null;
        $invoiceNumber = null;
        $branchId    = null;
        $deductFromCustomerBalance = 0.0;
        $refundMode  = $data['refund_mode'] ?? 'auto';
        $saleOutstanding     = null;
        $purchaseOutstanding = null;

        if ($data['return_type'] === 'sale') {
            $stmt = $this->db->prepare("
                SELECT id, invoice_number, branch_id,
                       total_amount AS original_total_amount,
                       discount_type AS original_discount_type,
                       discount_value AS original_discount_value,
                       net_total_amount, paid_amount, tax_rate, tax_amount
                FROM sales WHERE id = ? AND tenant_id = ?
            ");
            $stmt->execute([$data['invoice_id'], $tenantId]);
            $sale = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($sale) {
                $saleId        = $sale['id'];
                $invoiceNumber = $sale['invoice_number'];
                $branchId      = $sale['branch_id'];

                // تطبيق خصم الفاتورة الأصلية إذا لم يُمرَّر خصم صريح
                if (($providedDiscountValue === null || $providedDiscountValue == 0) && !empty($sale['original_total_amount'])) {
                    $origTotal     = (float) $sale['original_total_amount'];
                    $origDiscType  = $sale['original_discount_type'] ?? 'fixed';
                    $origDiscValue = (float) ($sale['original_discount_value'] ?? 0);
                    $origDiscAmount = ($origDiscType === 'percentage')
                        ? ($origTotal * ($origDiscValue / 100))
                        : $origDiscValue;
                    $saleNetTotal = (float) ($sale['net_total_amount'] ?? $origTotal);
                    if ($origTotal > 0 && $origDiscAmount > 0 && abs($grossTotal - $saleNetTotal) > 0.01 && $grossTotal > $saleNetTotal) {
                        $proRatedDisc       = ($grossTotal / $origTotal) * $origDiscAmount;
                        $totalAfterDiscount = max(0, $grossTotal - $proRatedDisc);
                    }
                }

                $saleGrandTotal  = (float) $sale['net_total_amount'] + (float) ($sale['tax_amount'] ?? 0);
                $saleOutstanding = max(0, $saleGrandTotal - (float) $sale['paid_amount']);

                if ($saleOutstanding > 0) {
                    if ($refundMode === 'auto' || $refundMode === 'deduct_and_return') {
                        $amountToDeductFromDebt    = min($saleOutstanding, $grandTotal);
                        $excessToReturn            = max(0, $grandTotal - $amountToDeductFromDebt);
                        $data['paid_amount']        = $excessToReturn;
                        $deductFromCustomerBalance  = $amountToDeductFromDebt;
                    } elseif ($refundMode === 'cash') {
                        throw new \Exception('لا يمكن رد نقدي لفاتورة آجلة أو غير مسددة بالكامل. سيتم خصم قيمة المرتجع من ذمة العميل.');
                    } else {
                        $data['paid_amount']       = 0;
                        $deductFromCustomerBalance = min($saleOutstanding, $grandTotal);
                    }
                } else {
                    // الفاتورة الأصلية مسددة بالكامل - لا نعيّن paid_amount هنا، سيتم حسابه بناءً على customerTotalOutstanding
                    // تأكد أن paid_amount غير محدود بالقيمة المرسلة من الواجهة
                }

                // إعادة حساب الضريبة إذا لم تُمرَّ
                if ($taxTotal <= 0) {
                    $effectiveTaxRate = isset($data['tax_rate']) ? (float) $data['tax_rate']
                        : (isset($sale['tax_rate']) ? (float) $sale['tax_rate'] : null);
                    if ($effectiveTaxRate && $effectiveTaxRate > 0) {
                        $taxTotal = round($totalAfterDiscount * ($effectiveTaxRate / 100), 2);
                    }
                }
                $grandTotal = $totalAfterDiscount + $taxTotal;

                // ── حساب paid_amount بناءً على الديون والـ refund_mode ────────────────────
                if ($saleOutstanding === 0) {
                    // الفاتورة الأصلية مسددة بالكامل = العميل دفع نقداً = يجب رد المبلغ نقداً
                    // في mode=auto، يكون ذكياً: خصم من ديون فواتير أخرى أولاً، ثم رد الباقي نقداً
                    // لا فرق بين auto و deduct_and_return في المنطق - الفرق فقط في اسم الـ mode

                    if ($refundMode === 'auto' || $refundMode === 'deduct_and_return') {
                        // A cash sale without a customer has no customer debt to offset; refund the excess as cash.
                        $customerTotalOutstanding = !empty($data['party_id'])
                            ? $this->getCustomerTotalOutstanding((int) $data['party_id'], $tenantId)
                            : 0.0;
                        $deductFromCustomerBalance = min($customerTotalOutstanding, $grandTotal);
                        $data['paid_amount'] = round(max(0, $grandTotal - $deductFromCustomerBalance), 2);
                    } elseif ($refundMode === 'cash') {
                        // cash mode: رد نقدي بالكامل
                        $data['paid_amount'] = $grandTotal;
                        $deductFromCustomerBalance = 0;
                    } else {
                        // Credit-note modes may offset only a real customer's outstanding balance.
                        $customerTotalOutstanding = !empty($data['party_id'])
                            ? $this->getCustomerTotalOutstanding((int) $data['party_id'], $tenantId)
                            : 0.0;
                        $data['paid_amount'] = 0;
                        $deductFromCustomerBalance = min($customerTotalOutstanding, $grandTotal);
                    }
                }

                if ($data['paid_amount'] > $grandTotal) {
                    $data['paid_amount'] = $grandTotal;
                }
            }
        } else {
            // مرتجع مشتريات
            $stmt = $this->db->prepare("
                SELECT id, invoice_number, branch_id,
                       (total_amount - discount_value + tax_amount) AS net_total_amount,
                       paid_amount, tax_rate
                FROM purchases WHERE id = ? AND tenant_id = ?
            ");
            $stmt->execute([$data['invoice_id'], $tenantId]);
            $purchase = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($purchase) {
                $purchaseId          = $purchase['id'];
                $invoiceNumber       = $purchase['invoice_number'];
                $branchId            = $purchase['branch_id'];
                $purchaseOutstanding = max(0, (float) $purchase['net_total_amount'] - (float) $purchase['paid_amount']);

                if ($purchaseOutstanding > 0) {
                    if (($data['refund_mode'] ?? null) === 'cash') {
                        throw new \Exception('لا يمكن استلام نقدي لمرتجع على فاتورة مشتريات آجلة أو غير مسددة بالكامل. سيتم خصم قيمة المرتجع من ذمة المورد.');
                    }
                    $data['paid_amount'] = 0;
                }

                if ($taxTotal <= 0) {
                    $effectiveTaxRate = isset($data['tax_rate']) ? (float) $data['tax_rate']
                        : (isset($purchase['tax_rate']) ? (float) $purchase['tax_rate'] : 0);
                    if ($effectiveTaxRate > 0) {
                        $taxTotal = round($totalAfterDiscount * ($effectiveTaxRate / 100), 2);
                    }
                }
                $grandTotal = $totalAfterDiscount + $taxTotal;

                // معالجة paid_amount بناءً على حالة الفاتورة والـ refund_mode
                if ($purchaseOutstanding === 0) {
                    // الفاتورة مسددة - فحص الديون الأخرى للمورد
                    $refundModePurchase = $data['refund_mode'] ?? 'auto';

                    if ($refundModePurchase === 'auto') {
                        // auto mode: خصم من ديون المورد إذا وجدت، وإلا فرد نقدي
                        // للتبسيط، نعامل كـ cash (رد نقدي)
                        $data['paid_amount'] = $grandTotal;
                    } elseif ($refundModePurchase === 'cash') {
                        // cash mode: رد نقدي بالكامل
                        $data['paid_amount'] = $grandTotal;
                    } elseif ($refundModePurchase === 'credit_note') {
                        // credit_note: لا رد نقدي
                        $data['paid_amount'] = 0;
                    } else {
                        // deduct_and_return أو آخر: رد نقدي
                        $data['paid_amount'] = $grandTotal;
                    }
                } else {
                    // الفاتورة بها ديون - تم تعيين paid_amount = 0 أعلاه
                    // لا نعدّل هنا
                }
                if ($data['paid_amount'] > $grandTotal) {
                    $data['paid_amount'] = $grandTotal;
                }
            }
        }

        // ── التحقق من الكميات ─────────────────────────────────────────────────
        // ── AUDIT FIX #6 (HIGH) ──────────────────────────────────────────────
        // Previously, the SUM(quantity) checks below (original qty vs. already
        // returned qty) ran with no row lock. Two concurrent return requests for
        // the SAME sale/purchase could both read the same "remaining quantity"
        // before either INSERTs its return_items row, and both pass validation —
        // over-returning more than was actually sold/purchased, with two
        // separate (individually balanced) journal entries compounding the
        // error. `createReturn()` already runs inside a transaction opened by
        // the caller (ReturnsHandler), so acquiring a `FOR UPDATE` lock on the
        // original invoice row here serializes concurrent return requests for
        // the same invoice: the second request blocks until the first commits,
        // and — since InnoDB locking reads fetch the latest committed data, not
        // the transaction's original snapshot — its subsequent SUM() queries
        // below correctly see the first request's already-inserted return_items
        // row. This mirrors the FOR UPDATE pattern already used elsewhere in
        // this same file (e.g. the payment-allocation lock above).
        if ($saleId) {
            $this->db->prepare("SELECT id FROM sales WHERE id = ? AND tenant_id = ? FOR UPDATE")
                ->execute([$saleId, $tenantId]);
        } elseif ($purchaseId) {
            $this->db->prepare("SELECT id FROM purchases WHERE id = ? AND tenant_id = ? FOR UPDATE")
                ->execute([$purchaseId, $tenantId]);
        }

        if ($saleId || $purchaseId) {
            foreach ($data['items'] as $index => $item) {
                $productId    = (int) $item['product_id'];
                $requestedQty = (float) $item['quantity'];

                $stmtProd = $this->db->prepare("SELECT name FROM products WHERE id = ? AND tenant_id = ?");
                $stmtProd->execute([$productId, $tenantId]);
                $productName = $stmtProd->fetchColumn() ?: '#' . $productId;

                if ($data['return_type'] === 'sale' && $saleId) {
                    $stmtOrig = $this->db->prepare("SELECT COALESCE(SUM(quantity), 0) FROM sales_items WHERE sale_id = ? AND product_id = ? AND tenant_id = ?");
                    $stmtOrig->execute([$saleId, $productId, $tenantId]);
                    $originalQty = (float) $stmtOrig->fetchColumn();
                    if ($originalQty <= 0) {
                        throw new \Exception("الصنف {$productName} غير موجود في الفاتورة الأصلية، لا يمكن إرجاعه");
                    }

                    $stmtPrev = $this->db->prepare("SELECT COALESCE(SUM(ri.quantity), 0) FROM return_items ri JOIN returns r ON r.id = ri.return_id WHERE r.tenant_id = ? AND r.return_type = 'sale' AND r.sale_id = ? AND ri.product_id = ?");
                    $stmtPrev->execute([$tenantId, $saleId, $productId]);
                    $remainingQty = max(0.0, $originalQty - (float) $stmtPrev->fetchColumn());

                    if (bccomp((string) $remainingQty, '0', 2) <= 0) {
                        throw new \Exception("تم إرجاع كامل الكمية للصنف {$productName} سابقاً، لا يمكن إرجاع كمية إضافية");
                    }
                    if ($requestedQty > $remainingQty) {
                        throw new \Exception("لا يمكن إرجاع كمية أكبر من الكمية المباعة للصنف {$productName}. الكمية المسموح بها حالياً: {$remainingQty}");
                    }
                } elseif ($data['return_type'] === 'purchase' && $purchaseId) {
                    $stmtOrig = $this->db->prepare("SELECT COALESCE(SUM(quantity), 0) FROM purchase_items WHERE purchase_id = ? AND product_id = ? AND tenant_id = ?");
                    $stmtOrig->execute([$purchaseId, $productId, $tenantId]);
                    $originalQty = (float) $stmtOrig->fetchColumn();
                    if ($originalQty <= 0) {
                        throw new \Exception("الصنف {$productName} غير موجود في فاتورة المشتريات الأصلية، لا يمكن إرجاعه");
                    }

                    $stmtPrev = $this->db->prepare("SELECT COALESCE(SUM(ri.quantity), 0) FROM return_items ri JOIN returns r ON r.id = ri.return_id WHERE r.tenant_id = ? AND r.return_type = 'purchase' AND r.purchase_id = ? AND ri.product_id = ?");
                    $stmtPrev->execute([$tenantId, $purchaseId, $productId]);
                    $remainingQty = max(0.0, $originalQty - (float) $stmtPrev->fetchColumn());

                    if (bccomp((string) $remainingQty, '0', 2) <= 0) {
                        throw new \Exception("تم إرجاع كامل الكمية للصنف {$productName} سابقاً، لا يمكن إرجاع كمية إضافية");
                    }
                    if ($requestedQty > $remainingQty) {
                        throw new \Exception("لا يمكن إرجاع كمية أكبر من الكمية المشتراة للصنف {$productName}. الكمية المسموح بها حالياً: {$remainingQty}");
                    }
                }
            }
        }

        // ── INSERT returns ────────────────────────────────────────────────────
        $methodIsCash = $this->isCashMethodInternal((int) $data['payment_method_id'], $tenantId);
        $isCash       = $methodIsCash && $data['paid_amount'] > 0;

        $costCenterId = $data['cost_center_id'] ?? null;

        // Persist invoice_id as the original document reference; sale_id/purchase_id remain typed relations.
        $sql = "INSERT INTO returns (
                    tenant_id, return_type, customer_id, supplier_id, return_number, return_date,
                    total_amount, tax_amount, discount_amount, paid_amount, notes,
                    status, payment_method_id, created_by, cost_center_id, created_at,
                    invoice_number, invoice_id, sale_id, purchase_id, branch_id, is_cash
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'approved', ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?)";

        $stmt = $this->db->prepare($sql);
        $maxAttempts = 5;
        $attempt     = 0;

        while (true) {
            try {
                $attempt++;
                $stmt->execute([
                    $tenantId,
                    $data['return_type'],
                    $data['return_type'] === 'sale' ? $data['party_id'] : null,
                    $data['return_type'] === 'purchase' ? $data['party_id'] : null,
                    $returnNumber,
                    $data['return_date'],
                    $totalAfterDiscount,
                    $taxTotal,
                    $discountAmount,
                    $data['paid_amount'],
                    $data['notes'] ?? null,
                    $data['payment_method_id'],
                    $userId,
                    $costCenterId,
                    $invoiceNumber,
                    !empty($data['invoice_id']) ? (int) $data['invoice_id'] : null,
                    $saleId,
                    $purchaseId,
                    $branchId,
                    $isCash,
                ]);
                break;
            } catch (PDOException $e) {
                if ($attempt >= $maxAttempts) {
                    throw $e;
                }
                $returnNumber  = $returnNumber  . '-' . date('His') . '-' . mt_rand(100, 999);
                $invoiceNumber = ($invoiceNumber ?? '') . '-' . date('His') . '-' . mt_rand(100, 999);
            }
        }

        $returnId = (int) $this->db->lastInsertId();

        // ✅ Update refund_amount and refund_method in returns table (Problem #4 fix)
        // Determine refund_method dynamically from actual payment method kind
        if ($data['return_type'] === 'sale' && $data['paid_amount'] > 0) {
            $refundMethod = 'cash'; // Default fallback

            // Fetch the actual kind from payment_methods (cash, bank, card, wallet, credit)
            $stmtMethod = $this->db->prepare(
                "SELECT kind FROM payment_methods WHERE id = ? AND tenant_id = ? LIMIT 1"
            );
            $stmtMethod->execute([(int) $data['payment_method_id'], $tenantId]);
            $methodKind = $stmtMethod->fetchColumn();

            if ($methodKind) {
                $refundMethod = $methodKind;
            }

            // ✅ Update both refund_amount and refund_method with correct method kind
            $this->db->prepare(
                "UPDATE returns SET refund_amount = ?, refund_method = ? 
                 WHERE id = ? AND tenant_id = ?"
            )->execute([$data['paid_amount'], $refundMethod, $returnId, $tenantId]);
        }

        // ── INSERT return_items + stock ───────────────────────────────────────
        $stmtItems = $this->db->prepare("
            INSERT INTO return_items (
                return_id, product_id, unit_id, quantity,
                unit_price, tax_rate, tax_amount, discount, discount_amount, subtotal,
                batch_number, expiry_date, serial, created_at, tenant_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
        ");

        foreach ($data['items'] as $item) {
            $stmtItems->execute([
                $returnId,
                $item['product_id'],
                $item['unit_id'],
                $item['quantity'],
                $item['unit_price'],
                isset($item['tax_rate']) ? (float) $item['tax_rate'] : 0,
                isset($item['tax_amount']) ? (float) $item['tax_amount'] : 0,
                isset($item['discount']) ? (float) $item['discount'] : 0,
                isset($item['discount_amount']) ? (float) $item['discount_amount'] : 0,
                $item['subtotal'],
                $item['batch_number'] ?? null,
                $item['expiry_date']  ?? null,
                $item['serial']       ?? null,
                $tenantId,
            ]);

            $this->updateStock(
                (int) $item['product_id'],
                (int) $item['unit_id'],
                (float) $item['quantity'],
                (int) $branchId,
                $returnId,
                $data['return_type'],
                $item['batch_number'] ?? null,
                $item['expiry_date']  ?? null,
                $item['serial']       ?? null
            );
        }

        // ── القيد المحاسبي ────────────────────────────────────────────────────
        $originalOutstanding = $data['return_type'] === 'sale' ? $saleOutstanding : $purchaseOutstanding;

        $journalEntryId = $this->accounting->postReturnJournalEntry(
            $returnId,
            $tenantId,
            (string) $data['return_date'],
            $returnNumber,
            $data['return_type'],
            $data['party_id'] ?? null,
            $totalAfterDiscount,
            (float) $data['paid_amount'],
            (int) $data['payment_method_id'],
            $taxTotal,
            $userId,
            (string) ($refundMode ?? 'auto'),
            (float) ($deductFromCustomerBalance ?? 0),
            $originalOutstanding,
            $costCenterId
        );

        $this->db->prepare("UPDATE returns SET journal_entry_id = ? WHERE id = ? AND tenant_id = ?")
            ->execute([$journalEntryId, $returnId, $tenantId]);

        // ── AUDIT FINDING FIX (new, discovered while tracing supplier statements) ──
        // مرتجعات المشتريات لم تكن تُحدِّث purchases.paid_amount/status إطلاقًا —
        // القيد المحاسبي (GL) كان يُخفِّض ذمة المورد بشكل صحيح، بينما فاتورة
        // الشراء الفرعية تبقى وكأن المرتجع لم يحدث للأبد. راجع التعليق الكامل
        // في PurchaseService::recalculateBalance()/determinePurchaseStatus().
        if ($data['return_type'] === 'purchase' && $purchaseId) {
            (new PurchaseService($this->db, $tenantId, $userId))->recalculateBalance((int) $purchaseId);
        }

        // ── INSERT payments ───────────────────────────────────────────────────
        $paymentType  = $data['return_type'] === 'sale' ? 'return_payment' : 'payment_for_return';
        $paymentNotes = $data['notes'] ?? ('مرتجع ' . ($data['return_type'] === 'sale' ? 'مبيعات' : 'مشتريات') . ' #' . $returnNumber);
        $currency     = $this->settingsRepo->get($tenantId, 'company.currency', 'EGP') ?: 'EGP';

        $this->db->prepare("
            INSERT INTO payments (
                tenant_id, payment_date, return_id, amount,
                payment_method_id, notes, created_by, created_at, type,
                sale_id, purchase_id, customer_id, supplier_id, session_id, cost_center_id, journal_entry_id, currency
            ) VALUES (?, NOW(), ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $tenantId,
            $returnId,
            $data['paid_amount'],
            $data['payment_method_id'],
            $paymentNotes,
            $userId,
            $paymentType,
            $saleId,
            $purchaseId,
            $data['return_type'] === 'sale' ? ($data['party_id'] ?? null) : null,
            $data['return_type'] === 'purchase' ? ($data['party_id'] ?? null) : null,
            $sessionId,
            $costCenterId,
            $journalEntryId,
            $currency,
        ]);

        $paymentId = (int) $this->db->lastInsertId();

        // ── cash_transactions ─────────────────────────────────────────────────
        if ($isCash && $data['paid_amount'] > 0) {
            $cashTransactionType = $data['return_type'] === 'sale' ? 'return_payment' : 'return_receipt';
            $customerId  = $data['return_type'] === 'sale' ? ($data['party_id'] ?? null) : null;
            $supplierId  = $data['return_type'] === 'purchase' ? ($data['party_id'] ?? null) : null;

            $this->db->prepare("
                INSERT INTO cash_transactions (
                    tenant_id, type, amount, payment_method_id, notes, created_by,
                    created_at, return_id, reference_type, reference_id,
                    customer_id, supplier_id, session_id, cost_center_id, journal_entry_id, branch_id
                ) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $tenantId,
                $cashTransactionType,
                $data['paid_amount'],
                $data['payment_method_id'],
                $data['notes'] ?? ('مرتجع ' . ($data['return_type'] === 'sale' ? 'مبيعات' : 'مشتريات') . ' #' . $returnNumber),
                $userId,
                $returnId,
                'return',
                $returnId,
                $customerId,
                $supplierId,
                $sessionId,
                $costCenterId,
                $journalEntryId,
                $branchId,
            ]);
        }

        // ── توزيع رصيد العميل ─────────────────────────────────────────────────
        if ($data['return_type'] === 'sale' && !empty($data['party_id']) && $deductFromCustomerBalance > 0) {
            // ✅ IMPORTANT: Always pass originalSaleId to register allocation on the original invoice first
            // Then allocateCustomerBalance() will distribute remaining balance to other invoices (FIFO by date)
            // This ensures the allocation appears in the API response for the invoice with the return
            $originalSaleIdToUse = (int) $saleId;

            $this->allocateCustomerBalance(
                (int) $data['party_id'],
                (float) $deductFromCustomerBalance,
                $originalSaleIdToUse,  // Always pass original sale ID for allocation registration
                $paymentId,
                $userId,
                $returnId  // معرّف المرتجع — لتسجيل allocations في جدول return_credit_allocations
            );
        }

        // ── Update original sale status if full return ────────────────────────
        // ✅ REMOVED: Duplicate status update block (2026-06-15)
        // allocateCustomerBalance() now handles all status updates with proper logic:
        // - Compares grand_total against (paid_amount + return_credits)
        // - Sets settlement_type='closed_by_return' OR 'paid' based on payment type
        // - Ensures no conflicting status values from multiple sources
        //
        // Removing this block prevents:
        // (1) Simple comparison (returnGrandTotal >= originalGrandTotal) ignoring prior payments
        // (2) Duplicate status updates that conflict with allocateCustomerBalance()
        // (3) Incorrect 'closed_by_return' when invoice had prior cash payments
        //
        // The status is now updated ONLY by allocateCustomerBalance() after all allocations are processed.

        return [
            'return_id'     => $returnId,
            'return_number' => $returnNumber,
            'journal_entry_id' => $journalEntryId,
            'payment_id'    => $paymentId,
        ];
    }

    // ── Private helpers for createReturn ─────────────────────────────────────

    private function isCashMethodInternal(int $paymentMethodId, int $tenantId): bool
    {
        try {
            $stmt = $this->db->prepare("SELECT kind FROM payment_methods WHERE id = ? AND tenant_id = ? LIMIT 1");
            $stmt->execute([$paymentMethodId, $tenantId]);
            return strtolower((string) ($stmt->fetchColumn() ?: '')) === 'cash';
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function getCustomerTotalOutstanding(int $customerId, int $tenantId): float
    {
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM((net_total_amount + IFNULL(tax_amount,0)) - IFNULL(paid_amount,0)), 0)
            FROM sales
            WHERE customer_id = ? AND tenant_id = ? AND status NOT IN ('closed_by_return', 'cancelled')
              AND ((net_total_amount + IFNULL(tax_amount,0)) - IFNULL(paid_amount,0)) > 0
        ");
        $stmt->execute([$customerId, $tenantId]);
        return (float) $stmt->fetchColumn();
    }
}
