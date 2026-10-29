<?php

declare(strict_types=1);

use DI\Container;
use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class PublicHealthRouteTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['HEALTH_DETAILS_TOKEN']);
        parent::tearDown();
    }

    public function testPublicHealthResponseOmitsOperationalDiagnostics(): void
    {
        $app = $this->createApp();
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'https://example.test/health');

        $response = $app->handle($request);
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['status', 'message', 'timestamp', 'data'], array_keys($payload));
        self::assertArrayNotHasKey('env', $payload);
        self::assertArrayNotHasKey('checks', $payload);
    }

    public function testDetailedHealthRouteIsUnavailableWithoutValidDedicatedToken(): void
    {
        $_ENV['HEALTH_DETAILS_TOKEN'] = 'expected-secret';
        $app = $this->createApp();
        $factory = new ServerRequestFactory();

        $withoutToken = $app->handle(
            $factory->createServerRequest('GET', 'https://example.test/health/details')
        );
        $withInvalidToken = $app->handle(
            $factory->createServerRequest('GET', 'https://example.test/health/details')
                ->withHeader('X-Health-Token', 'invalid')
        );

        self::assertSame(404, $withoutToken->getStatusCode());
        self::assertSame(404, $withInvalidToken->getStatusCode());
        self::assertSame('', (string) $withoutToken->getBody());
    }

    public function testDetailedHealthRouteRequiresTokenConfiguration(): void
    {
        unset($_ENV['HEALTH_DETAILS_TOKEN']);
        $app = $this->createApp();
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://example.test/health/details')
            ->withHeader('X-Health-Token', 'provided-secret');

        self::assertSame(404, $app->handle($request)->getStatusCode());
    }

    public function testDetailedHealthRouteReturnsDiagnosticsWithValidToken(): void
    {
        $_ENV['HEALTH_DETAILS_TOKEN'] = 'expected-secret';
        $app = $this->createApp();
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://example.test/health/details')
            ->withHeader('X-Health-Token', 'expected-secret');

        $response = $app->handle($request);
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayHasKey('checks', $payload);
        self::assertArrayHasKey('env', $payload);
    }

    private function createApp()
    {
        $container = new Container();
        $container->set(PDO::class, new PDO('sqlite::memory:'));
        $app = AppFactory::create(container: $container);
        (require dirname(__DIR__, 2) . '/app/routes/public.php')($app, $container);

        return $app;
    }
}
