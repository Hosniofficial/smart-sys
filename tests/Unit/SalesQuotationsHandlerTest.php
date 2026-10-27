<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/database/migrations/037_create_sales_quotations.php';
require_once dirname(__DIR__, 2) . '/api/v1/src/Handlers/BaseHandler.php';
require_once dirname(__DIR__, 2) . '/api/v1/src/Utils/PaginationHelper.php';
require_once dirname(__DIR__, 2) . '/api/v1/src/Utils/SuperAdminHelper.php';
require_once dirname(__DIR__, 2) . '/api/v1/src/Handlers/SalesQuotationsHandler.php';

use App\Handlers\SalesQuotationsHandler;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class SalesQuotationsHandlerTest extends TestCase
{
    private PDO $db;
    private SalesQuotationsHandler $handler;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('CREATE TABLE branches (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL)');
        $this->db->exec('CREATE TABLE sales (id INTEGER PRIMARY KEY AUTOINCREMENT)');
        $this->db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, branch_id INTEGER)');
        $this->db->exec('CREATE TABLE customers (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name TEXT)');
        $this->db->exec('CREATE TABLE products (
            id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name TEXT, product_code TEXT,
            active INTEGER, sale_price NUMERIC, purchase_price NUMERIC, min_sale_price NUMERIC,
            fixed_discount_percentage NUMERIC
        )');
        $this->db->exec('CREATE TABLE units (id INTEGER PRIMARY KEY, name TEXT, tenant_id INTEGER)');
        $this->db->exec('CREATE TABLE product_units (product_id INTEGER, unit_id INTEGER, tenant_id INTEGER, conversion_factor NUMERIC)');
        (new CreateSalesQuotations($this->db))->up();
        $this->db->exec("INSERT INTO branches (id, tenant_id) VALUES (5, 2)");
        $this->db->exec("INSERT INTO users (id, tenant_id, branch_id) VALUES (10, 2, 5)");
        $this->db->exec("INSERT INTO customers (id, tenant_id, name) VALUES (20, 2, 'Test Customer')");
        $this->db->exec("INSERT INTO products (id, tenant_id, name, product_code, active, sale_price, purchase_price, min_sale_price, fixed_discount_percentage)
                         VALUES (30, 2, 'Test Product', 'TP-30', 1, 125.5, 75, 100, 20)");
        $this->db->exec("INSERT INTO units (id, name, tenant_id) VALUES (40, 'قطعة', 2)");
        $this->db->exec('INSERT INTO product_units (product_id, unit_id, tenant_id, conversion_factor) VALUES (30, 40, 2, 1)');
        $this->handler = new SalesQuotationsHandler($this->db);
    }

    public function testCreatingQuotePersistsExpiryAndPriceWithoutCreatingSale(): void
    {
        $response = $this->invoke('create', [
            'branch_id' => 5,
            'customer_id' => 20,
            'valid_until' => date('Y-m-d', strtotime('+7 days')),
            'items' => [[
                'product_id' => 30,
                'unit_id' => 40,
                'quantity' => 2,
                'sale_price' => 125.5,
            ]],
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'tax_rate' => 14,
            'currency_code' => 'EGP',
        ]);

        self::assertSame(201, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR)['data'];
        self::assertStringStartsWith('QT-', $data['quote_number']);
        self::assertSame(251.0, (float) $data['subtotal']);
        self::assertSame(25.1, (float) $data['discount_amount']);
        self::assertSame(31.63, (float) $data['tax_amount']);
        self::assertSame(257.53, (float) $data['total_amount']);
        self::assertSame('Test Product', $data['items'][0]['product_name']);
        self::assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM sales_quotations')->fetchColumn());
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM sales')->fetchColumn());
    }

    public function testRejectsExpiredQuoteAndUnownedBranch(): void
    {
        $body = [
            'branch_id' => 5,
            'valid_until' => date('Y-m-d', strtotime('-1 day')),
            'items' => [[
                'product_id' => 30,
                'unit_id' => 40,
                'quantity' => 1,
                'sale_price' => 100,
            ]],
        ];
        $expired = $this->invoke('create', $body);
        self::assertSame(400, $expired->getStatusCode());
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM sales_quotations')->fetchColumn());

        $body['valid_until'] = date('Y-m-d', strtotime('+1 day'));
        $body['branch_id'] = 99;
        $wrongBranch = $this->invoke('create', $body);
        self::assertSame(403, $wrongBranch->getStatusCode());
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM sales_quotations')->fetchColumn());
    }

    public function testListAndDetailsReturnTenantScopedQuotationData(): void
    {
        $createdResponse = $this->invoke('create', [
            'branch_id' => 5,
            'customer_id' => 20,
            'valid_until' => date('Y-m-d', strtotime('+3 days')),
            'items' => [[
                'product_id' => 30,
                'unit_id' => 40,
                'quantity' => 3,
                'sale_price' => 101,
            ]],
        ]);
        $created = json_decode((string) $createdResponse->getBody(), true, 512, JSON_THROW_ON_ERROR)['data'];

        $listResponse = $this->invokeRead('list', '/api/v1/sales/quotations');
        self::assertSame(200, $listResponse->getStatusCode());
        $listData = json_decode((string) $listResponse->getBody(), true, 512, JSON_THROW_ON_ERROR)['data'];
        self::assertSame(1, $listData['total']);
        self::assertSame('Test Customer', $listData['items'][0]['customer_name']);
        self::assertSame('active', $listData['items'][0]['status']);

        $detailResponse = $this->invokeRead('get', '/api/v1/sales/quotations/' . $created['id'], [], ['id' => $created['id']]);
        self::assertSame(200, $detailResponse->getStatusCode());
        $detail = json_decode((string) $detailResponse->getBody(), true, 512, JSON_THROW_ON_ERROR)['data'];
        self::assertSame($created['quote_number'], $detail['quote_number']);
        self::assertSame(3.0, (float) $detail['items'][0]['quantity']);
    }

    private function invoke(string $method, array $body): Response
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/v1/sales/quotations')
            ->withParsedBody($body)
            ->withAttribute('tenant_id', 2)
            ->withAttribute('user', [
                'id' => 10,
                'tenant_id' => 2,
                'role' => 'admin',
                'is_owner' => 1,
            ]);

        return $this->handler->{$method}($request, new Response());
    }

    private function invokeRead(string $method, string $uri, array $query = [], array $args = []): Response
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', $uri)
            ->withQueryParams($query)
            ->withAttribute('tenant_id', 2)
            ->withAttribute('user', [
                'id' => 10,
                'tenant_id' => 2,
                'role' => 'admin',
                'is_owner' => 1,
            ]);

        return $this->handler->{$method}($request, new Response(), $args);
    }
}
