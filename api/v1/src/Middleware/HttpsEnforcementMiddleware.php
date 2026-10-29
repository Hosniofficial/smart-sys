<?php

declare(strict_types=1);

namespace App\Middleware;

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
        array $excludePaths = []
    ) {
        $this->enabled = $enabled;
        $this->redirectToHttps = $redirectToHttps;
        $this->excludePaths = $excludePaths ?? [
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

        if ($this->isPathExcluded($path)) {
            return $handler->handle($request);
        }

        if (in_array($host, ['localhost', '127.0.0.1', '0.0.0.0'])) {
            return $handler->handle($request);
        }

        if ($this->enabled && $this->redirectToHttps && $scheme === 'http') {
            $httpsUri = $request->getUri()->withScheme('https')->withPort(null);
            return (new SlimResponse())
                ->withStatus(301)
                ->withHeader('Location', (string)$httpsUri);
        }

        $response = $handler->handle($request);

        if ($scheme === 'https') {
            $response = $response->withHeader(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains; preload'
            );
        }

        return $response;
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
