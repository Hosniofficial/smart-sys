<?php

declare(strict_types=1);

use App\Middleware\JwtAuthMiddleware;
use App\Services\JwtBlacklistService;
use DI\Container;
use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

final class JwtAuthMiddlewareExceptionBoundaryTest extends TestCase
{
    public function testDownstreamExceptionsAreNotConvertedToUnauthorizedResponses(): void
    {
        $secret = 'jwt-auth-boundary-test-secret-long-enough';
        $container = new Container();
        $db = new PDO('sqlite::memory:');
        $db->exec(
            'CREATE TABLE users (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, token_version INTEGER NOT NULL DEFAULT 0)'
        );
        $db->exec('INSERT INTO users (id, tenant_id) VALUES (7, 3)');
        $container->set(PDO::class, $db);
        $container->set('config.security', ['jwt' => ['secret' => $secret]]);
        $blacklist = new class(new PDO('sqlite::memory:'), $secret) extends JwtBlacklistService {
            public function isBlacklisted(string $token): bool
            {
                return false;
            }
        };
        $middleware = new JwtAuthMiddleware($blacklist, null, $container);
        $token = JWT::encode([
            'user_id' => 7,
            'tenant_id' => 3,
            'exp' => time() + 3600,
        ], $secret, 'HS256');
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/v1/sales/add-payment')
            ->withHeader('Authorization', 'Bearer ' . $token);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new RuntimeException('payment validation failed');
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('payment validation failed');
        $middleware->process($request, $handler);
    }

    public function testAccessTokenWithOldVersionIsRejected(): void
    {
        $secret = 'jwt-auth-boundary-test-secret-long-enough';
        $container = new Container();
        $db = new PDO('sqlite::memory:');
        $db->exec(
            'CREATE TABLE users (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, token_version INTEGER NOT NULL DEFAULT 0)'
        );
        $db->exec('INSERT INTO users (id, tenant_id, token_version) VALUES (7, 3, 1)');
        $container->set(PDO::class, $db);
        $container->set('config.security', ['jwt' => ['secret' => $secret]]);
        $blacklist = new class($db, $secret) extends JwtBlacklistService {
            public function isBlacklisted(string $token): bool
            {
                return false;
            }
        };
        $middleware = new JwtAuthMiddleware($blacklist, null, $container);
        $token = JWT::encode([
            'user_id' => 7,
            'tenant_id' => 3,
            'token_version' => 0,
            'exp' => time() + 3600,
        ], $secret, 'HS256');
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/v1/sales/add-payment')
            ->withHeader('Authorization', 'Bearer ' . $token);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new \Slim\Psr7\Response();
            }
        };

        $response = $middleware->process($request, $handler);

        self::assertSame(403, $response->getStatusCode());
        $error = json_decode((string) $response->getBody(), true);
        self::assertSame('session_revoked', $error['error_code']);
    }
}
