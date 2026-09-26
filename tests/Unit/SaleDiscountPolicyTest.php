<?php

declare(strict_types=1);

use App\Security\SaleDiscountPolicy;
use PHPUnit\Framework\TestCase;

final class SaleDiscountPolicyTest extends TestCase
{
    public function testAllowsAnUndiscountedSaleWithoutDiscountPermission(): void
    {
        SaleDiscountPolicy::assertAllowed(
            [$this->item()],
            0.0,
            100.0,
            false
        );

        self::assertTrue(true);
    }

    public function testRejectsLinePriceReductionWithoutDiscountPermission(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('لا تملك صلاحية تطبيق خصم');

        SaleDiscountPolicy::assertAllowed(
            [$this->item(['sale_price' => 95.0])],
            0.0,
            95.0,
            false
        );
    }

    public function testAllowsDiscountWithinConfiguredPercentageAndPriceFloor(): void
    {
        SaleDiscountPolicy::assertAllowed(
            [$this->item()],
            10.0,
            100.0,
            true
        );

        self::assertTrue(true);
    }

    public function testRejectsDiscountAboveProductPercentageCap(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('يتجاوز الحد الأقصى');

        SaleDiscountPolicy::assertAllowed(
            [$this->item()],
            10.01,
            100.0,
            true
        );
    }

    public function testRejectsDiscountBelowProductMinimumSalePrice(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('أقل من أقل سعر مسموح');

        SaleDiscountPolicy::assertAllowed(
            [$this->item([
                'fixed_discount_percentage' => 20.0,
                'min_sale_price' => 95.0,
            ])],
            6.0,
            100.0,
            true
        );
    }

    public function testRequiresConfiguredProductDiscountCap(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('لم يتم تحديد حد خصم');

        SaleDiscountPolicy::assertAllowed(
            [$this->item(['fixed_discount_percentage' => 0.0])],
            1.0,
            100.0,
            true
        );
    }

    public function testProratesInvoiceDiscountBeforeCheckingEachProductCap(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('يتجاوز الحد الأقصى');

        SaleDiscountPolicy::assertAllowed(
            [
                $this->item(['quantity' => 1.0, 'sale_price' => 100.0, 'catalog_sale_price' => 100.0]),
                $this->item([
                    'quantity' => 1.0,
                    'sale_price' => 100.0,
                    'catalog_sale_price' => 100.0,
                    'fixed_discount_percentage' => 5.0,
                ]),
            ],
            12.0,
            200.0,
            true
        );
    }

    private function item(array $overrides = []): array
    {
        return array_merge([
            'quantity' => 1.0,
            'sale_price' => 100.0,
            'catalog_sale_price' => 100.0,
            'conversion_factor' => 1.0,
            'min_sale_price' => 90.0,
            'fixed_discount_percentage' => 10.0,
        ], $overrides);
    }
}
