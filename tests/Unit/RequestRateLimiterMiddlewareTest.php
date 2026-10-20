<?php

declare(strict_types=1);

use App\Middleware\RequestRateLimiter;
use DI\Container;
use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class RequestRateLimiterMiddlewareTest extends TestCase
{
    public function testConfiguredRoutesAreLimitedAndOtherRoutesRemainUnaffected(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->sqliteCreateFunction(
            'FROM_UNIXTIME',
            static fn(int $timestamp): string => date('Y-m-d H:i:s', $timestamp)
        );
        $db->exec('CREATE TABLE rate_limits (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip TEXT NOT NULL,
            route TEXT NOT NULL,
            window_start TEXT NOT NULL,
            counter INTEGER NOT NULL DEFAULT 0
        )');

        $rules = [['path' => '/auth/login', 'max' => 1, 'window' => 60]];
        $container = new Container();
        $container->set('config.security', [
            'cors' => ['origin' => []],
            'rate_limiting' => [
                'enabled' => true,
                'rules' => [[
                    'path' => '/auth/login',
                    'max_attempts' => 1,
                    'window' => 60,
                ]],
            ],
        ]);
        $container->set(
            RequestRateLimiter::class,
            new RequestRateLimiter($db, $rules, ['ip_whitelist' => []])
        );

        $app = AppFactory::create(container: $container);
        $app->post('/auth/login', fn() => new Response());
        $app->get('/health', fn() => new Response());
        (require dirname(__DIR__, 2) . '/config/middleware.php')($app, $container);

        $factory = new ServerRequestFactory();
        $request = static fn(string $method, string $path) => $factory
            ->createServerRequest($method, 'https://example.test' . $path, [
                'REMOTE_ADDR' => '203.0.113.42',
            ]);

        self::assertSame(200, $app->handle($request('POST', '/auth/login'))->getStatusCode());
        self::assertSame(1, (int) $db->query('SELECT counter FROM rate_limits')->fetchColumn());
        self::assertSame(429, $app->handle($request('POST', '/auth/login'))->getStatusCode());
        self::assertSame(200, $app->handle($request('GET', '/health'))->getStatusCode());
    }
}
