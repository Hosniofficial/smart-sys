<?php

declare(strict_types=1);

namespace App\Handlers;

use Exception;
use App\Services\MonologHandler;
use App\Services\EmailVerificationService;
use App\Services\AuthSessionService;
use App\Exceptions\NotFoundException;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * HTTP endpoints for email verification and password recovery.
 *
 * Validates request payloads and delegates token, email, and password operations
 * to EmailVerificationService; registration and email-change verification may
 * also issue an authenticated session through AuthHandler.
 */
class EmailVerificationHandler extends BaseHandler
{
    private ?AuthSessionService $authSession;

    public function __construct(PDO $db, ?AuthSessionService $authSession = null)
    {
        parent::__construct($db);
        $this->authSession = $authSession;
        $this->logger = MonologHandler::getInstance('email_verification');
    }

    private function authSessionService(): AuthSessionService
    {
        return $this->authSession ??= new AuthSessionService($this->db);
    }

    /** Resolve the shared email verification service. */
    private function emailVerificationService(): EmailVerificationService
    {
        return $this->services->emailVerification();
    }

    /** Verify a token and issue login credentials when the purpose permits it. */
    public function verifyEmail(Request $request, Response $response): Response
    {
        try {
            $data = $request->getParsedBody();
            if (!is_array($data)) {
                return $this->errorResponse($response, 'Invalid request data', 400);
            }

            $token = trim((string) ($data['token'] ?? ''));
            if ($token === '') {
                return $this->errorResponse($response, 'Verification token is required', 400);
            }

            $result = $this->emailVerificationService()->verifyToken($token);

            $accessToken = null;
            $refreshToken = null;
            $userData = null;
            $baseResponse = null;

            if (
                in_array($result['purpose'] ?? '', ['registration', 'email_change'], true)
                && !empty($result['user'])
            ) {
                try {
                    $userId = (int) $result['user']['id'];

                    $authSession = $this->authSessionService();
                    $identity = $authSession->resolveIdentity($result['user']);
                    $accessToken = $authSession->generateAccessToken($identity);
                    $refreshToken = $authSession->generateRefreshToken($userId);
                    $authSession->storeRefreshToken($userId, $refreshToken);

                    $userData = [
                        'id' => $userId,
                        'name' => $result['user']['name'] ?? '',
                        'email' => $result['user']['email'] ?? '',
                        'username' => $result['user']['username'] ?? '',
                        'role' => (string) ($result['user']['role_name'] ?? $result['user']['role'] ?? 'user'),
                        'role_id' => (int) ($result['user']['role_id'] ?? 0),
                        'tenant_id' => (int) ($result['user']['tenant_id'] ?? 0),
                        'is_owner' => (int) ($result['user']['is_owner'] ?? 0),
                        'branch_id' => isset($result['user']['branch_id']) ? (int) $result['user']['branch_id'] : null,
                        'status' => $result['user']['status'] ?? 'active',
                        'is_setup_complete' => (int) ($result['is_setup_complete'] ?? 0),
                    ];

                    $baseResponse = $this->jsonResponse($response, [
                        'status'       => 'success',
                        'message'      => 'Email verified successfully',
                        'access_token' => $accessToken,
                        'data' => [
                            'user' => $userData,
                            'is_setup_complete' => (bool) ($result['is_setup_complete'] ?? false),
                        ],
                    ], 200, false);

                    $baseResponse = $authSession->setRefreshTokenCookie($baseResponse, $refreshToken);

                    $this->logger->info('verifyEmail: auto-login tokens generated and stored successfully', [
                        'user_id' => $userId,
                        'access_token_length' => strlen($accessToken),
                        'refresh_token_stored' => true,
                    ]);

                    return $baseResponse;
                } catch (\Throwable $e) {
                    $this->logger->warning('verifyEmail: failed to generate auto-login tokens', [
                        'user_id' => $result['user']['id'] ?? null,
                        'error'   => $e->getMessage(),
                        'trace'   => $e->getTraceAsString(),
                    ]);

                    // Keep verification successful if optional auto-login fails.
                    return $this->jsonResponse($response, [
                        'status'       => 'success',
                        'message'      => 'Email verified successfully (access token only)',
                        'access_token' => $accessToken,
                        'data' => [
                            'user' => $userData,
                            'is_setup_complete' => (bool) ($result['is_setup_complete'] ?? false),
                        ],
                    ], 200, false);
                }
            } else {
                $this->logger->warning('verifyEmail: auto-login conditions not met', [
                    'purpose_match' => in_array($result['purpose'] ?? '', ['registration', 'email_change'], true),
                    'purpose' => $result['purpose'] ?? 'null',
                    'has_user' => !empty($result['user']),
                ]);
            }

            return $this->jsonResponse($response, [
                'status'       => 'success',
                'message'      => 'Email verified successfully',
                'access_token' => $accessToken,
                'data' => [
                    'user' => $userData,
                    'is_setup_complete' => (bool) ($result['is_setup_complete'] ?? false),
                ],
            ], 200, false);
        } catch (Exception $e) {
            $msg = $e->getMessage();
            $this->logger->error('verifyEmail failed', ['message' => $msg]);
            $code = str_contains($msg, 'Too many') ? 429 : 400;
            return $this->errorResponse($response, $msg, $code);
        }
    }

