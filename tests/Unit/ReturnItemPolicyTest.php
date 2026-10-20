<?php

declare(strict_types=1);

use App\Security\ReturnItemPolicy;
use PHPUnit\Framework\TestCase;

final class ReturnItemPolicyTest extends TestCase
{
    public function testRecalculatesSaleReturnAmountsFromOriginalInvoiceLines(): void
    {
        $result = ReturnItemPolicy::normalize(
            'sale',
            [[
                'sale_item_id' => 12,
                'product_id' => 4,
                'unit_id' => 2,
                'quantity' => 1,
                'unit_price' => 0.01,
                'subtotal' => 0.01,
            ]],
            [[
                'id' => 12,
                'product_id' => 4,
                'unit_id' => 2,
                'quantity' => 2,
                'net_total' => 180,
            ]],
            [],
            14
        );

        self::assertSame(90.0, $result['items'][0]['unit_price']);
        self::assertSame(90.0, $result['subtotal']);
        self::assertSame(12.6, $result['tax']);
        self::assertSame(102.6, $result['subtotal'] + $result['tax']);
    }

    public function testRecalculatesPurchaseReturnAmountsIncludingOriginalTax(): void
    {
        $result = ReturnItemPolicy::normalize(
            'purchase',
            [[
                'purchase_item_id' => 20,
                'product_id' => 8,
                'unit_id' => 1,
                'quantity' => 1,
                'unit_price' => 9999,
                'subtotal' => 9999,
            ]],
            [[
                'id' => 20,
                'product_id' => 8,
                'unit_id' => 1,
                'quantity' => 4,
                'subtotal' => 460,
                'tax_amount' => 60,
            ]],
            []
        );

        self::assertSame(100.0, $result['items'][0]['unit_price']);
        self::assertSame(100.0, $result['subtotal']);
        self::assertSame(15.0, $result['tax']);
    }

    public function testRejectsQuantityBeyondPreviouslyReturnedBalancePerUnit(): void
    {
        $this->expectException(DomainException::class);
        ReturnItemPolicy::normalize(
            'sale',
            [['product_id' => 4, 'unit_id' => 1, 'quantity' => 2]],
            [[
                'id' => 12,
                'product_id' => 4,
                'unit_id' => 1,
                'quantity' => 3,
                'net_total' => 300,
            ]],
            [ReturnItemPolicy::groupKey(4, 1) => 2]
        );
    }

    public function testRejectsSourceItemFromAnotherInvoiceProductOrUnit(): void
    {
        $this->expectException(DomainException::class);
        ReturnItemPolicy::normalize(
            'sale',
            [[
                'sale_item_id' => 99,
                'product_id' => 4,
                'unit_id' => 1,
                'quantity' => 1,
            ]],
            [[
                'id' => 12,
                'product_id' => 4,
                'unit_id' => 1,
                'quantity' => 1,
                'net_total' => 100,
            ]],
            []
        );
    }
}
