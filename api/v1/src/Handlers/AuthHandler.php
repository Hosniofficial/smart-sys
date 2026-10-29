<?php

declare(strict_types=1);

namespace App\Handlers;

use PDO;
use Exception;
use App\Services\SecurityLogger;
use App\Services\JwtBlacklistService;
use App\Services\MonologHandler;
use App\Services\AuthSessionService;
use App\Services\UserSessionService;
use App\Services\TwoFactorEncryptionService;
use App\Utils\RequestHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Handles login, token lifecycle, logout, and login-time two-factor verification.
 *
 * Delegates token issuance and refresh-cookie handling to AuthSessionService.
 */
class AuthHandler extends BaseHandler
{
    private ?JwtBlacklistService $jwtBlacklistService;
    private ?SecurityLogger $securityLogger;
    private AuthSessionService $authSession;
    private \PragmaRX\Google2FA\Google2FA $ga;
    private TwoFactorEncryptionService $twoFaEncryption;

    public function __construct(
        PDO $db,
        ?JwtBlacklistService $jwtBlacklistService = null,
        ?SecurityLogger $securityLogger = null,
        ?AuthSessionService $authSession = null
    ) {
        parent::__construct($db);

        $this->logger = MonologHandler::getInstance('auth');
        $this->jwtBlacklistService = $jwtBlacklistService;
        $this->securityLogger = $securityLogger;
        $this->authSession = $authSession ?? new AuthSessionService($db);
        $this->ga = new \PragmaRX\Google2FA\Google2FA();
        $this->twoFaEncryption = new TwoFactorEncryptionService();
    }

    /** Authenticate credentials and optional 2FA, then issue access and refresh tokens. */
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

                if (
                    empty($user['two_fa_secret'])
                    || !$this->ga->verifyKey(
                        (string) $this->twoFaEncryption->decrypt($user['two_fa_secret']),
                        $twoFactorCode,
                        1
                    )
                ) {
                    $this->logger->warning('Invalid 2FA code during login', [
                        'user_id' => $user['id'],
                        'ip' => $clientIp
                    ]);

                    return $this->errorResponse($response, 'رمز التحقق الثنائي غير صحيح', 401);
                }
            }

            $lastLoginStmt = $this->db->prepare('UPDATE users SET last_login = NOW() WHERE id = ?');
            $lastLoginStmt->execute([(int) $user['id']]);

            $user = $this->authSession->resolveIdentity($user);
            $accessToken = $this->authSession->generateAccessToken($user);
            $refreshToken = $this->authSession->generateRefreshToken((int) $user['id']);
            $this->authSession->storeRefreshToken((int) $user['id'], $refreshToken);

            $this->recordLoginAttempt($username, true, $clientIp);

            $this->securityLogger?->logSecurityEvent(
                'user.login',
                'info',
                'Successful login',
                [
                    'username' => $username,
                    'role' => $user['identity_role_name'],
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
                'role' => $user['identity_role_name']
            ]);

            return $this->authSession->setRefreshTokenCookie(
                $this->successResponse($response, [
                    'access_token' => $accessToken,
                    'user' => [
                        'id'              => (int) $user['id'],
                        'name'            => $user['name'],
                        'username'        => $user['username'],
                        'role_id'         => $user['identity_role_id'],
                        'role'            => $user['identity_role_name'],
                        'isAdmin'         => $user['isAdmin'],
                        'tenant_id'       => (int) $user['tenant_id'],
                        'branch_id'       => isset($user['branch_id']) ? (int) $user['branch_id'] : null,
                        'is_owner'        => (int) ($user['is_owner'] ?? 0),
                        'is_setup_complete' => (int) ($user['is_setup_complete'] ?? 0),
                        'permissions'     => $user['permissions'],
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

    /** Validate and rotate a refresh token, returning a new access token and cookie. */
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

            $decoded = $this->authSession->decodeRefreshToken($refreshToken);

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

            $user = $this->authSession->resolveIdentity($user);

            // Rotate: revoke old, issue new
            $newAccessToken  = $this->authSession->generateAccessToken($user);
            $newRefreshToken = $this->authSession->generateRefreshToken((int) $user['id']);

            $this->revokeRefreshToken($refreshToken);
            $this->authSession->storeRefreshToken((int) $user['id'], $newRefreshToken);

            $this->logger->info('Token refresh successful', [
                'user_id'   => $user['id'],
                'username'  => $user['username'],
                'tenant_id' => $user['tenant_id'],
            ]);

            // New refresh token goes into HttpOnly cookie; access token in body
            return $this->authSession->setRefreshTokenCookie(
                $this->successResponse($response, [
                    'access_token' => $newAccessToken,
                    'user' => [
                        'id'              => (int) $user['id'],
                        'name'            => $user['name'],
                        'username'        => $user['username'],
                        'role_id'         => $user['identity_role_id'],
                        'role'            => $user['identity_role_name'],
                        'isAdmin'         => $user['isAdmin'],
                        'tenant_id'       => (int) $user['tenant_id'],
                        'branch_id'       => isset($user['branch_id']) ? (int) $user['branch_id'] : null,
                        'is_owner'        => (int) ($user['is_owner'] ?? 0),
                        'permissions'     => $user['permissions'],
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

    /** Revoke the presented refresh token and blacklist its bearer token when supported. */
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

            return $this->authSession->clearRefreshTokenCookie(
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

    /** Revoke all refresh tokens for the authenticated user and blacklist the current access token. */
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

            $countStmt = $this->db->prepare(
                'SELECT COUNT(*) FROM refresh_tokens WHERE user_id = ? AND is_revoked = 0'
            );
            $countStmt->execute([$userId]);
            $revokedCount = (int) $countStmt->fetchColumn();
            (new UserSessionService($this->db))->revokeAllSessions(
                $userId,
                (int) $tenantId
            );

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

            return $this->authSession->clearRefreshTokenCookie(
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

    /** Read the refresh token from its cookie, falling back to the request body for legacy clients. */
    private function extractRefreshToken(Request $request): string
    {
        $cookies = $request->getCookieParams();
        if (!empty($cookies['refresh_token'])) {
            return urldecode((string) $cookies['refresh_token']);
        }

        $data = $request->getParsedBody();
        return (string) ($data['refresh_token'] ?? '');
    }

    /** Mark a stored refresh token as revoked. */
    private function revokeRefreshToken(string $token): void
    {
        $stmt = $this->db->prepare("
            UPDATE refresh_tokens
            SET is_revoked = 1
            WHERE token = ?
        ");
        $stmt->execute([$token]);
    }

    /** Record a login attempt; logging failures are reported without interrupting authentication. */
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

}
