<?php

declare(strict_types=1);

use App\Repositories\PaymentRepository;
use App\Services\BalanceCalculationService;
use PHPUnit\Framework\TestCase;

final class PurchasePaymentNettingTest extends TestCase
{
    public function testSupplierRefundOffsetsPaymentsAndDraftPaymentsAreIgnored(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->exec("
            CREATE TABLE payments (
                purchase_id INTEGER,
                tenant_id INTEGER,
                amount NUMERIC,
                type TEXT,
                status TEXT,
                is_draft INTEGER
            )
        ");
        $insert = $db->prepare(
            'INSERT INTO payments (purchase_id, tenant_id, amount, type, status, is_draft)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([12, 3, 250, 'purchase', 'completed', 0]);
        $insert->execute([12, 3, 40, 'payment_for_return', 'completed', 0]);
        $insert->execute([12, 3, 500, 'purchase', 'completed', 1]);
        $insert->execute([12, 3, 75, 'purchase', 'pending', 0]);
        $insert->execute([12, 9, 30, 'purchase', 'completed', 0]);

        self::assertSame(210.0, (new PaymentRepository($db))->getTotalPaidForPurchase(12, 3));
    }

    public function testPurchaseDueUsesNetPaymentsReturnsAndStoredTaxInclusiveTotal(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->exec("
            CREATE TABLE purchases (
                id INTEGER,
                tenant_id INTEGER,
                total_amount NUMERIC,
                tax_amount NUMERIC
            );
            CREATE TABLE payments (
                purchase_id INTEGER,
                tenant_id INTEGER,
                amount NUMERIC,
                type TEXT,
                status TEXT,
                is_draft INTEGER
            );
            CREATE TABLE returns (
                purchase_id INTEGER,
                tenant_id INTEGER,
                return_type TEXT,
                grand_total NUMERIC
            );
        ");
        $db->exec("INSERT INTO purchases VALUES (12, 3, 1000, 150)");
        $db->exec("
            INSERT INTO payments VALUES
                (12, 3, 600, 'purchase', 'completed', 0),
                (12, 3, 100, 'payment_for_return', 'completed', 0)
        ");
        $db->exec("INSERT INTO returns VALUES (12, 3, 'purchase', 100)");

        self::assertSame(400.0, (new BalanceCalculationService($db))->getAmountDueBalance('purchase', 12, 3));
    }
}
