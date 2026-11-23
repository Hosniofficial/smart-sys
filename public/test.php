<?php
// Simple test - no dependencies
header('Content-Type: application/json');
http_response_code(200);
echo json_encode([
    'status' => 'ok',
    'message' => 'PHP is working',
    'php_version' => PHP_VERSION,
    'timestamp' => date('Y-m-d H:i:s'),
    'env_vars' => [
        'APP_ENV' => $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'not set',
        'DB_HOST' => isset($_ENV['DB_HOST']) || getenv('DB_HOST') ? 'set' : 'not set',
    ]
], JSON_PRETTY_PRINT);
