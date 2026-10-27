<?php

/**
 * Daily Cron Job: Product Expiry Alerts
 *
 * Sends notifications for products expiring within 30 days
 *
 * Setup:
 * Linux/Mac: Add to crontab
 *   0 8 * * * php /path/to/smartsys/api/v1/crons/daily_expiry_alerts.php
 *
 * Windows: Use Task Scheduler with PHP
 *   php.exe C:\xampp\htdocs\smartsys\api\v1\crons\daily_expiry_alerts.php
 */

set_time_limit(300); // 5 minutes
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display, but log everything

// Logging
$logFile = __DIR__ . '/../../../../logs/expiry_alerts_' . date('Y-m-d') . '.log';
function log_cron($message)
{
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $msg = "[$timestamp] $message\n";
    file_put_contents($logFile, $msg, FILE_APPEND);
    echo $msg;
}

log_cron('=== Daily Expiry Alerts Cron Started ===');

try {
    // Database connection
    require_once __DIR__ . '/../../../config/database.php';

    $db = new PDO(
        "mysql:host=" . ($_ENV['DB_HOST'] ?? 'localhost') . ";dbname=" . ($_ENV['DB_DATABASE'] ?? 'inventory'),
        $_ENV['DB_USERNAME'] ?? 'root',
        $_ENV['DB_PASSWORD'] ?? '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    log_cron('Database connection established');

    // Load Composer autoloader
    require_once __DIR__ . '/../../../vendor/autoload.php';

    // Get all active tenants
    $tenantStmt = $db->prepare("
        SELECT DISTINCT p.tenant_id
        FROM products p
        JOIN product_expiry b
          ON b.product_id = p.id AND b.tenant_id = p.tenant_id
        WHERE b.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
          AND b.expiry_date > CURDATE()
    ");
    $tenantStmt->execute();
    $tenants = $tenantStmt->fetchAll(PDO::FETCH_COLUMN);

    log_cron('Found ' . count($tenants) . ' tenant(s) with expiring products');

    // Process each tenant
    $totalAlertsCount = 0;
    foreach ($tenants as $tenantId) {
        log_cron("Processing tenant ID: $tenantId");

        try {
            // Create handler for this tenant
            $notificationHandler = new \App\Handlers\NotificationHandler($db);

            // Get products expiring within 30 days
            $productStmt = $db->prepare("
                SELECT p.id, p.name, MIN(b.expiry_date) AS nearest_expiry_date
                FROM products p
                JOIN product_expiry b
                  ON b.product_id = p.id AND b.tenant_id = p.tenant_id
                WHERE p.tenant_id = ?
                  AND b.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                  AND b.expiry_date > CURDATE()
                GROUP BY p.id, p.name
                ORDER BY nearest_expiry_date ASC
            ");
            $productStmt->execute([$tenantId]);
            $products = $productStmt->fetchAll(PDO::FETCH_ASSOC);

            log_cron("  → Found " . count($products) . " product(s) expiring soon");

            // Send alert for each product
            foreach ($products as $product) {
                try {
                    $notificationHandler->sendExpiryAlert($product['id']);
                    log_cron("    ✓ Alert sent for: {$product['name']} (ID: {$product['id']})");
                    $totalAlertsCount++;
                } catch (\Throwable $e) {
                    log_cron("    ✗ Failed for product ID {$product['id']}: {$e->getMessage()}");
                }
            }

        } catch (\Throwable $e) {
            log_cron("  ✗ Error processing tenant: {$e->getMessage()}");
        }
    }

    log_cron("=== Cron Complete ===");
    log_cron("Total alerts sent: $totalAlertsCount");

} catch (\Throwable $e) {
    log_cron("FATAL ERROR: {$e->getMessage()}");
    log_cron("Stack: {$e->getTraceAsString()}");
    exit(1);
}

exit(0);
