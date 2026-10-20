<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Security\PermissionResolver;
use App\Utils\SuperAdminHelper;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;

/**
 * Enforces route permissions using the user's effective tenant role grants.
 *
 * Supports any-of/all-of checks and an optional tenant-owner bypass.
 * Platform super-admin bypass is restricted by SuperAdminHelper.
 */
class PermissionMiddleware implements MiddlewareInterface
{
    /** @var string[] */
    private array $permissions;

    private PDO $db;

    /** 'any' = at least one match, 'all' = every permission required */
    private string $mode;

    /** tenant owner bypass — when true, is_owner=1 users pass without permission check */
    private bool $allowOwner = false;

    /**
     * @param string[] $permissions
     * @param 'any'|'all' $mode
     */
    public function __construct(array $permissions, PDO $db, string $mode = 'any')
    {
        if (empty($permissions)) {
            throw new \InvalidArgumentException('PermissionMiddleware requires at least one permission.');
        }

        $this->permissions = $permissions;
        $this->db          = $db;
        $this->mode        = $mode;
    }

    /**
     * Require exactly one permission.
     */
    public static function require(string $permission, PDO $db): self
    {
        return new self([$permission], $db, 'any');
    }

    /**
     * Require at least one of the given permissions.
     *
     * @param string[] $permissions
     */
    public static function anyOf(array $permissions, PDO $db): self
    {
        return new self($permissions, $db, 'any');
    }

    /**
     * Return a clone that also permits tenant owners to bypass the check.
     */
    public function orOwner(): self
    {
        $clone = clone $this;
        $clone->allowOwner = true;
        return $clone;
    }

    /**
     * Require ALL of the given permissions.
     *
     * @param string[] $permissions
     */
    public static function allOf(array $permissions, PDO $db): self
    {
        return new self($permissions, $db, 'all');
    }

    /** Authenticate the user context and enforce the configured permission set. */
    public function process(Request $request, RequestHandler $handler): Response
    {
        $user   = $request->getAttribute('user');
        $userId = is_array($user) ? (int) ($user['id'] ?? 0) : 0;

        // 1. Must be authenticated
        if ($userId === 0) {
            return $this->deny($request, 'غير مصرح. يرجى تسجيل الدخول أولاً.', 401);
        }

        // 2. super_admin bypasses all permission checks
        if (SuperAdminHelper::is(is_array($user) ? $user : null)) {
            return $handler->handle($request);
        }

        // 3. tenant owner bypass — allowed when middleware is created with allowOwner()
        if ($this->allowOwner && is_array($user) && !empty($user['is_owner'])) {
            return $handler->handle($request);
        }

        // 4. Check permissions
        $granted = $this->resolveGranted($userId);

        $allowed = $this->mode === 'all'
            ? $this->hasAll($granted)
            : $this->hasAny($granted);

        if (!$allowed) {
            return $this->deny(
                $request,
                'ليس لديك الصلاحية الكافية لتنفيذ هذا الإجراء.',
                403
            );
        }

        return $handler->handle($request);
    }

    /**
     * Fetch all permission names granted to this user (via their roles).
     * Checks both users_role table AND users.role_id column as fallback.
     *
     * @return string[]
     */
    private function resolveGranted(int $userId): array
    {
        try {
            return PermissionResolver::getGrantedPermissionNames($this->db, $userId);
        } catch (\Throwable $e) {
            // On DB error, fail closed (deny access) — never fail open.
            return [];
        }
    }

    /** @param string[] $granted */
    private function hasAny(array $granted): bool
    {
        foreach ($this->permissions as $perm) {
            if (in_array($perm, $granted, true)) {
                return true;
            }
        }
        return false;
    }

    /** @param string[] $granted */
    private function hasAll(array $granted): bool
    {
        foreach ($this->permissions as $perm) {
            if (!in_array($perm, $granted, true)) {
                return false;
            }
        }
        return true;
    }

    private function deny(Request $request, string $message, int $status): Response
    {
        $response = new SlimResponse();
        $response->getBody()->write(json_encode([
            'status'   => 'error',
            'message'  => $message,
            'required' => $this->permissions,
            'mode'     => $this->mode,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store')
            ->withStatus($status);
    }
}
