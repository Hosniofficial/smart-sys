<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Psr\Log\LoggerInterface;

/**
 * Adds baseline response security headers and a Content Security Policy.
 *
 * Header values can be supplied by the caller; the CSP and reporting headers
 * are assembled by this middleware.
 */
class SecurityHeadersMiddleware implements MiddlewareInterface
{
    private array $defaultHeaders;
    private array $cspDirectives;
    private array $headers = [];
    private ?LoggerInterface $logger = null;
    private bool $isProduction = false;

    public function __construct(
        array $headers = [],
        ?LoggerInterface $logger = null,
        bool $isProduction = false
    ) {
        $this->logger = $logger;
        $this->isProduction = $isProduction;

        $this->defaultHeaders = [
            // Prevent clickjacking
            'X-Frame-Options' => 'DENY',

            // Prevent MIME type sniffing
            'X-Content-Type-Options' => 'nosniff',

            // Enable XSS filtering in older browsers
            'X-XSS-Protection' => '1; mode=block',

            // Control referrer information
            'Referrer-Policy' => 'strict-origin-when-cross-origin',

            // Feature policy (replaced by Permissions-Policy in modern browsers)
            'Permissions-Policy' => 'accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()',

            // Cross-Origin Resource Sharing
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cross-Origin-Resource-Policy' => 'same-site',
            'Cross-Origin-Embedder-Policy' => 'require-corp',

            // Cache control
            'Cache-Control' => 'no-store, max-age=0, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ];

        $this->cspDirectives = [
            // Default policy for loading resources
            'default-src' => ["'self'"],

            // JavaScript sources
            'script-src' => ["'self'", "'unsafe-inline'", "'unsafe-eval'"],

            // CSS sources
            'style-src' => ["'self'", "'unsafe-inline'", 'fonts.googleapis.com', 'cdn.jsdelivr.net'],

            // Image sources
            'img-src' => ["'self'", 'data:', 'blob:', 'cdn.jsdelivr.net'],

            // Font sources
            'font-src' => ["'self'", 'data:', 'fonts.gstatic.com', 'cdn.jsdelivr.net'],

            // Connect sources (XHR, WebSockets, EventSource, etc.)
            'connect-src' => ["'self'"],

            // Media sources (audio and video)
            'media-src' => ["'self'"],

            // Object sources (Flash, etc.)
            'object-src' => ["'none'"],

            // Child sources (iframes, etc.)
            'child-src' => ["'self'"],

            // Form actions
            'form-action' => ["'self'"],

            // Frame sources
            'frame-src' => ["'self'"],

            // Frame ancestors (replaces X-Frame-Options)
            'frame-ancestors' => ["'none'"],

            // Base URIs
            'base-uri' => ["'self'"],

            // Enable strict-dynamic for CSP Level 3
            'upgrade-insecure-requests' => [],
        ];

        $this->headers = array_merge($this->defaultHeaders, $headers);

        // Set Content-Security-Policy header
        $this->headers['Content-Security-Policy'] = $this->buildCspHeader();

        // Add Report-To header for reporting API (CSP, NEL, etc.)
        $this->headers['Report-To'] = json_encode([
            'group' => 'csp-endpoint',
            'max_age' => 10886400,
            'endpoints' => [
                ['url' => '/api/v1/security/report']
            ],
            'include_subdomains' => true
        ]);

        // Add NEL (Network Error Logging) policy
        $this->headers['NEL'] = json_encode([
            'report_to' => 'default',
            'max_age' => 31536000,
            'include_subdomains' => true,
            'success_fraction' => 0.0,
            'failure_fraction' => 1.0
        ]);
    }

    /** Add security headers to the downstream response, except on status paths. */
    public function process(Request $request, RequestHandler $handler): Response
    {
        $response = $handler->handle($request);

        $path = $request->getUri()->getPath();
        if (preg_match('#^/(health|status|metrics|phpinfo)#', $path)) {
            return $response;
        }

        if ($request->getUri()->getScheme() === 'https') {
            $this->headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains; preload';
        }

        foreach ($this->headers as $key => $value) {
            try {
                if (!empty($value)) {
                    $response = $response->withHeader($key, $value);
                }
            } catch (\InvalidArgumentException $e) {
                $this->logger && $this->logger->error("Failed to set header {$key}", [
                    'error' => $e->getMessage(),
                    'value' => $value
                ]);
            }
        }

        $response = $response
            ->withHeader('X-Permitted-Cross-Domain-Policies', 'none')
            ->withHeader('X-Download-Options', 'noopen')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-DNS-Prefetch-Control', 'off');

        if (!$response->hasHeader('X-Request-ID')) {
            $requestId = $request->getHeaderLine('X-Request-ID') ?: $this->generateRequestId();
            $response = $response->withHeader('X-Request-ID', $requestId);
        }

        return $response;
    }

    /** Serialize configured directives into a Content-Security-Policy value. */
    private function buildCspHeader(): string
    {
        $cspParts = [];

        foreach ($this->cspDirectives as $directive => $sources) {
            if (empty($sources)) {
                $cspParts[] = $directive;
            } else {
                $cspParts[] = $directive . ' ' . implode(' ', $sources);
            }
        }

        return implode('; ', $cspParts);
    }

    /** Generate a random request identifier. */
    private function generateRequestId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
