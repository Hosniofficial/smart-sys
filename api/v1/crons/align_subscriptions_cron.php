<?php

/**
 * External Cron Job: Align Subscriptions with Assigned Plans
 * Run this script periodically (e.g., every 5 minutes) via system cron
 *
 * Usage: php align_subscriptions_cron.php
 * OR: php align_subscriptions_cron.php --http (HTTP call)
 * Add to crontab (local execution):
 * 5 * * * * /usr/bin/php /path/to/smartsys/api/v1/crons/align_subscriptions_cron.php
 *
 * Add to crontab (HTTP execution - uses ADMIN_API_URL and CRON_SECRET from .env):
 * 5 * * * * /usr/bin/php /path/to/smartsys/api/v1/crons/align_subscriptions_cron.php --http
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
$dotenv = \Dotenv\Dotenv::createImmutable(__DIR__ . '/../../../');
$dotenv->safeLoad();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/bootstrap.php';

class AlignSubscriptionsCron
{
    private PDO $db;

    public function __construct()
    {
        $database = new Database();
        $this->db = $database->pdo;
    }

    public function run(): void
    {
        echo "[" . date('Y-m-d H:i:s') . "] Starting subscription alignment cron...\n";

        // Ensure default plans exist (idempotent — moved here from SubscriptionMiddleware
        // where it ran on every HTTP request, causing unnecessary DB writes per request)
        $this->seedPlans();

        $tenants = $this->getAllTenants();
        $processed = 0;
        $updated = 0;

        foreach ($tenants as $tenant) {
            $processed++;
            if ($this->alignTenantSubscription((int)$tenant['id'])) {
                $updated++;
            }
        }

        echo "[" . date('Y-m-d H:i:s') . "] Completed. Processed: {$processed}, Updated: {$updated}\n";
    }

    private function getAllTenants(): array
    {
        $stmt = $this->db->query("SELECT id FROM tenants ORDER BY id");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Seed default subscription plans (idempotent).
     * Moved from SubscriptionMiddleware where it ran on every HTTP request.
     */
    private function seedPlans(): void
    {
        try {
            $this->db->exec("INSERT INTO plans (code, name, price, currency, billing_cycle_days, features_json, is_active)
                VALUES ('trial','Trial 14 days',0,'SAR',14, JSON_OBJECT('all_features', true),1)
                ON DUPLICATE KEY UPDATE name=VALUES(name), is_active=VALUES(is_active)");
            $this->db->exec("INSERT INTO plans (code, name, price, currency, billing_cycle_days, features_json, is_active)
                VALUES ('monthly','Monthly Plan',300,'SAR',30, JSON_OBJECT('all_features', true),1)
                ON DUPLICATE KEY UPDATE name=VALUES(name), is_active=VALUES(is_active)");
            $this->db->exec("INSERT INTO plans (code, name, price, currency, billing_cycle_days, features_json, is_active)
                VALUES ('yearly','Yearly Plan',2500,'SAR',365, JSON_OBJECT('all_features', true),1)
                ON DUPLICATE KEY UPDATE name=VALUES(name), is_active=VALUES(is_active)");
            echo "[" . date('Y-m-d H:i:s') . "] Plans seeded/verified.\n";
        } catch (\Throwable $e) {
            echo "[" . date('Y-m-d H:i:s') . "] Warning: seedPlans failed: " . $e->getMessage() . "\n";
        }
    }

    private function alignTenantSubscription(int $tenantId): bool
    {
        try {
            // Get assigned plan from companies or tenants table
            $assigned = null;

            // Try companies table first
            try {
                $st = $this->db->prepare("SELECT plan_id, status FROM companies WHERE id = ? LIMIT 1");
                if ($st->execute([$tenantId])) {
                    $row = $st->fetch(PDO::FETCH_ASSOC);
                    if ($row && !empty($row['plan_id'])) {
                        $assigned = ['plan_id' => (int)$row['plan_id'], 'status' => strtolower((string)$row['status'])];
                    }
                }
            } catch (Throwable $e) { /* table may not exist */
            }

            // Try tenants table
            if (!$assigned) {
                try {
                    $st = $this->db->prepare("SELECT plan_id, status FROM tenants WHERE id = ? LIMIT 1");
                    if ($st->execute([$tenantId])) {
                        $row = $st->fetch(PDO::FETCH_ASSOC);
                        if ($row && !empty($row['plan_id'])) {
                            $assigned = ['plan_id' => (int)$row['plan_id'], 'status' => strtolower((string)$row['status'])];
                        }
                    }
                } catch (Throwable $e) { /* ignore */
                }
            }

            if (!$assigned || !in_array($assigned['status'] ?? 'active', ['active', 'trial'], true)) {
                return false; // nothing to align
            }

            // Get current subscription
            $currentSub = null;
            try {
                $st = $this->db->prepare("SELECT * FROM subscriptions WHERE tenant_id = ? ORDER BY id DESC LIMIT 1");
                $st->execute([$tenantId]);
                $currentSub = $st->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                return false;
            }

            // Check if alignment is needed
            if ($currentSub && (int)$currentSub['plan_id'] === $assigned['plan_id']) {
                return false; // already aligned
            }

            // Create/update subscription
            if ($currentSub) {
                // Update existing subscription
                $st = $this->db->prepare("
                    UPDATE subscriptions 
                    SET plan_id = ?, status = ?, updated_at = NOW() 
                    WHERE id = ?
                ");
                $st->execute([$assigned['plan_id'], $assigned['status'], $currentSub['id']]);
            } else {
                // Create new subscription
                $st = $this->db->prepare("
                    INSERT INTO subscriptions (tenant_id, plan_id, status, starts_at, created_at, updated_at) 
                    VALUES (?, ?, ?, NOW(), NOW(), NOW())
                ");
                $st->execute([$tenantId, $assigned['plan_id'], $assigned['status']]);
            }

            echo "[" . date('Y-m-d H:i:s') . "] Aligned tenant {$tenantId} to plan {$assigned['plan_id']}\n";
            return true;

        } catch (Throwable $e) {
            echo "[" . date('Y-m-d H:i:s') . "] Error aligning tenant {$tenantId}: " . $e->getMessage() . "\n";
            return false;
        }
    }
}

// Process command line arguments
$runViaHttp = in_array('--http', $argv ?? [], true);
$runCronRemote = $_ENV['RUN_CRON_REMOTE'] ?? getenv('RUN_CRON_REMOTE') ?? '';
$useRemote = $runCronRemote === '1' || $runViaHttp;

if ($useRemote) {
    // Call via HTTP with X-Cron-Token header
    $apiUrl = trim((string) ($_ENV['ADMIN_API_URL'] ?? getenv('ADMIN_API_URL') ?? ''));
    if (!$apiUrl) {
        $apiBaseUrl = rtrim((string) ($_ENV['API_BASE_URL'] ?? getenv('API_BASE_URL') ?? ''), '/');
        if ($apiBaseUrl === '') {
            fwrite(STDERR, "ADMIN_API_URL or API_BASE_URL must be configured for remote cron execution.\n");
            exit(1);
        }
        $apiUrl = $apiBaseUrl . '/smartsys/api/v1/admin/subscriptions/cron';
    }
    $cronSecret = (string) ($_ENV['CRON_SECRET'] ?? getenv('CRON_SECRET') ?? '');
    if ($cronSecret === '') {
        fwrite(STDERR, "CRON_SECRET must be configured for remote cron execution.\n");
        exit(1);
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $apiUrl,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'X-Cron-Token: ' . $cronSecret,
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    echo "[" . date('Y-m-d H:i:s') . "] HTTP Cron Call\n";
    echo "[" . date('Y-m-d H:i:s') . "] URL: {$apiUrl}\n";
    echo "[" . date('Y-m-d H:i:s') . "] HTTP Status: {$httpCode}\n";
    echo "[" . date('Y-m-d H:i:s') . "] Response: {$response}\n";
} else {
    // Direct execution (local execution)
    $cron = new AlignSubscriptionsCron();
    $cron->run();
}
