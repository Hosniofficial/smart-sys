<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use Exception;
use App\Services\MonologHandler;
use App\Utils\RequestHelper;
use Psr\Http\Message\ServerRequestInterface as Request;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

/**
 * Manages verification and password-reset tokens, rate limits, and SMTP delivery.
 *
 * Uses PDO for token and user state, RequestHelper for client metadata, and PHPMailer for delivery.
 */
class EmailVerificationService
{
    private PDO $db;
    private array $config;
    private $logger;
    private UserSessionService $userSessionService;

    public function __construct(PDO $db, array $config = [])
    {
        $this->db     = $db;
        $this->logger = MonologHandler::getInstance('email_verification');
        $this->userSessionService = new UserSessionService($db);

        $requireHttps = false;
        $httpsEnv = getenv('HTTPS_ENFORCEMENT_ENABLED');
        if ($httpsEnv !== false) {
            $requireHttps = filter_var($httpsEnv, FILTER_VALIDATE_BOOLEAN);
        }

        $frontendUrl = getenv('FRONTEND_URL') ?: 'http://localhost:5173';

        $this->config = array_merge([
            'token_expires_hours'        => 24,
            'max_attempts'               => 3,
            'token_bytes'                => 32,
            'require_https'              => $requireHttps,
            'enable_rate_limiting'       => true,
            'max_verifications_per_hour' => 10,
            'base_url'                   => rtrim((string) $frontendUrl, '/'),
        ], $config);
    }

    /**
     * Create and send a verification token for the requested purpose.
     *
     * @return array{expires_at: string, status: 'sent'}
     */
    public function sendVerification(
        string  $email,
        string  $purpose,
        Request $request
    ): array {
        if ($this->config['enable_rate_limiting']) {
            $this->checkRateLimit($email, $purpose);
        }

        $user = $this->getUserByEmail($email);
        if (!$user && $purpose !== 'registration') {
            throw new \App\Exceptions\NotFoundException('User not found');
        }

        $token      = $this->generateToken();
        $tokenHash  = hash('sha256', $token);
        $expiresAt  = date('Y-m-d H:i:s', time() + ((int) $this->config['token_expires_hours'] * 3600));

        $this->storeToken(
            isset($user['id']) ? (int) $user['id'] : null,
            $email,
            $tokenHash,
            $purpose,
            $expiresAt,
            $request
        );

        $this->sendEmail($email, $token, $purpose, $user ?: null);

        $this->logger->info('Verification email sent', [
            'email'   => $email,
            'purpose' => $purpose,
            'user_id' => $user['id'] ?? null,
        ]);

        return [
            'expires_at' => $expiresAt,
            'status'     => 'sent',
        ];
    }

    /**
     * Validate a token, apply its purpose-specific update, and mark it used.
     *
     * @return array{
     *     status: 'verified',
     *     purpose: string,
     *     user_id: int,
     *     tenant_id: int,
     *     user: array|null,
     *     is_setup_complete: bool
     * }
     */
    public function verifyToken(string $token): array
    {
        $tokenHash = hash('sha256', $token);
        $tokenInfo = $this->getTokenByHash($tokenHash);

        if (!$tokenInfo) {
            throw new Exception('Invalid verification token');
        }

        if (strtotime((string) $tokenInfo['expires_at']) < time()) {
            throw new Exception('Verification token expired');
        }

        if (!empty($tokenInfo['is_revoked']) || !empty($tokenInfo['used_at'])) {
            throw new Exception('Invalid verification token');
        }

        if ((int) ($tokenInfo['attempts'] ?? 0) >= (int) ($tokenInfo['max_attempts'] ?? $this->config['max_attempts'])) {
            throw new Exception('Too many verification attempts');
        }

        $this->incrementAttempts((int) $tokenInfo['id']);

        if (!hash_equals((string) $tokenInfo['token_hash'], $tokenHash)) {
            throw new Exception('Invalid verification token');
        }

        $purpose = (string) ($tokenInfo['purpose'] ?? 'registration');

        if (in_array($purpose, ['registration', 'email_change'], true)) {
            $this->db->prepare(
                "UPDATE users SET email_verified_at = NOW(), updated_at = NOW()
                 WHERE id = ? AND tenant_id = ?"
            )->execute([(int) $tokenInfo['user_id'], (int) $tokenInfo['tenant_id']]);
        }

        $this->markTokenUsed((int) $tokenInfo['id']);

        $userData = null;
        $isSetupComplete = false;
        if (in_array($purpose, ['registration', 'email_change'], true)) {
            $userData = $this->getUserById((int) $tokenInfo['user_id']);
            try {
                $stmt = $this->db->prepare(
                    "SELECT is_setup_complete FROM tenants WHERE id = ? LIMIT 1"
                );
                $stmt->execute([(int) $tokenInfo['tenant_id']]);
                $isSetupComplete = (bool) $stmt->fetchColumn();
            } catch (\Throwable $e) {
            }
        }

        return [
            'status'            => 'verified',
            'purpose'           => $purpose,
            'user_id'           => (int) $tokenInfo['user_id'],
            'tenant_id'         => (int) $tokenInfo['tenant_id'],
            'user'              => $userData,
            'is_setup_complete' => $isSetupComplete,
        ];
    }

