<?php

declare(strict_types=1);

use App\Utils\PaginationHelper;
use PHPUnit\Framework\TestCase;

final class PaginationHelperTest extends TestCase
{
    public function testUsesDefaultsAndCalculatesOffset(): void
    {
        self::assertSame([1, 20, 0], PaginationHelper::fromArray([]));
        self::assertSame([3, 10, 20], PaginationHelper::fromArray([
            'page' => 3,
            'limit' => 10,
        ]));
    }

    public function testClampsPageSizeToConfiguredBounds(): void
    {
        self::assertSame([1, 1, 0], PaginationHelper::fromArray([
            'limit' => 0,
        ]));
        self::assertSame([2, 100, 100], PaginationHelper::fromArray([
            'page' => 2,
            'limit' => 500,
        ], 20, 100));
        self::assertSame([1, 1000, 0], PaginationHelper::fromArray([
            'limit' => 1200,
        ], 10, 1000));
    }

    public function testBuildsPaginationMetadata(): void
    {
        self::assertSame([
            'total' => 45,
            'per_page' => 20,
            'current_page' => 2,
            'last_page' => 3,
        ], PaginationHelper::buildMeta(45, 2, 20));
    }
}
