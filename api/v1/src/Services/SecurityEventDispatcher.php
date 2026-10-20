<?php

namespace App\Services;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use App\Listeners\SecurityEventListener;

/**
 * Resolves registered listeners and dispatches security-event payloads.
 *
 * The container supplies listener instances; failures are recorded through an
 * optional PSR logger and do not propagate to the event producer.
 */
class SecurityEventDispatcher
{
    private ContainerInterface $container;
    private array $listeners = [];
    private ?LoggerInterface $logger;
    private bool $initialized = false;

    // Event identifiers
    public const EVENT_USER_LOGIN = 'user.login';
    public const EVENT_LOGIN_FAILED = 'login.failed';
    public const EVENT_USER_LOGOUT = 'user.logout';
    public const EVENT_PASSWORD_CHANGE = 'user.password_change';
    public const EVENT_ROLE_CHANGE = 'user.role_change';
    public const EVENT_PERMISSION_CHANGE = 'user.permission_change';
    public const EVENT_TENANT_CREATED = 'tenant.created';
    public const EVENT_TENANT_UPDATED = 'tenant.updated';
    public const EVENT_TENANT_DELETED = 'tenant.deleted';
    public const EVENT_POLICY_VIOLATION = 'security.policy_violation';
    public const EVENT_RATE_LIMIT_EXCEEDED = 'security.rate_limit_exceeded';
    public const EVENT_SUSPICIOUS_ACTIVITY = 'security.suspicious_activity';
    public const EVENT_API_KEY_CREATED = 'api_key.created';
    public const EVENT_API_KEY_REVOKED = 'api_key.revoked';
    public const EVENT_ACCOUNT_LOCKED = 'account.locked';
    public const EVENT_ACCOUNT_UNLOCKED = 'account.unlocked';
    public const EVENT_DATA_EXPORT = 'data.export';
    public const EVENT_DATA_IMPORT = 'data.import';

    public function __construct(ContainerInterface $container, ?LoggerInterface $logger = null)
    {
        $this->container = $container;
        $this->logger = $logger;
    }

    /** Register the default wildcard listener once. */
    public function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        $this->addListener(SecurityEventListener::class);

        $this->initialized = true;
    }

    /** Register a listener for one event type or for all events. */
    public function addListener(string $listenerClass, ?string $eventType = null, int $priority = 0): void
    {
        $key = $eventType ?? '*';
        $this->listeners[$key][$priority][] = $listenerClass;

        krsort($this->listeners[$key]);
    }

    /** Resolve and invoke listeners for an event, containing listener failures. */
    public function dispatch(string $eventType, array $eventData = []): void
    {
        try {
            $this->initialize();

            $listeners = array_merge(
                $this->getListenersForEvent($eventType),
                $this->getListenersForEvent('*')
            );

            foreach ($listeners as $listenerClass) {
                try {
                    $listener = $this->container->get($listenerClass);
                    $method = $this->getHandlerMethod($eventType);

                    if (method_exists($listener, $method)) {
                        $listener->$method(array_merge(['event_type' => $eventType], $eventData));
                    } elseif (method_exists($listener, '__invoke')) {
                        $listener->__invoke(array_merge(['event_type' => $eventType], $eventData));
                    }
                } catch (\Throwable $e) {
                    $this->logError('Error dispatching event to listener: ' . $e->getMessage(), [
                        'event_type' => $eventType,
                        'listener' => $listenerClass,
                        'exception' => $e,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            $this->logError('Error in event dispatcher: ' . $e->getMessage(), [
                'event_type' => $eventType,
                'exception' => $e,
            ]);
        }
    }

    /** Convert a dotted event identifier into its conventional handler name. */
    private function getHandlerMethod(string $eventType): string
    {
        $parts = explode('.', $eventType);
        $method = 'on' . str_replace(' ', '', ucwords(str_replace('_', ' ', $parts[0])));

        if (count($parts) > 1) {
            $method .= str_replace(' ', '', ucwords(str_replace('_', ' ', $parts[1])));
        }

        return $method;
    }

    /** Return listeners for an event in descending priority order. */
    private function getListenersForEvent(string $eventType): array
    {
        $listeners = [];

        if (isset($this->listeners[$eventType])) {
            foreach ($this->listeners[$eventType] as $priorityListeners) {
                $listeners = array_merge($listeners, $priorityListeners);
            }
        }

        return $listeners;
    }

    /** Log dispatcher failures when a logger was supplied. */
    private function logError(string $message, array $context = []): void
    {
        if ($this->logger) {
            $this->logger->error($message, $context);
        }
    }

    /** Convenience dispatchers for common event payloads. */

    public function dispatchUserLogin($user, $request = null): void
    {
        $this->dispatch(self::EVENT_USER_LOGIN, [
            'user' => $user,
            'request' => $request,
        ]);
    }

    public function dispatchLoginFailed($username, $reason = 'Invalid credentials', $userId = null, $tenantId = null, $request = null): void
    {
        $this->dispatch(self::EVENT_LOGIN_FAILED, [
            'username' => $username,
            'reason' => $reason,
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'request' => $request,
        ]);
    }

    public function dispatchUserLogout($user, $request = null): void
    {
        $this->dispatch(self::EVENT_USER_LOGOUT, [
            'user' => $user,
            'request' => $request,
        ]);
    }

    public function dispatchPasswordChange($userId, $targetUserId = null, $tenantId = null, $request = null): void
    {
        $this->dispatch(self::EVENT_PASSWORD_CHANGE, [
            'user_id' => $userId,
            'target_user_id' => $targetUserId ?: $userId,
            'tenant_id' => $tenantId,
            'request' => $request,
            'details' => [
                'changed_by_self' => $targetUserId === null || $userId === $targetUserId,
            ],
        ]);
    }

    public function dispatchRoleChange($userId, $targetUserId, $oldRole, $newRole, $tenantId = null, $request = null): void
    {
        $this->dispatch(self::EVENT_ROLE_CHANGE, [
            'user_id' => $userId,
            'target_user_id' => $targetUserId,
            'tenant_id' => $tenantId,
            'request' => $request,
            'details' => [
                'old_role' => $oldRole,
                'new_role' => $newRole,
            ],
        ]);
    }

    public function dispatchTenantEvent(string $action, $tenantId, $userId = null, array $details = [], $request = null): void
    {
        $this->dispatch('tenant.' . $action, [
            'action' => $action,
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'details' => $details,
            'request' => $request,
        ]);
    }

    public function dispatchPolicyViolation(string $policy, string $violation, $userId = null, $tenantId = null, string $severity = 'medium', array $details = [], $request = null): void
    {
        $this->dispatch(self::EVENT_POLICY_VIOLATION, [
            'policy' => $policy,
            'violation' => $violation,
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'severity' => $severity,
            'details' => $details,
            'request' => $request,
        ]);
    }
}
