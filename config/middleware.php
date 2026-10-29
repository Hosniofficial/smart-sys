<?php

declare(strict_types=1);

use App\Middleware\CorsMiddleware;
use App\Middleware\HttpsEnforcementMiddleware;
use App\Middleware\RequestRateLimiter;
use App\Middleware\SecurityHeadersMiddleware;
use App\Exceptions\ForbiddenException;
use App\Utils\ApiResponseEnvelope;
use DI\Container;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;

return function (App $app, Container $container): void {
    // Load CORS config from container (from config.security['cors'])
    try {
        $securityConfig = $container->get('config.security');
        $corsConfig = $securityConfig['cors'] ?? [];
    } catch (\Exception $e) {
        // Fallback to direct require if container config not available
        $securityConfig = require __DIR__ . '/security.php';
        $corsConfig = $securityConfig['cors'] ?? [];
    }

    error_reporting(E_ALL);

    // Do not emit raw PHP warnings/notices into HTTP responses.
    // Always log errors to file and keep display_errors disabled so JSON responses stay valid.
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('html_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', __DIR__ . '/../logs/error.log');

    // CORS Middleware MUST be first (added last in Slim order)
    $app->add(new CorsMiddleware($corsConfig ?? []));
    
    // Body parsing must be early to handle JSON before other middleware
    $app->addBodyParsingMiddleware();
    
    $app->add(new SecurityHeadersMiddleware(
        headers: $securityConfig['security_headers'] ?? [],
        cspDirectives: $securityConfig['csp'] ?? []
    ));

    $httpsEnabled = ($_ENV['HTTPS_ENFORCEMENT_ENABLED'] ?? 'true') === 'true';
    $app->add(new HttpsEnforcementMiddleware(
        $httpsEnabled,
        true,
        ['/health', '/status', '/metrics']
    ));

    $rateLimitingConfig = $securityConfig['rate_limiting'] ?? [];
    $rateLimitedPaths = array_values(array_filter(array_map(
        static fn(array $rule): string => (string) ($rule['path'] ?? ''),
        $rateLimitingConfig['rules'] ?? []
    )));
    if (($rateLimitingConfig['enabled'] ?? false) && $rateLimitedPaths !== []) {
        $app->add(function (Request $request, $handler) use ($container, $rateLimitedPaths) {
            $path = rtrim($request->getUri()->getPath(), '/') ?: '/';
            foreach ($rateLimitedPaths as $rateLimitedPath) {
                if (strpos($path, $rateLimitedPath) !== false) {
                    return $container->get(RequestRateLimiter::class)->process($request, $handler);
                }
            }

            return $handler->handle($request);
        });
    }

    $app->add(function (Request $request, $handler) {
        $uri = $request->getUri();
        $path = $uri->getPath();

        if ($path !== '/' && substr($path, -1) === '/') {
            $path = rtrim($path, '/');
            $uri = $uri->withPath($path);
            $request = $request->withUri($uri);
        }

        return $handler->handle($request);
    });

    $app->addRoutingMiddleware();

    $displayErrorDetails = ($_ENV['APP_ENV'] ?? 'production') === 'development';
    $logErrors = true;
    $logErrorDetails = true;

    $errorMiddleware = $app->addErrorMiddleware(
        $displayErrorDetails,
        $logErrors,
        $logErrorDetails
    );

    $errorMiddleware->setDefaultErrorHandler(function (
        Request $request,
        Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails
    ) use ($app, $corsConfig): Response {
        $response = $app->getResponseFactory()
            ->createResponse()
            ->withHeader('Content-Type', 'application/json');

        $origin = $request->getHeaderLine('Origin');
        $allowedOrigins = $corsConfig['origin'] ?? [];

        if (
            in_array('*', $allowedOrigins, true) ||
            in_array($origin, $allowedOrigins, true)
        ) {
            $response = $response
                ->withHeader('Access-Control-Allow-Origin', $origin)
                ->withHeader('Access-Control-Allow-Credentials', 'true')
                ->withHeader('Access-Control-Expose-Headers', 'Authorization, Content-Type')
                ->withHeader('Vary', 'Origin');
        }

        $statusCode = (int) $exception->getCode();
        if ($statusCode < 400 || $statusCode > 599) {
            $statusCode = 500;
        }

        // ForbiddenException always maps to 403 regardless of code
        if ($exception instanceof ForbiddenException) {
            $statusCode = 403;
        }

        // For client errors (4xx) always show the real message.
        // For server errors (5xx) only show details in development.
        $isClientError = $statusCode >= 400 && $statusCode < 500;

        $payload = [
            'status' => 'error',
            'message' => ($isClientError || $displayErrorDetails)
                ? $exception->getMessage()
                : 'Internal server error',
        ];

        $response->getBody()->write(
            json_encode(
                ApiResponseEnvelope::normalize($payload, $statusCode),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            )
        );

        return $response->withStatus($statusCode);
    });
};
