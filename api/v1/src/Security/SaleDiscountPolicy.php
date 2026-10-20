<?php

declare(strict_types=1);

namespace App\Security;

use DomainException;

/**
 * Enforces the server-side discount policy for each sale line.
 *
 * `fixed_discount_percentage` limits invoice-level discounts. A manually
 * overridden line price requires discount permission and still respects
 * `min_sale_price`, the absolute price floor unless an authorized
 * below-cost override is explicitly approved with a reason.
 */
final class SaleDiscountPolicy
{
    /**
     * @param array $items Items must contain quantity, sale_price,
     *                     catalog_sale_price, conversion_factor,
     *                     min_sale_price, fixed_discount_percentage and
     *                     optional manual_price_override.
     * @param float $invoiceDiscountAmount Discount amount after normalizing the
     *                                     requested fixed/percentage discount.
     * @param float $discountableSubtotal Subtotal used to prorate invoice discount.
     * @throws DomainException When the caller or configured product limits reject it.
     */
    public static function assertAllowed(
        array $items,
        float $invoiceDiscountAmount,
        float $discountableSubtotal,
        bool $canDiscount,
        bool $canApproveBelowCost = false,
        string $belowCostReason = ''
    ): bool {
        $hasBelowCostItems = false;
        foreach ($items as $item) {
            $quantity = (float) ($item['quantity'] ?? 0);
            if (!is_finite($quantity) || $quantity <= 0) {
                throw new DomainException('كمية الصنف يجب أن تكون أكبر من صفر.');
            }

            $salePrice = (float) ($item['sale_price'] ?? 0);
            if (!is_finite($salePrice) || $salePrice < 0) {
                throw new DomainException('سعر بيع الصنف غير صالح.');
            }
            $catalogPrice = (float) ($item['catalog_sale_price'] ?? $salePrice);
            $conversionFactor = (float) ($item['conversion_factor'] ?? 1);
            if ($conversionFactor <= 0) {
                $conversionFactor = 1;
            }

            $lineSubtotal = $quantity * $salePrice;
            $allocatedInvoiceDiscount = $discountableSubtotal > 0
                ? $invoiceDiscountAmount * ($lineSubtotal / $discountableSubtotal)
                : 0.0;
            $effectiveUnitPrice = max(0.0, $salePrice - ($allocatedInvoiceDiscount / $quantity));
            $referenceUnitPrice = max(0.0, $catalogPrice * $conversionFactor);
            $minimumUnitPrice = max(0.0, (float) ($item['min_sale_price'] ?? 0)) * $conversionFactor;
            $purchasePrice = max(0.0, (float) ($item['purchase_price'] ?? 0)) * $conversionFactor;
            $belowCostConfirmed = filter_var(
                $item['below_cost_confirmed'] ?? false,
                FILTER_VALIDATE_BOOLEAN
            );
            $belowMinimumPriceConfirmed = filter_var(
                $item['below_minimum_price_confirmed'] ?? false,
                FILTER_VALIDATE_BOOLEAN
            );
            $manualPriceOverride = filter_var(
                $item['manual_price_override'] ?? false,
                FILTER_VALIDATE_BOOLEAN
            );

            $isBelowCostItem = $purchasePrice > 0 && $effectiveUnitPrice + 0.005 < $purchasePrice;
            if ($isBelowCostItem) {
                if (!$canApproveBelowCost) {
                    throw new DomainException('لا تملك صلاحية اعتماد البيع بأقل من تكلفة المنتج.');
                }
                if (!$belowCostConfirmed) {
                    throw new DomainException('سعر البيع أقل من تكلفة المنتج. يلزم تأكيد المشرف للمتابعة.');
                }
                if (strlen(trim($belowCostReason)) < 5) {
                    throw new DomainException('سبب البيع بأقل من التكلفة مطلوب ويجب ألا يقل عن 5 أحرف.');
                }
                $hasBelowCostItems = true;
            }

            $isBelowMinimumPrice = !$isBelowCostItem
                && $minimumUnitPrice > 0
                && $effectiveUnitPrice + 0.005 < $minimumUnitPrice;
            if ($isBelowMinimumPrice && !$canDiscount) {
                throw new DomainException('لا تملك صلاحية تجاوز أقل سعر مسموح للمنتج.');
            }

            $discountPerUnit = max(0.0, $referenceUnitPrice - $effectiveUnitPrice);
            if ($discountPerUnit > 0.005) {
                if (!$canDiscount) {
                    throw new DomainException('لا تملك صلاحية تطبيق خصم على المبيعات.');
                }

                $maximumDiscountPercentage = max(
                    0.0,
                    (float) ($item['fixed_discount_percentage'] ?? 0)
                );
                if ($maximumDiscountPercentage > 100) {
                    throw new DomainException('نسبة الخصم القصوى لهذا المنتج غير صحيحة.');
                }
                $invoiceDiscountPerUnit = max(0.0, $allocatedInvoiceDiscount / $quantity);
                if (
                    $maximumDiscountPercentage <= 0
                    && !$isBelowCostItem
                    && (!$manualPriceOverride || $invoiceDiscountPerUnit > 0.005)
                ) {
                    throw new DomainException('لم يتم تحديد حد خصم مسموح لهذا المنتج.');
                }

                $discountForCap = $manualPriceOverride
                    ? $invoiceDiscountPerUnit
                    : $discountPerUnit;
                $discountBasis = $manualPriceOverride
                    ? $salePrice
                    : $referenceUnitPrice;
                $actualDiscountPercentage = $discountBasis > 0
                    ? ($discountForCap / $discountBasis) * 100
                    : 100.0;
                if ($maximumDiscountPercentage > 0
                    && !$isBelowCostItem
                    && $actualDiscountPercentage > $maximumDiscountPercentage + 0.005) {
                    throw new DomainException('الخصم يتجاوز الحد الأقصى المحدد لهذا المنتج.');
                }
            }

            if ($isBelowMinimumPrice && !$belowMinimumPriceConfirmed) {
                throw new DomainException('سعر البيع أقل من أقل سعر مسموح. يلزم تأكيد المشرف للمتابعة.');
            }
        }
        return $hasBelowCostItems;
    }
}
