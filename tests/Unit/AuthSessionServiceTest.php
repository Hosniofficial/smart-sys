<?php

declare(strict_types=1);

use App\Services\AuthSessionService;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;

final class AuthSessionServiceTest extends TestCase
{
    private const JWT_SECRET = 'auth-session-access-test-secret-32-bytes';
    private const REFRESH_SECRET = 'auth-session-refresh-test-secret-32';

    private array $previousEnv = [];

    protected function setUp(): void
    {
        foreach (['JWT_SECRET', 'JWT_REFRESH_SECRET', 'APP_BASE_PATH', 'HTTPS_ENFORCEMENT_ENABLED'] as $key) {
            $this->previousEnv[$key] = $_ENV[$key] ?? null;
        }

        $_ENV['JWT_SECRET'] = self::JWT_SECRET;
        $_ENV['JWT_REFRESH_SECRET'] = self::REFRESH_SECRET;
        $_ENV['APP_BASE_PATH'] = '/api/v1';
        $_ENV['HTTPS_ENFORCEMENT_ENABLED'] = 'true';
    }

    protected function tearDown(): void
    {
        foreach ($this->previousEnv as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }
    }

    public function testAccessTokenPreservesResolvedIdentityAndPermissions(): void
    {
        $service = new AuthSessionService(new PDO('sqlite::memory:'));
        $token = $service->generateAccessToken([
            'id' => 12,
            'username' => 'test-user',
            'tenant_id' => 4,
            'token_version' => 2,
            'branch_id' => 8,
            'is_owner' => 1,
            'identity_role_id' => 3,
            'identity_role_name' => 'owner',
            'isAdmin' => true,
            'permissions' => ['sales.view', 'sales.create'],
        ]);

        $decoded = JWT::decode($token, new Key(self::JWT_SECRET, 'HS256'));

        self::assertSame(12, $decoded->user_id);
        self::assertSame(4, $decoded->tenant_id);
        self::assertSame(2, $decoded->token_version);
        self::assertSame(8, $decoded->branch_id);
        self::assertSame(3, $decoded->role_id);
        self::assertSame('owner', $decoded->role);
        self::assertTrue($decoded->isAdmin);
        self::assertSame(['sales.view', 'sales.create'], $decoded->permissions);
        self::assertSame('access', $decoded->type);
    }

    public function testAccessTokenLoadsCurrentVersionFromTenantUserRecord(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                token_version INTEGER NOT NULL
            )'
        );
        $db->exec('INSERT INTO users (id, tenant_id, token_version) VALUES (12, 4, 9)');
        $service = new AuthSessionService($db);

        $token = $service->generateAccessToken([
            'id' => 12,
            'username' => 'test-user',
            'tenant_id' => 4,
            'identity_role_id' => 3,
            'identity_role_name' => 'owner',
            'isAdmin' => true,
            'permissions' => [],
        ]);

        $decoded = JWT::decode($token, new Key(self::JWT_SECRET, 'HS256'));

        self::assertSame(9, $decoded->token_version);
    }

    public function testRefreshTokenCanBeDecodedAndIdentifiesItsUser(): void
    {
        $service = new AuthSessionService(new PDO('sqlite::memory:'));
        $token = $service->generateRefreshToken(12);
        $decoded = $service->decodeRefreshToken($token);

        self::assertSame(12, $decoded->user_id);
        self::assertSame('refresh', $decoded->type);
        self::assertNotEmpty($decoded->jti);
    }

    public function testRefreshCookieRetainsAuthPathAndSecurityAttributes(): void
    {
        $service = new AuthSessionService(new PDO('sqlite::memory:'));
        $response = $service->setRefreshTokenCookie(new Response(), 'refresh-token');
        $cookie = $response->getHeaderLine('Set-Cookie');

        self::assertStringContainsString('HttpOnly', $cookie);
        self::assertStringContainsString('Secure', $cookie);
        self::assertStringContainsString('SameSite=Strict', $cookie);
        self::assertStringContainsString('Path=/api/v1/auth', $cookie);
        self::assertStringContainsString('refresh_token=refresh-token', $cookie);

        $cleared = $service->clearRefreshTokenCookie(new Response())->getHeaderLine('Set-Cookie');
        self::assertStringContainsString('Path=/api/v1/auth', $cleared);
        self::assertStringContainsString('Max-Age=0', $cleared);
    }

    public function testRefreshCookieDefaultsToSecureWhenHttpsConfigurationIsMissing(): void
    {
        unset($_ENV['HTTPS_ENFORCEMENT_ENABLED']);
        $service = new AuthSessionService(new PDO('sqlite::memory:'));

        $cookie = $service->setRefreshTokenCookie(new Response(), 'refresh-token')->getHeaderLine('Set-Cookie');
        self::assertStringContainsString('Secure', $cookie);

        $clearedCookie = $service->clearRefreshTokenCookie(new Response())->getHeaderLine('Set-Cookie');
        self::assertStringContainsString('Secure', $clearedCookie);
    }
}
