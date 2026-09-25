<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use Exception;
use App\Services\AccountingService;
use App\Services\CostingService;
use App\Services\MonologHandler;
use App\Services\Transaction\TransactionManager;
use App\Repositories\SettingsRepository;

/**
 * SaleApprovalService
 *
 * Handles the full approval workflow for sales invoices.
 * All logic extracted from SalesService — no delegation wrappers.
 *
 *   - approveSale()          ✅ real logic (stock + journal + payments)
 *   - rejectSale()           ✅ real logic
 *   - listPendingApprovals() ✅ real logic
 *   - updateSaleStatus()     ✅ real logic
 */
class SaleApprovalService
{
    private PDO $pdo;
    private ?int $userId;
    private ?int $tenantId;
    private AccountingService $accounting;
    private SettingsRepository $settingsRepo;
    private TransactionManager $txManager;
    private $logger;

    public function __construct(PDO $pdo, ?int $userId = null, ?int $tenantId = null)
    {
        $this->pdo          = $pdo;
        $this->userId       = $userId;
        $this->tenantId     = $tenantId;
        $this->accounting   = new AccountingService($pdo);
        $this->settingsRepo = new SettingsRepository($pdo);
        $this->txManager    = new TransactionManager($pdo, 'sales');
        $this->logger       = MonologHandler::getInstance('sales');
    }

    // =========================================================================
    // Private Helpers (extracted from SalesService)
    // =========================================================================

    private function logAudit(string $action, string $entity, int $entityId, array $details, int $tenantId): void
    {
        try {
            (new \App\Handlers\AuditHandler($this->pdo))->logAction(
                $action,
                $entity,
                $entityId,
                $details,
                $tenantId,
                $this->userId
            );
        } catch (\Throwable $e) {
            // لا نوقف العملية بسبب فشل التدقيق
        }
    }

    private function findOpenCashierSession(int $tenantId, int $branchId, ?int $cashierId = null): ?int
    {
        $sql    = "SELECT id FROM cashier_sessions WHERE tenant_id = ? AND branch_id = ? AND status = 'open'";
        $params = [$tenantId, $branchId];
        if ($cashierId) {
            $sql    .= ' AND cashier_id = ?';
            $params[] = $cashierId;
        }
        $sql .= ' ORDER BY id DESC LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn() ?: null;
    }

    private function findOpenGlobalCashierSession(int $tenantId, ?int $cashierId = null): ?int
    {
        $sql    = "SELECT id FROM cashier_sessions WHERE tenant_id = ? AND branch_id IS NULL AND status = 'open'";
        $params = [$tenantId];
        if ($cashierId) {
            $sql    .= ' AND cashier_id = ?';
            $params[] = $cashierId;
        }
        $sql .= ' ORDER BY id DESC LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn() ?: null;
    }

