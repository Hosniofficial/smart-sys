<?php

declare(strict_types=1);

final class CreateSalesPriceCampaigns
{
    public function __construct(private PDO $pdo)
    {
    }

    public function up(): void
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS sales_price_campaigns (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    tenant_id INTEGER NOT NULL,
                    branch_id INTEGER NULL,
                    name TEXT NOT NULL,
                    campaign_type TEXT NOT NULL DEFAULT 'custom',
                    start_date TEXT NOT NULL,
                    end_date TEXT NOT NULL,
                    priority INTEGER NOT NULL DEFAULT 0,
                    status TEXT NOT NULL DEFAULT 'active',
                    created_by INTEGER NOT NULL,
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                )
            ");
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS sales_price_campaign_items (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    tenant_id INTEGER NOT NULL,
                    campaign_id INTEGER NOT NULL,
                    product_id INTEGER NOT NULL,
                    unit_id INTEGER NOT NULL,
                    campaign_price NUMERIC NOT NULL,
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE (tenant_id, campaign_id, product_id, unit_id),
                    FOREIGN KEY (campaign_id) REFERENCES sales_price_campaigns (id) ON DELETE CASCADE
                )
            ");
            $this->pdo->exec(
                'CREATE INDEX IF NOT EXISTS idx_price_campaign_active_dates ON sales_price_campaigns (tenant_id, status, start_date, end_date, branch_id, priority)'
            );
            $this->pdo->exec(
                'CREATE INDEX IF NOT EXISTS idx_price_campaign_items_product ON sales_price_campaign_items (tenant_id, product_id, unit_id)'
            );
            return;
        }

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS sales_price_campaigns (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT NOT NULL,
                branch_id INT NULL,
                name VARCHAR(160) NOT NULL,
                campaign_type VARCHAR(24) NOT NULL DEFAULT 'custom',
                start_date DATE NOT NULL,
                end_date DATE NOT NULL,
                priority SMALLINT NOT NULL DEFAULT 0,
                status VARCHAR(16) NOT NULL DEFAULT 'active',
                created_by INT NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_price_campaign_active_dates (tenant_id, status, start_date, end_date, branch_id, priority)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS sales_price_campaign_items (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT NOT NULL,
                campaign_id BIGINT UNSIGNED NOT NULL,
                product_id INT NOT NULL,
                unit_id INT NOT NULL,
                campaign_price DECIMAL(15,4) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_price_campaign_item (tenant_id, campaign_id, product_id, unit_id),
                KEY idx_price_campaign_items_product (tenant_id, product_id, unit_id),
                CONSTRAINT fk_price_campaign_item_campaign
                    FOREIGN KEY (campaign_id) REFERENCES sales_price_campaigns (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS sales_price_campaign_items');
        $this->pdo->exec('DROP TABLE IF EXISTS sales_price_campaigns');
    }
}
