<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Unified service for posting product opening balances.
 *
 * Handles all three entry points consistently:
 *   - ProductsHandler  (product creation with initial stock)
 *   - ProductBranchHandler::postOpeningBalance()  (retroactive per-mapping)
 *   - OpeningBalanceHandler::commit()  (bulk setup page)
 *
 * Transaction management is self-contained:
 *   - Starts its own transaction if none is active (safe to nest inside a caller transaction).
 *   - Uses SELECT FOR UPDATE on the mapping row to prevent race conditions.
 * Existing branch mappings are updated after successful posting, but this service never creates one.
 * The snapshot's mapping reference is nullable; the stock and journal records remain valid without it.
 * On failure throws \RuntimeException so the caller can react.
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
     * Post opening balance for one product-branch combination.
     *
     * @param int         $tenantId
     * @param int         $productId
     * @param int         $branchId
     * @param int         $unitId
     * @param float       $quantity
     * @param float       $unitCost
     * @param string      $entryDate        (Y-m-d)
     * @param int|null    $userId
     * @param string      $productName      for journal description
     * @param string      $branchName       for journal description
     * @param int|null    $inventoryGLAccountId  resolved from branch/settings if null
     * @param int|null    $costCenterId
     *
     * @return int  journal_entry_id
     *
     * @throws \RuntimeException  on duplicate or any failure
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
            'SELECT id FROM products WHERE tenant_id = ? AND id = ? LIMIT 1 FOR UPDATE'
        );
        $productLock->execute([$tenantId, $productId]);
        if (!$productLock->fetchColumn()) {
            throw new \RuntimeException("المنتج #{$productId} غير موجود في المستأجر #{$tenantId}.");
        }

        // The opening-balance ledger is the authoritative duplicate guard; it works without a GL mapping.
        $openingBalanceCheck = $this->db->prepare(
            'SELECT 1 FROM opening_balances WHERE tenant_id = ? AND product_id = ? AND branch_id = ? LIMIT 1 FOR UPDATE'
        );
        $openingBalanceCheck->execute([$tenantId, $productId, $branchId]);
        if ($openingBalanceCheck->fetchColumn()) {
            throw new \RuntimeException(
                "يوجد رصيد افتتاحي مسجل مسبقاً للمنتج #{$productId} في الفرع #{$branchId}. "
                . 'راجع القيد وحركة المخزون قبل إعادة الترحيل.'
            );
        }


        // ── 2. Resolve GL accounts ────────────────────────────────────────────
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

        // ── 4. inventory_transactions ─────────────────────────────────────────
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

        // ── 5. branch_products (SET semantics — opening balance sets initial stock) ──
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

        // ── 6. opening_balances record ────────────────────────────────────────
        $this->db->prepare("
            INSERT IGNORE INTO opening_balances
                (tenant_id, product_id, branch_id, quantity, unit_cost, entry_date)
            VALUES (?, ?, ?, ?, ?, ?)
        ")->execute([$tenantId, $productId, $branchId, $quantity, $unitCost, $entryDate]);

        // ── 7. Journal entry: debit Inventory / credit Opening Balance Equity ──
        $idempotencyKey = "{$movementType}:t{$tenantId}:p{$productId}:b{$branchId}:d{$entryDate}";
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

        // ── 8. inventory_cost_snapshot (WAC/FIFO costing layer) ──────────────
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

        // ── 9b. Mark product opening_balance_posted flag ──────────────────────
        $this->db->prepare("
            UPDATE products
            SET opening_balance_posted = 1,
                updated_at             = NOW()
            WHERE tenant_id = ? AND id = ?
        ")->execute([$tenantId, $productId]);

        // ── 10. Update inventory_transactions with journal_entry_id ───────────
        $this->db->prepare("
            UPDATE inventory_transactions
            SET journal_entry_id = ?
            WHERE tenant_id = ? AND product_id = ? AND branch_to = ?
              AND movement_type = ?
              AND journal_entry_id IS NULL
            ORDER BY id DESC LIMIT 1
        ")->execute([$jeId, $tenantId, $productId, $branchId, $movementType]);

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
