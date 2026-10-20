<?php

declare(strict_types=1);

use App\Services\AccountingService;
use PHPUnit\Framework\TestCase;

final class AccountingDebtPaymentIdempotencyTest extends TestCase
{
    public function testIdenticalPaymentsWithoutRequestKeysGetDistinctJournalIdentity(): void
    {
        [$db, $service] = $this->createService();

        $first = $service->postDebtPaymentJournalEntry(3, 12, 74, 100, '2026-10-06', 2, null, 4, 101);
        $second = $service->postDebtPaymentJournalEntry(3, 12, 74, 100, '2026-10-06', 2, null, 4, 102);

        self::assertNotSame($first, $second);
        self::assertSame(2, (int) $db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn());
        self::assertSame(200.0, (float) $db->query(
            'SELECT SUM(credit_amount) FROM journal_entry_lines WHERE account_id = 74'
        )->fetchColumn());
    }

    public function testSameRequestKeyKeepsTheSameJournalIdentityAcrossRetry(): void
    {
        [$db, $service] = $this->createService();

        $first = $service->postDebtPaymentJournalEntry(3, 12, 74, 100, '2026-10-06', 2, null, 4, 101, 'request-123');
        $retry = $service->postDebtPaymentJournalEntry(3, 12, 74, 100, '2026-10-06', 2, null, 4, 101, 'request-123');

        self::assertSame($first, $retry);
        self::assertSame(1, (int) $db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn());
        self::assertSame(100.0, (float) $db->query(
            'SELECT SUM(credit_amount) FROM journal_entry_lines WHERE account_id = 74'
        )->fetchColumn());
    }

    /**
     * @return array{PDO, AccountingService}
     */
    private function createService(): array
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'));
        $db->exec("
            CREATE TABLE payment_methods (
                id INTEGER PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                kind TEXT NOT NULL,
                account_id INTEGER NOT NULL
            );
            INSERT INTO payment_methods (id, tenant_id, kind, account_id) VALUES (2, 3, 'cash', 10);
            CREATE TABLE accounting_periods (
                tenant_id INTEGER NOT NULL,
                status TEXT NOT NULL,
                start_date TEXT NOT NULL,
                end_date TEXT NOT NULL,
                period_name TEXT NOT NULL
            );
            CREATE TABLE journal_entries (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                entry_date TEXT NOT NULL,
                description TEXT NOT NULL,
                reference_type TEXT NOT NULL,
                reference_id INTEGER,
                created_by INTEGER,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                status TEXT NOT NULL,
                cost_center_id INTEGER,
                idempotency_key TEXT,
                UNIQUE (tenant_id, reference_type, idempotency_key),
                UNIQUE (tenant_id, reference_type, reference_id)
            );
            CREATE TABLE journal_entry_lines (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                journal_entry_id INTEGER NOT NULL,
                account_id INTEGER NOT NULL,
                debit_amount REAL NOT NULL,
                credit_amount REAL NOT NULL,
                description TEXT NOT NULL,
                tenant_id INTEGER NOT NULL,
                cost_center_id INTEGER
            );
        ");

        return [$db, new AccountingService($db)];
    }
}
