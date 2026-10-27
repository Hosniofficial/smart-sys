<?php
declare(strict_types=1);

namespace App\Middleware;

use DI\Container;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use Firebase\JWT\BeforeValidException;
use DomainException;
use UnexpectedValueException;
use App\Services\JwtBlacklistService;
use App\Services\MonologHandler;
use App\Utils\ApiResponseEnvelope;
use App\Services\SecurityLogger;
use App\Services\UserSessionService;
use App\Utils\SuperAdminHelper;
use PDO;

/**
 * Authenticates bearer JWTs and attaches the verified identity to the request.
 *
 * Tokens are checked against the revocation store and configured HS256 secret;
 * tenant scope is required except for platform super-admin identities.
 */
class JwtAuthMiddleware implements MiddlewareInterface
{
    private JwtBlacklistService $jwtBlacklistService;
    private ?SecurityLogger $securityLogger;
    private ?Container $container;
    private ?array $securityConfig;
    private ?UserSessionService $userSessionService;
    private $logger;

    public function __construct(
        JwtBlacklistService $jwtBlacklistService,
        ?SecurityLogger $securityLogger = null,
        ?Container $container = null,
        ?UserSessionService $userSessionService = null
    ) {
        $this->jwtBlacklistService = $jwtBlacklistService;
        $this->securityLogger      = $securityLogger;
        $this->container           = $container;
        $this->securityConfig      = null;
        $this->userSessionService  = $userSessionService;
        $this->logger              = MonologHandler::getInstance('jwt_auth');
    }

    /** Validate the request token before forwarding the request downstream. */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = new Response();
        $path = $request->getUri()->getPath();

        if (strpos($path, '/auth/') !== false) {
            return $handler->handle($request);
        }

