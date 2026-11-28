<?php

declare(strict_types=1);

use App\Handlers\UsersHandler;
use App\Middleware\TenantMiddleware;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class GlobalSuperAdminTenantContextTest extends TestCase
{
    private array $previousJwtEnvironment = [];

    protected function setUp(): void
    {
        foreach (['JWT_SECRET', 'JWT_REFRESH_SECRET'] as $key) {
            $this->previousJwtEnvironment[$key] = $_ENV[$key] ?? null;
        }

        $_ENV['JWT_SECRET'] = 'global-super-admin-access-test-secret-32-bytes';
        $_ENV['JWT_REFRESH_SECRET'] = 'global-super-admin-refresh-test-secret-32';
    }

    protected function tearDown(): void
    {
        foreach ($this->previousJwtEnvironment as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }
    }

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

    public function testInactiveTenantJwtIsRejectedAfterPlatformSuspension(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->exec('CREATE TABLE tenants (id INTEGER PRIMARY KEY, status TEXT NOT NULL)');
        $db->exec("INSERT INTO tenants (id, status) VALUES (7, 'inactive')");
        $handler = new TenantContextRecordingHandler();
        $middleware = new TenantMiddleware(new TenantContextTestContainer($db));
        $request = $this->request('/api/v1/settings', [
            'id' => 12,
            'role' => 'admin',
            'tenant_id' => 7,
        ])->withAttribute('tenant_id', 7);

        $response = $middleware->process($request, $handler);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(0, $handler->calls);
    }

    public function testTrialTenantJwtRemainsAvailableForSubscriptionChecks(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->exec('CREATE TABLE tenants (id INTEGER PRIMARY KEY, status TEXT NOT NULL)');
        $db->exec("INSERT INTO tenants (id, status) VALUES (7, 'trial')");
        $handler = new TenantContextRecordingHandler();
        $middleware = new TenantMiddleware(new TenantContextTestContainer($db));
        $request = $this->request('/api/v1/settings', [
            'id' => 12,
            'role' => 'admin',
            'tenant_id' => 7,
        ])->withAttribute('tenant_id', 7);

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $handler->calls);
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
                role_id INTEGER,
                branch_id INTEGER NULL,
                is_owner INTEGER NOT NULL DEFAULT 0,
                token_version INTEGER NOT NULL DEFAULT 0,
                updated_at TEXT
            )'
        );
        $db->exec(
            'CREATE TABLE roles (
                id INTEGER PRIMARY KEY,
                name TEXT,
                tenant_id INTEGER NULL,
                is_active INTEGER NOT NULL DEFAULT 1,
                is_system_role INTEGER NOT NULL DEFAULT 1
            )'
        );
        $db->exec("INSERT INTO roles (id, name, tenant_id) VALUES (1, 'super_admin', NULL)");
        $db->exec('CREATE TABLE users_role (user_id INTEGER, role_id INTEGER, tenant_id INTEGER NULL)');
        $db->exec('CREATE TABLE role_permissions (role_id INTEGER, permission_id INTEGER, tenant_id INTEGER NULL)');
        $db->exec('CREATE TABLE permissions (id INTEGER PRIMARY KEY, name TEXT, tenant_id INTEGER NULL, is_active INTEGER DEFAULT 1)');
        $db->exec(
            'CREATE TABLE refresh_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                token TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                is_revoked INTEGER NOT NULL DEFAULT 0
            )'
        );
        $insert = $db->prepare('INSERT INTO users (id, username, password, tenant_id, role_id) VALUES (?, ?, ?, NULL, 1)');
        $insert->execute([1, 'platform-admin', password_hash('OldSecure!Pass2026', PASSWORD_DEFAULT)]);
        $db->exec(
            "INSERT INTO refresh_tokens (user_id, token, expires_at, is_revoked)
             VALUES (1, 'old-refresh-token', datetime('now', '+1 day'), 0)"
        );
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
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode(), json_encode($payload));
        $user = $db->query('SELECT password, token_version FROM users WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        self::assertTrue(password_verify('NewSecure!Pass2026', $user['password']));
        self::assertSame(1, (int) $user['token_version']);
        self::assertSame(1, (int) $db->query('SELECT is_revoked FROM refresh_tokens WHERE user_id = 1')->fetchColumn());
        self::assertSame(2, (int) $db->query('SELECT COUNT(*) FROM refresh_tokens WHERE user_id = 1')->fetchColumn());
        self::assertSame(1, (int) $db->query(
            'SELECT COUNT(*) FROM refresh_tokens WHERE user_id = 1 AND is_revoked = 0'
        )->fetchColumn());
        self::assertStringContainsString('refresh_token=', $response->getHeaderLine('Set-Cookie'));

        $accessToken = $payload['data']['accessToken'];
        $claims = JWT::decode($accessToken, new Key($_ENV['JWT_SECRET'], 'HS256'));
        self::assertSame(1, $claims->token_version);
        self::assertSame('super_admin', $claims->role);
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
        if ($id === PDO::class && $this->db !== null) {
            return $this->db;
        }

        throw new RuntimeException("Unexpected container dependency: {$id}");
    }

    public function has(string $id): bool
    {
        return $id === PDO::class && $this->db !== null;
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
