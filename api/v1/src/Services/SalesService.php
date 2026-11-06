<?php

namespace App\Services;

use PDO;
use Exception;
use App\Services\AccountingService;
use App\Services\MonologHandler;
use App\Repositories\SettingsRepository;
use App\Security\Permissions;
use App\Security\PermissionResolver;
use App\Security\SaleDiscountPolicy;

/**
 * SalesService
 *
 * Handles sale update and delete operations.
 * Creation  → SaleCreationService
 * Approval  → SaleApprovalService
 * Payments  → SalePaymentService
 */
class SalesService
{
    private $pdo;
    private $userId;
    private $tenantId;
    private $logger;
    private AccountingService $accounting;
    private SettingsRepository $settingsRepo;
    private FinancialCalculationService $financialCalcService;

    public function __construct(PDO $pdo, $userId = 1, ?int $tenantId = null)
    {
        $this->pdo        = $pdo;
        $this->userId     = $userId;
        $this->tenantId   = $tenantId;
        $this->logger     = MonologHandler::getInstance('sales');
        $this->accounting = new AccountingService($pdo);
        $this->settingsRepo = new SettingsRepository($pdo);
        $this->financialCalcService = new FinancialCalculationService();
    }

    // Audit helper

    protected function logAudit(
        string $action,
        string $entity,
        int    $entity_id,
        array  $details    = [],
        int    $tenant_id  = 0,
        ?int   $userId     = null
    ): bool {
        if ($tenant_id === 0 && isset($this->tenantId)) {
            $tenant_id = $this->tenantId;
        }
        $userId = $userId ?? $this->userId;
        try {
            $audit = new \App\Handlers\AuditHandler($this->pdo);
            return $audit->logAction(
                $action,
                $entity,
                $entity_id ?: null,
                $details,
                $tenant_id ?: null,
                $userId ?: null
            );
        } catch (\Throwable $e) {
            return false;
        }
    }

    // updateSale — تحديث بيانات فاتورة غير معتمدة

    public function updateSale(int $tenantId, int $saleId, array $data, ?int $allowedBranchId = null): array
    {
        $allowedFields = [
            'sale_date',
            'notes',
            'payment_method_id',
            'cost_center_id',
            'branch_id',
            'items',
            'below_cost_reason',
        ];
        $unsupportedFields = array_diff(array_keys($data), $allowedFields);
        if ($unsupportedFields) {
            throw new \DomainException('تحتوي بيانات التعديل على حقول غير مسموح بها.');
        }
        if (!$data) {
            throw new \DomainException('لم يتم إرسال أي بيانات لتعديل الفاتورة.');
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                "SELECT id, status, journal_entry_id, payment_method_id, paid_amount, branch_id,
                        tax_rate, tax_inclusive
                 FROM sales WHERE id = ? AND tenant_id = ? FOR UPDATE"
            );
            $stmt->execute([$saleId, $tenantId]);
            $sale = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$sale) {
                throw new Exception('Sale not found');
            }
            if ($allowedBranchId !== null && (int) $sale['branch_id'] !== $allowedBranchId) {
                throw new \DomainException('لا يمكنك تعديل فاتورة خارج نطاق فرعك.');
            }

            $journalCheck = $this->pdo->prepare(
                "SELECT COUNT(*) FROM journal_entries
                 WHERE tenant_id = ? AND status = 'posted'
                   AND (
                       id = ?
                       OR (reference_type IN ('sale', 'cogs') AND reference_id = ?)
                   )"
            );
            $journalCheck->execute([$tenantId, $sale['journal_entry_id'], $saleId]);
            \App\Security\SaleWorkflowPolicy::assertSaleEditable(
                (string) $sale['status'],
                (int) $journalCheck->fetchColumn() > 0
            );

            $currentBranchId = (int) $sale['branch_id'];
            $newBranchId = $currentBranchId;
            $branchChanged = false;
            if (array_key_exists('branch_id', $data)) {
                $branchValue = filter_var($data['branch_id'], FILTER_VALIDATE_INT);
                if ($branchValue === false || $branchValue <= 0) {
                    throw new \DomainException('الفرع المحدد غير صالح.');
                }
                $newBranchId = (int) $branchValue;
                if ($allowedBranchId !== null && $newBranchId !== $allowedBranchId) {
                    throw new \DomainException('لا يمكنك نقل الفاتورة إلى فرع خارج نطاقك.');
                }
                $branchStmt = $this->pdo->prepare(
                    'SELECT id FROM branches WHERE id = ? AND tenant_id = ?'
                );
                $branchStmt->execute([$newBranchId, $tenantId]);
                if (!$branchStmt->fetchColumn()) {
                    throw new \DomainException('الفرع المحدد غير متاح لهذا المستأجر.');
                }
                $branchChanged = $newBranchId !== $currentBranchId;
            }

