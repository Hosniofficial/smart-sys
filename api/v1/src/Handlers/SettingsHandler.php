<?php

declare(strict_types=1);

namespace App\Handlers;

use PDO;
use Exception;
use Throwable;
use App\Services\MonologHandler;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

/**
 * Persists and retrieves typed, tenant-scoped settings.
 *
 * Settings are stored in the settings table, cached per handler instance, and
 * audited on writes. AdminSettingsHandler uses this class for API persistence;
 * StrictSubscriptionHandler also reads settings internally.
 * Authorization for settings endpoints is handled by AdminSettingsHandler and
 * app/routes/settings.php.
 */
class SettingsHandler extends BaseHandler
{
    private array $cache = [];

    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->logger = MonologHandler::getInstance('settings');
    }

    private function resolveTenantId(?int $tenantIdParam = null): ?int
    {
        if (!empty($tenantIdParam)) {
            return (int) $tenantIdParam;
        }

        return null;
    }

    /** Read a typed setting from the instance cache or tenant-scoped database row. */
    public function get($key, $default = null, $tenantId = null)
    {
        $resolvedTenantId = null;

        try {
            $resolvedTenantId = $this->resolveTenantId($tenantId);

            if (!$resolvedTenantId) {
                $this->logger->warning('Settings get - missing tenant ID', [
                    'key' => $key
                ]);
                return $default;
            }

            $cacheKey = $resolvedTenantId . ':' . $key;

            if (array_key_exists($cacheKey, $this->cache)) {
                $this->logger->debug('Settings retrieved from cache', [
                    'tenant_id' => $resolvedTenantId,
                    'key' => $key
                ]);
                return $this->cache[$cacheKey];
            }

            $stmt = $this->db->prepare(
                "SELECT value, type
                 FROM settings
                 WHERE key_name = ? AND tenant_id = ?
                 LIMIT 1"
            );
            $stmt->execute([$key, $resolvedTenantId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$result) {
                $this->logger->debug('Settings not found, returning default', [
                    'tenant_id' => $resolvedTenantId,
                    'key' => $key
                ]);
                return $default;
            }

            $value = $this->castValue($result['value'], $result['type']);
            $this->cache[$cacheKey] = $value;

            $this->logger->debug('Settings retrieved successfully', [
                'tenant_id' => $resolvedTenantId,
                'key' => $key,
                'type' => $result['type']
            ]);

            return $value;
        } catch (Throwable $e) {
            $this->logger->error('Settings get error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'tenant_id' => $resolvedTenantId ?? 'unknown',
                'key' => $key ?? 'unknown'
            ]);

            return $default;
        }
    }

    /** Serialize, persist, cache, and audit one tenant setting. */
    public function set($key, $value, $type = null, $tenantId = null, $userId = null): bool
    {
        $resolvedTenantId = null;

        try {
            $resolvedTenantId = $this->resolveTenantId($tenantId);

            if (!$resolvedTenantId) {
                $this->logger->error('Settings set - missing tenant ID', [
                    'key' => $key
                ]);
                throw new Exception('مطلوب معرف المستأجر (Tenant ID).');
            }

            if ($type === null) {
                $type = $this->detectValueType($value);
            }

            $serializedValue = $this->serializeValue($value);

            $this->logger->info('Settings update request', [
                'tenant_id' => $resolvedTenantId,
                'user_id' => $userId,
                'key' => $key,
                'type' => $type
            ]);

            $stmt = $this->db->prepare(
                "INSERT INTO settings (key_name, value, type, tenant_id, updated_at)
                 VALUES (?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                    value = VALUES(value),
                    type = VALUES(type),
                    updated_at = NOW()"
            );

            $success = $stmt->execute([
                $key,
                $serializedValue,
                $type,
                $resolvedTenantId
            ]);

            if (!$success) {
                $this->logger->error('Settings update failed', [
                    'tenant_id' => $resolvedTenantId,
                    'key' => $key
                ]);
                return false;
            }

            $cacheKey = $resolvedTenantId . ':' . $key;
            $this->cache[$cacheKey] = $value;

            $this->logSettingChange($key, $value, 'update', $resolvedTenantId, $userId);

            $this->logger->info('Settings updated successfully', [
                'tenant_id' => $resolvedTenantId,
                'user_id' => $userId,
                'key' => $key,
                'type' => $type
            ]);

            return true;
        } catch (Throwable $e) {
            $this->logger->error('Settings set error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'tenant_id' => $resolvedTenantId ?? 'unknown',
                'key' => $key ?? 'unknown'
            ]);

            throw $e;
        }
    }

    /** Delete a tenant setting, evict its cache entry, and audit the change. */
    public function delete($key, $tenantId = null, $userId = null): bool
    {
        $resolvedTenantId = null;

        try {
            $resolvedTenantId = $this->resolveTenantId($tenantId);

            if (!$resolvedTenantId) {
                $this->logger->error('Settings delete - missing tenant ID', [
                    'key' => $key
                ]);
                throw new Exception('مطلوب معرف المستأجر (Tenant ID).');
            }

            $this->logger->info('Settings delete request', [
                'tenant_id' => $resolvedTenantId,
                'user_id' => $userId,
                'key' => $key
            ]);

            $stmt = $this->db->prepare(
                "DELETE FROM settings
                 WHERE key_name = ? AND tenant_id = ?"
            );
            $success = $stmt->execute([$key, $resolvedTenantId]);

            if (!$success) {
                $this->logger->error('Settings delete failed', [
                    'tenant_id' => $resolvedTenantId,
                    'key' => $key
                ]);
                return false;
            }

            unset($this->cache[$resolvedTenantId . ':' . $key]);
            $this->logSettingChange($key, null, 'delete', $resolvedTenantId, $userId);

            $this->logger->info('Settings deleted successfully', [
                'tenant_id' => $resolvedTenantId,
                'key' => $key
            ]);

            return true;
        } catch (Throwable $e) {
            $this->logger->error('Settings delete error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'tenant_id' => $resolvedTenantId ?? 'unknown',
                'key' => $key ?? 'unknown'
            ]);

            throw $e;
        }
    }

    /** Persist a settings map, reusing an active database transaction. */
    public function setGroup($settings, $tenantId = null, $userId = null): bool
    {
        $resolvedTenantId = $this->resolveTenantId($tenantId);

        if (!$resolvedTenantId) {
            throw new Exception('مطلوب معرف المستأجر (Tenant ID).');
        }

        // Reuse an active transaction instead of opening a nested transaction.
        $inTransaction = $this->db->inTransaction();
        if (!$inTransaction) {
            $this->db->beginTransaction();
        }

        try {
            foreach ($settings as $key => $value) {
                $this->set($key, $value, null, $resolvedTenantId, $userId);
            }

            if (!$inTransaction) {
                $this->db->commit();
            }
            return true;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }
    }

    public function updateSettings($settings, $tenantId = null, $userId = null): bool
    {
        return $this->setGroup($settings, $tenantId, $userId);
    }

    private function castValue($value, $type)
    {
        switch ($type) {
            case 'boolean':
                return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;

            case 'integer':
                return (int) $value;

            case 'float':
                return (float) $value;

            case 'array':
                return json_decode($value, true) ?? [];

            case 'object':
                return json_decode($value);

            default:
                return $value;
        }
    }

    private function serializeValue($value): string
    {
        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }

    private function detectValueType($value): string
    {
        switch (true) {
            case is_bool($value):
                return 'boolean';

            case is_int($value):
                return 'integer';

            case is_float($value):
                return 'float';

            case is_array($value):
                return 'array';

            case is_object($value):
                return 'object';

            default:
                return 'string';
        }
    }

    /** Load tenant settings, falling back to built-in defaults when unavailable. */
    public function getSettings($tenantId = null): array
    {
        $resolvedTenantId = $this->resolveTenantId($tenantId);

        try {
            if (!$resolvedTenantId) {
                return $this->getDefaultSettings();
            }

            $stmt = $this->db->prepare(
                "SELECT key_name, value, type
                 FROM settings
                 WHERE tenant_id = ?
                 ORDER BY key_name"
            );
            $stmt->execute([$resolvedTenantId]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $settings = [];

            foreach ($results as $result) {
                $key = $result['key_name'];
                $value = $this->castValue($result['value'], $result['type']);

                $settings[$key] = $value;
                $this->cache[$resolvedTenantId . ':' . $key] = $value;
            }

            if (empty($settings)) {
                return $this->getDefaultSettings();
            }

            return $settings;
        } catch (Throwable $e) {
            $this->logger->error('Error getting settings', [
                'message' => $e->getMessage(),
                'tenant_id' => $resolvedTenantId ?? 'unknown'
            ]);

            return $this->getDefaultSettings();
        }
    }

    private function getDefaultSettings(): array
    {
        return [
            'company.name' => 'SmartSys',
            'company.address' => '',
            'company.phone' => '',
            'company.email' => '',
            'company.website' => '',
            'company.tax_number' => '',
            'company.registration_number' => '',
            'company.logo' => '',
            'company.currency' => 'EGP',
            // app.* is the canonical namespace for locale/format settings (used by frontend).
            // company.timezone / company.date_format / company.time_format are kept as aliases
            // only for backward compatibility with any legacy DB rows.
            'app.timezone'    => 'Africa/Cairo',
            'app.date_format' => 'DD/MM/YYYY',
            'app.time_format' => '12',
            'system.language' => 'ar',
            'system.theme' => 'light',
            'inventory.low_stock_alert' => 10,
            'inventory.auto_reorder' => false,
            'sales.tax_rate' => 15.0,
            'sales.discount_enabled' => true,
            'notifications.email_enabled' => true,
            'notifications.sms_enabled' => false
        ];
    }

    /** Audit a setting change without failing the persistence operation on audit errors. */
    private function logSettingChange($key, $value, $action = 'update', $tenantId = null, $userId = null): void
    {
        try {
            $this->logger->debug('Logging setting change', [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'key' => $key,
                'action' => $action
            ]);

            $auditHandler = $this->audit;
            $auditHandler->logAction(
                $action,
                'settings',
                null,
                [
                    'key' => $key,
                    'value' => $value
                ],
                $tenantId,
                $userId
            );

            $this->logger->debug('Setting change logged successfully', [
                'tenant_id' => $tenantId,
                'key' => $key,
                'action' => $action
            ]);
        } catch (Throwable $e) {
            $this->logger->error('Settings audit log failed', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'tenant_id' => $tenantId ?? 'unknown',
                'key' => $key ?? 'unknown',
                'action' => $action ?? 'unknown'
            ]);
        }
    }
}
