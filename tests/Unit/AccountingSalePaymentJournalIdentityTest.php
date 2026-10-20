<?php

declare(strict_types=1);

use App\Services\AccountingService;
use PHPUnit\Framework\TestCase;

final class AccountingSalePaymentJournalIdentityTest extends TestCase
{
    public function testEachReceiptForOneSaleGetsItsOwnJournalAndRetryReusesThatJournal(): void
    {
        [$db, $service] = $this->createService();

        $first = $service->postSalePaymentJournalEntry(
            3,
            201,
            101,
            50,
            '2026-10-07',
            2,
            null,
            4,
            'sale-payment-request-1'
        );
        $second = $service->postSalePaymentJournalEntry(
            3,
            201,
            102,
            100,
            '2026-10-07',
            2,
            null,
            4,
            'sale-payment-request-2'
        );
        $retry = $service->postSalePaymentJournalEntry(
            3,
            201,
            101,
            50,
            '2026-10-07',
            2,
            null,
            4,
            'sale-payment-request-1'
        );

        self::assertNotSame($first, $second);
        self::assertSame($first, $retry);
        self::assertSame(2, (int) $db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn());
        self::assertSame(
            [101, 102],
            array_map('intval', $db->query(
                "SELECT reference_id FROM journal_entries WHERE reference_type = 'customer_payment' ORDER BY reference_id"
            )->fetchAll(PDO::FETCH_COLUMN))
        );
        self::assertSame(150.0, (float) $db->query(
            'SELECT SUM(debit_amount) FROM journal_entry_lines'
        )->fetchColumn());
        self::assertSame(150.0, (float) $db->query(
            'SELECT SUM(credit_amount) FROM journal_entry_lines WHERE account_id = 74'
        )->fetchColumn());
        self::assertSame(0.0, (float) $db->query(
            'SELECT COALESCE(SUM(credit_amount), 0) FROM journal_entry_lines WHERE account_id = 75'
        )->fetchColumn());
    }

    public function testLegacyReceiptsWithIdenticalBusinessDetailsDoNotCollide(): void
    {
        [$db, $service] = $this->createService();

        $first = $service->postSalePaymentJournalEntry(
            3,
            201,
            101,
            50,
            '2026-10-07',
            2,
            null,
            4
        );
        $second = $service->postSalePaymentJournalEntry(
            3,
            201,
            102,
            50,
            '2026-10-07',
            2,
            null,
            4
        );

        self::assertNotSame($first, $second);
        self::assertSame(2, (int) $db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn());
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
            CREATE TABLE settings (
                tenant_id INTEGER,
                key_name TEXT NOT NULL,
                value TEXT,
                updated_at TEXT
            );
            CREATE TABLE accounts (
                id INTEGER PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                code TEXT NOT NULL
            );
            INSERT INTO accounts (id, tenant_id, code)
            VALUES (50, 3, '1001'), (74, 3, '11010001'), (75, 3, '1101');
            CREATE TABLE customers (
                id INTEGER PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                account_id INTEGER
            );
            INSERT INTO customers (id, tenant_id, account_id) VALUES (12, 3, 74);
            CREATE TABLE payment_methods (
                id INTEGER PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                kind TEXT NOT NULL,
                account_id INTEGER NOT NULL
            );
            INSERT INTO payment_methods (id, tenant_id, kind, account_id) VALUES (2, 3, 'cash', 50);
            CREATE TABLE sales (
                id INTEGER PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                customer_id INTEGER,
                invoice_number TEXT,
                net_total_amount REAL NOT NULL,
                tax_amount REAL NOT NULL DEFAULT 0,
                paid_amount REAL NOT NULL DEFAULT 0
            );
            INSERT INTO sales (id, tenant_id, customer_id, invoice_number, net_total_amount)
            VALUES (201, 3, 12, 'INV-201', 1000);
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
                UNIQUE (tenant_id, reference_type, reference_id),
                UNIQUE (tenant_id, reference_type, idempotency_key)
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
