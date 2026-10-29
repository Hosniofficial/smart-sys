<?php

declare(strict_types=1);

/**
 * cleanup_cron.php — Maintenance cleanup tasks.
 *
 * Runs daily (recommended: 03:00 AM server time).
 *
 * Tasks:
 *   1. Purge expired JWT blacklist entries
 *   2. Purge expired payment idempotency keys
 *   3. Purge expired refresh tokens
 *   4. Purge old login_attempts records (> 30 days)
 *
 * Crontab example:
 *   0 3 * * * php /var/www/smartsys/api/v1/crons/cleanup_cron.php >> /var/log/smartsys-cleanup.log 2>&1
 *
 * Security: protected by CRON_SECRET header check (same as other crons).
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

use Dotenv\Dotenv;
use App\Utils\ApiResponseEnvelope;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../../');
$dotenv->load();

// ── Auth: CLI only or valid CRON_SECRET ──────────────────────────────────────
$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    $cronSecret = $_ENV['CRON_SECRET'] ?? '';
    $provided   = $_SERVER['HTTP_X_CRON_TOKEN'] ?? '';
    if ($cronSecret === '' || !hash_equals($cronSecret, $provided)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(ApiResponseEnvelope::normalize([
            'status' => 'error',
            'message' => 'Forbidden',
            'error' => 'Forbidden',
        ], 403), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit(1);
    }
}

// ── DB connection ─────────────────────────────────────────────────────────────
try {
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $_ENV['DB_HOST']     ?? 'localhost',
        $_ENV['DB_PORT']     ?? '3306',
        $_ENV['DB_DATABASE'] ?? 'inventory'
    );
    $pdo = new PDO($dsn, $_ENV['DB_USERNAME'] ?? 'root', $_ENV['DB_PASSWORD'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (\PDOException $e) {
    error_log('[CLEANUP] DB connection failed: ' . $e->getMessage());
    if ($isCli) {
        echo '[CLEANUP] DB connection failed: ' . $e->getMessage() . PHP_EOL;
    } else {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(ApiResponseEnvelope::normalize([
            'status' => 'error',
            'message' => 'Cleanup failed.',
        ], 500), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    exit(1);
}

$startTime = microtime(true);
$results   = [];

// ── Task 1: JWT blacklist ─────────────────────────────────────────────────────
try {
    $stmt = $pdo->prepare("DELETE FROM jwt_blacklist WHERE expires_at < NOW()");
    $stmt->execute();
    $results['jwt_blacklist'] = ['deleted' => $stmt->rowCount(), 'status' => 'ok'];
} catch (\Throwable $e) {
    error_log('[CLEANUP] JWT blacklist cleanup failed: ' . $e->getMessage());
    $results['jwt_blacklist'] = ['status' => 'error', 'message' => $e->getMessage()];
}

// ── Task 2: Payment idempotency keys ─────────────────────────────────────────
try {
    $stmt = $pdo->prepare("DELETE FROM payment_idempotency_keys WHERE expires_at < NOW()");
    $stmt->execute();
    $results['idempotency_keys'] = ['deleted' => $stmt->rowCount(), 'status' => 'ok'];
} catch (\Throwable $e) {
    error_log('[CLEANUP] Idempotency-key cleanup failed: ' . $e->getMessage());
    $results['idempotency_keys'] = ['status' => 'error', 'message' => $e->getMessage()];
}

// ── Task 3: Expired refresh tokens ───────────────────────────────────────────
try {
    $stmt = $pdo->prepare("DELETE FROM refresh_tokens WHERE expires_at < NOW()");
    $stmt->execute();
    $results['refresh_tokens'] = ['deleted' => $stmt->rowCount(), 'status' => 'ok'];
} catch (\Throwable $e) {
    error_log('[CLEANUP] Refresh-token cleanup failed: ' . $e->getMessage());
    $results['refresh_tokens'] = ['status' => 'error', 'message' => $e->getMessage()];
}

// ── Task 4: Old login attempts (> 30 days) ────────────────────────────────────
try {
    $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $stmt->execute();
    $results['login_attempts'] = ['deleted' => $stmt->rowCount(), 'status' => 'ok'];
} catch (\Throwable $e) {
    error_log('[CLEANUP] Login-attempt cleanup failed: ' . $e->getMessage());
    $results['login_attempts'] = ['status' => 'error', 'message' => $e->getMessage()];
}

// ── Task 5: Old security events (per retention policy) ───────────────────────
try {
    $retentionDays = (int) ($_ENV['SECURITY_LOG_RETENTION_DAYS'] ?? 365);
    $stmt = $pdo->prepare("DELETE FROM security_events WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
    $stmt->execute([$retentionDays]);
    $results['security_events'] = ['deleted' => $stmt->rowCount(), 'status' => 'ok'];
} catch (\Throwable $e) {
    error_log('[CLEANUP] Security-event cleanup failed: ' . $e->getMessage());
    $results['security_events'] = ['status' => 'error', 'message' => $e->getMessage()];
}

// ── Summary ───────────────────────────────────────────────────────────────────
$duration = round((microtime(true) - $startTime) * 1000, 1);
$hasErrors = array_filter($results, fn ($r) => ($r['status'] ?? '') === 'error');

$output = [
    'timestamp'  => date('c'),
    'duration_ms' => $duration,
    'status'     => empty($hasErrors) ? 'ok' : 'partial',
    'tasks'      => $results,
];

if ($isCli) {
    echo json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
} else {
    header('Content-Type: application/json');
    foreach ($output['tasks'] as &$task) {
        if (($task['status'] ?? '') === 'error') {
            unset($task['message']);
        }
    }
    unset($task);
    $httpPayload = array_merge($output, [
        'message' => empty($hasErrors) ? 'Cleanup completed' : 'Cleanup completed with errors',
    ]);
    http_response_code(empty($hasErrors) ? 200 : 500);
    echo json_encode(
        ApiResponseEnvelope::normalize($httpPayload, empty($hasErrors) ? 200 : 500),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );
}
exit(empty($hasErrors) ? 0 : 1);
