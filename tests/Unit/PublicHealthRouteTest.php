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
        self::assertSame(['status', 'message', 'data', 'errors', 'meta'], array_keys($payload));
        self::assertArrayNotHasKey('env', $payload);
        self::assertArrayHasKey('timestamp', $payload['data']);
        self::assertArrayNotHasKey('checks', $payload['data']);
    }

    public function testPublicHealthLogsDatabaseFailureWithoutExposingItInResponse(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'smartsys-health-');
        self::assertNotFalse($logFile);
        $previousErrorLog = ini_get('error_log');
        ini_set('error_log', $logFile);

        try {
            $db = new class extends PDO {
                public function __construct()
                {
                }

                public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
                {
                    throw new \PDOException('SQLSTATE[HY000] [2002] Connection refused');
                }
            };
            $app = $this->createApp($db);
            $request = (new ServerRequestFactory())->createServerRequest('GET', 'https://example.test/health');

            $response = $app->handle($request);
            $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $log = file_get_contents($logFile);

            self::assertSame(503, $response->getStatusCode());
            self::assertStringNotContainsString('Connection refused', $payload['message']);
            self::assertStringContainsString('Database readiness check failed', $log);
            self::assertStringContainsString('Connection refused', $log);
        } finally {
            ini_set('error_log', (string) $previousErrorLog);
            unlink($logFile);
        }
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
        self::assertArrayHasKey('checks', $payload['data']);
        self::assertArrayHasKey('env', $payload['data']);
    }

    private function createApp(?PDO $db = null)
    {
        $container = new Container();
        $container->set(PDO::class, $db ?? new PDO('sqlite::memory:'));
        $app = AppFactory::create(container: $container);
        (require dirname(__DIR__, 2) . '/app/routes/public.php')($app, $container);

        return $app;
    }
}
