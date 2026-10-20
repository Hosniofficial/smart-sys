<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use DomainException;

/** Enforces product activity, type, and branch-account mapping requirements for stock operations. */
final class ProductBranchActivationGuard
{
    private const OPERATIONAL_STATUSES = ['ACTIVE_IN_BRANCH', 'GL_POSTED', 'RECONCILED'];

    /** Require an active stock product with an operational mapping in the target branch. */
    public static function assertActiveForStock(PDO $db, int $tenantId, int $branchId, int $productId): void
    {
        $product = self::findProduct($db, $tenantId, $branchId, $productId);
        self::assertProductActive($product, $productId);

        if (strtolower((string) ($product['product_type'] ?? 'stock')) !== 'stock') {
            throw new DomainException("المنتج #{$productId} من نوع خدمة ولا يقبل حركات مخزون.", 422);
        }

        self::assertOperationalMapping($product, $branchId, $productId);
    }

    /** Return whether a product is a sellable stock item; active services do not track inventory. */
    public static function assertSellableInBranch(PDO $db, int $tenantId, int $branchId, int $productId): bool
    {
        $product = self::findProduct($db, $tenantId, $branchId, $productId);
        self::assertProductActive($product, $productId);

        $productType = strtolower((string) ($product['product_type'] ?? 'stock'));
        if ($productType === 'service') {
            return false;
        }
        if ($productType !== 'stock') {
            throw new DomainException("المنتج #{$productId} من نوع غير مدعوم للبيع.", 422);
        }

        self::assertOperationalMapping($product, $branchId, $productId);
        return true;
    }

    /** Load the tenant product and its mapping for this branch, locking matched rows in a MySQL transaction. */
    private static function findProduct(PDO $db, int $tenantId, int $branchId, int $productId): ?array
    {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $forUpdate = $driver === 'mysql' && $db->inTransaction() ? 'FOR UPDATE' : '';
        $stmt = $db->prepare(
            "SELECT p.active, p.product_type, pbm.activation_status
             FROM products p
             LEFT JOIN product_branch_gl_mapping pbm
               ON pbm.tenant_id = p.tenant_id
              AND pbm.product_id = p.id
              AND pbm.branch_id = ?
             WHERE p.tenant_id = ? AND p.id = ?
             LIMIT 1
             {$forUpdate}"
        );
        $stmt->execute([$branchId, $tenantId, $productId]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        return $product ?: null;
    }

    private static function assertProductActive(?array $product, int $productId): void
    {
        if (!$product || (int) $product['active'] !== 1) {
            throw new DomainException("المنتج #{$productId} غير موجود أو موقوف في هذه المنشأة.", 422);
        }
    }

    private static function assertOperationalMapping(array $product, int $branchId, int $productId): void
    {
        $status = strtoupper((string) ($product['activation_status'] ?? 'DRAFT'));
        if (!in_array($status, self::OPERATIONAL_STATUSES, true)) {
            throw new DomainException(
                "المنتج #{$productId} غير مفعّل في الفرع #{$branchId}. فعّله واربط حساباته قبل حركة المخزون.",
                422
            );
        }
    }
}
