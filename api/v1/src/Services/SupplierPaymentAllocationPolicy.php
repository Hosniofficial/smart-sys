<?php

declare(strict_types=1);

namespace App\Services;

/** Validates and normalizes supplier-payment allocations across purchase invoices. */
final class SupplierPaymentAllocationPolicy
{
    /**
     * Normalize allocation rows and verify that their total matches the payment.
     *
     * @return array<int, array{purchase_id: int, amount: float}>
     */
    public static function normalize($allocations, float $paymentAmount): array
    {
        if (!is_array($allocations) || $allocations === [] || count($allocations) > 100) {
            throw new \InvalidArgumentException('يجب تحديد فاتورة واحدة على الأقل وبحد أقصى 100 فاتورة.');
        }

        $normalized = [];
        $seenPurchaseIds = [];
        $allocatedTotal = 0.0;
        foreach ($allocations as $allocation) {
            if (!is_array($allocation)) {
                throw new \InvalidArgumentException('صيغة تخصيص الفواتير غير صحيحة.');
            }

            $purchaseId = (int) ($allocation['purchase_id'] ?? 0);
            $amount = round((float) ($allocation['amount'] ?? 0), 2);
            if ($purchaseId <= 0 || !is_finite($amount) || $amount <= 0) {
                throw new \InvalidArgumentException('رقم الفاتورة ومبلغ التخصيص الموجب مطلوبان لكل فاتورة.');
            }
            if (isset($seenPurchaseIds[$purchaseId])) {
                throw new \InvalidArgumentException('لا يمكن تكرار الفاتورة ضمن التخصيص نفسه.');
            }

            $seenPurchaseIds[$purchaseId] = true;
            $allocatedTotal += $amount;
            $normalized[] = [
                'purchase_id' => $purchaseId,
                'amount' => $amount,
            ];
        }

        if (abs(round($allocatedTotal, 2) - round($paymentAmount, 2)) > 0.009) {
            throw new \InvalidArgumentException('يجب أن يساوي إجمالي التخصيصات مبلغ الدفعة.');
        }

        return $normalized;
    }
}
