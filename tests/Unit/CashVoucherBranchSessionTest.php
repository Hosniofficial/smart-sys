<?php

declare(strict_types=1);

use App\Handlers\CashVouchersHandler;
use App\Services\CashVoucherService;
use PHPUnit\Framework\TestCase;

final class CashVoucherBranchSessionTest extends TestCase
{
    public function testCashTransactionKeepsVoucherBranchAndSessionForLaterEdits(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec(
            'CREATE TABLE cash_transactions (
                tenant_id INTEGER,
                customer_id INTEGER NULL,
                supplier_id INTEGER NULL,
                amount REAL,
                type TEXT,
                reference_type TEXT,
                reference_id INTEGER,
                payment_method_id INTEGER,
                description TEXT NULL,
                created_by INTEGER NULL,
                created_at TEXT,
                return_id INTEGER NULL,
                status TEXT,
                notes TEXT NULL,
                journal_entry_id INTEGER,
                session_id INTEGER NULL,
                cost_center_id INTEGER NULL,
                branch_id INTEGER NULL
            )'
        );

        $service = (new ReflectionClass(CashVoucherService::class))->newInstanceWithoutConstructor();
        $this->setPrivateProperty($service, CashVoucherService::class, 'db', $db);
        $this->setPrivateProperty($service, CashVoucherService::class, 'tenantId', 4);
        $this->setPrivateProperty($service, CashVoucherService::class, 'userId', 8);

        $method = new ReflectionMethod(CashVoucherService::class, 'recordCashTransaction');
        $method->setAccessible(true);
        $method->invoke($service, 20, 30, 40, [
            'customer_id' => 12,
            'supplier_id' => null,
            'amount' => 125,
            'type' => 'receipt',
            'payment_method_id' => 2,
            'date' => '2026-10-02',
            'return_id' => null,
            'status' => 'cancelled',
            'cost_center_id' => 3,
            'branch_id' => 6,
        ], 'voucher note');

        $transaction = $db->query(
            'SELECT branch_id, session_id, reference_id, status FROM cash_transactions LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame(6, (int) $transaction['branch_id']);
        self::assertSame(40, (int) $transaction['session_id']);
        self::assertSame(20, (int) $transaction['reference_id']);
        self::assertSame('completed', $transaction['status']);
    }

    public function testVoucherBranchMustBelongToTheCurrentTenant(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE branches (id INTEGER PRIMARY KEY, tenant_id INTEGER)');
        $db->exec('INSERT INTO branches (id, tenant_id) VALUES (6, 4), (7, 5)');

        $handler = (new ReflectionClass(CashVouchersHandler::class))->newInstanceWithoutConstructor();
        $this->setPrivateProperty($handler, App\Handlers\BaseHandler::class, 'db', $db);
        $method = new ReflectionMethod(CashVouchersHandler::class, 'validateVoucherBranch');
        $method->setAccessible(true);

        self::assertNull($method->invoke($handler, 6, 4));
        self::assertSame(403, $method->invoke($handler, 7, 4)['status']);
        self::assertSame(400, $method->invoke($handler, 'invalid', 4)['status']);
        self::assertNull($method->invoke($handler, null, 4));
    }

    private function setPrivateProperty(object $object, string $class, string $name, mixed $value): void
    {
        $property = new ReflectionProperty($class, $name);
        $property->setAccessible(true);
        $property->setValue($object, $value);
    }
}
