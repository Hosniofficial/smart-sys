<?php

declare(strict_types=1);

use App\Services\SalesOutstandingBalance;
use PHPUnit\Framework\TestCase;

final class SalesOutstandingBalanceTest extends TestCase
{
    public function testCashRefundDoesNotIncreaseCustomerCredit(): void
    {
        $db = $this->database();
        $db->exec("INSERT INTO sales VALUES (1, 3, 900, 900, 0)");
        $db->exec("INSERT INTO payments VALUES
            (1, 3, 1, 900, 'sale', 'completed', 0),
            (2, 3, 1, 900, 'return_payment', 'completed', 0)");

        self::assertEquals(0, $this->outstanding($db, 1));
    }

    public function testRemainingCreditKeepsExistingBalanceAndReturnCreditAllocations(): void
    {
        $db = $this->database();
        $db->exec("INSERT INTO sales VALUES (1, 3, 1500, 1500, 0), (2, 3, 1000, 1000, 0)");
        $db->exec("INSERT INTO payments VALUES
            (1, 3, 1, 1000, 'sale', 'completed', 0),
            (2, 3, 1, 900, 'return_payment', 'completed', 0)");
        $db->exec("INSERT INTO return_credit_allocations VALUES (3, 2, 100)");

        self::assertEquals(500, $this->outstanding($db, 1));
        self::assertEquals(900, $this->outstanding($db, 2));
    }

    public function testOutstandingUsesDiscountedInvoiceTotal(): void
    {
        $db = $this->database();
        $db->exec('INSERT INTO sales VALUES (1, 3, 1000, 800, 0)');

        self::assertEquals(800, $this->outstanding($db, 1));
    }

    private function outstanding(PDO $db, int $saleId): float
    {
        $sql = SalesOutstandingBalance::sqlExpression();
        $stmt = $db->prepare("SELECT MAX(0, {$sql}) FROM sales s WHERE s.id = ?");
        $stmt->execute([$saleId]);

        return (float) $stmt->fetchColumn();
    }

    private function database(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE sales (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            total_amount REAL NOT NULL,
            net_total_amount REAL NOT NULL,
            tax_amount REAL NOT NULL DEFAULT 0
        )');
        $db->exec('CREATE TABLE payments (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            sale_id INTEGER NOT NULL,
            amount REAL NOT NULL,
            type TEXT NOT NULL,
            status TEXT NOT NULL,
            is_draft INTEGER NOT NULL DEFAULT 0
        )');
        $db->exec('CREATE TABLE customer_payment_allocations (
            tenant_id INTEGER NOT NULL,
            payment_id INTEGER NOT NULL,
            sale_id INTEGER NOT NULL,
            allocated_amount REAL NOT NULL
        )');
        $db->exec('CREATE TABLE return_credit_allocations (
            tenant_id INTEGER NOT NULL,
            sale_id INTEGER NOT NULL,
            allocated_amount REAL NOT NULL
        )');

        return $db;
    }
}
