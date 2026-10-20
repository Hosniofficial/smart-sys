<?php

declare(strict_types=1);

use App\Services\SalePaymentService;
use PHPUnit\Framework\TestCase;

final class SalePaymentTransactionOwnershipTest extends TestCase
{
    public function testFailedPaymentKeepsCallerOwnedTransactionActive(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->beginTransaction();

        try {
            (new SalePaymentService($db))->addSalePayment(
                3,
                5,
                10.0,
                '2026-10-16',
                2,
                idempotencyKey: 'sale-payment-key'
            );
            self::fail('Expected the SQLite test connection to reject the MySQL row-lock syntax.');
        } catch (PDOException $e) {
            self::assertTrue($db->inTransaction());
            $db->rollBack();
        }
    }

    public function testFailedPaymentRollsBackTransactionItStarted(): void
    {
        $db = new PDO('sqlite::memory:');

        try {
            (new SalePaymentService($db))->addSalePayment(
                3,
                5,
                10.0,
                '2026-10-16',
                2,
                idempotencyKey: 'sale-payment-key'
            );
            self::fail('Expected the SQLite test connection to reject the MySQL row-lock syntax.');
        } catch (PDOException $e) {
            self::assertFalse($db->inTransaction());
        }
    }

    public function testFailedDebtPaymentKeepsCallerOwnedTransactionActive(): void
    {
        $db = $this->createDebtPaymentDatabase();
        $db->beginTransaction();

        try {
            (new SalePaymentService($db))->payCustomerDebt(3, 5, 10.0, 2);
            self::fail('Expected the SQLite test connection to reject the MySQL row-lock syntax.');
        } catch (PDOException $e) {
            self::assertTrue($db->inTransaction());
            $db->rollBack();
        }
    }

    public function testFailedDebtPaymentRollsBackTransactionItStarted(): void
    {
        $db = $this->createDebtPaymentDatabase();

        try {
            (new SalePaymentService($db))->payCustomerDebt(3, 5, 10.0, 2);
            self::fail('Expected the SQLite test connection to reject the MySQL row-lock syntax.');
        } catch (PDOException $e) {
            self::assertFalse($db->inTransaction());
        }
    }

    private function createDebtPaymentDatabase(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE customers (id INTEGER, tenant_id INTEGER, account_id INTEGER)');
        $db->exec('INSERT INTO customers VALUES (5, 3, 9)');

        return $db;
    }
}
