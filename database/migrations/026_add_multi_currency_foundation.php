<?php

declare(strict_types=1);

/** Store tenant exchange rates and immutable transaction currency snapshots. */
final class AddMultiCurrencyFoundation
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function up(): void
    {
        $this->createExchangeRateTable();

        foreach (['sales', 'purchases', 'returns', 'payments', 'cash_vouchers'] as $table) {
            $this->addColumnIfMissing($table, 'transaction_currency_code', 'CHAR(3) NULL');
            $this->addColumnIfMissing($table, 'base_currency_code', 'CHAR(3) NULL');
            $this->addColumnIfMissing($table, 'exchange_rate', 'DECIMAL(24,12) NULL');
            $this->addColumnIfMissing($table, 'base_amount', 'DECIMAL(18,4) NULL');
            $this->addColumnIfMissing($table, 'foreign_total_amount', 'DECIMAL(18,4) NULL');
        }
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Migration 026 is forward-only; exchange-rate history and transaction currency snapshots must be retained.'
        );
    }

    private function createExchangeRateTable(): void
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS currency_exchange_rates (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    tenant_id INTEGER NOT NULL,
                    base_currency_code CHAR(3) NOT NULL,
                    quote_currency_code CHAR(3) NOT NULL,
                    rate NUMERIC NOT NULL,
                    provider VARCHAR(64) NOT NULL,
                    rate_date TEXT NOT NULL,
                    fetched_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE (tenant_id, base_currency_code, quote_currency_code, rate_date)
                )'
            );
            $this->pdo->exec(
                'CREATE INDEX IF NOT EXISTS idx_currency_exchange_rates_lookup
                 ON currency_exchange_rates (tenant_id, base_currency_code, quote_currency_code, rate_date)'
            );
            return;
        }

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS currency_exchange_rates (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                tenant_id INT(11) NOT NULL,
                base_currency_code CHAR(3) NOT NULL,
                quote_currency_code CHAR(3) NOT NULL,
                rate DECIMAL(24,12) NOT NULL,
                provider VARCHAR(64) NOT NULL,
                rate_date DATE NOT NULL,
                fetched_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_currency_exchange_rate_day (
                    tenant_id, base_currency_code, quote_currency_code, rate_date
                ),
                KEY idx_currency_exchange_rates_lookup (
                    tenant_id, base_currency_code, quote_currency_code, rate_date
                ),
                CONSTRAINT fk_currency_exchange_rates_tenant
                    FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private function addColumnIfMissing(string $table, string $column, string $definition): void
    {
        if ($this->columnExists($table, $column)) {
            return;
        }

        $this->pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    }

    private function columnExists(string $table, string $column): bool
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            foreach ($this->pdo->query("PRAGMA table_info(`{$table}`)")->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (($row['name'] ?? null) === $column) {
                    return true;
                }
            }
            return false;
        }

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
        );
        $stmt->execute([$table, $column]);
        return (int) $stmt->fetchColumn() > 0;
    }
}
