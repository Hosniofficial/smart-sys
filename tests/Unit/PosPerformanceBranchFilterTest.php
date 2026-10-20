<?php

declare(strict_types=1);

use App\Handlers\PosAnalyticsHandler;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class PosPerformanceBranchFilterTest extends TestCase
{
    public function testBranchAndSessionFiltersLimitPosPerformance(): void
    {
        $db = $this->database();
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/analytics/pos-performance')
            ->withAttribute('tenant_id', 3)
            ->withQueryParams([
                'start_date' => '2026-09-28',
                'end_date' => '2026-09-28',
                'branch_id' => '4',
                'session_id' => '12',
            ]);

        $response = (new PosAnalyticsHandler($db))->getPosPerformance($request, new Response());
        $body = json_decode((string) $response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('success', $body['status'] ?? null);
        self::assertCount(1, $body['data']['data'] ?? []);
        self::assertSame(4, $body['data']['data'][0]['user_id']);
        self::assertSame(1, $body['data']['data'][0]['orders_count']);
        self::assertEquals(1500, $body['data']['data'][0]['total_sales']);
    }

    public function testMalformedBranchFilterIsRejectedInsteadOfWideningScope(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/analytics/pos-performance')
            ->withAttribute('tenant_id', 3)
            ->withQueryParams([
                'start_date' => '2026-09-28',
                'end_date' => '2026-09-28',
                'branch_id' => ['signal' => '[object AbortSignal]', 'branchId' => '4'],
            ]);

        $response = (new PosAnalyticsHandler($this->database()))
            ->getPosPerformance($request, new Response());
        $body = json_decode((string) $response->getBody(), true);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('error', $body['status'] ?? null);
    }

    private function database(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE sales (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            branch_id INTEGER NOT NULL,
            session_id INTEGER NULL,
            created_at TEXT NOT NULL,
            total_amount REAL NOT NULL,
            discount_type TEXT NULL,
            discount_value REAL NULL,
            status TEXT NULL
        )');
        $db->exec('CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            name TEXT,
            username TEXT
        )');
        $db->exec('CREATE TABLE returns (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            sale_id INTEGER NOT NULL,
            return_type TEXT NOT NULL,
            status TEXT NOT NULL,
            created_at TEXT NOT NULL,
            total_amount REAL NOT NULL
        )');
        $db->exec("INSERT INTO users VALUES (4, 3, 'Cashier A', 'cashier_a'), (5, 3, 'Cashier B', 'cashier_b')");
        $db->exec("INSERT INTO sales VALUES
            (1, 3, 4, 4, 12, '2026-09-28 15:00:00', 1500, NULL, NULL, 'paid'),
            (2, 3, 5, 5, 12, '2026-09-28 16:00:00', 900, NULL, NULL, 'paid'),
            (3, 3, 4, 4, 13, '2026-09-28 17:00:00', 700, NULL, NULL, 'paid'),
            (4, 3, 4, 4, 12, '2026-09-28 18:00:00', 3500, NULL, NULL, 'canceled')");
        $db->exec("INSERT INTO returns VALUES
            (1, 3, 1, 'sale', 'approved', '2026-09-28 17:00:00', 900),
            (2, 3, 1, 'sale', 'cancelled', '2026-09-28 17:30:00', 300)");
        $db->exec('ALTER TABLE sales ADD COLUMN net_total_amount REAL NULL');

        return $db;
    }

    public function testPosPerformanceNetSalesSubtractApprovedReturnsButNotCancelledReturns(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/analytics/pos-performance')
            ->withAttribute('tenant_id', 3)
            ->withQueryParams([
                'start_date' => '2026-09-28',
                'end_date' => '2026-09-28',
                'branch_id' => '4',
                'session_id' => '12',
            ]);

        $response = (new PosAnalyticsHandler($this->database()))
            ->getPosPerformance($request, new Response());
        $body = json_decode((string) $response->getBody(), true);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertEquals(1500, $body['data']['data'][0]['total_sales']);
        self::assertEquals(600, $body['data']['data'][0]['net_sales']);
    }
}
