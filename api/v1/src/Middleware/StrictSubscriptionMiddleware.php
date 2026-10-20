<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;
use PDO;
use Exception;
use App\Services\SecurityLogger;
use App\Utils\RequestHelper;

/**
 * Applies IP, device, rate-limit, and abuse checks to subscription endpoints.
 *
 * Uses PDO-backed security records and optionally emits events through
 * SecurityLogger; rejected requests receive a generic 403 response.
 */
class StrictSubscriptionMiddleware implements MiddlewareInterface
{
    private PDO $db;
    private array $config;
    private array $securityConfig;
    private ?SecurityLogger $securityLogger;

    public function __construct(PDO $db, ?SecurityLogger $securityLogger = null, array $config = [])
    {
        $this->securityLogger = $securityLogger;
        $this->db = $db;
        $this->config = array_merge([
            'max_attempts_per_ip' => 10,
            'max_trials_per_ip_per_day' => 1,
            'max_registrations_per_ip_per_hour' => 3,
            'risk_threshold' => 0.7,
            'enable_device_fingerprinting' => true,
            'enable_rate_limiting' => true,
            'enable_ip_blocking' => true
        ], $config);

        // Load security configuration
        $this->securityConfig = $this->loadSecurityConfig();
    }

    /** Run configured subscription security checks before dispatching the request. */
    public function process(Request $request, RequestHandler $handler): Response
    {
        $startTime = microtime(true);
        $path = $request->getUri()->getPath();
        $method = $request->getMethod();
        $ipAddress = $this->getClientIp($request);
        $userAgent = $request->getHeaderLine('User-Agent');
        $deviceFingerprint = $this->generateDeviceFingerprint($request);

        // Skip security checks for non-subscription endpoints
        if (!$this->isSubscriptionEndpoint($path)) {
            return $handler->handle($request);
        }

        $isDevelopment = ($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?? 'production') === 'development';
        if ($isDevelopment) {
            return $handler->handle($request);
        }

        try {
            // 1. IP Blocking Check
            if ($this->config['enable_ip_blocking']) {
                $this->checkIpBlocking($ipAddress, $path);
            }

            // 2. Rate Limiting Check
            if ($this->config['enable_rate_limiting']) {
                $this->checkRateLimiting($ipAddress, $path, $deviceFingerprint);
            }

            // 3. Device Fingerprinting Check
            if ($this->config['enable_device_fingerprinting']) {
                $this->checkDeviceFingerprinting($ipAddress, $deviceFingerprint, $userAgent, $path);
            }

            // 4. Suspicious Pattern Detection
            $this->detectSuspiciousPatterns($ipAddress, $path, $request);

            // 5. Log Security Event
            $this->securityLogger?->logSecurityEvent(
                'request_allowed',
                'low',
                'Request allowed after security checks',
                [
                    'ip' => $ipAddress,
                    'path' => $path,
                    'method' => $method,
                    'device_fingerprint' => $deviceFingerprint,
                    'processing_time' => microtime(true) - $startTime
                ]
            );

            $response = $handler->handle($request);

            // 6. Post-request Security Checks
            $this->postRequestSecurityChecks($request, $response, $ipAddress, $deviceFingerprint);

            return $response;

        } catch (Exception $e) {
            // Log security violation
            $this->securityLogger?->logSecurityEvent(
                'security_violation',
                'high',
                'Security check failed: ' . $e->getMessage(),
                [
                    'ip' => $ipAddress,
                    'path' => $path,
                    'method' => $method,
                    'device_fingerprint' => $deviceFingerprint,
                    'error' => $e->getMessage()
                ]
            );

            // Block suspicious IPs
            if ($this->config['enable_ip_blocking'] && $this->shouldBlockIp($e)) {
                $this->blockIp($ipAddress, 'suspicious_activity', 'Automatic block due to security violation', 3600);
            }

            // Return error response
            $response = new SlimResponse();
            $response->getBody()->write(json_encode([
                'status' => 'error',
                'message' => 'Access denied',
                'code' => 'SECURITY_VIOLATION'
            ], JSON_UNESCAPED_UNICODE));

            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(403);
        }
    }

