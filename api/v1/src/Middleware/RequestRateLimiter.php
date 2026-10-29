<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use PDO;
use App\Utils\RequestHelper;
use App\Utils\ApiResponseEnvelope;
use Slim\Psr7\Response as SlimResponse;

/**
 * Enforces per-client request windows configured for matching request paths.
 *
 * Counters are stored in the rate_limits table and keyed by client IP and path.
 * Client IP resolution and optional proxy trust are delegated to RequestHelper.
 */
class RequestRateLimiter implements MiddlewareInterface
{
    private PDO $db;
    // Each rule: ['path' => string, 'max' => int, 'window' => int]
    private array $rules;
    private array $ipWhitelist = [];
    private bool $trustProxy = false;
    private array $trustedProxies = [];
    private array $headersConfig = [
        'enabled' => false,
        'limit' => 'X-RateLimit-Limit',
        'remaining' => 'X-RateLimit-Remaining',
        'reset' => 'X-RateLimit-Reset',
    ];

    public function __construct(PDO $db, array $rules = [], array $options = [])
    {
        $this->db = $db;
        $this->rules = $rules ?: [
            ['path' => '/auth/login', 'max' => 10, 'window' => 600],
            ['path' => '/auth/register', 'max' => 5, 'window' => 900],
            ['path' => '/auth/forgot-password', 'max' => 5, 'window' => 1800],
            ['path' => '/auth/reset-password', 'max' => 5, 'window' => 1800],
            ['path' => '/', 'max' => 1000, 'window' => 3600]
        ];
        $this->ipWhitelist = $options['ip_whitelist'] ?? [];
        $this->trustProxy = (bool)($options['trust_proxy'] ?? false);
        $this->trustedProxies = $options['trusted_proxies'] ?? [];
        if (!empty($options['headers']) && is_array($options['headers'])) {
            $this->headersConfig = array_merge($this->headersConfig, $options['headers']);
        }
    }

    /** Apply a matching rate rule before dispatching the request. */
    public function process(Request $request, RequestHandler $handler): Response
    {
        $path = $request->getUri()->getPath();
        $rule = $this->matchRule($path);
        if ($rule === null) {
            return $handler->handle($request);
        }

        $ip = $this->getClientIP($request);
        if ($this->isWhitelisted($ip)) {
            return $handler->handle($request);
        }
        $now = time();
        $windowStartThreshold = $now - (int)$rule['window'];

        // Fetch current record
        $stmt = $this->db->prepare("SELECT id, window_start, counter FROM rate_limits WHERE ip = ? AND route = ? LIMIT 1");
        $stmt->execute([$ip, $path]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $limit = (int)$rule['max'];
        $window = (int)$rule['window'];
        $remaining = null; // compute for headers
        $resetAfter = null;

        if (!$row) {
            // Create new window with counter=1
            $this->insertOrReset($ip, $path, $now, 1);
            $remaining = max(0, $limit - 1);
            $resetAfter = $window;
            $response = $handler->handle($request);
            return $this->withRateLimitHeaders($response, $limit, $remaining, $resetAfter);
        }

        $windowStart = strtotime($row['window_start']);
        $counter = (int)$row['counter'];

        if ($windowStart < $windowStartThreshold) {
            // Reset window
            $this->updateWindow($row['id'], $now, 1);
            $remaining = max(0, $limit - 1);
            $resetAfter = $window;
            $response = $handler->handle($request);
            return $this->withRateLimitHeaders($response, $limit, $remaining, $resetAfter);
        }

        if ($counter >= $limit) {
            $retryAfter = ($windowStart + $window) - $now;
            $response = new SlimResponse();
            $response = $response
                ->withStatus(429, 'Too Many Requests')
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Retry-After', (string)max(1, $retryAfter));
            $response->getBody()->write(json_encode(ApiResponseEnvelope::normalize([
                'status' => 'error',
                'message' => 'Too many requests. Please try again later.',
                'retry_after' => max(1, $retryAfter),
            ], 429)));
            return $response;
        }

        // Increment counter
        $this->incrementCounter($row['id']);
        $remaining = max(0, $limit - ($counter + 1));
        $resetAfter = max(0, ($windowStart + $window) - $now);
        $response = $handler->handle($request);
        return $this->withRateLimitHeaders($response, $limit, $remaining, $resetAfter);
    }

    private function getClientIP(Request $request): string
    {
        return RequestHelper::getClientIp($request, $this->trustProxy, $this->trustedProxies);
    }

    private function isWhitelisted(string $ip): bool
    {
        return in_array($ip, $this->ipWhitelist, true);
    }

    private function matchRule(string $path): ?array
    {
        foreach ($this->rules as $rule) {
            if (isset($rule['path']) && strpos($path, $rule['path']) !== false) {
                return $rule;
            }
        }
        return null;
    }


    private function insertOrReset(string $ip, string $route, int $now, int $counter): void
    {
        $stmt = $this->db->prepare("INSERT INTO rate_limits (ip, route, window_start, counter) VALUES (?, ?, FROM_UNIXTIME(?), ?)");
        $stmt->execute([$ip, $route, $now, $counter]);
    }

    private function updateWindow(int $id, int $now, int $counter): void
    {
        $stmt = $this->db->prepare("UPDATE rate_limits SET window_start = FROM_UNIXTIME(?), counter = ? WHERE id = ?");
        $stmt->execute([$now, $counter, $id]);
    }

    private function incrementCounter(int $id): void
    {
        $stmt = $this->db->prepare("UPDATE rate_limits SET counter = counter + 1 WHERE id = ?");
        $stmt->execute([$id]);
    }

    private function withRateLimitHeaders(Response $response, int $limit, int $remaining, int $resetAfter): Response
    {
        if (!($this->headersConfig['enabled'] ?? false)) {
            return $response;
        }
        $hLimit = $this->headersConfig['limit'] ?? 'X-RateLimit-Limit';
        $hRemaining = $this->headersConfig['remaining'] ?? 'X-RateLimit-Remaining';
        $hReset = $this->headersConfig['reset'] ?? 'X-RateLimit-Reset';
        return $response
            ->withHeader($hLimit, (string)$limit)
            ->withHeader($hRemaining, (string)max(0, $remaining))
            ->withHeader($hReset, (string)max(0, $resetAfter));
    }
}
