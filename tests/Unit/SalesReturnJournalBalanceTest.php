<?php

declare(strict_types=1);

use App\Services\AccountingService;
use PHPUnit\Framework\TestCase;

final class SalesReturnJournalBalanceTest extends TestCase
{
    public function testCashModeSaleReturnWithoutCustomerBalancesWithFullCashRefund(): void
    {
        $this->assertSaleReturnBalances(10000.0, 10000.0, null, 0.0);
    }

    public function testAutoModeSaleReturnBalancesAfterCustomerBalanceOffset(): void
    {
        $this->assertSaleReturnBalances(10000.0, 7000.0, 2, 3000.0);
    }

    public function testCreditNoteSaleReturnBalancesWithoutCashRefund(): void
    {
        $this->assertSaleReturnBalances(10000.0, 0.0, 2, 10000.0);
    }

    public function testDoesNotPostSaleReturnWhenCogsReversalFails(): void
    {
        $db = $this->database();
        $db->exec('DROP TABLE products');
        $service = new AccountingService($db);

        $this->expectException(\RuntimeException::class);
        $service->postReturnJournalEntry(
            returnId: 1,
            tenantId: 3,
            returnDate: '2026-09-27',
            returnNumber: 'SR-TEST-001',
            returnType: 'sale',
            partyId: 2,
            totalAmount: 10000.0,
            paidAmount: 0.0,
            paymentMethodId: 1,
            taxAmount: 0.0,
            userId: 1,
            deductFromCustomerBalance: 10000.0,
            originalInvoiceOutstanding: 0.0,
            costCenterId: 1
        );
    }

    private function assertSaleReturnBalances(
        float $returnAmount,
        float $cashRefund,
        ?int $customerId,
        float $expectedCustomerNetCredit
    ): void
    {
        $db = $this->database();
        $service = new AccountingService($db);

        $journalEntryId = $service->postReturnJournalEntry(
            returnId: 1,
            tenantId: 3,
            returnDate: '2026-09-27',
            returnNumber: 'SR-TEST-001',
            returnType: 'sale',
            partyId: $customerId,
            totalAmount: $returnAmount,
            paidAmount: $cashRefund,
            paymentMethodId: 1,
            taxAmount: 0.0,
            userId: 1,
            deductFromCustomerBalance: $returnAmount - $cashRefund,
            originalInvoiceOutstanding: 0.0,
            costCenterId: 1
        );

        self::assertGreaterThan(0, $journalEntryId);

        $stmt = $db->prepare(
            'SELECT account_id, debit_amount, credit_amount
             FROM journal_entry_lines
             WHERE journal_entry_id = ? AND tenant_id = ?'
        );
        $stmt->execute([$journalEntryId, 3]);
        $lines = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $totalDebit = array_sum(array_map(static fn(array $line): float => (float) $line['debit_amount'], $lines));
        $totalCredit = array_sum(array_map(static fn(array $line): float => (float) $line['credit_amount'], $lines));
        self::assertEqualsWithDelta($totalDebit, $totalCredit, 0.001);

        $cashCredit = 0.0;
        $customerNetCredit = 0.0;
        $inventoryDebit = 0.0;
        $cogsCredit = 0.0;
        foreach ($lines as $line) {
            $accountId = (int) $line['account_id'];
            if ($accountId === 50) {
                $cashCredit += (float) $line['credit_amount'];
            } elseif ($accountId === 73) {
                $customerNetCredit += (float) $line['credit_amount'] - (float) $line['debit_amount'];
            } elseif ($accountId === 72) {
                $inventoryDebit += (float) $line['debit_amount'];
            } elseif ($accountId === 5103) {
                $cogsCredit += (float) $line['credit_amount'];
            }
        }
        self::assertEqualsWithDelta($cashRefund, $cashCredit, 0.001);
        self::assertEqualsWithDelta($expectedCustomerNetCredit, $customerNetCredit, 0.001);
        if ($customerId === null) {
            self::assertSame([], array_values(array_filter(
                $lines,
                static fn(array $line): bool => in_array((int) $line['account_id'], [73, 1101], true)
            )));
        }
        self::assertEqualsWithDelta(1000.0, $inventoryDebit, 0.001);
        self::assertEqualsWithDelta(1000.0, $cogsCredit, 0.001);
    }

