<?php

declare(strict_types=1);

namespace App\Traits;

use App\Exceptions\ForbiddenException;
use App\Handlers\RBACHandler;
use App\Security\Permissions;
use App\Utils\SuperAdminHelper;
use PDO;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * AuthorizesRequests — inline RBAC checks inside handlers.
 *
 * Use this trait when you need a permission check in the middle of a handler
 * method (e.g. after loading a resource, before mutating it).
 *
 * For route-level protection (the common case), prefer PermissionMiddleware
 * on the route definition instead — it's cleaner and runs before the handler.
 *
 * ─── Requirements ────────────────────────────────────────────────────────────
 * The using class must expose:
 *   - $this->db  (PDO)
 *   - $this->extractUserId(Request $r): ?int
 *
 * Both are already present in BaseHandler.
 *
 * ─── Usage ───────────────────────────────────────────────────────────────────
 *
 *   // Throws ForbiddenException (caught by global error handler → 403 JSON)
 *   $this->authorize($request, Permissions::SALE_VOID);
 *
 *   // Returns bool — for conditional logic without throwing
 *   if ($this->can($request, Permissions::SALE_DISCOUNT)) { ... }
 *
 *   // Any-of check
 *   $this->authorizeAny($request, [Permissions::SETTINGS_VIEW,
 *                                   Permissions::SETTINGS_MANAGE]);
 */
trait AuthorizesRequests
{
    /**
     * Assert the current user has the given permission.
     * Throws ForbiddenException (→ 403) if not.
     */
    protected function authorize(Request $request, string $permission): void
    {
        if (!$this->can($request, $permission)) {
            throw new ForbiddenException($permission);
        }
    }

    /**
     * Assert the current user has at least one of the given permissions.
     * Throws ForbiddenException (→ 403) if none match.
     *
     * @param string[] $permissions
     */
    protected function authorizeAny(Request $request, array $permissions): void
    {
        foreach ($permissions as $perm) {
            if ($this->can($request, $perm)) {
                return;
            }
        }
        throw new ForbiddenException(
            implode('|', $permissions),
            'ليس لديك أي من الصلاحيات المطلوبة لتنفيذ هذا الإجراء.'
        );
    }

    /**
     * Assert the current user has ALL of the given permissions.
     * Throws ForbiddenException (→ 403) if any is missing.
     *
     * @param string[] $permissions
     */
    protected function authorizeAll(Request $request, array $permissions): void
    {
        foreach ($permissions as $perm) {
            if (!$this->can($request, $perm)) {
                throw new ForbiddenException($perm);
            }
        }
    }

    /**
     * Returns true if the current user has the given permission.
     * Never throws — safe for conditional checks.
     */
    protected function can(Request $request, string $permission): bool
    {
        $user   = $request->getAttribute('user');
        $userId = is_array($user) ? (int) ($user['id'] ?? 0) : 0;
        if ($userId === 0) {
            return false;
        }

        if (SuperAdminHelper::is(is_array($user) ? $user : null)) {
            return true;
        }

        if (!isset($this->db) || !($this->db instanceof PDO)) {
            return false;
        }

        try {
            $rbac = new RBACHandler($this->db);
            return $rbac->hasPermission($userId, $permission);
        } catch (\Throwable $e) {
            // Fail closed on any error
            return false;
        }
    }
}
