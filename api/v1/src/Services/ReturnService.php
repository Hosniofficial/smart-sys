<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use Throwable;
use PDOException;
use App\Services\AccountingService;
use App\Services\CostingService;
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

    private function getSaleActualPaidAmount(int $saleId, int $tenantId): float
    {
        $stmt = $this->db->prepare("
            SELECT
                COALESCE((
                    SELECT SUM(CASE WHEN p.type = 'return_payment' THEN -p.amount ELSE p.amount END)
                    FROM payments p
                    WHERE p.sale_id = ? AND p.tenant_id = ?
                      AND COALESCE(p.is_draft, 0) = 0 AND p.status = 'completed'
                ), 0)
                + COALESCE((
                    SELECT SUM(cpa.allocated_amount)
                    FROM customer_payment_allocations cpa
                    INNER JOIN payments ap ON ap.id = cpa.payment_id AND ap.tenant_id = cpa.tenant_id
                    WHERE cpa.sale_id = ? AND cpa.tenant_id = ?
                      AND COALESCE(ap.is_draft, 0) = 0 AND ap.status = 'completed'
                ), 0)
        ");
        $stmt->execute([$saleId, $tenantId, $saleId, $tenantId]);

        return (float) $stmt->fetchColumn();
    }

    /**
     * Return the supplier's outstanding payable balance for auto-settlement.
     * Locking the supplier account serializes concurrent return settlements.
     */
    private function getSupplierPayableBalance(int $supplierId, int $tenantId): float
    {
        if ($supplierId <= 0) {
            return 0.0;
        }

        $forUpdate = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $stmt = $this->db->prepare(
            "SELECT a.id AS account_id, a.debit_balance, a.credit_balance
             FROM suppliers s
             INNER JOIN accounts a ON a.id = s.account_id AND a.tenant_id = s.tenant_id
             WHERE s.id = ? AND s.tenant_id = ?
             {$forUpdate}"
        );
        $stmt->execute([$supplierId, $tenantId]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$account) {
            return 0.0;
        }

        $ledgerBalanceStmt = $this->db->prepare(
            "SELECT COUNT(je.id), COALESCE(SUM(jel.credit_amount - jel.debit_amount), 0)
             FROM journal_entry_lines jel
             INNER JOIN journal_entries je ON je.id = jel.journal_entry_id AND je.tenant_id = jel.tenant_id
             WHERE jel.account_id = ? AND jel.tenant_id = ?
               AND (je.status IS NULL OR je.status IN ('posted', 'reversed'))
             {$forUpdate}"
        );
        $ledgerBalanceStmt->execute([(int) $account['account_id'], $tenantId]);
        [$journalEntryCount, $journalBalance] = $ledgerBalanceStmt->fetch(PDO::FETCH_NUM);
        if ((int) $journalEntryCount > 0) {
            return max(0.0, round((float) $journalBalance, 2));
        }

        return max(0.0, round((float) $account['credit_balance'] - (float) $account['debit_balance'], 2));
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
        $typeStmt = $this->db->prepare(
            "SELECT COALESCE(product_type, 'stock')
             FROM products WHERE id = ? AND tenant_id = ?"
        );
        $typeStmt->execute([$productId, $this->tenantId]);
        $productType = $typeStmt->fetchColumn();
        if ($productType === false) {
            throw new \DomainException('المنتج غير موجود في المنشأة.');
        }
        if (strtolower((string) $productType) === 'service') {
            return;
        }
        $serialStmt = $this->db->prepare(
            'SELECT COALESCE(has_serial_number, 0) FROM products WHERE id = ? AND tenant_id = ?'
        );
        $serialStmt->execute([$productId, $this->tenantId]);
        $tracksSerial = (int) $serialStmt->fetchColumn() === 1;

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
        $unitCost = 0.0;
        $totalCost = 0.0;
        $costingBasisDate = date('Y-m-d H:i:s');
        if ($returnType === 'sale') {
            $saleDateStmt = $this->db->prepare(
                'SELECT s.sale_date
                 FROM returns r
                 INNER JOIN sales s ON s.id = r.sale_id AND s.tenant_id = r.tenant_id
                 WHERE r.id = ? AND r.tenant_id = ?
                 LIMIT 1'
            );
            $saleDateStmt->execute([$returnId, $this->tenantId]);
            $originalSaleDate = $saleDateStmt->fetchColumn();
            if ($originalSaleDate !== false && $originalSaleDate !== null) {
                $costingBasisDate = (string) $originalSaleDate;
            }
        }
        $costing = new CostingService($this->db);
        $wacPerBaseUnit = $costing->getWeightedAverageCost(
            $this->tenantId,
            $productId,
            $costingBasisDate
        );
        if ($wacPerBaseUnit !== null && (float) $wacPerBaseUnit > 0) {
            $unitCost = round((float) $wacPerBaseUnit * $conversionFactor, 4);
            $totalCost = round($unitCost * $quantity, 4);
        }

        $costAdjustment = $totalCost * ($returnType === 'sale' ? 1 : -1);

        \App\Services\ProductBranchActivationGuard::assertActiveForStock(
            $this->db,
            $this->tenantId,
            $branchId,
            $productId
        );

        $expectedSerialStatus = $returnType === 'sale' ? 'sold' : 'in_stock';
        if ($tracksSerial) {
            if ($serial === null || trim($serial) === '') {
                throw new \DomainException('الرقم المسلسل مطلوب لإرجاع هذا المنتج.');
            }
            $serialStmt = $this->db->prepare(
                "SELECT id FROM product_serials
                 WHERE product_id = ? AND branch_id = ? AND serial_number = ?
                   AND tenant_id = ? AND status = ? FOR UPDATE"
            );
            $serialStmt->execute([
                $productId,
                $branchId,
                $serial,
                $this->tenantId,
                $expectedSerialStatus,
            ]);
            if (!$serialStmt->fetchColumn()) {
                throw new \DomainException(
                    $returnType === 'sale'
                        ? 'الرقم المسلسل المرتجع غير مسجل كمباع في هذا الفرع.'
                        : 'الرقم المسلسل غير متوفر في مخزون هذا الفرع لإرجاعه للمورد.'
                );
            }
        }

        if ($returnType === 'purchase') {
            $stockStmt = $this->db->prepare(
                'SELECT quantity FROM branch_products
                 WHERE tenant_id = ? AND branch_id = ? AND product_id = ? FOR UPDATE'
            );
            $stockStmt->execute([$this->tenantId, $branchId, $productId]);
            $availableQuantity = $stockStmt->fetchColumn();
            if ($availableQuantity === false || (float) $availableQuantity + 0.0001 < $baseQuantity) {
                throw new \App\Exceptions\InsufficientStockException(
                    "الكمية المتاحة للمنتج {$productId} غير كافية لإرجاع الكمية المطلوبة للمورد.",
                    $productId,
                    $availableQuantity === false ? 0.0 : (float) $availableQuantity,
                    $baseQuantity
                );
            }
        }

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

        if ($tracksSerial) {
            $serialUpdate = $this->db->prepare(
                'UPDATE product_serials
                 SET status = ?, transaction_id = ?
                 WHERE product_id = ? AND branch_id = ? AND serial_number = ?
                   AND tenant_id = ? AND status = ?'
            );
            $serialUpdate->execute([
                $returnType === 'sale' ? 'in_stock' : 'damaged',
                $returnType === 'sale' ? null : $inventoryTransactionId,
                $productId,
                $branchId,
                $serial,
                $this->tenantId,
                $expectedSerialStatus,
            ]);
            if ($serialUpdate->rowCount() !== 1) {
                throw new \DomainException('تعذر تحديث حالة الرقم المسلسل؛ تم إلغاء حركة المرتجع.');
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
     * @param int|null   $returnId              معرف المرتجع لتسجيل إشعار الدائن
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
        $affectedSaleIds = [];

        try {
            /**
             * IMPORTANT: We do NOT update sales.paid_amount
             *
             * Why? A return is a CREDIT NOTE that reduces the debt (outstanding),
             * not an actual payment. paid_amount should only increase when real money
             * is received (cash or bank transfer).
             *
             * Return-credit allocations are recorded separately from cash payment applications.
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

                    $actualPaid = $this->getSaleActualPaidAmount((int) $originalSaleId, $tenantId);
                    $outstanding = max(0, (float) $row['grand_total'] - $actualPaid - $returnCredits);

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
                        $affectedSaleIds[$originalSaleId] = true;
                    }
                }
            }

            // 2. باقي الفواتير المفتوحة بالترتيب الزمني
            if ($toAllocate > 0) {
                $stmt = $this->db->prepare("
                    SELECT s.id,
                           s.net_total_amount + IFNULL(s.tax_amount, 0) AS grand_total
                    FROM sales s
                    WHERE s.customer_id = ?
                      AND s.tenant_id = ?
                      AND LOWER(COALESCE(s.status, '')) NOT IN (
                          'draft', 'pending', 'pending_approval', 'awaiting_approval',
                          'rejected', 'canceled', 'cancelled'
                      )
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

                    $rcStmt = $this->db->prepare("
                        SELECT COALESCE(SUM(allocated_amount), 0) AS return_credits
                        FROM return_credit_allocations
                        WHERE sale_id = ? AND tenant_id = ?
                    ");
                    $rcStmt->execute([$r['id'], $tenantId]);
                    $rcRow = $rcStmt->fetch(\PDO::FETCH_ASSOC);
                    $returnCredits = (float) ($rcRow['return_credits'] ?? 0.0);
                    $actualPaid = $this->getSaleActualPaidAmount((int) $r['id'], $tenantId);
                    $outstanding = max(0, (float) $r['grand_total'] - $actualPaid - $returnCredits);
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
                    $affectedSaleIds[(int) $r['id']] = true;
                }
            }

            foreach (array_keys($affectedSaleIds) as $saleId) {
                $status = $this->accounting->recalculateSaleStatus($tenantId, (int) $saleId);
                (MonologHandler::getInstance('returns'))->info('Invoice recalculated after return credit allocation', [
                    'tenant_id' => $tenantId,
                    'sale_id' => (int) $saleId,
                    'customer_id' => $customerId,
                    'status' => $status['status'],
                    'outstanding' => $status['outstanding'],
                ]);
            }
        } catch (\Throwable $e) {
            (MonologHandler::getInstance('returns'))->error('allocateCustomerBalance failed; return transaction must roll back', [
                'tenant_id'   => $tenantId,
                'customer_id' => $customerId,
                'payment_id'  => $paymentId,
                'message'     => $e->getMessage(),
            ]);
            throw $e;
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
        $this->db->prepare("
            INSERT INTO return_credit_allocations
                (tenant_id, return_id, sale_id, allocated_amount, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                allocated_amount = VALUES(allocated_amount),
                created_at = NOW()
        ")->execute([$this->tenantId, $returnId, $saleId, $allocatedAmount, $createdBy ?? 1]);
    }

    // -------------------------------------------------------------------------
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
        if (!$this->db->inTransaction()) {
            throw new \LogicException('Return creation requires an active transaction.');
        }

        if (empty($data['items']) || !is_array($data['items'])) {
            throw new \DomainException('قائمة الأصناف المرتجعة غير صالحة.');
        }

        $returnType = (string) ($data['return_type'] ?? '');
        $invoiceId = (int) ($data['invoice_id'] ?? 0);
        if ($invoiceId <= 0 || !in_array($returnType, ['sale', 'purchase'], true)) {
            throw new \DomainException('الفاتورة الأصلية أو نوع المرتجع غير صالح.');
        }

        if ($returnType === 'sale') {
            $sourceStmt = $this->db->prepare(
                "SELECT id, customer_id, status, tax_rate
                 FROM sales WHERE id = ? AND tenant_id = ? FOR UPDATE"
            );
            $sourceStmt->execute([$invoiceId, $tenantId]);
            $sourceInvoice = $sourceStmt->fetch(PDO::FETCH_ASSOC);
            if (!$sourceInvoice) {
                throw new \DomainException('فاتورة المبيعات الأصلية غير موجودة.');
            }
            \App\Security\SaleWorkflowPolicy::assertReturnAllowed((string) $sourceInvoice['status']);
            $data['party_id'] = !empty($sourceInvoice['customer_id']) ? (int) $sourceInvoice['customer_id'] : null;
            $sourceItemsStmt = $this->db->prepare(
                'SELECT id, product_id, unit_id, quantity, net_total, batch_number, expiry_date, serial
                 FROM sales_items WHERE sale_id = ? AND tenant_id = ?'
            );
            $sourceItemsStmt->execute([$invoiceId, $tenantId]);
            $sourceItems = $sourceItemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $previousStmt = $this->db->prepare(
                "SELECT ri.product_id, ri.unit_id, COALESCE(SUM(ri.quantity), 0) AS returned_quantity
                 FROM return_items ri
                 INNER JOIN returns r ON r.id = ri.return_id
                 WHERE r.tenant_id = ? AND r.return_type = 'sale' AND r.sale_id = ?
                 GROUP BY ri.product_id, ri.unit_id"
            );
            $previousStmt->execute([$tenantId, $invoiceId]);
            $previousRows = $previousStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $previousQuantities = [];
            foreach ($previousRows as $row) {
                $previousQuantities[\App\Security\ReturnItemPolicy::groupKey(
                    (int) $row['product_id'],
                    (int) $row['unit_id']
                )] = (float) $row['returned_quantity'];
            }
            $normalized = \App\Security\ReturnItemPolicy::normalize(
                'sale',
                $data['items'],
                $sourceItems,
                $previousQuantities,
                (float) ($sourceInvoice['tax_rate'] ?? 0)
            );
        } else {
            $sourceStmt = $this->db->prepare(
                "SELECT id, supplier_id, status, tax_rate
                 FROM purchases WHERE id = ? AND tenant_id = ? FOR UPDATE"
            );
            $sourceStmt->execute([$invoiceId, $tenantId]);
            $sourceInvoice = $sourceStmt->fetch(PDO::FETCH_ASSOC);
            if (!$sourceInvoice) {
                throw new \DomainException('فاتورة المشتريات الأصلية غير موجودة.');
            }
            if (in_array(strtolower((string) $sourceInvoice['status']), ['draft', 'rejected', 'canceled', 'cancelled'], true)) {
                throw new \DomainException('لا يمكن إنشاء مرتجع لفاتورة مشتريات غير معتمدة أو ملغاة.');
            }
            $data['party_id'] = !empty($sourceInvoice['supplier_id']) ? (int) $sourceInvoice['supplier_id'] : null;
            $sourceItemsStmt = $this->db->prepare(
                'SELECT id, product_id, unit_id, quantity,
                        COALESCE(subtotal, total - COALESCE(discount_amount, 0) + COALESCE(tax_amount, 0)) AS subtotal,
                        tax_amount, batch_number, expiry_date, serial
                 FROM purchase_items WHERE purchase_id = ? AND tenant_id = ?'
            );
            $sourceItemsStmt->execute([$invoiceId, $tenantId]);
            $sourceItems = $sourceItemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $previousStmt = $this->db->prepare(
                "SELECT ri.product_id, ri.unit_id, COALESCE(SUM(ri.quantity), 0) AS returned_quantity
                 FROM return_items ri
                 INNER JOIN returns r ON r.id = ri.return_id
                 WHERE r.tenant_id = ? AND r.return_type = 'purchase' AND r.purchase_id = ?
                 GROUP BY ri.product_id, ri.unit_id"
            );
            $previousStmt->execute([$tenantId, $invoiceId]);
            $previousRows = $previousStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $previousQuantities = [];
            foreach ($previousRows as $row) {
                $previousQuantities[\App\Security\ReturnItemPolicy::groupKey(
                    (int) $row['product_id'],
                    (int) $row['unit_id']
                )] = (float) $row['returned_quantity'];
            }
            $normalized = \App\Security\ReturnItemPolicy::normalize(
                'purchase',
                $data['items'],
                $sourceItems,
                $previousQuantities
            );
        }
        $data['items'] = $normalized['items'];
        $data['discount_value'] = 0;

        // ── حساب الإجماليات ───────────────────────────────────────────────────
        $grossTotal = $normalized['subtotal'];
        $taxTotal = $normalized['tax'];

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

        $tenantLock = $this->db->prepare('SELECT id FROM tenants WHERE id = ? FOR UPDATE');
        $tenantLock->execute([$tenantId]);
        if ($tenantLock->fetchColumn() === false) {
            throw new \RuntimeException('Tenant row is unavailable while generating a return number.');
        }

        $stmt = $this->db->prepare("
            SELECT CAST(SUBSTRING_INDEX(return_number, '-', -1) AS UNSIGNED) AS sequence_number
            FROM returns
            WHERE return_type = ? AND return_number LIKE ? AND tenant_id = ?
            FOR UPDATE
        ");
        $stmt->execute([$data['return_type'], "{$prefix}-{$datePart}-%", $tenantId]);
        $maxSequence = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $sequenceNumber) {
            $maxSequence = max($maxSequence, (int) $sequenceNumber);
        }
        $returnNumber = sprintf('%s-%s-%03d', $prefix, $datePart, $maxSequence + 1);

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
                       customer_id,
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
                if (
                    !empty($sale['customer_id'])
                    && !empty($data['party_id'])
                    && (int) $data['party_id'] !== (int) $sale['customer_id']
                ) {
                    throw new \DomainException('العميل المحدد لا يطابق العميل المسجل على الفاتورة.');
                }
                $data['party_id'] = $sale['customer_id'] ?: null;

                $saleGrandTotal = (float) $sale['net_total_amount'] + (float) ($sale['tax_amount'] ?? 0);
                $salePaidAmount = $this->getSaleActualPaidAmount((int) $sale['id'], $tenantId);
                $saleCreditsStmt = $this->db->prepare(
                    'SELECT COALESCE(SUM(allocated_amount), 0)
                     FROM return_credit_allocations WHERE sale_id = ? AND tenant_id = ?'
                );
                $saleCreditsStmt->execute([(int) $sale['id'], $tenantId]);
                $saleReturnCredits = (float) $saleCreditsStmt->fetchColumn();
                $saleOutstanding = max(0, $saleGrandTotal - $salePaidAmount - $saleReturnCredits);

                if ($refundMode === 'refund_excess') {
                    if (empty($data['party_id'])) {
                        throw new \DomainException('لا يمكن صرف فائض المرتجع دون تحديد العميل.');
                    }

                    $paymentMethodStmt = $this->db->prepare(
                        'SELECT kind FROM payment_methods WHERE id = ? AND tenant_id = ? LIMIT 1'
                    );
                    $paymentMethodStmt->execute([(int) $data['payment_method_id'], $tenantId]);
                    $paymentMethodKind = strtolower((string) ($paymentMethodStmt->fetchColumn() ?: ''));
                    if ($paymentMethodKind === '' || $paymentMethodKind === 'credit') {
                        throw new \DomainException('اختر طريقة دفع غير آجلة لصرف فائض المرتجع.');
                    }

                    $customerBalance = $this->getCustomerAccountBalance((int) $data['party_id'], $tenantId);
                    $customerSalesOutstanding = $this->getCustomerTotalOutstanding((int) $data['party_id'], $tenantId);
                    $data['paid_amount'] = min(
                        $grandTotal,
                        max(0, $grandTotal - max(0, $customerBalance))
                    );
                    $deductFromCustomerBalance = min(
                        $customerSalesOutstanding,
                        max(0, $grandTotal - $data['paid_amount'])
                    );
                }

                if ($saleOutstanding > 0) {
                    if ($refundMode === 'auto' || $refundMode === 'deduct_and_return') {
                        $amountToDeductFromDebt    = min($saleOutstanding, $grandTotal);
                        $excessToReturn            = max(0, $grandTotal - $amountToDeductFromDebt);
                        $data['paid_amount']        = $excessToReturn;
                        $deductFromCustomerBalance  = $amountToDeductFromDebt;
                    } elseif ($refundMode === 'cash') {
                        throw new \Exception('لا يمكن رد نقدي لفاتورة آجلة أو غير مسددة بالكامل. سيتم خصم قيمة المرتجع من ذمة العميل.');
                    } elseif ($refundMode !== 'refund_excess') {
                        $customerSalesOutstanding = !empty($data['party_id'])
                            ? $this->getCustomerTotalOutstanding((int) $data['party_id'], $tenantId)
                            : 0.0;
                        $data['paid_amount']       = 0;
                        $deductFromCustomerBalance = min($customerSalesOutstanding, $grandTotal);
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
                if ($saleOutstanding === 0 && $refundMode !== 'refund_excess') {
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
                SELECT id, invoice_number, supplier_id, branch_id,
                       total_amount, tax_rate
                FROM purchases WHERE id = ? AND tenant_id = ?
            ");
            $stmt->execute([$data['invoice_id'], $tenantId]);
            $purchase = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($purchase) {
                $purchaseId          = $purchase['id'];
                $invoiceNumber       = $purchase['invoice_number'];
                $branchId            = $purchase['branch_id'];

                $stmtPaid = $this->db->prepare("
                    SELECT COALESCE(SUM(
                        CASE WHEN type = 'payment_for_return' THEN -amount ELSE amount END
                    ), 0)
                    FROM payments
                    WHERE purchase_id = ? AND tenant_id = ?
                      AND status = 'completed' AND is_draft = 0
                ");
                $stmtPaid->execute([$purchaseId, $tenantId]);
                $netPaid = (float) $stmtPaid->fetchColumn();

                $stmtReturns = $this->db->prepare("
                    SELECT COALESCE(SUM(grand_total), 0)
                    FROM returns
                    WHERE purchase_id = ? AND tenant_id = ? AND return_type = 'purchase'
                ");
                $stmtReturns->execute([$purchaseId, $tenantId]);
                $previousReturns = (float) $stmtReturns->fetchColumn();

                $purchaseOutstanding = PurchaseReturnSettlementPolicy::outstandingAmount(
                    (float) $purchase['total_amount'],
                    $netPaid,
                    $previousReturns
                );

                if ($taxTotal <= 0) {
                    $effectiveTaxRate = isset($data['tax_rate']) ? (float) $data['tax_rate']
                        : (isset($purchase['tax_rate']) ? (float) $purchase['tax_rate'] : 0);
                    if ($effectiveTaxRate > 0) {
                        $taxTotal = round($totalAfterDiscount * ($effectiveTaxRate / 100), 2);
                    }
                }
                $grandTotal = $totalAfterDiscount + $taxTotal;

                $refundModePurchase = (string) ($data['refund_mode'] ?? 'auto');
                $supplierIdForReturn = (int) ($data['party_id'] ?? $purchase['supplier_id'] ?? 0);
                $supplierPayable = 0.0;
                if ($purchaseOutstanding <= 0 && in_array($refundModePurchase, ['auto', 'deduct_and_return'], true)) {
                    $supplierPayable = $this->getSupplierPayableBalance($supplierIdForReturn, $tenantId);
                }

                $data['paid_amount'] = PurchaseReturnSettlementPolicy::cashRefundAmount(
                    $refundModePurchase,
                    $grandTotal,
                    $purchaseOutstanding,
                    $supplierPayable
                );
            }
        }

        // ── INSERT returns ────────────────────────────────────────────────────
        $methodIsCash = $this->isCashMethodInternal((int) $data['payment_method_id'], $tenantId);
        $isCash       = $methodIsCash && $data['paid_amount'] > 0;

        // ── LIQUIDITY VALIDATION (Phase 5 — Sale Return Cash Refund) ──────────
        // A sale return with paid_amount > 0 using a cash or bank method is an OUTFLOW:
        //   Dr. Sales Returns  /  Cr. Cash|Bank  (money leaves the register)
        // This path is NOT hit for credit-note-only returns (paid_amount = 0)
        // or purchase returns that receive cash FROM the supplier (those are inflows).
        if (
            $data['return_type'] === 'sale' &&
            (float) $data['paid_amount'] > 0
        ) {
            try {
                $liquidityPolicy = new \App\Services\LiquidityPolicyService($this->db, $this->logger);
                $validation = $liquidityPolicy->validateLiquidityBalance(
                    tenantId: $tenantId,
                    paymentMethodId: (int) $data['payment_method_id'],
                    debitAmount: (float) $data['paid_amount'],
                    cashierSessionId: $sessionId,
                    branchId: $branchId !== null ? (int) $branchId : null
                );

                if (!$validation['allowed']) {
                    throw new \App\Exceptions\InsufficientLiquidityException(
                        $validation['reason'],
                        402,
                        $validation
                    );
                }
            } catch (\App\Exceptions\InsufficientLiquidityException $e) {
                throw $e; // re-throw — caller's transaction will rollback
            } catch (\Throwable $e) {
                $this->logger->error('Liquidity validation failed for sale return refund', [
                    'return_type'       => $data['return_type'],
                    'payment_method_id' => $data['payment_method_id'],
                    'paid_amount'       => $data['paid_amount'],
                    'error'             => $e->getMessage(),
                ]);
                throw new \RuntimeException(
                    'تعذر التحقق من رصيد حساب الدفع؛ لم يتم اعتماد رد النقدية.',
                    0,
                    $e
                );
            }
        }

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

        if ($data['return_type'] === 'purchase' && $purchaseId) {
            (new PurchaseService($this->db, $tenantId, $userId))->recalculateBalance((int) $purchaseId);
        }

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

        // allocateCustomerBalance() recalculates every invoice affected by the credit allocation.

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
            SELECT COALESCE(SUM(GREATEST(
                0,
                (s.net_total_amount + IFNULL(s.tax_amount, 0))
                - COALESCE((
                    SELECT SUM(CASE WHEN p.type = 'return_payment' THEN -p.amount ELSE p.amount END)
                    FROM payments p
                    WHERE p.sale_id = s.id AND p.tenant_id = s.tenant_id
                      AND p.is_draft = 0 AND p.status = 'completed'
                ), 0)
                - COALESCE((
                    SELECT SUM(cpa.allocated_amount)
                    FROM customer_payment_allocations cpa
                    INNER JOIN payments ap ON ap.id = cpa.payment_id AND ap.tenant_id = cpa.tenant_id
                    WHERE cpa.sale_id = s.id AND cpa.tenant_id = s.tenant_id
                      AND ap.is_draft = 0 AND ap.status = 'completed'
                ), 0)
                - COALESCE((
                    SELECT SUM(rca.allocated_amount)
                    FROM return_credit_allocations rca
                    WHERE rca.sale_id = s.id AND rca.tenant_id = s.tenant_id
                ), 0)
            )), 0)
            FROM sales s
            WHERE s.customer_id = ? AND s.tenant_id = ?
              AND LOWER(COALESCE(s.status, '')) NOT IN ('cancelled', 'canceled')
        ");
        $stmt->execute([$customerId, $tenantId]);
        return max(0.0, (float) $stmt->fetchColumn());
    }

    private function getCustomerAccountBalance(int $customerId, int $tenantId): float
    {
        $stmt = $this->db->prepare("
            SELECT c.account_id, COALESCE(SUM(jel.debit_amount - jel.credit_amount), 0) AS balance
            FROM customers c
            LEFT JOIN journal_entry_lines jel
                ON jel.account_id = c.account_id
               AND jel.tenant_id = c.tenant_id
            WHERE c.id = ? AND c.tenant_id = ?
            GROUP BY c.id, c.account_id
        ");
        $stmt->execute([$customerId, $tenantId]);
        $customerAccount = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$customerAccount || empty($customerAccount['account_id'])) {
            throw new \DomainException('لا يوجد حساب مالي مرتبط بالعميل لحساب صافي التسوية.');
        }
        return (float) $customerAccount['balance'];
    }
}
