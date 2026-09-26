<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use PDOException;
use Exception;
use App\Handlers\NotificationHandler;
use App\Services\Transaction\TransactionManager;
use App\Services\AccountingService;
use App\Services\CostingService;
use App\Services\MonologHandler;
use App\Repositories\SettingsRepository;
use App\Security\Permissions;
use App\Security\SaleDiscountPolicy;

/**
 * SaleCreationService
 *
 * Handles the full sale creation flow extracted from SalesService::createSale().
 * Includes: item calculation, stock application, journal entry, payments, cash transactions.
 *
 * Constructor signature matches PurchaseService / ReturnService:
 *   __construct(PDO $pdo, int $tenantId, ?int $userId)
 */
class SaleCreationService
{
    private PDO $pdo;
    private int $tenantId;
    private ?int $userId;
    private TransactionManager $txManager;
    private AccountingService $accounting;
    private SettingsRepository $settingsRepo;
    private FinancialCalculationService $financialCalcService;
    private $logger;

    public function __construct(PDO $pdo, int $tenantId, ?int $userId = null)
    {
        $this->pdo          = $pdo;
        $this->tenantId     = $tenantId;
        $this->userId       = $userId;
        $this->txManager    = new TransactionManager($pdo, 'sales');
        $this->accounting   = new AccountingService($pdo);
        $this->settingsRepo = new SettingsRepository($pdo);
        $this->financialCalcService = new FinancialCalculationService();
        $this->logger       = MonologHandler::getInstance('sales');
    }

    // =========================================================================
    // Private Helpers (copied from SalesService)
    // =========================================================================

    private function getSettingValue(int $tenantId, string $key, $default = null)
    {
        return $this->settingsRepo->get($tenantId, $key, $default !== null ? (string) $default : null) ?? $default;
    }

    private function getCompanyCurrency(int $tenantId): string
    {
        return $this->getSettingValue($tenantId, 'company.currency', 'EGP');
    }

    private function isApprovalRequired(int $tenantId, ?int $branchId = null): bool
    {
        if ($branchId) {
            $rawBranch = $this->getSettingValue($tenantId, 'pos.branch_approvals', null);
            if ($rawBranch) {
                try {
                    $branchMap = json_decode($rawBranch, true);
                    if (is_array($branchMap) && array_key_exists((string)$branchId, $branchMap)) {
                        return (bool)$branchMap[(string)$branchId];
                    }
                } catch (\Throwable $e) {
                }
            }
        }
        $raw = $this->getSettingValue($tenantId, 'pos.require_approval', '0');
        return in_array(strtolower(trim((string)$raw)), ['1', 'true', 'yes', 'on'], true);
    }

