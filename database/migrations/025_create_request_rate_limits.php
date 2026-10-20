<?php

declare(strict_types=1);

/** Store request rate-limit windows used by RequestRateLimiter middleware. */
final class CreateRequestRateLimits
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function up(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS rate_limits (
                id INT NOT NULL AUTO_INCREMENT,
                ip VARCHAR(64) NOT NULL,
                route VARCHAR(128) NOT NULL,
                window_start DATETIME NOT NULL,
                counter INT NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                KEY idx_ip_route (ip, route),
                KEY idx_window (window_start)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public function down(): void
    {
        echo "No rollback: rate-limit history is retained.\n";
    }
}
