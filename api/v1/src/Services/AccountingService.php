<?php

namespace App\Services;

use PDO;
use App\Exceptions\AccountingPeriodClosedException;
use App\Services\MonologHandler;
use App\Services\CostCenter\CostCenterService;
use App\Repositories\SettingsRepository;

/**
 * Resolves tenant accounting configuration and builds journal entries for operational transactions.
 *
 * Persistence uses PDO; settings and cost-center resolution are delegated to their repositories and services.
 */
class AccountingService
{
    private PDO $db;
    private $logger;
    private SettingsRepository $settingsRepo;
    private CostCenterService $costCenterService;

    public function __construct(PDO $db)
    {
        $this->db                = $db;
        $this->logger            = MonologHandler::getInstance('accounting');
        $this->settingsRepo      = new SettingsRepository($db);
        $this->costCenterService = new CostCenterService($db, 'sales');
    }

    // ─── isCashMethod ─────────────────────────────────────────────────────────
    /**
     * Single Source of Truth لتحديد هل طريقة الدفع نقدية (تحتاج cashier session).
    * النوع النقدي الفعلي فقط: cash. التحويل والبطاقة والمحفظة لا تمثل نقد الدرج.
     */
    public function isCashMethod(int $paymentMethodId, int $tenantId): bool
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT kind FROM payment_methods WHERE id = ? AND tenant_id = ? LIMIT 1"
            );
            $stmt->execute([$paymentMethodId, $tenantId]);
            $kind = strtolower((string) ($stmt->fetchColumn() ?: ''));
            return $kind === 'cash';
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ─── resolveCostCenterForService ──────────────────────────────────────────
    /**
     * Resolve a cost center using the shared policy; log resolution failures and return null.
     */
    public function resolveCostCenterForService(int $tenantId, ?int $userId, $provided = null, ?int $providedBranchId = null): ?int
    {
        $providedInt = !empty($provided) ? (int) $provided : null;

        try {
            return $this->costCenterService->resolve($tenantId, $userId, $providedInt, $providedBranchId);
        } catch (\Throwable $e) {
            // نفس السلوك التاريخي لهذه الدالة: عند فشل كل خطوات الحل (لا توجد مراكز تكلفة
            // على الإطلاق للمستأجر، أو $provided غير صالحة)، تُرجَع null بدل رفع استثناء —
            // بعض مسارات المبيعات (كالدفعات) تتحمّل غياب مركز التكلفة، على عكس المشتريات/
            // المرتجعات التي تفضّل منع العملية بالكامل عبر catch على مستوى الـ Handler.
            $this->logger->debug('resolveCostCenter: resolution failed, returning NULL', [
                'tenant_id'          => $tenantId,
                'user_id'            => $userId,
                'provided_branch_id' => $providedBranchId,
                'error'              => $e->getMessage(),
            ]);
        }

        return null;
    }

    // ─── getAccountIdFallbackPublic ───────────────────────────────────────────
    /**
     * Public wrapper around getAccountIdFallback — للاستخدام من الـ handlers.
     */
    public function getAccountIdFallbackPublic(int $tenantId, array $codes): ?int
    {
        return $this->getAccountIdFallback($tenantId, $codes);
    }

    // ─── resolveAccountId (public) ────────────────────────────────────────────
    /**
     * Resolve an account ID for a tenant.
     *
     * Resolution order:
     *   1. settings row where tenant_id = $tenantId AND key_name = $settingKeyName
     *   2. settings row where tenant_id IS NULL  AND key_name = $settingKeyName  (global)
     *   3. accounts row where code = $fallbackCode (tenant-specific first, then global)
     *
     * Replaces the duplicated private resolveAccountId() that existed in:
     *   AdvancedReportsHandler, BranchHandler, ReturnsHandler, SalesService,
     *   and AccountStatementHandler.
     *
     * @param int    $tenantId        Tenant context.
     * @param string $settingKeyName  e.g. 'sales_account', 'cogs_account_id'
     * @param string $fallbackCode    e.g. '4001', '5103'
     */
    public function resolveAccountId(int $tenantId, string $settingKeyName, string $fallbackCode): ?int
    {
        try {
            // 1 & 2: Use SettingsRepository (handles tenant-specific + global fallback)
            $val = $this->settingsRepo->get($tenantId, $settingKeyName);
            if ($val !== null && (int) $val > 0) {
                return (int) $val;
            }

            // 3. Fallback by account code (tenant-specific first, then global)
            $stmt = $this->db->prepare(
                "SELECT id FROM accounts
                 WHERE code = ? AND (tenant_id = ? OR tenant_id IS NULL)
                 ORDER BY tenant_id DESC LIMIT 1"
            );
            $stmt->execute([$fallbackCode, $tenantId]);
            $accId = $stmt->fetchColumn();
            return $accId ? (int) $accId : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ─── getAccountByCode (public) ────────────────────────────────────────────
    /**
     * Get an account ID by its code for a given tenant.
     *
     * Replaces the duplicated private getGLAccountByCode() that existed in:
     *   OpeningBalanceHandler, ProductBranchHandler, ProductsHandler.
     *
     * @param int    $tenantId
     * @param string $code     e.g. '1301', '5001', '5103'
     */
    public function getAccountByCode(int $tenantId, string $code): ?int
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT id FROM accounts
                 WHERE code = ? AND (tenant_id = ? OR tenant_id IS NULL)
                 ORDER BY tenant_id DESC LIMIT 1"
            );
            $stmt->execute([$code, $tenantId]);
            $id = $stmt->fetchColumn();
            return $id ? (int) $id : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Resolve the inventory GL account using the approved priority:
     * branch.account_id, branch-specific tenant setting, tenant inventory setting, then account 1301.
     */
    public function resolveInventoryAccountForBranch(int $tenantId, ?int $branchId): ?int
    {
        if ($branchId !== null && $branchId > 0) {
            try {
                $stmt = $this->db->prepare(
                    'SELECT account_id FROM branches WHERE id = ? AND tenant_id = ? LIMIT 1'
                );
                $stmt->execute([$branchId, $tenantId]);
                $branchAccountId = (int) $stmt->fetchColumn();
                if ($branchAccountId > 0) {
                    return $branchAccountId;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Branch inventory account lookup failed; using configured fallbacks.', [
                    'tenant_id' => $tenantId,
                    'branch_id' => $branchId,
                    'error' => $e->getMessage(),
                ]);
            }

            foreach (["branch.{$branchId}.inventory_account_id", "inventory.branch.{$branchId}.account_id"] as $key) {
                $configuredId = $this->settingsRepo->getInt($tenantId, $key, 0);
                if ($configuredId > 0) {
                    return $configuredId;
                }
            }
        }

        foreach (['inventory_account_id', 'accounting.inventory_account_id'] as $key) {
            $configuredId = $this->settingsRepo->getInt($tenantId, $key, 0);
            if ($configuredId > 0) {
                return $configuredId;
            }
        }

        return $this->getAccountByCode($tenantId, '1301');
    }

    // ─── getAccountIdByCode ───────────────────────────────────────────────────
    private function getAccountIdByCode(int $tenantId, string $code): ?int
    {
        $stmt = $this->db->prepare("SELECT id FROM accounts WHERE code = ? AND tenant_id = ? LIMIT 1");
        $stmt->execute([$code, $tenantId]);
        $id = $stmt->fetchColumn();
        return $id ? (int) $id : null;
    }

    // ─── getAccountIdFallback ─────────────────────────────────────────────────
    private function getAccountIdFallback(int $tenantId, array $codes): ?int
    {
        foreach ($codes as $c) {
            $id = $this->getAccountIdByCode($tenantId, $c);
            if ($id) {
                return $id;
            }
        }
        return null;
    }

    // ─── postCOGSForSale ──────────────────────────────────────────────────────
    public function postCOGSForSale(int $tenantId, int $saleId, ?int $userId = null): ?int
    {
        $costing  = new CostingService($this->db);
        $saleStmt = $this->db->prepare("SELECT id, created_at, branch_id FROM sales WHERE id = ? AND tenant_id = ? LIMIT 1");
        $saleStmt->execute([$saleId, $tenantId]);
        $sale = $saleStmt->fetch(PDO::FETCH_ASSOC);
        if (!$sale) {
            return null;
        }

        $saleDate = $sale['created_at'] ?? null;
        $cogs     = (float) $costing->computeCOGSForSale($tenantId, $saleId, $saleDate);

        $cogs = round($cogs, 2);
        if ($cogs <= 0.0) {
            return null;
        }

        $cogsAccountId      = $this->resolveAccountId($tenantId, 'cogs_account_id', '5103');
        $inventoryAccountId = $this->resolveInventoryAccountForBranch(
            $tenantId,
            (int) ($sale['branch_id'] ?? 0) ?: null
        );
        if (!$cogsAccountId || !$inventoryAccountId) {
            return null;
        }

        $entryDate    = $saleDate ? substr($saleDate, 0, 10) : date('Y-m-d');
        $costCenterId = $this->resolveCostCenterForService($tenantId, $userId, null);

        $jeId = $this->postJournalEntry(
            $tenantId,
            'cogs',
            $saleId,
            "قيد تكلفة البضاعة المباعة لفاتورة بيع #$saleId",
            [
                ['account_id' => $cogsAccountId,      'debit' => $cogs, 'credit' => 0,     'description' => 'إثبات تكلفة البضاعة المباعة'],
                ['account_id' => $inventoryAccountId, 'debit' => 0,     'credit' => $cogs, 'description' => 'تخفيض المخزون بقيمة التكلفة'],
            ],
            $entryDate,
            $userId,
            $costCenterId
        );

        if ($jeId) {
            try {
                $this->db->prepare("UPDATE sales SET journal_entry_id = ? WHERE id = ? AND tenant_id = ?")
                    ->execute([$jeId, $saleId, $tenantId]);
            } catch (\Throwable $e) {
            }
        }

        return $jeId;
    }

    // ─── postOpeningBalance ───────────────────────────────────────────────────
    public function postOpeningBalance(int $tenantId, int $purchaseId, float $totalCost, ?int $userId = null): ?int
    {
        $totalCost = round($totalCost, 2);
        if ($totalCost <= 0) {
            return null;
        }

        $getSettingWithKey = function (int $tenantId, array $keys) {
            foreach ($keys as $key) {
                $val = $this->settingsRepo->getInt($tenantId, $key, 0);
                if ($val > 0) {
                    return ['id' => $val, 'key' => $key];
                }
            }
            return null;
        };

        $inventoryAccountId = $this->resolveInventoryAccountForBranch($tenantId, null);

        $equityLookup = $getSettingWithKey($tenantId, ['opening_balance_equity_account_id', 'accounting.opening_balance_equity_account_id', 'capital_account_id']);
        $equityAccountId = $equityLookup ? (int) $equityLookup['id'] : $this->getAccountIdFallback($tenantId, ['3001']);

        if (!$inventoryAccountId || !$equityAccountId) {
            return null;
        }

        $st = $this->db->prepare("SELECT invoice_date, cost_center_id FROM purchases WHERE id = ? AND tenant_id = ?");
        $st->execute([$purchaseId, $tenantId]);
        $purchaseData         = $st->fetch(\PDO::FETCH_ASSOC);
        $entryDate            = isset($purchaseData['invoice_date']) ? substr($purchaseData['invoice_date'], 0, 10) : date('Y-m-d');
        $purchaseCostCenterId = $purchaseData['cost_center_id'] ?? null;
        $costCenterId         = $this->resolveCostCenterForService($tenantId, $userId, $purchaseCostCenterId);

        $resolveInventoryAccountId = function (int $tenantId, ?int $branchId): int {
            $accountId = $this->resolveInventoryAccountForBranch($tenantId, $branchId);
            if (!$accountId) {
                throw new \RuntimeException('لم يتم تحديد حساب المخزون المناسب للفرع.');
            }
            return $accountId;
        };

        $lines = [];
        try {
            $gst = $this->db->prepare("SELECT branch_id, COALESCE(SUM(quantity*cost),0) AS amt FROM purchase_items WHERE purchase_id = ? AND tenant_id = ? GROUP BY branch_id");
            $gst->execute([$purchaseId, $tenantId]);
            while ($row = $gst->fetch(\PDO::FETCH_ASSOC)) {
                $bid = !empty($row['branch_id']) ? (int) $row['branch_id'] : null;
                $amt = (float) $row['amt'];
                if ($amt > 0) {
                    $lines[] = [
                        'account_id'  => $resolveInventoryAccountId($tenantId, $bid),
                        'debit'       => $amt,
                        'credit'      => 0,
                        'description' => $bid ? "إثبات رصيد المخزون الافتتاحي - مخزن #$bid" : 'إثبات رصيد المخزون الافتتاحي',
                    ];
                }
            }
        } catch (\Throwable $e) {
        }

        if (empty($lines)) {
            $lines[] = [
                'account_id'  => $inventoryAccountId,
                'debit'       => $totalCost,
                'credit'      => 0,
                'description' => 'إثبات رصيد المخزون الافتتاحي',
            ];
        }

        $lines[] = [
            'account_id'  => $equityAccountId,
            'debit'       => 0,
            'credit'      => $totalCost,
            'description' => 'حساب مقابل افتتاحي',
        ];

        $jeId = $this->postJournalEntry(
            $tenantId,
            'opening_balance',
            $purchaseId,
            "قيد رصيد افتتاحي مخزون - مشتريات افتتاحية #$purchaseId",
            $lines,
            $entryDate,
            $userId,
            $costCenterId
        );

        if ($jeId) {
            try {
                $this->db->prepare("UPDATE purchases SET journal_entry_id = ? WHERE id = ? AND tenant_id = ?")
                    ->execute([$jeId, $purchaseId, $tenantId]);
            } catch (\Throwable $e) {
            }
        }

        return $jeId;
    }

    // ─── postPayment ──────────────────────────────────────────────────────────
    public function postPayment(
        int     $tenantId,
        int     $paymentId,
        float   $amount,
        string  $paymentType,
        ?int    $saleId            = null,
        ?int    $purchaseId        = null,
        ?int    $returnId          = null,
        ?int    $userId            = null,
        ?int    $costCenterId      = null,
        ?int    $debitAccountId    = null,
        ?int    $creditAccountId   = null,
        ?string $description       = null,
        ?int    $paymentMethodId   = null   // ✅ CRITICAL-2: لتحديد حساب السيولة الصحيح
    ): ?int {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            return null;
        }

        try {
            // ✅ B-1: تحقق مسبق قبل فتح transaction
            $stmtCheck = $this->db->prepare("SELECT journal_entry_id FROM payments WHERE id = ? AND tenant_id = ?");
            $stmtCheck->execute([$paymentId, $tenantId]);
            $existingJeId = $stmtCheck->fetchColumn();

            if ($existingJeId !== false && $existingJeId !== null) {
                $this->logger->warning('Payment already posted to journal (skipping duplicate call)', [
                    'payment_id'               => $paymentId,
                    'existing_journal_entry_id' => $existingJeId,
                ]);
                return (int) $existingJeId;
            }

            $getSettingInt = function (int $tenantId, array $keys): ?int {
                foreach ($keys as $key) {
                    $val = $this->settingsRepo->getInt($tenantId, $key, 0);
                    if ($val > 0) {
                        return $val;
                    }
                }
                return null;
            };

            $finalDebitAccountId  = $debitAccountId;
            $finalCreditAccountId = $creditAccountId;
            $finalDescription     = $description;

            switch ($paymentType) {
                case 'sale':
                    // ✅ CRITICAL-2: استخدام resolveLiquidityAccount إن توفر payment_method_id
                    if (!$finalDebitAccountId) {
                        if ($paymentMethodId) {
                            $liq = $this->resolveLiquidityAccount($paymentMethodId, $tenantId);
                            if ($liq === null) {
                                // credit — الذمم تُعالج عند إنشاء الفاتورة، لا قيد سيولة مطلوب
                                return null;
                            }
                            $finalDebitAccountId = $liq;
                        } else {
                            $finalDebitAccountId = $getSettingInt($tenantId, ['accounting.cash_account_id', 'cash_account_id']) ?? $this->getAccountIdFallback($tenantId, ['1001']);
                        }
                    }
                    if (!$finalCreditAccountId) {
                        $finalCreditAccountId = $getSettingInt($tenantId, ['ar_account', 'accounting.ar_account_id', 'ar_account_id']) ?? $this->getAccountIdFallback($tenantId, ['1101']);
                    }
                    if (!$finalDescription) {
                        $finalDescription     = "دفعة مبيعات #" . ($saleId ?? 'N/A');
                    }
                    $referenceType = 'sale';
                    $referenceId   = $saleId;
                    break;

                case 'purchase':
                    if (!$finalDebitAccountId) {
                        $finalDebitAccountId  = $getSettingInt($tenantId, ['accounting.ap_account_id', 'ap_account_id']) ?? $this->getAccountIdFallback($tenantId, ['2101']);
                    }
                    // ✅ B-2: سداد المشتريات يُخرج من الحساب الصحيح لطريقة الدفع
                    if (!$finalCreditAccountId) {
                        if ($paymentMethodId) {
                            $liq = $this->resolveLiquidityAccount($paymentMethodId, $tenantId);
                            if ($liq === null) {
                                // credit — لا قيد سيولة لفاتورة الشراء الآجلة
                                return null;
                            }
                            $finalCreditAccountId = $liq;
                        } else {
                            $finalCreditAccountId = $getSettingInt($tenantId, ['accounting.cash_account_id', 'cash_account_id']) ?? $this->getAccountIdFallback($tenantId, ['1001']);
                        }
                    }
                    if (!$finalDescription) {
                        $finalDescription     = "دفعة مشتريات #" . ($purchaseId ?? 'N/A');
                    }
                    $referenceType = 'purchase';
                    $referenceId   = $purchaseId;
                    break;

                case 'return_payment':
                case 'return_receipt':
                    $this->logger->info("$paymentType: skipping (full JE already created by ReturnsHandler)", [
                        'return_id'  => $returnId,
                        'payment_id' => $paymentId,
                    ]);
                    return null;

                case 'customer_payment':
                    // ✅ CRITICAL-2: نفس المعالجة لدفعات العميل المستقلة
                    if (!$finalDebitAccountId) {
                        if ($paymentMethodId) {
                            $liq = $this->resolveLiquidityAccount($paymentMethodId, $tenantId);
                            if ($liq === null) {
                                // credit — لا قيد سيولة لدفعة العميل الآجلة
                                return null;
                            }
                            $finalDebitAccountId = $liq;
                        } else {
                            $finalDebitAccountId = $getSettingInt($tenantId, ['accounting.cash_account_id', 'cash_account_id']) ?? $this->getAccountIdFallback($tenantId, ['1001']);
                        }
                    }
                    if (!$finalCreditAccountId) {
                        $finalCreditAccountId = $getSettingInt($tenantId, ['ar_account', 'accounting.ar_account_id', 'ar_account_id']) ?? $this->getAccountIdFallback($tenantId, ['1101']);
                    }
                    if (!$finalDescription) {
                        $finalDescription     = "دفعة عميل #$paymentId";
                    }
                    $referenceType = 'customer_payment';
                    $referenceId   = $paymentId;
                    break;

                case 'supplier_payment':
                    if (!$finalDebitAccountId) {
                        $finalDebitAccountId  = $getSettingInt($tenantId, ['accounting.ap_account_id', 'ap_account_id']) ?? $this->getAccountIdFallback($tenantId, ['2101']);
                    }
                    // ✅ CRITICAL-2: سداد المورد يُخرج من الحساب الصحيح لطريقة الدفع
                    if (!$finalCreditAccountId) {
                        if ($paymentMethodId) {
                            $liq = $this->resolveLiquidityAccount($paymentMethodId, $tenantId);
                            if ($liq === null) {
                                // credit — لا قيد سيولة لسداد المورد الآجل
                                return null;
                            }
                            $finalCreditAccountId = $liq;
                        } else {
                            $finalCreditAccountId = $getSettingInt($tenantId, ['accounting.cash_account_id', 'cash_account_id']) ?? $this->getAccountIdFallback($tenantId, ['1001']);
                        }
                    }
                    if (!$finalDescription) {
                        $finalDescription     = "دفعة مورد #$paymentId";
                    }
                    $referenceType = 'supplier_payment';
                    $referenceId   = $paymentId;
                    break;

                default:
                    $this->logger->error('Unknown payment type', ['payment_type' => $paymentType]);
                    return null;
            }

            if (!$finalDebitAccountId || !$finalCreditAccountId) {
                throw new \Exception(sprintf(
                    'حسابات الدفع مفقودة للنوع [%s] — debit=%s, credit=%s',
                    $paymentType,
                    $finalDebitAccountId ?? 'null',
                    $finalCreditAccountId ?? 'null'
                ));
            }

            if (!$costCenterId) {
                $costCenterId = $this->resolveCostCenterForService($tenantId, $userId);
            }

            $entryDate = date('Y-m-d');
            try {
                // ✅ H-2: إضافة tenant_id لضمان عزل البيانات
                $stmtPay = $this->db->prepare("SELECT payment_date FROM payments WHERE id = ? AND tenant_id = ? LIMIT 1");
                $stmtPay->execute([$paymentId, $tenantId]);
                $paymentDate = $stmtPay->fetchColumn();
                if ($paymentDate) {
                    $entryDate = (new \DateTime($paymentDate))->format('Y-m-d');
                }
            } catch (\Throwable $e) {
            }

            // ✅ idempotency: مفتاح فريد per-payment يمنع تكرار القيد حتى مع concurrency
            $idempotencyKey = "payment_{$paymentId}_t{$tenantId}";

            // ✅ WARN-2: استدعاء postJournalEntry — مصدر واحد للـ INSERT لمنع divergence
            $jeId = $this->postJournalEntry(
                $tenantId,
                $referenceType,
                $referenceId,
                $finalDescription,
                [
                    [
                        'account_id'  => $finalDebitAccountId,
                        'debit'       => $amount,
                        'credit'      => 0,
                        'description' => "[Dr] $finalDescription",
                    ],
                    [
                        'account_id'  => $finalCreditAccountId,
                        'debit'       => 0,
                        'credit'      => $amount,
                        'description' => "[Cr] $finalDescription",
                    ],
                ],
                $entryDate,
                $userId,
                $costCenterId,
                $idempotencyKey
            );

            if (!$jeId) {
                return null;
            }

            // ربط القيد بسجل الدفعة — best-effort بعد نجاح postJournalEntry
            try {
                $this->db->prepare("UPDATE payments SET journal_entry_id = ? WHERE id = ? AND tenant_id = ?")
                    ->execute([$jeId, $paymentId, $tenantId]);
            } catch (\Throwable $ue) {
                $this->logger->warning('postPayment: failed to link journal_entry_id to payment', [
                    'payment_id'       => $paymentId,
                    'journal_entry_id' => $jeId,
                    'error'            => $ue->getMessage(),
                ]);
            }

            $this->logger->info('postPayment: journal entry posted', [
                'payment_id'       => $paymentId,
                'journal_entry_id' => $jeId,
                'payment_type'     => $paymentType,
                'amount'           => $amount,
            ]);

            return $jeId;
        } catch (\Throwable $e) {
            $this->logger->error('postPayment() error', [
                'payment_id' => $paymentId,
                'error'      => $e->getMessage(),
            ]);
            return null;
        }
    }

    // ─── postJournalEntry ─────────────────────────────────────────────────────
    /**
     * Validate and persist a balanced journal entry, reusing an existing entry when its idempotency key matches.
     */
    public function postJournalEntry(
        int     $tenantId,
        string  $referenceType,
        ?int    $referenceId,
        string  $description,
        array   $lines,
        ?string $entryDate      = null,
        ?int    $userId         = null,
        ?int    $costCenterId   = null,
        ?string $idempotencyKey = null
    ): ?int {
        try {
            // ── validation قبل فتح الـ transaction (لا تحتاج DB) ─────────────
            // ✅ N-1: throw بدل null صامت
            if (empty($lines) || !is_array($lines)) {
                throw new \Exception('postJournalEntry: لا توجد سطور قيد — لا يمكن إنشاء قيد فارغ.');
            }

            $totalDebit  = 0;
            $totalCredit = 0;
            foreach ($lines as $line) {
                $totalDebit  += floatval($line['debit']  ?? 0);
                $totalCredit += floatval($line['credit'] ?? 0);
            }

            // ✅ WARN-1: tolerance = 0.001 (كان 0.01 — قد يمرر فرق $100+ على فواتير كبيرة)
            if (abs($totalDebit - $totalCredit) > 0.001) {
                throw new \Exception(sprintf(
                    'قيد غير متوازن: مدين = %.4f, دائن = %.4f (فرق = %.6f) — يرجى مراجعة سطور القيد',
                    $totalDebit,
                    $totalCredit,
                    abs($totalDebit - $totalCredit)
                ));
            }

            // ✅ H-4: التحقق من account_id لكل السطور قبل الـ INSERT (early validation)
            foreach ($lines as $idx => $line) {
                if (empty($line['account_id'])) {
                    throw new \Exception("سطر القيد [دليل {$idx}] لا يحتوي على account_id صالح — يرجى ربط جميع الحسابات.");
                }
            }

            if (!$entryDate) {
                $entryDate    = date('Y-m-d');
            }
            if (!$costCenterId) {
                $costCenterId = $this->resolveCostCenterForService($tenantId, $userId);
            }

            // ✅ Period Close: رفض القيد إذا كانت التاريخ في دورة مغلقة
            $this->assertDateNotInClosedPeriod($tenantId, $entryDate);

            // ✅ BLOCKING-1 + WARN-4: فتح الـ transaction أولاً ثم check الـ idempotency
            // لمنع race condition بين الـ SELECT والـ INSERT
            $ownTransaction = !$this->db->inTransaction();
            if ($ownTransaction) {
                $this->db->beginTransaction();
            }

            // ── [FIX] WARN-4: Duplicate check داخل الـ transaction ──────────
            if ($idempotencyKey) {
                $stmtCheck = $this->db->prepare(
                    "SELECT id FROM journal_entries
                     WHERE tenant_id = ? AND reference_type = ? AND idempotency_key = ?
                     LIMIT 1"
                );
                $stmtCheck->execute([$tenantId, $referenceType, $idempotencyKey]);
                $existingJeId = $stmtCheck->fetchColumn();

                if ($existingJeId !== false && $existingJeId !== null) {
                    if ($ownTransaction) {
                        $this->db->rollBack();
                    }
                    $this->logger->info('Journal entry already exists (idempotency key match)', [
                        'reference_type'   => $referenceType,
                        'journal_entry_id' => $existingJeId,
                    ]);
                    return (int) $existingJeId;
                }
            }

            $stmtJE = $this->db->prepare(
                "INSERT INTO journal_entries (
                    tenant_id, entry_date, description, reference_type, reference_id,
                    created_by, created_at, status, cost_center_id, idempotency_key
                ) VALUES (?, ?, ?, ?, ?, ?, NOW(), 'posted', ?, ?)"
            );
            $stmtJE->execute([
                $tenantId, $entryDate, $description,
                $referenceType, $referenceId, $userId, $costCenterId, $idempotencyKey,
            ]);

            $jeId = (int) $this->db->lastInsertId();
            // ✅ N-2: throw بدل null صامت
            if (!$jeId) {
                if ($ownTransaction) {
                    $this->db->rollBack();
                }
                throw new \Exception('postJournalEntry: فشل إنشاء رأس القيد المحاسبي.');
            }

            $stmtLine = $this->db->prepare(
                "INSERT INTO journal_entry_lines (
                    journal_entry_id, account_id, debit_amount, credit_amount,
                    description, tenant_id, cost_center_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?)"
            );

            foreach ($lines as $line) {
                $stmtLine->execute([
                    $jeId,
                    $line['account_id'],
                    floatval($line['debit']  ?? 0),
                    floatval($line['credit'] ?? 0),
                    $line['description'] ?? $description,
                    $tenantId,
                    $costCenterId,
                ]);
            }

            if ($ownTransaction) {
                $this->db->commit();
            }

            $this->logger->info('Journal entry posted successfully', [
                'journal_entry_id' => $jeId,
                'reference_type'   => $referenceType,
                'reference_id'     => $referenceId,
                'line_count'       => count($lines),
                'total_debit'      => $totalDebit,
            ]);

            return $jeId;
        } catch (\Throwable $e) {
            if (!empty($ownTransaction) && $ownTransaction) {
                try {
                    $this->db->rollBack();
                } catch (\Throwable $re) {
                }
            }

            // ── [FIX] Idempotency recovery for concurrent duplicates ────────────
            // If an exception occurred during INSERT and we have an idempotencyKey,
            // try to retrieve the existing journal entry that caused the UNIQUE violation.
            // This handles the race condition where two concurrent requests both pass
            // the idempotency check, but one loses the INSERT race.
            if ($idempotencyKey) {
                try {
                    $stmtRecover = $this->db->prepare(
                        "SELECT id FROM journal_entries
                         WHERE tenant_id = ? AND idempotency_key = ?
                         LIMIT 1"
                    );
                    $stmtRecover->execute([$tenantId, $idempotencyKey]);
                    $recoveredJeId = $stmtRecover->fetchColumn();

                    if ($recoveredJeId !== false && $recoveredJeId !== null) {
                        $this->logger->info('Idempotency recovery: existing entry found after exception', [
                            'idempotency_key'  => $idempotencyKey,
                            'journal_entry_id' => $recoveredJeId,
                            'original_error'   => $e->getMessage(),
                        ]);
                        return (int) $recoveredJeId;
                    }
                } catch (\Throwable $recoveryException) {
                    // Recovery attempt itself failed — log it and continue to return null
                    $this->logger->warning('Idempotency recovery attempt failed', [
                        'idempotency_key'   => $idempotencyKey,
                        'recovery_error'    => $recoveryException->getMessage(),
                        'original_error'    => $e->getMessage(),
                    ]);
                }
            }

            if ($e instanceof AccountingPeriodClosedException) {
                throw $e;
            }

            $this->logger->error('postJournalEntry() error', [
                'reference_type' => $referenceType,
                'error'          => $e->getMessage(),
            ]);
            return null;
        }
    }

    // ─── resolveLiquidityAccount ──────────────────────────────────────────────
    /**
     * تحديد حساب السيولة (صندوق/بنك) لطريقة دفع معينة.
     * Single Source of Truth — تُستدعى من المبيعات والمشتريات والمرتجعات.
     *
     * @return int|null  معرف الحساب، أو null إذا كانت الطريقة آجلة (credit)
     * @throws \Exception إذا لم يُعثر على الحساب المناسب
     */
    public function resolveLiquidityAccount(int $paymentMethodId, int $tenantId): ?int
    {
        $stmt = $this->db->prepare("
            SELECT kind, account_id
            FROM payment_methods
            WHERE id = ? AND tenant_id = ?
            LIMIT 1
        ");
        $stmt->execute([$paymentMethodId, $tenantId]);
        $pmData = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$pmData) {
            throw new \Exception("طريقة الدفع غير صالحة: لم يتم العثور على سجل للمعرف {$paymentMethodId}");
        }

        if (empty($pmData['kind'])) {
            $this->logger->warning('payment_method has NULL kind — falling back to other', [
                'payment_method_id' => $paymentMethodId,
                'tenant_id'         => $tenantId,
            ]);
            $pmData['kind'] = 'other';
        }

        $kind = strtolower((string) $pmData['kind']);

        // ✅ #2: validation — رفض أي kind غير معروف
        $allowedKinds = ['cash', 'bank', 'card', 'wallet', 'credit', 'other'];
        if (!in_array($kind, $allowedKinds, true)) {
            throw new \Exception("نوع طريقة الدفع غير مدعوم: '{$kind}' — القيم المسموحة: " . implode(', ', $allowedKinds));
        }

        // آجل → null (لا سطر سيولة)، الذمم تُعالج منفصلاً
        // ✅ #4: المستدعي يجب أن يتحقق بـ !== null وليس فقط truthy
        if ($kind === 'credit') {
            return null;
        }

        // حساب مربوط مباشرة بطريقة الدفع — مطلوب لكل الأنواع غير الآجلة
        if (!empty($pmData['account_id'])) {
            return (int) $pmData['account_id'];
        }

        throw new \Exception(
            "طريقة الدفع [{$paymentMethodId}] من نوع '{$kind}' غير مرتبطة بحساب محاسبي — " .
            "يرجى ربطها بحساب سيولة من صفحة إعدادات طرق الدفع."
        );
    }

    // ─── deleteJournalEntry ───────────────────────────────────────────────────
    public function deleteJournalEntry(int $tenantId, int $journalEntryId): bool
    {
        $ownTransaction = !$this->db->inTransaction();
        try {
            if ($ownTransaction) {
                $this->db->beginTransaction();
            }

            $stmtLines = $this->db->prepare("DELETE FROM journal_entry_lines WHERE journal_entry_id = ? AND tenant_id = ?");
            $stmtLines->execute([$journalEntryId, $tenantId]);

            $stmtJE = $this->db->prepare("DELETE FROM journal_entries WHERE id = ? AND tenant_id = ?");
            $stmtJE->execute([$journalEntryId, $tenantId]);

            if ($ownTransaction) {
                $this->db->commit();
            }

            $this->logger->info('Journal entry deleted successfully', [
                'journal_entry_id' => $journalEntryId,
                'tenant_id'        => $tenantId,
            ]);

            return true;
        } catch (\Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }

            $this->logger->error('Failed to delete journal entry', [
                'journal_entry_id' => $journalEntryId,
                'tenant_id'        => $tenantId,
                'error'            => $e->getMessage(),
            ]);

            return false;
        }
    }

    // ─── assertDateNotInClosedPeriod ──────────────────────────────────────────
    /**
     * يرفع Exception إذا كان تاريخ القيد يقع ضمن دورة محاسبية مغلقة.
     */
    private function assertDateNotInClosedPeriod(int $tenantId, string $entryDate): void
    {
        $stmt = $this->db->prepare("
            SELECT period_name FROM accounting_periods
            WHERE tenant_id = ? AND status = 'closed'
              AND start_date <= ? AND end_date >= ?
            LIMIT 1
        ");
        $stmt->execute([$tenantId, $entryDate, $entryDate]);
        $period = $stmt->fetchColumn();
        if ($period !== false && $period !== null) {
            throw new AccountingPeriodClosedException("لا يمكن تسجيل قيد في دورة مغلقة: {$period}");
        }
    }

    // ─── reverseJournalEntry ──────────────────────────────────────────────────
    /**
     * يعكس قيداً محاسبياً بإنشاء قيد مرآة (Dr↔Cr مقلوبة).
     * يُحدِّث القيد الأصلي إلى status='reversed'.
     *
     * @return int  معرف قيد العكس الجديد
     * @throws \Exception في حالة الفشل
     */
    public function reverseJournalEntry(int $jeId, int $tenantId, ?int $userId = null): int
    {
        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $reversalJeId = $this->reverseJournalEntryWithinTransaction($jeId, $tenantId, $userId);
            if ($ownTransaction) {
                $this->db->commit();
            }
            return $reversalJeId;
        } catch (\Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    private function reverseJournalEntryWithinTransaction(int $jeId, int $tenantId, ?int $userId): int
    {
        $forUpdate = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'FOR UPDATE' : '';
        $stmtJE = $this->db->prepare("
            SELECT id, entry_date, description, reference_type, reference_id,
                   status, reversal_of, cost_center_id
            FROM journal_entries
            WHERE id = ? AND tenant_id = ?
            LIMIT 1
            {$forUpdate}
        ");
        $stmtJE->execute([$jeId, $tenantId]);
        $je = $stmtJE->fetch(\PDO::FETCH_ASSOC);

        if (!$je) {
            throw new \Exception("القيد المحاسبي رقم {$jeId} غير موجود.");
        }
        if ($je['status'] === 'reversed') {
            if ((int) ($je['reversal_of'] ?? 0) > 0) {
                return (int) $je['reversal_of'];
            }
            throw new \Exception("القيد رقم {$jeId} معكوس مسبقاً دون رابط لقيد العكس.");
        }
        if ($je['reversal_of'] !== null) {
            throw new \Exception("القيد رقم {$jeId} هو نفسه قيد عكس — لا يمكن عكسه مجدداً.");
        }

        $reversalDate = date('Y-m-d');
        $this->assertDateNotInClosedPeriod($tenantId, $reversalDate);

        $stmtLines = $this->db->prepare("
            SELECT account_id, debit_amount, credit_amount, description, cost_center_id
            FROM journal_entry_lines
            WHERE journal_entry_id = ? AND tenant_id = ?
        ");
        $stmtLines->execute([$jeId, $tenantId]);
        $lines = $stmtLines->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($lines)) {
            throw new \Exception("القيد رقم {$jeId} لا يحتوي على سطور.");
        }

        $reversedLines = array_map(fn ($l) => [
            'account_id'  => $l['account_id'],
            'debit'       => floatval($l['credit_amount']),
            'credit'      => floatval($l['debit_amount']),
            'description' => 'عكس: ' . $l['description'],
        ], $lines);

        $reversalDesc = 'عكس قيد #' . $jeId . ': ' . $je['description'];

        $reversalJeId = $this->postJournalEntry(
            $tenantId,
            'reversal',
            $jeId,
            $reversalDesc,
            $reversedLines,
            $reversalDate,
            $userId,
            $je['cost_center_id'] ? (int) $je['cost_center_id'] : null,
            'journal_reversal_' . $jeId
        );

        if (!$reversalJeId) {
            throw new \Exception("فشل إنشاء قيد العكس للقيد رقم {$jeId}.");
        }

        // تحديث القيد الأصلي → معكوس + رابط لقيد العكس
        $this->db->prepare("UPDATE journal_entries SET status = 'reversed', reversal_of = ? WHERE id = ? AND tenant_id = ?")
            ->execute([$reversalJeId, $jeId, $tenantId]);

        // ربط قيد العكس بالقيد الأصلي
        $this->db->prepare("UPDATE journal_entries SET reversal_of = ? WHERE id = ? AND tenant_id = ?")
            ->execute([$jeId, $reversalJeId, $tenantId]);

        $this->logger->info('Journal entry reversed', [
            'original_je_id' => $jeId,
            'reversal_je_id' => $reversalJeId,
        ]);

        return $reversalJeId;
    }

    // ─── postNrvWriteDown (IAS 2) ─────────────────────────────────────────────
    /**
     * يُسجِّل قيود تخفيض قيمة المخزون تلقائياً وفق IAS 2.
     * قيد لكل فرع:
     *   Dr. خسارة تخفيض قيمة المخزون  (5201)
     *   Cr. المخزون                     (1301 أو حساب الفرع)
     *
     * @return array{posted: int[], skipped: int[], total_amount: float}
     */
    public function postNrvWriteDown(int $tenantId, ?int $userId = null, ?int $branchId = null): array
    {
        $sql = "
            SELECT
                p.id            AS product_id,
                p.name          AS product_name,
                b.id            AS branch_id,
                b.name          AS branch_name,
                bp.quantity     AS qty_on_hand,
                pbm.average_cost AS unit_cost,
                p.sale_price    AS sale_price,
                ABS(bp.quantity * (pbm.average_cost - p.sale_price)) AS write_down_amount
            FROM products p
            JOIN branch_products bp
                ON bp.product_id = p.id AND bp.tenant_id = p.tenant_id
            JOIN branches b ON b.id = bp.branch_id
            JOIN product_branch_gl_mapping pbm
                ON pbm.product_id = p.id AND pbm.branch_id = bp.branch_id AND pbm.tenant_id = p.tenant_id
            WHERE p.tenant_id = ?
              AND p.active = 1
              AND bp.quantity > 0
              AND pbm.average_cost > 0
              AND pbm.average_cost > p.sale_price
        ";
        $bind = [(int) $tenantId];
        if ($branchId) {
            $sql .= " AND bp.branch_id = ?";
            $bind[] = $branchId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($bind);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($rows)) {
            return ['posted' => [], 'skipped' => [], 'total_amount' => 0.0];
        }

        // حساب تخفيض قيمة المخزون (impairment loss expense)
        $impairmentAccountId = $this->getAccountIdFallback($tenantId, ['5201', '5200']);
        if (!$impairmentAccountId) {
            throw new \Exception('لم يُعثر على حساب خسارة تخفيض المخزون (5201). يرجى إنشاؤه أولاً.');
        }

        // تجميع حسب الفرع
        $byBranch = [];
        foreach ($rows as $row) {
            $bid = (int) $row['branch_id'];
            if (!isset($byBranch[$bid])) {
                $byBranch[$bid] = ['branch_name' => $row['branch_name'], 'total' => 0.0];
            }
            $byBranch[$bid]['total'] += (float) $row['write_down_amount'];
        }

        $posted      = [];
        $skipped     = [];
        $totalAmount = 0.0;
        $today       = date('Y-m-d');

        foreach ($byBranch as $bid => $data) {
            $amount = round($data['total'], 2);
            if ($amount <= 0) {
                $skipped[] = $bid;
                continue;
            }

            $invAccountId = $this->resolveInventoryAccountForBranch($tenantId, (int) $bid);
            if (!$invAccountId) {
                $skipped[] = $bid;
                continue;
            }

            $idempotencyKey = "nrv_writedown_{$tenantId}_{$bid}_{$today}";
            $costCenterId   = $this->resolveCostCenterForService($tenantId, $userId, null);

            $jeId = $this->postJournalEntry(
                $tenantId,
                'nrv_writedown',
                $bid,
                "قيد تخفيض قيمة المخزون IAS 2 — فرع {$data['branch_name']} ({$today})",
                [
                    ['account_id' => $impairmentAccountId, 'debit' => $amount, 'credit' => 0,      'description' => 'خسارة تخفيض قيمة المخزون (IAS 2)'],
                    ['account_id' => $invAccountId,        'debit' => 0,       'credit' => $amount, 'description' => "تخفيض المخزون — فرع {$data['branch_name']}"],
                ],
                $today,
                $userId,
                $costCenterId,
                $idempotencyKey
            );

            if ($jeId) {
                $posted[]     = $jeId;
                $totalAmount += $amount;
            } else {
                $skipped[] = $bid;
            }
        }

        return [
            'posted'       => $posted,
            'skipped'      => $skipped,
            'total_amount' => round($totalAmount, 2),
        ];
    }

    // ─── postReturnJournalEntry ───────────────────────────────────────────────
    /**
     * Single Source of Truth لقيد المرتجعات (مبيعات أو مشتريات).
     *
     * يبني سطور القيد الكاملة:
     *   - مرتجع مبيعات: مردودات المبيعات + VAT عكس + سيولة + ذمم عميل + عكس COGS (WAC)
     *   - مرتجع مشتريات: ذمم مورد + مخزون + VAT عكس + سيولة
     *
     * ثم يستدعي postJournalEntry() — Single Source of Truth للـ INSERT.
     *
     * @param int        $returnId
     * @param int        $tenantId
     * @param string     $returnDate          YYYY-MM-DD
     * @param string     $returnNumber        رقم المرتجع للوصف
     * @param string     $returnType          'sale' | 'purchase'
     * @param mixed      $partyId             customer_id أو supplier_id
     * @param float      $totalAmount         الإجمالي قبل الضريبة
     * @param float      $paidAmount          المبلغ المدفوع فعلاً
     * @param int        $paymentMethodId
     * @param float      $taxAmount           قيمة الضريبة
     * @param int|null   $userId
     * @param string     $refundMode          'auto' | 'cash' | 'credit_note' | 'deduct_and_return'
     * @param float      $deductFromCustomerBalance  مبلغ الخصم من رصيد العميل
     * @param float|null $originalInvoiceOutstanding المتبقي على الفاتورة الأصلية
     * @param int|null   $costCenterId
     * @return int  journal_entry_id
     * @throws \Exception
     */
    public function postReturnJournalEntry(
        int     $returnId,
        int     $tenantId,
        string  $returnDate,
        string  $returnNumber,
        string  $returnType,
        $partyId,
        float   $totalAmount,
        float   $paidAmount,
        int     $paymentMethodId,
        float   $taxAmount,
        ?int    $userId                    = null,
        string  $refundMode                = 'auto',
        float   $deductFromCustomerBalance = 0.0,
        ?float  $originalInvoiceOutstanding = null,
        ?int    $costCenterId              = null
    ): int {
        $totalAmount = round($totalAmount, 2);
        $taxAmount   = round($taxAmount, 2);
        $paidAmount  = round($paidAmount, 2);

        // ── حل الحسابات ───────────────────────────────────────────────────────
        $accountsStmt = $this->db->prepare(
            "SELECT code, id FROM accounts
             WHERE code IN ('1101','2101','4002','5002','2201','2202')
               AND (tenant_id = ? OR tenant_id IS NULL)
             ORDER BY tenant_id DESC"
        );
        $accountsStmt->execute([$tenantId]);
        $accounts = [];
        foreach ($accountsStmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (!isset($accounts[$row['code']])) {
                $accounts[$row['code']] = (int) $row['id'];
            }
        }

        $salesReturnAccountId     = $accounts['4002'] ?? null;
        $purchaseReturnAccountId  = $accounts['5002'] ?? null;
        $genericCustomerAccountId = $accounts['1101'] ?? null;
        $genericSupplierAccountId = $accounts['2101'] ?? null;
        $vatInputAccountId        = $accounts['2201'] ?? null;
        $vatOutputAccountId       = $accounts['2202'] ?? null;

        $cogsAccountId = $this->resolveAccountId($tenantId, 'cogs_account_id', '5103');

        $stmtBranch = $this->db->prepare(
            "SELECT branch_id FROM returns WHERE id = ? AND tenant_id = ?"
        );
        $stmtBranch->execute([$returnId, $tenantId]);
        $returnBranchId = (int) ($stmtBranch->fetchColumn() ?: 0) ?: null;

        $inventoryAccountId = $this->resolveInventoryAccountForBranch($tenantId, null);
        $branchInventoryAccountId = $returnBranchId
            ? $this->resolveInventoryAccountForBranch($tenantId, $returnBranchId)
            : $inventoryAccountId;

        // حساب الطرف (عميل / مورد)
        $customerAccountIdToUse = $genericCustomerAccountId;
        $supplierAccountIdToUse = $genericSupplierAccountId;

        if ($returnType === 'sale' && $partyId) {
            $stmt = $this->db->prepare("SELECT account_id FROM customers WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$partyId, $tenantId]);
            $custAcc = $stmt->fetchColumn();
            if ($custAcc) {
                $customerAccountIdToUse = (int) $custAcc;
            }
        } elseif ($returnType === 'purchase' && $partyId) {
            $stmt = $this->db->prepare("SELECT account_id FROM suppliers WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$partyId, $tenantId]);
            $suppAcc = $stmt->fetchColumn();
            if ($suppAcc) {
                $supplierAccountIdToUse = (int) $suppAcc;
            }
        }

        // ── validation ────────────────────────────────────────────────────────
        // المرتجع النقدي الكامل بدون عميل لا يمثل ذمة مدينة؛ لذلك لا يحتاج
        // حساب العملاء، بينما المرتجع الآجل/الجزئي يحتاجه لتسجيل الرصيد الفعلي.
        $grandTotalAmount = round($totalAmount + $taxAmount, 2);
        $isFullyRefunded  = $paidAmount >= ($grandTotalAmount - 0.01);

        if ($returnType === 'sale') {
            if (!$salesReturnAccountId) {
                throw new \Exception('حساب مرتجع المبيعات (4002) غير معرف.');
            }
            if (!$partyId && !$isFullyRefunded) {
                throw new \Exception('يجب تحديد عميل للمرتجع الآجل أو غير المسدد بالكامل.');
            }
            if ($partyId && !$customerAccountIdToUse) {
                throw new \Exception('حساب العملاء (1101) غير معرف ولا يوجد حساب محدد للعميل.');
            }
        } else {
            if (!$purchaseReturnAccountId) {
                throw new \Exception('حساب مردودات المشتريات (5002) غير معرف.');
            }
            if (!$supplierAccountIdToUse) {
                throw new \Exception('حساب الموردين (2101) غير معرف ولا يوجد حساب محدد للمورد.');
            }
        }

        $description = 'قيد مرتجع ' . ($returnType === 'sale' ? 'مبيعات' : 'مشتريات') . ' رقم ' . $returnNumber;
        $lines       = [];

        // ── حساب المبلغ المتبقي (AR/AP) ───────────────────────────────────────
        if ($originalInvoiceOutstanding !== null) {
            $originalOutstanding = (float) $originalInvoiceOutstanding;
            if ($originalOutstanding > 0) {
                $remainingAmount = max(0, round(min($grandTotalAmount - $paidAmount, $originalOutstanding), 2));
            } else {
                $requestedDeduction = round($grandTotalAmount - $paidAmount, 2);
                if ($requestedDeduction <= 0) {
                    $remainingAmount = 0.0;
                } elseif (in_array(strtolower($refundMode), ['deduct_and_return', 'auto'], true)) {
                    // استخدم $deductFromCustomerBalance المحسوبة بالفعل في ReturnService
                    // لا تعيد الحساب من الـ DB — هذا يضمن توافق البيانات بين الطبقتين
                    if ($deductFromCustomerBalance > 0) {
                        $remainingAmount = round($deductFromCustomerBalance, 2);
                    } else {
                        $remainingAmount = 0.0;
                    }
                } else {
                    $remainingAmount = round($requestedDeduction, 2);
                }
            }
        } else {
            $remainingAmount = round($grandTotalAmount - $paidAmount, 2);
        }

        // ── حساب السيولة ──────────────────────────────────────────────────────
        $liquidityAccountId = null;
        if ($paidAmount > 0) {
            $liquidityAccountId = $this->resolveLiquidityAccount($paymentMethodId, $tenantId);
        }

        // ── بناء سطور القيد ───────────────────────────────────────────────────
        if ($returnType === 'sale') {
            // Dr. مردودات المبيعات
            $lines[] = [
                'account_id'  => $salesReturnAccountId,
                'debit'       => $totalAmount,
                'credit'      => 0,
                'description' => 'قيد مرتجع مبيعات',
            ];

            // Dr. ضريبة المخرجات (عكس)
            if ($taxAmount > 0) {
                if (!$vatOutputAccountId) {
                    throw new \Exception('قيمة ضريبة المخرجات موجودة ولكن حساب ضريبة المخرجات (2202) غير معرف.');
                }
                $lines[] = [
                    'account_id'  => $vatOutputAccountId,
                    'debit'       => $taxAmount,
                    'credit'      => 0,
                    'description' => 'عكس ضريبة المخرجات للمرتجع',
                ];
            }

            // Cr. سيولة (صرف نقدي للعميل)
            if ($paidAmount > 0 && $liquidityAccountId !== null) {
                $lines[] = [
                    'account_id'  => $liquidityAccountId,
                    'debit'       => 0,
                    'credit'      => $paidAmount,
                    'description' => 'صرف نقدي مرتجع للعميل',
                ];
            }

            // تُستخدم ذمم العميل فقط عند وجود عميل أو وجود مبلغ متبقٍ فعلي.
            // في المرتجع النقدي الكامل بدون عميل يكون المقابل هو السيولة مباشرة.
            if ($customerAccountIdToUse && ($partyId || !$isFullyRefunded)) {
                $lines[] = [
                    'account_id'  => $customerAccountIdToUse,
                    'debit'       => 0,
                    'credit'      => $grandTotalAmount,
                    'description' => 'إشعار دائن مرتجع',
                ];
                if ($paidAmount > 0) {
                    $lines[] = [
                        'account_id'  => $customerAccountIdToUse,
                        'debit'       => $paidAmount,
                        'credit'      => 0,
                        'description' => 'صرف نقدي للعميل',
                    ];
                }
            }

            // Dr. مخزون / Cr. تكلفة البضاعة المباعة (WAC reversal)
            try {
                $costing   = new CostingService($this->db);

                // ── AUDIT FIX #7 (HIGH) ──────────────────────────────────────────
                // Previously: getWeightedAverageCost() was called with $returnDate
                // (today, or whenever the return is processed), while the ORIGINAL
                // COGS line for the sale (postSaleJournalEntry → computeCOGSForSale)
                // was booked using the WAC as of the sale's own date. Because WAC
                // is a cumulative function of all purchases/purchase-returns dated
                // on or before a given date, any purchase recorded between the sale
                // and the return shifts the average — so the reversal no longer
                // nets to zero against the original entry, leaking a residual
                // amount into the inventory account on every return whose WAC
                // moved in the interim (see numeric example in the audit report,
                // Section E — RETURN-001).
                //
                // Fix: look up the WAC using the ORIGINAL SALE's date, not the
                // return's date. Since computeBatchWAC only depends on
                // purchase/purchase-return records with a purchase date <= the
                // date passed in, and those historical records are immutable by
                // the time a return happens, this reproduces the exact same WAC
                // value that was used when the original COGS was posted — making
                // the reversal net to zero against it as it should.
                $costingBasisDate = $returnDate;
                if ($returnType === 'sale') {
                    $origSaleStmt = $this->db->prepare(
                        "SELECT s.sale_date
                         FROM returns r
                         INNER JOIN sales s ON s.id = r.sale_id AND s.tenant_id = r.tenant_id
                         WHERE r.id = ? AND r.tenant_id = ?
                         LIMIT 1"
                    );
                    $origSaleStmt->execute([$returnId, $tenantId]);
                    $origSaleDate = $origSaleStmt->fetchColumn();
                    if ($origSaleDate) {
                        $costingBasisDate = $origSaleDate;
                    }
                }

                $itemsStmt = $this->db->prepare(
                                        "SELECT ri.product_id, ri.unit_id, ri.quantity
                                         FROM return_items ri
                                         INNER JOIN products p ON p.id = ri.product_id AND p.tenant_id = ri.tenant_id
                                         WHERE ri.return_id = ? AND ri.tenant_id = ?
                                             AND COALESCE(p.product_type, 'stock') = 'stock'"
                );
                $itemsStmt->execute([$returnId, $tenantId]);
                $items    = $itemsStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
                $convStmt = $this->db->prepare(
                    "SELECT conversion_factor FROM product_units
                     WHERE product_id = ? AND unit_id = ? AND tenant_id = ? LIMIT 1"
                );
                $totalInventoryAmount = 0.0;

                foreach ($items as $it) {
                    $convStmt->execute([(int) $it['product_id'], (int) $it['unit_id'], $tenantId]);
                    $conv    = (float) ($convStmt->fetchColumn() ?: 1.0);
                    $baseQty = (float) $it['quantity'] * ($conv > 0 ? $conv : 1.0);
                    if ($baseQty <= 0) {
                        continue;
                    }
                    $wac = $costing->getWeightedAverageCost($tenantId, (int) $it['product_id'], $costingBasisDate) ?? 0.0;
                    $totalInventoryAmount += $baseQty * $wac;
                }

                $totalInventoryAmount    = round($totalInventoryAmount, 2);
                $finalInventoryAccountId = $branchInventoryAccountId ?? $inventoryAccountId;

                if ($totalInventoryAmount > 0 && $finalInventoryAccountId && $cogsAccountId) {
                    $lines[] = [
                        'account_id'  => (int) $finalInventoryAccountId,
                        'debit'       => $totalInventoryAmount,
                        'credit'      => 0,
                        'description' => 'إرجاع مخزون من مرتجع مبيعات (WAC)',
                    ];
                    $lines[] = [
                        'account_id'  => (int) $cogsAccountId,
                        'debit'       => 0,
                        'credit'      => $totalInventoryAmount,
                        'description' => 'عكس تكلفة البضاعة المباعة لمرتجع مبيعات (WAC)',
                    ];
                }
            } catch (\Throwable $e) {
                $this->logger->error('postReturnJournalEntry: COGS reversal failed', [
                    'tenant_id' => $tenantId,
                    'return_id' => $returnId,
                    'error'     => $e->getMessage(),
                ]);
                throw new \RuntimeException('تعذر عكس تكلفة البضاعة المباعة للمرتجع.', 0, $e);
            }
        } else {
            // ── مرتجع مشتريات ─────────────────────────────────────────────────
            $finalInventoryAccountId = $branchInventoryAccountId ?? $inventoryAccountId;
            if (!$finalInventoryAccountId) {
                throw new \Exception('لم يتم تحديد حساب المخزون المناسب للفرع أو المستأجر.');
            }

            // Dr. ذمم المورد (تخفيض)
            $supplierDebitAmount = round($totalAmount + $taxAmount - $paidAmount, 2);
            if ($supplierDebitAmount > 0) {
                $lines[] = [
                    'account_id'  => $supplierAccountIdToUse,
                    'debit'       => $supplierDebitAmount,
                    'credit'      => 0,
                    'description' => 'تخفيض ذمة المورد لمرتجع مشتريات',
                ];
            }

            // Cr. مخزون
            $lines[] = [
                'account_id'  => $finalInventoryAccountId,
                'debit'       => 0,
                'credit'      => $totalAmount,
                'description' => 'تخفيض المخزون لمرتجع مشتريات',
            ];

            // Cr. ضريبة المدخلات (عكس)
            if ($taxAmount > 0) {
                if (!$vatInputAccountId) {
                    throw new \Exception('قيمة ضريبة المدخلات موجودة ولكن حساب ضريبة المدخلات (2201) غير معرف.');
                }
                $lines[] = [
                    'account_id'  => $vatInputAccountId,
                    'debit'       => 0,
                    'credit'      => $taxAmount,
                    'description' => 'عكس ضريبة المدخلات لمرتجع المشتريات',
                ];
            }

            // Dr. سيولة (استلام مبلغ المرتجع من المورد)
            if ($paidAmount > 0 && $liquidityAccountId !== null) {
                $lines[] = [
                    'account_id'  => $liquidityAccountId,
                    'debit'       => $paidAmount,
                    'credit'      => 0,
                    'description' => 'استلام قيمة المرتجع نقداً/بشيك من المورد',
                ];
            }
        }

        // ── إنشاء القيد ───────────────────────────────────────────────────────
        $journalEntryId = $this->postJournalEntry(
            $tenantId,
            $returnType === 'sale' ? 'sale_return' : 'purchase_return',
            $returnId,
            $description,
            $lines,
            $returnDate,
            $userId,
            $costCenterId
        );

        if (!$journalEntryId) {
            throw new \Exception('فشل في إنشاء قيد المرتجع في السجل المحاسبي.');
        }

        // ربط inventory_transactions بالقيد. أي فشل أو عدم تطابق يوقف المعاملة
        // الخارجية حتى لا يبقى المرتجع والقيد في حالة نصف مكتملة.
        $movementCountStmt = $this->db->prepare(
            "SELECT COUNT(*) AS total_count,
                    SUM(CASE WHEN journal_entry_id IS NULL OR journal_entry_id = 0 THEN 1 ELSE 0 END) AS pending_count
             FROM inventory_transactions
             WHERE tenant_id = ? AND reference_type = 'return' AND reference_id = ?"
        );
        $movementCountStmt->execute([$tenantId, $returnId]);
        $movementCounts = $movementCountStmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        $movementCount  = (int) ($movementCounts['total_count'] ?? 0);
        $pendingCount   = (int) ($movementCounts['pending_count'] ?? 0);

        if ($movementCount <= 0) {
            throw new \Exception('لا توجد حركة مخزون مرتبطة بالمرتجع لربطها بالقيد.');
        }

        if ($pendingCount > 0) {
            $linkStmt = $this->db->prepare(
                "UPDATE inventory_transactions SET journal_entry_id = ?
                 WHERE tenant_id = ? AND reference_type = 'return' AND reference_id = ?
                   AND (journal_entry_id IS NULL OR journal_entry_id = 0)"
            );
            $linkStmt->execute([$journalEntryId, $tenantId, $returnId]);
            if ($linkStmt->rowCount() !== $pendingCount) {
                throw new \Exception('فشل ربط جميع حركات مخزون المرتجع بالقيد المحاسبي.');
            }
        }

        return $journalEntryId;
    }

    // ─── postSalePaymentJournalEntry ─────────────────────────────────────────
    /**
     * Single Source of Truth لقيد دفعة مبيعات إضافية (بعد إنشاء الفاتورة).
     *
     *   Dr. سيولة (صندوق/بنك)   ← liquidityAccountId
     *   Cr. ذمم العملاء (AR)    ← arAccountId
     *
     * @param int    $tenantId
     * @param int    $saleId
     * @param int    $paymentId     Unique payment-record ID used as journal reference.
     * @param float  $amount
     * @param string $paymentDate
     * @param int    $paymentMethodId
     * @param int|null $userId
     * @param int|null $costCenterId
     * @return int  journal_entry_id
     * @throws \Exception
     */
    public function postSalePaymentJournalEntry(
        int    $tenantId,
        int    $saleId,
        int    $paymentId,
        float  $amount,
        string $paymentDate,
        int    $paymentMethodId,
        ?int   $userId       = null,
        ?int   $costCenterId = null,
        ?string $externalIdempotencyKey = null
    ): int {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new \Exception('قيمة الدفعة يجب أن تكون أكبر من صفر.');
        }
        if ($paymentId <= 0) {
            throw new \Exception('معرف سجل الدفعة مطلوب لإنشاء قيد تحصيل المبيعات.');
        }

        $liquidityAccountId = $this->resolveLiquidityAccount($paymentMethodId, $tenantId);
        if ($liquidityAccountId === null) {
            throw new \Exception('طريقة الدفع آجلة (credit) — لا سطر سيولة مطلوب.');
        }

        $entryDate = substr($paymentDate, 0, 10) ?: date('Y-m-d');

        // Fetch sale to determine if payment is full or partial
        $saleStmt = $this->db->prepare("
            SELECT 
                invoice_number,
                customer_account.account_id AS customer_account_id,
                (net_total_amount + IFNULL(tax_amount, 0)) AS grand_total,
                IFNULL(paid_amount, 0) AS current_paid_amount
            FROM sales
            LEFT JOIN customers customer_account
                ON customer_account.id = sales.customer_id
                AND customer_account.tenant_id = sales.tenant_id
            WHERE sales.id = ? AND sales.tenant_id = ?
            LIMIT 1
        ");
        $saleStmt->execute([$saleId, $tenantId]);
        $sale = $saleStmt->fetch(\PDO::FETCH_ASSOC);

        // Credit the same customer subledger account that was debited by the
        // invoice. Use the tenant's generic AR account only for walk-in sales
        // that have no linked customer account.
        $arAccountId = !empty($sale['customer_account_id'])
            ? (int) $sale['customer_account_id']
            : $this->resolveAccountId($tenantId, 'ar_account', '1101');
        if (!$arAccountId) {
            throw new \Exception('حساب ذمم العملاء (AR) غير معرّف.');
        }

        // Build description based on payment type
        $paymentDescription = 'تسديد جزء من الفاتورة';
        if ($sale) {
            $invoiceNumber = $sale['invoice_number'] ?? "#{$saleId}";
            $currentOutstanding = (float)$sale['grand_total'] - (float)$sale['current_paid_amount'];

            // Check if this payment will complete the invoice
            $totalAfterPayment = (float)$sale['current_paid_amount'] + $amount;
            $isFullPayment = (abs($totalAfterPayment - (float)$sale['grand_total']) < 0.01);

            if ($isFullPayment) {
                $paymentDescription = 'سداد فاتورة رقم ' . $invoiceNumber;
            } else {
                $paymentDescription = 'دفعة جزئية لفاتورة رقم ' . $invoiceNumber;
            }
        }

        // Use the request key to recognize an actual retry. When legacy callers
        // omit it, use the newly-created payment record so distinct receipts
        // with otherwise identical details cannot collide.
        $idempotencyKey = $externalIdempotencyKey !== null && $externalIdempotencyKey !== ''
            ? 'sale_payment_key_' . $saleId . '_' . $externalIdempotencyKey
            : 'sale_payment_record_' . $paymentId;

        $journalEntryId = $this->postJournalEntry(
            $tenantId,
            'customer_payment',
            $paymentId,
            $paymentDescription,
            [
                ['account_id' => $liquidityAccountId, 'debit' => $amount, 'credit' => 0,      'description' => 'تحصيل دفعة'],
                ['account_id' => $arAccountId,        'debit' => 0,       'credit' => $amount, 'description' => $paymentDescription],
            ],
            $entryDate,
            $userId,
            $costCenterId,
            $idempotencyKey
        );

        if (!$journalEntryId) {
            throw new \Exception('فشل إنشاء القيد المحاسبي لدفعة المبيعات.');
        }

        return $journalEntryId;
    }

    // ─── recalculateSaleStatus ────────────────────────────────────────────────
    /**
     * Recompute a sale's stored paid amount and status from completed payments,
     * payment allocations, return credits, and sale returns.
     *
     * @return array{status: string, paid_amount: float, return_credits: float, outstanding: float, return_amount: float}
     */
    public function recalculateSaleStatus(int $tenantId, int $saleId): array
    {
        $stmt = $this->db->prepare(
            "SELECT net_total_amount, IFNULL(tax_amount, 0) AS tax_amount
             FROM sales WHERE id = ? AND tenant_id = ? FOR UPDATE"
        );
        $stmt->execute([$saleId, $tenantId]);
        $sale = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$sale) {
            throw new \Exception("Sale not found for status recalculation: {$saleId}");
        }
        $grandTotal = round((float) $sale['net_total_amount'] + (float) $sale['tax_amount'], 2);

        // Net cash position: completed payments minus cash refunds
        // (type='return_payment') — matches SalesHandler::determineSaleStatus()'s
        // $actualPaid exactly, closing the refund-netting bug described above.
        $paidStmt = $this->db->prepare(
            "SELECT COALESCE(SUM(CASE WHEN type = 'return_payment' THEN -amount ELSE amount END), 0)
             FROM payments
             WHERE tenant_id = ? AND sale_id = ? AND status = 'completed' AND COALESCE(is_draft, 0) = 0"
        );
        $paidStmt->execute([$tenantId, $saleId]);
        $directPaid = (float) $paidStmt->fetchColumn();

        $allocatedPaidStmt = $this->db->prepare(
            "SELECT COALESCE(SUM(cpa.allocated_amount), 0)
             FROM customer_payment_allocations cpa
             JOIN payments p ON p.id = cpa.payment_id AND p.tenant_id = cpa.tenant_id
             WHERE cpa.tenant_id = ? AND cpa.sale_id = ?
               AND p.status = 'completed' AND COALESCE(p.is_draft, 0) = 0"
        );
        $allocatedPaidStmt->execute([$tenantId, $saleId]);
        $totalPaid = round($directPaid + (float) $allocatedPaidStmt->fetchColumn(), 2);

        $creditStmt = $this->db->prepare(
            "SELECT COALESCE(SUM(allocated_amount), 0) FROM return_credit_allocations
             WHERE tenant_id = ? AND sale_id = ?"
        );
        $creditStmt->execute([$tenantId, $saleId]);
        $returnCredits = round((float) $creditStmt->fetchColumn(), 2);

        $returnStmt = $this->db->prepare(
            "SELECT COALESCE(SUM(grand_total), 0) FROM returns
             WHERE sale_id = ? AND tenant_id = ? AND return_type = 'sale'"
        );
        $returnStmt->execute([$saleId, $tenantId]);
        $returnAmount = round((float) $returnStmt->fetchColumn(), 2);

        $directReturnStmt = $this->db->prepare(
            "SELECT COUNT(*) FROM returns WHERE sale_id = ? AND tenant_id = ? AND return_type = 'sale'"
        );
        $directReturnStmt->execute([$saleId, $tenantId]);
        $hasDirectReturn = ((int) $directReturnStmt->fetchColumn()) > 0;

        $outstanding = round(max(0.0, $grandTotal - $totalPaid - $returnCredits), 2);
        $status      = $this->computeSaleStatus($grandTotal, $totalPaid, $returnAmount, $returnCredits, $hasDirectReturn);

        $this->db->prepare(
            "UPDATE sales SET paid_amount = ?, status = ?, updated_at = NOW() WHERE id = ? AND tenant_id = ?"
        )->execute([$totalPaid, $status, $saleId, $tenantId]);

        return [
            'status'         => $status,
            'paid_amount'    => $totalPaid,
            'return_credits' => $returnCredits,
            'outstanding'    => $outstanding,
            'return_amount'  => $returnAmount,
        ];
    }

    /**
     * منطق تحديد الحالة الكامل — نسخة طبق الأصل (Faithful port) من
     * SalesHandler::determineSaleStatus() المستخدَمة سابقًا للعرض فقط
     * (dynamic_status)، وأصبحت الآن أيضًا المصدر الفعلي المكتوب في العمود
     * المخزَّن sales.status عبر recalculateSaleStatus() أعلاه. أي تعديل مستقبلي
     * على منطق الحالة يجب أن يحدث هنا (وفي determineSaleStatus للتطابق) وليس
     * في أي من نقاط الكتابة الأربع (SalePaymentService، SaleApprovalService،
     * CashVoucherService، أو مستقبلاً أي مسار جديد).
     */
    public function computeSaleStatus(
        float $grandTotal,
        float $paidAmount,
        float $returnAmount,
        float $returnCredits,
        bool $hasDirectReturn
    ): string {
        if ($grandTotal <= 0) {
            return 'paid';
        }
        if ($returnAmount >= $grandTotal) {
            return 'returned';
        }

        $outstanding = max(0.0, round($grandTotal - $paidAmount - $returnCredits, 2));

        if ($outstanding < 0.01) {
            if ($paidAmount >= $grandTotal - 0.01) {
                return ($hasDirectReturn || $returnAmount > 0.01) ? 'returned' : 'paid';
            }
            if ($paidAmount < 0.01) {
                if ($returnCredits > 0.01 && !$hasDirectReturn) {
                    return 'settled_by_credit';
                }
                return 'closed_by_return';
            }
            if ($returnCredits > 0.01 && $paidAmount > 0.01) {
                return 'settled_mixed';
            }
            return 'closed_by_return';
        }

        if ($paidAmount <= 0) {
            return 'pending_payment';
        }

        return 'partial';
    }

    // ─── postDebtPaymentJournalEntry ──────────────────────────────────────────
    /**
     * Single Source of Truth لقيد تحصيل دفعة عميل مرتبطة بسجل payment.
     *
     *   Dr. سيولة (صندوق/بنك)   ← liquidityAccountId
     *   Cr. حساب العميل          ← customerAccountId
     *
     * @param int    $tenantId
     * @param int    $customerId
     * @param int    $customerAccountId  حساب العميل المحاسبي
     * @param float  $amount
     * @param string $paymentDate
     * @param int    $paymentMethodId
     * @param int|null $userId
     * @param int|null $costCenterId
     * @return int  journal_entry_id
     * @throws \Exception
     */
    public function postDebtPaymentJournalEntry(
        int    $tenantId,
        int    $customerId,
        int    $customerAccountId,
        float  $amount,
        string $paymentDate,
        int    $paymentMethodId,
        ?int   $userId       = null,
        ?int   $costCenterId = null,
        ?int   $paymentId    = null,
        ?string $idempotencyKey = null
    ): int {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new \Exception('مبلغ الدفع غير صالح.');
        }
        if ($paymentId === null || $paymentId <= 0) {
            throw new \Exception('يجب إنشاء سجل الدفعة قبل ترحيل قيدها.');
        }

        $liquidityAccountId = $this->resolveLiquidityAccount($paymentMethodId, $tenantId);
        if ($liquidityAccountId === null) {
            throw new \Exception('طريقة الدفع آجلة (credit) — لا سطر سيولة مطلوب.');
        }

        // Equal-value payments on the same date are separate operations unless the caller retries the same key.
        $paymentIdentity = $idempotencyKey !== null && trim($idempotencyKey) !== ''
            ? trim($idempotencyKey)
            : bin2hex(random_bytes(32));
        $journalIdempotencyKey = 'customer_payment_' . $customerId . '_' . hash('sha256', $paymentIdentity);

        $journalEntryId = $this->postJournalEntry(
            $tenantId,
            'customer_payment',
            $paymentId,
            "تحصيل دفعة من العميل #{$customerId} بقيمة {$amount}",
            [
                ['account_id' => $liquidityAccountId,  'debit' => $amount, 'credit' => 0,      'description' => 'إيداع بالصندوق'],
                ['account_id' => $customerAccountId,   'debit' => 0,       'credit' => $amount, 'description' => 'تخفيض دين العميل'],
            ],
            substr($paymentDate, 0, 10) ?: date('Y-m-d'),
            $userId,
            $costCenterId,
            $journalIdempotencyKey
        );

        if (!$journalEntryId) {
            throw new \Exception('فشل إنشاء القيد المحاسبي لتحصيل الدين.');
        }

        return $journalEntryId;
    }

    // ─── postPurchaseJournalEntry ─────────────────────────────────────────────
    /**
     * Single Source of Truth لقيد فاتورة المشتريات.
     *
     * يبني سطور القيد:
     *   Dr. المخزون          (netBeforeTax)
     *   Dr. ضريبة المدخلات   (taxAmount — إن وُجدت)
     *   Cr. سيولة            (paidAmount — إن وُجدت)
     *   Cr. ذمم المورد       (balanceDue — إن وُجد)
     *
     * ثم يستدعي postJournalEntry() — Single Source of Truth للـ INSERT.
     *
     * @param int        $purchaseId
     * @param int        $tenantId
     * @param string     $invoiceNumber   رقم الفاتورة للوصف
     * @param string     $invoiceDate     YYYY-MM-DD أو datetime
     * @param int        $supplierAccountId
     * @param int        $inventoryAccountId
     * @param float      $totalAmount     الإجمالي شامل الضريبة
     * @param float      $paidAmount      المبلغ المدفوع فعلاً
     * @param float      $taxAmount       قيمة الضريبة
     * @param int        $paymentMethodId
     * @param int|null   $userId
     * @param int|null   $costCenterId
     * @return int  journal_entry_id
     * @throws \Exception
     */
    public function postPurchaseJournalEntry(
        int    $purchaseId,
        int    $tenantId,
        string $invoiceNumber,
        string $invoiceDate,
        int    $supplierAccountId,
        int    $inventoryAccountId,
        float  $totalAmount,
        float  $paidAmount,
        float  $taxAmount,
        int    $paymentMethodId,
        ?int   $userId        = null,
        ?int   $costCenterId  = null
    ): int {
        $totalAmount = round($totalAmount, 2);
        $taxAmount   = round($taxAmount, 2);
        $paidAmount  = min(round($paidAmount, 2), $totalAmount);

        if ($purchaseId <= 0) {
            throw new \Exception('بيانات القيد المحاسبي غير صالحة: purchase id مفقود.');
        }

        // ── idempotency: تجنب تكرار القيد ────────────────────────────────────
        $existing = $this->db->prepare(
            "SELECT id FROM journal_entries
             WHERE tenant_id = ? AND reference_type = 'purchase' AND reference_id = ? LIMIT 1"
        );
        $existing->execute([$tenantId, $purchaseId]);
        $existingJeId = $existing->fetchColumn();
        if ($existingJeId) {
            return (int) $existingJeId;
        }

        $netBeforeTax = round($totalAmount - $taxAmount, 2);
        $balanceDue   = round($totalAmount - $paidAmount, 2);

        // ── بناء سطور القيد ───────────────────────────────────────────────────
        $lines = [];

        // Dr. مخزون
        $lines[] = [
            'account_id'  => $inventoryAccountId,
            'debit'       => $netBeforeTax,
            'credit'      => 0,
            'description' => 'زيادة مخزون لفاتورة شراء #' . $invoiceNumber,
        ];

        // Dr. ضريبة المدخلات
        if ($taxAmount > 0) {
            $vatInputAccountId = $this->resolveAccountId($tenantId, 'vat.input_account_id', '2201');
            if (!$vatInputAccountId) {
                throw new \Exception('حساب ضريبة المدخلات غير معرف (vat.input_account_id أو الحساب 2201).');
            }
            $lines[] = [
                'account_id'  => $vatInputAccountId,
                'debit'       => $taxAmount,
                'credit'      => 0,
                'description' => 'ضريبة المدخلات لفاتورة شراء #' . $invoiceNumber,
            ];
        }

        // Cr. سيولة
        if ($paidAmount > 0) {
            $liquidityAccountId = $this->resolveLiquidityAccount($paymentMethodId, $tenantId);
            if ($liquidityAccountId === null) {
                // آجل — لا سطر سيولة، كل المبلغ على ذمم المورد
                $balanceDue = $totalAmount;
                $paidAmount = 0;
            } else {
                $lines[] = [
                    'account_id'  => $liquidityAccountId,
                    'debit'       => 0,
                    'credit'      => $paidAmount,
                    'description' => 'دفعة لفاتورة شراء #' . $invoiceNumber,
                ];
            }
        }

        // Cr. ذمم المورد
        if ($balanceDue > 0) {
            $lines[] = [
                'account_id'  => $supplierAccountId,
                'debit'       => 0,
                'credit'      => $balanceDue,
                'description' => 'استحقاق للمورد لفاتورة شراء #' . $invoiceNumber,
            ];
        }

        // ── إنشاء القيد ───────────────────────────────────────────────────────
        $entryDate = substr($invoiceDate, 0, 10) ?: date('Y-m-d');

        $journalEntryId = (int) $this->postJournalEntry(
            $tenantId,
            'purchase',
            $purchaseId,
            'فاتورة شراء #' . $invoiceNumber,
            $lines,
            $entryDate,
            $userId,
            $costCenterId
        );

        if ($journalEntryId <= 0) {
            throw new \Exception('فشل في إنشاء قيد الشراء في السجل المحاسبي.');
        }

        // ربط inventory_transactions بالقيد
        try {
            $this->db->prepare(
                "UPDATE inventory_transactions SET journal_entry_id = ?
                 WHERE tenant_id = ? AND reference_type = 'purchase' AND reference_id = ?
                   AND (journal_entry_id IS NULL OR journal_entry_id = 0)"
            )->execute([$journalEntryId, $tenantId, $purchaseId]);
        } catch (\Throwable $e) {
            $this->logger->warning('postPurchaseJournalEntry: failed to link inventory_transactions', [
                'tenant_id'        => $tenantId,
                'purchase_id'      => $purchaseId,
                'journal_entry_id' => $journalEntryId,
                'error'            => $e->getMessage(),
            ]);
        }

        return $journalEntryId;
    }

    // ─── postSaleJournalEntry ─────────────────────────────────────────────────
    /**
     * Single Source of Truth لقيد فاتورة المبيعات.
     * يُستخدم من SaleCreationService و SaleApprovalService.
     * يبني: Revenue + COGS + VAT + Liquidity + AR lines ثم يستدعي postJournalEntry().
     */
    public function postSaleJournalEntry(
        int    $saleId,
        int    $tenantId,
        int    $userId,
        string $saleDate,
        float  $netTotalAmount,
        float  $taxAmount,
        float  $paidAmount,
        ?int   $customerId,
        ?int   $paymentMethodId,
        ?int   $customerAccountId,
        int    $salesAccountId,
        ?int   $costCenterId = null
    ): int {
        $netTotalAmount = round($netTotalAmount, 2);
        $taxAmount      = round($taxAmount, 2);
        $paidAmount     = round($paidAmount, 2);
        $grossAmount    = round($netTotalAmount + $taxAmount, 2);

        // ── GUARD: منع الترحيل المزدوج ────────────────────────────────────────────
        // يتحقق أولاً من وجود قيد لهذه الفاتورة. إذا وُجد، يُخزَّن ID القيد في
        // $journalEntryId ويُتجاوز بناء $lines وإنشاء القيد. التنفيذ يستمر دائماً
        // إلى كود ربط inventory_transactions لضمان اكتمال الربط حتى عند إعادة المحاولة.
        $existingJeStmt = $this->db->prepare("
            SELECT id FROM journal_entries
            WHERE tenant_id = ? AND reference_type = 'sale' AND reference_id = ?
            LIMIT 1
        ");
        $existingJeStmt->execute([$tenantId, $saleId]);
        $existingJeId = $existingJeStmt->fetchColumn();

        if ($existingJeId !== false && (int) $existingJeId > 0) {
            $journalEntryId = (int) $existingJeId;
        } else {
            if ($paidAmount > $grossAmount + 0.01) {
                throw new \Exception("المبلغ المدفوع ({$paidAmount}) أكبر من إجمالي الفاتورة ({$grossAmount}) — يرجى مراجعة البيانات.");
            }
            $paidAmount = min($paidAmount, $grossAmount);

            $stmtSale = $this->db->prepare("SELECT branch_id FROM sales WHERE id = ? AND tenant_id = ? LIMIT 1");
            $stmtSale->execute([$saleId, $tenantId]);
            $branchId = (int) $stmtSale->fetchColumn();

            if ($costCenterId === null && $branchId > 0) {
                $costCenterId = $this->resolveCostCenterForService($tenantId, $userId, null, $branchId);
            }

            $lines = [];

            // سطر 1: إيراد المبيعات (دائن)
            $lines[] = ['account_id' => $salesAccountId, 'debit' => 0, 'credit' => $netTotalAmount, 'description' => "إيراد مبيعات فاتورة رقم #{$saleId}"];

            // سطر 2: COGS
            $cogsAmount = (float) (new CostingService($this->db))
                ->computeCOGSForSale($tenantId, $saleId, $saleDate);
            if ($cogsAmount > 0.0) {
                $cogsAccountId      = $this->resolveAccountId($tenantId, 'cogs_account_id', '5103');
                $inventoryAccountId = $this->resolveInventoryAccountForBranch(
                    $tenantId,
                    $branchId > 0 ? $branchId : null
                );
                if ($cogsAccountId && $inventoryAccountId) {
                    $lines[] = ['account_id' => $cogsAccountId,      'debit' => $cogsAmount, 'credit' => 0,           'description' => "تكلفة البضاعة المباعة لفاتورة #{$saleId}"];
                    $lines[] = ['account_id' => $inventoryAccountId, 'debit' => 0,           'credit' => $cogsAmount, 'description' => "خفض المخزون لفاتورة #{$saleId}"];
                }
            }

            // سطر 3: ضريبة المخرجات (دائن)
            if ($taxAmount > 0) {
                $vatAccountId = $this->resolveAccountId($tenantId, 'vat.output_account_id', '2202')
                    ?? $this->resolveAccountId($tenantId, 'vat_payable', '2202');
                if ($vatAccountId) {
                    $lines[] = ['account_id' => $vatAccountId, 'debit' => 0, 'credit' => $taxAmount, 'description' => "ضريبة المخرجات لفاتورة رقم #{$saleId}"];
                }
            }

            // سطر 4: السيولة (مدين)
            if ($paidAmount > 0 && $paymentMethodId) {
                $liquidityAccountId = $this->resolveLiquidityAccount($paymentMethodId, $tenantId);
                if ($liquidityAccountId !== null) {
                    $lines[] = ['account_id' => $liquidityAccountId, 'debit' => $paidAmount, 'credit' => 0, 'description' => "تحصيل من فاتورة رقم #{$saleId}"];
                }
            }

            // ذمم العملاء: لا تُنشأ في البيع النقدي الكامل بدون عميل.
            $isFullyPaid = $paidAmount >= $grossAmount - 0.01;
            if (!$customerId && !$isFullyPaid) {
                throw new \Exception('يجب تحديد عميل للبيع الآجل أو البيع غير المسدد بالكامل.');
            }

            if ($customerId && $grossAmount > 0) {
                $arAccountId = $this->resolveAccountId($tenantId, 'ar_account', '1101');
                if (!$arAccountId) {
                    throw new \Exception('حساب ذمم العملاء غير معرّف. يرجى التحقق من إعدادات الحسابات (ar_account).');
                }
                if (!$customerAccountId) {
                    $custStmt = $this->db->prepare("SELECT account_id FROM customers WHERE id = ? AND tenant_id = ?");
                    $custStmt->execute([$customerId, $tenantId]);
                    $fetched = $custStmt->fetchColumn();
                    if ($fetched) {
                        $arAccountId = (int) $fetched;
                    }
                } else {
                    $arAccountId = (int) $customerAccountId;
                }
                $lines[] = ['account_id' => $arAccountId, 'debit' => $grossAmount, 'credit' => 0, 'description' => "دين على العميل لفاتورة رقم #{$saleId}"];
                if ($paidAmount > 0) {
                    $lines[] = ['account_id' => $arAccountId, 'debit' => 0, 'credit' => $paidAmount, 'description' => "تسوية دفعة أولى من العميل لفاتورة رقم #{$saleId}"];
                }
            }

            $entryDate      = substr($saleDate, 0, 10) ?: date('Y-m-d');
            $journalEntryId = $this->postJournalEntry(
                $tenantId,
                'sale',
                $saleId,
                "قيد فاتورة بيع رقم #{$saleId}",
                $lines,
                $entryDate,
                $userId,
                $costCenterId,
                'sale_je_' . $saleId
            );

            if (!$journalEntryId) {
                throw new \Exception('فشل إنشاء القيد المحاسبي عبر الخدمة المركزية.');
            }
        }

        // ── ربط حركات المخزون بالقيد (يُنفَّذ دائماً — سواء كان القيد جديداً أو موجوداً) ──
        // Link all stock movements for this sale to the posted journal entry.
        // Any database failure propagates to SaleCreationService's transaction,
        // so the sale cannot commit in a partially linked state.
        $pendingMovementStmt = $this->db->prepare(
            "SELECT COUNT(*)
             FROM inventory_transactions
             WHERE tenant_id = ? AND reference_type = 'sale' AND reference_id = ?
               AND (journal_entry_id IS NULL OR journal_entry_id = 0)"
        );
        $pendingMovementStmt->execute([$tenantId, $saleId]);
        $pendingMovements = (int) $pendingMovementStmt->fetchColumn();

        if ($pendingMovements > 0) {
            $linkStmt = $this->db->prepare(
                "UPDATE inventory_transactions
                 SET journal_entry_id = ?
                 WHERE tenant_id = ? AND reference_type = 'sale' AND reference_id = ?
                   AND (journal_entry_id IS NULL OR journal_entry_id = 0)"
            );
            $linkStmt->execute([$journalEntryId, $tenantId, $saleId]);
            if ($linkStmt->rowCount() !== $pendingMovements) {
                throw new \RuntimeException('تعذر ربط حركات المخزون بالقيد المحاسبي للبيع.');
            }
        }

        return $journalEntryId;
    }
}
