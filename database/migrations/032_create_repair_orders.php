<?php

declare(strict_types=1);

final class CreateRepairOrders
{
    public function __construct(private PDO $pdo)
    {
    }

    public function up(): void
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS repair_orders (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    tenant_id INTEGER NOT NULL,
                    branch_id INTEGER NOT NULL,
                    customer_id INTEGER NOT NULL,
                    order_number TEXT NULL,
                    device_type TEXT NOT NULL,
                    brand TEXT NULL,
                    model TEXT NULL,
                    serial_number TEXT NULL,
                    accessories TEXT NULL,
                    issue_description TEXT NOT NULL,
                    condition_received TEXT NULL,
                    diagnosis TEXT NULL,
                    resolution TEXT NULL,
                    estimated_cost NUMERIC NOT NULL DEFAULT 0,
                    labor_product_id INTEGER NULL,
                    labor_amount NUMERIC NOT NULL DEFAULT 0,
                    expected_completion_date TEXT NULL,
                    status TEXT NOT NULL DEFAULT 'received',
                    invoice_id INTEGER NULL,
                    received_by INTEGER NULL,
                    delivered_by INTEGER NULL,
                    received_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    delivered_at TEXT NULL,
                    notes TEXT NULL,
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                )
            ");
            $this->pdo->exec(
                'CREATE UNIQUE INDEX IF NOT EXISTS uq_repair_order_tenant_number ON repair_orders (tenant_id, order_number)'
            );
            $this->pdo->exec(
                'CREATE INDEX IF NOT EXISTS idx_repair_orders_tenant_status ON repair_orders (tenant_id, status)'
            );
            $this->pdo->exec(
                'CREATE INDEX IF NOT EXISTS idx_repair_orders_tenant_branch ON repair_orders (tenant_id, branch_id)'
            );
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS repair_order_parts (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    tenant_id INTEGER NOT NULL,
                    repair_order_id INTEGER NOT NULL,
                    product_id INTEGER NOT NULL,
                    unit_id INTEGER NOT NULL,
                    quantity NUMERIC NOT NULL,
                    sale_price NUMERIC NOT NULL,
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (repair_order_id) REFERENCES repair_orders (id) ON DELETE CASCADE
                )
            ");
            $this->pdo->exec(
                'CREATE INDEX IF NOT EXISTS idx_repair_parts_order ON repair_order_parts (tenant_id, repair_order_id)'
            );
            return;
        }

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS repair_orders (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT NOT NULL,
                branch_id INT NOT NULL,
                customer_id INT NOT NULL,
                order_number VARCHAR(40) NULL,
                device_type VARCHAR(100) NOT NULL,
                brand VARCHAR(120) NULL,
                model VARCHAR(120) NULL,
                serial_number VARCHAR(120) NULL,
                accessories TEXT NULL,
                issue_description TEXT NOT NULL,
                condition_received TEXT NULL,
                diagnosis TEXT NULL,
                resolution TEXT NULL,
                estimated_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
                labor_product_id INT NULL,
                labor_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
                expected_completion_date DATE NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'received',
                invoice_id INT NULL,
                received_by INT NULL,
                delivered_by INT NULL,
                received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                delivered_at TIMESTAMP NULL,
                notes TEXT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_repair_order_tenant_number (tenant_id, order_number),
                KEY idx_repair_orders_tenant_status (tenant_id, status),
                KEY idx_repair_orders_tenant_branch (tenant_id, branch_id),
                KEY idx_repair_orders_customer (tenant_id, customer_id),
                KEY idx_repair_orders_invoice (tenant_id, invoice_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS repair_order_parts (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT NOT NULL,
                repair_order_id BIGINT UNSIGNED NOT NULL,
                product_id INT NOT NULL,
                unit_id INT NOT NULL,
                quantity DECIMAL(16,4) NOT NULL,
                sale_price DECIMAL(15,4) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_repair_parts_order (tenant_id, repair_order_id),
                CONSTRAINT fk_repair_parts_order FOREIGN KEY (repair_order_id)
                    REFERENCES repair_orders (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS repair_order_parts');
        $this->pdo->exec('DROP TABLE IF EXISTS repair_orders');
    }
}
