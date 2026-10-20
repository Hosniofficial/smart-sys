<?php

declare(strict_types=1);

namespace App\Resources;

/**
 * Shapes product and branch-inventory data for detailed API responses.
 */
class ProductDetailResource
{
    /**
     * Return product details, optionally including cost metrics.
     * Branch-scoped GL status uses the branch mapping rather than the product flag.
     */
    public static function transform(
        array $product,
        array $branchProduct,
        array $units = [],
        ?string $glActivationStatus = null,
        bool $includeCostData = false,
        bool $branchScoped = false
    ): array {
        $mainUnit = self::getMainUnit($units);
        $currentQty = (int) ($branchProduct['quantity'] ?? 0);
        $minQty = $branchProduct['minimum_quantity'] ?? $product['min_quantity'] ?? null;
        $minQty = $minQty !== null ? (float) $minQty : 0;

        $categoryName = $product['category_name'] ?? null;

        // Opening-balance state is only authoritative outside branch-scoped results.
        $glStatus = 'draft';

        if (!$branchScoped && (bool) ($product['opening_balance_posted'] ?? false)) {
            $glStatus = 'posted';
        } elseif ($glActivationStatus === 'GL_POSTED' || $glActivationStatus === 'RECONCILED') {
            $glStatus = 'posted';
        } elseif ($glActivationStatus === 'ACTIVE_IN_BRANCH') {
            $glStatus = 'active';
        }

        return [
            'id'                    => (int) $product['id'],
            'name'                  => $product['name'],
            'product_code'          => $product['product_code'],
            'sku'                   => $product['product_code'],
            'quantity'              => $currentQty,
            'barcode'               => $product['barcode'],
            'description'           => $product['description'],
            'category_id'           => $product['category_id'] ? (int) $product['category_id'] : null,
            'category_name'         => $categoryName,

            'category'              => [
                'id'   => $product['category_id'] ? (int) $product['category_id'] : null,
                'name' => $categoryName,
            ],

            'pricing'               => [
                'purchase_price'        => $includeCostData ? (float) $product['purchase_price'] : null,
                'sale_price'            => (float) $product['sale_price'],
                'min_sale_price'        => (float) ($product['min_sale_price'] ?? 0),
                'fixed_discount_percentage' => (float) ($product['fixed_discount_percentage'] ?? 0),
                'profit_margin_percent' => $includeCostData ? self::calculateMargin(
                    (float) $product['sale_price'],
                    (float) $product['purchase_price']
                ) : null,
                'profit_markup_percent' => $includeCostData ? self::calculateMarkup(
                    (float) $product['sale_price'],
                    (float) $product['purchase_price']
                ) : null,
            ],

            'inventory'             => [
                'current_quantity'      => $currentQty,
                'quantity'              => $currentQty,
                'unit_id'               => $mainUnit['id'] ?? null,
                'unit_name'             => $mainUnit['name'] ?? 'قطعة',
                'min_quantity'          => $minQty,
                'max_quantity'          => (int) ($product['maximum_quantity'] ?? 0),
                'inventory_status'      => self::calculateInventoryStatus($currentQty, $minQty, $product['product_type'] ?? 'stock'),
                'total_inventory_value' => $includeCostData
                    ? $currentQty * (float) $product['purchase_price']
                    : null,
            ],

            'configuration'         => [
                'product_type'         => $product['product_type'] ?? 'stock',
                'active'               => (int) ($product['active'] ?? 0),
                'has_expiry_date'      => (bool) ($product['has_expiry_date'] ?? false),
                'has_batch_number'     => (bool) ($product['has_batch_number'] ?? false),
                'has_serial_number'    => (bool) ($product['has_serial_number'] ?? false),
                'expiry_date'          => $product['default_expiry_date'] ?? null,
                'batch_number'         => $product['default_batch_number'] ?? null,
                'serial_number'        => $product['default_serial_number'] ?? null,
            ],

            'accounting'            => [
                'gl_status'                     => $glStatus,
                'opening_balance_posted'        => (bool) ($product['opening_balance_posted'] ?? false),
                'profit_calculation_type'       => $product['profit_calculation_type'] ?? 'margin',
                'default_category_gl_account_id' => $product['default_category_gl_account_id'] ? (int) $product['default_category_gl_account_id'] : null,
            ],

            'supplier'              => [
                'id' => $product['supplier_id'] ? (int) $product['supplier_id'] : null,
            ],

            'units'                 => self::transformUnits($units),

            'timestamps'            => [
                'created_at' => $product['created_at'] ?? null,
                'updated_at' => $product['updated_at'] ?? null,
            ],
        ];
    }

