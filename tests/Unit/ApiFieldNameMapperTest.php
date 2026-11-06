<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Utils\ApiFieldNameMapper;
use PHPUnit\Framework\TestCase;

final class ApiFieldNameMapperTest extends TestCase
{
    public function testMapsNestedApiFieldsWithoutChangingValuesOrListIndexes(): void
    {
        $payload = [
            'branchId' => 7,
            'items' => [
                ['productId' => 2, 'createdAt' => '2026-11-06T09:00:00Z'],
            ],
            'emptyObject' => (object) [],
            'snake_case_value' => 'unchanged',
        ];

        self::assertEquals([
            'branch_id' => 7,
            'items' => [
                ['product_id' => 2, 'created_at' => '2026-11-06T09:00:00Z'],
            ],
            'empty_object' => (object) [],
            'snake_case_value' => 'unchanged',
        ], ApiFieldNameMapper::toSnakeCase($payload));

        self::assertEquals([
            'branchId' => 7,
            'items' => [
                ['productId' => 2, 'createdAt' => '2026-11-06T09:00:00Z'],
            ],
            'emptyObject' => (object) [],
            'snakeCaseValue' => 'unchanged',
        ], ApiFieldNameMapper::toCamelCase(ApiFieldNameMapper::toSnakeCase($payload)));
    }

    public function testPreservesDottedSettingKeys(): void
    {
        $settings = [
            'company.currency_code' => 'EGP',
            'company.currencySymbol' => 'ج.م',
            'tax.tax_rate' => 14,
        ];

        self::assertSame($settings, ApiFieldNameMapper::toSnakeCase($settings));
        self::assertSame($settings, ApiFieldNameMapper::toCamelCase($settings));
    }

    public function testRejectsFieldsThatWouldCollideAfterMapping(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(400);

        ApiFieldNameMapper::toSnakeCase([
            'branchId' => 7,
            'branch_id' => 8,
        ]);
    }
}
