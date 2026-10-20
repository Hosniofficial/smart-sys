<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Posts branch opening stock to inventory, costing, and the general ledger.
 *
 * Joins an active caller transaction or manages its own; updates an existing GL mapping but never creates one.
 */
class InventoryOpeningBalanceService
{
    private PDO $db;
    private AccountingService $accounting;

    public function __construct(PDO $db)
    {
        $this->db         = $db;
        $this->accounting = new AccountingService($db);
    }

    /**
     * Post one product-branch opening balance and return its journal entry ID.
     *
     * @throws \RuntimeException when an active duplicate exists or posting fails.
     */
    public function post(
        int    $tenantId,
        int    $productId,
        int    $branchId,
        int    $unitId,
        float  $quantity,
        float  $unitCost,
        string $entryDate,
        ?int   $userId,
        string $productName,
        string $branchName,
        ?int   $inventoryGLAccountId = null,
        ?int   $costCenterId         = null,
        string $movementType         = 'opening_balance'
    ): int {
        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $jeId = $this->doPost(
                $tenantId,
                $productId,
                $branchId,
                $unitId,
                $quantity,
                $unitCost,
                $entryDate,
                $userId,
                $productName,
                $branchName,
                $inventoryGLAccountId,
                $costCenterId,
                $movementType
            );
            if ($ownTransaction) {
                $this->db->commit();
            }
            return $jeId;
        } catch (\Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    private function doPost(
        int    $tenantId,
        int    $productId,
        int    $branchId,
        int    $unitId,
        float  $quantity,
        float  $unitCost,
        string $entryDate,
        ?int   $userId,
        string $productName,
        string $branchName,
        ?int   $inventoryGLAccountId,
        ?int   $costCenterId,
        string $movementType = 'opening_balance'
    ): int {
        // Serialize opening-balance attempts for this product before checking for a prior posting.
        $productLock = $this->db->prepare(
            'SELECT id, product_type FROM products WHERE tenant_id = ? AND id = ? LIMIT 1 FOR UPDATE'
        );
        $productLock->execute([$tenantId, $productId]);
        $lockedProduct = $productLock->fetch(PDO::FETCH_ASSOC);
        if (!$lockedProduct) {
            throw new \RuntimeException("المنتج #{$productId} غير موجود في المستأجر #{$tenantId}.");
        }
        if (strtolower((string) ($lockedProduct['product_type'] ?? 'stock')) !== 'stock') {
            throw new \InvalidArgumentException("المنتج #{$productId} من نوع خدمة ولا يقبل رصيدًا مخزنيًا.");
        }

        // The opening-balance ledger is the authoritative duplicate guard; it works without a GL mapping.
        $openingBalanceCheck = $this->db->prepare(
            'SELECT 1 FROM opening_balances
             WHERE tenant_id = ? AND product_id = ? AND branch_id = ?
               AND reversed_at IS NULL
             LIMIT 1 FOR UPDATE'
        );
        $openingBalanceCheck->execute([$tenantId, $productId, $branchId]);
        if ($openingBalanceCheck->fetchColumn()) {
            throw new \RuntimeException(
                "يوجد رصيد افتتاحي مسجل مسبقاً للمنتج #{$productId} في الفرع #{$branchId}. "
                . 'راجع القيد وحركة المخزون قبل إعادة الترحيل.'
            );
        }


        if (!$inventoryGLAccountId) {
            $inventoryGLAccountId = $this->accounting->resolveInventoryAccountForBranch($tenantId, $branchId);
        }
        $equityAccountId = $this->getAccountByCode($tenantId, '2001');

        if (!$inventoryGLAccountId || !$equityAccountId) {
            throw new \RuntimeException(
                'حساب GL المخزون المناسب للفرع أو حساب الأرصدة الافتتاحية (2001) غير مُهيأ.'
            );
        }

        $totalCost = round($quantity * $unitCost, 2);

        // A mapping is optional metadata. Reuse it if explicitly configured; never create or activate it here.
        $mappingStmt = $this->db->prepare(
            'SELECT id FROM product_branch_gl_mapping
             WHERE tenant_id = ? AND product_id = ? AND branch_id = ? LIMIT 1 FOR UPDATE'
        );
        $mappingStmt->execute([$tenantId, $productId, $branchId]);
        $mappingValue = $mappingStmt->fetchColumn();
        $mappingId = ($mappingValue === false || $mappingValue === null) ? null : (int) $mappingValue;

        $movementNote = match ($movementType) {
            'initial_stock'          => "رصيد افتتاحي عند إنشاء المنتج: {$productName}",
            'opening_balance_manual' => "رصيد افتتاحي يدوي: {$productName}",
            'opening_balance_bulk'   => "رصيد افتتاحي دفعي: {$productName}",
            default                  => "رصيد افتتاحي: {$productName}",
        };

        $this->db->prepare("
            INSERT INTO inventory_transactions
                (tenant_id, product_id, unit_id, branch_from, branch_to,
                 quantity, unit_cost, total_cost,
                 movement_type, movement_date, reference_type, reference_id,
                 notes, user_id, created_at)
            VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, NOW())
        ")->execute([
            $tenantId, $productId, $unitId, $branchId,
            $quantity, $unitCost, $totalCost,
            $movementType,
            $entryDate,
            $movementType,
            $mappingId,
            $movementNote,
            $userId ?: 1,
        ]);
        $inventoryTransactionId = (int) $this->db->lastInsertId();

