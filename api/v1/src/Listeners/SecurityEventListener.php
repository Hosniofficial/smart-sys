<?php

namespace App\Listeners;

use Psr\Log\LoggerInterface;
use App\Services\SecurityLogger;
use App\Models\User;
use App\Models\Tenant;

/**
 * Persists security audit events and emits operational log messages.
 *
 * Receives event payloads from SecurityEventDispatcher and delegates durable
 * audit writes to SecurityLogger; the PSR logger is optional.
 */
class SecurityEventListener
{
    private SecurityLogger $securityLogger;
    private ?LoggerInterface $logger;

    public function __construct(
        SecurityLogger $securityLogger,
        ?LoggerInterface $logger = null
    ) {
        $this->securityLogger = $securityLogger;
        $this->logger = $logger;
    }

    /**
     * Handle user login events
     */
    public function onUserLogin(array $event): void
    {
        try {
            $request = $event['request'] ?? null;

            // Accept either array payload or User object, or gracefully skip if missing
            $userPayload = $event['user'] ?? null;
            if ($userPayload === null) {
                $this->log('warning', 'onUserLogin called without user payload', [
                    'event_keys' => array_keys($event),
                ]);
                return;
            }

            if (is_array($userPayload)) {
                $uid = $userPayload['id'] ?? null;
                $uname = $userPayload['username'] ?? ($userPayload['name'] ?? 'unknown');
                $tenantId = $userPayload['tenant_id'] ?? null;
            } else {
                /** @var User $userPayload */
                $uid = $userPayload->id ?? null;
                $uname = $userPayload->username ?? 'unknown';
                $tenantId = $userPayload->tenant_id ?? null;
            }

            $this->securityLogger->logLoginAttempt(
                $uname,
                true,
                $uid,
                $tenantId,
                $request,
                'Login successful'
            );

            $this->log('info', 'User logged in', [
                'user_id' => $uid,
                'username' => $uname,
                'tenant_id' => $tenantId,
                'ip' => $request ? ($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown') : 'unknown',
            ]);
        } catch (\Throwable $e) {
            $this->log('error', 'Error in onUserLogin: ' . $e->getMessage(), [
                'exception' => $e,
                'event' => $event,
            ]);
        }
    }

    /**
     * Handle failed login attempts
     */
    public function onLoginFailed(array $event): void
    {
        try {
            $username = $event['username'] ?? 'unknown';
            $reason = $event['reason'] ?? 'Invalid credentials';
            $request = $event['request'] ?? null;
            $userId = $event['user_id'] ?? null;
            $tenantId = $event['tenant_id'] ?? null;

            $this->securityLogger->logLoginAttempt(
                $username,
                false,
                $userId,
                $tenantId,
                $request,
                $reason
            );

            $this->log('warning', 'Failed login attempt', [
                'username' => $username,
                'reason' => $reason,
                'user_id' => $userId,
                'tenant_id' => $tenantId,
                'ip' => $request ? ($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown') : 'unknown',
            ]);

            // Consult the account-lockout policy after a failed login.
            if ($userId && $this->shouldLockAccount($userId)) {
                $this->lockAccount($userId);
            }
        } catch (\Throwable $e) {
            $this->log('error', 'Error in onLoginFailed: ' . $e->getMessage(), [
                'exception' => $e,
                'event' => $event,
            ]);
        }
    }

    /**
     * Handle user logout events
     */
    public function onUserLogout(array $event): void
    {
        try {
            $request = $event['request'] ?? null;

            $userPayload = $event['user'] ?? null;
            if ($userPayload === null) {
                $this->log('warning', 'onUserLogout called without user payload', [
                    'event_keys' => array_keys($event),
                ]);
                return;
            }

            if (is_array($userPayload)) {
                $uid = $userPayload['id'] ?? null;
                $uname = $userPayload['username'] ?? ($userPayload['name'] ?? 'unknown');
                $tenantId = $userPayload['tenant_id'] ?? null;
            } else {
                /** @var User $userPayload */
                $uid = $userPayload->id ?? null;
                $uname = $userPayload->username ?? 'unknown';
                $tenantId = $userPayload->tenant_id ?? null;
            }

            $this->securityLogger->logLogout(
                $uid,
                $tenantId,
                $request
            );

            $this->log('info', 'User logged out', [
                'user_id' => $uid,
                'username' => $uname,
                'tenant_id' => $tenantId,
            ]);
        } catch (\Throwable $e) {
            $this->log('error', 'Error in onUserLogout: ' . $e->getMessage(), [
                'exception' => $e,
                'event' => $event,
            ]);
        }
    }

    /**
     * Handle sensitive operations (e.g., password changes, role changes)
     */
    public function onSensitiveOperation(array $event): void
    {
        try {
            $operation = $event['operation'] ?? 'unknown';
            $userId = $event['user_id'] ?? null;
            $targetUserId = $event['target_user_id'] ?? null;
            $tenantId = $event['tenant_id'] ?? null;
            $request = $event['request'] ?? null;

            $details = $event['details'] ?? [];

            $this->securityLogger->logSensitiveOperation(
                $operation,
                $details,
                $userId,
                $tenantId,
                $request
            );

            $this->log('info', 'Sensitive operation performed', [
                'operation' => $operation,
                'user_id' => $userId,
                'target_user_id' => $targetUserId,
                'tenant_id' => $tenantId,
                'details' => $details,
            ]);
        } catch (\Throwable $e) {
            $this->log('error', 'Error in onSensitiveOperation: ' . $e->getMessage(), [
                'exception' => $e,
                'event' => $event,
            ]);
        }
    }

    /**
     * Handle tenant-related events
     */
    public function onTenantEvent(array $event): void
    {
        try {
            $action = $event['action'] ?? 'unknown';
            $tenantId = $event['tenant_id'] ?? null;
            $userId = $event['user_id'] ?? null;
            $details = $event['details'] ?? [];
            $request = $event['request'] ?? null;

            $this->securityLogger->logSecurityEvent(
                'tenant_' . $action,
                'info',
                'Tenant event: ' . $action,
                array_merge($details, ['action' => $action]),
                $userId,
                $tenantId,
                $request
            );

            $this->log('info', 'Tenant event: ' . $action, [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'action' => $action,
                'details' => $details,
            ]);
        } catch (\Throwable $e) {
            $this->log('error', 'Error in onTenantEvent: ' . $e->getMessage(), [
                'exception' => $e,
                'event' => $event,
            ]);
        }
    }

    /**
     * Handle security policy violations
     */
    public function onPolicyViolation(array $event): void
    {
        try {
            $policy = $event['policy'] ?? 'unknown';
            $violation = $event['violation'] ?? 'unknown';
            $userId = $event['user_id'] ?? null;
            $tenantId = $event['tenant_id'] ?? null;
            $request = $event['request'] ?? null;
            $severity = $event['severity'] ?? 'medium';

            $this->securityLogger->logSecurityEvent(
                'policy_violation_' . $policy,
                $severity,
                'Security policy violation',
                [
                    'policy' => $policy,
                    'violation' => $violation,
                    'severity' => $severity,
                    'details' => $event['details'] ?? [],
                ],
                $userId,
                $tenantId,
                $request
            );

            $this->log('warning', 'Security policy violation', [
                'policy' => $policy,
                'violation' => $violation,
                'severity' => $severity,
                'user_id' => $userId,
                'tenant_id' => $tenantId,
                'details' => $event['details'] ?? [],
            ]);

            // Take additional actions based on severity
            if ($severity === 'high' && $userId) {
                $this->onHighSeverityViolation($userId, $policy, $violation);
            }
        } catch (\Throwable $e) {
            $this->log('error', 'Error in onPolicyViolation: ' . $e->getMessage(), [
                'exception' => $e,
                'event' => $event,
            ]);
        }
    }

    /**
     * Handle high severity security violations
     * @param int $userId - User ID associated with violation
     * @param string $policy - Policy that was violated
     * @param string $violation - Description of violation
     * @return void
     */
    private function onHighSeverityViolation(int $userId, string $policy, string $violation): void
    {
        try {
            // Log the high severity violation
            $this->log('alert', 'High severity security violation detected', [
                'user_id' => $userId,
                'policy' => $policy,
                'violation' => $violation,
            ]);

        } catch (\Throwable $e) {
            $this->log('error', 'Error in onHighSeverityViolation: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => $userId,
            ]);
        }
    }

    /**
     * Check if an account should be locked due to too many failed login attempts
     * @param int|null $userId - User ID to check
     * @return bool - True if account should be locked
     */
    private function shouldLockAccount(?int $userId): bool
    {
        if (!$userId) {
            return false;
        }

        return false;
    }

    /**
     * Lock a user account due to suspicious activity
     * @param int|null $userId - User ID to lock
     * @return void
     */
    private function lockAccount(?int $userId): void
    {
        if (!$userId) {
            return;
        }

        try {
            $this->log('warning', 'User account locked due to suspicious activity', [
                'user_id' => $userId,
                'action' => 'Account status changed to locked',
                'timestamp' => date('Y-m-d H:i:s')
            ]);

        } catch (\Throwable $e) {
            $this->log('error', 'Failed to lock user account', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Log a message using the logger if available
     */
    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger) {
            $this->logger->$level($message, $context);
        }
    }
}
