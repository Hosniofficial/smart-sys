<?php

declare(strict_types=1);

final class CreateSalesQuotations
{
    public function __construct(private PDO $pdo)
    {
    }

    public function up(): void
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS sales_quotations (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    tenant_id INTEGER NOT NULL,
                    branch_id INTEGER NOT NULL,
                    customer_id INTEGER NULL,
                    quote_number TEXT NULL,
                    status TEXT NOT NULL DEFAULT 'active',
                    valid_until TEXT NOT NULL,
                    items_json TEXT NOT NULL,
                    discount_type TEXT NOT NULL DEFAULT 'fixed',
                    discount_value NUMERIC NOT NULL DEFAULT 0,
                    subtotal NUMERIC NOT NULL DEFAULT 0,
                    discount_amount NUMERIC NOT NULL DEFAULT 0,
                    tax_rate NUMERIC NOT NULL DEFAULT 0,
                    tax_amount NUMERIC NOT NULL DEFAULT 0,
                    total_amount NUMERIC NOT NULL DEFAULT 0,
                    currency_code TEXT NOT NULL DEFAULT 'EGP',
                    notes TEXT NULL,
                    created_by INTEGER NOT NULL,
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                )
            ");
            $this->pdo->exec(
                'CREATE UNIQUE INDEX IF NOT EXISTS uq_sales_quotations_tenant_number ON sales_quotations (tenant_id, quote_number)'
            );
            $this->pdo->exec(
                'CREATE INDEX IF NOT EXISTS idx_sales_quotations_tenant_branch_status ON sales_quotations (tenant_id, branch_id, status, valid_until)'
            );
            $this->pdo->exec(
                'CREATE INDEX IF NOT EXISTS idx_sales_quotations_tenant_customer ON sales_quotations (tenant_id, customer_id)'
            );
            return;
        }

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS sales_quotations (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT NOT NULL,
                branch_id INT NOT NULL,
                customer_id INT NULL,
                quote_number VARCHAR(40) NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                valid_until DATE NOT NULL,
                items_json JSON NOT NULL,
                discount_type VARCHAR(16) NOT NULL DEFAULT 'fixed',
                discount_value DECIMAL(15,4) NOT NULL DEFAULT 0,
                subtotal DECIMAL(15,2) NOT NULL DEFAULT 0,
                discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
                tax_rate DECIMAL(7,4) NOT NULL DEFAULT 0,
                tax_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
                total_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
                currency_code CHAR(3) NOT NULL DEFAULT 'EGP',
                notes TEXT NULL,
                created_by INT NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_sales_quotations_tenant_number (tenant_id, quote_number),
                KEY idx_sales_quotations_tenant_branch_status (tenant_id, branch_id, status, valid_until),
                KEY idx_sales_quotations_tenant_customer (tenant_id, customer_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS sales_quotations');
    }
}