            if ($branchChanged) {
                $paymentStmt = $this->pdo->prepare(
                    'SELECT COUNT(*) FROM payments WHERE tenant_id = ? AND sale_id = ? AND amount > 0'
                );
                $paymentStmt->execute([$tenantId, $saleId]);
                if ((float) ($sale['paid_amount'] ?? 0) > 0 || (int) $paymentStmt->fetchColumn() > 0) {
                    throw new \DomainException('لا يمكن نقل الفاتورة إلى فرع آخر بعد تسجيل تحصيل.');
                }
                if (
                    !array_key_exists('cost_center_id', $data)
                    || $data['cost_center_id'] === null
                    || $data['cost_center_id'] === ''
                ) {
                    $branchCostCenterStmt = $this->pdo->prepare(
                        'SELECT cost_center_id FROM branches WHERE id = ? AND tenant_id = ?'
                    );
                    $branchCostCenterStmt->execute([$newBranchId, $tenantId]);
                    $branchCostCenterId = $branchCostCenterStmt->fetchColumn();
                    $data['cost_center_id'] = $branchCostCenterId ? (int) $branchCostCenterId : null;
                }
            }

            $itemTotals = null;
            $previousItems = [];
            if (array_key_exists('items', $data)) {
                if ((float) ($sale['paid_amount'] ?? 0) > 0) {
                    throw new \DomainException('لا يمكن تعديل أصناف فاتورة عليها تحصيل.');
                }
                if (!is_string($data['below_cost_reason'] ?? '')) {
                    throw new \DomainException('سبب البيع بأقل من التكلفة غير صالح.');
                }
                $paymentStmt = $this->pdo->prepare(
                    'SELECT COUNT(*) FROM payments
                     WHERE tenant_id = ? AND sale_id = ? AND COALESCE(is_draft, 0) = 0 AND amount > 0'
                );
                $paymentStmt->execute([$tenantId, $saleId]);
                if ((int) $paymentStmt->fetchColumn() > 0) {
                    throw new \DomainException('لا يمكن تعديل أصناف فاتورة عليها تحصيل.');
                }
                $previousItemsStmt = $this->pdo->prepare(
                    'SELECT product_id, unit_id, quantity, sale_price, discount_value, net_total
                     FROM sales_items WHERE sale_id = ? AND tenant_id = ? ORDER BY id'
                );
                $previousItemsStmt->execute([$saleId, $tenantId]);
                $previousItems = $previousItemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $itemTotals = $this->prepareSaleItemsForUpdate(
                    $tenantId,
                    $newBranchId,
                    $data['items'],
                    $data['below_cost_reason'] ?? '',
                    (float) ($sale['tax_rate'] ?? 0),
                    (bool) ($sale['tax_inclusive'] ?? false)
                );
            }

