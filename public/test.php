<?php
// Simple test - no dependencies
header('Content-Type: application/json');
http_response_code(200);

// Get environment variables
$appEnv = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'not set';
$dbHost = $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: 'not set';
$dbPort = $_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: 'not set';
$dbDatabase = $_ENV['DB_DATABASE'] ?? getenv('DB_DATABASE') ?: 'not set';
$dbUsername = $_ENV['DB_USERNAME'] ?? getenv('DB_USERNAME') ?: 'not set';
$dbPassword = ($_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD')) ? '***set***' : 'not set';
$jwtSecret = ($_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET')) ? '***set***' : 'not set';

echo json_encode([
    'status' => 'ok',
    'message' => 'PHP is working',
    'php_version' => PHP_VERSION,
    'timestamp' => date('Y-m-d H:i:s'),
    'env_vars' => [
        'APP_ENV' => $appEnv,
        'DB_HOST' => $dbHost,
        'DB_PORT' => $dbPort,
        'DB_DATABASE' => $dbDatabase,
        'DB_USERNAME' => $dbUsername,
        'DB_PASSWORD' => $dbPassword,
        'JWT_SECRET' => $jwtSecret,
    ]
], JSON_PRETTY_PRINT);
