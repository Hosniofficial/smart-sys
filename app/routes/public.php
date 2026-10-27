<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use DI\Container;
use Slim\App;
use App\Utils\ApiResponseEnvelope;

return function (App $app, Container $container): void {

    // ── Liveness probe (load balancer / uptime monitor) ───────────────────────
    // Lightweight — no DB call. Returns 200 if the PHP process is alive.
    $app->get('/check', function (Request $request, Response $response) {
        $response->getBody()->write(json_encode(ApiResponseEnvelope::normalize([
            'status'    => 'ok',
            'timestamp' => date('c'),
        ], 200), JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
    });

    // ── Readiness / full health check ─────────────────────────────────────────
    // Returns 200 if all critical checks pass, 503 otherwise.
    // Used by monitoring tools (UptimeRobot, Grafana, Prometheus, etc.)
    //
    // Checks:
    //   database     — PDO ping
    //   logs         — log directory writable
    //   disk         — free disk space (warn < 500 MB)
    //   subscriptions — active tenant count (business metric)
    //   expired_tokens — blacklist table size (maintenance metric)
    $app->get('/health', function (Request $request, Response $response) use ($container) {
        $checks  = [];
        $healthy = true;
        $db      = null;

        // 1. Database ping
        try {
            $db   = $container->get(PDO::class);
            $db->query('SELECT 1');
            $checks['database'] = ['status' => 'ok'];
        } catch (\Throwable $e) {
            $checks['database'] = ['status' => 'error', 'message' => 'DB unreachable'];
            $healthy = false;
        }

        // 2. Log directory writable
        $logDir = __DIR__ . '/../../logs';
        $logOk  = is_dir($logDir) && is_writable($logDir);
        $checks['logs'] = $logOk
            ? ['status' => 'ok']
            : ['status' => 'warn', 'message' => 'Log directory not writable'];

        // 3. Disk space
        $freeBytes = @disk_free_space(__DIR__);
        $freeMB    = $freeBytes !== false ? (int) round($freeBytes / 1024 / 1024) : null;
        $diskStatus = ($freeMB === null || $freeMB > 500) ? 'ok' : 'warn';
        if ($freeMB !== null && $freeMB < 100) {
            $diskStatus = 'error';
            $healthy    = false;
        }
        $checks['disk'] = ['status' => $diskStatus, 'free_mb' => $freeMB];

        // 4. Active subscriptions (business metric — non-critical)
        if ($db) {
            try {
                $stmt = $db->query("SELECT COUNT(*) FROM subscriptions WHERE status IN ('active','trial')");
                $checks['subscriptions'] = ['status' => 'ok', 'active' => (int) $stmt->fetchColumn()];
            } catch (\Throwable $e) {
                $checks['subscriptions'] = ['status' => 'skip'];
            }
        }

        // 5. JWT blacklist size (maintenance — warn if > 10k rows, should be pruned by cron)
        if ($db) {
            try {
                $stmt = $db->query("SELECT COUNT(*) FROM jwt_blacklist WHERE expires_at < NOW()");
                $expiredCount = (int) $stmt->fetchColumn();
                $checks['jwt_blacklist'] = [
                    'status'          => $expiredCount > 10000 ? 'warn' : 'ok',
                    'expired_entries' => $expiredCount,
                    'note'            => $expiredCount > 10000 ? 'Run blacklist cleanup cron' : null,
                ];
            } catch (\Throwable $e) {
                $checks['jwt_blacklist'] = ['status' => 'skip'];
            }
        }

        // 6. Idempotency keys cleanup check
        if ($db) {
            try {
                $stmt = $db->query("SELECT COUNT(*) FROM payment_idempotency_keys WHERE expires_at < NOW()");
                $expiredIdem = (int) $stmt->fetchColumn();
                $checks['idempotency_keys'] = [
                    'status'          => $expiredIdem > 5000 ? 'warn' : 'ok',
                    'expired_entries' => $expiredIdem,
                ];
            } catch (\Throwable $e) {
                $checks['idempotency_keys'] = ['status' => 'skip'];
            }
        }

        $payload = [
            'status'    => $healthy ? 'ok' : 'error',
            'message'   => $healthy ? 'Healthy' : 'Health checks failed',
            'timestamp' => date('c'),
            'version'   => $_ENV['APP_VERSION'] ?? '1.0.0',
            'env'       => $_ENV['APP_ENV']     ?? 'production',
            'uptime_s'  => (int) (microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true))),
            'checks'    => $checks,
        ];

        $response->getBody()->write(
            json_encode(
                ApiResponseEnvelope::normalize($payload, $healthy ? 200 : 503),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
            )
        );

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store, no-cache')
            ->withStatus($healthy ? 200 : 503);
    });

    // ── CORS preflight catch-all ──────────────────────────────────────────────
    $app->options('/{routes:.+}', function (Request $request, Response $response) {
        return $response;
    });
};