        // Opening balance replaces the branch's initial stock values.
        $this->db->prepare("
            INSERT INTO branch_products
                (tenant_id, product_id, branch_id, quantity, quantity_cost,
                 gl_reconciled, last_gl_posting_date, last_update)
            VALUES (?, ?, ?, ?, ?, 0, NULL, NOW())
            ON DUPLICATE KEY UPDATE
                quantity             = VALUES(quantity),
                quantity_cost        = VALUES(quantity_cost),
                gl_reconciled        = 0,
                last_gl_posting_date = NULL,
                last_update          = NOW()
        ")->execute([$tenantId, $productId, $branchId, $quantity, $totalCost]);

        $this->db->prepare("
            INSERT INTO opening_balances
                (tenant_id, product_id, branch_id, quantity, unit_cost, entry_date)
            VALUES (?, ?, ?, ?, ?, ?)
        ")->execute([$tenantId, $productId, $branchId, $quantity, $unitCost, $entryDate]);
        $openingBalanceId = (int) $this->db->lastInsertId();

        // Debit inventory and credit opening-balance equity.
        $cycleStmt = $this->db->prepare(
            'SELECT COUNT(*) FROM opening_balances
             WHERE tenant_id = ? AND product_id = ? AND branch_id = ?'
        );
        $cycleStmt->execute([$tenantId, $productId, $branchId]);
        $reversalCycle = max(0, (int) $cycleStmt->fetchColumn() - 1);
        $idempotencyKey = "{$movementType}:t{$tenantId}:p{$productId}:b{$branchId}:d{$entryDate}";
        if ($reversalCycle > 0) {
            $idempotencyKey .= ":r{$reversalCycle}";
        }
        $description = match ($movementType) {
            'initial_stock'          => "إدخال مخزون أولي: {$productName} - {$branchName}",
            'opening_balance_manual' => "رصيد افتتاحي يدوي: {$productName} - {$branchName}",
            'opening_balance_bulk'   => "رصيد افتتاحي دفعي: {$productName} - {$branchName}",
            default                  => "رصيد افتتاحي: {$productName} - {$branchName}",
        };

        $jeId = $this->accounting->postJournalEntry(
            $tenantId,
            $movementType,
            $mappingId,
            $description,
            [
                [
                    'account_id'     => $inventoryGLAccountId,
                    'debit'          => $totalCost,
                    'credit'         => 0,
                    'description'    => "مخزون - {$productName}",
                    'cost_center_id' => $costCenterId,
                ],
                [
                    'account_id'     => $equityAccountId,
                    'debit'          => 0,
                    'credit'         => $totalCost,
                    'description'    => 'مقابل رصيد افتتاحي',
                    'cost_center_id' => $costCenterId,
                ],
            ],
            $entryDate,
            $userId ?: 1,
            $costCenterId,
            $idempotencyKey
        );

        if (!$jeId) {
            throw new \RuntimeException('فشل إنشاء القيد المحاسبي للرصيد الافتتاحي.');
        }

        // The stock row is reconciled only after its opening-balance journal posts successfully.
        // This update shares the caller's transaction (or this service's own transaction).
        $this->db->prepare(
            'UPDATE branch_products
             SET gl_reconciled = 1, last_gl_posting_date = NOW()
             WHERE tenant_id = ? AND product_id = ? AND branch_id = ?'
        )->execute([$tenantId, $productId, $branchId]);

        // Add the opening quantity as a costing layer.
        $this->db->prepare("
            INSERT IGNORE INTO inventory_cost_snapshot
                (tenant_id, product_id, branch_id, product_branch_mapping_id,
                 layer_date, layer_sequence, unit_cost,
                 quantity_received, quantity_remaining, source_type)
            VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, 'OPENING_BALANCE')
        ")->execute([
            $tenantId, $productId, $branchId, $mappingId,
            $entryDate, $unitCost, $quantity, $quantity,
        ]);

        // Reconcile mapping metadata only when a mapping was explicitly configured beforehand.
        if ($mappingId !== null) {
            $this->db->prepare("
                UPDATE product_branch_gl_mapping
                SET gl_reconciliation_status = 'RECONCILED',
                    last_gl_posting_date     = NOW(),
                    average_cost             = ?,
                    gl_balance               = gl_balance + ?,
                    updated_by_user_id       = ?,
                    updated_at               = NOW()
                WHERE id = ? AND tenant_id = ? AND product_id = ? AND branch_id = ?
            ")->execute([$unitCost, $totalCost, $userId, $mappingId, $tenantId, $productId, $branchId]);
        }

        $this->db->prepare("
            UPDATE products
            SET opening_balance_posted = 1,
                updated_at             = NOW()
            WHERE tenant_id = ? AND id = ?
        ")->execute([$tenantId, $productId]);

        $this->db->prepare("
            UPDATE inventory_transactions
            SET journal_entry_id = ?
            WHERE id = ? AND tenant_id = ?
        ")->execute([$jeId, $inventoryTransactionId, $tenantId]);
        $this->db->prepare("
            UPDATE opening_balances
            SET inventory_transaction_id = ?
            WHERE id = ? AND tenant_id = ?
        ")->execute([$inventoryTransactionId, $openingBalanceId, $tenantId]);

        return $jeId;
    }

    private function getAccountByCode(int $tenantId, string $code): ?int
    {
        $st = $this->db->prepare(
            "SELECT id FROM accounts WHERE tenant_id = ? AND code = ? LIMIT 1"
        );
        $st->execute([$tenantId, $code]);
        $id = $st->fetchColumn();
        return $id !== false ? (int) $id : null;
    }
}
