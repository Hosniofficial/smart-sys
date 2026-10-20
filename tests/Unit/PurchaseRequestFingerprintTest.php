<?php

declare(strict_types=1);

use App\Services\PurchaseRequestFingerprint;
use PHPUnit\Framework\TestCase;

final class PurchaseRequestFingerprintTest extends TestCase
{
    public function testAssociativeKeyOrderDoesNotChangeFingerprint(): void
    {
        $first = [
            'supplier_id' => 9,
            'items' => [['product_id' => 5, 'quantity' => 10]],
            'branch_id' => 4,
        ];
        $retry = [
            'branch_id' => 4,
            'items' => [['quantity' => 10, 'product_id' => 5]],
            'supplier_id' => 9,
        ];

        self::assertSame(
            PurchaseRequestFingerprint::fromPayload($first),
            PurchaseRequestFingerprint::fromPayload($retry)
        );
    }

    public function testDifferentPurchasePayloadHasDifferentFingerprint(): void
    {
        $original = ['supplier_id' => 9, 'items' => [['product_id' => 5, 'quantity' => 10]]];
        $changed = ['supplier_id' => 9, 'items' => [['product_id' => 5, 'quantity' => 20]]];

        self::assertNotSame(
            PurchaseRequestFingerprint::fromPayload($original),
            PurchaseRequestFingerprint::fromPayload($changed)
        );
    }
}
