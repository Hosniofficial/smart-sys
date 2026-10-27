#!/usr/bin/env php
<?php

/**
 * ════════════════════════════════════════════════════════════════════════════════
 * SmartSys ERP - Notification Batch Processor Cron Job
 * ════════════════════════════════════════════════════════════════════════════════
 * 
 * Purpose: Process pending notification batches and send aggregated notifications
 * 
 * Schedule: Run every 5 minutes
 * 
 * Crontab Example:
 * Run every 5 minutes: php /path/to/smartsys/api/v1/crons/process_notification_batches.php
 * 
 * Security:
 * - Requires CRON_SECRET header or X-Cron-Token header
 * - Only processes batches that are scheduled (scheduled_at <= NOW())
 * 
 * What it does:
 * 1. Finds all pending batches scheduled for sending
 * 2. Finalizes batches (marks as 'sent')
 * 3. Aggregated notifications already created by NotificationAggregatorService
 * 4. Logs processing results
 * 
 * ════════════════════════════════════════════════════════════════════════════════
 */

declare(strict_types=1);

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');

// Load environment
require_once __DIR__ . '/../../../vendor/autoload.php';

use App\Services\NotificationAggregatorService;
use App\Services\MonologHandler;
use App\Utils\ApiResponseEnvelope;

// Load .env
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../..');
$dotenv->load();

// Verify cron secret if running via HTTP
if (php_sapi_name() !== 'cli') {
    $cronSecret = $_ENV['CRON_SECRET'] ?? null;
    $providedSecret = $_SERVER['HTTP_X_CRON_TOKEN'] ?? $_GET['token'] ?? null;

    if (!$cronSecret || $providedSecret !== $cronSecret) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(ApiResponseEnvelope::normalize([
            'status' => 'error',
            'message' => 'Unauthorized - Invalid cron token',
            'error' => 'Unauthorized - Invalid cron token'
        ], 403), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit(1);
    }
}

// Initialize logger
$logger = MonologHandler::getInstance('cron-notifications');
$startTime = microtime(true);

$logger->info('=== Notification Batch Processor Started ===');

try {
    // Database connection
    $host = $_ENV['DB_HOST'] ?? 'localhost';
    $port = $_ENV['DB_PORT'] ?? '3306';
    $database = $_ENV['DB_DATABASE'] ?? 'inventory';
    $username = $_ENV['DB_USERNAME'] ?? 'root';
    $password = $_ENV['DB_PASSWORD'] ?? '';
    $charset = $_ENV['DB_CHARSET'] ?? 'utf8mb4';
    
    $dsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";
    
    $db = new PDO(
        $dsn,
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );

    $logger->info('Database connected successfully');

    // Initialize NotificationAggregatorService
    $aggregator = new NotificationAggregatorService($db, $logger);

    // Process pending batches
    $logger->info('Processing pending notification batches...');
    $processedCount = $aggregator->processPendingBatches();

    $duration = round(microtime(true) - $startTime, 2);

    $logger->info('=== Notification Batch Processor Completed ===', [
        'processed_batches' => $processedCount,
        'duration_seconds' => $duration
    ]);

    $message = sprintf(
        'Notification batch processor completed: %d batches processed in %.2f seconds',
        $processedCount,
        $duration
    );
    if (PHP_SAPI === 'cli') {
        echo sprintf("[%s] %s\n", date('Y-m-d H:i:s'), $message);
    } else {
        header('Content-Type: application/json');
        echo json_encode(ApiResponseEnvelope::normalize([
            'status' => 'success',
            'message' => $message,
            'data' => [
                'processed_batches' => $processedCount,
                'duration_seconds' => $duration,
            ],
        ], 200), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    exit(0);

} catch (PDOException $e) {
    $logger->error('Database error in notification batch processor', [
        'error' => $e->getMessage(),
        'code' => $e->getCode()
    ]);

    $message = 'ERROR: Database connection failed - ' . $e->getMessage();
    if (PHP_SAPI === 'cli') {
        echo sprintf("[%s] %s\n", date('Y-m-d H:i:s'), $message);
    } else {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(ApiResponseEnvelope::normalize([
            'status' => 'error',
            'message' => $message,
        ], 500), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    exit(1);

} catch (Throwable $e) {
    $logger->error('Fatal error in notification batch processor', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);

    $message = 'ERROR: ' . $e->getMessage();
    if (PHP_SAPI === 'cli') {
        echo sprintf("[%s] %s\n", date('Y-m-d H:i:s'), $message);
    } else {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(ApiResponseEnvelope::normalize([
            'status' => 'error',
            'message' => $message,
        ], 500), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    exit(1);
}