    /**
     * Revoke the user's unused tokens for this purpose and send a replacement.
     */
    public function resend(string $email, string $purpose, Request $request): array
    {
        $user = $this->getUserByEmail($email);
        if (!$user) {
            throw new \App\Exceptions\NotFoundException('User not found');
        }

        $this->revokeUserTokens((int) $user['id'], $purpose);

        return $this->sendVerification($email, $purpose, $request);
    }

    /**
     * Send a password-reset link when the address belongs to a user.
     *
     * Unknown addresses are logged and return normally so the caller can keep a generic response.
     */
    public function sendPasswordReset(string $email, Request $request): void
    {
        if ($this->config['enable_rate_limiting']) {
            $this->checkRateLimit($email, 'password_reset');
        }

        $user = $this->getUserByEmail($email);
        if (!$user) {
            $this->logger->warning('Password reset requested for unknown email', ['email' => $email]);
            return;
        }

        $token     = $this->generateToken();
        $tokenHash = hash('sha256', $token);
        $expiresAt = date('Y-m-d H:i:s', time() + 1800); // 30 min

        $this->storeToken((int) $user['id'], $email, $tokenHash, 'password_reset', $expiresAt, $request);
        $this->sendEmail($email, $token, 'password_reset', $user);
    }

    /** Reset the password using a valid, unused password-reset token. */
    public function resetPassword(string $token, string $newPassword): void
    {
        if (strlen($newPassword) < 8) {
            throw new Exception('كلمة المرور يجب أن تكون 8 أحرف على الأقل');
        }

        $tokenHash = hash('sha256', $token);
        $tokenInfo = $this->getTokenByHash($tokenHash);

        if (!$tokenInfo
            || (string) $tokenInfo['purpose'] !== 'password_reset'
            || strtotime((string) $tokenInfo['expires_at']) < time()
            || !empty($tokenInfo['used_at'])
            || !empty($tokenInfo['is_revoked'])
            || !hash_equals((string) $tokenInfo['token_hash'], $tokenHash)
        ) {
            usleep(150000);
            throw new Exception('الرابط غير صالح أو منتهي');
        }

        $startedTransaction = !$this->db->inTransaction();
        if ($startedTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $this->userSessionService->changePassword(
                (int) $tokenInfo['user_id'],
                (int) $tokenInfo['tenant_id'],
                $newPassword
            );
            $this->markTokenUsed((int) $tokenInfo['id']);
            if ($startedTransaction) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    private function generateToken(): string
    {
        return bin2hex(random_bytes((int) $this->config['token_bytes']));
    }

    private function generateVerificationUrl(string $token, string $purpose): string
    {
        $baseUrl = rtrim((string) $this->config['base_url'], '/');

        $path = match ($purpose) {
            'registration'     => '/verify-email',
            'email_change'     => '/verify-email-change',
            'password_reset'   => '/reset-password',
            'account_recovery' => '/recover-account',
            default            => '/verify',
        };

        $url = $baseUrl . $path . '?token=' . urlencode($token);

        if (!empty($this->config['require_https']) && !str_starts_with($url, 'https://')) {
            throw new Exception('HTTPS is required for verification URLs');
        }

        return $url;
    }

    private function checkRateLimit(string $email, string $purpose): void
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM email_verification_tokens
             WHERE email = ? AND purpose = ? AND created_at >= (NOW() - INTERVAL 1 HOUR)"
        );
        $stmt->execute([$email, $purpose]);

        if ((int) $stmt->fetchColumn() >= (int) $this->config['max_verifications_per_hour']) {
            throw new Exception('Rate limit exceeded');
        }
    }