    /** Normalize product-unit rows for the detail response. */
    private static function transformUnits(array $units): array
    {
        return array_map(fn ($unit) => [
            'id'                => (int) ($unit['unit_id'] ?? $unit['id'] ?? 0),
            'name'              => $unit['unit_name'] ?? $unit['name'] ?? 'قطعة',
            'code'              => $unit['unit_code'] ?? $unit['code'] ?? null,
            'is_main_unit'      => (bool) ($unit['is_main_unit'] ?? false),
            'conversion_factor' => (float) ($unit['conversion_factor'] ?? 1.0),
        ], $units);
    }

    /** Select the designated main unit, falling back to the first available unit. */
    private static function getMainUnit(array $units): ?array
    {
        foreach ($units as $unit) {
            if ((bool) ($unit['is_main_unit'] ?? false)) {
                return [
                    'id'   => (int) ($unit['unit_id'] ?? 1),
                    'name' => $unit['unit_name'] ?? $unit['name'] ?? 'قطعة',
                ];
            }
        }

        return isset($units[0]) ? [
            'id'   => (int) ($units[0]['unit_id'] ?? 1),
            'name' => $units[0]['unit_name'] ?? $units[0]['name'] ?? 'قطعة',
        ] : null;
    }

    /**
     * Calculate profit margin percentage: (Sale - Cost) / Sale * 100
     */
    private static function calculateMargin(float $salePrice, float $costPrice): float
    {
        if ($salePrice <= 0) {
            return 0.0;
        }

        return round((($salePrice - $costPrice) / $salePrice) * 100, 2);
    }

    /**
     * Calculate profit markup percentage: (Sale - Cost) / Cost * 100
     */
    private static function calculateMarkup(float $salePrice, float $costPrice): float
    {
        if ($costPrice <= 0) {
            return 0.0;
        }

        return round((($salePrice - $costPrice) / $costPrice) * 100, 2);
    }

    /**
     * Determine inventory status based on quantity and minimum threshold
     * Now handles numeric min_quantity (0 = threshold not defined, >0 = threshold defined)
     *
     * @param int $quantity Current quantity
     * @param float $minQuantity Minimum threshold (0 = threshold not defined, >0 = threshold defined)
     * @param string $productType 'stock' or 'service'
     * @return string Status code: 'in_stock', 'low_stock', 'out_of_stock', 'N/A'
     */
    private static function calculateInventoryStatus(int $quantity, float|null $minQuantity, string $productType): string
    {
        // Services don't have stock status
        if ($productType === 'service') {
            return 'N/A';
        }

        // Ensure minQuantity is a float (never null)
        $minQty = (float) ($minQuantity ?? 0);

        // If quantity is zero, always out of stock
        if ($quantity <= 0) {
            return 'out_of_stock';
        }

        // If min_quantity is 0 or negative, threshold not defined: assume in stock
        if ($minQty <= 0) {
            // Quantity > 0, but threshold not defined: assume in stock
            return 'in_stock';
        }

        // Min quantity is defined: evaluate against threshold
        if ($quantity <= $minQty) {
            return 'low_stock';
        }

        return 'in_stock';
    }
}
