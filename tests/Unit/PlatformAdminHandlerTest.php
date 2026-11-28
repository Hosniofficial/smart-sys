<?php

declare(strict_types=1);

use App\Handlers\PlatformAdminHandler;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class PlatformAdminHandlerTest extends TestCase
{
    private PDO $db;
    private PlatformAdminHandler $handler;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec("
            CREATE TABLE tenants (
                id INTEGER PRIMARY KEY,
                company_name TEXT NOT NULL,
                status TEXT NOT NULL,
                is_setup_complete INTEGER NOT NULL DEFAULT 0,
                current_step TEXT,
                created_at TEXT NOT NULL
            )
        ");
        $this->db->exec("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                tenant_id INTEGER NULL,
                name TEXT,
                username TEXT
            )
        ");
        $this->db->exec("
            CREATE TABLE audit_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NULL,
                action TEXT,
                entity TEXT,
                entity_id INTEGER NULL,
                details TEXT,
                tenant_id INTEGER NULL,
                ip_address TEXT,
                created_at TEXT
            )
        ");
        $this->db->exec("
            INSERT INTO tenants (id, company_name, status, is_setup_complete, current_step, created_at)
            VALUES
                (7, 'Retail North', 'active', 1, 'complete', '2026-01-01 10:00:00'),
                (8, 'Food Shop', 'trial', 0, 'business_info', '2026-02-01 10:00:00')
        ");
        $this->db->exec("
            INSERT INTO users (id, tenant_id, name, username)
            VALUES (1, NULL, 'Platform Admin', 'platform-admin'), (11, 7, 'Tenant User', 'tenant-user')
        ");
        $this->handler = new PlatformAdminHandler($this->db);
    }

    public function testTenantListFiltersAndLimitsOutputToAdministrativeFields(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest(
            'GET',
            '/api/v1/admin/tenants?search=Retail&status=active'
        );

        $response = $this->handler->listTenants($request, new Response());
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $payload['data']['items']);
        self::assertSame('Retail North', $payload['data']['items'][0]['companyName']);
        self::assertSame(1, (int) $payload['data']['items'][0]['userCount']);
        self::assertArrayNotHasKey('signupIp', $payload['data']['items'][0]);
    }

    public function testTenantStatusChangeAndAuditInsertAreCommittedTogether(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('PATCH', '/api/v1/admin/tenants/8/status')
            ->withAttribute('user', ['id' => 1, 'role' => 'super_admin', 'tenant_id' => null])
            ->withParsedBody([
                'status' => 'inactive',
                'reason' => 'Customer requested suspension',
            ]);

        $response = $this->handler->updateTenantStatus($request, new Response(), ['id' => '8']);
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $tenantStatus = $this->db->query('SELECT status FROM tenants WHERE id = 8')->fetchColumn();
        $audit = $this->db->query('SELECT * FROM audit_log')->fetch(PDO::FETCH_ASSOC);
        $details = json_decode($audit['details'], true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('inactive', $tenantStatus);
        self::assertSame('tenant_status_changed', $audit['action']);
        self::assertSame('platform_admin', $audit['entity']);
        self::assertSame(8, (int) $audit['entity_id']);
        self::assertNull($audit['tenant_id']);
        self::assertSame('trial', $details['from_status']);
        self::assertSame('inactive', $details['to_status']);
        self::assertSame('Customer requested suspension', $details['reason']);
        self::assertSame(8, $payload['data']['tenantId']);
    }

    public function testInvalidStatusChangeIsRejectedWithoutMutationOrAudit(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('PATCH', '/api/v1/admin/tenants/8/status')
            ->withAttribute('user', ['id' => 1, 'role' => 'super_admin', 'tenant_id' => null])
            ->withParsedBody([
                'status' => 'deleted',
                'reason' => 'Requested by admin',
            ]);

        $response = $this->handler->updateTenantStatus($request, new Response(), ['id' => '8']);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('trial', $this->db->query('SELECT status FROM tenants WHERE id = 8')->fetchColumn());
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM audit_log')->fetchColumn());
    }

    public function testPlatformAuditListExcludesTenantScopedAuditRows(): void
    {
        $insert = $this->db->prepare("
            INSERT INTO audit_log
                (user_id, action, entity, entity_id, details, tenant_id, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $insert->execute([1, 'tenant_status_changed', 'platform_admin', 7, '{"reason":"test"}', null, '2026-03-01 10:00:00']);
        $insert->execute([11, 'sale_created', 'sales', 31, '{"amount":100}', 7, '2026-03-01 11:00:00']);

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/admin/platform-audit?tenant_id=7');
        $response = $this->handler->listPlatformAuditLogs($request, new Response());
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $payload['data']['items']);
        self::assertSame('tenant_status_changed', $payload['data']['items'][0]['action']);
        self::assertSame(7, (int) $payload['data']['items'][0]['tenantId']);
        self::assertSame('Retail North', $payload['data']['items'][0]['tenantName']);
        self::assertSame(['reason' => 'test'], $payload['data']['items'][0]['details']);
    }
}
