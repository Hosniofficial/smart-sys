<?php

declare(strict_types=1);

use App\Handlers\SalesAnalyticsHandler;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class SalesAnalyticsPaymentBreakdownTest extends TestCase
{
    public function testBreakdownUsesActualPaymentsInsteadOfInvoiceTotals(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE payment_methods (
            id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, kind TEXT NOT NULL
        )');
        $db->exec('CREATE TABLE sales (
            id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, created_at TEXT NOT NULL,
            session_id INTEGER NULL, payment_method_id INTEGER NOT NULL,
            total_amount REAL NOT NULL, net_total_amount REAL NOT NULL,
            tax_amount REAL NOT NULL DEFAULT 0, status TEXT NOT NULL, branch_id INTEGER
        )');
        $db->exec('CREATE TABLE payments (
            id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, sale_id INTEGER,
            customer_id INTEGER, amount REAL NOT NULL, payment_method_id INTEGER NOT NULL,
            type TEXT NOT NULL, created_at TEXT NOT NULL, session_id INTEGER,
            branch_id INTEGER, is_draft INTEGER NOT NULL DEFAULT 0, status TEXT NOT NULL
        )');
        $db->exec('CREATE TABLE returns (
            id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, sale_id INTEGER,
            created_at TEXT, return_type TEXT, status TEXT, total_amount REAL, tax_amount REAL,
            branch_id INTEGER
        )');
        $db->exec('CREATE TABLE customer_payment_allocations (
            tenant_id INTEGER NOT NULL, payment_id INTEGER NOT NULL, sale_id INTEGER NOT NULL,
            allocated_amount REAL NOT NULL
        )');
        $db->exec('CREATE TABLE return_credit_allocations (
            tenant_id INTEGER NOT NULL, sale_id INTEGER NOT NULL, allocated_amount REAL NOT NULL
        )');
        $db->exec("INSERT INTO payment_methods VALUES
            (1, 3, 'cash'), (2, 3, 'bank'), (3, 3, 'card'), (4, 3, 'wallet')");
        $db->exec("INSERT INTO sales VALUES
            (1, 3, '2026-09-28 10:00:00', NULL, 1, 1500, 1500, 0, 'paid', 5),
            (2, 3, '2026-09-28 11:00:00', NULL, 2, 1500, 1500, 0, 'paid', 5),
            (3, 3, '2026-09-28 12:00:00', NULL, 3, 1500, 1500, 0, 'paid', 5),
            (4, 3, '2026-09-28 13:00:00', NULL, 4, 1500, 1500, 0, 'paid', 5),
            (5, 3, '2026-09-28 14:00:00', NULL, 1, 1500, 1500, 0, 'partial', 5)");
        $db->exec("INSERT INTO payments VALUES
            (1, 3, 1, NULL, 1500, 1, 'sale', '2026-09-28 10:00:00', NULL, 4, 0, 'completed'),
            (2, 3, 2, NULL, 1500, 2, 'sale', '2026-09-28 11:00:00', NULL, 4, 0, 'completed'),
            (3, 3, 3, NULL, 1500, 3, 'sale', '2026-09-28 12:00:00', NULL, 4, 0, 'completed'),
            (4, 3, 4, NULL, 1500, 4, 'sale', '2026-09-28 13:00:00', NULL, 4, 0, 'completed'),
            (5, 3, 5, NULL, 400, 1, 'sale', '2026-09-28 14:00:00', NULL, 4, 0, 'completed'),
            (6, 3, 1, NULL, 900, 1, 'return_payment', '2026-09-28 15:00:00', NULL, 4, 0, 'completed')");
        $db->exec("INSERT INTO returns VALUES
            (1, 3, 1, '2026-09-29 15:00:00', 'sale', 'approved', 900, 0, 5),
            (2, 3, 2, '2026-09-29 15:30:00', 'sale', 'approved', 900, 0, 5),
            (3, 3, 1, '2026-09-29 16:00:00', 'sale', 'cancelled', 300, 0, 5),
            (4, 3, 1, '2026-09-29 16:30:00', 'sale', 'approved', 500, 0, 6)");

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/analytics/sales-payments-breakdown')
            ->withAttribute('tenant_id', 3)
            ->withQueryParams([
                'start_date' => '2026-09-28',
                'end_date' => '2026-09-29',
                'branch_id' => 5,
            ]);
        $response = (new SalesAnalyticsHandler($db))->breakdownSalesPayments($request, new Response());
        $body = json_decode((string) $response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('success', $body['status'] ?? null);
        self::assertEquals(1000, $body['data']['cash']);
        self::assertEquals(1500, $body['data']['bank']);
        self::assertEquals(1500, $body['data']['card']);
        self::assertEquals(1500, $body['data']['wallet']);
        self::assertEquals(1000, $body['data']['cash_drawer']);
        self::assertEquals(1800, $body['data']['returns']);
        self::assertEquals(1100, $body['data']['credit']);

        $returnDayRequest = $request
            ->withQueryParams([
                'start_date' => '2026-09-29',
                'end_date' => '2026-09-29',
                'branch_id' => 5,
            ]);
        $returnDayResponse = (new SalesAnalyticsHandler($db))->breakdownSalesPayments(
            $returnDayRequest,
            new Response()
        );
        $returnDayBody = json_decode((string) $returnDayResponse->getBody(), true);

        self::assertSame(200, $returnDayResponse->getStatusCode());
        self::assertEquals(1800, $returnDayBody['data']['returns']);
    }
}