    /** Request another verification message for the given address and purpose. */
    public function resendVerificationEmail(Request $request, Response $response): Response
    {
        try {
            $data = $request->getParsedBody();
            if (!is_array($data)) {
                return $this->errorResponse($response, 'Invalid request data', 400);
            }

            $email   = trim((string) ($data['email'] ?? ''));
            $purpose = (string) ($data['purpose'] ?? 'registration');

            if ($email === '') {
                return $this->errorResponse($response, 'البريد الإلكتروني مطلوب', 400);
            }

            $result = $this->emailVerificationService()->resend($email, $purpose, $request);

            return $this->successResponse($response, array_merge(
                ['message' => 'Verification email sent'],
                $result
            ));
        } catch (NotFoundException $e) {
            return $this->errorResponse($response, $e->getMessage(), 404);
        } catch (Exception $e) {
            $this->logger->error('resendVerificationEmail failed', ['message' => $e->getMessage()]);
            return $this->errorResponse($response, 'Failed to resend verification email', 500);
        }
    }

    /** Request a password-reset message without disclosing account existence. */
    public function forgotPassword(Request $request, Response $response): Response
    {
        // Use the same response for known and unknown addresses.
        $genericMsg = ['message' => 'إذا كان الإيميل مسجلاً، ستصلك رسالة خلال دقائق'];

        try {
            $data = $request->getParsedBody();
            if (!is_array($data)) {
                return $this->successResponse($response, $genericMsg);
            }

            $email = trim((string) ($data['email'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $this->successResponse($response, $genericMsg);
            }

            $this->emailVerificationService()->sendPasswordReset($email, $request);

            return $this->successResponse($response, $genericMsg);
        } catch (Exception $e) {
            $this->logger->error('forgotPassword failed', ['message' => $e->getMessage()]);
            return $this->successResponse($response, $genericMsg);
        }
    }

    /** Validate a reset token and delegate the password change. */
    public function resetPassword(Request $request, Response $response): Response
    {
        try {
            $data = $request->getParsedBody();
            if (!is_array($data)) {
                return $this->errorResponse($response, 'Invalid request data', 400);
            }

            $token       = trim((string) ($data['token'] ?? ''));
            $newPassword = (string) ($data['new_password'] ?? '');

            if ($token === '' || $newPassword === '') {
                return $this->errorResponse($response, 'بيانات غير مكتملة', 400);
            }

            $this->emailVerificationService()->resetPassword($token, $newPassword);

            return $this->jsonResponse($response, [
                'status'  => 'success',
                'message' => 'تم تحديث كلمة المرور وتسجيل الخروج من جميع الأجهزة.',
                'data'    => null,
            ], 200, false);
        } catch (Exception $e) {
            $this->logger->error('resetPassword failed', ['message' => $e->getMessage()]);
            return $this->errorResponse($response, $e->getMessage() ?: 'فشل تحديث كلمة المرور', 400);
        }
    }
}
