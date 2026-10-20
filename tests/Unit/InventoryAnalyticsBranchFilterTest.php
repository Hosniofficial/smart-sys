<?php

declare(strict_types=1);

use App\Handlers\InventoryAnalyticsHandler;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class InventoryAnalyticsBranchFilterTest extends TestCase
{
    public function testInventoryAnalyticsScopesStockAndMovementsToSelectedBranch(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/analytics/inventory')
            ->withAttribute('tenant_id', 3)
            ->withQueryParams(['branch_id' => '4']);

        $response = (new InventoryAnalyticsHandler($this->database()))
            ->analyzeInventory($request, new Response());
        $body = json_decode((string) $response->getBody(), true);
        $stockById = [];
        foreach ($body['data']['branch_products'] ?? [] as $product) {
            $stockById[(int) $product['id']] = $product;
        }
        $movementById = [];
        foreach ($body['data']['stock_movement'] ?? [] as $product) {
            $movementById[(int) $product['id']] = $product;
        }

        self::assertSame(200, $response->getStatusCode());
        self::assertEqualsCanonicalizing([1, 2], array_keys($stockById));
        self::assertEquals(2, $stockById[1]['quantity']);
        self::assertSame('low', $stockById[1]['stock_status']);
        self::assertEquals(0, $stockById[2]['quantity']);
        self::assertSame('out_of_stock', $stockById[2]['stock_status']);
        self::assertEquals(1, $movementById[1]['total_restocks']);
        self::assertEquals(1, $movementById[1]['total_withdrawals']);
        self::assertEquals(5, $movementById[1]['total_in']);
        self::assertEquals(3, $movementById[1]['total_out']);
    }

    public function testInventoryAnalyticsRejectsMalformedBranchInsteadOfWideningScope(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/analytics/inventory')
            ->withAttribute('tenant_id', 3)
            ->withQueryParams(['branch_id' => ['branchId' => '4']]);

        $response = (new InventoryAnalyticsHandler($this->database()))
            ->analyzeInventory($request, new Response());
        $body = json_decode((string) $response->getBody(), true);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('error', $body['status'] ?? null);
    }

    public function testInventoryAnalyticsKeepsAllBranchesWhenNoBranchIsSelected(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/analytics/inventory')
            ->withAttribute('tenant_id', 3);

        $response = (new InventoryAnalyticsHandler($this->database()))
            ->analyzeInventory($request, new Response());
        $body = json_decode((string) $response->getBody(), true);
        $stockByBranchAndId = [];
        foreach ($body['data']['branch_products'] ?? [] as $product) {
            $stockByBranchAndId[(int) $product['branch_id']][(int) $product['id']] = $product;
        }
        $movementById = [];
        foreach ($body['data']['stock_movement'] ?? [] as $product) {
            $movementById[(int) $product['id']] = $product;
        }

        self::assertSame(200, $response->getStatusCode());
        self::assertEqualsCanonicalizing([4, 5], array_keys($stockByBranchAndId));
        self::assertEqualsCanonicalizing([1, 2], array_keys($stockByBranchAndId[4]));
        self::assertEqualsCanonicalizing([1, 2], array_keys($stockByBranchAndId[5]));
        self::assertEquals(2, $stockByBranchAndId[4][1]['quantity']);
        self::assertEquals(100, $stockByBranchAndId[5][1]['quantity']);
        self::assertEquals(0, $stockByBranchAndId[4][2]['quantity']);
        self::assertEquals(3, $stockByBranchAndId[5][2]['quantity']);
        self::assertEquals(105, $movementById[1]['total_in']);
        self::assertEquals(3, $movementById[1]['total_out']);
    }

    public function testInventoryAnalyticsRejectsBranchFromAnotherTenant(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/analytics/inventory')
            ->withAttribute('tenant_id', 3)
            ->withQueryParams(['branch_id' => '6']);

        $response = (new InventoryAnalyticsHandler($this->database()))
            ->analyzeInventory($request, new Response());
        $body = json_decode((string) $response->getBody(), true);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('error', $body['status'] ?? null);
    }

    private function database(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE branches (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            name TEXT NOT NULL
        )');
        $db->exec('CREATE TABLE products (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            product_code TEXT NOT NULL,
            category_id INTEGER NULL,
            min_quantity NUMERIC NOT NULL,
            maximum_quantity NUMERIC NOT NULL,
            active INTEGER NOT NULL
        )');
        $db->exec('CREATE TABLE branch_products (
            branch_id INTEGER NOT NULL,
            product_id INTEGER NOT NULL,
            tenant_id INTEGER NOT NULL,
            quantity NUMERIC NOT NULL,
            minimum_quantity NUMERIC NULL
        )');
        $db->exec('CREATE TABLE inventory_transactions (
            id INTEGER PRIMARY KEY,
            product_id INTEGER NOT NULL,
            tenant_id INTEGER NOT NULL,
            branch_from INTEGER NULL,
            branch_to INTEGER NULL,
            movement_type TEXT NOT NULL,
            quantity NUMERIC NOT NULL
        )');
        $db->exec("INSERT INTO branches VALUES
            (4, 3, 'Branch A'), (5, 3, 'Branch B'), (6, 9, 'Other tenant')");
        $db->exec("INSERT INTO products VALUES
            (1, 3, 'Product A', 'A', NULL, 5, 0, 1),
            (2, 3, 'Product B', 'B', NULL, 0, 0, 1),
            (3, 9, 'Other Tenant Product', 'C', NULL, 5, 0, 1)");
        $db->exec('INSERT INTO branch_products VALUES
            (4, 1, 3, 2, NULL), (5, 1, 3, 100, NULL),
            (4, 2, 3, 0, NULL), (5, 2, 3, 3, NULL)');
        $db->exec("INSERT INTO inventory_transactions VALUES
            (1, 1, 3, NULL, 4, 'in', 5),
            (2, 1, 3, 4, NULL, 'out', 3),
            (3, 1, 3, NULL, 5, 'in', 100),
            (4, 1, 9, NULL, 6, 'in', 900)");

        return $db;
    }
}
