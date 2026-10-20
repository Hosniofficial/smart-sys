<?php

declare(strict_types=1);

namespace App\Services;

use App\Security\PermissionResolver;
use App\Utils\SuperAdminHelper;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;

final class AuthSessionService
{
    private PDO $db;
    private string $jwtKey;
    private string $jwtRefreshSecret;
    private int $tokenExpiry;
    private int $refreshTokenExpiry;
    private $logger;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->logger = MonologHandler::getInstance('auth_session');

        $securityConfigPath = __DIR__ . '/../../../../config/security.php';
        $securityConfig = file_exists($securityConfigPath) ? require $securityConfigPath : [];
        $jwtConfig = $securityConfig['jwt'] ?? [];

        $this->jwtKey = (string) ($jwtConfig['secret'] ?? ($_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET') ?: ''));
        $this->jwtRefreshSecret = (string) ($jwtConfig['refresh_secret'] ?? ($_ENV['JWT_REFRESH_SECRET'] ?? getenv('JWT_REFRESH_SECRET') ?: ''));
        $this->tokenExpiry = (int) ($jwtConfig['access_token_expiry'] ?? 3600);
        $this->refreshTokenExpiry = (int) ($jwtConfig['refresh_token_expiry'] ?? 2592000);

        if ($this->jwtKey === '' || $this->jwtRefreshSecret === '') {
            throw new \RuntimeException('JWT secrets are not set in environment variables.');
        }
    }

    /** Resolve the user role and permissions used by the token and login response. */
    public function resolveIdentity(array $user): array
    {
        try {
            $role = PermissionResolver::getEffectiveRole($this->db, (int) $user['id']);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to resolve effective user role', [
                'user_id' => $user['id'] ?? null,
                'message' => $e->getMessage(),
            ]);
            $role = null;
        }

        $user['identity_role_id'] = (int) ($role['id'] ?? 0);
        $user['identity_role_name'] = (string) ($role['name'] ?? 'user');
        $user['role_id'] = $user['identity_role_id'];
        $user['role'] = $user['identity_role_name'];
        $user['isAdmin'] = SuperAdminHelper::isAdminOrAbove($user);

        try {
            $user['permissions'] = PermissionResolver::getGrantedPermissionNames($this->db, (int) $user['id']);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to fetch permissions for user', [
                'user_id' => $user['id'],
                'message' => $e->getMessage(),
            ]);
            $user['permissions'] = [];
        }

        return $user;
    }

    /** Issue an access token containing the resolved identity and permission claims. */
    public function generateAccessToken(array $user): string
    {
        if (!isset($user['identity_role_id'], $user['identity_role_name'], $user['permissions'], $user['isAdmin'])) {
            $user = $this->resolveIdentity($user);
        }

        if (!array_key_exists('token_version', $user)) {
            $tenantId = (int) ($user['tenant_id'] ?? 0);
            $tenantCondition = $tenantId > 0
                ? 'tenant_id = ?'
                : '(tenant_id IS NULL OR tenant_id = 0)';
            $stmt = $this->db->prepare(
                "SELECT token_version FROM users WHERE id = ? AND {$tenantCondition} LIMIT 1"
            );
            $params = [(int) $user['id']];
            if ($tenantId > 0) {
                $params[] = $tenantId;
            }
            $stmt->execute($params);
            $tokenVersion = $stmt->fetchColumn();
            if ($tokenVersion === false) {
                throw new \RuntimeException('Cannot issue an access token for a missing tenant user.');
            }
            $user['token_version'] = (int) $tokenVersion;
        }

        return JWT::encode([
            'user_id' => (int) $user['id'],
            'token_version' => (int) $user['token_version'],
            'username' => $user['username'],
            'role_id' => (int) $user['identity_role_id'],
            'role' => (string) $user['identity_role_name'],
            'isAdmin' => (bool) $user['isAdmin'],
            'tenant_id' => (int) $user['tenant_id'],
            'branch_id' => isset($user['branch_id']) ? (int) $user['branch_id'] : null,
            'is_owner' => (int) ($user['is_owner'] ?? 0),
            'permissions' => $user['permissions'],
            'jti' => bin2hex(random_bytes(16)),
            'iat' => time(),
            'exp' => time() + $this->tokenExpiry,
            'iss' => $_SERVER['HTTP_HOST'] ?? 'erp-system',
            'type' => 'access',
        ], $this->jwtKey, 'HS256');
    }

    /** Create a signed refresh token for the user. */
    public function generateRefreshToken(int $userId): string
    {
        return JWT::encode([
            'user_id' => $userId,
            'jti' => bin2hex(random_bytes(16)),
            'iat' => time(),
            'exp' => time() + $this->refreshTokenExpiry,
            'iss' => $_SERVER['HTTP_HOST'] ?? 'erp-system',
            'type' => 'refresh',
        ], $this->jwtRefreshSecret, 'HS256');
    }

    /** Decode and verify a refresh token with the configured signing key. */
    public function decodeRefreshToken(string $token): object
    {
        return JWT::decode($token, new Key($this->jwtRefreshSecret, 'HS256'));
    }

    /** Persist an issued refresh token for rotation and revocation checks. */
    public function storeRefreshToken(int $userId, string $token): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO refresh_tokens (user_id, token, expires_at)
            VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))
        ");
        $stmt->execute([$userId, $token, $this->refreshTokenExpiry]);
    }

    /** Add the configured HttpOnly refresh-token cookie to a response. */
    public function setRefreshTokenCookie(Response $response, string $token): Response
    {
        $secure = ($_ENV['HTTPS_ENFORCEMENT_ENABLED'] ?? 'false') === 'true' ? '; Secure' : '';
        $sameSite = '; SameSite=Strict';
        $path = '; Path=' . $this->getRefreshCookiePath();
        $maxAge = '; Max-Age=' . $this->refreshTokenExpiry;

        return $response->withAddedHeader(
            'Set-Cookie',
            'refresh_token=' . urlencode($token) . '; HttpOnly' . $secure . $sameSite . $path . $maxAge
        );
    }

    /** Clear the refresh-token cookie using the same path and security attributes. */
    public function clearRefreshTokenCookie(Response $response): Response
    {
        $secure = ($_ENV['HTTPS_ENFORCEMENT_ENABLED'] ?? 'false') === 'true' ? '; Secure' : '';
        $sameSite = '; SameSite=Strict';
        $path = '; Path=' . $this->getRefreshCookiePath();

        return $response->withAddedHeader(
            'Set-Cookie',
            'refresh_token=; HttpOnly' . $secure . $sameSite . $path . '; Max-Age=0; Expires=Thu, 01 Jan 1970 00:00:00 GMT'
        );
    }

    private function getRefreshCookiePath(): string
    {
        $basePath = trim((string) ($_ENV['APP_BASE_PATH'] ?? getenv('APP_BASE_PATH') ?? '/api/v1'));
        $basePath = trim($basePath, '/');

        if ($basePath === '') {
            return '/';
        }

        $cookiePath = '/' . $basePath;
        if (!str_ends_with($cookiePath, '/auth')) {
            $cookiePath .= '/auth';
        }

        return $cookiePath;
    }
}
