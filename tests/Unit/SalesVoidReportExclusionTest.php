<?php

declare(strict_types=1);

use App\Handlers\AdvancedReportsHandler;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class SalesVoidReportExclusionTest extends TestCase
{
    public function testProfitLossUsesSharedNetCogsInsteadOfJournalEntryAmount(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE settings (
            tenant_id INTEGER, key_name TEXT, value TEXT, updated_at TEXT
        )');
        $db->exec('CREATE TABLE accounts (
            id INTEGER PRIMARY KEY, tenant_id INTEGER, code TEXT, name TEXT, type TEXT
        )');
        $db->exec('CREATE TABLE branches (id INTEGER PRIMARY KEY, tenant_id INTEGER)');
        $db->exec('CREATE TABLE sales (
            id INTEGER PRIMARY KEY, tenant_id INTEGER, created_at TEXT, sale_date TEXT,
            status TEXT, total_amount REAL, tax_amount REAL, branch_id INTEGER,
            journal_entry_id INTEGER
        )');
        $db->exec('CREATE TABLE returns (
            id INTEGER PRIMARY KEY, tenant_id INTEGER, return_type TEXT, created_at TEXT,
            return_date TEXT, status TEXT, sale_id INTEGER, total_amount REAL,
            tax_amount REAL, branch_id INTEGER, journal_entry_id INTEGER
        )');
        $db->exec('CREATE TABLE cash_vouchers (
            id INTEGER PRIMARY KEY, tenant_id INTEGER, account_id INTEGER,
            type TEXT, amount REAL, date TEXT, status TEXT, branch_id INTEGER
        )');
        $db->exec('CREATE TABLE journal_entries (
            id INTEGER PRIMARY KEY, tenant_id INTEGER, entry_date TEXT,
            reference_type TEXT, reference_id INTEGER
        )');
        $db->exec('CREATE TABLE journal_entry_lines (
            id INTEGER PRIMARY KEY, tenant_id INTEGER, journal_entry_id INTEGER,
            account_id INTEGER, debit_amount REAL, credit_amount REAL
        )');
        $db->exec('CREATE TABLE products (
            id INTEGER PRIMARY KEY, tenant_id INTEGER, product_type TEXT, purchase_price REAL
        )');
        $db->exec('CREATE TABLE purchases (
            id INTEGER PRIMARY KEY, tenant_id INTEGER, invoice_date TEXT, status TEXT
        )');
        $db->exec('CREATE TABLE purchase_items (
            id INTEGER PRIMARY KEY, purchase_id INTEGER, tenant_id INTEGER,
            product_id INTEGER, quantity REAL, cost REAL
        )');
        $db->exec('CREATE TABLE sales_items (
            id INTEGER PRIMARY KEY, sale_id INTEGER, tenant_id INTEGER, product_id INTEGER,
            unit_id INTEGER, quantity REAL, purchase_price REAL, conversion_factor REAL
        )');
        $db->exec('CREATE TABLE return_items (
            id INTEGER PRIMARY KEY, return_id INTEGER, tenant_id INTEGER, product_id INTEGER,
            unit_id INTEGER, quantity REAL, unit_price REAL
        )');
        $db->exec('CREATE TABLE inventory_transactions (
            id INTEGER PRIMARY KEY, tenant_id INTEGER, product_id INTEGER, unit_id INTEGER,
            quantity REAL, total_cost REAL, movement_type TEXT, reference_type TEXT, reference_id INTEGER
        )');

        $db->exec("INSERT INTO settings VALUES (5, 'cogs_account_id', '1', '2026-10-01')");
        $db->exec("INSERT INTO settings VALUES (5, 'app.timezone', 'Africa/Cairo', '2026-10-01')");
        $db->exec("INSERT INTO accounts VALUES (1, 5, '5101', 'تكلفة المبيعات', 'expense')");
        $db->exec('INSERT INTO branches VALUES (8, 5)');
        $db->exec("INSERT INTO sales VALUES (
            20, 5, '2026-10-08 10:00:00', '2026-10-08', 'paid', 6100, 0, 8, 1
        )");
        $db->exec('ALTER TABLE sales ADD COLUMN net_total_amount REAL NULL');
        $db->exec("INSERT INTO returns VALUES (
            30, 5, 'sale', '2026-10-09 11:00:00', '2026-10-09', 'completed',
            20, 900, 0, 8, 2
        )");
        $db->exec("INSERT INTO journal_entries VALUES
            (1, 5, '2026-10-08', 'cogs', 20),
            (2, 5, '2026-10-09', 'sale_return', 30)
        ");
        $db->exec("INSERT INTO journal_entry_lines VALUES
            (1, 5, 1, 1, 6000, 0),
            (2, 5, 2, 1, 0, 500)
        ");
        $db->exec("INSERT INTO products VALUES (7, 5, 'stock', 5000)");
        $db->exec("INSERT INTO purchases VALUES (1, 5, '2026-10-01 09:00:00', 'completed')");
        $db->exec('INSERT INTO purchase_items VALUES (1, 1, 5, 7, 1, 5000)');
        $db->exec('INSERT INTO sales_items VALUES (1, 20, 5, 7, 3, 1, 5000, 1)');
        $db->exec('INSERT INTO return_items VALUES (1, 30, 5, 7, 3, 1, 900)');
        $db->exec("INSERT INTO inventory_transactions VALUES (
            1, 5, 7, 3, 1, 1000, 'in', 'return', 30
        )");

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/reports/profit-loss')
            ->withAttribute('tenant_id', 5)
            ->withQueryParams([
                'start_date' => '2026-09-30T21:00:00.000Z',
                'end_date' => '2026-10-09T20:59:59.999Z',
                'mode' => 'cogs',
                'expense_source' => 'vouchers',
                'branch_id' => 8,
            ]);

        $response = (new AdvancedReportsHandler($db))->getProfitLossReport($request, new Response());
        $body = json_decode((string) $response->getBody(), true);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('success', $body['status'] ?? null);
        self::assertEquals(5200, $body['data']['revenue']['total_revenue']);
        self::assertEquals(1000, $body['data']['revenue']['returns_cogs']);
        self::assertEquals(4000, $body['data']['revenue']['net_cogs']);
        self::assertEquals(4000, $body['data']['revenue']['cogs']);
        self::assertEquals(1200, $body['data']['revenue']['gross_profit']);
        self::assertEquals(1200, $body['data']['summary']['net_profit']);
        self::assertSame('costing_engine_wac', $body['data']['revenue']['cogs_source']);
        self::assertSame('2026-10-01', $body['data']['period']['start_date']);
        self::assertSame('2026-10-09', $body['data']['period']['end_date']);

        $db->exec('UPDATE journal_entry_lines SET credit_amount = 6000 WHERE id = 2');
        $zeroBalanceResponse = (new AdvancedReportsHandler($db))->getProfitLossReport($request, new Response());
        $zeroBalanceBody = json_decode((string) $zeroBalanceResponse->getBody(), true);

        self::assertSame('costing_engine_wac', $zeroBalanceBody['data']['revenue']['cogs_source']);
        self::assertEquals(4000, $zeroBalanceBody['data']['revenue']['cogs']);
    }

    public function testProfitLossExcludesCanceledSalesFromRevenueAndCogs(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE settings (tenant_id INTEGER, key_name TEXT, value TEXT)');
        $db->exec('CREATE TABLE accounts (id INTEGER PRIMARY KEY, tenant_id INTEGER, code TEXT, name TEXT, type TEXT)');
        $db->exec('CREATE TABLE sales (
            id INTEGER PRIMARY KEY, tenant_id INTEGER, created_at TEXT, sale_date TEXT,
            status TEXT, total_amount REAL, tax_amount REAL
        )');
        $db->exec('CREATE TABLE sales_items (
            id INTEGER PRIMARY KEY, sale_id INTEGER, tenant_id INTEGER, product_id INTEGER,
            quantity REAL, conversion_factor REAL, purchase_price REAL
        )');
        $db->exec('CREATE TABLE products (
            id INTEGER PRIMARY KEY, tenant_id INTEGER, product_type TEXT, purchase_price REAL
        )');
        $db->exec('CREATE TABLE purchases (
            id INTEGER PRIMARY KEY, tenant_id INTEGER, invoice_date TEXT, status TEXT
        )');
        $db->exec('CREATE TABLE purchase_items (
            id INTEGER PRIMARY KEY, purchase_id INTEGER, tenant_id INTEGER, product_id INTEGER,
            quantity REAL, cost REAL
        )');
        $db->exec('CREATE TABLE returns (
            id INTEGER PRIMARY KEY, tenant_id INTEGER, return_type TEXT, created_at TEXT,
            return_date TEXT, status TEXT, sale_id INTEGER, purchase_id INTEGER,
            total_amount REAL, tax_amount REAL
        )');
        $db->exec('CREATE TABLE return_items (
            id INTEGER PRIMARY KEY, return_id INTEGER, tenant_id INTEGER, product_id INTEGER,
            unit_id INTEGER, quantity REAL, unit_price REAL
        )');
        $db->exec('CREATE TABLE cash_vouchers (
            id INTEGER PRIMARY KEY, tenant_id INTEGER, account_id INTEGER,
            type TEXT, amount REAL, date TEXT, status TEXT
        )');

        $db->exec("INSERT INTO products VALUES (1, 5, 'stock', 10)");
        $db->exec("INSERT INTO purchases VALUES (1, 5, '2026-09-30 09:00:00', 'completed')");
        $db->exec('INSERT INTO purchase_items VALUES (1, 1, 5, 1, 10, 10)');
        $db->exec("INSERT INTO sales VALUES
            (10, 5, '2026-10-01 10:00:00', '2026-10-01', 'pending_payment', 100, 0),
            (11, 5, '2026-10-01 11:00:00', '2026-10-01', 'canceled', 100, 0)");
        $db->exec('ALTER TABLE sales ADD COLUMN net_total_amount REAL NULL');
        $db->exec('INSERT INTO sales_items VALUES
            (1, 10, 5, 1, 1, 1, 10),
            (2, 11, 5, 1, 1, 1, 10)');

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/reports/profit-loss')
            ->withAttribute('tenant_id', 5)
            ->withQueryParams([
                'start_date' => '2026-10-01',
                'end_date' => '2026-10-01',
                'mode' => 'cogs',
                'expense_source' => 'vouchers',
            ]);

        $response = (new AdvancedReportsHandler($db))->getProfitLossReport($request, new Response());
        $body = json_decode((string) $response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('success', $body['status'] ?? null);
        self::assertEquals(100, $body['data']['revenue']['total_revenue']);
        self::assertEquals(10, $body['data']['revenue']['cogs']);
        self::assertEquals(90, $body['data']['summary']['net_profit']);
    }
}
