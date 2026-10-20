<?php

declare(strict_types=1);

namespace App\Utils;

/**
 * SuperAdminHelper
 *
 * Single source of truth for super-admin identity checks.
 *
 * Platform identity is determined by both the canonical role and explicit
 * platform scope. Tenant owners/admins remain tenant-scoped administrators.
 *
 * Usage:
 *   use App\Utils\SuperAdminHelper;
 *
 *   if (SuperAdminHelper::is($user)) { ... }
 *   if (SuperAdminHelper::isAdminOrAbove($user)) { ... }
 */
final class SuperAdminHelper
{
    /** The canonical role_id for super_admin in the database. */
    public const SUPER_ADMIN_ROLE_ID = 1;

    /** The canonical role string stored in the users table. */
    public const SUPER_ADMIN_ROLE = 'super_admin';

    /**
     * Returns true if the given user payload represents a super-admin.
     *
     * Accepts role_id (int) or role (string), but requires an explicit platform
     * scope so tenant users cannot inherit platform identity from a stale role.
     *
     * @param array|null $user  Associative array from JWT / request attribute.
     *                          Expected keys: 'role_id' (int) and/or 'role' (string).
     */
    public static function is(?array $user): bool
    {
        if (empty($user)) {
            return false;
        }

        if (!array_key_exists('tenant_id', $user)) {
            return false;
        }

        $tenantId = $user['tenant_id'];
        if ($tenantId !== null && (int) $tenantId !== 0) {
            return false;
        }

        return (isset($user['role_id']) && (int) $user['role_id'] === self::SUPER_ADMIN_ROLE_ID)
            || (isset($user['role']) && strtolower((string) $user['role']) === self::SUPER_ADMIN_ROLE);
    }

    /**
     * Returns true if the user is a super_admin OR a regular admin.
     *
     * Used by AdminSettingsHandler and RBACHandler which allow both roles.
     *
     * @param array|null $user
     */
    public static function isAdminOrAbove(?array $user): bool
    {
        if (empty($user)) {
            return false;
        }

        if (self::is($user)) {
            return true;
        }

        if (!isset($user['tenant_id']) || (int) $user['tenant_id'] <= 0) {
            return false;
        }

        return (!empty($user['is_owner']) && (int) $user['is_owner'] === 1)
            || (isset($user['role']) && strtolower((string) $user['role']) === 'admin');
    }

    /**
     * Returns true if the user is not a platform super-admin.
     * Convenience inverse used by SuperAdminMiddleware.
     *
     * @param array|null $user
     */
    public static function isNot(?array $user): bool
    {
        return !self::is($user);
    }
}
