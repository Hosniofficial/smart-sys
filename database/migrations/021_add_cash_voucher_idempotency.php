<?php

declare(strict_types=1);

/**
 * Enforce tenant-scoped idempotency for cash voucher creation.
 */
final class AddCashVoucherIdempotency
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function up(): void
    {
        $this->ensureColumn('idempotency_key', 'VARCHAR(255) NULL');
        $this->ensureColumn('request_hash', 'CHAR(64) NULL');

        $duplicateCount = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM (
                SELECT tenant_id, idempotency_key
                FROM cash_vouchers
                WHERE idempotency_key IS NOT NULL
                GROUP BY tenant_id, idempotency_key
                HAVING COUNT(*) > 1
            ) duplicate_voucher_keys"
        )->fetchColumn();
        if ($duplicateCount > 0) {
            throw new RuntimeException(
                'Cannot add cash voucher idempotency index: duplicate tenant/key values already exist.'
            );
        }

        if ($this->indexExists('uq_cash_vouchers_tenant_idempotency_key')) {
            return;
        }

        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $this->pdo->exec(
                'CREATE UNIQUE INDEX uq_cash_vouchers_tenant_idempotency_key
                 ON cash_vouchers (tenant_id, idempotency_key)'
            );
        } else {
            $this->pdo->exec(
                'ALTER TABLE cash_vouchers
                 ADD UNIQUE INDEX uq_cash_vouchers_tenant_idempotency_key (tenant_id, idempotency_key)'
            );
        }
    }

    public function down(): void
    {
        $usedKeys = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM cash_vouchers WHERE idempotency_key IS NOT NULL'
        )->fetchColumn();
        if ($usedKeys > 0) {
            throw new RuntimeException(
                'Cannot remove cash voucher idempotency support after keys have been used; restore a reviewed backup instead.'
            );
        }

        if ($this->indexExists('uq_cash_vouchers_tenant_idempotency_key')) {
            if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $this->pdo->exec('DROP INDEX uq_cash_vouchers_tenant_idempotency_key');
            } else {
                $this->pdo->exec('DROP INDEX uq_cash_vouchers_tenant_idempotency_key ON cash_vouchers');
            }
        }

        if ($this->columnExists('request_hash')) {
            if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
                $this->pdo->exec('ALTER TABLE cash_vouchers DROP COLUMN request_hash');
            }
        }
    }

    private function ensureColumn(string $column, string $definition): void
    {
        if (!$this->columnExists($column)) {
            $this->pdo->exec("ALTER TABLE cash_vouchers ADD COLUMN `{$column}` {$definition}");
        }
    }

    private function columnExists(string $column): bool
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            foreach ($this->pdo->query('PRAGMA table_info(cash_vouchers)')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ($row['name'] === $column) {
                    return true;
                }
            }
            return false;
        }

        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'cash_vouchers' AND column_name = ?"
        );
        $stmt->execute([$column]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function indexExists(string $index): bool
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            foreach ($this->pdo->query('PRAGMA index_list(cash_vouchers)')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ($row['name'] === $index) {
                    return true;
                }
            }
            return false;
        }

        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = 'cash_vouchers' AND index_name = ?"
        );
        $stmt->execute([$index]);
        return (int) $stmt->fetchColumn() > 0;
    }
}
