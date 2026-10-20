<?php

declare(strict_types=1);

use App\Handlers\BranchInventoryReportHandler;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class BranchInventoryMovementCancellationTest extends TestCase
{
    public function testSaleCancellationIsReportedAsInboundAndRestoresRunningBalance(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE products (
            id INTEGER PRIMARY KEY, tenant_id INTEGER, name TEXT, purchase_price REAL
        )');
        $db->exec('CREATE TABLE branch_products (
            tenant_id INTEGER, branch_id INTEGER, product_id INTEGER,
            quantity REAL, quantity_cost REAL
        )');
        $db->exec('CREATE TABLE inventory_transactions (
            id INTEGER PRIMARY KEY, tenant_id INTEGER, product_id INTEGER,
            branch_from INTEGER, branch_to INTEGER, quantity REAL,
            unit_cost REAL, total_cost REAL, movement_type TEXT, movement_date TEXT,
            reference_type TEXT, reference_id INTEGER, notes TEXT
        )');
        $db->exec("INSERT INTO products VALUES (7, 4, 'منتج 2', 1000)");
        $db->exec('INSERT INTO branch_products VALUES (4, 4, 7, 10, 10000)');
        $db->exec("INSERT INTO inventory_transactions VALUES
            (1, 4, 7, NULL, 4, 10, 1000, 10000, 'initial_stock', '2026-09-30 10:00:00', 'initial_stock', 0, NULL),
            (2, 4, 7, 4, NULL, 1, 1000, 1000, 'out', '2026-10-01 11:00:00', 'sale', 11, NULL),
            (3, 4, 7, NULL, 4, 1, 1000, 1000, 'sale_cancel', '2026-10-01 11:01:00', 'sale', 11, NULL)");

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/branches/reports/inventory/movements')
            ->withAttribute('tenant_id', 4)
            ->withQueryParams([
                'start_date' => '2026-10-01',
                'end_date' => '2026-10-01',
                'branch_id' => 4,
            ]);
        $response = (new BranchInventoryReportHandler($db))->inventoryMovementsReport($request, new Response(), []);
        $body = json_decode((string) $response->getBody(), true);
        $items = $body['data']['items'] ?? [];

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('success', $body['status'] ?? null);
        self::assertCount(2, $items);
        self::assertSame('sale', $items[0]['type']);
        self::assertSame(1.0, (float) $items[0]['out']);
        self::assertSame(9.0, (float) $items[0]['balance']);
        self::assertSame('sale_cancel', $items[1]['type']);
        self::assertSame(1.0, (float) $items[1]['in']);
        self::assertSame(0.0, (float) $items[1]['out']);
        self::assertSame(10.0, (float) $items[1]['balance']);
        self::assertSame(1.0, (float) $body['data']['total_in']);
        self::assertSame(1.0, (float) $body['data']['total_out']);
        self::assertSame(10.0, (float) $body['data']['final_balance']);
        self::assertSame(10000.0, (float) $body['data']['valuation']);
    }
}