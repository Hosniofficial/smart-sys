<?php

declare(strict_types=1);

use App\Middleware\HttpsEnforcementMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class HttpsEnforcementMiddlewareTest extends TestCase
{
    public function testHealthDetailsSubpathIsNotExemptFromHttps(): void
    {
        $middleware = new HttpsEnforcementMiddleware(true, true, ['/health', '/status', '/metrics']);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response();
            }
        };
        $request = (new ServerRequestFactory())->createServerRequest(
            'GET',
            'http://example.test/health/details'
        );

        $response = $middleware->process($request, $handler);

        self::assertSame(301, $response->getStatusCode());
        self::assertStringStartsWith('https://', $response->getHeaderLine('Location'));
    }

    public function testExactHealthEndpointRemainsExemptFromHttpsRedirect(): void
    {
        $middleware = new HttpsEnforcementMiddleware(true, true, ['/health']);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://example.test/health');

        self::assertSame(200, $middleware->process($request, $handler)->getStatusCode());
    }
}
