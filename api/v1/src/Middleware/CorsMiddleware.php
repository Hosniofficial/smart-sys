<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;
use App\Services\MonologHandler;

/**
 * Handles CORS preflight requests and adds CORS headers to allowed origins.
 *
 * Origin, method, request-header, credential, and cache settings come from the
 * security configuration. Downstream exceptions are logged and converted to JSON.
 */
class CorsMiddleware implements MiddlewareInterface
{
    private array $allowedOrigins;
    private int $maxAge;
    private array $allowedMethods;
    private array $allowedHeaders;
    private bool $allowCredentials;
    private $logger;

    public function __construct(array $corsConfig = [])
    {
        $this->logger = MonologHandler::getInstance('api');

        // Origins
        $originConfig = $corsConfig['origin'] ?? '*';
        if ($originConfig === '*' || (is_string($originConfig) && trim($originConfig) === '*')) {
            $this->allowedOrigins = ['*'];
        } else {
            $this->allowedOrigins = (array)$originConfig;
        }

        // Methods
        $this->allowedMethods = $corsConfig['methods'] ?? ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

        // Headers (ensure common defaults if not provided)
        $this->allowedHeaders = $corsConfig['headers'] ?? ['Content-Type', 'Authorization', 'X-Requested-With', 'X-Tenant-ID'];

        // Max age and credentials
        $this->maxAge = isset($corsConfig['max_age']) ? (int)$corsConfig['max_age'] : 86400;
        $this->allowCredentials = (bool)($corsConfig['credentials'] ?? false);
    }

    /** Handle preflight directly; otherwise dispatch and decorate the response. */
    public function process(Request $request, RequestHandler $handler): Response
    {
        $origin = $request->getHeaderLine('Origin');
        $method = $request->getMethod();

        if ($method === 'OPTIONS') {
            return $this->handlePreflight($request, $origin);
        }

        try {
            $response = $handler->handle($request);

            if ($this->isOriginAllowed($origin)) {
                $response = $this->addCorsHeaders($response, $origin);
            }

            return $response;
        } catch (\Throwable $e) {
            $this->logger->error('Unhandled API request exception', [
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
                'method'    => $method,
                'path'      => $request->getUri()->getPath(),
                'trace'     => $e->getTraceAsString(),
            ]);

            $response = new SlimResponse();

            if ($this->isOriginAllowed($origin)) {
                $response = $this->addCorsHeaders($response, $origin);
            }

            $statusCode = ($e->getCode() >= 400 && $e->getCode() <= 599) ? $e->getCode() : 500;
            $response->getBody()->write(json_encode([
                'status' => 'error',
                'message' => $statusCode >= 500 ? 'Internal server error' : $e->getMessage(),
            ]));

            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus($statusCode);
        }
    }

    private function handlePreflight(Request $request, string $origin): Response
    {
        $response = new SlimResponse();

        if ($this->isOriginAllowed($origin)) {
            $requestedMethod = $request->getHeaderLine('Access-Control-Request-Method');
            $requestedHeaders = $request->getHeaderLine('Access-Control-Request-Headers');

            $allowedMethods = $requestedMethod ? [$requestedMethod] : $this->allowedMethods;

            if ($this->allowCredentials && !in_array('*', $this->allowedOrigins, true)) {
                $response = $response->withHeader('Access-Control-Allow-Origin', $origin);
            } else {
                $allowedOrigin = in_array('*', $this->allowedOrigins, true) ? '*' : $origin;
                $response = $response->withHeader('Access-Control-Allow-Origin', $allowedOrigin);
            }

            $response = $response->withHeader('Access-Control-Allow-Methods', implode(', ', $allowedMethods));

            if ($requestedHeaders) {
                $response = $response->withHeader('Access-Control-Allow-Headers', $requestedHeaders);
            } elseif (!empty($this->allowedHeaders)) {
                $response = $response->withHeader('Access-Control-Allow-Headers', implode(', ', $this->allowedHeaders));
            }

            $response = $response->withHeader('Access-Control-Max-Age', (string)$this->maxAge);

            if ($this->allowCredentials) {
                $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
            }

            if (!empty($this->allowedHeaders)) {
                $response = $response->withHeader('Access-Control-Expose-Headers', implode(', ', $this->allowedHeaders));
            }
        }

        return $response->withStatus(204); // No Content
    }

    private function isOriginAllowed(string $origin): bool
    {
        if (empty($origin)) {
            return false;
        }

        if (in_array('*', $this->allowedOrigins, true)) {
            return true;
        }

        if (in_array($origin, $this->allowedOrigins, true)) {
            return true;
        }

        foreach ($this->allowedOrigins as $allowedOrigin) {
            if (strpos($allowedOrigin, '*') !== false) {
                $pattern = '/^' . str_replace('\*', '.*', preg_quote($allowedOrigin, '/')) . '$/';
                if (preg_match($pattern, $origin)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function addCorsHeaders(Response $response, string $origin): Response
    {
        if ($this->allowCredentials && !in_array('*', $this->allowedOrigins, true)) {
            $response = $response->withHeader('Access-Control-Allow-Origin', $origin);
        } else {
            $allowedOrigin = in_array('*', $this->allowedOrigins, true) ? '*' : $origin;
            $response = $response->withHeader('Access-Control-Allow-Origin', $allowedOrigin);
        }

        if ($this->allowCredentials) {
            $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
        }

        if (!empty($this->allowedHeaders)) {
            $response = $response->withHeader('Access-Control-Expose-Headers', implode(', ', $this->allowedHeaders));
        }

        if ($response->hasHeader('Vary')) {
            $vary = $response->getHeaderLine('Vary');
            if (stripos($vary, 'Origin') === false) {
                $vary = empty($vary) ? 'Origin' : $vary . ', Origin';
                $response = $response->withHeader('Vary', $vary);
            }
        } else {
            $response = $response->withHeader('Vary', 'Origin');
        }

        return $response;
    }

    public function __invoke(Request $request, RequestHandler $handler): Response
    {
        return $this->process($request, $handler);
    }
}
