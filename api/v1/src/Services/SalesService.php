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
        $allowedFields = ['sale_date', 'notes', 'payment_method_id', 'cost_center_id'];
        $unsupportedFields = array_diff(array_keys($data), $allowedFields);
        if ($unsupportedFields) {
            throw new \DomainException('الحقول المسموح بتعديلها هي التاريخ والملاحظات وطريقة الدفع ومركز التكلفة فقط.');
        }
        if (!$data) {
            throw new \DomainException('لم يتم إرسال أي بيانات لتعديل الفاتورة.');
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                "SELECT id, status, journal_entry_id, payment_method_id, paid_amount
                 FROM sales WHERE id = ? AND tenant_id = ? FOR UPDATE"
            );
            $stmt->execute([$saleId, $tenantId]);
            $sale = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$sale) {
                throw new Exception('Sale not found');
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
                if (!$costCenterStmt->fetchColumn()) {
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

            $columns = [
                'sale_date' => 'sale_date',
                'notes' => 'notes',
                'payment_method_id' => 'payment_method_id',
                'cost_center_id' => 'cost_center_id',
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
            $updates[] = 'updated_at = NOW()';
            $values[] = $saleId;
            $values[] = $tenantId;
            $upd = $this->pdo->prepare(
                'UPDATE sales SET ' . implode(', ', $updates) . ' WHERE id = ? AND tenant_id = ?'
            );
            $upd->execute($values);

            $this->logAudit('sale_updated', 'sales', $saleId, [
                'tenant_id' => (int) $tenantId,
                'user_id'   => (int) $this->userId,
                'sale_id'   => (int) $saleId,
                'changed'   => array_keys($data),
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

    // -------------------------------------------------------------------------
    // deleteSale — إلغاء فاتورة (canceled) مع عكس المخزون والقيد
    // -------------------------------------------------------------------------

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
                             user_id, movement_date, batch_number, expiry_date
                         ) VALUES (?, ?, ?, NULL, ?, ?, ?, ?, 'sale_cancel', 'sale', ?, ?, NOW(), ?, ?)"
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
