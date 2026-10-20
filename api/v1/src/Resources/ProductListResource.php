<?php

declare(strict_types=1);

namespace App\Resources;

/**
 * Shapes product and branch-inventory records for product-list API responses.
 */
class ProductListResource
{
    /**
     * Return a product row with display fields, optional cost metrics, and inventory status.
     * Branch-scoped results derive GL status from the branch mapping, not the product flag.
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
        $minQty = (float) ($branchProduct['minimum_quantity'] ?? $product['min_quantity'] ?? 0);

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
            'barcode'               => $product['barcode'],

            'category_id'           => $product['category_id'] ? (int) $product['category_id'] : null,
            'category_name'         => $product['category_name'] ?? null,

            'current_quantity'      => $currentQty,
            'quantity'              => $currentQty,
            'unit_name'             => $mainUnit['name'] ?? 'قطعة',
            'unit_id'               => $mainUnit['id'] ?? null,
            'min_quantity'          => $minQty,

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

            'total_inventory_value' => $includeCostData
                ? $currentQty * (float) $product['purchase_price']
                : null,

            'product_type'          => $product['product_type'] ?? 'stock',
            'active'                => (int) ($product['active'] ?? 0),

            'inventory_status'      => self::calculateInventoryStatus($currentQty, $minQty, $product['product_type'] ?? 'stock'),
            'gl_status'             => $glStatus,

            'last_updated'          => $product['updated_at'] ?? $product['created_at'] ?? null,
        ];
    }

    /**
     * Transform multiple products at once
     */
    public static function transformMany(
        array $products,
        array $branchProducts = [],
        array $allUnits = [],
        array $glMappings = [],
        bool $includeCostData = false
    ): array
    {
        return array_map(
            fn ($product) => self::transform(
                $product,
                $branchProducts[$product['id']] ?? [],
                $allUnits[$product['id']] ?? [],
                $glMappings[$product['id']] ?? null,
                $includeCostData
            ),
            $products
        );
    }

    /**
     * Get main unit from product_units array
     * @param array $units Product units with is_main_unit flag
     * @return array|null Main unit or null
     */
    private static function getMainUnit(array $units): ?array
    {
        foreach ($units as $unit) {
            if ((bool) ($unit['is_main_unit'] ?? false)) {
                return [
                    'id' => (int) $unit['unit_id'],
                    'name' => $unit['unit_name'] ?? $unit['name'] ?? 'قطعة',
                ];
            }
        }

        // Fallback to first unit if no is_main_unit
        return isset($units[0]) ? [
            'id' => (int) ($units[0]['unit_id'] ?? 1),
            'name' => $units[0]['unit_name'] ?? $units[0]['name'] ?? 'قطعة',
        ] : null;
    }

    /**
     * Calculate profit margin percentage
     * Formula: (Sale - Cost) / Sale * 100
     * @return float Percentage value
     */
    private static function calculateMargin(float $salePrice, float $costPrice): float
    {
        if ($salePrice <= 0) {
            return 0.0;
        }

        return round((($salePrice - $costPrice) / $salePrice) * 100, 2);
    }

    /**
     * Calculate profit markup percentage
     * Formula: (Sale - Cost) / Cost * 100
     * @return float Percentage value
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
