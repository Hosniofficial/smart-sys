<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Psr\Log\LoggerInterface;
use App\Services\SecurityLogger;
use App\Utils\RequestHelper;

/**
 * Records HTTP request metadata and error responses through security and app loggers.
 *
 * Optional body/header logging applies configured redaction; health and other
 * configured paths can be excluded from logging.
 */
class RequestLoggingMiddleware implements MiddlewareInterface
{
    private array $config;
    private SecurityLogger $securityLogger;
    private ?LoggerInterface $logger;

    public function __construct(
        SecurityLogger $securityLogger,
        ?LoggerInterface $logger = null,
        array $config = []
    ) {
        $this->securityLogger = $securityLogger;
        $this->logger = $logger;
        $this->config = array_merge([
            'enabled' => true,
            'log_body' => false, // Be careful with logging sensitive data
            'log_headers' => false, // Be careful with logging sensitive headers
            'exclude_paths' => [
                '/health',
                '/status',
                '/metrics',
            ],
            'sensitive_headers' => [
                'authorization',
                'cookie',
                'set-cookie',
                'x-api-key',
            ],
            'sensitive_params' => [
                'password',
                'new_password',
                'current_password',
                'confirm_password',
                'token',
                'api_key',
                'access_token',
                'refresh_token',
            ],
        ], $config);
    }

    /** Log the request, dispatch it, then record response status and errors. */
    public function process(Request $request, RequestHandler $handler): Response
    {
        if (!$this->config['enabled']) {
            return $handler->handle($request);
        }

        $path = $request->getUri()->getPath();

        foreach ($this->config['exclude_paths'] as $excludedPath) {
            if (strpos($path, $excludedPath) === 0) {
                return $handler->handle($request);
            }
        }

        $this->logRequest($request);

        $response = $handler->handle($request);

        $this->logResponse($request, $response);

        return $response;
    }

    private function logRequest(Request $request): void
    {
        $method = $request->getMethod();
        $uri = (string) $request->getUri();
        $ip = $this->getClientIp($request);
        $userAgent = $request->getHeaderLine('User-Agent');
        $userId = $request->getAttribute('user_id');
        $tenantId = $request->getAttribute('tenant_id');

        $context = [
            'method' => $method,
            'uri' => $uri,
            'ip' => $ip,
            'user_agent' => $userAgent,
            'user_id' => $userId,
            'tenant_id' => $tenantId,
        ];

        // Add headers if configured
        if ($this->config['log_headers']) {
            $headers = [];
            foreach ($request->getHeaders() as $name => $values) {
                $normalizedName = strtolower($name);
                if (in_array($normalizedName, $this->config['sensitive_headers'], true)) {
                    $headers[$name] = '***REDACTED***';
                } else {
                    $headers[$name] = $values;
                }
            }
            $context['headers'] = $headers;
        }

        // Add request body if configured and not too large
        if ($this->config['log_body'] && $request->getBody()->getSize() < 1048576) { // 1MB max
            $body = (string) $request->getBody();
            $contentType = $request->getHeaderLine('Content-Type');

            if (strpos($contentType, 'application/json') !== false) {
                $params = json_decode($body, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $params = $this->redactSensitiveData($params);
                    $context['body'] = $params;
                } else {
                    $context['body'] = '***INVALID_JSON***';
                }
            } elseif (strpos($contentType, 'application/x-www-form-urlencoded') !== false) {
                parse_str($body, $params);
                $params = $this->redactSensitiveData($params);
                $context['body'] = $params;
            } elseif (!empty($body)) {
                $context['body'] = '***BINARY_OR_UNSUPPORTED_CONTENT_TYPE***';
            }

            // Rewind the body for the next middleware
            $request->getBody()->rewind();
        }

        // Log to security logger
        $this->securityLogger->logSecurityEvent(
            'http_request',
            'info',
            'HTTP request logged',
            $context,
            $userId,
            $tenantId,
            $request
        );

        // Also log to application logger if available
        if ($this->logger) {
            $this->logger->info(
                sprintf('%s %s', $method, $uri),
                $context
            );
        }
    }

    private function logResponse(Request $request, Response $response): void
    {
        $statusCode = $response->getStatusCode();
        $contentType = $response->getHeaderLine('Content-Type');
        $userId = $request->getAttribute('user_id');
        $tenantId = $request->getAttribute('tenant_id');

        $context = [
            'status_code' => $statusCode,
            'content_type' => $contentType,
            'user_id' => $userId,
            'tenant_id' => $tenantId,
        ];

        // Log error responses with more details
        if ($statusCode >= 400) {
            $body = (string) $response->getBody();

            // Try to parse error response
            if (strpos($contentType, 'application/json') !== false) {
                $errorData = json_decode($body, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $context['error'] = $errorData;
                } else {
                    $context['error'] = '***INVALID_JSON***';
                }
            } else {
                $context['error'] = $body;
            }

            // Log to security logger for error responses
            $this->securityLogger->logSecurityEvent(
                'http_error_response',
                'error',
                'HTTP error response',
                $context,
                $userId,
                $tenantId,
                $request
            );

            // Rewind the body for the next middleware
            $response->getBody()->rewind();
        }

        // Log to application logger if available
        if ($this->logger) {
            $logLevel = $statusCode >= 500 ? 'error' : ($statusCode >= 400 ? 'warning' : 'info');
            $this->logger->$logLevel(
                sprintf('HTTP %d: %s %s', $statusCode, $request->getMethod(), $request->getUri()->getPath()),
                $context
            );
        }
    }

    private function redactSensitiveData(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->redactSensitiveData($value);
            } elseif (in_array(strtolower($key), $this->config['sensitive_params'], true)) {
                $data[$key] = '***REDACTED***';
            }
        }
        return $data;
    }

    private function getClientIp(Request $request): string
    {
        return RequestHelper::getClientIp($request);
    }
}