    /**
     * Check if IP is blocked
     */
    private function checkIpBlocking(string $ipAddress, string $path): void
    {
        $stmt = $this->db->prepare("
            SELECT * FROM blocked_ips 
            WHERE ip_address = ? 
            AND (is_permanent = TRUE OR blocked_until > NOW())
        ");
        $stmt->execute([$ipAddress]);
        $blocked = $stmt->fetch();

        if ($blocked) {
            // Update attempt count
            $updateStmt = $this->db->prepare("
                UPDATE blocked_ips 
                SET attempt_count = attempt_count + 1, 
                    last_attempt_at = NOW() 
                WHERE id = ?
            ");
            $updateStmt->execute([$blocked['id']]);

            throw new Exception("IP address is blocked: {$blocked['reason']}");
        }
    }

    /**
     * Check rate limiting
     */
    private function checkRateLimiting(string $ipAddress, string $path, string $deviceFingerprint): void
    {
        // Get applicable rate limiting rules
        $stmt = $this->db->prepare("
            SELECT * FROM rate_limiting_rules 
            WHERE is_active = TRUE 
            AND ? LIKE endpoint_pattern
            ORDER BY priority DESC
        ");
        $stmt->execute([$path]);
        $rules = $stmt->fetchAll();

        foreach ($rules as $rule) {
            $this->checkRule($rule, $ipAddress, $deviceFingerprint, $path);
        }
    }

    /**
     * Apply a database-defined rate-limit rule to the available identifiers.
     */
    private function checkRule(array $rule, string $ipAddress, string $deviceFingerprint, string $path): void
    {
        $identifiers = [];

        // Priority 1: Use tenant_id:user_id if available (per-user rate limiting)
        $tenantUserId = $this->getTenantUserIdentifier();
        if ($tenantUserId !== null) {
            $identifiers[] = ['type' => 'tenant_user', 'value' => $tenantUserId];
        }

        // Priority 2: IP-based (fallback for unauthenticated requests)
        if ($rule['ip_based'] && empty($identifiers)) {
            $identifiers[] = ['type' => 'ip', 'value' => $ipAddress];
        }

        // Priority 3: Device-based
        if ($rule['device_based'] && !empty($deviceFingerprint)) {
            $identifiers[] = ['type' => 'device', 'value' => $deviceFingerprint];
        }

        foreach ($identifiers as $identifier) {
            $windowStart = date('Y-m-d H:i:s', time() - ($rule['window_minutes'] * 60));

            // Check existing tracking record
            $stmt = $this->db->prepare("
                SELECT * FROM rate_limiting_tracking 
                WHERE rule_id = ? 
                AND identifier = ? 
                AND identifier_type = ?
                AND window_start = ?
            ");
            $stmt->execute([$rule['id'], $identifier['value'], $identifier['type'], $windowStart]);
            $tracking = $stmt->fetch();

            if ($tracking) {
                // Check if currently penalized
                if ($tracking['is_penalized'] && $tracking['penalty_until'] > date('Y-m-d H:i:s')) {
                    throw new Exception("Rate limit penalty active until {$tracking['penalty_until']}");
                }

                // Check request count
                if ($tracking['request_count'] >= $rule['max_requests']) {
                    $this->applyRateLimitPenalty($rule, $tracking, $identifier);
                    throw new Exception("Rate limit exceeded: {$rule['max_requests']} requests per {$rule['window_minutes']} minutes");
                }

                // Update request count
                $updateStmt = $this->db->prepare("
                    UPDATE rate_limiting_tracking 
                    SET request_count = request_count + 1,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $updateStmt->execute([$tracking['id']]);
            } else {
                // Create new tracking record
                $windowEnd = date('Y-m-d H:i:s', time() + ($rule['window_minutes'] * 60));
                $insertStmt = $this->db->prepare("
                    INSERT INTO rate_limiting_tracking 
                    (rule_id, identifier, identifier_type, window_start, window_end)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $insertStmt->execute([$rule['id'], $identifier['value'], $identifier['type'], $windowStart, $windowEnd]);
            }
        }
    }

    /**
     * Extract tenant_id:user_id from JWT token
     * 
     * Returns format: "5:10" (tenant_id:user_id)
     * Returns null if no valid JWT found
     */
    private function getTenantUserIdentifier(): ?string
    {
        // Try to get JWT from Authorization header
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
        
        if (!$authHeader || !preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            return null;
        }

        $jwt = $matches[1];

        try {
            // Decode JWT (without verification for speed - only extracting IDs)
            $parts = explode('.', $jwt);
            if (count($parts) !== 3) {
                return null;
            }

            $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[1])), true);
            
            if (!$payload || !isset($payload['tenant_id']) || !isset($payload['user_id'])) {
                return null;
            }

            $tenantId = (int) $payload['tenant_id'];
            $userId = (int) $payload['user_id'];

            // Validate IDs are positive integers
            if ($tenantId <= 0 || $userId <= 0) {
                return null;
            }

            return "{$tenantId}:{$userId}";

        } catch (\Exception $e) {
            // Silently fail - will fallback to IP-based rate limiting
            return null;
        }
    }

    /**
     * Apply rate limiting penalty
     */
    private function applyRateLimitPenalty(array $rule, array $tracking, array $identifier): void
    {
        $penaltyMinutes = $rule['penalty_minutes'];
        $multiplier = $rule['penalty_multiplier'];

        // Increase penalty for repeated violations
        if ($tracking['total_violations'] > 0) {
            $penaltyMinutes = (int)($penaltyMinutes * $multiplier * $tracking['total_violations']);
        }

        $penaltyUntil = date('Y-m-d H:i:s', time() + ($penaltyMinutes * 60));

        $updateStmt = $this->db->prepare("
            UPDATE rate_limiting_tracking 
            SET is_penalized = TRUE,
                penalty_until = ?,
                total_violations = total_violations + 1,
                last_violation_at = NOW()
            WHERE id = ?
        ");
        $updateStmt->execute([$penaltyUntil, $tracking['id']]);

        // Log penalty application
        $this->securityLogger?->logSecurityEvent(
            'rate_limit_penalty',
            'medium',
            "Rate limit penalty applied: {$penaltyMinutes} minutes",
            [
                'rule_id' => $rule['id'],
                'rule_name' => $rule['rule_name'],
                'identifier' => $identifier['value'],
                'identifier_type' => $identifier['type'],
                'penalty_until' => $penaltyUntil,
                'total_violations' => $tracking['total_violations'] + 1
            ]
        );
    }

    /**
     * Check device fingerprinting
     */
    private function checkDeviceFingerprinting(string $ipAddress, string $deviceFingerprint, string $userAgent, string $path): void
    {
        if (empty($deviceFingerprint)) {
            return;
        }

        // Store or update device fingerprint
        $this->storeDeviceFingerprint($deviceFingerprint, $ipAddress, $userAgent);

        // Check for suspicious device patterns
        $stmt = $this->db->prepare("
            SELECT * FROM device_fingerprints 
            WHERE fingerprint_hash = ?
            AND (is_suspicious = TRUE OR risk_score >= ?)
        ");
        $stmt->execute([$deviceFingerprint, $this->config['risk_threshold']]);
        $suspicious = $stmt->fetch();

        if ($suspicious) {
            throw new Exception("Suspicious device detected (risk score: {$suspicious['risk_score']})");
        }

        // Check for too many accounts associated with single device
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as account_count 
            FROM account_device_associations ada
            JOIN device_fingerprints df ON ada.device_fingerprint_id = df.id
            WHERE df.fingerprint_hash = ?
            AND ada.association_type = 'primary'
            AND ada.is_blocked = FALSE
        ");
        $stmt->execute([$deviceFingerprint]);
        $accountCount = $stmt->fetchColumn();

        if ($accountCount > 5) { // Configurable threshold
            $this->flagDeviceAsSuspicious($deviceFingerprint, 'too_many_accounts', "Device associated with {$accountCount} accounts");
            throw new Exception("Device associated with too many accounts");
        }
    }

    /**
     * Store device fingerprint
     */
    private function storeDeviceFingerprint(string $deviceFingerprint, string $ipAddress, string $userAgent): void
    {
        $fingerprintHash = hash('sha256', $deviceFingerprint);
        $userAgentHash = hash('sha256', $userAgent);

        $stmt = $this->db->prepare("
            INSERT INTO device_fingerprints 
            (fingerprint_hash, ip_address, user_agent_hash, first_seen_at, last_seen_at)
            VALUES (?, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                last_seen_at = NOW(),
                usage_count = usage_count + 1
        ");
        $stmt->execute([$fingerprintHash, $ipAddress, $userAgentHash]);
    }

    /**
     * Flag device as suspicious
     */
    private function flagDeviceAsSuspicious(string $deviceFingerprint, string $reason, string $description): void
    {
        $fingerprintHash = hash('sha256', $deviceFingerprint);

        $stmt = $this->db->prepare("
            UPDATE device_fingerprints 
            SET is_suspicious = TRUE,
                risk_score = LEAST(risk_score + 0.3, 1.0),
                last_seen_at = NOW()
            WHERE fingerprint_hash = ?
        ");
        $stmt->execute([$fingerprintHash]);

        $this->securityLogger?->logSecurityEvent(
            'device_flagged',
            'medium',
            "Device flagged as suspicious: {$reason}",
            [
                'device_fingerprint' => $deviceFingerprint,
                'reason' => $reason,
                'description' => $description
            ]
        );
    }

    /**
     * Detect suspicious patterns
     */
    private function detectSuspiciousPatterns(string $ipAddress, string $path, Request $request): void
    {
        // Check for rapid registration attempts
        if (strpos($path, '/register') !== false) {
            $this->checkRapidRegistrations($ipAddress);
        }

        // Check for trial abuse patterns
        if (strpos($path, '/trial') !== false) {
            $this->checkTrialAbuse($ipAddress);
        }

        // Check for timing attacks
        $this->checkTimingAttacks($ipAddress, $path);
    }

    /**
     * Check for rapid registrations
     */
    private function checkRapidRegistrations(string $ipAddress): void
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count 
            FROM subscription_attempts 
            WHERE ip_address = ? 
            AND attempt_type = 'registration'
            AND attempted_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $stmt->execute([$ipAddress]);
        $count = $stmt->fetchColumn();

        if ($count > $this->config['max_registrations_per_ip_per_hour']) {
            $this->blockIp($ipAddress, 'trial_abuse', 'Too many registration attempts', 7200);
            throw new Exception("Too many registration attempts from this IP");
        }
    }

    /**
     * Check for trial abuse
     */
    private function checkTrialAbuse(string $ipAddress): void
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count 
            FROM subscription_attempts 
            WHERE ip_address = ? 
            AND attempt_type = 'trial_creation'
            AND status = 'success'
            AND attempted_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        $stmt->execute([$ipAddress]);
        $count = $stmt->fetchColumn();

        if ($count > $this->config['max_trials_per_ip_per_day']) {
            $this->blockIp($ipAddress, 'trial_abuse', 'Trial abuse detected', 86400);
            throw new Exception("Trial abuse detected from this IP");
        }
    }

    /**
     * Check for timing attacks
     */
    private function checkTimingAttacks(string $ipAddress, string $path): void
    {
        // Check for multiple requests in short time period
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count,
                   MIN(attempted_at) as first_attempt,
                   MAX(attempted_at) as last_attempt
            FROM subscription_attempts 
            WHERE ip_address = ? 
            AND attempted_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)
        ");
        $stmt->execute([$ipAddress]);
        $result = $stmt->fetch();

        if ($result['count'] > 20) { // More than 20 requests in 1 minute
            $timeSpan = strtotime($result['last_attempt']) - strtotime($result['first_attempt']);
            if ($timeSpan < 30) { // All within 30 seconds
                $this->blockIp($ipAddress, 'suspicious_activity', 'Timing attack detected', 3600);
                throw new Exception("Suspicious activity detected from this IP");
            }
        }
    }

    /**
     * Post-request security checks
     */
    private function postRequestSecurityChecks(Request $request, Response $response, string $ipAddress, string $deviceFingerprint): void
    {
        $statusCode = $response->getStatusCode();
        $path = $request->getUri()->getPath();

        // Log failed requests
        if ($statusCode >= 400) {
            $this->securityLogger?->logSecurityEvent('request_failed', 'medium', "Request failed with status {$statusCode}", [
                'ip' => $ipAddress,
                'path' => $path,
                'status_code' => $statusCode,
                'device_fingerprint' => $deviceFingerprint
            ]);
        }

        // Check for successful registration/trial creation
        if ($statusCode === 200 || $statusCode === 201) {
            if (strpos($path, '/register') !== false) {
                $this->logSubscriptionAttempt($ipAddress, 'registration', 'success', $request);
            } elseif (strpos($path, '/trial') !== false) {
                $this->logSubscriptionAttempt($ipAddress, 'trial_creation', 'success', $request);
            }
        }
    }

    /**
     * Block IP address
     */
    private function blockIp(string $ipAddress, string $reason, string $notes, int $durationSeconds = 3600): void
    {
        $blockedUntil = date('Y-m-d H:i:s', time() + $durationSeconds);

        $stmt = $this->db->prepare("
            INSERT INTO blocked_ips 
            (ip_address, reason, blocked_at, blocked_until, is_permanent, notes)
            VALUES (?, ?, NOW(), ?, FALSE, ?)
            ON DUPLICATE KEY UPDATE
                blocked_until = VALUES(blocked_until),
                notes = VALUES(notes),
                attempt_count = attempt_count + 1
        ");
        $stmt->execute([$ipAddress, $reason, $blockedUntil, $notes]);

        $this->securityLogger?->logSecurityEvent(
            'ip_blocked',
            'high',
            "IP blocked: {$reason}",
            [
                'ip' => $ipAddress,
                'reason' => $reason,
                'blocked_until' => $blockedUntil,
                'duration_seconds' => $durationSeconds
            ]
        );
    }

    /**
     * Check if IP should be blocked based on exception
     */
    private function shouldBlockIp(Exception $e): bool
    {
        $message = strtolower($e->getMessage());

        // Block for security-related exceptions
        $blockReasons = [
            'rate limit',
            'blocked',
            'suspicious',
            'abuse',
            'timing attack',
            'security violation'
        ];

        foreach ($blockReasons as $reason) {
            if (strpos($message, $reason) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Log subscription attempt
     */
    private function logSubscriptionAttempt(string $ipAddress, string $attemptType, string $status, Request $request): void
    {
        $deviceFingerprint = $this->generateDeviceFingerprint($request);
        $userAgent = $request->getHeaderLine('User-Agent');
        $requestData = $request->getParsedBody() ?? [];

        $stmt = $this->db->prepare("
            INSERT INTO subscription_attempts 
            (ip_address, attempt_type, status, attempt_data, device_fingerprint, user_agent)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $ipAddress,
            $attemptType,
            $status,
            json_encode($requestData),
            $deviceFingerprint,
            $userAgent
        ]);
    }



    /**
     * Check if endpoint is subscription-related
     */
    private function isSubscriptionEndpoint(string $path): bool
    {
        $subscriptionPaths = [
            '/auth/register',
            '/auth/trial',
            '/subscription',
            '/payment',
            '/plan',
            '/billing'
        ];

        foreach ($subscriptionPaths as $protectedPath) {
            if (strpos($path, $protectedPath) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate device fingerprint
     */
    private function generateDeviceFingerprint(Request $request): string
    {
        $userAgent = $request->getHeaderLine('User-Agent');
        $acceptLanguage = $request->getHeaderLine('Accept-Language');
        $acceptEncoding = $request->getHeaderLine('Accept-Encoding');

        // Get client hints if available
        $clientHints = [];
        if ($request->hasHeader('Sec-CH-UA')) {
            $clientHints['ua'] = $request->getHeaderLine('Sec-CH-UA');
        }
        if ($request->hasHeader('Sec-CH-UA-Mobile')) {
            $clientHints['mobile'] = $request->getHeaderLine('Sec-CH-UA-Mobile');
        }
        if ($request->hasHeader('Sec-CH-UA-Platform')) {
            $clientHints['platform'] = $request->getHeaderLine('Sec-CH-UA-Platform');
        }

        $fingerprintData = [
            'user_agent' => $userAgent,
            'accept_language' => $acceptLanguage,
            'accept_encoding' => $acceptEncoding,
            'client_hints' => $clientHints,
            'timestamp' => floor(time() / 3600) // Hour granularity to prevent exact tracking
        ];

        return hash('sha256', json_encode($fingerprintData));
    }

    /**
     * Get client IP address — delegates to RequestHelper (single source of truth).
     */
    private function getClientIp(Request $request): string
    {
        return RequestHelper::getClientIp($request);
    }

    /**
     * Load security configuration
     */
    private function loadSecurityConfig(): array
    {
        // Default security configuration
        return [
            'risk_threshold' => 0.7,
            'max_accounts_per_device' => 5,
            'max_registrations_per_ip_per_hour' => 3,
            'max_trials_per_ip_per_day' => 1,
            'rate_limit_penalty_multiplier' => 2.0,
            'auto_block_threshold' => 10,
            'device_fingerprint_expiry' => 86400 * 30, // 30 days
            'security_event_retention' => 86400 * 90, // 90 days
        ];
    }
}
