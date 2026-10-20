<?php

declare(strict_types=1);

use App\Handlers\AccountStatementHandler;
use App\Services\BalanceCalculationService;
use PHPUnit\Framework\TestCase;

final class SupplierStatementReconciliationTest extends TestCase
{
    public function testSupplierReferencesUseStoredPurchaseTotalAndNetCompletedPayments(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->exec("
            CREATE TABLE purchases (
                id INTEGER, tenant_id INTEGER, supplier_id INTEGER, invoice_date TEXT,
                invoice_number TEXT, status TEXT, total_amount NUMERIC,
                tax_amount NUMERIC, discount_value NUMERIC, paid_amount NUMERIC,
                journal_entry_id INTEGER
            );
            CREATE TABLE payments (
                id INTEGER, tenant_id INTEGER, supplier_id INTEGER, purchase_id INTEGER,
                return_id INTEGER, created_at TEXT, reference_number TEXT, amount NUMERIC,
                journal_entry_id INTEGER, is_draft INTEGER, status TEXT, type TEXT
            );
            CREATE TABLE returns (
                id INTEGER, tenant_id INTEGER, supplier_id INTEGER, purchase_id INTEGER,
                return_type TEXT, created_at TEXT, return_number TEXT, invoice_number TEXT,
                status TEXT, grand_total NUMERIC, journal_entry_id INTEGER,
                refund_amount NUMERIC, refund_method TEXT
            );
        ");
        $db->exec("
            INSERT INTO purchases VALUES
                (10, 1, 7, '2026-10-01 10:00:00', 'PUR-10', 'partial', 1000, 100, 0, 999, 101);
            INSERT INTO payments VALUES
                (1, 1, 7, 10, NULL, '2026-10-01 10:05:00', 'PAY-1', 600, 102, 0, 'completed', 'purchase'),
                (2, 1, 7, 10, 20, '2026-10-02 10:05:00', 'REF-1', 100, 103, 0, 'completed', 'payment_for_return'),
                (3, 1, 7, 10, NULL, '2026-10-02 10:06:00', 'DRAFT', 200, NULL, 1, 'completed', 'purchase');
            INSERT INTO returns VALUES
                (20, 1, 7, 10, 'purchase', '2026-10-02 10:00:00', 'PR-20', 'PUR-10',
                 'approved', 100, 103, 100, 'cash');
        ");

        $handler = new AccountStatementHandler($db);
        $method = new ReflectionMethod(AccountStatementHandler::class, 'getSupplierReferences');
        $method->setAccessible(true);
        $result = $method->invoke($handler, 7, '2026-10-01', '2026-10-03', 1);

        $purchase = array_values(array_filter(
            $result['items'],
            static fn (array $item): bool => $item['type'] === 'purchase'
        ))[0];

        self::assertSame(1000.0, $purchase['total_amount']);
        self::assertSame(900.0, $purchase['net_total_amount']);
        self::assertSame(100.0, $purchase['tax_amount']);
        self::assertSame(500.0, $purchase['paid_amount']);
        self::assertSame(100.0, $purchase['returned_amount']);
        self::assertSame(400.0, $purchase['outstanding']);
    }

    public function testSupplierRunningBalanceIncludesReversedEntriesAndIgnoresDraftAndOtherTenantEntries(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->exec("
            CREATE TABLE journal_entries (
                id INTEGER, tenant_id INTEGER, status TEXT,
                reference_type TEXT, reference_id INTEGER, description TEXT
            );
            CREATE TABLE journal_entry_lines (
                id INTEGER, journal_entry_id INTEGER, tenant_id INTEGER, account_id INTEGER,
                debit_amount NUMERIC, credit_amount NUMERIC, created_at TEXT
            );
        ");
        $db->exec("
            INSERT INTO journal_entries VALUES
                (1, 1, 'posted', 'purchase', 1, 'posted'),
                (2, 1, 'draft', 'purchase', 2, 'draft'),
                (3, 1, 'reversed', 'purchase', 3, 'reversed'),
                (4, 2, 'posted', 'purchase', 4, 'other tenant'),
                (5, 1, 'posted', 'reversal', 3, 'reversal');
            INSERT INTO journal_entry_lines VALUES
                (1, 1, 1, 70, 0, 100, '2026-10-01 10:00:00'),
                (2, 2, 1, 70, 0, 50, '2026-10-02 10:00:00'),
                (3, 3, 1, 70, 0, 25, '2026-10-03 10:00:00'),
                (4, 4, 1, 70, 0, 10, '2026-10-04 10:00:00'),
                (5, 5, 1, 70, 25, 0, '2026-10-03 10:01:00');
        ");

        $transactions = (new BalanceCalculationService($db))
            ->getTransactionHistoryWithRunningBalance(70, 1, 'supplier');

        self::assertCount(3, $transactions);
        self::assertSame([100.0, 125.0, 100.0], array_map(
            static fn (array $transaction): float => (float) $transaction['running_balance'],
            $transactions
        ));
    }
}
