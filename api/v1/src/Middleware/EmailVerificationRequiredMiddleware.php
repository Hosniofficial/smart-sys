<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response as SlimResponse;
use App\Utils\ApiResponseEnvelope;
use App\Services\MonologHandler;
use PDO;
use PDOException;

/**
 * Enforces email verification before accessing restricted endpoints.
 *
 * This middleware checks that the authenticated user has verified their email address.
 * If `email_verified_at` is null, it returns 403 Forbidden with a clear message.
 *
 * Usage: Add to any routes that should require email verification:
 *   ->add(EmailVerificationRequiredMiddleware::class)
 */
class EmailVerificationRequiredMiddleware implements MiddlewareInterface
{
    private PDO $db;
    private $logger;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->logger = MonologHandler::getInstance('email_verification_required');
    }

    /**
     * Check if user has verified their email before proceeding.
     */
    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        $user = $request->getAttribute('user');

        if (is_array($user)) {
            $userId = (int) ($user['user_id'] ?? $user['id'] ?? 0);
            $tenantId = (int) ($user['tenant_id'] ?? 0);
        } elseif (is_object($user)) {
            $userId = (int) ($user->user_id ?? $user->id ?? 0);
            $tenantId = (int) ($user->tenant_id ?? 0);
        } else {
            return $this->errorResponse(401, 'Authentication required', 'AUTHENTICATION_REQUIRED');
        }

        if ($userId <= 0) {
            return $this->errorResponse(401, 'Authentication required', 'AUTHENTICATION_REQUIRED');
        }

        try {
            if ($tenantId > 0) {
                $stmt = $this->db->prepare(
                    'SELECT email_verified_at FROM users WHERE id = ? AND tenant_id = ? LIMIT 1'
                );
                $stmt->execute([$userId, $tenantId]);
            } else {
                $stmt = $this->db->prepare(
                    'SELECT email_verified_at FROM users
                     WHERE id = ? AND (tenant_id IS NULL OR tenant_id = 0)
                     LIMIT 1'
                );
                $stmt->execute([$userId]);
            }
            $userRecord = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->logger->error('Email verification status check failed', [
                'user_id' => $userId,
                'path' => $request->getUri()->getPath(),
                'exception' => $e,
            ]);

            return $this->errorResponse(
                503,
                'Email verification status is temporarily unavailable',
                'EMAIL_VERIFICATION_CHECK_UNAVAILABLE'
            );
        }

        if (!$userRecord) {
            return $this->errorResponse(401, 'Authentication required', 'AUTHENTICATION_REQUIRED');
        }

        if (empty($userRecord['email_verified_at'])) {
            $this->logger->warning('Email verification required but not completed', [
                'user_id' => $userId,
                'path' => $request->getUri()->getPath(),
            ]);

            return $this->errorResponse(
                403,
                'Email verification required',
                'EMAIL_NOT_VERIFIED',
                'Please verify your email address before accessing this resource. Check your inbox for the verification link.'
            );
        }

        return $handler->handle($request);
    }

    private function errorResponse(
        int $status,
        string $message,
        string $code,
        ?string $details = null
    ): SlimResponse {
        $payload = [
            'status' => 'error',
            'message' => $message,
            'code' => $code,
        ];
        if ($details !== null) {
            $payload['details'] = $details;
        }

        $response = new SlimResponse();
        $response->getBody()->write(json_encode(
            ApiResponseEnvelope::normalize($payload, $status),
            JSON_UNESCAPED_UNICODE
        ));

        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
