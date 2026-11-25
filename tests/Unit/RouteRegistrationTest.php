<?php

declare(strict_types=1);

use DI\Container;
use App\Routing\ContainerHandlerProxy;
use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class RouteRegistrationTest extends TestCase
{
    public function testHandlerProxyDefersServiceResolutionUntilInvocation(): void
    {
        $container = new Container();
        $container->set(RouteProxyTestHandler::class, new RouteProxyTestHandler());
        $handler = new ContainerHandlerProxy($container, RouteProxyTestHandler::class);
        AppFactory::setContainer($container);
        $app = AppFactory::create();
        $app->get('/proxy-test', [$handler, 'respond']);
        $app->addRoutingMiddleware();

        self::assertTrue(is_callable([$handler, 'respond']));
        $response = $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/proxy-test')
        );

        self::assertSame('handled', (string) $response->getBody());
    }

    public function testRoutesRegisterWithoutOpeningDatabaseAndLivenessRemainsAvailable(): void
    {
        $container = new Container();
        $container->set(PDO::class, static function (): PDO {
            throw new RuntimeException('Database is unavailable.');
        });

        AppFactory::setContainer($container);
        $app = AppFactory::create();
        (require __DIR__ . '/../../config/routes.php')($app, $container);
        $app->addRoutingMiddleware();

        $requestFactory = new ServerRequestFactory();
        $liveness = $app->handle($requestFactory->createServerRequest('GET', '/check'));
        $readiness = $app->handle($requestFactory->createServerRequest('GET', '/health'));

        self::assertSame(200, $liveness->getStatusCode());
        self::assertSame(503, $readiness->getStatusCode());
    }
}

final class RouteProxyTestHandler
{
    public function respond(
        \Psr\Http\Message\ServerRequestInterface $request,
        \Psr\Http\Message\ResponseInterface $response,
        array $args
    ): \Psr\Http\Message\ResponseInterface {
        $response->getBody()->write('handled');

        return $response;
    }

}