            if (isset($data['sale_date'])) {
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $data['sale_date']);
                if (!$date || $date->format('Y-m-d') !== $data['sale_date']) {
                    throw new \DomainException('تاريخ الفاتورة غير صالح.');
                }
            }

            if (array_key_exists('payment_method_id', $data) && $data['payment_method_id'] !== null && $data['payment_method_id'] !== '') {
                $methodStmt = $this->pdo->prepare(
                    'SELECT id FROM payment_methods WHERE id = ? AND tenant_id = ?'
                );
                $methodStmt->execute([(int) $data['payment_method_id'], $tenantId]);
                if (!$methodStmt->fetchColumn()) {
                    throw new \DomainException('طريقة الدفع المحددة غير صالحة.');
                }
            }

            if (array_key_exists('cost_center_id', $data) && $data['cost_center_id'] !== null && $data['cost_center_id'] !== '') {
                $costCenterStmt = $this->pdo->prepare(
                    'SELECT id FROM cost_centers WHERE id = ? AND tenant_id = ?'
                );
                $costCenterStmt->execute([(int) $data['cost_center_id'], $tenantId]);
                $costCenter = $costCenterStmt->fetch(PDO::FETCH_ASSOC);
                if (!$costCenter) {
                    throw new \DomainException('مركز التكلفة المحدد غير صالح.');
                }
            }

            if (array_key_exists('payment_method_id', $data)) {
                $newPaymentMethodId = ($data['payment_method_id'] === null || $data['payment_method_id'] === '')
                    ? null
                    : (int) $data['payment_method_id'];
                $currentPaymentMethodId = $sale['payment_method_id'] !== null
                    ? (int) $sale['payment_method_id']
                    : null;
                if ($newPaymentMethodId !== $currentPaymentMethodId) {
                    $paymentStmt = $this->pdo->prepare(
                        'SELECT COUNT(*) FROM payments WHERE tenant_id = ? AND sale_id = ? AND amount > 0'
                    );
                    $paymentStmt->execute([$tenantId, $saleId]);
                    if ((float) ($sale['paid_amount'] ?? 0) > 0 || (int) $paymentStmt->fetchColumn() > 0) {
                        throw new \DomainException('لا يمكن تغيير طريقة الدفع بعد تسجيل دفعة على الفاتورة.');
                    }
                }
            }

            if ($itemTotals !== null && $itemTotals['has_below_cost']) {
                $reasonNote = 'سبب تعديل السعر بأقل من التكلفة: ' . trim((string) $data['below_cost_reason']);
                $existingNotes = trim((string) ($data['notes'] ?? ''));
                $data['notes'] = $existingNotes === ''
                    ? $reasonNote
                    : $existingNotes . "\n" . $reasonNote;
            }

            $columns = [
                'sale_date' => 'sale_date',
                'notes' => 'notes',
                'payment_method_id' => 'payment_method_id',
                'cost_center_id' => 'cost_center_id',
                'branch_id' => 'branch_id',
            ];
            $updates = [];
            $values = [];
            foreach ($columns as $field => $column) {
                if (array_key_exists($field, $data)) {
                    $updates[] = "{$column} = ?";
                    $values[] = in_array($field, ['payment_method_id', 'cost_center_id'], true)
                        && ($data[$field] === '' || $data[$field] === null)
                        ? null
                        : $data[$field];
                }
            }
            if ($itemTotals !== null) {
                $updates = array_merge($updates, [
                    'total_amount = ?',
                    'net_total_amount = ?',
                    'paid_amount = 0',
                    'discount_type = ?',
                    'discount_value = ?',
                    'tax_rate = ?',
                    'tax_amount = ?',
                    'tax_inclusive = ?',
                    'total_profit = ?',
                    'total_items = ?',
                ]);
                array_push(
                    $values,
                    $itemTotals['total_amount'],
                    $itemTotals['net_total_amount'],
                    'fixed',
                    $itemTotals['discount_value'],
                    $itemTotals['tax_rate'],
                    $itemTotals['tax_amount'],
                    $itemTotals['tax_inclusive'] ? 1 : 0,
                    $itemTotals['total_profit'],
                    count($itemTotals['items'])
                );
            }
            if ($itemTotals !== null && in_array(strtolower((string) $sale['status']), ['rejected', 'pending_approval'], true)) {
                $updates[] = "status = 'pending_approval'";
            }
            $updates[] = 'updated_at = NOW()';
            $values[] = $saleId;
            $values[] = $tenantId;
            $upd = $this->pdo->prepare(
                'UPDATE sales SET ' . implode(', ', $updates) . ' WHERE id = ? AND tenant_id = ?'
            );
            $upd->execute($values);

            if ($itemTotals !== null) {
                $this->pdo->prepare(
                    'DELETE FROM sales_items WHERE sale_id = ? AND tenant_id = ?'
                )->execute([$saleId, $tenantId]);
                $insertItem = $this->pdo->prepare(
                    "INSERT INTO sales_items (
                        sale_id, product_id, unit_id, conversion_factor, quantity,
                        sale_price, purchase_price, discount_type, discount_value, net_total, tax_amount,
                        batch_number, expiry_date, serial, tenant_id, created_at, created_by
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, 'amount', ?, ?, ?, ?, ?, ?, ?, NOW(), ?)"
                );
                foreach ($itemTotals['items'] as $item) {
                    $insertItem->execute([
                        $saleId,
                        $item['product_id'],
                        $item['unit_id'],
                        $item['conversion_factor'],
                        $item['quantity'],
                        $item['sale_price'],
                        $item['purchase_price'],
                        $item['discount_value'],
                        $item['net_total'],
                        $item['tax_amount'],
                        $item['batch_number'],
                        $item['expiry_date'],
                        $item['serial'],
                        $tenantId,
                        $this->userId,
                    ]);
                }
                $this->pdo->prepare(
                    'DELETE FROM payments WHERE tenant_id = ? AND sale_id = ? AND is_draft = 1'
                )->execute([$tenantId, $saleId]);
                if (in_array(strtolower((string) $sale['status']), ['rejected', 'pending_approval'], true)) {
                    $this->pdo->prepare(
                        "INSERT INTO invoice_approvals (
                            tenant_id, sale_id, action, previous_status, new_status, note, action_by
                         ) VALUES (?, ?, 'submit', ?, 'pending_approval', ?, ?)"
                    )->execute([
                        $tenantId,
                        $saleId,
                        (string) $sale['status'],
                        'تم تعديل بيانات الأصناف والأسعار وإعادة الفاتورة للاعتماد.',
                        $this->userId,
                    ]);
                }
            }

            $this->logAudit('sale_updated', 'sales', $saleId, [
                'tenant_id' => (int) $tenantId,
                'user_id'   => (int) $this->userId,
                'sale_id'   => (int) $saleId,
                'changed'   => array_keys($data),
                'items_changed' => $itemTotals !== null,
                'previous_status' => (string) $sale['status'],
                'previous_items' => $previousItems,
                'updated_items' => $itemTotals === null
                    ? null
                    : array_map(
                        static fn (array $item): array => [
                            'product_id' => $item['product_id'],
                            'unit_id' => $item['unit_id'],
                            'quantity' => $item['quantity'],
                            'sale_price' => $item['sale_price'],
                            'discount_value' => $item['discount_value'],
                            'net_total' => $item['net_total'],
                        ],
                        $itemTotals['items']
                    ),
            ], (int) $tenantId);

            $this->pdo->commit();
            return ['sale_id' => $saleId];
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Rebuild invoice lines and totals using the same product, price, discount,
     * and tax policies as sale creation. Approval-pending invoices have no
     * posted inventory effects, so no stock reversal is needed here.
     */
    private function prepareSaleItemsForUpdate(
        int $tenantId,
        int $branchId,
        $items,
        string $belowCostReason,
        float $fallbackTaxRate,
        bool $fallbackTaxInclusive
    ): array {
        if (!is_array($items) || $items === []) {
            throw new \DomainException('لا يمكن حفظ فاتورة بدون أصناف.');
        }
        if (count($items) > 500) {
            throw new \DomainException('عدد أصناف الفاتورة يتجاوز الحد المسموح.');
        }
        if (!is_string($belowCostReason) || strlen(trim($belowCostReason)) > 500) {
            throw new \DomainException('سبب البيع بأقل من التكلفة غير صالح.');
        }

        $prepared = [];
        $enteredSubtotal = 0.0;
        $totalAmount = 0.0;
        $manualPriceDiscountTotal = 0.0;
        $totalProfit = 0.0;
        $stockRequirements = [];
        $seenSerials = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new \DomainException('بيانات أحد أصناف الفاتورة غير صالحة.');
            }
            $productId = filter_var($item['product_id'] ?? null, FILTER_VALIDATE_INT);
            $unitId = filter_var($item['unit_id'] ?? null, FILTER_VALIDATE_INT);
            $quantity = $item['quantity'] ?? null;
            $salePrice = $item['sale_price'] ?? null;
            if ($productId === false || $productId <= 0 || $unitId === false || $unitId <= 0) {
                throw new \DomainException('المنتج ووحدة القياس مطلوبان لكل صنف.');
            }
            if (!is_numeric($quantity) || !is_finite((float) $quantity) || (float) $quantity <= 0) {
                throw new \DomainException('كمية الصنف يجب أن تكون أكبر من صفر.');
            }
            if (!is_numeric($salePrice) || !is_finite((float) $salePrice) || (float) $salePrice < 0) {
                throw new \DomainException('سعر بيع الصنف غير صالح.');
            }

            $productStmt = $this->pdo->prepare(
                'SELECT sale_price, purchase_price, min_sale_price,
                        fixed_discount_percentage, product_type, has_serial_number
                 FROM products WHERE id = ? AND tenant_id = ? AND active = 1'
            );
            $productStmt->execute([$productId, $tenantId]);
            $product = $productStmt->fetch(PDO::FETCH_ASSOC);
            if (!$product) {
                throw new \DomainException("المنتج رقم {$productId} غير موجود أو موقوف.");
            }

            $unitStmt = $this->pdo->prepare(
                'SELECT conversion_factor FROM product_units
                 WHERE product_id = ? AND unit_id = ? AND tenant_id = ? LIMIT 1'
            );
            $unitStmt->execute([$productId, $unitId, $tenantId]);
            $conversionFactor = $unitStmt->fetchColumn();
            if ($conversionFactor === false || !is_numeric($conversionFactor) || (float) $conversionFactor <= 0) {
                throw new \DomainException('وحدة القياس المحددة غير مرتبطة بالمنتج.');
            }

            $activeCampaign = PriceCampaignService::findActivePrice(
                $this->pdo,
                $tenantId,
                $branchId,
                $productId,
                $unitId
            );
            $catalogSalePrice = $activeCampaign !== null
                ? (float) $activeCampaign['campaign_price'] / (float) $conversionFactor
                : (float) $product['sale_price'];
            if ($activeCampaign !== null) {
                $salePrice = min((float) $salePrice, (float) $activeCampaign['campaign_price']);
            }
            $tracksStock = ProductBranchActivationGuard::assertSellableInBranch(
                $this->pdo,
                $tenantId,
                $branchId,
                (int) $productId
            );
            if ($tracksStock) {
                $stockRequirements[(int) $productId] = ($stockRequirements[(int) $productId] ?? 0.0)
                    + (float) $quantity * (float) $conversionFactor;
            }

            foreach (['serial', 'batch_number', 'expiry_date'] as $stringField) {
                if (isset($item[$stringField]) && !is_string($item[$stringField])) {
                    throw new \DomainException('بيانات الدفعة أو الرقم المسلسل غير صالحة.');
                }
            }
            if (
                strlen((string) ($item['serial'] ?? '')) > 255
                || strlen((string) ($item['batch_number'] ?? '')) > 50
            ) {
                throw new \DomainException('رقم المسلسل أو الدفعة يتجاوز الحد المسموح.');
            }
            if (!empty($item['expiry_date'])) {
                $expiryDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $item['expiry_date']);
                if (!$expiryDate || $expiryDate->format('Y-m-d') !== $item['expiry_date']) {
                    throw new \DomainException('تاريخ صلاحية الدفعة غير صالح.');
                }
            }
            $serial = trim((string) ($item['serial'] ?? ''));
            if ($tracksStock && (int) ($product['has_serial_number'] ?? 0) === 1) {
                if ($serial === '') {
                    throw new \DomainException("الرقم المسلسل مطلوب للمنتج رقم {$productId}.");
                }
                $serialStmt = $this->pdo->prepare(
                    "SELECT 1 FROM product_serials
                     WHERE tenant_id = ? AND product_id = ? AND branch_id = ?
                       AND serial_number = ? AND status = 'in_stock' LIMIT 1"
                );
                $serialStmt->execute([$tenantId, $productId, $branchId, $serial]);
                if (!$serialStmt->fetchColumn()) {
                    throw new \DomainException("الرقم المسلسل {$serial} غير متاح في هذا الفرع.");
                }
                $serialKey = $productId . ':' . $serial;
                if (isset($seenSerials[$serialKey])) {
                    throw new \DomainException("الرقم المسلسل {$serial} مكرر في أصناف الفاتورة.");
                }
                $seenSerials[$serialKey] = true;
                if ((float) $quantity !== 1.0) {
                    throw new \DomainException('يجب أن تكون كمية المنتج ذي الرقم المسلسل واحدًا لكل سطر.');
                }
            }

            $unitPrice = (float) $salePrice;
            $referenceUnitPrice = max(
                $unitPrice,
                $catalogSalePrice * (float) $conversionFactor
            );
            $enteredLineTotal = $unitPrice * (float) $quantity;
            $referenceLineTotal = $referenceUnitPrice * (float) $quantity;
            $enteredSubtotal += $enteredLineTotal;
            $totalAmount += $referenceLineTotal;
            $manualPriceDiscountTotal += max(0.0, $referenceLineTotal - $enteredLineTotal);

            $prepared[] = [
                'product_id' => (int) $productId,
                'unit_id' => (int) $unitId,
                'quantity' => (float) $quantity,
                'sale_price' => $unitPrice,
                'reference_unit_price' => $referenceUnitPrice,
                'entered_price' => $unitPrice,
                'purchase_price' => $tracksStock ? (float) $product['purchase_price'] : 0.0,
                'conversion_factor' => (float) $conversionFactor,
                'catalog_sale_price' => $catalogSalePrice,
                'min_sale_price' => $activeCampaign !== null
                    ? min((float) ($product['min_sale_price'] ?? 0), (float) $activeCampaign['campaign_price'] / (float) $conversionFactor)
                    : (float) ($product['min_sale_price'] ?? 0),
                'fixed_discount_percentage' => (float) ($product['fixed_discount_percentage'] ?? 0),
                'manual_price_override' => abs($unitPrice - $catalogSalePrice * (float) $conversionFactor) > 0.005,
                'below_cost_confirmed' => filter_var($item['below_cost_confirmed'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'below_minimum_price_confirmed' => filter_var($item['below_minimum_price_confirmed'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'batch_number' => $tracksStock ? ($item['batch_number'] ?? null) : null,
                'expiry_date' => $tracksStock ? ($item['expiry_date'] ?? null) : null,
                'serial' => $tracksStock ? ($serial !== '' ? $serial : null) : null,
                'product_type' => strtolower((string) ($product['product_type'] ?? 'stock')),
            ];
        }

        ksort($stockRequirements, SORT_NUMERIC);
        $stockStmt = $this->pdo->prepare(
            'SELECT quantity FROM branch_products
             WHERE product_id = ? AND branch_id = ? AND tenant_id = ? FOR UPDATE'
        );
        foreach ($stockRequirements as $productId => $requiredQuantity) {
            $stockStmt->execute([$productId, $branchId, $tenantId]);
            $availableQuantity = (float) ($stockStmt->fetchColumn() ?? 0);
            if ($availableQuantity + 0.0001 < $requiredQuantity) {
                throw new \App\Exceptions\InsufficientStockException(
                    "الكمية المتاحة للمنتج {$productId} ({$availableQuantity}) أقل من الكمية المطلوبة ({$requiredQuantity}).",
                    (int) $productId,
                    $availableQuantity,
                    $requiredQuantity
                );
            }
        }

        $canDiscount = $this->userCanDiscount($tenantId);
        $hasBelowCostItems = SaleDiscountPolicy::assertAllowed(
            $prepared,
            0.0,
            $enteredSubtotal,
            $canDiscount,
            $this->userCanApproveBelowCost($tenantId),
            trim($belowCostReason)
        );

        $taxEnabledSetting = $this->settingsRepo->get($tenantId, 'tax.tax_enabled')
            ?? $this->settingsRepo->get($tenantId, 'company.tax_enabled');
        $configuredTaxRate = $this->settingsRepo->get($tenantId, 'tax.tax_rate')
            ?? $this->settingsRepo->get($tenantId, 'company.tax_rate');
        $taxEnabled = $taxEnabledSetting === null
            ? $fallbackTaxRate > 0
            : in_array(strtolower(trim((string) $taxEnabledSetting)), ['1', 'true', 'yes', 'on'], true);
        $taxRate = $taxEnabled ? (float) ($configuredTaxRate ?? $fallbackTaxRate) : 0.0;
        if (!is_finite($taxRate) || $taxRate < 0 || $taxRate > 100) {
            throw new \DomainException('نسبة الضريبة المحفوظة غير صالحة.');
        }
        $taxInclusive = $taxEnabled
            && $this->settingsRepo->getBool($tenantId, 'invoice.show_tax_in_price', $fallbackTaxInclusive);

        $netTotalAmount = $totalAmount - $manualPriceDiscountTotal;
        $discountAmount = $manualPriceDiscountTotal;
        if ($taxInclusive && $taxRate > 0) {
            $grossTotal = round($netTotalAmount, 2);
            $netTotalAmount = $this->financialCalcService->calculateTaxInclusivePrice($grossTotal, $taxRate);
            $taxAmount = round($grossTotal - $netTotalAmount, 2);
        } else {
            $taxAmount = round($taxRate * $netTotalAmount / 100, 2);
        }

        $allocatedTax = 0.0;
        $largestLineIndex = null;
        $largestLineAmount = -1.0;
        foreach ($prepared as $index => &$item) {
            $lineTotal = (float) $item['reference_unit_price'] * (float) $item['quantity']
                - max(0.0, ((float) $item['reference_unit_price'] - (float) $item['entered_price']) * (float) $item['quantity']);
            $item['discount_value'] = max(
                0.0,
                ((float) $item['reference_unit_price'] - (float) $item['entered_price']) * (float) $item['quantity']
            );
            $item['sale_price'] = $item['reference_unit_price'];
            $item['net_total'] = $lineTotal;
            $revenue = $taxInclusive
                ? $this->financialCalcService->calculateTaxInclusivePrice($lineTotal, $taxRate)
                : $lineTotal;
            $item['profit'] = $revenue - ((float) $item['purchase_price'] * (float) $item['quantity']);
            $item['tax_amount'] = $taxInclusive && $taxRate > 0
                ? round($lineTotal - $this->financialCalcService->calculateTaxInclusivePrice($lineTotal, $taxRate), 2)
                : round($lineTotal * $taxRate / 100, 2);
            $allocatedTax += $item['tax_amount'];
            if ($lineTotal > $largestLineAmount) {
                $largestLineAmount = $lineTotal;
                $largestLineIndex = $index;
            }
            $totalProfit += $item['profit'];
        }
        unset($item);
        if ($largestLineIndex !== null) {
            $prepared[$largestLineIndex]['tax_amount'] = round(
                $prepared[$largestLineIndex]['tax_amount'] + $taxAmount - $allocatedTax,
                2
            );
        }

        return [
            'items' => $prepared,
            'has_below_cost' => $hasBelowCostItems,
            'total_amount' => round($totalAmount, 2),
            'net_total_amount' => round($netTotalAmount, 2),
            'discount_value' => round($discountAmount, 2),
            'tax_rate' => $taxRate,
            'tax_amount' => round($taxAmount, 2),
            'tax_inclusive' => $taxInclusive,
            'total_profit' => round($totalProfit, 2),
        ];
    }

    private function userCanDiscount(int $tenantId): bool
    {
        if (!$this->userId) {
            return false;
        }
        $userStmt = $this->pdo->prepare(
            'SELECT 1 FROM users WHERE id = ? AND tenant_id = ? LIMIT 1'
        );
        $userStmt->execute([$this->userId, $tenantId]);
        if (!$userStmt->fetchColumn()) {
            return false;
        }
        $roleNames = $this->getSaleRoleNames($tenantId);
        if (array_intersect($roleNames, ['admin', 'manager', 'branch_manager', 'super_admin'])) {
            return true;
        }
        if (in_array('cashier', $roleNames, true)) {
            return false;
        }
        return PermissionResolver::hasPermission($this->pdo, (int) $this->userId, Permissions::SALE_DISCOUNT);
    }

    private function userCanApproveBelowCost(int $tenantId): bool
    {
        $userStmt = $this->pdo->prepare(
            'SELECT is_owner FROM users WHERE id = ? AND tenant_id = ? LIMIT 1'
        );
        $userStmt->execute([$this->userId, $tenantId]);
        $isOwner = $userStmt->fetchColumn();
        return $isOwner !== false && (
            (int) $isOwner === 1
            || (bool) array_intersect(
                $this->getSaleRoleNames($tenantId),
                ['admin', 'manager', 'branch_manager', 'super_admin']
            )
        );
    }

    private function getSaleRoleNames(int $tenantId): array
    {
        $assignment = $this->pdo->prepare('SELECT 1 FROM users_role WHERE user_id = ? LIMIT 1');
        $assignment->execute([$this->userId]);
        if ($assignment->fetchColumn()) {
            $stmt = $this->pdo->prepare(
                'SELECT DISTINCT LOWER(r.name)
                 FROM users_role ur
                 JOIN roles r ON r.id = ur.role_id
                 WHERE ur.user_id = ?
                   AND (ur.tenant_id IS NULL OR ur.tenant_id = ?)
                   AND (r.tenant_id IS NULL OR r.tenant_id = ?)'
            );
            $stmt->execute([$this->userId, $tenantId, $tenantId]);
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT LOWER(r.name)
                 FROM users u
                 JOIN roles r ON r.id = u.role_id
                 WHERE u.id = ?
                   AND (r.tenant_id IS NULL OR r.tenant_id = ?)'
            );
            $stmt->execute([$this->userId, $tenantId]);
        }
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    // deleteSale — إلغاء فاتورة (canceled) مع عكس المخزون والقيد

    public function deleteSale(int $tenantId, int $saleId, ?string $note = null): array
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                "SELECT status, branch_id, paid_amount FROM sales WHERE id = ? AND tenant_id = ? FOR UPDATE"
            );
            $stmt->execute([$saleId, $tenantId]);
            $sale = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$sale) {
                throw new Exception('Sale not found');
            }

            $previousStatus = $sale['status'];
            $branchId       = (int) ($sale['branch_id'] ?? 1);

            if (in_array($previousStatus, ['paid', 'canceled', 'cancelled'], true)) {
                throw new \DomainException('لا يمكن إلغاء فاتورة مدفوعة بالكامل أو مُلغاة بالفعل.');
            }
            if ((float) ($sale['paid_amount'] ?? 0) > 0) {
                throw new \DomainException('لا يمكن إلغاء فاتورة عليها مبلغ مدفوع؛ سجّل مرتجعًا أو عالج الدفعة أولًا.');
            }

            $paymentCheck = $this->pdo->prepare(
                "SELECT COUNT(*) FROM payments
                 WHERE tenant_id = ? AND sale_id = ? AND status = 'completed'
                                     AND COALESCE(type, 'sale') <> 'return_payment' AND amount > 0"
            );
            $paymentCheck->execute([$tenantId, $saleId]);
            if ((int) $paymentCheck->fetchColumn() > 0) {
                throw new \DomainException('لا يمكن إلغاء فاتورة لها تحصيل مسجل؛ عالج التحصيل أولًا.');
            }

            $returnCheck = $this->pdo->prepare(
                "SELECT (
                    (SELECT COUNT(*) FROM returns WHERE tenant_id = ? AND sale_id = ? AND return_type = 'sale')
                    + (SELECT COUNT(*) FROM return_credit_allocations WHERE tenant_id = ? AND sale_id = ?)
                 )"
            );
            $returnCheck->execute([$tenantId, $saleId, $tenantId, $saleId]);
            if ((int) $returnCheck->fetchColumn() > 0) {
                throw new \DomainException('لا يمكن إلغاء فاتورة مرتبطة بمرتجع أو بتسوية رصيد.');
            }

            // عكس المخزون فقط إذا كانت الفاتورة معتمدة؛ pending_approval لا يطبق المخزون.
            $stockWasApplied = !in_array(
                strtolower(trim($previousStatus)),
                ['pending_approval', 'rejected', 'draft'],
                true
            );

            if ($stockWasApplied) {
                $stmt = $this->pdo->prepare(
                            "SELECT si.product_id, si.quantity, si.conversion_factor, si.purchase_price,
                                si.unit_id, si.batch_number, si.expiry_date, si.serial,
                            COALESCE(p.product_type, 'stock') AS product_type,
                            COALESCE(p.has_serial_number, 0) AS has_serial_number
                         FROM sales_items si
                         INNER JOIN products p ON p.id = si.product_id AND p.tenant_id = si.tenant_id
                         WHERE si.sale_id = ? AND si.tenant_id = ?"
                );
                $stmt->execute([$saleId, $tenantId]);
                $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($items as $item) {
                    if (strtolower((string) $item['product_type']) !== 'stock') {
                        continue;
                    }
                    $qty = (float) $item['quantity'] * max((float) ($item['conversion_factor'] ?? 1), 0.0001);
                    $unitCost = (float) ($item['purchase_price'] ?? 0);
                    $totalCost = $qty * $unitCost;

                    $this->pdo->prepare(
                        "UPDATE branch_products
                         SET quantity = quantity + ?,
                             quantity_cost = quantity_cost + ?
                         WHERE product_id = ? AND branch_id = ? AND tenant_id = ?"
                    )->execute([$qty, $totalCost, $item['product_id'], $branchId, $tenantId]);
                    BranchInventoryReconciliationService::markUnreconciled(
                        $this->pdo,
                        $tenantId,
                        $branchId,
                        (int) $item['product_id']
                    );

                    $this->pdo->prepare(
                        "INSERT INTO inventory_transactions (
                             tenant_id, product_id, unit_id,
                             branch_from, branch_to,
                             quantity, unit_cost, total_cost, movement_type, reference_type, reference_id,
                             user_id, movement_date, batch_number, expiry_date, notes
                         ) VALUES (?, ?, ?, NULL, ?, ?, ?, ?, 'sale_cancel', 'sale', ?, ?, NOW(), ?, ?, ?)"
                    )->execute([
                        $tenantId,
                        $item['product_id'],
                        $item['unit_id'] ?? 1,
                        $branchId,
                        $qty,
                        $unitCost,
                        $totalCost,
                        $saleId,
                        $this->userId,
                        $item['batch_number'] ?? null,
                        $item['expiry_date']  ?? null,
                        InventoryMovementNotes::saleCancellation((int) $saleId),
                    ]);

                    if (!empty($item['has_serial_number']) && !empty($item['serial'])) {
                        $serialUpdate = $this->pdo->prepare(
                            "UPDATE product_serials
                             SET status = 'in_stock', transaction_id = NULL
                             WHERE product_id = ? AND branch_id = ? AND serial_number = ?
                               AND tenant_id = ? AND status = 'sold'"
                        );
                        $serialUpdate->execute([
                            $item['product_id'],
                            $branchId,
                            $item['serial'],
                            $tenantId,
                        ]);
                        if ($serialUpdate->rowCount() !== 1) {
                            throw new \DomainException(
                                'تعذر إعادة الرقم المسلسل إلى المخزون؛ لم يتم إلغاء الفاتورة.'
                            );
                        }
                    }
                }
            }

            // Reverse the sale posting and any legacy standalone COGS posting for this sale.
            $stmt = $this->pdo->prepare(
                "SELECT id FROM journal_entries
                 WHERE tenant_id = ?
                   AND (
                       id = (SELECT journal_entry_id FROM sales WHERE id = ? AND tenant_id = ?)
                       OR (reference_type IN ('sale', 'cogs') AND reference_id = ?)
                   )"
            );
            $stmt->execute([$tenantId, $saleId, $tenantId, $saleId]);
            $journalEntryIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            foreach ($journalEntryIds as $journalEntryId) {
                $this->accounting->reverseJournalEntry(
                    (int) $journalEntryId,
                    (int) $tenantId,
                    $this->userId ? (int) $this->userId : null
                );
            }

            $this->pdo->prepare(
                'DELETE FROM payments WHERE tenant_id = ? AND sale_id = ? AND is_draft = 1'
            )->execute([$tenantId, $saleId]);

            $this->pdo->prepare(
                "UPDATE sales
                 SET status = 'canceled',
                     approval_note = COALESCE(?, approval_note),
                     updated_at = NOW()
                 WHERE id = ? AND tenant_id = ?"
            )->execute([$note, $saleId, $tenantId]);

            $this->logAudit('sale_deleted', 'sales', $saleId, [
                'tenant_id'       => (int) $tenantId,
                'user_id'         => (int) $this->userId,
                'branch_id'       => $branchId,
                'sale_id'         => (int) $saleId,
                'previous_status' => $previousStatus,
                'stock_reversed'  => $stockWasApplied,
                'note'            => $note,
            ], (int) $tenantId);

            $this->pdo->commit();
            return ['sale_id' => $saleId, 'status' => 'canceled'];
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
