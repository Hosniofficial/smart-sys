<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Utils\RequestHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;

/**
 * Redirects eligible HTTP requests to HTTPS and adds HSTS to HTTPS responses.
 */
class HttpsEnforcementMiddleware implements MiddlewareInterface
{
    private bool $enabled;
    private bool $redirectToHttps;
    private array $excludePaths = [];

    public function __construct(
        bool $enabled = true,
        bool $redirectToHttps = true,
        ?array $excludePaths = null
    ) {
        $this->enabled = $enabled;
        $this->redirectToHttps = $redirectToHttps;
        $this->excludePaths = $excludePaths ?: [
            '/health',
            '/check',
            '/status',
            '/metrics',
        ];
    }

    /** Enforce HTTPS before dispatch, unless disabled or the request is excluded. */
    public function process(Request $request, RequestHandler $handler): Response
    {
        $scheme = $request->getUri()->getScheme();
        $path = $request->getUri()->getPath();
        $host = $request->getUri()->getHost();

        // Skip excluded paths (health checks, metrics)
        if ($this->isPathExcluded($path)) {
            return $handler->handle($request);
        }

        // Skip localhost/development
        if (in_array($host, ['localhost', '127.0.0.1', '0.0.0.0'], true)) {
            return $handler->handle($request);
        }

        // Determine if request is HTTPS (direct or via trusted proxy)
        $isHttps = $scheme === 'https' || $this->isForwardedHttps($request);

        // Redirect HTTP → HTTPS if enforcement is enabled
        if ($this->enabled && $this->redirectToHttps && !$isHttps) {
            $httpsUri = $request->getUri()->withScheme('https')->withPort(null);
            return (new SlimResponse())
                ->withStatus(301)
                ->withHeader('Location', (string)$httpsUri);
        }

        $response = $handler->handle($request);

        // Add HSTS header for HTTPS responses
        if ($isHttps) {
            $response = $response->withHeader(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains; preload'
            );
        }

        return $response;
    }

    /**
     * Check if the request is HTTPS based on X-Forwarded-Proto from a trusted proxy.
     *
     * The header is honoured only when TRUST_PROXY is enabled and the direct peer matches
     * TRUSTED_PROXY_IPS (exact IPs or CIDR ranges, IPv4 or IPv6), for example ::1 on Render or
     * a private range such as 100.0.0.0/8 on Railway.
     */
    private function isForwardedHttps(Request $request): bool
    {
        if (!RequestHelper::isProxyTrustEnabled()) {
            return false;
        }

        $remote = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');
        if ($remote === '' || !RequestHelper::isTrustedProxy($remote)) {
            return false;
        }

        // With several hops the nearest proxy appends last; use that value.
        $hops = array_map('trim', explode(',', $request->getHeaderLine('X-Forwarded-Proto')));

        return strtolower((string) end($hops)) === 'https';
    }

    /** Exempt only the exact health endpoint paths from HTTPS redirects. */
    private function isPathExcluded(string $path): bool
    {
        foreach ($this->excludePaths as $excludePath) {
            if ($path === $excludePath) {
                return true;
            }
        }
        return false;
    }
}
