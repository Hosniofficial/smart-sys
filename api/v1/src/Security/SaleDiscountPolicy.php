<?php

declare(strict_types=1);

namespace App\Security;

use DomainException;

/**
 * Enforces the server-side discount policy for each sale line.
 *
 * `fixed_discount_percentage` is the product's maximum discount percentage.
 * `min_sale_price` is the absolute price floor. Both values are maintained by
 * tenant staff with product-pricing access; a discount permission alone never
 * bypasses either limit.
 */
final class SaleDiscountPolicy
{
    /**
     * @param array $items Items must contain quantity, sale_price,
     *                     catalog_sale_price, conversion_factor,
     *                     min_sale_price and fixed_discount_percentage.
     * @param float $invoiceDiscountAmount Discount amount after normalizing the
     *                                     requested fixed/percentage discount.
     * @param float $discountableSubtotal Subtotal used to prorate invoice discount.
     * @throws DomainException When the caller or configured product limits reject it.
     */
    public static function assertAllowed(
        array $items,
        float $invoiceDiscountAmount,
        float $discountableSubtotal,
        bool $canDiscount
    ): void {
        foreach ($items as $item) {
            $quantity = (float) ($item['quantity'] ?? 0);
            if ($quantity <= 0) {
                continue;
            }

            $salePrice = (float) ($item['sale_price'] ?? 0);
            if ($salePrice < 0) {
                throw new DomainException('سعر البيع لا يمكن أن يكون سالبًا.');
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
            $discountPerUnit = max(0.0, $referenceUnitPrice - $effectiveUnitPrice);

            if ($discountPerUnit <= 0.005) {
                continue;
            }

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
            if ($maximumDiscountPercentage <= 0) {
                throw new DomainException('لم يتم تحديد حد خصم مسموح لهذا المنتج.');
            }

            $actualDiscountPercentage = $referenceUnitPrice > 0
                ? ($discountPerUnit / $referenceUnitPrice) * 100
                : 100.0;
            if ($actualDiscountPercentage > $maximumDiscountPercentage + 0.005) {
                throw new DomainException('الخصم يتجاوز الحد الأقصى المحدد لهذا المنتج.');
            }

            $minimumUnitPrice = max(0.0, (float) ($item['min_sale_price'] ?? 0)) * $conversionFactor;
            if ($minimumUnitPrice > 0 && $effectiveUnitPrice + 0.005 < $minimumUnitPrice) {
                throw new DomainException('سعر البيع بعد الخصم أقل من أقل سعر مسموح للمنتج.');
            }
        }
    }
}
