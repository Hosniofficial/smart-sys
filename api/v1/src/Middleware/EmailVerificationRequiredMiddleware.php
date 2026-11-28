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
    private $logger;

    public function __construct()
    {
        $this->logger = MonologHandler::getInstance('email_verification_required');
    }

    /**
     * Check if user has verified their email before proceeding.
     */
    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        $user = $request->getAttribute('user');

        // No user attribute - let other middleware handle authentication
        if (!$user) {
            return $handler->handle($request);
        }

        // Convert array to object if needed for consistent access
        if (is_array($user)) {
            $email_verified_at = $user['email_verified_at'] ?? null;
            $user_id = $user['user_id'] ?? $user['id'] ?? null;
        } else {
            $email_verified_at = $user->email_verified_at ?? null;
            $user_id = $user->user_id ?? $user->id ?? null;
        }

        // Check if email is verified
        if (empty($email_verified_at)) {
            $this->logger->warning('Email verification required but not completed', [
                'user_id' => $user_id,
                'email' => is_array($user) ? ($user['email'] ?? 'unknown') : ($user->email ?? 'unknown'),
                'path' => $request->getUri()->getPath(),
            ]);

            $response = new SlimResponse();
            $response->getBody()->write(json_encode(ApiResponseEnvelope::normalize([
                'status' => 'error',
                'message' => 'Email verification required',
                'code' => 'EMAIL_NOT_VERIFIED',
                'details' => 'Please verify your email address before accessing this resource. Check your inbox for the verification link.',
            ], 403), JSON_UNESCAPED_UNICODE));

            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(403);
        }

        // Email is verified - proceed
        return $handler->handle($request);
    }
}
