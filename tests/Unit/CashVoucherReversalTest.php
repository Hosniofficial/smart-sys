<?php

declare(strict_types=1);

use App\Services\CashVoucherService;
use PHPUnit\Framework\TestCase;

final class CashVoucherReversalTest extends TestCase
{
    public function testVoucherReversalUsesLinkedAccountingReversalAndIsIdempotent(): void
    {
        $db = $this->database();
        $db->exec("INSERT INTO cash_vouchers (id, tenant_id, journal_entry_id) VALUES (20, 4, 10)");
        $db->exec(
            "INSERT INTO journal_entries
                (id, tenant_id, entry_date, description, reference_type, reference_id, status, cost_center_id)
             VALUES (10, 4, '2026-09-30', 'Voucher #20', 'cash_voucher', 20, 'posted', 3)"
        );
        $db->exec(
            "INSERT INTO journal_entry_lines
                (journal_entry_id, account_id, debit_amount, credit_amount, description, tenant_id, cost_center_id)
             VALUES (10, 100, 125, 0, 'Cash', 4, 3), (10, 400, 0, 125, 'Party', 4, 3)"
        );

        $service = new CashVoucherService($db, 4, 8);
        $service->reverseJournalEntryByVoucherIdIfNeeded(20);
        $service->reverseJournalEntryByVoucherIdIfNeeded(20);

        self::assertSame('reversed', $db->query('SELECT status FROM journal_entries WHERE id = 10')->fetchColumn());
        self::assertSame(
            1,
            (int) $db->query("SELECT COUNT(*) FROM journal_entries WHERE reference_type = 'reversal'")->fetchColumn()
        );
    }

    public function testRefusesAnUnlinkedLegacyReversalInsteadOfPostingAnother(): void
    {
        $db = $this->database();
        $db->exec("INSERT INTO cash_vouchers (id, tenant_id, journal_entry_id) VALUES (20, 4, 10)");
        $db->exec(
            "INSERT INTO journal_entries
                (id, tenant_id, entry_date, description, reference_type, reference_id, status, cost_center_id)
             VALUES
                (10, 4, '2026-09-30', 'Voucher #20', 'cash_voucher', 20, 'posted', 3),
                (11, 4, '2026-09-30', 'Old reversal', 'reversal', 10, 'posted', 3)"
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('قيد عكس قديم غير مرتبط');
        (new CashVoucherService($db, 4, 8))->reverseJournalEntryByVoucherIdIfNeeded(20);
    }

    public function testClosedAccountingPeriodIsReportedAsAConflictAndLeavesVoucherUnchanged(): void
    {
        $db = $this->database();
        $db->exec("INSERT INTO cash_vouchers (id, tenant_id, journal_entry_id) VALUES (20, 4, 10)");
        $db->exec(
            "INSERT INTO journal_entries
                (id, tenant_id, entry_date, description, reference_type, reference_id, status, cost_center_id)
             VALUES (10, 4, '2026-09-30', 'Voucher #20', 'cash_voucher', 20, 'posted', 3)"
        );
        $db->exec(
            "INSERT INTO journal_entry_lines
                (journal_entry_id, account_id, debit_amount, credit_amount, description, tenant_id, cost_center_id)
             VALUES (10, 100, 125, 0, 'Cash', 4, 3)"
        );
        $today = date('Y-m-d');
        $period = $db->prepare(
            "INSERT INTO accounting_periods (tenant_id, period_name, status, start_date, end_date)
             VALUES (4, 'Closed current period', 'closed', ?, ?)"
        );
        $period->execute([$today, $today]);

        try {
            (new CashVoucherService($db, 4, 8))->reverseJournalEntryByVoucherIdIfNeeded(20);
            self::fail('Expected reversal in a closed period to be rejected.');
        } catch (App\Exceptions\AccountingPeriodClosedException $e) {
            self::assertStringContainsString('Closed current period', $e->getMessage());
        }

        self::assertSame('posted', $db->query('SELECT status FROM journal_entries WHERE id = 10')->fetchColumn());
        self::assertSame(
            0,
            (int) $db->query("SELECT COUNT(*) FROM journal_entries WHERE reference_type = 'reversal'")->fetchColumn()
        );
    }

    private function database(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->sqliteCreateFunction('NOW', static fn (): string => date('Y-m-d H:i:s'));
        $db->exec('CREATE TABLE cash_vouchers (id INTEGER, tenant_id INTEGER, journal_entry_id INTEGER)');
        $db->exec(
            'CREATE TABLE journal_entries (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                entry_date TEXT,
                description TEXT,
                reference_type TEXT,
                reference_id INTEGER,
                status TEXT,
                reversal_of INTEGER,
                cost_center_id INTEGER,
                created_by INTEGER,
                created_at TEXT,
                idempotency_key TEXT
            )'
        );
        $db->exec(
            'CREATE TABLE journal_entry_lines (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                journal_entry_id INTEGER NOT NULL,
                account_id INTEGER NOT NULL,
                debit_amount REAL NOT NULL,
                credit_amount REAL NOT NULL,
                description TEXT,
                tenant_id INTEGER NOT NULL,
                cost_center_id INTEGER
            )'
        );
        $db->exec(
            'CREATE TABLE accounting_periods (
                tenant_id INTEGER,
                period_name TEXT,
                status TEXT,
                start_date TEXT,
                end_date TEXT
            )'
        );

        return $db;
    }
}
