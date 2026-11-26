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

    public function testTrustedProxyCidrRecognizesForwardedHttpsButRejectsUntrustedPeers(): void
    {
        $previousTrust = $_ENV['TRUST_PROXY'] ?? null;
        $previousProxies = $_ENV['TRUSTED_PROXY_IPS'] ?? null;
        $_ENV['TRUST_PROXY'] = 'true';
        $_ENV['TRUSTED_PROXY_IPS'] = '100.64.0.0/10';

        try {
            $middleware = new HttpsEnforcementMiddleware(true, true);
            $handler = new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return new Response(200);
                }
            };
            $factory = new ServerRequestFactory();
            $trustedRequest = $factory
                ->createServerRequest('GET', 'http://example.test/api/v1/auth/login', ['REMOTE_ADDR' => '100.64.0.3'])
                ->withHeader('X-Forwarded-Proto', 'https');
            $untrustedRequest = $factory
                ->createServerRequest('GET', 'http://example.test/api/v1/auth/login', ['REMOTE_ADDR' => '203.0.113.8'])
                ->withHeader('X-Forwarded-Proto', 'https');

            self::assertSame(200, $middleware->process($trustedRequest, $handler)->getStatusCode());
            self::assertSame(301, $middleware->process($untrustedRequest, $handler)->getStatusCode());
        } finally {
            if ($previousTrust === null) {
                unset($_ENV['TRUST_PROXY']);
            } else {
                $_ENV['TRUST_PROXY'] = $previousTrust;
            }
            if ($previousProxies === null) {
                unset($_ENV['TRUSTED_PROXY_IPS']);
            } else {
                $_ENV['TRUSTED_PROXY_IPS'] = $previousProxies;
            }
        }
    }
}
