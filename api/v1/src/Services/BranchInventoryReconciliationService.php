<?php

declare(strict_types=1);

namespace App\Services;

use LogicException;
use PDO;

final class BranchInventoryReconciliationService
{
    public static function markUnreconciled(PDO $db, int $tenantId, int $branchId, int $productId): void
    {
        if (!$db->inTransaction()) {
            throw new LogicException('Stock reconciliation state must be updated within the stock operation transaction.');
        }

        $stmt = $db->prepare(
            'UPDATE branch_products
             SET gl_reconciled = 0
             WHERE tenant_id = ? AND branch_id = ? AND product_id = ? AND gl_reconciled = 1'
        );
        $stmt->execute([$tenantId, $branchId, $productId]);
    }
}