    private function database(): \PDO
    {
        $db = new \PDO('sqlite::memory:');
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $db->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'));

        $db->exec('CREATE TABLE accounts (id INTEGER PRIMARY KEY, tenant_id INTEGER, code TEXT NOT NULL)');
        $db->exec('CREATE TABLE products (
            id INTEGER PRIMARY KEY, tenant_id INTEGER, product_type TEXT, purchase_price NUMERIC
        )');
        $db->exec('CREATE TABLE branches (id INTEGER PRIMARY KEY, tenant_id INTEGER, account_id INTEGER NULL)');
        $db->exec('CREATE TABLE settings (tenant_id INTEGER NULL, key_name TEXT NOT NULL, value TEXT, updated_at TEXT)');
        $db->exec('CREATE TABLE customers (id INTEGER PRIMARY KEY, tenant_id INTEGER, account_id INTEGER NULL)');
        $db->exec('CREATE TABLE suppliers (id INTEGER PRIMARY KEY, tenant_id INTEGER, account_id INTEGER NULL)');
        $db->exec('CREATE TABLE payment_methods (id INTEGER PRIMARY KEY, tenant_id INTEGER, kind TEXT, account_id INTEGER)');
        $db->exec('CREATE TABLE returns (
            id INTEGER PRIMARY KEY, tenant_id INTEGER, branch_id INTEGER, sale_id INTEGER,
            return_type TEXT, return_date TEXT, status TEXT
        )');
        $db->exec('CREATE TABLE sales (id INTEGER PRIMARY KEY, tenant_id INTEGER, sale_date TEXT)');
        $db->exec('CREATE TABLE return_items (
            id INTEGER PRIMARY KEY, return_id INTEGER, tenant_id INTEGER, product_id INTEGER,
            unit_id INTEGER, quantity NUMERIC, unit_price NUMERIC
        )');
        $db->exec('CREATE TABLE product_units (
            product_id INTEGER, unit_id INTEGER, tenant_id INTEGER, conversion_factor NUMERIC
        )');
        $db->exec('CREATE TABLE purchases (
            id INTEGER PRIMARY KEY, tenant_id INTEGER, invoice_date TEXT, status TEXT
        )');
        $db->exec('CREATE TABLE purchase_items (
            id INTEGER PRIMARY KEY, purchase_id INTEGER, tenant_id INTEGER, product_id INTEGER,
            quantity NUMERIC, cost NUMERIC
        )');
        $db->exec('CREATE TABLE accounting_periods (tenant_id INTEGER, status TEXT, start_date TEXT, end_date TEXT, period_name TEXT)');
        $db->exec('CREATE TABLE journal_entries (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, entry_date TEXT, description TEXT,
            reference_type TEXT, reference_id INTEGER, created_by INTEGER, created_at TEXT,
            status TEXT, cost_center_id INTEGER, idempotency_key TEXT
        )');
        $db->exec('CREATE TABLE journal_entry_lines (
            id INTEGER PRIMARY KEY AUTOINCREMENT, journal_entry_id INTEGER, account_id INTEGER,
            debit_amount NUMERIC, credit_amount NUMERIC, description TEXT, tenant_id INTEGER, cost_center_id INTEGER
        )');
        $db->exec('CREATE TABLE inventory_transactions (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, reference_type TEXT,
            reference_id INTEGER, journal_entry_id INTEGER NULL
        )');

        $db->exec("INSERT INTO accounts (id, tenant_id, code) VALUES
            (50, 3, '1001'), (72, 3, '1301-0001'), (73, 3, '11010001'),
            (1101, 3, '1101'), (2101, 3, '2101'), (4002, 3, '4002'),
            (5002, 3, '5002'), (5103, 3, '5103'), (2201, 3, '2201'), (2202, 3, '2202')");
        $db->exec("INSERT INTO products (id, tenant_id, product_type, purchase_price)
            VALUES (6, 3, 'stock', 1000)");
        $db->exec('INSERT INTO branches (id, tenant_id, account_id) VALUES (4, 3, 72)');
        $db->exec('INSERT INTO customers (id, tenant_id, account_id) VALUES (2, 3, 73)');
        $db->exec("INSERT INTO payment_methods (id, tenant_id, kind, account_id) VALUES (1, 3, 'cash', 50)");
        $db->exec("INSERT INTO returns (id, tenant_id, branch_id, sale_id, return_type, return_date, status)
            VALUES (1, 3, 4, 1, 'sale', '2026-09-27', 'approved')");
        $db->exec("INSERT INTO sales (id, tenant_id, sale_date) VALUES (1, 3, '2026-09-26')");
        $db->exec('INSERT INTO return_items (id, return_id, tenant_id, product_id, unit_id, quantity, unit_price)
            VALUES (1, 1, 3, 6, 1, 1, 10000)');
        $db->exec('INSERT INTO product_units (product_id, unit_id, tenant_id, conversion_factor) VALUES (6, 1, 3, 1)');
        $db->exec("INSERT INTO purchases (id, tenant_id, invoice_date, status) VALUES (1, 3, '2026-01-01', 'posted')");
        $db->exec('INSERT INTO purchase_items (id, purchase_id, tenant_id, product_id, quantity, cost)
            VALUES (1, 1, 3, 6, 1, 1000)');
        $db->exec("INSERT INTO inventory_transactions (tenant_id, reference_type, reference_id) VALUES (3, 'return', 1)");

        return $db;
    }
}
