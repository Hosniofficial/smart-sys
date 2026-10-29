<?php

declare(strict_types=1);

use App\Middleware\SubscriptionMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class SubscriptionMiddlewareFailureTest extends TestCase
{
    public function testDownstreamHandlerExceptionIsNotRetried(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE plans (id INTEGER PRIMARY KEY, name TEXT, billing_cycle_days INTEGER, code TEXT)');
        $db->exec(
            'CREATE TABLE subscriptions (
                id INTEGER PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                plan_id INTEGER NOT NULL,
                status TEXT NOT NULL,
                end_date TEXT NOT NULL
            )'
        );
        $db->exec("INSERT INTO plans (id, name, billing_cycle_days, code) VALUES (1, 'Test', 30, 'test')");
        $db->exec(
            "INSERT INTO subscriptions (id, tenant_id, plan_id, status, end_date)
             VALUES (1, 2, 1, 'active', datetime('now', '+14 days'))"
        );
        $middleware = new SubscriptionMiddleware($db);
        $downstream = new class implements RequestHandlerInterface {
            public int $calls = 0;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->calls++;
                throw new RuntimeException('Downstream failure after a possible side effect.');
            }
        };
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/v1/sales')
            ->withAttribute('tenant_id', 2)
            ->withAttribute('user', ['id' => 10, 'tenant_id' => 2, 'role' => 'manager']);

        try {
            $middleware->process($request, $downstream);
            self::fail('The downstream exception should propagate.');
        } catch (RuntimeException $error) {
            self::assertSame('Downstream failure after a possible side effect.', $error->getMessage());
        }

        self::assertSame(1, $downstream->calls);
    }

    public function testSubscriptionDatabaseFailureBlocksRequestWithoutCallingHandler(): void
    {
        $db = new PDO('sqlite::memory:');
        $middleware = new SubscriptionMiddleware($db);
        $downstream = new class implements RequestHandlerInterface {
            public int $calls = 0;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->calls++;
                return new Response();
            }
        };
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/v1/sales')
            ->withAttribute('tenant_id', 2)
            ->withAttribute('user', ['id' => 10, 'tenant_id' => 2, 'role' => 'manager']);

        $response = $middleware->process($request, $downstream);

        self::assertSame(503, $response->getStatusCode());
        self::assertSame(0, $downstream->calls);
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('error', $payload['status']);
        self::assertStringNotContainsString('no such table', json_encode($payload));
    }
}
