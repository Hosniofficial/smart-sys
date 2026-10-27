<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/database/migrations/038_create_sales_price_campaigns.php';
require_once dirname(__DIR__, 2) . '/api/v1/src/Handlers/BaseHandler.php';
require_once dirname(__DIR__, 2) . '/api/v1/src/Utils/PaginationHelper.php';
require_once dirname(__DIR__, 2) . '/api/v1/src/Utils/SuperAdminHelper.php';
require_once dirname(__DIR__, 2) . '/api/v1/src/Services/PriceCampaignService.php';
require_once dirname(__DIR__, 2) . '/api/v1/src/Handlers/SalesPriceCampaignsHandler.php';

use App\Handlers\SalesPriceCampaignsHandler;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class SalesPriceCampaignsHandlerTest extends TestCase
{
    private PDO $db;
    private SalesPriceCampaignsHandler $handler;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('CREATE TABLE branches (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name TEXT)');
        $this->db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, branch_id INTEGER)');
        $this->db->exec(
            'CREATE TABLE products (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name TEXT, sale_price NUMERIC, active INTEGER)'
        );
        $this->db->exec(
            'CREATE TABLE product_units (product_id INTEGER, unit_id INTEGER, tenant_id INTEGER, conversion_factor NUMERIC)'
        );
        (new CreateSalesPriceCampaigns($this->db))->up();
        $this->db->exec("INSERT INTO branches (id, tenant_id, name) VALUES (5, 2, 'Assigned'), (6, 2, 'Other')");
        $this->db->exec('INSERT INTO users (id, tenant_id, branch_id) VALUES (10, 2, 5)');
        $this->db->exec("INSERT INTO products (id, tenant_id, name, sale_price, active) VALUES (20, 2, 'Test', 100, 1)");
        $this->db->exec('INSERT INTO product_units (product_id, unit_id, tenant_id, conversion_factor) VALUES (20, 30, 2, 1)');
        $this->handler = new SalesPriceCampaignsHandler($this->db);
    }

    public function testBranchManagerListIsLimitedToAssignedAndGlobalCampaigns(): void
    {
        $this->addCampaign(1, 5, 'Assigned');
        $this->addCampaign(2, 6, 'Other');
        $this->addCampaign(3, null, 'Global');

        $response = $this->invoke('list', 'GET');
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR)['data'];

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(2, $data['total']);
        self::assertEqualsCanonicalizing(['Assigned', 'Global'], array_column($data['items'], 'name'));
    }

    public function testBranchManagerCanOnlyCreateCampaignForAssignedBranch(): void
    {
        $body = $this->campaignBody(6);
        self::assertSame(403, $this->invoke('create', 'POST', $body)->getStatusCode());
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM sales_price_campaigns')->fetchColumn());

        $body = $this->campaignBody(null);
        self::assertSame(403, $this->invoke('create', 'POST', $body)->getStatusCode());
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM sales_price_campaigns')->fetchColumn());

        $body = $this->campaignBody(5);
        self::assertSame(201, $this->invoke('create', 'POST', $body)->getStatusCode());
        self::assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM sales_price_campaigns WHERE branch_id = 5')->fetchColumn());
    }

    public function testBranchManagerCannotChangeOtherBranchOrGlobalCampaignStatus(): void
    {
        $this->addCampaign(1, 5, 'Assigned');
        $this->addCampaign(2, 6, 'Other');
        $this->addCampaign(3, null, 'Global');

        $body = ['status' => 'paused'];
        self::assertSame(200, $this->invoke('setStatus', 'POST', $body, ['id' => 1])->getStatusCode());
        self::assertSame(404, $this->invoke('setStatus', 'POST', $body, ['id' => 2])->getStatusCode());
        self::assertSame(404, $this->invoke('setStatus', 'POST', $body, ['id' => 3])->getStatusCode());
        self::assertSame('active', $this->campaignStatus(2));
        self::assertSame('active', $this->campaignStatus(3));
    }

    private function invoke(string $method, string $httpMethod, ?array $body = null, array $args = []): Response
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($httpMethod, '/api/v1/sales/price-campaigns')
            ->withParsedBody($body)
            ->withAttribute('tenant_id', 2)
            ->withAttribute('user', [
                'id' => 10,
                'tenant_id' => 2,
                'role' => 'branch_manager',
                'branch_id' => 5,
            ]);

        return $this->handler->{$method}($request, new Response(), $args);
    }

    private function addCampaign(int $id, ?int $branchId, string $name): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO sales_price_campaigns
                (id, tenant_id, branch_id, name, campaign_type, start_date, end_date, priority, status, created_by)
             VALUES (?, 2, ?, ?, 'custom', '2026-01-01', '2026-12-31', 0, 'active', 10)"
        );
        $stmt->execute([$id, $branchId, $name]);
    }

    private function campaignBody(?int $branchId): array
    {
        return [
            'name' => 'Test campaign',
            'branch_id' => $branchId,
            'campaign_type' => 'custom',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'items' => [[
                'product_id' => 20,
                'unit_id' => 30,
                'campaign_price' => 90,
            ]],
        ];
    }

    private function campaignStatus(int $id): string
    {
        $stmt = $this->db->prepare('SELECT status FROM sales_price_campaigns WHERE id = ?');
        $stmt->execute([$id]);
        return (string) $stmt->fetchColumn();
    }
}
