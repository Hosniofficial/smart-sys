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
     * Check if request is HTTPS based on X-Forwarded-Proto from trusted proxy
     * 
     * Only trusts the header if TRUST_PROXY is enabled and request comes from
     * a trusted proxy IP (e.g., Render's internal proxy at ::1)
     */
    private function isForwardedHttps(Request $request): bool
    {
        // Check if proxy trust is enabled
        if (!filter_var($_ENV['TRUST_PROXY'] ?? 'false', FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        // Verify request comes from trusted proxy
        $remote = (string)($request->getServerParams()['REMOTE_ADDR'] ?? '');
        $trustedProxies = array_values(array_filter(array_map(
            'trim',
            explode(',', (string)($_ENV['TRUSTED_PROXY_IPS'] ?? ''))
        )));

        if (empty($trustedProxies) || !RequestHelper::matchesTrustedProxy($remote, $trustedProxies)) {
            return false;
        }

        // Check X-Forwarded-Proto header
        return strtolower(trim($request->getHeaderLine('X-Forwarded-Proto'))) === 'https';
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
