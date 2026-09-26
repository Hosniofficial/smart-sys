<?php

declare(strict_types=1);

namespace App\Handlers;

use PDO;
use Exception;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use App\Services\SecurityLogger;
use App\Services\SecurityEventDispatcher;
use App\Services\JwtBlacklistService;
use App\Services\MonologHandler;
use App\Services\TwoFactorEncryptionService;
use App\Utils\RequestHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AuthHandler extends BaseHandler
{
    private string $jwtKey;
    private string $jwtRefreshSecret;
    private int $tokenExpiry;
    private int $refreshTokenExpiry;
    private ?JwtBlacklistService $jwtBlacklistService;
    private ?SecurityLogger $securityLogger;
    private ?SecurityEventDispatcher $eventDispatcher;
    private \PHPGangsta_GoogleAuthenticator $ga;
    private TwoFactorEncryptionService $twoFaEncryption;

    public function __construct(
        PDO $db,
        ?JwtBlacklistService $jwtBlacklistService = null,
        ?SecurityLogger $securityLogger = null
    ) {
        parent::__construct($db);

        $this->logger = MonologHandler::getInstance('auth');
        $this->jwtBlacklistService = $jwtBlacklistService;
        $this->securityLogger = $securityLogger;

        $securityConfigPath = __DIR__ . '/../../../../config/security.php';
        $securityConfig = file_exists($securityConfigPath) ? require $securityConfigPath : [];
        $jwtConfig = $securityConfig['jwt'] ?? [];

        $this->jwtKey = (string) ($jwtConfig['secret'] ?? ($_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET') ?: ''));
        $this->jwtRefreshSecret = (string) ($jwtConfig['refresh_secret'] ?? ($_ENV['JWT_REFRESH_SECRET'] ?? getenv('JWT_REFRESH_SECRET') ?: ''));
        $this->tokenExpiry = (int) ($jwtConfig['access_token_expiry'] ?? 3600);
        $this->refreshTokenExpiry = (int) ($jwtConfig['refresh_token_expiry'] ?? 2592000);
        $this->ga = new \PHPGangsta_GoogleAuthenticator();
        $this->twoFaEncryption = new TwoFactorEncryptionService();

        if ($this->jwtKey === '' || $this->jwtRefreshSecret === '') {
            throw new Exception('JWT secrets are not set in environment variables.');
        }
    }

    public function login(Request $request, Response $response): Response
    {
        try {
            $data = $request->getParsedBody();
            $data = is_array($data) ? $data : [];

            $username = trim((string) ($data['username'] ?? ''));
            $password = (string) ($data['password'] ?? '');
            $twoFactorCode = isset($data['two_factor_code']) ? trim((string) $data['two_factor_code']) : null;

            if ($username === '' || $password === '') {
                return $this->errorResponse($response, 'اسم المستخدم وكلمة المرور مطلوبان', 400);
            }

            $clientIp = RequestHelper::getClientIpFromServer();
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

            // Account lockout: block after 5 failed attempts in 15 minutes
            try {
                $lockoutWindow  = 900; // 15 min
                $maxAttempts    = 5;
                $stmtCheck = $this->db->prepare("
                    SELECT COUNT(*) FROM login_attempts
                    WHERE (username = ? OR ip_address = ?)
                      AND success = 0
                      AND created_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)
                ");
                $stmtCheck->execute([$username, $clientIp, $lockoutWindow]);
                $recentFails = (int) $stmtCheck->fetchColumn();

                if ($recentFails >= $maxAttempts) {
                    $this->securityLogger?->logSecurityEvent(
                        'security.brute_force',
                        'critical',
                        'Account locked — too many failed attempts',
                        ['username' => $username, 'ip' => $clientIp, 'attempts' => $recentFails]
                    );
                    return $this->errorResponse(
                        $response,
                        'تم تجاوز عدد محاولات تسجيل الدخول المسموح بها. يرجى المحاولة بعد 15 دقيقة.',
                        429
                    );
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Lockout check failed', ['error' => $e->getMessage()]);
            }

            $this->logger->info('Login attempt', [
                'username' => $username,
                'ip' => $clientIp,
                'user_agent' => substr($userAgent, 0, 100)
            ]);

            $stmt = $this->db->prepare("
                SELECT
                    u.id,
                    u.password,
                    u.name,
                    u.username,
                    u.email,
                    u.role_id,
                    u.status,
                    u.tenant_id,
                    u.branch_id,
                    u.is_owner,
                    u.two_fa_enabled,
                    u.two_fa_secret,
                    t.is_setup_complete
                FROM users u
                LEFT JOIN tenants t ON u.tenant_id = t.id
                WHERE u.username = ? OR u.email = ?
                LIMIT 1
            ");
            $stmt->execute([$username, $username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user || !password_verify($password, (string) $user['password'])) {
                $this->recordLoginAttempt($username, false, $clientIp);

                $this->securityLogger?->logSecurityEvent(
                    'login.failed',
                    'warning',
                    'Invalid credentials',
                    [
                        'username' => $username,
                        'reason' => 'invalid_credentials',
                        'ip' => $clientIp,
                        'user_agent' => substr($userAgent, 0, 100)
                    ]
                );

                return $this->errorResponse($response, 'اسم المستخدم أو كلمة المرور غير صحيحة', 401);
            }

            if (($user['status'] ?? '') !== 'active') {
                $this->securityLogger?->logSecurityEvent(
                    'login.failed',
                    'warning',
                    'Account inactive',
                    [
                        'username' => $username,
                        'reason' => 'account_inactive',
                        'status' => $user['status'],
                        'ip' => $clientIp
                    ],
                    $user['id'],
                    $user['tenant_id']
                );

                return $this->errorResponse($response, 'الحساب غير نشط', 403);
            }

            if (!empty($user['two_fa_enabled'])) {
                if ($twoFactorCode === null || $twoFactorCode === '') {
                    return $this->jsonResponse($response, [
                        'status' => '2fa_required',
                        'message' => 'Two-factor authentication code is required'
                    ], 200);
                }

                if (empty($user['two_fa_secret']) || !$this->ga->verifyCode((string) $this->twoFaEncryption->decrypt($user['two_fa_secret']), $twoFactorCode)) {
                    $this->logger->warning('Invalid 2FA code during login', [
                        'user_id' => $user['id'],
                        'ip' => $clientIp
                    ]);

                    return $this->errorResponse($response, 'رمز التحقق الثنائي غير صحيح', 401);
                }
            }

            $accessToken = $this->generateAccessToken($user);
            $refreshToken = $this->generateRefreshToken((int) $user['id']);
            $this->storeRefreshToken((int) $user['id'], $refreshToken);

            $this->recordLoginAttempt($username, true, $clientIp);

            $this->securityLogger?->logSecurityEvent(
                'user.login',
                'info',
                'Successful login',
                [
                    'username' => $username,
                    'role' => $this->mapRoleName((int) $user['role_id']),
                    'ip' => $clientIp,
                    'tenant_id' => $user['tenant_id'],
                    'branch_id' => $user['branch_id']
                ],
                $user['id'],
                $user['tenant_id']
            );

            $this->logger->info('Login successful', [
                'user_id' => $user['id'],
                'username' => $username,
                'tenant_id' => $user['tenant_id'],
                'role' => $this->mapRoleName((int) $user['role_id'])
            ]);

            // Include permissions from every role assigned to the user.
            $permissions = $this->getPermissionsForUser((int) $user['id']);

            return $this->setRefreshTokenCookie(
                $this->successResponse($response, [
                    'access_token' => $accessToken,
                    'user' => [
                        'id'              => (int) $user['id'],
                        'name'            => $user['name'],
                        'username'        => $user['username'],
                        'role_id'         => (int) $user['role_id'],
                        'role'            => $this->mapRoleName((int) $user['role_id']),
                        'isAdmin'         => $this->isAdmin((int) $user['role_id']),
                        'tenant_id'       => (int) $user['tenant_id'],
                        'branch_id'       => isset($user['branch_id']) ? (int) $user['branch_id'] : null,
                        'is_owner'        => (int) ($user['is_owner'] ?? 0),
                        'is_setup_complete' => (int) ($user['is_setup_complete'] ?? 0),
                        'permissions'     => $permissions,
                    ],
                ], 200),
                $refreshToken
            );
        } catch (Exception $e) {
            $this->logger->error('Login error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'username' => $data['username'] ?? 'unknown'
            ]);

            return $this->errorResponse($response, 'حدث خطأ أثناء تسجيل الدخول', 500);
        }
    }

    public function refreshToken(Request $request, Response $response): Response
    {
        try {
            $clientIp     = RequestHelper::getClientIpFromServer();
            $refreshToken = $this->extractRefreshToken($request);

            if ($refreshToken === '') {
                return $this->errorResponse($response, 'رمز التحديث مطلوب', 400);
            }

            $this->logger->info('Token refresh attempt', [
                'ip'           => $clientIp,
                'token_length' => strlen($refreshToken),
                'source'       => !empty($request->getCookieParams()['refresh_token']) ? 'cookie' : 'body',
            ]);

            $decoded = JWT::decode($refreshToken, new Key($this->jwtRefreshSecret, 'HS256'));

            if (($decoded->type ?? null) !== 'refresh') {
                return $this->errorResponse($response, 'نوع الرمز غير صالح', 401);
            }

            if (($decoded->iss ?? null) !== ($_SERVER['HTTP_HOST'] ?? 'erp-system')) {
                return $this->errorResponse($response, 'مصدر الرمز غير صالح', 401);
            }

            $stmt = $this->db->prepare("
                SELECT user_id, is_revoked
                FROM   refresh_tokens
                WHERE  token      = ?
                  AND  expires_at > NOW()
            ");
            $stmt->execute([$refreshToken]);
            $tokenData = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$tokenData || !empty($tokenData['is_revoked'])) {
                $this->logger->warning('Invalid refresh token', [
                    'user_id' => $decoded->user_id ?? 'unknown',
                    'ip'      => $clientIp,
                    'reason'  => $tokenData ? 'revoked' : 'not_found',
                ]);
                return $this->errorResponse($response, 'رمز التحديث غير صالح', 401);
            }

            if ((int) $tokenData['user_id'] !== (int) ($decoded->user_id ?? 0)) {
                return $this->errorResponse($response, 'رمز التحديث غير متطابق', 401);
            }

            $stmt = $this->db->prepare("
                SELECT id, username, name, role_id, status, tenant_id, branch_id, is_owner
                FROM   users
                WHERE  id = ?
                LIMIT  1
            ");
            $stmt->execute([$tokenData['user_id']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user || ($user['status'] ?? '') !== 'active') {
                $this->logger->warning('Token refresh failed - user inactive', [
                    'user_id' => $tokenData['user_id'],
                    'status'  => $user['status'] ?? 'not_found',
                    'ip'      => $clientIp,
                ]);
                return $this->errorResponse($response, 'المستخدم غير موجود أو غير نشط', 403);
            }

            // Rotate: revoke old, issue new
            $newAccessToken  = $this->generateAccessToken($user);
            $newRefreshToken = $this->generateRefreshToken((int) $user['id']);

            $this->revokeRefreshToken($refreshToken);
            $this->storeRefreshToken((int) $user['id'], $newRefreshToken);

            $this->logger->info('Token refresh successful', [
                'user_id'   => $user['id'],
                'username'  => $user['username'],
                'tenant_id' => $user['tenant_id'],
            ]);

            // Include permissions from every role assigned to the user.
            $permissions = $this->getPermissionsForUser((int) $user['id']);

            // New refresh token goes into HttpOnly cookie; access token in body
            return $this->setRefreshTokenCookie(
                $this->successResponse($response, [
                    'access_token' => $newAccessToken,
                    'user' => [
                        'id'              => (int) $user['id'],
                        'name'            => $user['name'],
                        'username'        => $user['username'],
                        'role_id'         => (int) $user['role_id'],
                        'role'            => $this->mapRoleName((int) $user['role_id']),
                        'isAdmin'         => $this->isAdmin((int) $user['role_id']),
                        'tenant_id'       => (int) $user['tenant_id'],
                        'branch_id'       => isset($user['branch_id']) ? (int) $user['branch_id'] : null,
                        'is_owner'        => (int) ($user['is_owner'] ?? 0),
                        'permissions'     => $permissions,
                    ],
                ], 200),
                $newRefreshToken
            );

        } catch (Exception $e) {
            $this->logger->error('Token refresh error', [
                'message' => $e->getMessage(),
                'ip'      => RequestHelper::getClientIpFromServer(),
            ]);
            return $this->errorResponse($response, 'حدث خطأ أثناء تحديث الرمز', 500);
        }
    }

    public function logout(Request $request, Response $response): Response
    {
        try {
            $refreshToken = $this->extractRefreshToken($request);
            $clientIp     = RequestHelper::getClientIpFromServer();

            $user     = $request->getAttribute('user');
            $userId   = is_array($user) ? ($user['id']       ?? null) : null;
            $tenantId = is_array($user) ? ($user['tenant_id'] ?? null) : null;
            $username = is_array($user) ? ($user['username']  ?? 'unknown') : 'unknown';

            $this->logger->info('Logout attempt', [
                'user_id'   => $userId,
                'username'  => $username,
                'tenant_id' => $tenantId,
                'ip'        => $clientIp,
            ]);

            if ($refreshToken !== '') {
                $this->revokeRefreshToken($refreshToken);
                $this->logger->debug('Refresh token revoked', ['user_id' => $userId]);
            }

            $authHeader = $request->getHeaderLine('Authorization');
            if ($authHeader !== '' && preg_match('/Bearer\s+(\S+)/', $authHeader, $matches)) {
                if ($this->jwtBlacklistService) {
                    $this->jwtBlacklistService->addToBlacklist($matches[1]);
                    $this->logger->debug('Access token blacklisted on logout', ['user_id' => $userId]);
                }
            }

            $this->securityLogger?->logSecurityEvent(
                'user.logout',
                'info',
                'User logged out',
                ['username' => $username, 'ip' => $clientIp],
                $userId,
                $tenantId
            );

            $this->logger->info('Logout successful', [
                'user_id'   => $userId,
                'username'  => $username,
                'tenant_id' => $tenantId,
            ]);

            return $this->clearRefreshTokenCookie(
                $this->jsonResponse($response, [
                    'status'  => 'success',
                    'message' => 'تم تسجيل الخروج بنجاح',
                ], 200)
            );

        } catch (Exception $e) {
            $this->logger->error('Logout error', [
                'message' => $e->getMessage(),
                'ip'      => RequestHelper::getClientIpFromServer(),
            ]);
            return $this->errorResponse($response, 'حدث خطأ أثناء تسجيل الخروج', 500);
        }
    }

    /**
     * Logout from ALL devices — revokes every refresh token for this user.
     * POST /auth/logout-all-devices  (requires valid JWT)
     */
    public function logoutAllDevices(Request $request, Response $response): Response
    {
        try {
            $user     = $request->getAttribute('user');
            $userId   = is_array($user) ? (int) ($user['id']       ?? 0) : 0;
            $tenantId = is_array($user) ? ($user['tenant_id'] ?? null) : null;
            $username = is_array($user) ? ($user['username']  ?? 'unknown') : 'unknown';
            $clientIp = RequestHelper::getClientIpFromServer();

            if ($userId === 0) {
                return $this->errorResponse($response, 'غير مصرح', 401);
            }

            $stmt = $this->db->prepare("
                UPDATE refresh_tokens
                SET    is_revoked = 1
                WHERE  user_id    = ?
                  AND  is_revoked = 0
            ");
            $stmt->execute([$userId]);
            $revokedCount = $stmt->rowCount();

            $authHeader = $request->getHeaderLine('Authorization');
            if ($authHeader !== '' && preg_match('/Bearer\s+(\S+)/', $authHeader, $matches)) {
                $this->jwtBlacklistService?->addToBlacklist($matches[1]);
            }

            $this->securityLogger?->logSecurityEvent(
                'user.logout',
                'notice',
                'User logged out from all devices',
                ['username' => $username, 'ip' => $clientIp, 'tokens_revoked' => $revokedCount],
                $userId,
                $tenantId
            );

            $this->logger->info('Logout all devices', [
                'user_id'        => $userId,
                'tokens_revoked' => $revokedCount,
            ]);

            return $this->clearRefreshTokenCookie(
                $this->jsonResponse($response, [
                    'status'         => 'success',
                    'message'        => 'تم تسجيل الخروج من جميع الأجهزة بنجاح',
                    'tokens_revoked' => $revokedCount,
                ], 200)
            );

        } catch (Exception $e) {
            $this->logger->error('Logout all devices error', ['message' => $e->getMessage()]);
            return $this->errorResponse($response, 'حدث خطأ أثناء تسجيل الخروج', 500);
        }
    }

    // ── Cookie configuration ──────────────────────────────────────────────────
    // Centralised so login, refresh, and logout all use identical settings.
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

    private function setRefreshTokenCookie(Response $response, string $token): Response
    {
        $secure   = ($_ENV['HTTPS_ENFORCEMENT_ENABLED'] ?? 'false') === 'true' ? '; Secure' : '';
        $sameSite = '; SameSite=Strict';
        $path     = '; Path=' . $this->getRefreshCookiePath();
        $maxAge   = '; Max-Age=' . $this->refreshTokenExpiry;
        $httpOnly = '; HttpOnly';

        return $response->withAddedHeader(
            'Set-Cookie',
            'refresh_token=' . urlencode($token) . $httpOnly . $secure . $sameSite . $path . $maxAge
        );
    }

    private function clearRefreshTokenCookie(Response $response): Response
    {
        $secure   = ($_ENV['HTTPS_ENFORCEMENT_ENABLED'] ?? 'false') === 'true' ? '; Secure' : '';
        $sameSite = '; SameSite=Strict';
        $path     = '; Path=' . $this->getRefreshCookiePath();

        return $response->withAddedHeader(
            'Set-Cookie',
            'refresh_token=; HttpOnly' . $secure . $sameSite . $path . '; Max-Age=0; Expires=Thu, 01 Jan 1970 00:00:00 GMT'
        );
    }

    /**
     * Extract refresh token — cookie first, body as fallback.
     *
     * Cookie mode is the secure default (HttpOnly, SameSite=Strict).
     * Body fallback supports legacy clients and the transition period.
     */
    private function extractRefreshToken(Request $request): string
    {
        // 1. Try HttpOnly cookie (preferred — not accessible to JS)
        $cookies = $request->getCookieParams();
        if (!empty($cookies['refresh_token'])) {
            return urldecode((string) $cookies['refresh_token']);
        }

        // 2. Fallback: body (legacy / transition period)
        $data = $request->getParsedBody();
        return (string) ($data['refresh_token'] ?? '');
    }

    private function generateAccessToken(array $user): string
    {
        $permissions = $this->getPermissionsForUser((int) $user['id']);
        $jti = bin2hex(random_bytes(16));

        $payload = [
            'user_id'    => (int) $user['id'],
            'username'   => $user['username'],
            'role_id'    => (int) $user['role_id'],
            'role'       => $this->mapRoleName((int) $user['role_id']),
            'isAdmin'    => $this->isAdmin((int) $user['role_id']),
            'tenant_id'  => (int) $user['tenant_id'],
            'branch_id'  => isset($user['branch_id']) ? (int) $user['branch_id'] : null,
            'is_owner'   => (int) ($user['is_owner'] ?? 0),
            'permissions' => $permissions,
            'jti'        => $jti,
            'iat'        => time(),
            'exp'        => time() + $this->tokenExpiry,
            'iss'        => $_SERVER['HTTP_HOST'] ?? 'erp-system',
            'type'       => 'access'
        ];

        return JWT::encode($payload, $this->jwtKey, 'HS256');
    }

    /**
     * Public wrapper for generateAccessToken — used by EmailVerificationHandler
     * to issue an auto-login token after email verification.
     */
    public function generateAccessTokenPublic(array $user): string
    {
        return $this->generateAccessToken($user);
    }

    private function generateRefreshToken(int $userId): string
    {
        $payload = [
            'user_id' => $userId,
            'jti' => bin2hex(random_bytes(16)),
            'iat' => time(),
            'exp' => time() + $this->refreshTokenExpiry,
            'iss' => $_SERVER['HTTP_HOST'] ?? 'erp-system',
            'type' => 'refresh'
        ];

        return JWT::encode($payload, $this->jwtRefreshSecret, 'HS256');
    }

    private function storeRefreshToken(int $userId, string $token): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO refresh_tokens (user_id, token, expires_at)
            VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))
        ");
        $stmt->execute([$userId, $token, $this->refreshTokenExpiry]);
    }

    private function revokeRefreshToken(string $token): void
    {
        $stmt = $this->db->prepare("
            UPDATE refresh_tokens
            SET is_revoked = 1
            WHERE token = ?
        ");
        $stmt->execute([$token]);
    }

    private function recordLoginAttempt(string $username, bool $success, string $ip): void
    {
        try {
            $stmtLog = $this->db->prepare("
                INSERT INTO login_attempts (username, success, ip_address)
                VALUES (?, ?, ?)
            ");
            $stmtLog->execute([$username, $success ? 1 : 0, $ip]);
        } catch (Exception $e) {
            $this->logger->warning('Failed to log login attempt', ['error' => $e->getMessage()]);
        }
    }

    private function mapRoleName(int $roleId): string
    {
        try {
            $stmt = $this->db->prepare("SELECT name FROM roles WHERE id = ? LIMIT 1");
            $stmt->execute([$roleId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row && !empty($row['name'])) {
                return (string) $row['name'];
            }
        } catch (\Throwable $e) {
            // fallback below
        }

        $roles = [
            1 => 'super_admin',
            2 => 'admin',
            3 => 'manager',
            4 => 'cashier',
            5 => 'inventory_clerk',
            6 => 'finance_officer',
        ];

        return $roles[$roleId] ?? 'user';
    }

    private function isAdmin(int $roleId): bool
    {
        return in_array($roleId, [1, 2], true);
    }

    private function getPermissionsForUser(int $userId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT DISTINCT p.name
                FROM users u
                JOIN role_permissions rp
                  ON rp.role_id = u.role_id
                  OR rp.role_id IN (
                      SELECT ur.role_id
                      FROM users_role ur
                      WHERE ur.user_id = u.id
                        AND (ur.tenant_id = u.tenant_id OR ur.tenant_id IS NULL)
                  )
                JOIN roles r ON r.id = rp.role_id
                JOIN permissions p ON p.id = rp.permission_id
                WHERE u.id = ?
                  AND (
                      r.tenant_id = u.tenant_id
                      OR (r.tenant_id IS NULL AND r.is_system_role = 1)
                  )
            ");
            $stmt->execute([$userId]);

            return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (\Throwable $e) {
            $this->logger->error('Failed to fetch permissions for user', [
                'user_id' => $userId,
                'message' => $e->getMessage()
            ]);
            return [];
        }
    }

    // =========================================================================
    // PUBLIC WRAPPERS FOR EXTERNAL HANDLERS
    // =========================================================================

    /**
     * Generate refresh token (public wrapper for EmailVerificationHandler)
     */
    public function generateRefreshTokenPublic(int $userId): string
    {
        return $this->generateRefreshToken($userId);
    }

    /**
     * Store refresh token (public wrapper for EmailVerificationHandler)
     */
    public function storeRefreshTokenPublic(int $userId, string $token): void
    {
        $this->storeRefreshToken($userId, $token);
    }

    /**
     * Set refresh token cookie (public wrapper for EmailVerificationHandler)
     */
    public function setRefreshTokenCookiePublic(Response $response, string $token): Response
    {
        return $this->setRefreshTokenCookie($response, $token);
    }

    /**
     * Setup 2FA for user
     */
    public function setup2FA(Request $request, Response $response): Response
    {
        try {
            $data = $request->getParsedBody();
            $data = is_array($data) ? $data : [];

            $jwtUserId = $this->extractUserId($request);
            if (!$jwtUserId) {
                return $this->errorResponse($response, 'Unauthorized', 401);
            }

            if (!isset($data['user_id'])) {
                return $this->errorResponse($response, 'User ID is required', 400);
            }

            $requestUserId = (int) $data['user_id'];

            if ($requestUserId !== $jwtUserId) {
                return $this->errorResponse($response, 'أنت غير مصرح بإعداد 2FA لمستخدم آخر', 403);
            }

            $secret = $this->ga->createSecret();
            $qrCodeUrl = $this->ga->getQRCodeGoogleUrl('SmartSys', $secret);

            $this->logger->info('2FA setup initiated', ['user_id' => $requestUserId]);

            return $this->successResponse($response, [
                'secret_key' => $secret,
                'qr_code' => $qrCodeUrl
            ], 200);
        } catch (Exception $e) {
            $this->logger->error('2FA setup error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->errorResponse($response, 'فشل إعداد المصادقة الثنائية. يرجى المحاولة مرة أخرى.', 400);
        }
    }

    public function verify2FA(Request $request, Response $response): Response
    {
        try {
            $data = $request->getParsedBody();
            $data = is_array($data) ? $data : [];

            if (!isset($data['user_id'], $data['code'])) {
                return $this->errorResponse($response, 'User ID and verification code are required', 400);
            }

            // ✅ Security: verify caller owns this user_id
            $jwtUserId = $this->extractUserId($request);
            if (!$jwtUserId) {
                return $this->errorResponse($response, 'Unauthorized', 401);
            }

            $requestUserId = (int) $data['user_id'];
            if ($requestUserId !== $jwtUserId) {
                $this->logger->warning('2FA verify: user_id mismatch', [
                    'jwt_user_id'     => $jwtUserId,
                    'request_user_id' => $requestUserId,
                ]);
                return $this->errorResponse($response, 'أنت غير مصرح بالتحقق من 2FA لمستخدم آخر', 403);
            }

            $userId = $requestUserId;
            $code   = (string) $data['code'];

            // Security: Scope query by tenant_id to prevent cross-tenant reads
            $tenantId = $this->extractTenantId($request);
            $stmt = $this->db->prepare("
                SELECT two_fa_secret
                FROM users
                WHERE id = ? AND tenant_id = ?
                LIMIT 1
            ");
            $stmt->execute([$userId, $tenantId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user || empty($user['two_fa_secret'])) {
                return $this->errorResponse($response, 'Invalid verification code', 401);
            }

            $isValid = $this->ga->verifyCode((string) $this->twoFaEncryption->decrypt($user['two_fa_secret']), $code);

            if (!$isValid) {
                $this->logger->warning('Invalid 2FA code attempt', ['user_id' => $userId]);
                return $this->errorResponse($response, 'Invalid verification code', 401);
            }

            $this->logger->info('2FA verification successful', ['user_id' => $userId]);

            return $this->jsonResponse($response, [
                'status' => 'success',
                'message' => '2FA verification successful'
            ]);
        } catch (Exception $e) {
            $this->logger->error('2FA verification error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->errorResponse($response, 'فشل التحقق من المصادقة الثنائية. تأكد من صحة الرمز.', 400);
        }
    }

    /**
     * Enable 2FA for user
     */
    public function enable2FA(Request $request, Response $response): Response
    {
        try {
            $data = $request->getParsedBody();
            $data = is_array($data) ? $data : [];

            if (!isset($data['user_id'], $data['code'], $data['secret'])) {
                return $this->errorResponse($response, 'User ID, code and secret are required', 400);
            }

            // ✅ Security: verify caller owns this user_id
            $jwtUserId = $this->extractUserId($request);
            if (!$jwtUserId) {
                return $this->errorResponse($response, 'Unauthorized', 401);
            }

            $requestUserId = (int) $data['user_id'];
            if ($requestUserId !== $jwtUserId) {
                $this->logger->warning('2FA enable: user_id mismatch', [
                    'jwt_user_id'     => $jwtUserId,
                    'request_user_id' => $requestUserId,
                ]);
                return $this->errorResponse($response, 'أنت غير مصرح بتفعيل 2FA لمستخدم آخر', 403);
            }

            $userId   = $requestUserId;
            $code     = (string) $data['code'];
            $secret   = (string) $data['secret'];
            $tenantId = $this->extractTenantId($request);

            $isValid = $this->ga->verifyCode($secret, $code);

            if (!$isValid) {
                $this->logger->warning('Invalid code for 2FA enable', ['user_id' => $userId]);
                return $this->errorResponse($response, 'Invalid verification code', 400);
            }

            // Security: WHERE includes tenant_id to prevent cross-tenant updates
            // Encrypt secret before storing for security
            $encryptedSecret = $this->twoFaEncryption->encrypt($secret);
            $stmt = $this->db->prepare("
                UPDATE users
                SET two_fa_secret = ?, two_fa_enabled = 1
                WHERE id = ? AND tenant_id = ?
            ");
            $stmt->execute([$encryptedSecret, $userId, $tenantId]);

            if ($stmt->rowCount() === 0) {
                // No row updated — user doesn't exist in this tenant
                $this->logger->warning('2FA enable: no matching user in tenant', [
                    'user_id'   => $userId,
                    'tenant_id' => $tenantId,
                ]);
                return $this->errorResponse($response, 'User not found', 404);
            }

            $this->logger->info('2FA enabled', ['user_id' => $userId]);

            return $this->jsonResponse($response, [
                'status'  => 'success',
                'message' => '2FA enabled successfully',
            ]);
        } catch (Exception $e) {
            $this->logger->error('2FA enable error', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return $this->errorResponse($response, 'فشل تفعيل المصادقة الثنائية. يرجى المحاولة مرة أخرى.', 400);
        }
    }
}
