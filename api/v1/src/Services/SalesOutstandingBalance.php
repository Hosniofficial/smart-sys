<?php

declare(strict_types=1);

namespace App\Services;

/** Builds the SQL expression for a sale's remaining customer balance. */
final class SalesOutstandingBalance
{
    /** Return a correlated expression; the alias must be a trusted SQL identifier. */
    public static function sqlExpression(string $salesAlias = 's'): string
    {
        return "({$salesAlias}.net_total_amount + COALESCE({$salesAlias}.tax_amount, 0))
            - COALESCE((
                SELECT SUM(p.amount)
                FROM payments p
                WHERE p.sale_id = {$salesAlias}.id
                  AND p.tenant_id = {$salesAlias}.tenant_id
                  AND p.status = 'completed'
                  AND COALESCE(p.is_draft, 0) = 0
                  AND COALESCE(p.type, '') <> 'return_payment'
            ), 0)
            - COALESCE((
                SELECT SUM(cpa.allocated_amount)
                FROM customer_payment_allocations cpa
                JOIN payments ap ON ap.id = cpa.payment_id AND ap.tenant_id = cpa.tenant_id
                WHERE cpa.sale_id = {$salesAlias}.id
                  AND cpa.tenant_id = {$salesAlias}.tenant_id
                  AND ap.status = 'completed'
                  AND COALESCE(ap.is_draft, 0) = 0
                  AND COALESCE(ap.type, '') <> 'return_payment'
            ), 0)
            - COALESCE((
                SELECT SUM(rca.allocated_amount)
                FROM return_credit_allocations rca
                WHERE rca.sale_id = {$salesAlias}.id
                  AND rca.tenant_id = {$salesAlias}.tenant_id
            ), 0)";
    }
}
