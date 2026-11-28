<?php

declare(strict_types=1);

use App\Handlers\UsersHandler;
use App\Middleware\TenantMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class GlobalSuperAdminTenantContextTest extends TestCase
{
    public function testGlobalSuperAdminCanReachPlatformAdminRouteWithoutTenantHeader(): void
    {
        $handler = new TenantContextRecordingHandler();
        $middleware = new TenantMiddleware(new TenantContextTestContainer());

        $response = $middleware->process(
            $this->request('/api/v1/admin/plans', $this->globalSuperAdmin()),
            $handler
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $handler->calls);
    }

    public function testTenantScopedRouteStillRequiresTenantHeaderForGlobalSuperAdmin(): void
    {
        $handler = new TenantContextRecordingHandler();
        $middleware = new TenantMiddleware(new TenantContextTestContainer());

        $response = $middleware->process(
            $this->request('/api/v1/settings', $this->globalSuperAdmin()),
            $handler
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(0, $handler->calls);
    }

    public function testTenantHeaderIsValidatedAndAttachedForTenantScopedRoute(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->exec("CREATE TABLE tenants (id INTEGER PRIMARY KEY, status TEXT NOT NULL)");
        $db->exec("INSERT INTO tenants (id, status) VALUES (7, 'active')");
        $handler = new TenantContextRecordingHandler();
        $middleware = new TenantMiddleware(new TenantContextTestContainer($db));
        $request = $this->request('/api/v1/settings', $this->globalSuperAdmin())
            ->withHeader('X-Tenant-ID', '7');

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('7', $handler->tenantId);
    }

    public function testGlobalSuperAdminCanReadOwnProfileWithoutTenant(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                username TEXT,
                name TEXT,
                email TEXT,
                phone TEXT,
                role_id INTEGER,
                status TEXT,
                tenant_id INTEGER NULL,
                branch_id INTEGER NULL,
                is_owner INTEGER,
                two_fa_enabled INTEGER,
                last_login TEXT,
                created_at TEXT,
                updated_at TEXT
            )'
        );
        $db->exec("INSERT INTO users (id, username, name, email, tenant_id) VALUES (1, 'platform-admin', 'Platform Admin', 'admin@example.test', NULL)");
        $handler = new UsersHandler($db);
        $request = $this->request('/api/v1/users/me', $this->globalSuperAdmin())
            ->withAttribute('tenant_id', 0);

        $response = $handler->getMe($request, new Response());
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, (int) $payload['data']['id']);
    }

    public function testGlobalSuperAdminCanChangeOwnPasswordWithoutTenant(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                username TEXT,
                password TEXT,
                tenant_id INTEGER NULL,
                token_version INTEGER NOT NULL DEFAULT 0,
                updated_at TEXT
            )'
        );
        $db->exec('CREATE TABLE refresh_tokens (user_id INTEGER, is_revoked INTEGER NOT NULL DEFAULT 0)');
        $insert = $db->prepare('INSERT INTO users (id, username, password, tenant_id) VALUES (?, ?, ?, NULL)');
        $insert->execute([1, 'platform-admin', password_hash('OldSecure!Pass2026', PASSWORD_DEFAULT)]);
        $db->exec('INSERT INTO refresh_tokens (user_id, is_revoked) VALUES (1, 0)');
        $handler = new UsersHandler($db);
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/v1/users/change-password')
            ->withAttribute('user', $this->globalSuperAdmin())
            ->withAttribute('tenant_id', 0)
            ->withParsedBody([
                'current_password' => 'OldSecure!Pass2026',
                'new_password' => 'NewSecure!Pass2026',
            ]);

        $response = $handler->changePassword($request, new Response());

        self::assertSame(200, $response->getStatusCode());
        $user = $db->query('SELECT password, token_version FROM users WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        self::assertTrue(password_verify('NewSecure!Pass2026', $user['password']));
        self::assertSame(1, (int) $user['token_version']);
        self::assertSame(1, (int) $db->query('SELECT is_revoked FROM refresh_tokens WHERE user_id = 1')->fetchColumn());
    }

    private function globalSuperAdmin(): array
    {
        return ['id' => 1, 'role_id' => 1, 'role' => 'super_admin', 'tenant_id' => null];
    }

    private function request(string $path, array $user): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', $path)
            ->withAttribute('user', $user);
    }
}

final class TenantContextTestContainer implements ContainerInterface
{
    public function __construct(private ?PDO $db = null)
    {
    }

    public function get(string $id)
    {
        if ($id === 'db' && $this->db !== null) {
            return $this->db;
        }

        throw new RuntimeException("Unexpected container dependency: {$id}");
    }

    public function has(string $id): bool
    {
        return $id === 'db' && $this->db !== null;
    }
}

final class TenantContextRecordingHandler implements RequestHandlerInterface
{
    public int $calls = 0;
    public $tenantId = null;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->calls++;
        $this->tenantId = $request->getAttribute('tenant_id');
        return new Response();
    }
}
