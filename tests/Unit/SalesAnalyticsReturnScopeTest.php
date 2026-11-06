<?php

declare(strict_types=1);

use App\Handlers\SalesAnalyticsHandler;
use App\Handlers\AdvancedReportsHandler;
use PHPUnit\Framework\TestCase;

use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class SalesAnalyticsReturnScopeTest extends TestCase
{
    public function testPurchaseReturnDoesNotReduceSalesAnalytics(): void
    {
        $data = $this->analyze($this->databaseWithPurchaseReturnAndSale());

        self::assertEquals(1500, $data['totalSalesAmount']);
        self::assertEquals(0, $data['totalReturnsAmount']);
        self::assertEquals(1500, $data['netGrandTotal']);
        self::assertEquals(1500, $data['cashInDrawer']);
        self::assertEquals(1000, $data['cogsTotal']);
        self::assertEquals(0, $data['returnsCogs']);
        self::assertEquals(500, $data['grossProfit']);
        self::assertEquals(1500, $data['totalSales']);
        self::assertSame(1, $data['invoicesCount']);
        self::assertEquals(1500, $data['avgInvoice']);
        self::assertSame(1, (int) $data['dailySales'][0]['totalOrders']);
    }

    public function testSaleReturnStillReducesNetSalesAndCostOfGoods(): void
    {
        $db = $this->databaseWithPurchaseReturnAndSale();
        $db->exec("INSERT INTO returns (
            id, tenant_id, return_type, created_at, return_date, total_amount,
            tax_amount, grand_total, sale_id, status
        ) VALUES (2, 3, 'sale', '2026-09-28 16:00:00', '2026-09-28', 300, 0, 300, 7, 'approved')");
        $db->exec('INSERT INTO return_items VALUES (2, 2, 3, 6, NULL, 7, 0.3, 1000)');

        $data = $this->analyze($db);

        self::assertEquals(300, $data['totalReturnsAmount']);
        self::assertEquals(1200, $data['netGrandTotal']);
        self::assertEquals(300, $data['returnsCogs']);
        self::assertEquals(700, $data['netCogs']);
        self::assertEquals(500, $data['grossProfit']);
    }

    public function testReturnOfBelowCostSaleReversesOriginalCostAndRemovesTheLoss(): void
    {
        $db = $this->databaseWithPurchaseReturnAndSale();
        $db->exec("UPDATE sales SET total_amount = 900 WHERE id = 7");
        $db->exec("UPDATE sales_items SET sale_price = 900 WHERE sale_id = 7");
        $db->exec("INSERT INTO returns (
            id, tenant_id, return_type, created_at, return_date, total_amount,
            tax_amount, grand_total, sale_id, status
        ) VALUES (2, 3, 'sale', '2026-09-28 16:00:00', '2026-09-28', 900, 0, 900, 7, 'approved')");
        $db->exec('INSERT INTO return_items VALUES (2, 2, 3, 6, NULL, 7, 1, 900)');
        $db->exec("INSERT INTO inventory_transactions (
            tenant_id, product_id, unit_id, quantity, total_cost, movement_type, reference_type, reference_id
        ) VALUES (3, 6, 7, 1, 1000, 'in', 'return', 2)");

        $data = $this->analyze($db);

        self::assertEquals(900, $data['totalReturnsAmount']);
        self::assertEquals(1000, $data['cogsTotal']);
        self::assertEquals(1000, $data['returnsCogs']);
        self::assertEquals(0, $data['netCogs']);
        self::assertEquals(0, $data['grossProfit']);
    }

    public function testSalesAnalyticsFlagsUnresolvedReturnCostDespiteCurrentProductPrice(): void
    {
        $db = $this->databaseWithPurchaseReturnAndSale();
        $db->exec('UPDATE sales_items SET purchase_price = NULL WHERE sale_id = 7');
        $db->exec("INSERT INTO returns (
            id, tenant_id, return_type, created_at, return_date, total_amount,
            tax_amount, grand_total, sale_id, status
        ) VALUES (2, 3, 'sale', '2026-09-28 16:00:00', '2026-09-28', 300, 0, 300, 7, 'approved')");
        $db->exec('INSERT INTO return_items VALUES (2, 2, 3, 6, 7, 7, 1, 300)');

        $data = $this->analyze($db);

        self::assertEquals(0, $data['returnsCogs']);
        self::assertSame(1, $data['returnsCostUnresolvedLineCount']);
        self::assertEquals(1, $data['returnsCostUnresolvedQuantity']);
        self::assertFalse($data['returnsCostComplete']);
    }

    public function testDailySummaryAddsReturnedInventoryCostBackToProfit(): void
    {
        $db = $this->databaseWithPurchaseReturnAndSale();
        $db->exec("INSERT INTO returns (
            id, tenant_id, return_type, created_at, return_date, total_amount,
            tax_amount, grand_total, sale_id, status
        ) VALUES (2, 3, 'sale', '2026-09-28 18:00:00', '2026-09-28', 900, 0, 900, 7, 'approved')");
        $db->exec('INSERT INTO return_items VALUES (2, 2, 3, 6, 7, 7, 1, 900)');
        $db->exec("INSERT INTO inventory_transactions (
            tenant_id, product_id, unit_id, quantity, total_cost, movement_type, reference_type, reference_id
        ) VALUES (3, 6, 7, 1, 1000, 'in', 'return', 2)");

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/reports/sales/daily-summary')
            ->withAttribute('tenant_id', 3)
            ->withQueryParams(['date' => '2026-09-28', 'branch_id' => 4]);

        $response = (new SalesAnalyticsHandler($db))->dailySalesSummary($request, new Response());
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded, (string) $response->getBody());
        self::assertSame('success', $decoded['status'] ?? null);
        self::assertEquals(1500, $decoded['data']['totalSalesAmount']);
        self::assertEquals(900, $decoded['data']['totalReturnsAmount']);
        self::assertEquals(1000, $decoded['data']['cogsTotal']);
        self::assertEquals(1000, $decoded['data']['returnsCogs']);
        self::assertSame(0, $decoded['data']['returnsCostUnresolvedLineCount']);
        self::assertTrue($decoded['data']['returnsCostComplete']);
        self::assertEquals(0, $decoded['data']['netCogs']);
        self::assertEquals(600, $decoded['data']['grossProfit']);
    }

    public function testSalesAnalyticsConvertsIsoUtcDateBoundsToTenantCalendarDays(): void
    {
        $db = $this->databaseWithPurchaseReturnAndSale();
        $db->exec('CREATE TABLE settings (
            tenant_id INTEGER, key_name TEXT, value TEXT, updated_at TEXT
        )');
        $db->exec("INSERT INTO settings VALUES (3, 'app.timezone', 'Africa/Cairo', '2026-10-01')");

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/analytics/sales')
            ->withAttribute('tenant_id', 3)
            ->withQueryParams([
                'start_date' => '2026-09-27T21:00:00.000Z',
                'end_date' => '2026-09-28T20:59:59.999Z',
                'branch_id' => 4,
            ]);

        $response = (new SalesAnalyticsHandler($db))->analyzeSales($request, new Response());
        $decoded = json_decode((string) $response->getBody(), true);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('success', $decoded['status'] ?? null);
        self::assertEquals(1500, $decoded['data']['totalSalesAmount']);
        self::assertSame(1, (int) $decoded['data']['invoicesCount']);
        self::assertEquals(500, $decoded['data']['grossProfit']);
    }

    public function testPendingApprovalSaleIsExcludedFromAnalyticsAndDailySummary(): void
    {
        $db = $this->databaseWithPurchaseReturnAndSale();
        $db->exec("INSERT INTO sales (
            id, tenant_id, created_at, sale_date, branch_id, session_id,
            total_amount, tax_amount, payment_method_id, status
        ) VALUES (9, 3, '2026-09-28 16:30:00', '2026-09-28', 4, NULL, 1300, 0, NULL, 'pending_approval')");
        $db->exec('INSERT INTO sales_items (
            id, sale_id, tenant_id, product_id, unit_id, quantity,
            sale_price, purchase_price, conversion_factor
        ) VALUES (3, 9, 3, 6, 7, 1, 1300, 1000, 1)');

        $analytics = $this->analyze($db);
        self::assertEquals(1500, $analytics['totalSalesAmount']);
        self::assertSame(1, (int) $analytics['invoicesCount']);
        self::assertEquals(1000, $analytics['cogsTotal']);
        self::assertSame(7, (int) $analytics['latest'][0]['saleId']);

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/reports/sales/daily-summary')
            ->withAttribute('tenant_id', 3)
            ->withQueryParams(['date' => '2026-09-28', 'branch_id' => 4]);
        $response = (new SalesAnalyticsHandler($db))->dailySalesSummary($request, new Response());
        $decoded = json_decode((string) $response->getBody(), true);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('success', $decoded['status'] ?? null);
        self::assertEquals(1500, $decoded['data']['totalSalesAmount']);
        self::assertSame(1, (int) $decoded['data']['orderCount']);
        self::assertSame(7, (int) $decoded['data']['recentTransactions'][0]['id']);
    }

    public function testPendingApprovalSaleIsExcludedFromProfitLossRevenueAndCogs(): void
    {
        $db = $this->databaseWithPurchaseReturnAndSale();
        $db->exec("INSERT INTO sales (
            id, tenant_id, created_at, sale_date, branch_id, session_id,
            total_amount, tax_amount, payment_method_id, status
        ) VALUES (9, 3, '2026-09-28 16:30:00', '2026-09-28', 4, NULL, 1300, 0, NULL, 'pending_approval')");
        $db->exec('INSERT INTO sales_items (
            id, sale_id, tenant_id, product_id, unit_id, quantity,
            sale_price, purchase_price, conversion_factor
        ) VALUES (3, 9, 3, 6, 7, 1, 1300, 1000, 1)');
        $db->exec('CREATE TABLE settings (
            tenant_id INTEGER, key_name TEXT, value TEXT, updated_at TEXT
        )');
        $db->exec('CREATE TABLE accounts (id INTEGER PRIMARY KEY, tenant_id INTEGER, name TEXT, type TEXT)');
        $db->exec('CREATE TABLE cash_vouchers (
            tenant_id INTEGER, status TEXT, type TEXT, account_id INTEGER,
            amount REAL, date TEXT, branch_id INTEGER
        )');

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/reports/profit-loss')
            ->withAttribute('tenant_id', 3)
            ->withQueryParams([
                'start_date' => '2026-09-28',
                'end_date' => '2026-09-28',
                'mode' => 'cogs',
                'branch_id' => 4,
            ]);
        $response = (new AdvancedReportsHandler($db))->getProfitLossReport($request, new Response());
        $decoded = json_decode((string) $response->getBody(), true);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('success', $decoded['status'] ?? null);
        self::assertEquals(1500, $decoded['data']['revenue']['totalRevenue']);
        self::assertEquals(1000, $decoded['data']['revenue']['cogs']);
        self::assertEquals(500, $decoded['data']['summary']['netProfit']);
    }

    public function testApprovedSaleIsReportedOnApprovalDateInsteadOfCreationDate(): void
    {
        $db = $this->databaseWithPurchaseReturnAndSale();
        $db->exec("INSERT INTO sales (
            id, tenant_id, created_at, sale_date, branch_id, session_id,
            total_amount, tax_amount, payment_method_id, status, approved_at
        ) VALUES (9, 3, '2026-09-27 16:30:00', '2026-09-27', 4, NULL, 1300, 0, NULL, 'paid', '2026-09-28 09:00:00')");
        $db->exec('INSERT INTO sales_items (
            id, sale_id, tenant_id, product_id, unit_id, quantity,
            sale_price, purchase_price, conversion_factor
        ) VALUES (3, 9, 3, 6, 7, 1, 1300, 1000, 1)');

        $handler = new SalesAnalyticsHandler($db);
        $requestFactory = new ServerRequestFactory();

        $creationDateRequest = $requestFactory
            ->createServerRequest('GET', '/api/v1/reports/sales/daily-summary')
            ->withAttribute('tenant_id', 3)
            ->withQueryParams(['date' => '2026-09-27', 'branch_id' => 4]);
        $creationDateResponse = $handler->dailySalesSummary($creationDateRequest, new Response());
        $creationDateData = json_decode((string) $creationDateResponse->getBody(), true)['data'] ?? [];

        self::assertSame(200, $creationDateResponse->getStatusCode());
        self::assertEquals(0, $creationDateData['totalSalesAmount']);
        self::assertSame(0, $creationDateData['orderCount']);

        $approvalDateRequest = $requestFactory
            ->createServerRequest('GET', '/api/v1/reports/sales/daily-summary')
            ->withAttribute('tenant_id', 3)
            ->withQueryParams(['date' => '2026-09-28', 'branch_id' => 4]);
        $approvalDateResponse = $handler->dailySalesSummary($approvalDateRequest, new Response());
        $approvalDateData = json_decode((string) $approvalDateResponse->getBody(), true)['data'] ?? [];

        self::assertSame(200, $approvalDateResponse->getStatusCode());
        self::assertEquals(2800, $approvalDateData['totalSalesAmount']);
        self::assertSame(2, $approvalDateData['orderCount']);
        $approvedSale = array_values(array_filter(
            $approvalDateData['recentTransactions'],
            static fn (array $transaction): bool => (int) $transaction['id'] === 9
        ));
        self::assertSame('2026-09-28 09:00:00', $approvedSale[0]['time'] ?? null);
    }

    private function analyze(PDO $db): array
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/analytics/sales')
            ->withAttribute('tenant_id', 3)
            ->withQueryParams([
                'start_date' => '2026-09-21',
                'end_date' => '2026-09-29',
                'branch_id' => 4,
            ]);

        $response = (new SalesAnalyticsHandler($db))->analyzeSales($request, new Response());
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded, (string) $response->getBody());
        self::assertSame('success', $decoded['status'] ?? null);

        return $decoded['data'];
    }

    private function databaseWithPurchaseReturnAndSale(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $db->exec('CREATE TABLE payment_methods (
            id INTEGER PRIMARY KEY, tenant_id INTEGER, kind TEXT, name TEXT
        )');
        $db->exec('CREATE TABLE sales (
            id INTEGER PRIMARY KEY, tenant_id INTEGER, created_at TEXT, sale_date TEXT,
            branch_id INTEGER, session_id INTEGER NULL, total_amount REAL, tax_amount REAL,
            payment_method_id INTEGER, status TEXT
        )');
        $db->exec('CREATE TABLE sales_items (
            id INTEGER PRIMARY KEY, sale_id INTEGER, tenant_id INTEGER, product_id INTEGER,
            unit_id INTEGER, quantity REAL, sale_price REAL, purchase_price REAL, conversion_factor REAL
        )');
        $db->exec('CREATE TABLE products (
            id INTEGER PRIMARY KEY, tenant_id INTEGER, name TEXT, product_code TEXT,
            category_id INTEGER, purchase_price REAL, product_type TEXT
        )');
        $db->exec('CREATE TABLE categories (id INTEGER PRIMARY KEY, name TEXT)');
        $db->exec('CREATE TABLE payments (
            id INTEGER PRIMARY KEY, sale_id INTEGER, tenant_id INTEGER, amount REAL,
            payment_method_id INTEGER, is_draft INTEGER, status TEXT,
            type TEXT, created_at TEXT, session_id INTEGER, branch_id INTEGER
        )');
        $db->exec('CREATE TABLE returns (
            id INTEGER PRIMARY KEY, tenant_id INTEGER, return_type TEXT, created_at TEXT,
            return_date TEXT, total_amount REAL, tax_amount REAL, grand_total REAL,
            sale_id INTEGER NULL, status TEXT, branch_id INTEGER DEFAULT 4
        )');
        $db->exec('CREATE TABLE customer_payment_allocations (
            tenant_id INTEGER, payment_id INTEGER, sale_id INTEGER, allocated_amount REAL
        )');
        $db->exec('CREATE TABLE return_credit_allocations (
            tenant_id INTEGER, return_id INTEGER, sale_id INTEGER, allocated_amount REAL
        )');
        $db->exec('CREATE TABLE return_items (
            id INTEGER PRIMARY KEY, return_id INTEGER, tenant_id INTEGER, product_id INTEGER,
            sale_id INTEGER NULL, unit_id INTEGER, quantity REAL, unit_price REAL
        )');
        $db->exec('CREATE TABLE inventory_transactions (
            id INTEGER PRIMARY KEY, tenant_id INTEGER, product_id INTEGER, unit_id INTEGER,
            quantity REAL, total_cost REAL, movement_type TEXT, reference_type TEXT, reference_id INTEGER
        )');
        $db->exec('CREATE TABLE branches (id INTEGER PRIMARY KEY, tenant_id INTEGER, name TEXT)');
        $db->exec('CREATE TABLE purchases (
            id INTEGER PRIMARY KEY, tenant_id INTEGER, invoice_date TEXT, status TEXT
        )');
        $db->exec('CREATE TABLE purchase_items (
            id INTEGER PRIMARY KEY, purchase_id INTEGER, tenant_id INTEGER, product_id INTEGER,
            quantity REAL, cost REAL
        )');

        $db->exec("INSERT INTO payment_methods VALUES (5, 3, 'cash', 'Cash')");
        $db->exec("INSERT INTO categories VALUES (34, 'اختبار')");
        $db->exec("INSERT INTO products VALUES (6, 3, 'منتج 1', 'PRD-000006', 34, 1000, 'stock')");
        $db->exec("INSERT INTO branches VALUES (4, 3, 'الفرع الرئيسي')");
        $db->exec("INSERT INTO purchases VALUES (2, 3, '2026-09-27 10:00:00', 'completed')");
        $db->exec('INSERT INTO purchase_items VALUES (1, 2, 3, 6, 10, 1000)');
        $db->exec("INSERT INTO returns (
            id, tenant_id, return_type, created_at, return_date, total_amount,
            tax_amount, grand_total, sale_id, status
        ) VALUES (1, 3, 'purchase', '2026-09-27 12:00:00', '2026-09-27', 10000, 0, 10000, NULL, 'approved')");
        $db->exec('INSERT INTO return_items VALUES (1, 1, 3, 6, NULL, 7, 10, 1000)');
        $db->exec("INSERT INTO sales VALUES (
            7, 3, '2026-09-28 15:44:50', '2026-09-28', 4, NULL, 1500, 0, 5, 'paid'
        )");
        $db->exec('INSERT INTO sales_items VALUES (1, 7, 3, 6, 7, 1, 1500, 1000, 1)');
        $db->exec("INSERT INTO payments VALUES (1, 7, 3, 1500, 5, 0, 'completed', 'sale', '2026-09-28 15:44:50', NULL, 4)");
        $db->exec("INSERT INTO sales VALUES (
            8, 3, '2026-09-28 16:00:00', '2026-09-28', 4, NULL, 3000, 0, 5, 'canceled'
        )");
        $db->exec('INSERT INTO sales_items VALUES (2, 8, 3, 6, 7, 2, 1500, 1000, 1)');
        $db->exec("INSERT INTO payments VALUES (2, 8, 3, 3000, 5, 0, 'completed', 'sale', '2026-09-28 16:00:00', NULL, 4)");
        $db->exec('ALTER TABLE sales_items ADD COLUMN net_total REAL NULL');
        $db->exec('ALTER TABLE sales ADD COLUMN net_total_amount REAL NULL');
        $db->exec('ALTER TABLE sales ADD COLUMN approved_at TEXT NULL');

        return $db;
    }
}
