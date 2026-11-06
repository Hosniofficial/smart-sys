<?php

/**
 * cleanup_tokens.php — Nightly cleanup cron job.
 *
 * Removes expired/revoked tokens and idempotency keys to keep the DB lean.
 *
 * Schedule (run nightly at 02:00):
 *   Linux:   0 2 * * * php /path/to/smartsys/api/v1/crons/cleanup_tokens.php
 *   Windows: Task Scheduler → php.exe C:\xampp\htdocs\smartsys\api\v1\crons\cleanup_tokens.php
 *
 * Exit codes:
 *   0 — success
 *   1 — error
 */

declare(strict_types=1);

set_time_limit(120);
error_reporting(E_ALL);
ini_set('display_errors', '0');

$logFile = __DIR__ . '/../../../../logs/cleanup_' . date('Y-m-d') . '.log';

function clog(string $msg): void
{
    global $logFile;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND);
    echo $line;
}

clog('=== Token Cleanup Cron Started ===');

require_once __DIR__ . '/../../../vendor/autoload.php';

try {
    (Dotenv\Dotenv::createImmutable(__DIR__ . '/../../../'))->load();
} catch (\Throwable $e) {
    clog('ERROR: Could not load .env — ' . $e->getMessage());
    exit(1);
}

try {
    $pdo = new PDO(
        'mysql:host=' . ($_ENV['DB_HOST'] ?? 'localhost') .
        ';port=' . ($_ENV['DB_PORT'] ?? '3306') .
        ';dbname=' . ($_ENV['DB_DATABASE'] ?? 'inventory') .
        ';charset=utf8mb4',
        $_ENV['DB_USERNAME'] ?? 'root',
        $_ENV['DB_PASSWORD'] ?? '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (\PDOException $e) {
    clog('ERROR: DB connection failed — ' . $e->getMessage());
    exit(1);
}

$totalDeleted = 0;

// 1. Expired refresh tokens
try {
    $stmt = $pdo->prepare("
        DELETE FROM refresh_tokens
        WHERE expires_at < NOW()
           OR is_revoked = 1
    ");
    $stmt->execute();
    $n = $stmt->rowCount();
    $totalDeleted += $n;
    clog("refresh_tokens: deleted {$n} expired/revoked rows");
} catch (\Throwable $e) {
    clog('WARN: refresh_tokens cleanup failed — ' . $e->getMessage());
}

// 2. Expired JWT blacklist entries
// The blacklist table stores tokens until their natural expiry.
// Once expired, they can be safely removed (JWT validation will reject them anyway).
try {
    $stmt = $pdo->prepare("
        DELETE FROM jwt_blacklist
        WHERE expires_at < NOW()
    ");
    $stmt->execute();
    $n = $stmt->rowCount();
    $totalDeleted += $n;
    clog("jwt_blacklist: deleted {$n} expired rows");
} catch (\Throwable $e) {
    // Table may not exist in all environments
    clog('SKIP: jwt_blacklist — ' . $e->getMessage());
}

// 3. Expired idempotency keys
try {
    $idem = new \App\Services\IdempotencyService($pdo);
    $n    = $idem->purgeExpired();
    $totalDeleted += $n;
    clog("payment_idempotency_keys: deleted {$n} expired rows");
} catch (\Throwable $e) {
    clog('WARN: idempotency cleanup failed — ' . $e->getMessage());
}

// 4. Old login_attempts (keep 30 days)
try {
    $stmt = $pdo->prepare("
        DELETE FROM login_attempts
        WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $stmt->execute();
    $n = $stmt->rowCount();
    $totalDeleted += $n;
    clog("login_attempts: deleted {$n} old rows (>30 days)");
} catch (\Throwable $e) {
    clog('WARN: login_attempts cleanup failed — ' . $e->getMessage());
}

// 5. Old security_events (respect retention_days from config)
try {
    $retentionDays = (int) ($_ENV['SECURITY_LOG_RETENTION_DAYS'] ?? 365);
    $stmt = $pdo->prepare("
        DELETE FROM security_events
        WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
    ");
    $stmt->execute([$retentionDays]);
    $n = $stmt->rowCount();
    $totalDeleted += $n;
    clog("security_events: deleted {$n} rows older than {$retentionDays} days");
} catch (\Throwable $e) {
    clog('WARN: security_events cleanup failed — ' . $e->getMessage());
}

clog("=== Cleanup complete. Total rows deleted: {$totalDeleted} ===");
exit(0);
