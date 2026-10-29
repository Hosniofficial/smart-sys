<?php

declare(strict_types=1);

use App\Utils\RequestHelper;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

final class RequestHelperTrustedProxyTest extends TestCase
{
    public function testForwardingHeadersAreIgnoredForUntrustedDirectPeers(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://example.test/', ['REMOTE_ADDR' => '198.51.100.10'])
            ->withHeader('CF-Connecting-IP', '203.0.113.5')
            ->withHeader('X-Real-IP', '203.0.113.6')
            ->withHeader('X-Forwarded-For', '203.0.113.7');

        self::assertSame('198.51.100.10', RequestHelper::getClientIp($request, true, ['10.0.0.0/8']));
    }

    public function testTrustedProxyChainSkipsTrustedHopsAndReturnsNearestUntrustedAddress(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://example.test/', ['REMOTE_ADDR' => '10.0.0.5'])
            ->withHeader('X-Forwarded-For', '192.0.2.99, 198.51.100.24, 10.0.0.4');

        self::assertSame(
            '198.51.100.24',
            RequestHelper::getClientIp($request, true, ['10.0.0.0/8'])
        );
    }

    public function testProxyHeadersAreIgnoredWhenTrustIsDisabled(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://example.test/', ['REMOTE_ADDR' => '2001:db8::5'])
            ->withHeader('X-Forwarded-For', '203.0.113.7');

        self::assertSame('2001:db8::5', RequestHelper::getClientIp($request, false, ['2001:db8::/32']));
    }
}
