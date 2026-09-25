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
 * PurchaseService
 *
 * Centralises purchase invoice business logic extracted from PurchasesHandler.
 * Handlers should delegate to this service instead of containing SQL/accounting logic.
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

    // -------------------------------------------------------------------------
    // Status helpers
    // -------------------------------------------------------------------------

    /**
     * ── AUDIT FINDING (new, discovered while tracing supplier statements) ────
     * تحديد حالة فاتورة الشراء. `$returnAmount` كانت موجودة كباراميتر لكنها لم
     * تُستخدَم إلا في التحقق من "إرجاع كامل" (returned) — لم تكن تُطرَح من
     * المبلغ المتبقي في حالة الإرجاع الجزئي، فيبقى `outstanding` محسوبًا وكأن
     * المرتجع لم يحدث إطلاقًا لأي حالة غير الإرجاع الكامل 100%. تم تصحيحه
     * الآن ليطرح قيمة المرتجع من المتبقي بشكل صحيح (يماثل outstanding =
     * grandTotal - paid - returnCredits على جانب المبيعات)، وأُضيفت حالة
     * 'closed_by_return' لتمييز فاتورة أُغلقت عبر مرتجع دون دفع نقدي، بنفس
     * منطق الجانب الآخر (بتفويض صريح: "نحن في مرحلة تطوير... قم بتنفيذه").
     */
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
                return 'settled_mixed'; // نفس مفردات جانب المبيعات: تسديد جزئي نقدًا + تغطية الباقي بمرتجع
            }
            return $returnAmount > 0.01 ? 'closed_by_return' : 'paid';
        }
        if ($paidAmount <= 0) {
            return 'due';
        }
        return 'partial';
    }

    /**
     * إجمالي قيمة مرتجعات المشتريات المرتبطة بهذه الفاتورة (return_type='purchase').
     */
    public function getReturnedAmount(int $purchaseId): float
    {
        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(grand_total), 0) FROM returns
             WHERE purchase_id = ? AND tenant_id = ? AND return_type = 'purchase'"
        );
        $stmt->execute([$purchaseId, $this->tenantId]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    // -------------------------------------------------------------------------
    // Balance recalculation
    // -------------------------------------------------------------------------

    /**
     * Recalculate and persist paid_amount + status for a purchase.
     *
     * ── AUDIT FINDING (new) ──────────────────────────────────────────────────
     * كانت تُستدعى بدون تمرير قيمة المرتجعات إطلاقًا (returnAmount=0.0
     * الافتراضية دائمًا)، حتى في الحالات التي فيها مرتجعات فعلية — والأهطر:
     * هذه الدالة نفسها لم تكن تُستدعى إطلاقًا من ReturnService::createReturn
     * لمرتجعات المشتريات (تحقّقتُ: بحث شامل عن `recalculateBalance` و
     * `UPDATE purchases` في ReturnService.php أعطى صفر نتائج). النتيجة: كان
     * القيد المحاسبي (GL) يُخفِّض ذمة المورد (حساب 2101) بشكل صحيح عند كل
     * مرتجع مشتريات، بينما purchases.paid_amount/status يبقيان كأن المرتجع لم
     * يحدث للأبد — بلا أي آلية تصحيح لاحقة (بعكس جانب المبيعات الذي كان لديه
     * على الأقل dynamic_status الصحيح للعرض). كشف حساب المورد
     * (AccountStatementHandler::getSupplierReferences) كان بالتالي يعرض دائمًا
     * رصيدًا مستحقًا للمورد أكبر من الصحيح فعليًا بعد أي مرتجع — خطر مباشر
     * بدفع مبلغ زائد لمورد.
     */
    public function recalculateBalance(int $purchaseId): void
    {
        $totalPaid    = $this->paymentRepo->getTotalPaidForPurchase($purchaseId, $this->tenantId);
        $totalAmount  = $this->purchaseRepo->getTotalAmount($purchaseId, $this->tenantId);
        $returnAmount = $this->getReturnedAmount($purchaseId);
        $status       = $this->determinePurchaseStatus($totalAmount, $totalPaid, $returnAmount);
        $this->purchaseRepo->updateBalance($purchaseId, $this->tenantId, $totalPaid, $status);
    }

    // -------------------------------------------------------------------------
    // Account resolution helpers
    // -------------------------------------------------------------------------

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

    public function getInventoryAccountId(?int $branchId = null): int
    {
        $id = $this->accounting->resolveInventoryAccountForBranch($this->tenantId, $branchId) ?? 0;
        if ($id > 0) {
            return $id;
        }

        throw new Exception('لم يتم تحديد حساب المخزون المناسب.');
    }

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

    public function resolveLiquidityAccountId(int $paymentMethodId): int
    {
        $accountId = $this->accounting->resolveLiquidityAccount($paymentMethodId, $this->tenantId);

        if ($accountId === null) {
            throw new Exception('طريقة الدفع آجلة (credit) — لا يوجد حساب سيولة لفاتورة الشراء.');
        }

        return $accountId;
    }

    // -------------------------------------------------------------------------
    // Invoice number generation
    // -------------------------------------------------------------------------

    public function generateInvoiceNumber(?string $invoiceDate = null): string
    {
        $invoiceDateTime = DateHelper::normalize($invoiceDate);
        $day      = date('Y-m-d', strtotime($invoiceDateTime));
        $dayShort = date('ymd', strtotime($invoiceDateTime));

        // Retry logic for handling duplicate entry errors
        $maxAttempts = 3;
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            try {
                $attempt++;

                // Get sequential count for today with table lock to prevent duplicates
                $stmt = $this->db->prepare(
                    "SELECT COUNT(*) FROM purchases
                     WHERE tenant_id = ? AND DATE(invoice_date) = ?
                     FOR UPDATE"
                );
                $stmt->execute([
                    $this->tenantId,
                    $day
                ]);

                $count = (int) $stmt->fetchColumn() + 1;
                $invoiceNumber = sprintf('PUR-%s-%03d', $dayShort, $count);

                // If we got here without exception, return the number
                return $invoiceNumber;

            } catch (PDOException $e) {
                $msg = $e->getMessage();
                // If it's a duplicate entry error and we haven't exceeded max attempts, retry
                if (($e->getCode() === '23000' || strpos($msg, 'Duplicate entry') !== false) && $attempt < $maxAttempts) {
                    // Wait a brief moment before retrying to reduce contention
                    usleep(100000); // 100ms
                    continue;
                }
                // If it's not a duplicate or we've exceeded attempts, throw the exception
                throw $e;
            }
        }

        // Should not reach here, but if it does, throw an exception
        throw new PDOException('Failed to generate unique invoice number after ' . $maxAttempts . ' attempts');
    }

    // -------------------------------------------------------------------------
    // Journal entry creation — delegates to AccountingService (Single Source of Truth)
    // -------------------------------------------------------------------------

    public function createJournalEntry(array $purchaseData, int $supplierAccountId): int
    {
        $purchaseId      = (int) ($purchaseData['id'] ?? 0);
        $invoiceNumber   = (string) ($purchaseData['invoice_number'] ?? 'غير معروف');
        $invoiceDate     = (string) ($purchaseData['invoice_date'] ?? date('Y-m-d H:i:s'));
        $branchId        = !empty($purchaseData['branch_id']) ? (int) $purchaseData['branch_id'] : null;
        $userId          = isset($purchaseData['user_id']) ? (int) $purchaseData['user_id'] : $this->userId;
        $costCenterId    = !empty($purchaseData['cost_center_id'])
            ? (int) $purchaseData['cost_center_id']
            // نُمرِّر فرع فاتورة الشراء نفسها (وليس فرع المستخدم فقط) — كان مفقوداً سابقاً
            // رغم توفره في $branchId أعلاه، ما كان يُسبب نسب مركز تكلفة خاطئ حين يسجّل
            // موظف من فرع (ب) فاتورة شراء واردة لفرع (أ).
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

        // ربط journal_entry_id بسجل الفاتورة
        $this->purchaseRepo->setJournalEntryId($purchaseId, $this->tenantId, $journalEntryId);

        return $journalEntryId;
    }

    // -------------------------------------------------------------------------
    // Currency
    // -------------------------------------------------------------------------

    public function getCompanyCurrency(): string
    {
        return $this->settingsRepo->get($this->tenantId, 'company.currency', 'EGP') ?: 'EGP';
    }

    // -------------------------------------------------------------------------
    // Stock management
    // -------------------------------------------------------------------------

    /**
     * Insert purchase items and optionally update stock.
     * Extracted from PurchasesHandler::insertPurchaseItems().
     */
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
                $item['batch_number'] ?? null,
                $item['expiry_date'] ?? null,
                $item['serial'] ?? null,
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
                    $item['batch_number'] ?? null,
                    $item['expiry_date'] ?? null,
                    $item['serial'] ?? null
                );
            }
        }
    }

    /**
     * Update stock for a purchase item.
     * Extracted from PurchasesHandler::updateStock().
     */
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

    /**
     * Record a payment for a purchase invoice.
     * Extracted from PurchasesHandler::recordPurchasePayment().
     * $request replaced with explicit session parameters to decouple from HTTP layer.
     */
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
        bool $purchaseJeAlreadyCreated = false
    ): array {
        if ($amount <= 0) {
            throw new \Exception('قيمة الدفعة يجب أن تكون أكبر من صفر.');
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

        $this->paymentRepo->insertApplication(
            $this->tenantId,
            $paymentId,
            'purchase',
            $purchaseId,
            $amount,
            $this->userId
        );

        $journalEntryId = null;

        if ($purchaseJeAlreadyCreated) {
            $stmtPJe = $this->db->prepare(
                "SELECT journal_entry_id FROM purchases WHERE id = ? AND tenant_id = ? LIMIT 1"
            );
            $stmtPJe->execute([$purchaseId, $this->tenantId]);
            $inheritedJeId = (int) $stmtPJe->fetchColumn();
            if ($inheritedJeId > 0) {
                $this->paymentRepo->setJournalEntryId($paymentId, $this->tenantId, $inheritedJeId);
                $journalEntryId = $inheritedJeId;
            }
        } else {
            try {
                $liquidityAccountId = $this->resolveLiquidityAccountId($paymentMethodId);
                $desc               = 'دفعة مورد لفاتورة شراء #' . $purchaseId;

                $journalEntryId = $this->accounting->postPayment(
                    $this->tenantId,
                    $paymentId,
                    $amount,
                    'purchase',
                    null,
                    $purchaseId,
                    null,
                    $this->userId,
                    $costCenterId,
                    $supplierAccountId,
                    $liquidityAccountId,
                    $desc
                );

                if ($journalEntryId) {
                    $this->paymentRepo->setJournalEntryId($paymentId, $this->tenantId, $journalEntryId);
                }
            } catch (Throwable $e) {
                $this->logger->warning('Failed to post purchase payment to journal', [
                    'payment_id' => $paymentId, 'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'payment_id'       => $paymentId,
            'journal_entry_id' => $journalEntryId,
            'session_id'       => $sessionId,
        ];
    }

    /**
     * Safe audit logging — delegates to AuditHandler.
     */
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
        }
    }

    // -------------------------------------------------------------------------
    // updatePurchase — تحديث فاتورة شراء كاملة
    // -------------------------------------------------------------------------

    /**
     * تحديث فاتورة شراء: عكس المخزون القديم + حذف items + update + insert items + قيد جديد.
     *
     * @param int   $purchaseId
     * @param array $purchase   السجل الحالي من DB (يُمرَّر من الـ handler بعد التحقق)
     * @param array $data       بيانات الطلب (supplier_id, branch_id, items, ...)
     * @param array $totals     نتيجة calculatePurchaseTotals()
     * @param string $invoiceDate
     * @param int|null $costCenterId
     * @return array ['id' => int, 'invoice_number' => string]
     */
    public function updatePurchase(
        int    $purchaseId,
        array  $purchase,
        array  $data,
        array  $totals,
        string $invoiceDate,
        ?int   $costCenterId = null
    ): array {
        // 1. حذف القيد المحاسبي القديم
        if (!empty($purchase['journal_entry_id'])) {
            $this->accounting->deleteJournalEntry($this->tenantId, (int) $purchase['journal_entry_id']);
        }

        // 2. عكس المخزون القديم
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

        // 3. حذف items القديمة
        $this->db->prepare(
            "DELETE FROM purchase_items WHERE purchase_id = ? AND tenant_id = ?"
        )->execute([$purchaseId, $this->tenantId]);

        // 4. تحديث رأس الفاتورة
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

        // 5. إدراج items الجديدة + تحديث المخزون
        $this->insertPurchaseItems(
            $purchaseId,
            $totals['items'],
            $totals['gross_total'],
            $totals['discount_amount'],
            (int) $data['branch_id'],
            $data['notes'] ?? null,
            true
        );

        // 6. إنشاء قيد محاسبي جديد
        $supplierAccountId = $this->getSupplierAccountId((int) $data['supplier_id']);
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

        return [
            'id'             => $purchaseId,
            'invoice_number' => (string) ($purchase['invoice_number'] ?? ''),
        ];
    }

    // -------------------------------------------------------------------------
    // deletePurchase — حذف فاتورة شراء
    // -------------------------------------------------------------------------

    /**
     * حذف فاتورة شراء: التحقق من عدم وجود مدفوعات + عكس المخزون + حذف items + حذف الفاتورة + عكس القيد.
     *
     * @throws \Exception إذا كانت هناك مدفوعات مرتبطة
     */
    public function deletePurchase(int $purchaseId): void
    {
        try {
            // التحقق من عدم وجود مدفوعات
            $stmtPay = $this->db->prepare(
                "SELECT COUNT(*) FROM payments WHERE purchase_id = ? AND tenant_id = ?"
            );
            $stmtPay->execute([$purchaseId, $this->tenantId]);
            if ((int) $stmtPay->fetchColumn() > 0) {
                throw new \Exception('لا يمكن حذف الفاتورة: يوجد عليها مدفوعات مرتبطة بها');
            }

            // جلب بيانات الفاتورة
            $stmt = $this->db->prepare(
                "SELECT * FROM purchases WHERE id = ? AND tenant_id = ? LIMIT 1"
            );
            $stmt->execute([$purchaseId, $this->tenantId]);
            $purchase = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$purchase) {
                throw new \App\Exceptions\NotFoundException('Purchase not found');
            }

            // ابدأ transaction
            $this->db->beginTransaction();

            // حذف القيد المحاسبي
            if (!empty($purchase['journal_entry_id'])) {
                $this->accounting->deleteJournalEntry($this->tenantId, (int) $purchase['journal_entry_id']);
            }

            // عكس المخزون
            $stmtItems = $this->db->prepare(
                "SELECT * FROM purchase_items WHERE purchase_id = ? AND tenant_id = ?"
            );
            $stmtItems->execute([$purchaseId, $this->tenantId]);
            $items = $stmtItems->fetchAll(\PDO::FETCH_ASSOC);

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

            // حذف items
            $this->db->prepare(
                "DELETE FROM purchase_items WHERE purchase_id = ? AND tenant_id = ?"
            )->execute([$purchaseId, $this->tenantId]);

            // حذف الفاتورة
            $this->db->prepare(
                "DELETE FROM purchases WHERE id = ? AND tenant_id = ?"
            )->execute([$purchaseId, $this->tenantId]);

            // أكمل transaction
            $this->db->commit();

        } catch (\Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // addPaymentToInvoice — إضافة دفعة لفاتورة شراء قائمة
    // -------------------------------------------------------------------------

    /**
     * التحقق من المبلغ المتبقي وإضافة دفعة لفاتورة شراء.
     *
     * @param int    $purchaseId
     * @param array  $purchase          بيانات الفاتورة + supplier_account_id
     * @param float  $amount
     * @param string $paymentDate
     * @param int    $paymentMethodId
     * @param string|null $referenceNumber
     * @param int|null $branchId
     * @param int|null $costCenterId
     * @param int|null $sessionId       يُحسب في الـ handler (HTTP concern)
     * @return array ['payment_id', 'journal_entry_id', 'session_id', 'remaining', 'new_paid', 'status']
     * @throws \Exception إذا تجاوزت الدفعة المبلغ المتبقي
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
        // حساب المبلغ المتبقي
        $stmtPaid = $this->db->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM payments
             WHERE purchase_id = ? AND tenant_id = ? AND status = 'completed'"
        );
        $stmtPaid->execute([$purchaseId, $this->tenantId]);
        $actualPaidSoFar = round((float) $stmtPaid->fetchColumn(), 2);

        $stmtRet = $this->db->prepare(
            "SELECT COALESCE(SUM(grand_total), 0) FROM returns
             WHERE purchase_id = ? AND tenant_id = ? AND return_type = 'purchase'"
        );
        $stmtRet->execute([$purchaseId, $this->tenantId]);
        $returnAmt = round((float) $stmtRet->fetchColumn(), 2);

        $netOwed   = max(0.0, (float) $purchase['total_amount'] - $returnAmt);
        $remaining = round(max(0.0, $netOwed - $actualPaidSoFar), 2);

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
            false  // قيد مستقل لكل دفعة
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

    // -------------------------------------------------------------------------
    // recordSupplierDebtPayment — سداد دين مورد (supplier_payments)
    // -------------------------------------------------------------------------

    /**
     * تسجيل سداد دين مورد في جدول supplier_payments + قيد محاسبي.
     *
     * @return array ['payment_id', 'journal_entry_id']
     */
    public function recordSupplierDebtPayment(
        int    $supplierId,
        float  $amount,
        string $paymentDate,
        string $notes = ''
    ): array {
        $supplierAccountId = $this->getSupplierAccountId($supplierId);

        $this->db->prepare(
            "INSERT INTO supplier_payments
                 (supplier_id, tenant_id, amount, payment_date, notes, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())"
        )->execute([
            $supplierId, $this->tenantId, $amount, $paymentDate, $notes, $this->userId,
        ]);
        $paymentId = (int) $this->db->lastInsertId();

        $jeId = null;
        try {
            $jeId = $this->accounting->postPayment(
                $this->tenantId,
                $paymentId,
                $amount,
                'supplier_payment',
                null,
                null,
                null,
                $this->userId,
                null,
                $supplierAccountId,
                null,
                "سداد دين مورد #$supplierId"
            );
        } catch (Throwable $e) {
            $this->logger->warning('recordSupplierDebtPayment: failed to post journal', [
                'payment_id' => $paymentId,
                'error'      => $e->getMessage(),
            ]);
        }

        return [
            'payment_id'       => $paymentId,
            'journal_entry_id' => $jeId,
        ];
    }
}
