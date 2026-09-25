<?php

namespace App\Services;

use PDO;
use Exception;
use App\Services\AccountingService;
use App\Services\MonologHandler;

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

    public function __construct(PDO $pdo, $userId = 1, ?int $tenantId = null)
    {
        $this->pdo        = $pdo;
        $this->userId     = $userId;
        $this->tenantId   = $tenantId;
        $this->logger     = MonologHandler::getInstance('sales');
        $this->accounting = new AccountingService($pdo);
    }

    // -------------------------------------------------------------------------
    // Audit helper
    // -------------------------------------------------------------------------

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

    // -------------------------------------------------------------------------
    // updateSale — تحديث بيانات فاتورة (التاريخ / الملاحظات / طريقة الدفع)
    // -------------------------------------------------------------------------

    public function updateSale(int $tenantId, int $saleId, array $data): array
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                "SELECT id FROM sales WHERE id = ? AND tenant_id = ? FOR UPDATE"
            );
            $stmt->execute([$saleId, $tenantId]);
            if (!$stmt->fetchColumn()) {
                throw new Exception('Sale not found');
            }

            $upd = $this->pdo->prepare(
                "UPDATE sales
                 SET sale_date        = COALESCE(?, sale_date),
                     notes            = COALESCE(?, notes),
                     payment_method_id = COALESCE(?, payment_method_id),
                     cost_center_id   = COALESCE(?, cost_center_id),
                     updated_at       = NOW()
                 WHERE id = ? AND tenant_id = ?"
            );
            $upd->execute([
                $data['sale_date']        ?? null,
                $data['notes']            ?? null,
                $data['payment_method_id'] ?? null,
                array_key_exists('cost_center_id', $data) ? $data['cost_center_id'] : null,
                $saleId,
                $tenantId,
            ]);

            $this->logAudit('sale_updated', 'sales', $saleId, [
                'tenant_id' => (int) $tenantId,
                'user_id'   => (int) $this->userId,
                'sale_id'   => (int) $saleId,
                'changed'   => array_keys($data),
            ], (int) $tenantId);

            $this->pdo->commit();
            return ['sale_id' => $saleId];
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // deleteSale — إلغاء فاتورة (canceled) مع عكس المخزون والقيد
    // -------------------------------------------------------------------------

    public function deleteSale(int $tenantId, int $saleId, ?string $note = null): array
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                "SELECT status, branch_id FROM sales WHERE id = ? AND tenant_id = ? FOR UPDATE"
            );
            $stmt->execute([$saleId, $tenantId]);
            $sale = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$sale) {
                throw new Exception('Sale not found');
            }

            $previousStatus = $sale['status'];
            $branchId       = (int) ($sale['branch_id'] ?? 1);

            if (in_array($previousStatus, ['paid', 'canceled'], true)) {
                throw new Exception('لا يمكن إلغاء فاتورة مدفوعة بالكامل أو مُلغاة بالفعل');
            }

            // عكس المخزون فقط إذا كانت الفاتورة معتمدة (لا pending_approval / draft)
            $stockWasApplied = !in_array(
                $previousStatus,
                ['pending_approval', 'rejected', 'draft'],
                true
            );

            if ($stockWasApplied) {
                $stmt = $this->pdo->prepare(
                    "SELECT product_id, quantity, unit_id, batch_number, expiry_date
                     FROM sales_items WHERE sale_id = ? AND tenant_id = ?"
                );
                $stmt->execute([$saleId, $tenantId]);
                $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($items as $item) {
                    $qty = (float) $item['quantity'];

                    $this->pdo->prepare(
                        "UPDATE branch_products
                         SET quantity = quantity + ?
                         WHERE product_id = ? AND branch_id = ? AND tenant_id = ?"
                    )->execute([$qty, $item['product_id'], $branchId, $tenantId]);
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
                             quantity, movement_type, reference_type, reference_id,
                             user_id, movement_date, batch_number, expiry_date
                         ) VALUES (?, ?, ?, NULL, ?, ?, 'sale_cancel', 'sale', ?, ?, NOW(), ?, ?)"
                    )->execute([
                        $tenantId,
                        $item['product_id'],
                        $item['unit_id'] ?? 1,
                        $branchId,
                        $qty,
                        $saleId,
                        $this->userId,
                        $item['batch_number'] ?? null,
                        $item['expiry_date']  ?? null,
                    ]);
                }
            }

            // عكس القيد المحاسبي
            $stmt = $this->pdo->prepare(
                "SELECT journal_entry_id FROM sales WHERE id = ? AND tenant_id = ?"
            );
            $stmt->execute([$saleId, $tenantId]);
            $journalEntryId = $stmt->fetchColumn();
            if ($journalEntryId) {
                $this->accounting->deleteJournalEntry((int) $tenantId, (int) $journalEntryId);
            }

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
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
