<?php

declare(strict_types=1);

use App\Services\CustomerPaymentAllocationService;
use PHPUnit\Framework\TestCase;

final class CustomerPaymentAllocationServiceTest extends TestCase
{
    public function testAllocatesAcrossOpenInvoicesInFifoOrderWithinPaymentBranch(): void
    {
        $pdo = $this->createDatabase();
        $pdo->exec("INSERT INTO sales
            (id, tenant_id, customer_id, branch_id, net_total_amount, tax_amount, status, sale_date)
            VALUES
            (11, 4, 3, 1, 100, 0, 'pending_payment', '2026-10-01'),
            (12, 4, 3, 1, 100, 0, 'pending_payment', '2026-10-02'),
            (13, 4, 3, 2, 100, 0, 'pending_payment', '2026-10-03'),
            (14, 4, 3, 1, 100, 0, 'canceled', '2026-10-04')");
        $pdo->exec("INSERT INTO payments
            (id, tenant_id, customer_id, sale_id, amount, status, is_draft, type, payment_date)
            VALUES (21, 4, 3, 11, 40, 'completed', 0, 'sale', '2026-10-01')");
        $pdo->exec("INSERT INTO payments
            (id, tenant_id, customer_id, sale_id, amount, status, is_draft, type, payment_date)
            VALUES (31, 4, 3, NULL, 120, 'completed', 0, 'customer', '2026-10-02')");

        $pdo->beginTransaction();
        $saleIds = (new CustomerPaymentAllocationService($pdo))->allocatePayment(4, 3, 31, 120, 1);
        $pdo->commit();

        self::assertSame([11, 12], $saleIds);
        $allocations = $pdo->query(
            'SELECT sale_id, allocated_amount FROM customer_payment_allocations ORDER BY sale_id'
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        self::assertEquals([11 => 60.0, 12 => 60.0], $allocations);
    }

    public function testDoesNotAllocateAnOverpaymentAgainOnRetry(): void
    {
        $pdo = $this->createDatabase();
        $pdo->exec("INSERT INTO sales
            (id, tenant_id, customer_id, branch_id, net_total_amount, tax_amount, status)
            VALUES (11, 4, 3, 1, 100, 0, 'pending_payment')");
        $pdo->exec("INSERT INTO payments
            (id, tenant_id, customer_id, sale_id, amount, status, is_draft, type, payment_date)
            VALUES (31, 4, 3, NULL, 140, 'completed', 0, 'customer', '2026-10-02')");

        $pdo->beginTransaction();
        $allocator = new CustomerPaymentAllocationService($pdo);
        $first = $allocator->allocatePayment(4, 3, 31, 140, 1);
        $second = $allocator->allocatePayment(4, 3, 31, 140, 1);
        $pdo->commit();

        self::assertSame([11], $first);
        self::assertSame([11], $second);
        self::assertEquals(100.0, (float) $pdo->query(
            'SELECT SUM(allocated_amount) FROM customer_payment_allocations'
        )->fetchColumn());
    }

    private function createDatabase(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE customers (id INTEGER, tenant_id INTEGER)');
        $pdo->exec("INSERT INTO customers VALUES (3, 4)");
        $pdo->exec('CREATE TABLE sales (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER,
            customer_id INTEGER,
            branch_id INTEGER,
            net_total_amount NUMERIC,
            tax_amount NUMERIC,
            status TEXT,
            sale_date TEXT DEFAULT CURRENT_TIMESTAMP
        )');
        $pdo->exec('CREATE TABLE payments (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER,
            customer_id INTEGER,
            sale_id INTEGER,
            amount NUMERIC,
            status TEXT,
            is_draft INTEGER,
            type TEXT,
            payment_date TEXT
        )');
        $pdo->exec('CREATE TABLE return_credit_allocations (
            tenant_id INTEGER,
            sale_id INTEGER,
            allocated_amount NUMERIC
        )');
        $pdo->exec('CREATE TABLE customer_payment_allocations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER,
            payment_id INTEGER,
            sale_id INTEGER,
            allocated_amount NUMERIC,
            UNIQUE (tenant_id, payment_id, sale_id)
        )');
        return $pdo;
    }
}
