<?php

declare(strict_types=1);

use App\Services\AccountingService;
use App\Exceptions\AccountingPeriodClosedException;
use PHPUnit\Framework\TestCase;

final class JournalEntryReversalTest extends TestCase
{
    public function testReversesOriginalLinesAndCanBeRetriedIdempotently(): void
    {
        $db = $this->database();
        $db->exec(
            "INSERT INTO journal_entries
                (id, tenant_id, entry_date, description, reference_type, reference_id, status, cost_center_id)
             VALUES (10, 4, '2026-09-30', 'Sale #20', 'sale', 20, 'posted', 3)"
        );
        $db->exec(
            "INSERT INTO journal_entry_lines
                (journal_entry_id, account_id, debit_amount, credit_amount, description, tenant_id, cost_center_id)
             VALUES
                (10, 100, 125, 0, 'Receivable', 4, 3),
                (10, 400, 0, 125, 'Revenue', 4, 3)"
        );

        $accounting = new AccountingService($db);
        $reversalId = $accounting->reverseJournalEntry(10, 4, 8);

        self::assertSame($reversalId, $accounting->reverseJournalEntry(10, 4, 8));
        self::assertSame(
            'reversed',
            $db->query('SELECT status FROM journal_entries WHERE id = 10')->fetchColumn()
        );
        self::assertSame(
            $reversalId,
            (int) $db->query('SELECT reversal_of FROM journal_entries WHERE id = 10')->fetchColumn()
        );

        $reversal = $db->query(
            "SELECT reference_type, reference_id, reversal_of, status
             FROM journal_entries WHERE id = {$reversalId}"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame('reversal', $reversal['reference_type']);
        self::assertSame(10, (int) $reversal['reference_id']);
        self::assertSame(10, (int) $reversal['reversal_of']);
        self::assertSame('posted', $reversal['status']);

        $lines = $db->query(
            "SELECT account_id, debit_amount, credit_amount
             FROM journal_entry_lines WHERE journal_entry_id = {$reversalId} ORDER BY account_id"
        )->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame([
            ['account_id' => 100, 'debit_amount' => 0.0, 'credit_amount' => 125.0],
            ['account_id' => 400, 'debit_amount' => 125.0, 'credit_amount' => 0.0],
        ], $lines);
    }

    public function testRejectsReversalInAClosedCurrentPeriod(): void
    {
        $db = $this->database();
        $db->exec(
            "INSERT INTO journal_entries
                (id, tenant_id, entry_date, description, reference_type, reference_id, status, cost_center_id)
             VALUES (10, 4, '2026-09-30', 'Sale #20', 'sale', 20, 'posted', 3)"
        );
        $db->exec(
            "INSERT INTO journal_entry_lines
                (journal_entry_id, account_id, debit_amount, credit_amount, description, tenant_id, cost_center_id)
             VALUES (10, 100, 125, 0, 'Receivable', 4, 3)"
        );
        $today = date('Y-m-d');
        $period = $db->prepare(
            "INSERT INTO accounting_periods (tenant_id, period_name, status, start_date, end_date)
             VALUES (4, 'Closed current period', 'closed', ?, ?)"
        );
        $period->execute([$today, $today]);

        try {
            (new AccountingService($db))->reverseJournalEntry(10, 4, 8);
            self::fail('Expected reversal in a closed period to be rejected.');
        } catch (AccountingPeriodClosedException $e) {
            self::assertStringContainsString('لا يمكن تسجيل قيد في دورة مغلقة', $e->getMessage());
        }

        self::assertSame(
            'posted',
            $db->query('SELECT status FROM journal_entries WHERE id = 10')->fetchColumn()
        );
        self::assertSame(
            0,
            (int) $db->query('SELECT COUNT(*) FROM journal_entries WHERE reference_type = \'reversal\'')->fetchColumn()
        );
    }

    private function database(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->sqliteCreateFunction('NOW', static fn (): string => date('Y-m-d H:i:s'));
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
