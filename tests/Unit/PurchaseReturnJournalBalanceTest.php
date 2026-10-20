<?php

declare(strict_types=1);

use App\Services\AccountingService;
use PHPUnit\Framework\TestCase;

final class PurchaseReturnJournalBalanceTest extends TestCase
{
    public function testCashModeReturnPostsBalancedCashAndInventoryLines(): void
    {
        $this->assertPurchaseReturnBalances('cash', 10000.0, 0.0, 10000.0);
    }

    public function testAutoModeOffsetsSupplierPayableAndRefundsRemainder(): void
    {
        $this->assertPurchaseReturnBalances('auto', 7000.0, 3000.0, 7000.0);
    }

    public function testCreditNoteModeLeavesFullAmountOnSupplierAccount(): void
    {
        $this->assertPurchaseReturnBalances('credit_note', 0.0, 10000.0, 0.0);
    }

    private function assertPurchaseReturnBalances(
        string $refundMode,
        float $paidAmount,
        float $supplierDebit,
        float $cashDebit
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
            totalAmount: 10000.0,
            paidAmount: $paidAmount,
            paymentMethodId: 1,
            taxAmount: 0.0,
            userId: 1,
            refundMode: $refundMode,
            costCenterId: 1
        );

        self::assertGreaterThan(0, $journalEntryId);
        $stmt = $db->prepare(
            'SELECT account_id, debit_amount, credit_amount
             FROM journal_entry_lines WHERE journal_entry_id = ? AND tenant_id = ?'
        );
        $stmt->execute([$journalEntryId, 3]);
        $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalDebit = array_sum(array_map(static fn(array $line): float => (float) $line['debit_amount'], $lines));
        $totalCredit = array_sum(array_map(static fn(array $line): float => (float) $line['credit_amount'], $lines));
        self::assertEqualsWithDelta($totalDebit, $totalCredit, 0.001);
        self::assertEqualsWithDelta(10000.0, $totalCredit, 0.001);

        $accountDebits = [];
        $accountCredits = [];
        foreach ($lines as $line) {
            $accountId = (int) $line['account_id'];
            $accountDebits[$accountId] = ($accountDebits[$accountId] ?? 0.0) + (float) $line['debit_amount'];
            $accountCredits[$accountId] = ($accountCredits[$accountId] ?? 0.0) + (float) $line['credit_amount'];
        }
        self::assertEqualsWithDelta($supplierDebit, $accountDebits[73] ?? 0.0, 0.001);
        self::assertEqualsWithDelta($cashDebit, $accountDebits[50] ?? 0.0, 0.001);
        self::assertEqualsWithDelta(10000.0, $accountCredits[72] ?? 0.0, 0.001);

        $entry = $db->query('SELECT status FROM journal_entries WHERE id = ' . (int) $journalEntryId)->fetchColumn();
        self::assertSame('posted', $entry);
        $linkedEntryId = $db->query('SELECT journal_entry_id FROM inventory_transactions WHERE id = 1')->fetchColumn();
        self::assertSame((string) $journalEntryId, (string) $linkedEntryId);
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
            (50, 3, '1001'), (72, 3, '1301-0001'), (73, 3, '2101-S'),
            (2101, 3, '2101'), (5002, 3, '5002'), (2201, 3, '2201'), (2202, 3, '2202')");
        $db->exec('INSERT INTO branches (id, tenant_id, account_id) VALUES (4, 3, 72)');
        $db->exec('INSERT INTO suppliers (id, tenant_id, account_id) VALUES (2, 3, 73)');
        $db->exec("INSERT INTO payment_methods (id, tenant_id, kind, account_id) VALUES (1, 3, 'cash', 50)");
        $db->exec('INSERT INTO returns (id, tenant_id, branch_id) VALUES (1, 3, 4)');
        $db->exec("INSERT INTO inventory_transactions (id, tenant_id, reference_type, reference_id)
            VALUES (1, 3, 'return', 1)");

        return $db;
    }
}
