<?php

declare(strict_types=1);

use App\Resources\ProductDetailResource;
use App\Resources\ProductListResource;
use PHPUnit\Framework\TestCase;

final class ProductCostVisibilityTest extends TestCase
{
    public function testProductListHidesPurchaseCostAndDerivedMarginsByDefault(): void
    {
        $product = ProductListResource::transform($this->product(), []);

        self::assertNull($product['purchase_price']);
        self::assertNull($product['profit_margin_percent']);
        self::assertNull($product['profit_markup_percent']);
        self::assertNull($product['total_inventory_value']);
    }

    public function testProductListIncludesCostOnlyWhenAuthorized(): void
    {
        $product = ProductListResource::transform($this->product(), ['quantity' => 2], [], null, true);

        self::assertSame(60.0, $product['purchase_price']);
        self::assertSame(40.0, $product['profit_margin_percent']);
        self::assertSame(66.67, $product['profit_markup_percent']);
        self::assertSame(120.0, $product['total_inventory_value']);
    }

    public function testProductDetailHidesPurchaseCostAndDerivedMarginsByDefault(): void
    {
        $product = ProductDetailResource::transform($this->product(), ['quantity' => 2]);

        self::assertNull($product['pricing']['purchase_price']);
        self::assertNull($product['pricing']['profit_margin_percent']);
        self::assertNull($product['pricing']['profit_markup_percent']);
        self::assertNull($product['inventory']['total_inventory_value']);
    }

    public function testProductDetailIncludesCostOnlyWhenAuthorized(): void
    {
        $product = ProductDetailResource::transform($this->product(), ['quantity' => 2], [], null, true);

        self::assertSame(60.0, $product['pricing']['purchase_price']);
        self::assertSame(40.0, $product['pricing']['profit_margin_percent']);
        self::assertSame(66.67, $product['pricing']['profit_markup_percent']);
        self::assertSame(120.0, $product['inventory']['total_inventory_value']);
    }

    private function product(): array
    {
        return [
            'id' => 1,
            'name' => 'Sample',
            'product_code' => 'SKU-1',
            'barcode' => null,
            'description' => null,
            'category_id' => 1,
            'category_name' => 'General',
            'purchase_price' => 60.0,
            'sale_price' => 100.0,
            'min_sale_price' => 80.0,
            'fixed_discount_percentage' => 20.0,
            'min_quantity' => 0,
            'product_type' => 'stock',
            'active' => 1,
            'opening_balance_posted' => 0,
            'has_expiry_date' => 0,
            'has_batch_number' => 0,
            'has_serial_number' => 0,
            'profit_calculation_type' => 'margin',
            'default_category_gl_account_id' => null,
            'supplier_id' => null,
            'created_at' => null,
            'updated_at' => null,
            'default_expiry_date' => null,
            'default_batch_number' => null,
            'default_serial_number' => null,
        ];
    }
}
