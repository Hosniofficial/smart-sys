<?php

declare(strict_types=1);

use App\Handlers\AccountingReportsHandler;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class AccountingReportsDateRangeTest extends TestCase
{
    public function testIncomeStatementUsesTenantLocalDaysForUtcDateBounds(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE settings (
            tenant_id INTEGER, key_name TEXT, value TEXT, updated_at TEXT
        )');
        $db->exec("INSERT INTO settings VALUES (5, 'app.timezone', 'Africa/Cairo', '2026-10-01')");
        $db->exec('CREATE TABLE accounts (
            id INTEGER PRIMARY KEY, tenant_id INTEGER, type TEXT
        )');
        $db->exec('CREATE TABLE journal_entry_lines (
            id INTEGER PRIMARY KEY, account_id INTEGER, tenant_id INTEGER,
            journal_entry_id INTEGER, debit_amount REAL, credit_amount REAL
        )');
        $db->exec('CREATE TABLE journal_entries (
            id INTEGER PRIMARY KEY, tenant_id INTEGER, entry_date TEXT
        )');
        $db->exec("INSERT INTO accounts VALUES (1, 5, 'revenue')");
        $db->exec("INSERT INTO journal_entries VALUES
            (10, 5, '2026-10-01 12:00:00'),
            (11, 5, '2026-10-02 00:00:00')");
        $db->exec('INSERT INTO journal_entry_lines VALUES
            (1, 1, 5, 10, 0, 750),
            (2, 1, 5, 11, 0, 250)');

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/reports/accounting/income-statement')
            ->withAttribute('tenant_id', 5)
            ->withQueryParams([
                'start_date' => '2026-09-30T21:00:00.000Z',
                'end_date' => '2026-10-01T20:59:59.999Z',
            ]);

        $response = (new AccountingReportsHandler($db))->incomeStatement($request, new Response());
        $body = json_decode((string) $response->getBody(), true);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('success', $body['status'] ?? null);
        self::assertEquals(750, $body['data']['revenues']);
        self::assertSame('2026-10-01', $body['data']['filters']['start_date']);
        self::assertSame('2026-10-01', $body['data']['filters']['end_date']);
    }

    public function testIncomeStatementReturnsBadRequestForInvalidDate(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE settings (
            tenant_id INTEGER, key_name TEXT, value TEXT, updated_at TEXT
        )');

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/reports/accounting/income-statement')
            ->withAttribute('tenant_id', 5)
            ->withQueryParams([
                'start_date' => '2026-02-30',
                'end_date' => '2026-03-01',
            ]);

        $response = (new AccountingReportsHandler($db))->incomeStatement($request, new Response());
        $body = json_decode((string) $response->getBody(), true);

        self::assertSame(400, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('error', $body['status'] ?? null);
        self::assertStringContainsString('valid YYYY-MM-DD', $body['message'] ?? '');
    }
}