    private function getCurrentUserRoleId(): ?int
    {
        $stmt = $this->pdo->prepare("SELECT role_id FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$this->userId]);
        $rid = $stmt->fetchColumn();
        return $rid !== false ? (int) $rid : null;
    }

    private function isRoleEnforced(int $tenantId, ?int $roleId): bool
    {
        if (!$roleId) {
            return false;
        }
        $raw  = (string) $this->settingsRepo->get($tenantId, 'pos.sessions.enforce_for_roles', '');
        $trim = trim($raw);
        if ($trim === '') {
            return false;
        }
        $enforce = strpos($trim, '[') === 0
            ? (array) json_decode($trim, true)
            : array_filter(array_map('trim', explode(',', $trim)));
        return in_array((int) $roleId, array_map('intval', $enforce), true);
    }

    private function getCompanyCurrency(int $tenantId): string
    {
        return $this->settingsRepo->get($tenantId, 'company.currency', 'EGP') ?: 'EGP';
    }

    private function resolveAccountId(int $tenantId, string $settingKey, string $fallbackCode): ?int
    {
        return $this->accounting->resolveAccountId($tenantId, $settingKey, $fallbackCode);
    }


    // =========================================================================
    // applyStockForExistingSaleItems — extracted from SalesService
    // تطبيق المخزون لأصناف فاتورة موجودة مسبقاً (تُستخدم عند الموافقة)
    // =========================================================================

    private function applyStockForExistingSaleItems(int $saleId, int $tenantId, int $branchId): void
    {
        $stmt = $this->pdo->prepare("
            SELECT si.product_id, si.quantity, si.unit_id, si.conversion_factor,
                   si.batch_number, si.expiry_date, si.purchase_price, si.serial
            FROM sales_items si
            INNER JOIN sales s ON si.sale_id = s.id AND s.tenant_id = si.tenant_id
            WHERE si.sale_id = ? AND si.tenant_id = ?
        ");
        $stmt->execute([$saleId, $tenantId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // ✅ نفس ترتيب القفل المستخدم في الإنشاء لمنع deadlock
        usort($items, fn ($a, $b) => (int)$a['product_id'] <=> (int)$b['product_id']);

        foreach ($items as $item) {
            $baseQuantity = (float) $item['quantity'] * (float) ($item['conversion_factor'] ?? 1);
            $unitCost     = (float) ($item['purchase_price'] ?? 0);
            $totalCost    = $baseQuantity * $unitCost;

            // SELECT FOR UPDATE: منع التزامن في الموافقة
            $lockStmt = $this->pdo->prepare("
                SELECT quantity
                FROM   branch_products
                WHERE  product_id = ? AND branch_id = ? AND tenant_id = ?
                FOR UPDATE
            ");
            $lockStmt->execute([$item['product_id'], $branchId, $tenantId]);
            $availableQty = (float) ($lockStmt->fetchColumn() ?? 0);

            if ($availableQty < $baseQuantity) {
                throw new \App\Exceptions\InsufficientStockException(
                    "الكمية المتوفرة للمنتج {$item['product_id']} ({$availableQty}) " .
                    "أقل من الكمية المطلوبة ({$baseQuantity}) عند الاعتماد.",
                    (int)$item['product_id'],
                    $availableQty,
                    $baseQuantity
                );
            }

            $this->pdo->prepare("
                UPDATE branch_products
                SET quantity      = quantity - ?,
                    quantity_cost = GREATEST(0, quantity_cost - ?)
                WHERE product_id = ? AND branch_id = ? AND tenant_id = ?
            ")->execute([$baseQuantity, $totalCost, $item['product_id'], $branchId, $tenantId]);
            BranchInventoryReconciliationService::markUnreconciled(
                $this->pdo,
                $tenantId,
                $branchId,
                (int) $item['product_id']
            );

            $this->pdo->prepare("
                INSERT INTO inventory_transactions (
                    tenant_id, product_id, unit_id, branch_from, branch_to,
                    quantity, unit_cost, total_cost,
                    movement_type, reference_type, reference_id,
                    user_id, movement_date,
                    batch_number, expiry_date, serial
                ) VALUES (?, ?, ?, ?, NULL, ?, ?, ?, 'out', 'sale', ?, ?, NOW(), ?, ?, ?)
            ")->execute([
                $tenantId,
                $item['product_id'],
                $item['unit_id'] ?? 1,
                $branchId,
                $baseQuantity,
                $unitCost,
                $totalCost,
                $saleId,
                $this->userId,
                $item['batch_number'] ?? null,
                $item['expiry_date']  ?? null,
                $item['serial']       ?? null,
            ]);

            // تحديث حالة الأرقام السلسلية (in_stock → sold)
            if (!empty($item['serial'])) {
                $chk = $this->pdo->prepare(
                    "SELECT has_serial_number FROM products WHERE id = ? AND tenant_id = ?"
                );
                $chk->execute([$item['product_id'], $tenantId]);
                if ($chk->fetchColumn()) {
                    $this->pdo->prepare("
                        UPDATE product_serials
                        SET status = 'sold', transaction_id = LAST_INSERT_ID()
                        WHERE product_id = ? AND branch_id = ? AND serial_number = ?
                          AND tenant_id = ? AND status = 'in_stock'
                    ")->execute([$item['product_id'], $branchId, $item['serial'], $tenantId]);
                }
            }
        }
    }

    // =========================================================================
    // approveSale — real logic extracted from SalesService
    // اعتماد الفاتورة: مخزون + قيد محاسبي + تحويل الدفعات المسودة
    // =========================================================================

    public function approveSale(int $tenantId, int $saleId, ?string $note = null, array $paymentOverride = []): array
    {
        return $this->txManager->execute(function () use ($tenantId, $saleId, $note, $paymentOverride) {

            // 1. جلب الفاتورة مع قفل للتحديث
            $stmt = $this->pdo->prepare("
                SELECT id, status, branch_id, customer_id,
                       net_total_amount, tax_amount, paid_amount,
                       cost_center_id, payment_method_id
                FROM sales
                WHERE id = ? AND tenant_id = ?
                FOR UPDATE
            ");
            $stmt->execute([$saleId, $tenantId]);
            $sale = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$sale) {
                throw new Exception('الفاتورة غير موجودة');
            }
            if ($sale['status'] !== 'pending_approval') {
                throw new Exception('لا يمكن اعتماد فاتورة ليست قيد الموافقة');
            }

            // 2. التحقق: فواتير الآجل تحتاج عميلاً
            if (!empty($sale['payment_method_id'])) {
                $pmStmt = $this->pdo->prepare(
                    "SELECT kind FROM payment_methods WHERE id = ? AND tenant_id = ?"
                );
                $pmStmt->execute([$sale['payment_method_id'], $tenantId]);
                $pmKind = $pmStmt->fetchColumn();
                if ($pmKind === 'credit' && empty($sale['customer_id'])) {
                    throw new Exception(
                        'لا يمكن اعتماد فاتورة آجل بدون تحديد عميل — يجب تعيين عميل لفواتير الذمم قبل الاعتماد.'
                    );
                }
            }

            $branchId = (int) $sale['branch_id'];

            // 3. تطبيق تجاوز طريقة الدفع / المبلغ من الكاشير
            $overrideMethodId   = !empty($paymentOverride['payment_method_id'])
                ? (int) $paymentOverride['payment_method_id'] : null;
            $overridePaidAmount = isset($paymentOverride['paid_amount'])
                ? (float) $paymentOverride['paid_amount'] : null;

            if ($overrideMethodId) {
                $sale['payment_method_id'] = $overrideMethodId;
                $this->pdo->prepare(
                    "UPDATE sales SET payment_method_id = ? WHERE id = ? AND tenant_id = ?"
                )->execute([$overrideMethodId, $saleId, $tenantId]);
                $this->pdo->prepare(
                    "UPDATE payments SET payment_method_id = ? WHERE sale_id = ? AND tenant_id = ? AND is_draft = 1"
                )->execute([$overrideMethodId, $saleId, $tenantId]);
            }

            if ($overridePaidAmount !== null) {
                $this->pdo->prepare(
                    "DELETE FROM payments WHERE sale_id = ? AND tenant_id = ? AND is_draft = 1"
                )->execute([$saleId, $tenantId]);

                if ($overridePaidAmount > 0) {
                    $resolvedCc = $this->accounting->resolveCostCenterForService(
                        (int) $tenantId,
                        $this->userId,
                        $sale['cost_center_id'] ?? null,
                        $branchId
                    );
                    $methodId  = $overrideMethodId ?? (int) ($sale['payment_method_id'] ?? 1);
                    $currency  = $this->getCompanyCurrency((int) $tenantId);
                    $this->pdo->prepare("
                        INSERT INTO payments (
                            tenant_id, sale_id, amount, payment_date, payment_method_id,
                            customer_id, created_by, type, status, created_at,
                            is_draft, cost_center_id, currency
                        ) VALUES (?, ?, ?, NOW(), ?, ?, ?, 'sale', 'pending', NOW(), 1, ?, ?)
                    ")->execute([
                        $tenantId, $saleId, $overridePaidAmount, $methodId,
                        $sale['customer_id'] ?? null, $this->userId, $resolvedCc, $currency,
                    ]);
                }
            }

            // 4. تطبيق المخزون
            $this->applyStockForExistingSaleItems((int) $sale['id'], (int) $tenantId, $branchId);

            // 5. حساب مجموع الدفعات المسودة قبل إنشاء القيد
            $draftSumStmt = $this->pdo->prepare("
                SELECT COALESCE(SUM(amount), 0)
                FROM payments
                WHERE tenant_id = ? AND sale_id = ? AND is_draft = 1
            ");
            $draftSumStmt->execute([$tenantId, $saleId]);
            $draftPaidForJournal = (float) $draftSumStmt->fetchColumn();

            // 6. إنشاء القيد المحاسبي
            $salesAccountId = $this->resolveAccountId((int) $tenantId, 'sales_account', '4001');
            if (!$salesAccountId) {
                throw new Exception('حساب المبيعات غير معرّف لهذا التاجر');
            }

            $resolvedCc     = $this->accounting->resolveCostCenterForService(
                (int) $tenantId,
                $this->userId,
                $sale['cost_center_id'] ?? null,
                $branchId
            );
            $journalEntryId = $this->accounting->postSaleJournalEntry(
                $saleId,
                $tenantId,
                (int) $this->userId,
                date('Y-m-d H:i:s'),
                (float) $sale['net_total_amount'],
                (float) $sale['tax_amount'],
                $draftPaidForJournal,
                $sale['customer_id'] ? (int) $sale['customer_id'] : null,
                isset($sale['payment_method_id']) ? (int) $sale['payment_method_id'] : null,
                null,
                $salesAccountId,
                $resolvedCc
            );

            // ربط القيد بالفاتورة
            if ($journalEntryId) {
                $this->pdo->prepare(
                    "UPDATE sales SET journal_entry_id = ? WHERE id = ? AND tenant_id = ?"
                )->execute([$journalEntryId, $saleId, $tenantId]);
            }

            // 7. تحويل الدفعات المسودة إلى فعلية
            $draftStmt = $this->pdo->prepare("
                SELECT id, amount, payment_method_id, payment_date
                FROM payments
                WHERE tenant_id = ? AND sale_id = ? AND is_draft = 1
            ");
            $draftStmt->execute([$tenantId, $saleId]);
            $drafts   = $draftStmt->fetchAll(PDO::FETCH_ASSOC);
            $sumDraft = 0.0;

            foreach ($drafts as $dp) {
                $amt = (float) ($dp['amount'] ?? 0);
                if ($amt <= 0) {
                    continue;
                }
                $sumDraft += $amt;

                $methodId = (int) ($dp['payment_method_id'] ?? $sale['payment_method_id'] ?? 1);
                $isCash   = $this->accounting->isCashMethod($methodId, (int) $tenantId);
                $sessionId = null;

                if ($isCash) {
                    $sessionId = $this->findOpenCashierSession(
                        (int) $tenantId,
                        (int) $branchId,
                        (int) $this->userId
                    );
                    if (!$sessionId) {
                        $roleId   = $this->getCurrentUserRoleId();
                        $enforced = $this->isRoleEnforced((int) $tenantId, $roleId);
                        if (!$enforced) {
                            $sessionId = $this->findOpenGlobalCashierSession(
                                (int) $tenantId,
                                (int) $this->userId
                            );
                        }
                    }
                    if (!$sessionId) {
                        $roleId   = $this->getCurrentUserRoleId();
                        $enforced = $this->isRoleEnforced((int) $tenantId, $roleId);
                        if ($enforced) {
                            throw new Exception(
                                'لا توجد جلسة كاشير مفتوحة لتحويل دفعات المسودة النقدية. افتح جلسة قبل الاعتماد.'
                            );
                        }
                        // أدوار غير مُلزَمة: جلسة admin ذرية للحفاظ على سجل التدقيق
                        $this->pdo->prepare("
                            INSERT INTO cashier_sessions (
                                tenant_id, branch_id, cashier_id, session_type,
                                start_time, end_time, status, created_by, created_at, updated_at
                            ) VALUES (?, ?, ?, 'admin', NOW(), NOW(), 'closed', ?, NOW(), NOW())
                        ")->execute([$tenantId, $branchId, $this->userId, $this->userId]);
                        $sessionId = (int) $this->pdo->lastInsertId();
                    }
                }

                $this->pdo->prepare("
                    UPDATE payments
                    SET is_draft = 0, status = 'completed', session_id = ?, journal_entry_id = ?
                    WHERE id = ? AND tenant_id = ?
                ")->execute([$sessionId, $journalEntryId, $dp['id'], $tenantId]);

                if ($isCash) {
                    $resolvedCcForCt = $this->accounting->resolveCostCenterForService(
                        (int) $tenantId,
                        $this->userId,
                        $sale['cost_center_id'] ?? null,
                        $branchId
                    );
                    $this->pdo->prepare("
                        INSERT INTO cash_transactions (
                            customer_id, amount, type, reference_type, reference_id,
                            payment_method_id, created_by, created_at, tenant_id, status,
                            session_id, cost_center_id, journal_entry_id
                        ) VALUES (?, ?, 'income', 'sale', ?, ?, ?, NOW(), ?, 'completed', ?, ?, ?)
                    ")->execute([
                        $sale['customer_id'] ?? null,
                        $amt,
                        $saleId,
                        $methodId,
                        $this->userId,
                        $tenantId,
                        $sessionId,
                        $resolvedCcForCt,
                        $journalEntryId,
                    ]);
                }
            }

            // 8. تحديث paid_amount وحالة الفاتورة
            // ── AUDIT SELF-REVIEW FIX 15-A (continued) ───────────────────────────
            // كانت هذه الدالة تحسب SUM(payments) مباشرة دون طرح استرداد نقدي
            // (return_payment) ودون طرح return_credit_allocations — نفس الفجوة
            // المُصلَحة في SalePaymentService::addSalePayment. الآن تستخدم نفس
            // المصدر الموحّد AccountingService::recalculateSaleStatus() (يحدّث
            // paid_amount + status معًا)، ثم تُستكمل بتحديث منفصل لحقول الاعتماد
            // فقط (approved_by/approved_at/approval_note) التي لا تدخل ضمن تلك
            // الدالة المشتركة.
            $statusResult = $this->accounting->recalculateSaleStatus((int) $tenantId, (int) $saleId);
            $newStatus    = $statusResult['status'];

            $this->pdo->prepare("
                UPDATE sales
                SET approved_by = ?, approved_at = NOW(), approval_note = ?
                WHERE id = ? AND tenant_id = ?
            ")->execute([$this->userId, $note, $saleId, $tenantId]);

            $this->pdo->prepare("
                INSERT INTO invoice_approvals (tenant_id, sale_id, action, previous_status, new_status, note, action_by)
                VALUES (?, ?, 'approve', 'pending_approval', ?, ?, ?)
            ")->execute([$tenantId, $saleId, $newStatus, $note, $this->userId]);

            $this->logAudit('sale_status_updated', 'sales', (int) $saleId, [
                'tenant_id'  => (int) $tenantId,
                'user_id'    => (int) $this->userId,
                'branch_id'  => (int) $branchId,
                'session_id' => null,
                'sale_id'    => (int) $saleId,
                'new_status' => $newStatus,
                'source'     => 'approveSale',
            ], (int) $tenantId);

            $this->logAudit('sale_approved', 'sales', (int) $saleId, [
                'tenant_id' => (int) $tenantId,
                'user_id'   => (int) $this->userId,
                'sale_id'   => (int) $saleId,
                'note'      => $note,
            ], (int) $tenantId);

            return ['sale_id' => $saleId, 'status' => $newStatus];

        }, 'approve_sale', ['tenant_id' => $tenantId, 'sale_id' => $saleId]);
    }

    // =========================================================================
    // rejectSale — real logic extracted from SalesService
    // =========================================================================

    public function rejectSale(int $tenantId, int $saleId, ?string $note = null): array
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                "SELECT id, status FROM sales WHERE id = ? AND tenant_id = ? FOR UPDATE"
            );
            $stmt->execute([$saleId, $tenantId]);
            $sale = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$sale) {
                throw new Exception('الفاتورة غير موجودة');
            }
            if ($sale['status'] !== 'pending_approval') {
                throw new Exception('لا يمكن رفض فاتورة ليست قيد الموافقة');
            }

            $this->pdo->prepare("
                UPDATE sales
                SET status = 'rejected', approved_by = ?, approved_at = NOW(), approval_note = ?
                WHERE id = ? AND tenant_id = ?
            ")->execute([$this->userId, $note, $saleId, $tenantId]);

            $this->pdo->prepare("
                INSERT INTO invoice_approvals (tenant_id, sale_id, action, previous_status, new_status, note, action_by)
                VALUES (?, ?, 'reject', 'pending_approval', 'rejected', ?, ?)
            ")->execute([$tenantId, $saleId, $note, $this->userId]);

            $this->logAudit('sale_status_updated', 'sales', (int) $saleId, [
                'tenant_id'  => (int) $tenantId,
                'user_id'    => (int) $this->userId,
                'branch_id'  => null,
                'session_id' => null,
                'sale_id'    => (int) $saleId,
                'new_status' => 'rejected',
                'source'     => 'rejectSale',
            ], (int) $tenantId);

            $this->logAudit('sale_rejected', 'sales', (int) $saleId, [
                'tenant_id' => (int) $tenantId,
                'user_id'   => (int) $this->userId,
                'sale_id'   => (int) $saleId,
                'note'      => $note,
            ], (int) $tenantId);

            $this->pdo->commit();
            return ['sale_id' => $saleId, 'status' => 'rejected'];
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    // =========================================================================
    // updateSaleStatus — real logic extracted from SalesService
    // تغيير حالة الفاتورة يدوياً (draft/pending/canceled فقط)
    // =========================================================================

    public function updateSaleStatus(int $tenantId, int $saleId, string $newStatus, ?int $userId = null): array
    {
        $userId = $userId ?? $this->userId;

        $manuallyAllowed = ['draft', 'pending', 'canceled'];
        if (!in_array($newStatus, $manuallyAllowed, true)) {
            throw new Exception(
                'قيمة الحالة غير صالحة. الحالات المسموح بتعيينها يدوياً: ' .
                implode(', ', $manuallyAllowed) .
                '. حالات السداد (paid/partial/unpaid) تُحدَّث تلقائياً عند تسجيل الدفعات.'
            );
        }

        $stmt = $this->pdo->prepare(
            "SELECT status FROM sales WHERE id = ? AND tenant_id = ? LIMIT 1"
        );
        $stmt->execute([$saleId, $tenantId]);
        $sale = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$sale) {
            throw new \App\Exceptions\NotFoundException('الفاتورة غير موجودة');
        }

        $currentStatus = $sale['status'];
        $immutable     = ['posted', 'canceled', 'rejected', 'pending_approval'];

        if (in_array($currentStatus, $immutable, true)) {
            throw new Exception(
                "لا يمكن تغيير حالة فاتورة بحالة «{$currentStatus}». " .
                'استخدم نقطة نهاية الاعتماد أو الإلغاء المخصصة.'
            );
        }

        $transitions = [
            'draft'           => ['pending', 'canceled'],
            'pending'         => ['draft', 'canceled'],
            'paid'            => ['canceled'],
            'partial'         => ['canceled'],
            'pending_payment' => ['draft', 'canceled'],
        ];

        $allowed = $transitions[$currentStatus] ?? [];
        if (!in_array($newStatus, $allowed, true)) {
            throw new Exception(
                "الانتقال من «{$currentStatus}» إلى «{$newStatus}» غير مسموح."
            );
        }

        $this->pdo->prepare(
            "UPDATE sales SET status = ?, updated_at = NOW() WHERE id = ? AND tenant_id = ?"
        )->execute([$newStatus, $saleId, $tenantId]);

        $this->logAudit('sale_status_updated', 'sales', $saleId, [
            'tenant_id'   => (int) $tenantId,
            'user_id'     => $userId,
            'sale_id'     => (int) $saleId,
            'from_status' => $currentStatus,
            'to_status'   => $newStatus,
        ], (int) $tenantId);

        return [
            'sale_id'     => $saleId,
            'from_status' => $currentStatus,
            'to_status'   => $newStatus,
        ];
    }
}
