<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * SettingsRepository
 *
 * Single source of truth for reading from the `settings` table.
 *
 * Replaces the duplicated settings-lookup logic that existed in:
 *   - BaseHandler::getSetting()          (tenant only)
 *   - SalesService::getSettingValue()    (tenant + global fallback)
 *   - AccountingService::resolveAccountId() (tenant + global fallback)
 *   - SessionsHandler::getSettingValue() (tenant only, now delegates to BaseHandler)
 *
 * Resolution order for get() / getBool():
 *   1. settings WHERE tenant_id = $tenantId AND key_name = $key
 *   2. settings WHERE tenant_id IS NULL    AND key_name = $key  (global/system default)
 *   3. $default
 *
 * Usage:
 *   $repo = new SettingsRepository($db);
 *   $currency = $repo->get($tenantId, 'company.currency', 'EGP');
 *   $enabled  = $repo->getBool($tenantId, 'pos.sessions.enabled', false);
 */
class SettingsRepository
{
    private PDO $db;

    /** In-memory cache: "tenantId:key" → value (avoids repeated queries per request) */
    private array $cache = [];

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // -------------------------------------------------------------------------
    // Core read methods
    // -------------------------------------------------------------------------

    /**
     * Get a setting value as string.
     *
     * @param int         $tenantId
     * @param string      $key      e.g. 'company.currency', 'pos.sessions.enabled'
     * @param string|null $default  Returned when no row is found.
     */
    public function get(int $tenantId, string $key, ?string $default = null): ?string
    {
        $cacheKey = $tenantId . ':' . $key;

        if (array_key_exists($cacheKey, $this->cache)) {
            $cached = $this->cache[$cacheKey];
            return $cached !== null ? $cached : $default;
        }

        try {
            // 1. Tenant-specific
            $stmt = $this->db->prepare(
                "SELECT value FROM settings
                 WHERE tenant_id = ? AND key_name = ?
                 ORDER BY updated_at DESC LIMIT 1"
            );
            $stmt->execute([$tenantId, $key]);
            $val = $stmt->fetchColumn();

            if ($val !== false && $val !== null) {
                $this->cache[$cacheKey] = (string) $val;
                return (string) $val;
            }

            // 2. Global / system-wide (tenant_id IS NULL)
            $stmt = $this->db->prepare(
                "SELECT value FROM settings
                 WHERE tenant_id IS NULL AND key_name = ?
                 ORDER BY updated_at DESC LIMIT 1"
            );
            $stmt->execute([$key]);
            $val = $stmt->fetchColumn();

            if ($val !== false && $val !== null) {
                $this->cache[$cacheKey] = (string) $val;
                return (string) $val;
            }
        } catch (\Throwable $e) {
            // Silently fall through to default
        }

        $this->cache[$cacheKey] = null;
        return $default;
    }

    /**
     * Get a setting value as integer.
     *
     * @param int $tenantId
     * @param string $key
     * @param int $default
     */
    public function getInt(int $tenantId, string $key, int $default = 0): int
    {
        $val = $this->get($tenantId, $key);
        return $val !== null ? (int) $val : $default;
    }

    /**
     * Get a setting value as boolean.
     *
     * Truthy values: '1', 'true', 'yes', 'on'
     * Everything else is false.
     *
     * @param int    $tenantId
     * @param string $key
     * @param bool   $default
     */
    public function getBool(int $tenantId, string $key, bool $default = false): bool
    {
        $val = $this->get($tenantId, $key);

        if ($val === null) {
            return $default;
        }

        return in_array(strtolower(trim($val)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Get a setting value as float.
     */
    public function getFloat(int $tenantId, string $key, float $default = 0.0): float
    {
        $val = $this->get($tenantId, $key);
        return $val !== null ? (float) $val : $default;
    }

    // -------------------------------------------------------------------------
    // Write methods
    // -------------------------------------------------------------------------

    /**
     * Upsert a setting value for a tenant.
     * Invalidates the in-memory cache for this key.
     */
    public function set(int $tenantId, string $key, string $value): bool
    {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO settings (tenant_id, key_name, value, updated_at)
                 VALUES (?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW()"
            );
            $result = $stmt->execute([$tenantId, $key, $value]);

            // Invalidate cache
            unset($this->cache[$tenantId . ':' . $key]);

            return $result;
        } catch (\Throwable $e) {
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // Cache management
    // -------------------------------------------------------------------------

    /**
     * Clear the in-memory cache (useful in tests or after bulk updates).
     */
    public function clearCache(): void
    {
        $this->cache = [];
    }

    /**
     * Clear cache for a specific tenant.
     */
    public function clearTenantCache(int $tenantId): void
    {
        $prefix = $tenantId . ':';
        foreach (array_keys($this->cache) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->cache[$key]);
            }
        }
    }
}