    private function getUserByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id, username, name, email, tenant_id, status,
                   role_id, branch_id, is_owner, email_verified_at,
                   verification_token, verification_token_expires
            FROM users WHERE email = ? LIMIT 1
        ");
        $stmt->execute([$email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function getUserById(int $userId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT u.id, u.username, u.name, u.email, u.tenant_id, u.status,
                   u.role_id, u.branch_id, u.is_owner, u.email_verified_at,
                   u.verification_token, u.verification_token_expires,
                   r.name AS role_name
            FROM users u
            LEFT JOIN roles r ON r.id = u.role_id
            WHERE u.id = ? LIMIT 1
        ");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function storeToken(
        ?int    $userId,
        string  $email,
        string  $tokenHash,
        string  $purpose,
        string  $expiresAt,
        Request $request
    ): void {
        if (!$userId) {
            throw new Exception('User not found');
        }

        $user     = $this->getUserById($userId);
        $tenantId = (int) ($user['tenant_id'] ?? 0);
        if ($tenantId <= 0) {
            throw new Exception('Tenant not found');
        }

        $ip                = RequestHelper::getClientIp($request);
        $deviceFingerprint = $request->getHeaderLine('X-Device-Fingerprint') ?: null;

        $this->db->prepare(
            "INSERT INTO email_verification_tokens
                 (tenant_id, user_id, email, token, token_hash, purpose,
                  expires_at, ip_address, device_fingerprint, attempts, max_attempts, is_revoked, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, 0, NOW())"
        )->execute([
            $tenantId,
            $userId,
            $email,
            null,
            $tokenHash,
            $purpose,
            $expiresAt,
            $ip,
            $deviceFingerprint,
            (int) $this->config['max_attempts'],
        ]);
    }

    private function getTokenByHash(string $tokenHash): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM email_verification_tokens WHERE token_hash = ? LIMIT 1"
        );
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function incrementAttempts(int $id): void
    {
        $this->db->prepare(
            "UPDATE email_verification_tokens SET attempts = attempts + 1 WHERE id = ?"
        )->execute([$id]);
    }

    private function markTokenUsed(int $id): void
    {
        $this->db->prepare(
            "UPDATE email_verification_tokens SET used_at = NOW() WHERE id = ?"
        )->execute([$id]);
    }

    private function revokeUserTokens(int $userId, string $purpose): void
    {
        $this->db->prepare(
            "UPDATE email_verification_tokens
             SET is_revoked = 1, revoked_at = NOW(), revoked_reason = 'resend'
             WHERE user_id = ? AND purpose = ? AND used_at IS NULL AND is_revoked = 0"
        )->execute([$userId, $purpose]);
    }

    private function sendEmail(string $email, string $token, string $purpose, ?array $user): void
    {
        $maxExecTime = (int) ($_ENV['EMAIL_MAX_EXECUTION_TIME'] ?? 60);
        if (function_exists('set_time_limit')) {
            set_time_limit($maxExecTime);
        }

        $verificationUrl = $this->generateVerificationUrl($token, $purpose);

        $subject = match ($purpose) {
            'registration'   => 'تأكيد البريد الإلكتروني',
            'email_change'   => 'تأكيد تغيير البريد الإلكتروني',
            'password_reset' => 'إعادة تعيين كلمة المرور',
            default          => 'تأكيد البريد الإلكتروني',
        };

        $title = match ($purpose) {
            'password_reset' => 'إعادة تعيين كلمة المرور',
            default          => 'تأكيد البريد الإلكتروني',
        };

        $userName  = htmlspecialchars((string) ($user['name'] ?? $user['username'] ?? ''), ENT_QUOTES, 'UTF-8');
        $safeUrl   = htmlspecialchars($verificationUrl, ENT_QUOTES, 'UTF-8');

        $bodyHtml =
            "<div dir=\"rtl\" style=\"font-family:Tahoma,Arial;line-height:1.8\">" .
            "<h2 style=\"margin:0 0 12px\">{$title}</h2>" .
            "<p>مرحباً {$userName}</p>" .
            ($purpose === 'password_reset'
                ? "<p>تم طلب إعادة تعيين كلمة المرور لحسابك. إذا لم تكن أنت من طلب ذلك، تجاهل هذه الرسالة.</p>"
                : "<p>الرجاء الضغط على الرابط التالي لإكمال العملية.</p>") .
            "<p><a href=\"{$safeUrl}\" style=\"display:inline-block;background:#2563eb;color:#fff;padding:10px 16px;border-radius:12px;text-decoration:none;font-weight:bold\">فتح الرابط</a></p>" .
            ($purpose === 'password_reset'
                ? "<p style=\"color:#64748b;font-size:12px\">صلاحية الرابط: 30 دقيقة.</p>"
                : "") .
            "</div>";

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host     = (string) ($_ENV['SMTP_HOST'] ?? '');
            $mail->SMTPAuth = true;
            $mail->Username = (string) ($_ENV['SMTP_USER'] ?? '');
            $mail->Password = (string) ($_ENV['SMTP_PASS'] ?? '');
            $mail->Port     = (int) ($_ENV['SMTP_PORT'] ?? 587);
            $mail->Timeout  = (int) ($_ENV['SMTP_TIMEOUT'] ?? 15);

            $secure = strtolower((string) ($_ENV['SMTP_SECURE'] ?? 'tls'));
            $mail->SMTPSecure = ($secure === 'ssl')
                ? PHPMailer::ENCRYPTION_SMTPS
                : PHPMailer::ENCRYPTION_STARTTLS;

            $mail->CharSet  = 'UTF-8';
            $mail->Encoding = 'base64';
            $mail->setFrom(
                (string) ($_ENV['SMTP_FROM'] ?? $_ENV['SMTP_USER'] ?? ''),
                (string) ($_ENV['SMTP_FROM_NAME'] ?? 'SmartSys')
            );
            $mail->addAddress($email);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $bodyHtml;
            $mail->send();

            $this->logger->info('Email sent', [
                'email'   => $email,
                'purpose' => $purpose,
                'user_id' => $user['id'] ?? null,
            ]);
        } catch (MailException $e) {
            $this->logger->error('Email sending failed', [
                'email'   => $email,
                'purpose' => $purpose,
                'error'   => $e->getMessage(),
            ]);
            throw new Exception('Failed to send email');
        }
    }
}
