<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Resolves the cash-refund portion of a purchase return.
 * The balance remaining after the refund is posted against the supplier account.
 */
final class PurchaseReturnSettlementPolicy
{
    /** Calculate the purchase balance after net payments and prior returns. */
    public static function outstandingAmount(float $purchaseTotal, float $netPaid, float $returnedAmount): float
    {
        return round(max(0.0, $purchaseTotal - $netPaid - $returnedAmount), 2);
    }

    /** Calculate how much of a purchase return is paid out as cash. */
    public static function cashRefundAmount(
        string $refundMode,
        float $returnTotal,
        float $purchaseOutstanding,
        float $supplierPayable
    ): float {
        $mode = strtolower(trim($refundMode));
        if ($mode === 'deduct_and_return') {
            $mode = 'auto';
        }

        if (!in_array($mode, ['auto', 'cash', 'credit_note'], true)) {
            throw new \InvalidArgumentException('سياسة مرتجع المشتريات غير صالحة.');
        }

        $returnTotal = round(max(0.0, $returnTotal), 2);
        $purchaseOutstanding = round(max(0.0, $purchaseOutstanding), 2);
        $supplierPayable = round(max(0.0, $supplierPayable), 2);

        if ($purchaseOutstanding > 0) {
            if ($mode === 'cash') {
                throw new \InvalidArgumentException(
                    'لا يمكن استلام نقدي لمرتجع على فاتورة مشتريات آجلة أو غير مسددة بالكامل. سيتم خصم قيمة المرتجع من ذمة المورد.'
                );
            }

            return 0.0;
        }

        return match ($mode) {
            'cash' => $returnTotal,
            'credit_note' => 0.0,
            'auto' => round(max(0.0, $returnTotal - min($returnTotal, $supplierPayable)), 2),
        };
    }
}
