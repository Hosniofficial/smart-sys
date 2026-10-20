<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

/**
 * Allocates unlinked customer receipts to eligible open invoices in FIFO order.
 *
 * The caller owns the active transaction and recalculates affected sale statuses.
 */
final class CustomerPaymentAllocationService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Distribute an unallocated customer receipt across open invoices in FIFO order.
     * A null branch ID allows invoices from any branch.
     *
     * @return int[] Sale IDs whose balances were changed.
     */
    public function allocatePayment(
        int $tenantId,
        int $customerId,
        int $paymentId,
        float $amount,
        ?int $branchId
    ): array {
        if (!$this->db->inTransaction()) {
            throw new RuntimeException('Customer payment allocation requires an active transaction.');
        }

        $existing = $this->db->prepare(
            'SELECT sale_id FROM customer_payment_allocations WHERE tenant_id = ? AND payment_id = ?'
        );
        $existing->execute([$tenantId, $paymentId]);
        $existingSaleIds = array_map('intval', $existing->fetchAll(PDO::FETCH_COLUMN) ?: []);
        if ($existingSaleIds) {
            return $existingSaleIds;
        }

        $forUpdate = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $customerLock = $this->db->prepare(
            "SELECT id FROM customers WHERE tenant_id = ? AND id = ? LIMIT 1{$forUpdate}"
        );
        $customerLock->execute([$tenantId, $customerId]);
        if (!$customerLock->fetchColumn()) {
            throw new RuntimeException('Customer not found for payment allocation.');
        }

        $branchFilter = $branchId !== null ? ' AND s.branch_id = :branch_id' : '';
        $sales = $this->db->prepare("
            SELECT
                s.id,
                s.net_total_amount,
                COALESCE(s.tax_amount, 0) AS tax_amount,
                COALESCE((
                    SELECT SUM(CASE WHEN p.type = 'return_payment' THEN -p.amount ELSE p.amount END)
                    FROM payments p
                    WHERE p.sale_id = s.id
                      AND p.tenant_id = s.tenant_id
                      AND p.is_draft = 0
                      AND p.status = 'completed'
                ), 0) AS direct_paid,
                COALESCE((
                    SELECT SUM(cpa.allocated_amount)
                    FROM customer_payment_allocations cpa
                    JOIN payments ap ON ap.id = cpa.payment_id AND ap.tenant_id = cpa.tenant_id
                    WHERE cpa.sale_id = s.id
                      AND cpa.tenant_id = s.tenant_id
                      AND ap.is_draft = 0
                      AND ap.status = 'completed'
                ), 0) AS allocated_paid,
                COALESCE((
                    SELECT SUM(rca.allocated_amount)
                    FROM return_credit_allocations rca
                    WHERE rca.sale_id = s.id AND rca.tenant_id = s.tenant_id
                ), 0) AS return_credits
            FROM sales s
            WHERE s.tenant_id = :tenant_id
              AND s.customer_id = :customer_id
              AND LOWER(COALESCE(s.status, '')) IN ('pending_payment', 'partial', 'due')
              {$branchFilter}
            ORDER BY s.sale_date ASC, s.id ASC
            {$forUpdate}
        ");
        $params = [':tenant_id' => $tenantId, ':customer_id' => $customerId];
        if ($branchId !== null) {
            $params[':branch_id'] = $branchId;
        }
        $sales->execute($params);

        $remainingToAllocate = round($amount, 2);
        $insert = $this->db->prepare(
            'INSERT INTO customer_payment_allocations (tenant_id, payment_id, sale_id, allocated_amount)
             VALUES (?, ?, ?, ?)'
        );
        $allocatedSaleIds = [];

        foreach ($sales->fetchAll(PDO::FETCH_ASSOC) ?: [] as $sale) {
            if ($remainingToAllocate <= 0.009) {
                break;
            }

            $invoiceTotal = round((float) $sale['net_total_amount'] + (float) $sale['tax_amount'], 2);
            $invoiceRemaining = max(0.0, round(
                $invoiceTotal
                - (float) $sale['direct_paid']
                - (float) $sale['allocated_paid']
                - (float) $sale['return_credits'],
                2
            ));
            if ($invoiceRemaining <= 0.009) {
                continue;
            }

            $allocated = min($invoiceRemaining, $remainingToAllocate);
            $insert->execute([$tenantId, $paymentId, (int) $sale['id'], $allocated]);
            $remainingToAllocate = round($remainingToAllocate - $allocated, 2);
            $allocatedSaleIds[] = (int) $sale['id'];
        }

        return $allocatedSaleIds;
    }
}