    private function getCurrentUserRoleId(): ?int
    {
        $stmt = $this->pdo->prepare("SELECT role_id FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$this->userId]);
        $rid = $stmt->fetchColumn();
        return $rid !== false ? (int)$rid : null;
    }

    private function isRoleEnforced(int $tenantId, ?int $roleId): bool
    {
        if (!$roleId) {
            return false;
        }
        $raw = (string)$this->getSettingValue($tenantId, 'pos.sessions.enforce_for_roles', '');
        $enforce = [];
        if ($raw !== '') {
            $trim = trim($raw);
            if (strpos($trim, '[') === 0) {
                $decoded = json_decode($trim, true);
                if (is_array($decoded)) {
                    $enforce = array_map('intval', $decoded);
                }
            } else {
                $parts = array_filter(array_map('trim', explode(',', $trim)), fn ($v) => $v !== '');
                $enforce = array_map('intval', $parts);
            }
        }
        return in_array((int)$roleId, $enforce, true);
    }

    private function findOpenCashierSession(int $tenantId, int $branchId, ?int $cashierId = null, ?string $deviceId = null): ?int
    {
        $sql = "SELECT id FROM cashier_sessions WHERE tenant_id = ? AND branch_id = ? AND status = 'open'";
        $params = [$tenantId, $branchId];
        if ($cashierId) {
            $sql .= " AND cashier_id = ?";
            $params[] = $cashierId;
        }
        if ($deviceId !== null && $deviceId !== '') {
            $sql .= " AND device_id = ?";
            $params[] = $deviceId;
        }
        $sql .= " ORDER BY id DESC LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn() ?: null;
    }

    private function findOpenGlobalCashierSession(int $tenantId, ?int $cashierId = null): ?int
    {
        $sql = "SELECT id FROM cashier_sessions WHERE tenant_id = ? AND branch_id IS NULL AND status = 'open'";
        $params = [$tenantId];
        if ($cashierId) {
            $sql .= " AND cashier_id = ?";
            $params[] = $cashierId;
        }
        $sql .= " ORDER BY id DESC LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn() ?: null;
    }

    private function resolveAccountId(int $tenantId, string $settingKeyName, string $fallbackCode): ?int
    {
        return $this->accounting->resolveAccountId($tenantId, $settingKeyName, $fallbackCode);
    }

    private function userCanDiscount(int $tenantId): bool
    {
        if (!$this->userId) {
            return false;
        }

        $userStmt = $this->pdo->prepare(
            'SELECT role_id FROM users WHERE id = ? AND (tenant_id = ? OR role_id = 1) LIMIT 1'
        );
        $userStmt->execute([$this->userId, $tenantId]);
        $roleId = $userStmt->fetchColumn();
        if ($roleId === false) {
            return false;
        }
        if ((int) $roleId === 1) {
            return true;
        }

        $permissionStmt = $this->pdo->prepare(
            "SELECT 1
             FROM role_permissions rp
             JOIN permissions p ON p.id = rp.permission_id
             JOIN roles r ON r.id = rp.role_id
             WHERE p.name = ?
               AND (r.tenant_id = ? OR (r.tenant_id IS NULL AND r.is_system_role = 1))
               AND (
                   r.id = (SELECT u.role_id FROM users u WHERE u.id = ? AND u.tenant_id = ?)
                   OR r.id IN (
                       SELECT ur.role_id FROM users_role ur
                       WHERE ur.user_id = ? AND (ur.tenant_id = ? OR ur.tenant_id IS NULL)
                   )
               )
             LIMIT 1"
        );
        $permissionStmt->execute([
            Permissions::SALE_DISCOUNT,
            $tenantId,
            $this->userId,
            $tenantId,
            $this->userId,
            $tenantId,
        ]);
        return (bool) $permissionStmt->fetchColumn();
    }

    private function generateInvoiceNumber(int $tenantId): string
    {
        $today = date('ymd');
        $stmt = $this->pdo->prepare("
            SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(invoice_number, '-', -1) AS UNSIGNED)), 0) as max_seq
            FROM sales
            WHERE tenant_id = ? AND DATE(created_at) = DATE(NOW())
              AND invoice_number LIKE CONCAT('S-', ?, '-%')
            FOR UPDATE
        ");
        $stmt->execute([$tenantId, $today]);
        $maxSeq = (int)$stmt->fetchColumn();
        return 'S-' . $today . '-' . str_pad((string)($maxSeq + 1), 3, '0', STR_PAD_LEFT);
    }

    private function resolvePaymentMethodId(array $data, float $paidAmount, int $tenantId): int
    {
        if (!empty($data['payment_method_id'])) {
            $pmId = (int)$data['payment_method_id'];
            // Verify the payment method exists for this tenant
            $stmt = $this->pdo->prepare("SELECT id FROM payment_methods WHERE id = ? AND tenant_id = ? LIMIT 1");
            $stmt->execute([$pmId, $tenantId]);
            if ($stmt->fetchColumn()) {
                return $pmId;
            }
            // If specified payment method doesn't exist, fall through to auto-resolve
        }
        $kind = $paidAmount > 0 ? 'cash' : 'credit';
        $stmt = $this->pdo->prepare("SELECT id FROM payment_methods WHERE kind = ? AND tenant_id = ? ORDER BY is_default DESC, id ASC LIMIT 1");
        $stmt->execute([$kind, $tenantId]);
        $id = $stmt->fetchColumn();
        if ($id) {
            return (int)$id;
        }
        $stmt = $this->pdo->prepare("SELECT id FROM payment_methods WHERE tenant_id = ? ORDER BY id ASC LIMIT 1");
        $stmt->execute([$tenantId]);
        $id = $stmt->fetchColumn();
        if ($id) {
            return (int)$id;
        }
        throw new \Exception("لم يتم العثور على أي طريقة دفع للمستأجر {$tenantId}.");
    }

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
        }
    }

    // =========================================================================
    // createSaleItemsAndHandleStock — extracted from SalesService
    // =========================================================================

    private function createSaleItemsAndHandleStock(int $saleId, int $tenantId, int $branchId, array $preparedItems, bool $applyStock = true): void
    {
        $notificationHandler = null;
        if ($applyStock) {
            $notificationHandler = new NotificationHandler($this->pdo);
        }

        // ✅ ترتيب ثابت حسب product_id لمنع deadlock عند فواتير متزامنة
        // تحتوي نفس المنتجات بترتيب مختلف
        if ($applyStock) {
            usort($preparedItems, fn ($a, $b) => (int)$a['product_id'] <=> (int)$b['product_id']);
        }

        foreach ($preparedItems as $item) {
            $productId       = (int)$item['product_id'];
            $unitId          = isset($item['unit_id']) ? (int)$item['unit_id'] : null;
            $conversionFactor = isset($item['conversion_factor']) ? (float)$item['conversion_factor'] : 1.0;
            $quantity        = (float)$item['quantity'];
            $salePrice       = (float)$item['sale_price'];
            $purchasePrice   = (float)$item['purchase_price'];
            $discountValue   = (float)($item['discount_value'] ?? 0);
            $netTotal        = (float)($item['net_total'] ?? (($salePrice * $quantity) - $discountValue));

            $stmt = $this->pdo->prepare("
                INSERT INTO sales_items (
                    sale_id, product_id, unit_id, conversion_factor, quantity,
                    sale_price, purchase_price, discount_type, discount_value, net_total,
                    batch_number, expiry_date, serial,
                    tenant_id, created_at, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 'amount', ?, ?, ?, ?, ?, ?, NOW(), ?)
            ");
            $stmt->execute([
                $saleId, $productId, $unitId, $conversionFactor, $quantity,
                $salePrice, $purchasePrice, $discountValue, $netTotal,
                $item['batch_number'] ?? null, $item['expiry_date'] ?? null, $item['serial'] ?? null,
                $tenantId, $this->userId,
            ]);

            if ($applyStock) {
                $baseQuantity = $quantity * $conversionFactor;
                $unitCost     = $purchasePrice;
                $totalCost    = $baseQuantity * $unitCost;

                $lockStmt = $this->pdo->prepare("
                    SELECT quantity FROM branch_products
                    WHERE product_id = ? AND branch_id = ? AND tenant_id = ? FOR UPDATE
                ");
                $lockStmt->execute([$productId, $branchId, $tenantId]);
                $availableQty = (float)($lockStmt->fetchColumn() ?? 0);

                if ($availableQty < $baseQuantity) {
                    throw new \App\Exceptions\InsufficientStockException(
                        "الكمية المتوفرة للمنتج {$productId} ({$availableQty}) أقل من الكمية المطلوبة ({$baseQuantity}).",
                        $productId,
                        $availableQty,
                        $baseQuantity
                    );
                }

                $this->pdo->prepare("
                    UPDATE branch_products
                    SET quantity      = quantity - ?,
                        quantity_cost = GREATEST(0, quantity_cost - ?)
                    WHERE product_id = ? AND branch_id = ? AND tenant_id = ?
                ")->execute([$baseQuantity, $totalCost, $productId, $branchId, $tenantId]);
                BranchInventoryReconciliationService::markUnreconciled(
                    $this->pdo,
                    $tenantId,
                    $branchId,
                    $productId
                );

                $this->pdo->prepare("
                    INSERT INTO inventory_transactions (
                        tenant_id, product_id, unit_id, branch_from, branch_to, quantity,
                        unit_cost, total_cost, movement_type, reference_type, reference_id,
                        user_id, movement_date, batch_number, expiry_date, serial
                    ) VALUES (?, ?, ?, ?, NULL, ?, ?, ?, 'out', 'sale', ?, ?, NOW(), ?, ?, ?)
                ")->execute([
                    $tenantId, $productId, $item['unit_id'] ?? 1, $branchId,
                    $baseQuantity, $unitCost, $totalCost, $saleId, $this->userId,
                    $item['batch_number'] ?? null, $item['expiry_date'] ?? null, $item['serial'] ?? null,
                ]);

                if ($notificationHandler !== null) {
                    try {
                        $notificationHandler->sendLowStockAlert($productId);
                    } catch (\Throwable $e) {
                        $this->logger->warning('Low stock notification failed', [
                            'message' => $e->getMessage(), 'product_id' => $productId,
                        ]);
                    }
                }

                if ($item['serial'] ?? null) {
                    $chkProduct = $this->pdo->prepare("SELECT has_serial_number FROM products WHERE id = ? AND tenant_id = ?");
                    $chkProduct->execute([$productId, $tenantId]);
                    if ($chkProduct->fetchColumn()) {
                        $this->pdo->prepare("
                            UPDATE product_serials
                            SET status = 'sold', transaction_id = LAST_INSERT_ID()
                            WHERE product_id = ? AND branch_id = ? AND serial_number = ?
                              AND tenant_id = ? AND status = 'in_stock'
                        ")->execute([$productId, $branchId, $item['serial'], $tenantId]);
                    }
                }
            }
        }
    }

    // =========================================================================
    // createSale — extracted from SalesService
    // =========================================================================

    /**
     * إنشاء فاتورة بيع كاملة مع عناصرها.
     *
     * @param  array $data  بيانات الفاتورة (tenant_id, items, branch_id, ...)
     * @return array ['sale_id' => int, 'invoice_number' => string]
     */
    public function createSale(array $data): array
    {
        if (empty($data['tenant_id'])) {
            throw new Exception('معرّف التاجر (tenant_id) مفقود');
        }
        $this->tenantId = (int)$data['tenant_id'];

        if (empty($data['items']) || !is_array($data['items'])) {
            throw new Exception('لا يمكن إنشاء فاتورة بدون أصناف');
        }
        if (empty($data['branch_id'])) {
            throw new Exception('يجب تحديد المخزن (branch_id)');
        }

        $status = $data['status'] ?? '';
        if (in_array($status, ['pending_payment', 'due', 'partial']) && empty($data['customer_id'])) {
            throw new Exception('يجب تحديد عميل لعمليات البيع الآجل والمدفوعات الجزئية');
        }
        if ($status === 'pending_approval' && !empty($data['payment_method_id']) && empty($data['customer_id'])) {
            $pmStmt = $this->pdo->prepare("SELECT kind FROM payment_methods WHERE id = ? AND tenant_id = ?");
            $pmStmt->execute([$data['payment_method_id'], $data['tenant_id']]);
            if ($pmStmt->fetchColumn() === 'credit') {
                throw new Exception('يجب تحديد عميل لفواتير الآجل (طريقة الدفع آجل/ذمم)');
            }
        }

        return $this->txManager->execute(function () use ($data) {
            $tenantId      = (int)$data['tenant_id'];
            $branchId      = (int)$data['branch_id'];
            $deviceId      = isset($data['device_id']) ? (string)$data['device_id'] : null;
            $discountType  = $data['discount_type']  ?? 'fixed';
            $discountValue = (float)($data['discount_value'] ?? 0);
            $customerId    = $data['customer_id'] ?? null;

            $paymentMethodId   = $this->resolvePaymentMethodId($data, (float)($data['paid_amount'] ?? 0), $tenantId);
            $customerAccountId = null;

            if ($customerId) {
                $stmt = $this->pdo->prepare("SELECT account_id FROM customers WHERE id = ? AND tenant_id = ?");
                $stmt->execute([$customerId, $tenantId]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($result) {
                    $customerAccountId = $result['account_id'];
                }
            }

            // ── حساب الإجماليات ──────────────────────────────────────────────
            $totalAmount    = 0;
            $totalProfit    = 0;
            $saleItemsForDb = [];

            foreach ($data['items'] as &$item) {
                if (!isset($item['unit_id']) || is_null($item['unit_id'])) {
                    throw new Exception("وحدة القياس (unit_id) مفقودة للمنتج برقم {$item['product_id']}");
                }
                $stmt = $this->pdo->prepare(
                    "SELECT sale_price, purchase_price, min_sale_price, fixed_discount_percentage
                     FROM products WHERE id = ? AND tenant_id = ?"
                );
                $stmt->execute([$item['product_id'], $tenantId]);
                $product = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$product) {
                    throw new Exception("المنتج برقم {$item['product_id']} غير موجود.");
                }
                $item['purchase_price'] = (float) $product['purchase_price'];
                $item['catalog_sale_price'] = (float) $product['sale_price'];
                $item['min_sale_price'] = (float) ($product['min_sale_price'] ?? 0);
                $item['fixed_discount_percentage'] = (float) ($product['fixed_discount_percentage'] ?? 0);
                $totalAmount += (float)$item['quantity'] * (float)$item['sale_price'];
            }
            unset($item);

            // ⚠️ إصلاح تناسق: كان الخصم يُحسب هنا بصيغة رابعة مستقلة، بلا أي حد أقصى — خصم
            // نسبته > 100% كان يُنتج قيمة خصم أكبر من الإجمالي نفسه (صافي سالب!). استُبدلت
            // بنفس FinancialCalculationService المستخدمة الآن في المشتريات، والتي تُعامل أي
            // نسبة > 100% كمبلغ ثابت محدود بالإجمالي (لا يمكن أن يتجاوز الخصم قيمة الفاتورة).
            $totalDiscountAmount = $this->financialCalcService->calculateDiscount(
                $totalAmount,
                (float) $discountValue,
                (string) $discountType
            );
            SaleDiscountPolicy::assertAllowed(
                $data['items'],
                $totalDiscountAmount,
                $totalAmount,
                $this->userCanDiscount($tenantId)
            );

            foreach ($data['items'] as $item) {
                $quantity      = (float)$item['quantity'];
                $salePrice     = (float)$item['sale_price'];
                $purchasePrice = (float)$item['purchase_price'];
                $itemTotal     = $quantity * $salePrice;
                $itemDiscount  = ($totalAmount > 0) ? ($itemTotal / $totalAmount) * $totalDiscountAmount : 0;
                $itemNetTotal  = $itemTotal - $itemDiscount;
                $itemNetPrice  = ($quantity > 0) ? $itemNetTotal / $quantity : 0;
                $itemProfit    = $itemNetTotal - ($purchasePrice * $quantity);
                $totalProfit  += $itemProfit;

                $saleItemsForDb[] = [
                    'product_id'    => $item['product_id'],
                    'unit_id'       => $item['unit_id'] ?? null,
                    'quantity'      => $quantity,
                    'sale_price'    => $salePrice,
                    'purchase_price' => $purchasePrice,
                    'discount_value' => $itemDiscount,
                    'net_price'     => $itemNetPrice,
                    'total'         => $itemTotal,
                    'net_total'     => $itemNetTotal,
                    'profit'        => $itemProfit,
                    'batch_number'  => $item['batch_number'] ?? null,
                    'expiry_date'   => $item['expiry_date']  ?? null,
                    'serial'        => $item['serial']       ?? null,
                ];
            }

            $netTotalAmount      = $totalAmount - $totalDiscountAmount;
            $taxRate             = $data['tax_rate'] ?? 0;
            $taxAmount           = ($taxRate / 100) * $netTotalAmount;
            $paidAmount          = (float)($data['paid_amount'] ?? 0);
            $grossAmountForStatus = $netTotalAmount + $taxAmount;

            // ── Approval mode ─────────────────────────────────────────────────
            $approvalRequired = $this->isApprovalRequired($tenantId, $branchId);
            if ($approvalRequired) {
                $paidAmount = 0.0;
                $status     = 'pending_approval';
            } else {
                $status = ($paidAmount >= $grossAmountForStatus) ? 'paid' : 'pending_payment';
            }

            $invoiceNumber = isset($data['invoice_number']) && $data['invoice_number']
                ? $data['invoice_number']
                : $this->generateInvoiceNumber($tenantId);

            // ── Early validations ─────────────────────────────────────────────
            if ($taxAmount > 0) {
                $accStmt = $this->pdo->prepare("SELECT id FROM accounts WHERE code = '2202' AND tenant_id = ?");
                $accStmt->execute([$tenantId]);
                if (!$accStmt->fetchColumn()) {
                    throw new Exception('لا يمكن إنشاء فاتورة بضريبة لأن حساب ضريبة المخرجات (2202) غير معرف للتاجر.');
                }
            }
            if ($paidAmount > 0) {
                try {
                    $liq = $this->accounting->resolveLiquidityAccount((int)$paymentMethodId, $tenantId);
                    if ($liq === null) {
                        throw new Exception('طريقة الدفع آجلة (credit) — لا يمكن تسجيل مبلغ مدفوع مع فاتورة آجلة، جعل المدفوع = 0.');
                    }
                } catch (\Exception $e) {
                    throw new Exception('لا يمكن تسجيل الدفعة: ' . $e->getMessage());
                }
            }

            // ── Session for cash ──────────────────────────────────────────────
            $sessionIdForSale = null;
            $isCash = $this->accounting->isCashMethod($paymentMethodId, $tenantId);
            if ($isCash) {
                $sessionIdForSale = $this->findOpenCashierSession($tenantId, $branchId, $this->userId, $deviceId);
                if (!$sessionIdForSale) {
                    $roleId   = $this->getCurrentUserRoleId();
                    $enforced = $this->isRoleEnforced($tenantId, $roleId);
                    if (!$enforced) {
                        $sessionIdForSale = $this->findOpenGlobalCashierSession($tenantId, $this->userId);
                    }
                }
            }

            // ── Resolve cost center ───────────────────────────────────────────
            $resolvedCostCenter = $this->accounting->resolveCostCenterForService($tenantId, $this->userId, $data['cost_center_id'] ?? null, $branchId);
            if (empty($resolvedCostCenter)) {
                try {
                    $st = $this->pdo->prepare("SELECT cost_center_id FROM branches WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL) LIMIT 1");
                    $st->execute([$branchId, $tenantId]);
                    $wc = $st->fetchColumn();
                    if ($wc) {
                        $resolvedCostCenter = (int)$wc;
                    }
                } catch (\Throwable $e) {
                }
            }
            if (empty($resolvedCostCenter)) {
                throw new Exception('فشل إنشاء الفاتورة: يجب أن يكون cost_center_id معرفًا في الفرع أو إعداد accounting.default_cost_center_id.');
            }

            // ── INSERT sales ──────────────────────────────────────────────────
            $stmt = $this->pdo->prepare("
                INSERT INTO sales (
                    tenant_id, customer_id, sale_date, total_amount, net_total_amount, paid_amount,
                    discount_type, discount_value, status, user_id, notes, total_items, total_profit,
                    branch_id, invoice_number, payment_method_id, tax_rate, tax_amount,
                    session_id, cost_center_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $params = [
                $tenantId, $data['customer_id'] ?? null, $data['sale_date'] ?? date('Y-m-d H:i:s'),
                $totalAmount, $netTotalAmount, $paidAmount, $discountType, $discountValue,
                $status, $this->userId, $data['notes'] ?? null, count($data['items']),
                $totalProfit, $branchId, $invoiceNumber, $paymentMethodId,
                $taxRate, $taxAmount, $sessionIdForSale, $resolvedCostCenter,
            ];

            $maxAttempts = 5;
            $attempt     = 0;
            while (true) {
                try {
                    $attempt++;
                    $stmt->execute($params);
                    break;
                } catch (PDOException $e) {
                    $msg = $e->getMessage();
                    if (($e->getCode() === '23000' || strpos($msg, 'Duplicate entry') !== false) && $attempt < $maxAttempts) {
                        $invoiceNumber = $this->generateInvoiceNumber($tenantId);
                        $params[14]    = $invoiceNumber;
                        continue;
                    }
                    throw $e;
                }
            }

            $saleId        = (int)$this->pdo->lastInsertId();
            $applyStockNow = !$approvalRequired;
            $this->createSaleItemsAndHandleStock($saleId, $tenantId, $branchId, $saleItemsForDb, $applyStockNow);

            // ── Draft payment for approval mode ───────────────────────────────
            if ($approvalRequired) {
                $intentAmount = isset($data['paid_amount']) ? (float)$data['paid_amount'] : 0.0;
                if ($intentAmount > 0) {
                    $resolvedCc = $this->accounting->resolveCostCenterForService($tenantId, $this->userId, $data['cost_center_id'] ?? null, $branchId);
                    $currency   = $this->getCompanyCurrency($tenantId);
                    $this->pdo->prepare("
                        INSERT INTO payments (tenant_id, sale_id, amount, payment_date, payment_method_id, customer_id,
                                             created_by, type, status, created_at, is_draft, due_date, session_id, cost_center_id, currency)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'sale', 'pending', NOW(), 1, ?, NULL, ?, ?)
                    ")->execute([
                        $tenantId, $saleId, max(0, $intentAmount), $data['sale_date'] ?? date('Y-m-d H:i:s'),
                        (int)$paymentMethodId, $data['customer_id'] ?? null, $this->userId,
                        $data['due_date'] ?? null, $resolvedCc, $currency,
                    ]);
                }
            }

            // ── Journal entry ─────────────────────────────────────────────────
            $journalEntryId = null;
            if (!$approvalRequired) {
                $salesAccountId = $this->resolveAccountId($tenantId, 'sales_account', '4001');
                if (!$salesAccountId) {
                    throw new Exception('حساب المبيعات غير معرّف لهذا التاجر');
                }

                $journalEntryId = $this->accounting->postSaleJournalEntry(
                    $saleId,
                    $tenantId,
                    $this->userId,
                    $data['sale_date'] ?? date('Y-m-d H:i:s'),
                    $netTotalAmount,
                    $taxAmount,
                    $paidAmount,
                    $customerId ? (int)$customerId : null,
                    $paymentMethodId ? (int)$paymentMethodId : null,
                    $customerAccountId ? (int)$customerAccountId : null,
                    $salesAccountId,
                    $resolvedCostCenter
                );

                if ($journalEntryId) {
                    $this->pdo->prepare("UPDATE sales SET journal_entry_id = ? WHERE id = ? AND tenant_id = ?")
                        ->execute([$journalEntryId, $saleId, $tenantId]);
                }
            }

            // ── Payment + cash transaction ────────────────────────────────────
            if (!$approvalRequired && $paidAmount > 0) {
                $isCash   = $this->accounting->isCashMethod($paymentMethodId, $tenantId);
                $sessionId = null;
                if ($isCash) {
                    $sessionId = $this->findOpenCashierSession($tenantId, $branchId, $this->userId, $deviceId);
                    if (!$sessionId) {
                        $roleId   = $this->getCurrentUserRoleId();
                        $enforced = $this->isRoleEnforced($tenantId, $roleId);
                        if (!$enforced) {
                            $sessionId = $this->findOpenGlobalCashierSession($tenantId, $this->userId);
                        }
                    }
                    if (!$sessionId) {
                        $roleId   = $this->getCurrentUserRoleId();
                        $enforced = $this->isRoleEnforced($tenantId, $roleId);
                        if ($enforced) {
                            throw new Exception('لا توجد جلسة كاشير مفتوحة لهذا المخزن. افتح جلسة قبل تسجيل الدفع النقدي.');
                        }
                        $this->pdo->prepare("
                            INSERT INTO cashier_sessions
                            (tenant_id, branch_id, cashier_id, session_type, start_time, end_time, status, created_by, created_at, updated_at)
                            VALUES (?, ?, ?, 'admin', NOW(), NOW(), 'closed', ?, NOW(), NOW())
                        ")->execute([$tenantId, $branchId, $this->userId, $this->userId]);
                        $sessionId = (int)$this->pdo->lastInsertId();
                    }
                }

                $resolvedCc2 = $this->accounting->resolveCostCenterForService($tenantId, $this->userId, $data['cost_center_id'] ?? null, $branchId);
                $currency    = $this->getCompanyCurrency($tenantId);

                $this->pdo->prepare("
                    INSERT INTO payments (tenant_id, sale_id, amount, payment_date, payment_method_id, created_by,
                                         customer_id, type, status, created_at, session_id, cost_center_id, journal_entry_id, currency)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'sale', 'completed', NOW(), ?, ?, ?, ?)
                ")->execute([
                    $tenantId, $saleId, $paidAmount, $data['sale_date'] ?? date('Y-m-d H:i:s'),
                    $paymentMethodId, $this->userId, $data['customer_id'] ?? null,
                    $sessionId, $resolvedCc2, $journalEntryId, $currency,
                ]);

                if ($isCash) {
                    $resolvedCcForCt = $this->accounting->resolveCostCenterForService($tenantId, $this->userId, $data['cost_center_id'] ?? null, $branchId);
                    $this->pdo->prepare("
                        INSERT INTO cash_transactions (
                            customer_id, amount, type, reference_type, reference_id,
                            payment_method_id, created_by, created_at, tenant_id, status,
                            session_id, cost_center_id, journal_entry_id
                        ) VALUES (?, ?, 'income', 'sale', ?, ?, ?, NOW(), ?, 'completed', ?, ?, ?)
                    ")->execute([
                        $data['customer_id'] ?? null, $paidAmount, $saleId,
                        $paymentMethodId, $this->userId, $tenantId,
                        $sessionId, $resolvedCcForCt, $journalEntryId,
                    ]);
                }
            }

            // ── Approval log ──────────────────────────────────────────────────
            if ($approvalRequired) {
                $this->pdo->prepare("
                    INSERT INTO invoice_approvals (tenant_id, sale_id, action, previous_status, new_status, note, action_by)
                    VALUES (?, ?, 'submit', NULL, 'pending_approval', ?, ?)
                ")->execute([$tenantId, $saleId, $data['notes'] ?? null, $this->userId]);
            }

            $this->logAudit('sale_created', 'sales', $saleId, [
                'tenant_id'        => $tenantId,
                'user_id'          => (int)$this->userId,
                'branch_id'        => $branchId,
                'session_id'       => null,
                'sale_id'          => $saleId,
                'invoice_number'   => $invoiceNumber,
                'customer_id'      => $customerId,
                'total_amount'     => (float)$totalAmount,
                'net_total_amount' => (float)$netTotalAmount,
                'tax_rate'         => (float)$taxRate,
                'tax_amount'       => (float)$taxAmount,
                'paid_amount'      => (float)$paidAmount,
                'status'           => $status,
                'payment_method_id' => $paymentMethodId,
            ], $tenantId);

            return ['sale_id' => $saleId, 'invoice_number' => $invoiceNumber];
        }, 'create_sale', ['tenant_id' => $data['tenant_id']]);
    }
}
