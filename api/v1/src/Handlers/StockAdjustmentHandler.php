<?php

declare(strict_types=1);

namespace App\Handlers;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Services\CostingService;
use App\Services\BranchInventoryReconciliationService;
use App\Services\MonologHandler;

/**
 * Handles single and bulk branch-stock adjustments, including CSV imports.
 *
 * Each adjustment records an inventory transaction, updates branch quantity
 * and valuation, and marks the stock unreconciled. Non-zero valuations require
 * a journal entry; zero-valued movements may proceed without one. CostingService
 * supplies fallback unit costs, and BranchInventoryReconciliationService tracks
 * reconciliation state. Routes and permissions are defined in
 * app/routes/branches.php.
 */
class StockAdjustmentHandler extends BaseHandler
{
    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->logger = MonologHandler::getInstance('branch');
    }

    private function resolveAccountId(int $tenantId, string $settingKeyName, string $fallbackCode): ?int
    {
        return $this->accounting->resolveAccountId($tenantId, $settingKeyName, $fallbackCode);
    }

    /** Use a positive supplied cost or fall back to weighted-average and purchase costs. */
    private function resolveAdjustmentUnitCost(int $tenantId, int $productId, float $providedUnitCost): float
    {
        if ($providedUnitCost > 0) {
            return $providedUnitCost;
        }

        try {
            $unitCost = (float) (new CostingService($this->db))
                ->getWeightedAverageCost($tenantId, $productId, date('Y-m-d H:i:s'));
        } catch (\Throwable $e) {
            $unitCost = 0.0;
        }

        if ($unitCost <= 0) {
            $stmt = $this->db->prepare(
                "SELECT COALESCE(purchase_price, 0) FROM products WHERE id = ? AND tenant_id = ?"
            );
            $stmt->execute([$productId, $tenantId]);
            $unitCost = (float) ($stmt->fetchColumn() ?: 0);
        }

        return max(0.0, $unitCost);
    }

    /** Record the adjustment direction, quantity, and valuation in inventory_transactions. */
    private function insertInventoryAdjustment(
        int     $tenantId,
        int     $productId,
        int     $unitId,
        ?int    $branchFrom,
        ?int    $branchTo,
        float   $quantity,
        float   $unitCost,
        string  $movement,
        ?string $batchNumber,
        ?string $expiryDate,
        ?string $serial,
        ?string $notes,
        ?int    $userId
    ): int {
        $totalCost = abs($quantity) * $unitCost;
        $this->db->prepare(
            "INSERT INTO inventory_transactions
             (tenant_id, product_id, unit_id, branch_from, branch_to, quantity, unit_cost, total_cost,
              movement_type, movement_date, batch_number, expiry_date, serial,
              reference_type, reference_id, notes, user_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, 'adjustment', NULL, ?, ?, NOW())"
        )->execute([
            $tenantId, $productId, $unitId, $branchFrom, $branchTo,
            abs($quantity), $unitCost, $totalCost, $movement,
            $batchNumber, $expiryDate, $serial, $notes, $userId,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Post the accounting entry for a valued adjustment.
     *
     * Returns null for a zero amount; missing accounts and posting failures throw.
     */
    private function createAdjustmentJournalEntry(
        int     $tenantId,
        int     $branchId,
        int     $productId,
        float   $delta,
        ?string $notes,
        ?int    $userId,
        string  $movement,
        float   $unitCost
    ): ?int {
        $stmt = $this->db->prepare(
            "SELECT name FROM products WHERE id = ? AND tenant_id = ?"
        );
        $stmt->execute([$productId, $tenantId]);
        $prod = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['name' => 'منتج'];

        $amount = abs($delta) * $unitCost;
        if ($amount <= 0) {
            // A zero-valued movement has no meaningful debit/credit entry.
            $this->logger->warning('[StockAdjustment] Zero amount — journal entry skipped.', [
                'tenant_id' => $tenantId, 'branch_id' => $branchId,
                'product_id' => $productId, 'delta' => $delta,
            ]);
            return null;
        }

        $inventoryAccountId = $this->accounting->resolveInventoryAccountForBranch($tenantId, $branchId);
        $adjustmentAccountId = $this->resolveAccountId($tenantId, 'inventory_adjustment_account_id', '5104');

        if (!$inventoryAccountId || !$adjustmentAccountId) {
            $this->logger->error('[StockAdjustment] Missing accounts — blocking adjustment (was silently skipped before).', [
                'tenant_id' => $tenantId, 'branch_id' => $branchId, 'product_id' => $productId, 'amount' => $amount,
            ]);
            throw new \RuntimeException(
                'تعذّر ترحيل قيد محاسبي لهذه التسوية (حسابات المخزون/فروق الجرد غير مُعرَّفة لهذا الفرع/المستأجر)، ' .
                'ولن يتم تنفيذ تعديل الكمية لتجنّب انحراف صامت بين المخزون الفعلي وحسابات دفتر الأستاذ. ' .
                'الرجاء إعداد الحسابات المحاسبية المطلوبة أولاً.'
            );
        }

        $desc  = 'تسوية مخزون لمخزن #' . $branchId . ' - منتج #' . $productId
               . ' (' . ($prod['name'] ?? '') . ') كمية ' . $delta;

        $lines = $delta > 0 ? [
            ['account_id' => $inventoryAccountId,  'debit' => $amount, 'credit' => 0,      'description' => 'زيادة مخزون بالتسوية'],
            ['account_id' => $adjustmentAccountId, 'debit' => 0,       'credit' => $amount, 'description' => 'قيد مقابل زيادة مخزون'],
        ] : [
            ['account_id' => $adjustmentAccountId, 'debit' => $amount, 'credit' => 0,       'description' => 'نقص مخزون بالتسوية'],
            ['account_id' => $inventoryAccountId,  'debit' => 0,       'credit' => $amount, 'description' => 'قيد مقابل نقص مخزون'],
        ];

        // Posting errors must propagate so the caller can roll back the stock change.
        $jeId = $this->accounting->postJournalEntry(
            $tenantId,
            'inventory_adjustment',
            null,
            $desc,
            $lines,
            date('Y-m-d'),
            $userId,
            null,
            'branch:' . $branchId . ':product:' . $productId . ':' . date('Y-m-d') . ':' . microtime(true)
        );

        if (!$jeId) {
            throw new \RuntimeException('فشل ترحيل القيد المحاسبي لتسوية المخزون.');
        }
        return $jeId;
    }

    /** Apply one stock delta with its inventory transaction and accounting entry. */
    public function adjustStockQuantity(Request $request, Response $response, array $args): Response
    {
        try {
            $tenantId  = $this->extractTenantId($request);
            $branchId  = (int) ($args['id'] ?? 0);
            $data      = $request->getParsedBody() ?: [];
            $productId = (int) ($data['product_id'] ?? 0);
            $delta     = (float) ($data['quantity']   ?? 0);
            $notes     = $data['notes'] ?? null;
            $userAttr  = $request->getAttribute('user');
            $userId    = $request->getAttribute('user_id') ?? ($userAttr['id'] ?? null);

            if (!$tenantId || !$branchId || !$productId || $delta == 0.0) {
                return $this->errorResponse($response, 'invalid payload', 400);
            }

            $this->db->beginTransaction();

            \App\Services\ProductBranchActivationGuard::assertActiveForStock(
                $this->db,
                (int) $tenantId,
                $branchId,
                $productId
            );

            // Reject a decrement larger than the available stock.
            if ($delta < 0) {
                $chk = $this->db->prepare(
                    "SELECT COALESCE(quantity, 0) FROM branch_products
                     WHERE tenant_id = ? AND branch_id = ? AND product_id = ?"
                );
                $chk->execute([$tenantId, $branchId, $productId]);
                $currentQty = (float) ($chk->fetchColumn() ?? 0);
                if ($currentQty < abs($delta)) {
                    $this->db->rollBack();
                    return $this->errorResponse(
                        $response,
                        'الكمية المتاحة (' . $currentQty . ') غير كافية للخصم المطلوب (' . abs($delta) . ')',
                        422
                    );
                }
            }

            $movement    = $delta > 0 ? 'adjustment_in' : 'adjustment_out';
            $unitId      = (int)   ($data['unit_id']   ?? 1);
            $unitCost    = $this->resolveAdjustmentUnitCost(
                (int) $tenantId,
                $productId,
                (float) ($data['unit_cost'] ?? 0)
            );
            $batchNumber = $data['batch_number'] ?? null;
            $expiryDate  = $data['expiry_date']  ?? null;
            $serial      = $data['serial']       ?? null;

            $txId = $this->insertInventoryAdjustment(
                $tenantId,
                $productId,
                $unitId,
                $delta < 0 ? $branchId : null,
                $delta > 0 ? $branchId : null,
                $delta,
                $unitCost,
                $movement,
                $batchNumber,
                $expiryDate,
                $serial,
                $notes,
                $userId
            );

            $costAdjustment = $delta * $unitCost;
            $this->db->prepare(
                "INSERT INTO branch_products (tenant_id, branch_id, product_id, quantity, quantity_cost, last_update, gl_reconciled)
                 VALUES (?, ?, ?, ?, ?, NOW(), 0)
                 ON DUPLICATE KEY UPDATE
                     quantity      = quantity + VALUES(quantity),
                     quantity_cost = GREATEST(0, quantity_cost + VALUES(quantity_cost)),
                     last_update   = NOW()"
            )->execute([$tenantId, $branchId, $productId, $delta, $costAdjustment]);
            BranchInventoryReconciliationService::markUnreconciled($this->db, (int) $tenantId, $branchId, $productId);

            $jeId     = $this->createAdjustmentJournalEntry($tenantId, $branchId, $productId, $delta, $notes, $userId, $movement, $unitCost);
            $warnings = null;
            if ($jeId) {
                $this->db->prepare(
                    "UPDATE inventory_transactions SET journal_entry_id = ? WHERE id = ? AND tenant_id = ?"
                )->execute([$jeId, $txId, $tenantId]);
            } else {
                $warnings = ['journal_entry_skipped' => true, 'reason' => 'missing_accounts_or_zero_amount'];
            }

            $this->db->commit();
            return $this->jsonResponse($response, [
                'status'   => 'success',
                'message'  => 'تمت التسوية بنجاح',
                'warnings' => $warnings,
            ]);
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logger->error('فشل في عملية التسوية: ' . $e->getMessage());
            return $this->errorResponse(
                $response,
                $e instanceof \DomainException ? $e->getMessage() : 'فشل في عملية التسوية',
                $e instanceof \DomainException ? 422 : 500
            );
        }
    }

    /** Apply a set of product adjustments across branches in one transaction. */
    public function bulkAdjustments(Request $request, Response $response, array $args): Response
    {
        try {
            $tenantId  = $this->extractTenantId($request);
            $data      = $request->getParsedBody() ?: [];
            $productId = (int) ($data['product_id'] ?? 0);
            $items     = $data['items'] ?? [];
            $userAttr  = $request->getAttribute('user');
            $userId    = $request->getAttribute('user_id') ?? ($userAttr['id'] ?? null);

            if (!$tenantId || !$productId || !is_array($items) || count($items) === 0) {
                return $this->errorResponse($response, 'invalid payload', 400);
            }

            $this->db->beginTransaction();
            foreach ($items as $item) {
                $branchId = (int) ($item['branch_id'] ?? 0);
                if ($branchId > 0) {
                    \App\Services\ProductBranchActivationGuard::assertActiveForStock(
                        $this->db,
                        (int) $tenantId,
                        $branchId,
                        $productId
                    );
                }
            }
            $upsertWp = $this->db->prepare(
                "INSERT INTO branch_products (tenant_id, branch_id, product_id, quantity, quantity_cost, last_update, gl_reconciled)
                 VALUES (?, ?, ?, ?, ?, NOW(), 0)
                 ON DUPLICATE KEY UPDATE
                     quantity      = quantity + VALUES(quantity),
                     quantity_cost = GREATEST(0, quantity_cost + VALUES(quantity_cost)),
                     last_update   = NOW()"
            );
            $warnings = ['journal_entry_skipped' => false, 'skipped_count' => 0];

            foreach ($items as $it) {
                $branchId    = (int)   ($it['branch_id']    ?? 0);
                $delta       = (float) ($it['quantity']      ?? 0);
                $notes       = $it['notes']        ?? null;
                $unitId      = (int)   ($it['unit_id']       ?? 1);
                $unitCost    = (float) ($it['unit_cost']     ?? 0);
                $batchNumber = $it['batch_number'] ?? null;
                $expiryDate  = $it['expiry_date']  ?? null;
                $serial      = $it['serial']       ?? null;

                if ($branchId <= 0 || $delta == 0.0) {
                    continue;
                }
                $unitCost = $this->resolveAdjustmentUnitCost((int) $tenantId, $productId, $unitCost);

                // Reject a decrement larger than the available stock.
                if ($delta < 0) {
                    $stmt = $this->db->prepare(
                        "SELECT quantity FROM branch_products WHERE tenant_id = ? AND branch_id = ? AND product_id = ? LIMIT 1"
                    );
                    $stmt->execute([$tenantId, $branchId, $productId]);
                    $currentQty = (float) ($stmt->fetchColumn() ?? 0);

                    if ($currentQty + $delta < 0) {
                        throw new \Exception(sprintf(
                            'الكمية الحالية للمنتج في الفرع (%s) أقل من الكمية المراد سحبها (%s)',
                            $currentQty,
                            abs($delta)
                        ));
                    }
                }

                $movement = $delta > 0 ? 'adjustment_in' : 'adjustment_out';
                $txId     = $this->insertInventoryAdjustment(
                    $tenantId,
                    $productId,
                    $unitId,
                    $delta < 0 ? $branchId : null,
                    $delta > 0 ? $branchId : null,
                    $delta,
                    $unitCost,
                    $movement,
                    $batchNumber,
                    $expiryDate,
                    $serial,
                    $notes,
                    $userId
                );
                $upsertWp->execute([$tenantId, $branchId, $productId, $delta, $delta * $unitCost]);
                BranchInventoryReconciliationService::markUnreconciled($this->db, (int) $tenantId, $branchId, $productId);

                $jeId = $this->createAdjustmentJournalEntry($tenantId, $branchId, $productId, $delta, $notes, $userId, $movement, $unitCost);
                if ($jeId) {
                    $this->db->prepare(
                        "UPDATE inventory_transactions SET journal_entry_id = ? WHERE id = ? AND tenant_id = ?"
                    )->execute([$jeId, $txId, $tenantId]);
                } else {
                    $warnings['journal_entry_skipped'] = true;
                    $warnings['skipped_count']++;
                }
            }

            $this->db->commit();
            return $this->jsonResponse($response, [
                'status'   => 'success',
                'message'  => 'تم تنفيذ التسويات الجماعية',
                'warnings' => $warnings['journal_entry_skipped'] ? $warnings : null,
            ]);
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logger->error('فشل في تنفيذ التسويات الجماعية: ' . $e->getMessage());
            return $this->errorResponse(
                $response,
                $e instanceof \DomainException ? $e->getMessage() : 'فشل في تنفيذ التسويات الجماعية',
                $e instanceof \DomainException ? 422 : 500
            );
        }
    }

    /**
     * Import stock adjustments from CSV.
     *
     * Rows support product/branch IDs or codes, quantity, notes, unit, cost,
     * batch, expiry, and serial fields; a request-level product_id is optional.
     */
    public function bulkAdjustmentsCsv(Request $request, Response $response, array $args): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }
            $userAttr = $request->getAttribute('user');
            $userId   = $request->getAttribute('user_id') ?? ($userAttr['id'] ?? null);

            $uploadedFiles = $request->getUploadedFiles();
            if (empty($uploadedFiles['file'])) {
                return $this->errorResponse($response, 'CSV file is required (field name: file)', 400);
            }
            $file = $uploadedFiles['file'];
            if ($file->getError() !== UPLOAD_ERR_OK) {
                return $this->errorResponse($response, 'File upload error', 400);
            }

            $stream = $file->getStream()->detach();
            $handle = is_resource($stream) ? $stream : fopen('php://temp', 'r+');
            if (!is_resource($stream)) {
                fwrite($handle, (string) $file->getStream());
                rewind($handle);
            }

            $defaultProductId = isset($request->getParsedBody()['product_id'])
                ? (int) $request->getParsedBody()['product_id']
                : null;

            $this->db->beginTransaction();
            $upsertWp         = $this->db->prepare(
                "INSERT INTO branch_products (tenant_id, branch_id, product_id, quantity, quantity_cost, last_update, gl_reconciled)
                 VALUES (?, ?, ?, ?, ?, NOW(), 0)
                 ON DUPLICATE KEY UPDATE
                     quantity      = quantity + VALUES(quantity),
                     quantity_cost = GREATEST(0, quantity_cost + VALUES(quantity_cost)),
                     last_update   = NOW()"
            );
            $findProductByCode = $this->db->prepare(
                "SELECT id FROM products WHERE tenant_id = ? AND (product_code = ? OR code = ?) LIMIT 1"
            );
            $findBranchByCode  = $this->db->prepare(
                "SELECT id FROM branches WHERE tenant_id = ? AND (code = ? OR name = ?) LIMIT 1"
            );

            $header   = null;
            $rowNum   = 0;
            $imported = 0;
            $skipped  = 0;
            $warnings = ['journal_entry_skipped' => false, 'skipped_count' => 0];

            while (($row = fgetcsv($handle)) !== false) {
                $rowNum++;
                if ($rowNum === 1) {
                    $header = array_map('strtolower', $row);
                    continue;
                }
                $line = array_combine($header, $row);
                if (!$line) {
                    $skipped++;
                    continue;
                }

                $pId = $defaultProductId ?: (int) ($line['product_id'] ?? 0);
                if (!$pId && !empty($line['product_code'])) {
                    $findProductByCode->execute([$tenantId, $line['product_code'], $line['product_code']]);
                    $pId = (int) $findProductByCode->fetchColumn();
                }
                $branchId = (int) ($line['branch_id'] ?? 0);
                if (!$branchId && !empty($line['branch_code'])) {
                    $findBranchByCode->execute([$tenantId, $line['branch_code'], $line['branch_code']]);
                    $branchId = (int) $findBranchByCode->fetchColumn();
                }
                $delta       = isset($line['quantity']) ? (float) $line['quantity'] : 0.0;
                $notes       = $line['notes']               ?? null;
                $unitId      = isset($line['unit_id']) ? (int)   $line['unit_id'] : 1;
                $unitCost    = isset($line['unit_cost']) ? (float) $line['unit_cost'] : 0.0;
                $batchNumber = $line['batch_number']        ?? null;
                $expiryDate  = $line['expiry_date']         ?? null;
                $serial      = $line['serial']              ?? null;

                if (!$pId || !$branchId || $delta == 0.0) {
                    $skipped++;
                    continue;
                }

                \App\Services\ProductBranchActivationGuard::assertActiveForStock(
                    $this->db,
                    (int) $tenantId,
                    $branchId,
                    $pId
                );

                // Reject a decrement larger than the available stock.
                if ($delta < 0) {
                    $stmt = $this->db->prepare(
                        "SELECT quantity FROM branch_products WHERE tenant_id = ? AND branch_id = ? AND product_id = ? LIMIT 1"
                    );
                    $stmt->execute([$tenantId, $branchId, $pId]);
                    $currentQty = (float) ($stmt->fetchColumn() ?? 0);

                    if ($currentQty + $delta < 0) {
                        $this->db->rollBack();
                        fclose($handle);
                        return $this->errorResponse(
                            $response,
                            sprintf('الكمية الحالية للمنتج في الفرع (%s) أقل من الكمية المراد سحبها (%s)', $currentQty, abs($delta)),
                            422
                        );
                    }
                }

                $unitCost = $this->resolveAdjustmentUnitCost((int) $tenantId, $pId, $unitCost);

                \App\Services\ProductBranchActivationGuard::assertActiveForStock(
                    $this->db,
                    (int) $tenantId,
                    $branchId,
                    $pId
                );

                $movement = $delta > 0 ? 'adjustment_in' : 'adjustment_out';
                $txId     = $this->insertInventoryAdjustment(
                    $tenantId,
                    $pId,
                    $unitId,
                    $delta < 0 ? $branchId : null,
                    $delta > 0 ? $branchId : null,
                    $delta,
                    $unitCost,
                    $movement,
                    $batchNumber,
                    $expiryDate,
                    $serial,
                    $notes,
                    $userId
                );
                $upsertWp->execute([$tenantId, $branchId, $pId, $delta, $delta * $unitCost]);
                BranchInventoryReconciliationService::markUnreconciled($this->db, (int) $tenantId, $branchId, $pId);

                $jeId = $this->createAdjustmentJournalEntry($tenantId, $branchId, $pId, $delta, $notes, $userId, $movement, $unitCost);
                if ($jeId) {
                    $this->db->prepare(
                        "UPDATE inventory_transactions SET journal_entry_id = ? WHERE id = ? AND tenant_id = ?"
                    )->execute([$jeId, $txId, $tenantId]);
                } else {
                    $warnings['journal_entry_skipped'] = true;
                    $warnings['skipped_count']++;
                }
                $imported++;
            }

            $this->db->commit();
            return $this->jsonResponse($response, [
                'status'   => 'success',
                'message'  => 'تم استيراد CSV للتسويات',
                'summary'  => ['imported' => $imported, 'skipped' => $skipped],
                'warnings' => $warnings['journal_entry_skipped'] ? $warnings : null,
            ]);
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logger->error('فشل استيراد CSV للتسويات: ' . $e->getMessage());
            return $this->errorResponse(
                $response,
                $e instanceof \DomainException ? $e->getMessage() : 'فشل في استيراد ملف CSV',
                $e instanceof \DomainException ? 422 : 500
            );
        }
    }
}
