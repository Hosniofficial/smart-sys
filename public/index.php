<?php

declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════════════════
// ERROR REPORTING CONFIGURATION (Production Safe - Permanent Solution)
// ═══════════════════════════════════════════════════════════════════════════
// This is a PERMANENT fix for deprecation warnings flooding logs.
// Will NOT be removed by composer update/install.
// Safe for production deployment.
// ═══════════════════════════════════════════════════════════════════════════

// Load environment early to determine error reporting level
require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use App\Utils\ApiResponseEnvelope;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

// Determine environment
$appEnv = strtolower($_ENV['APP_ENV'] ?? 'production');
$isProduction = in_array($appEnv, ['production', 'prod'], true);
$isDevelopment = in_array($appEnv, ['development', 'dev', 'local'], true);

if ($isProduction) {
    // ═══════════════════════════════════════════════════════════════════════
    // PRODUCTION: Maximum security & clean logs
    // ═══════════════════════════════════════════════════════════════════════
    // - Hide E_DEPRECATED & E_STRICT (vendor library warnings)
    // - Never display errors on screen (security risk)
    // - Log all real errors to file for debugging
    // ═══════════════════════════════════════════════════════════════════════
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', __DIR__ . '/../logs/error.log');
    
} elseif ($isDevelopment) {
    // ═══════════════════════════════════════════════════════════════════════
    // DEVELOPMENT: Show everything for debugging
    // ═══════════════════════════════════════════════════════════════════════
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    ini_set('log_errors', '1');
    ini_set('error_log', __DIR__ . '/../logs/error.log');
    
} else {
    // ═══════════════════════════════════════════════════════════════════════
    // STAGING/OTHER: Moderate logging (show warnings, hide deprecations)
    // ═══════════════════════════════════════════════════════════════════════
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT & ~E_NOTICE);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', __DIR__ . '/../logs/error.log');
}

function normalizeRequestPath(string $rawUri, string $basePath): string
{
    $parsedUri = parse_url($rawUri);
    $path = urldecode($parsedUri['path'] ?? '/');

    if (preg_match('#^/smartsys(?:/|$)#', $path)) {
        $path = preg_replace('#^/smartsys#', '', $path, 1) ?: '/';
    }

    $basePath = trim($basePath, '/');
    if ($basePath !== '') {
        $basePrefix = '/' . $basePath;
        if ($path === $basePrefix || strpos($path, $basePrefix . '/') === 0) {
            $path = substr($path, strlen($basePrefix));
            if ($path === '') {
                $path = '/';
            }
        }
    }

    if ($path === '/api') {
        $path = '/api/v1';
    }

    if (preg_match('#^/api/(?!v1/)(.*)#', $path, $m)) {
        $path = '/api/v1/' . $m[1];
    }

    if ($path === '') {
        $path = '/';
    }

    return $path;
}

// ══════════════════════════════════════════════════════════
// Static files handler — Dev only (PHP built-in server)
// يخدّم الصور والـ CSS والـ JS مباشرةً بدون routing
// ══════════════════════════════════════════════════════════
$basePath = (string) ($_ENV['APP_BASE_PATH'] ?? getenv('APP_BASE_PATH') ?? '/smartsys/api/v1');
$rawRequestUri = $_SERVER['REQUEST_URI'] ?? '/';
$parsedRequestUri = parse_url($rawRequestUri);
$uri = normalizeRequestPath($rawRequestUri, $basePath);

$staticFile = __DIR__ . $uri;
if ($uri !== '/' && is_file($staticFile)) {
    return false;
}
// ══════════════════════════════════════════════════════════

use Slim\Factory\AppFactory;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    $config = require __DIR__ . '/../config/security.php';
    $corsConfig = is_array($config) ? ($config['cors'] ?? []) : [];

    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowedOrigins = array_values(array_filter(array_map('trim', (array) ($corsConfig['origin'] ?? [])), fn($item) => $item !== ''));

    if ($origin !== '' && (in_array('*', $allowedOrigins, true) || in_array($origin, $allowedOrigins, true))) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }

    $requestedMethod = $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'] ?? '';
    $methods = $requestedMethod !== '' ? $requestedMethod : implode(', ', (array) ($corsConfig['methods'] ?? []));
    if ($methods !== '') {
        header('Access-Control-Allow-Methods: ' . $methods);
    }

    $allowedHeaderMap = [];
    foreach ((array) ($corsConfig['headers'] ?? []) as $header) {
        $trimmedHeader = trim((string) $header);
        if ($trimmedHeader !== '') {
            $allowedHeaderMap[strtolower($trimmedHeader)] = $trimmedHeader;
        }
    }

    $requestedHeaderValue = $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] ?? '';
    $requestedHeaders = array_values(array_unique(array_filter(array_map('trim', preg_split('/\s*,\s*/', $requestedHeaderValue)), fn($header) => $header !== '')));
    $invalidHeaders = [];
    $validHeaders = [];
    foreach ($requestedHeaders as $header) {
        $normalizedHeader = strtolower($header);
        if (isset($allowedHeaderMap[$normalizedHeader])) {
            $validHeaders[] = $allowedHeaderMap[$normalizedHeader];
        } else {
            $invalidHeaders[] = $header;
        }
    }

    if (!empty($invalidHeaders)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(ApiResponseEnvelope::normalize([
            'status' => 'error',
            'message' => 'Requested CORS headers are not allowed.',
            'invalid_headers' => $invalidHeaders,
        ], 403), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if (!empty($validHeaders)) {
        header('Access-Control-Allow-Headers: ' . implode(', ', $validHeaders));
    } elseif (!empty($allowedHeaderMap)) {
        header('Access-Control-Allow-Headers: ' . implode(', ', array_values($allowedHeaderMap)));
    }

    if (!empty($corsConfig['credentials'])) {
        header('Access-Control-Allow-Credentials: true');
    }

    if (isset($corsConfig['max_age'])) {
        header('Access-Control-Max-Age: ' . (string) (int) $corsConfig['max_age']);
    }

    http_response_code(204);
    exit;
}

// ── normalize URI: /smartsys/api/something or /api/something → /api/v1/something ────────────────────
$requestedPath = $_SERVER['REQUEST_URI'] ?? '/';
$parsedRequest = parse_url($requestedPath);
$currentPath = normalizeRequestPath($requestedPath, (string) ($_ENV['APP_BASE_PATH'] ?? getenv('APP_BASE_PATH') ?? '/smartsys/api/v1'));
$queryString = isset($parsedRequest['query']) && $parsedRequest['query'] !== '' ? '?' . $parsedRequest['query'] : '';
$_SERVER['REQUEST_URI'] = $currentPath . $queryString;

// ── Container ─────────────────────────────────────────────────────────────
$container = (require __DIR__ . '/../config/container.php')();
(require __DIR__ . '/../config/bootstrap.php')($container);

// ── App ───────────────────────────────────────────────────────────────────
AppFactory::setContainer($container);
$app = AppFactory::create();

// ── Middleware ────────────────────────────────────────────────────────────
(require __DIR__ . '/../config/middleware.php')($app, $container);

// ── Routes ────────────────────────────────────────────────────────────────
(require __DIR__ . '/../config/routes.php')($app, $container);

// ── Run ───────────────────────────────────────────────────────────────────
$app->run();