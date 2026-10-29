<?php

declare(strict_types=1);

use App\Middleware\SecurityHeadersMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class SecurityHeadersMiddlewareTest extends TestCase
{
    public function testContentSecurityPolicyDoesNotAllowUnsafeEval(): void
    {
        $middleware = new SecurityHeadersMiddleware();
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response();
            }
        };
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/example');

        $response = $middleware->process($request, $handler);

        self::assertStringNotContainsString(
            "'unsafe-eval'",
            $response->getHeaderLine('Content-Security-Policy')
        );
    }

    public function testContentSecurityPolicyUsesConfiguredDirectives(): void
    {
        $middleware = new SecurityHeadersMiddleware(
            headers: ['Cross-Origin-Opener-Policy' => 'same-origin-allow-popups'],
            cspDirectives: [
                'default-src' => ["'none'"],
                'script-src' => ["'self'"],
            ]
        );
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response();
            }
        };
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/example');

        $response = $middleware->process($request, $handler);

        self::assertSame(
            "default-src 'none'; script-src 'self'",
            $response->getHeaderLine('Content-Security-Policy')
        );
        self::assertSame(
            'same-origin-allow-popups',
            $response->getHeaderLine('Cross-Origin-Opener-Policy')
        );
    }
}
