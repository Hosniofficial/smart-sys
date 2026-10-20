<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

final class SupplierInvoicePaymentAllocator
{
    /**
     * @param array<int, array{purchase_id: int, amount: float}> $allocations
     * @return array<int, array<string, mixed>>
     */
    public static function allocate(
        PDO $db,
        PurchaseService $purchaseService,
        int $tenantId,
        int $supplierId,
        array $allocations,
        string $paymentDate,
        int $paymentMethodId,
        ?string $referenceNumber,
        ?int $branchId,
        ?int $costCenterId,
        ?int $sessionId
    ): array {
        if (!$db->inTransaction()) {
            throw new \LogicException('Invoice allocations must run inside the payment transaction.');
        }

        $purchaseIds = array_column($allocations, 'purchase_id');
        sort($purchaseIds, SORT_NUMERIC);
        $forUpdate = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $purchaseStmt = $db->prepare(
            "SELECT p.*, s.account_id AS supplier_account_id
             FROM purchases p
             INNER JOIN suppliers s ON s.id = p.supplier_id AND s.tenant_id = p.tenant_id
             WHERE p.id = ? AND p.supplier_id = ? AND p.tenant_id = ?
             LIMIT 1{$forUpdate}"
        );

        $purchasesById = [];
        foreach ($purchaseIds as $purchaseId) {
            $purchaseStmt->execute([$purchaseId, $supplierId, $tenantId]);
            $purchase = $purchaseStmt->fetch(PDO::FETCH_ASSOC);
            if (!$purchase) {
                throw new \InvalidArgumentException('إحدى فواتير التخصيص غير موجودة أو لا تخص هذا المورد.');
            }
            $purchasesById[(int) $purchaseId] = $purchase;
        }

        $payments = [];
        foreach ($allocations as $allocation) {
            $purchaseId = $allocation['purchase_id'];
            $result = $purchaseService->addPaymentToInvoice(
                $purchaseId,
                $purchasesById[$purchaseId],
                $allocation['amount'],
                $paymentDate,
                $paymentMethodId,
                $referenceNumber,
                $branchId,
                $costCenterId,
                $sessionId
            );
            $payments[] = [
                'payment_id' => (int) ($result['payment_id'] ?? 0),
                'purchase_id' => $purchaseId,
                'amount' => $allocation['amount'],
                'journal_entry_id' => $result['journal_entry_id'] ?? null,
                'remaining_amount' => $result['remaining'] ?? null,
                'status' => $result['status'] ?? null,
            ];
        }

        return $payments;
    }
}
