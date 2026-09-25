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
 * StockAdjustmentHandler
 *
 * Handles inventory adjustment operations extracted from BranchHandler:
 *   - upsertInventoryItem()   — add/update a single inventory item in a branch
 *   - adjustStockQuantity()   — single product adjustment (delta + journal entry)
 *   - bulkAdjustments()       — JSON bulk adjustments across branches
 *   - bulkAdjustmentsCsv()    — CSV import for bulk adjustments
 */
class StockAdjustmentHandler extends BaseHandler
{
    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->logger = MonologHandler::getInstance('branch');
    }

    // =========================================================================
    // Private Helpers
    // =========================================================================

    private function resolveAccountId(int $tenantId, string $settingKeyName, string $fallbackCode): ?int
    {
        return $this->accounting->resolveAccountId($tenantId, $settingKeyName, $fallbackCode);
    }

    /**
     * إدراج سجل في inventory_transactions — Single Source of Truth للتسويات.
     */
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
     * إنشاء قيد يومية للتسوية (زيادة أو نقص مخزون).
     * يُرجع journal_entry_id أو null إذا تعذّر الإنشاء.
     */
    private function createAdjustmentJournalEntry(
        int     $tenantId,
        int     $branchId,
        int     $productId,
        float   $delta,
        ?string $notes,
        ?int    $userId,
        string  $movement
    ): ?int {
        $stmt = $this->db->prepare(
            "SELECT name, COALESCE(purchase_price, 0) AS cost FROM products WHERE id = ? AND tenant_id = ?"
        );
        $stmt->execute([$productId, $tenantId]);
        $prod = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['name' => 'منتج', 'cost' => 0];

        $unitCost = null;
        try {
            $unitCost = (float) (new CostingService($this->db))
                ->getWeightedAverageCost($tenantId, $productId, date('Y-m-d H:i:s'));
        } catch (\Throwable $e) {
            $unitCost = null;
        }
        if ($unitCost === null || $unitCost <= 0) {
            $unitCost = (float) $prod['cost'];
        }

        $amount = abs($delta) * $unitCost;
        if ($amount <= 0) {
            // لا قيمة مالية حقيقية لهذه التسوية (تكلفة الوحدة غير معروفة/صفرية) —
            // لا حاجة لقيد محاسبي (Dr=Cr=0 لا يضيف أي معلومة)، وهذا ليس الخطأ
            // الذي رصده التدقيق. الاستمرار بتسوية الكمية فقط هنا آمن ومقصود.
            $this->logger->warning('[StockAdjustment] Zero amount — journal entry skipped.', [
                'tenant_id' => $tenantId, 'branch_id' => $branchId,
                'product_id' => $productId, 'delta' => $delta,
            ]);
            return null;
        }

        // ── AUDIT FIX #8 (MEDIUM-HIGH) ──────────────────────────────────────────
        // Previously: if the branch/tenant had no inventory or inventory-
        // adjustment GL account configured, this branch just logged a warning
        // and returned null — and the caller (adjustStockQuantity) proceeded to
        // COMMIT the quantity + quantity_cost change to branch_products anyway,
        // with $warnings['reason']='missing_accounts_or_zero_amount' silently
        // attached to the JSON response. A caller that doesn't inspect
        // `warnings` (most UI flows) never learns that inventory value moved
        // with zero trace in the GL — a direct, silent divergence between the
        // physical stock report and the GL inventory account (1301).
        //
        // Also previously: this whole method was wrapped in one blanket
        // try/catch that swallowed EVERY failure — including postJournalEntry()
        // rejecting the entry for a genuinely bad reason (Dr≠Cr, closed
        // accounting period, DB error) — and still returned null silently. That
        // meant even a real posting failure never blocked the stock mutation.
        //
        // Fix: once we know real money is on the line ($amount > 0), a missing
        // account or a failed postJournalEntry() call now THROWS instead of
        // returning null. adjustStockQuantity() runs inside its own DB
        // transaction and already rolls back on any Throwable, so this turns a
        // silent, value-changing, GL-less stock mutation into a rejected
        // request — the adjustment either gets a full accounting trail or it
        // doesn't happen at all.

        $branchStmt = $this->db->prepare(
            "SELECT account_id FROM branches WHERE id = ? AND tenant_id = ? LIMIT 1"
        );
        $branchStmt->execute([$branchId, $tenantId]);
        $inventoryAccountId  = (int) ($branchStmt->fetchColumn() ?: 0)
            ?: $this->resolveAccountId($tenantId, 'inventory_account_id', '1301');
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

        // لا نلتقط الاستثناءات هنا: أي فشل حقيقي في postJournalEntry (توازن Dr/Cr،
        // دورة مغلقة، خطأ قاعدة بيانات) يجب أن يوقف العملية بالكامل عبر rollback
        // في adjustStockQuantity، وليس أن يُبتلع صامتاً كما كان يحدث سابقاً.
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

    // =========================================================================
    // Public Endpoints
    // =========================================================================

    /**
     * إضافة/تحديث عنصر مخزون لمستودع
     * POST /branches/{id}/inventory
     * PUT  /branches/{id}/inventory/{productId}
     */
    public function upsertInventoryItem(Request $request, Response $response, array $args): Response
    {
        try {
            $tenantId  = $this->extractTenantId($request);
            $branchId  = (int) ($args['id'] ?? 0);
            $productId = isset($args['productId']) ? (int) $args['productId'] : null;
            $data      = $request->getParsedBody() ?: [];

            if (!$tenantId || !$branchId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            if ($request->getMethod() === 'POST') {
                $productId = (int) ($data['product_id'] ?? 0);
                $quantity  = (float) ($data['quantity']   ?? 0);
                if ($productId <= 0) {
                    return $this->errorResponse($response, 'product_id is required', 400);
                }
                $this->db->beginTransaction();
                $this->db->prepare(
                    "INSERT INTO branch_products (tenant_id, branch_id, product_id, quantity, last_update, gl_reconciled)
                     VALUES (?, ?, ?, ?, NOW(), 0)
                     ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), last_update = NOW()"
                )->execute([$tenantId, $branchId, $productId, $quantity]);
                BranchInventoryReconciliationService::markUnreconciled($this->db, (int) $tenantId, $branchId, $productId);
                $this->db->commit();
                return $this->successResponse($response, ['status' => 'success', 'message' => 'Inventory item added/updated'], 200);
            }

            // PUT
            if (!$productId) {
                return $this->errorResponse($response, 'product id is required in URL', 400);
            }
            $quantity = isset($data['quantity']) ? (float) $data['quantity'] : null;
            $this->db->beginTransaction();
            if ($quantity !== null) {
                $this->db->prepare(
                    "UPDATE branch_products SET quantity = ?, last_update = NOW()
                     WHERE tenant_id = ? AND branch_id = ? AND product_id = ?"
                )->execute([$quantity, $tenantId, $branchId, $productId]);
                BranchInventoryReconciliationService::markUnreconciled($this->db, (int) $tenantId, $branchId, $productId);
            }
            $this->db->commit();
            return $this->successResponse($response, ['status' => 'success', 'message' => 'Inventory item updated'], 200);
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logger->error('خطأ في حفظ عنصر المخزون: ' . $e->getMessage());
            return $this->errorResponse($response, 'فشل في حفظ عنصر المخزون', 500);
        }
    }

    /**
     * تسوية مخزون (زيادة/نقص) لمستودع — عملية واحدة
     */
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

            // منع الرصيد السالب عند الخصم
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
                        'الكمية المتوفرة (' . $currentQty . ') غير كافية للخصم المطلوب (' . abs($delta) . ')',
                        422
                    );
                }
            }

            $movement    = $delta > 0 ? 'adjustment_in' : 'adjustment_out';
            $unitId      = (int)   ($data['unit_id']   ?? 1);
            $unitCost    = (float) ($data['unit_cost']  ?? 0);
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

            $jeId     = $this->createAdjustmentJournalEntry($tenantId, $branchId, $productId, $delta, $notes, $userId, $movement);
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
            return $this->errorResponse($response, 'فشل في عملية التسوية', 500);
        }
    }

    /**
     * تسويات جماعية — JSON payload
     */
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
            // 🔴 إصلاح: كان هذا الاستعلام يُحدِّث quantity فقط ويتجاهل quantity_cost تماماً
            // رغم توفر unit_cost لكل عنصر أدناه — بالضبط نفس الانحراف (drift) الذي كان
            // تعليق InventoryHandler.php يحذّر منه كسبب لعدم الوثوق بـ quantity_cost. أصبح
            // الآن يطبّق نفس الصيغة المُثبَتة صحتها في adjustStockQuantity (دالتين أعلاه في
            // نفس الملف): costAdjustment = delta × unit_cost.
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

                // Prevent negative stock (same check as in adjustStockQuantity)
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

                $jeId = $this->createAdjustmentJournalEntry($tenantId, $branchId, $productId, $delta, $notes, $userId, $movement);
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
            return $this->errorResponse($response, 'فشل في تنفيذ التسويات الجماعية', 500);
        }
    }

    /**
     * تسويات جماعية — استيراد CSV
     * الأعمدة المدعومة: product_id / product_code، branch_id / branch_code، quantity، notes،
     * unit_id، unit_cost (اختياري — يُستخدم لتحديث quantity_cost بدقة؛ 0 إذا غير موجود)،
     * batch_number، expiry_date، serial
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
            // 🔴 نفس إصلاح bulkAdjustments أعلاه: unit_cost كان يُقرأ من كل صف CSV (راجع
            // $unitCost أدناه) لكنه كان يُهمَل تماماً عند الكتابة على branch_products —
            // فيُحدَّث quantity فقط وينحرف quantity_cost تدريجياً مع كل استيراد.
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

                // Prevent negative stock (same check as in bulkAdjustments)
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

                $jeId = $this->createAdjustmentJournalEntry($tenantId, $branchId, $pId, $delta, $notes, $userId, $movement);
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
            return $this->errorResponse($response, 'فشل في استيراد ملف CSV', 500);
        }
    }
}
