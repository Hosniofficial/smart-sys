<?php

declare(strict_types=1);

use App\Services\AccountingService;
use App\Services\CustomerPaymentAllocationService;

final class AllocateCustomerPaymentsFifo
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function up(): void
    {
        $this->ensureBranchColumn();
        $this->createAllocationTable();
        $this->backfillCustomerVouchers();

        $payments = $this->pdo->query(
            "SELECT id, tenant_id, customer_id, amount, branch_id
             FROM payments
             WHERE sale_id IS NULL
               AND customer_id IS NOT NULL
               AND status = 'completed'
               AND COALESCE(is_draft, 0) = 0
               AND COALESCE(type, '') IN ('customer', 'voucher', '')
             ORDER BY tenant_id, customer_id, payment_date, id"
        )->fetchAll(PDO::FETCH_ASSOC);

        $allocator = new CustomerPaymentAllocationService($this->pdo);
        $accounting = new AccountingService($this->pdo);
        foreach ($payments as $payment) {
            $this->pdo->beginTransaction();
            try {
                $saleIds = $allocator->allocatePayment(
                    (int) $payment['tenant_id'],
                    (int) $payment['customer_id'],
                    (int) $payment['id'],
                    (float) $payment['amount'],
                    !empty($payment['branch_id']) ? (int) $payment['branch_id'] : null
                );
                foreach ($saleIds as $saleId) {
                    $accounting->recalculateSaleStatus((int) $payment['tenant_id'], $saleId);
                }
                $this->pdo->commit();
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $e;
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Migration 023 is forward-only; allocated customer payments must remain auditable.'
        );
    }

    private function ensureBranchColumn(): void
    {
        if ($this->columnExists('payments', 'branch_id')) {
            return;
        }
        $definition = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? 'INTEGER NULL'
            : 'INT(11) NULL';
        $this->pdo->exec("ALTER TABLE payments ADD COLUMN branch_id {$definition}");
    }

    private function createAllocationTable(): void
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS customer_payment_allocations (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    tenant_id INTEGER NOT NULL,
                    payment_id INTEGER NOT NULL,
                    sale_id INTEGER NOT NULL,
                    allocated_amount NUMERIC NOT NULL,
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE (tenant_id, payment_id, sale_id)
                )'
            );
            $this->pdo->exec(
                'CREATE INDEX IF NOT EXISTS idx_customer_payment_allocations_sale
                 ON customer_payment_allocations (tenant_id, sale_id)'
            );
            return;
        }

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS customer_payment_allocations (
                id INT(11) NOT NULL AUTO_INCREMENT,
                tenant_id INT(11) NOT NULL,
                payment_id INT(11) NOT NULL,
                sale_id INT(11) NOT NULL,
                allocated_amount DECIMAL(15,2) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_customer_payment_allocations_payment_sale (tenant_id, payment_id, sale_id),
                KEY idx_customer_payment_allocations_sale (tenant_id, sale_id),
                KEY idx_customer_payment_allocations_payment (tenant_id, payment_id),
                CONSTRAINT fk_customer_payment_allocations_payment
                    FOREIGN KEY (payment_id) REFERENCES payments (id) ON DELETE CASCADE,
                CONSTRAINT fk_customer_payment_allocations_sale
                    FOREIGN KEY (sale_id) REFERENCES sales (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private function backfillCustomerVouchers(): void
    {
        if (!$this->tableExists('cash_vouchers')) {
            return;
        }

        $this->pdo->exec(
            "INSERT INTO payments (
                tenant_id, customer_id, amount, payment_date, payment_method_id,
                reference_number, created_by, is_draft, branch_id, status, type,
                created_at, cost_center_id, journal_entry_id, currency
            )
            SELECT cv.tenant_id, cv.customer_id, cv.amount, cv.date, ct.payment_method_id,
                   cv.reference, cv.created_by, 0, cv.branch_id, 'completed', 'voucher',
                   cv.created_at, cv.cost_center_id, cv.journal_entry_id, cv.currency
            FROM cash_vouchers cv
            JOIN cash_transactions ct
              ON ct.tenant_id = cv.tenant_id
             AND ct.reference_type = 'cash_voucher'
             AND ct.reference_id = cv.id
            WHERE cv.type = 'receipt'
              AND cv.customer_id IS NOT NULL
              AND cv.created_by IS NOT NULL
              AND ct.status = 'completed'
              AND (cv.status IS NULL OR cv.status NOT IN ('cancelled', 'canceled'))
              AND NOT EXISTS (
                  SELECT 1 FROM payments p
                  WHERE p.tenant_id = cv.tenant_id
                    AND p.type = 'voucher'
                    AND (
                        p.journal_entry_id = cv.journal_entry_id
                        OR (
                            p.journal_entry_id IS NULL
                            AND cv.journal_entry_id IS NULL
                            AND p.customer_id = cv.customer_id
                            AND p.amount = cv.amount
                            AND p.payment_date = cv.date
                            AND p.created_by = cv.created_by
                            AND (p.reference_number = cv.reference OR
                                 (p.reference_number IS NULL AND cv.reference IS NULL))
                        )
                    )
              )"
        );
    }

    private function tableExists(string $table): bool
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?"
            );
            $stmt->execute([$table]);
            return (int) $stmt->fetchColumn() > 0;
        }

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $stmt->execute([$table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function columnExists(string $table, string $column): bool
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            foreach ($this->pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ($row['name'] === $column) {
                    return true;
                }
            }
            return false;
        }

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
        );
        $stmt->execute([$table, $column]);
        return (int) $stmt->fetchColumn() > 0;
    }
}