        try {
            // For protected routes, require a token
            $token = $this->getTokenFromHeader($request);
            if (empty($token)) {
                $this->securityLogger?->logSecurityEvent(
                    'auth.token_missing',
                    'warning',
                    'Token missing in request',
                    ['path' => $path],
                    null,
                    null,
                    $request
                );
                throw new \Exception('Token not found in request');
            }

            if ($this->jwtBlacklistService->isBlacklisted($token)) {
                $this->securityLogger?->logSecurityEvent(
                    'auth.token_blacklisted',
                    'medium',
                    'Blacklisted token used',
                    ['token_prefix' => substr($token, 0, 20) . '...'],
                    null,
                    null,
                    $request
                );
                throw new \Exception('Token has been blacklisted');
            }

            // Load JWT secret from config (with fallback to direct require if container not available)
            if ($this->container === null) {
                // Fallback for backward compatibility
                $securityConfigPath = __DIR__ . '/../../../../config/security.php';
                $this->securityConfig = file_exists($securityConfigPath) ? require $securityConfigPath : [];
            } else {
                // Use config from container (preferred)
                try {
                    $this->securityConfig = $this->container->get('config.security') ?? [];
                } catch (\Exception $e) {
                    // Fallback if config not in container
                    $securityConfigPath = __DIR__ . '/../../../../config/security.php';
                    $this->securityConfig = file_exists($securityConfigPath) ? require $securityConfigPath : [];
                }
            }

            $jwtConfig = $this->securityConfig['jwt'] ?? [];

            $secret = $jwtConfig['secret'] ?? ($_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET') ?: null);
            if (empty($secret)) {
                throw new \Exception('JWT secret is not configured');
            }

            // Decode and verify token with leeway for clock skew
            try {
                $decoded = JWT::decode($token, new Key($secret, 'HS256'));
                $decodedArray = (array)$decoded;

                // Additional token expiration check
                if (isset($decodedArray['exp']) && $decodedArray['exp'] < time()) {
                    throw new ExpiredException('Token has expired');
                }
            } catch (ExpiredException $e) {
                throw new \Exception('Token has expired');
            } catch (SignatureInvalidException $e) {
                throw new \Exception('Invalid token signature');
            } catch (BeforeValidException $e) {
                throw new \Exception('Token not yet valid');
            } catch (DomainException | UnexpectedValueException $e) {
                throw new \Exception('Invalid token format');
            }

            // Tenant users must carry a tenant ID; the platform super-admin
            // is the only identity allowed to use the platform scope (0).
            $hasTenantId = array_key_exists('tenant_id', $decodedArray)
                && $decodedArray['tenant_id'] !== '';
            $platformSuperAdmin = SuperAdminHelper::is($decodedArray);
            if (!$hasTenantId || ((int) $decodedArray['tenant_id'] <= 0 && !$platformSuperAdmin)) {
                $this->logger->warning('Tenant ID is missing in the JWT token');
                throw new \Exception('Tenant ID missing in token');
            }

            $userId = (int) ($decodedArray['user_id'] ?? $decodedArray['id'] ?? 0);
            $tenantId = (int) $decodedArray['tenant_id'];
            $tokenVersion = (int) ($decodedArray['token_version'] ?? 0);
            if (
                $userId <= 0
                || !$this->getUserSessionService()->isCurrentTokenVersion($userId, $tenantId, $tokenVersion)
            ) {
                throw new \Exception('Token has been revoked');
            }

            // Ensure we have all required user data with consistent format
            $userData = [
                'id'         => $userId,
                'username'   => $decodedArray['username']  ?? null,
                'email'      => $decodedArray['email']     ?? null,
                'role'       => $decodedArray['role']      ?? null,
                'role_id'    => $decodedArray['role_id']   ?? null,
                'tenant_id'  => $decodedArray['tenant_id'] ?? null,
                'branch_id'  => isset($decodedArray['branch_id']) ? (int) $decodedArray['branch_id'] : null,
                'full_name'  => $decodedArray['full_name'] ?? $decodedArray['name'] ?? null,
                'is_owner'   => (int) ($decodedArray['is_owner'] ?? 0),
            ];

            // Attach user data and tenant_id to the request
            // Also attach user_id for backward compatibility with existing handlers
            $request = $request
                ->withAttribute('user', $userData)
                ->withAttribute('tenant_id', $userData['tenant_id'])
                ->withAttribute('user_id', $userData['id']);
        } catch (\Exception $e) {
            $statusCode = 401;
            $errorMessage = 'Unauthorized';
            $errorCode = null;

            if ($e instanceof \RuntimeException || $e instanceof \PDOException) {
                $statusCode = 500;
                $errorMessage = 'Authentication service unavailable';
                $this->logger->error('JWT session verification failed', ['error' => $e->getMessage()]);
            } else {
                // More specific error messages for different exception types
                if ($e instanceof ExpiredException) {
                    $errorMessage = 'Session expired. Please log in again.';
                } elseif ($e instanceof SignatureInvalidException) {
                    $errorMessage = 'Invalid token signature';
                } elseif ($e->getMessage() === 'Token has been blacklisted') {
                    $errorMessage = 'Session has been terminated. Please log in again.';
                    $statusCode = 403; // Forbidden
                    $errorCode = 'session_revoked';
                } elseif ($e->getMessage() === 'Token has been revoked') {
                    $errorMessage = 'Session has been terminated. Please log in again.';
                    $statusCode = 403;
                    $errorCode = 'session_revoked';
                }
                $this->logger->warning('JWT Authentication Error', ['error' => $e->getMessage()]);
            }

            $response = $response->withStatus($statusCode);
            $error = [
                'status' => 'error',
                'message' => $errorMessage
            ];
            if ($errorCode !== null) {
                $error['error_code'] = $errorCode;
            }
            $response->getBody()->write(json_encode(
                ApiResponseEnvelope::normalize($error, $statusCode),
                JSON_UNESCAPED_UNICODE
            ));

            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Cache-Control', 'no-store')
                ->withHeader('Pragma', 'no-cache');
        }

        return $handler->handle($request);
    }

    /** Extract a bearer token from the Authorization header. */
    private function getTokenFromHeader(ServerRequestInterface $request): ?string
    {
        $header = $request->getHeaderLine('Authorization');

        if (empty($header)) {
            return null;
        }

        if (preg_match('/Bearer\s+(.*)$/i', $header, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function getUserSessionService(): UserSessionService
    {
        if ($this->userSessionService !== null) {
            return $this->userSessionService;
        }

        if ($this->container === null || !$this->container->has(PDO::class)) {
            throw new \RuntimeException('User session service is not configured.');
        }

        $this->userSessionService = new UserSessionService($this->container->get(PDO::class));

        return $this->userSessionService;
    }

}
