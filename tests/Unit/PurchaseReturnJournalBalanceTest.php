<?php

declare(strict_types=1);

use App\Services\AccountingService;

use PHPUnit\Framework\TestCase;

final class PurchaseReturnJournalBalanceTest extends TestCase
{
    public function testFullyRefundedPurchaseReturnBalances(): void
    {
        $this->assertPurchaseReturnBalances(10000.0, 10000.0, 0.0);
    }

    public function testPartiallyRefundedPurchaseReturnBalances(): void
    {
        $this->assertPurchaseReturnBalances(10000.0, 4000.0, 6000.0);
    }

    public function testUnrefundedPurchaseReturnBalancesOnSupplierAccount(): void
    {
        $this->assertPurchaseReturnBalances(10000.0, 0.0, 10000.0);
    }

    private function assertPurchaseReturnBalances(
        float $returnAmount,
        float $refundedAmount,
        float $expectedSupplierDebit
    ): void {
        $db = $this->database();
        $service = new AccountingService($db);

        $journalEntryId = $service->postReturnJournalEntry(
            returnId: 1,
            tenantId: 3,
            returnDate: '2026-09-27',
            returnNumber: 'PR-TEST-001',
            returnType: 'purchase',
            partyId: 2,
            totalAmount: $returnAmount,
            paidAmount: $refundedAmount,
            paymentMethodId: 1,
            taxAmount: 0.0,
            userId: 1,
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

        self::assertEqualsWithDelta($returnAmount, $totalDebit, 0.001);
        self::assertEqualsWithDelta($returnAmount, $totalCredit, 0.001);

        $debitsByAccount = [];
        $creditsByAccount = [];
        foreach ($lines as $line) {
            $accountId = (int) $line['account_id'];
            $debitsByAccount[$accountId] = ($debitsByAccount[$accountId] ?? 0.0) + (float) $line['debit_amount'];
            $creditsByAccount[$accountId] = ($creditsByAccount[$accountId] ?? 0.0) + (float) $line['credit_amount'];
        }

        self::assertEqualsWithDelta($returnAmount, $creditsByAccount[72] ?? 0.0, 0.001, 'Inventory must be credited for the returned goods.');
        self::assertEqualsWithDelta($expectedSupplierDebit, $debitsByAccount[73] ?? 0.0, 0.001, 'Unrefunded amount must remain as a debit supplier balance.');
        self::assertEqualsWithDelta($refundedAmount, $debitsByAccount[50] ?? 0.0, 0.001, 'Cash/bank refund received must be debited.');
    }

    private function database(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'));

        $db->exec('CREATE TABLE accounts (id INTEGER PRIMARY KEY, tenant_id INTEGER, code TEXT NOT NULL)');
        $db->exec('CREATE TABLE branches (id INTEGER PRIMARY KEY, tenant_id INTEGER, account_id INTEGER NULL)');
        $db->exec('CREATE TABLE settings (tenant_id INTEGER NULL, key_name TEXT NOT NULL, value TEXT, updated_at TEXT)');
        $db->exec('CREATE TABLE suppliers (id INTEGER PRIMARY KEY, tenant_id INTEGER, account_id INTEGER NULL)');
        $db->exec('CREATE TABLE payment_methods (id INTEGER PRIMARY KEY, tenant_id INTEGER, kind TEXT, account_id INTEGER)');
        $db->exec('CREATE TABLE returns (id INTEGER PRIMARY KEY, tenant_id INTEGER, branch_id INTEGER)');
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
            (50, 3, '1001'), (72, 3, '1301-0001'), (73, 3, '21010001'),
            (2101, 3, '2101'), (5002, 3, '5002'), (5103, 3, '5103')");
        $db->exec("INSERT INTO branches (id, tenant_id, account_id) VALUES (4, 3, 72)");
        $db->exec("INSERT INTO suppliers (id, tenant_id, account_id) VALUES (2, 3, 73)");
        $db->exec("INSERT INTO payment_methods (id, tenant_id, kind, account_id) VALUES (1, 3, 'cash', 50)");
        $db->exec("INSERT INTO returns (id, tenant_id, branch_id) VALUES (1, 3, 4)");
        $db->exec("INSERT INTO inventory_transactions (tenant_id, reference_type, reference_id) VALUES (3, 'return', 1)");

        return $db;
    }
}
