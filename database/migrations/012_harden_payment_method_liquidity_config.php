<?php

declare(strict_types=1);

require_once __DIR__ . '/../MigrationDatabase.php';

/**
 * Correct the legacy `other` account_type default and enforce the tenant/kind invariant.
 *
 * `kind` is the canonical payment-method discriminator. This migration repairs rows
 * created by migration 008 whose default `other` value prevented its NULL-only backfill.
 * Wallets remain non-negative until wallet_type and credit_limit are explicitly set.
 */
final class HardenPaymentMethodLiquidityConfig
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? MigrationDatabase::connect($_ENV);
    }

    public function up(): void
    {
        $this->ensureTenantKindUniqueIndex();

        $this->pdo->beginTransaction();
        try {
            $before = (int) $this->pdo->query(
                "SELECT COUNT(*) FROM payment_methods WHERE account_type IS NULL OR account_type = 'other'"
            )->fetchColumn();

            $this->pdo->exec("
                UPDATE payment_methods
                SET account_type = CASE LOWER(kind)
                    WHEN 'cash' THEN 'cash'
                    WHEN 'bank' THEN 'bank'
                    WHEN 'card' THEN 'card'
                    WHEN 'wallet' THEN 'wallet'
                    WHEN 'credit' THEN 'payable'
                    ELSE 'other'
                END
                WHERE account_type IS NULL OR account_type = 'other'
            ");

            $after = (int) $this->pdo->query(
                                "SELECT COUNT(*) FROM payment_methods WHERE (account_type IS NULL OR account_type = 'other')
                AND LOWER(kind) IN ('cash', 'bank', 'card', 'wallet', 'credit')"
            )->fetchColumn();
            if ($after > 0) {
                throw new RuntimeException("{$after} payment method account_type row(s) remain unnormalized.");
            }

            $this->pdo->commit();
            echo "Normalized {$before} legacy payment method configuration row(s).\n";
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function down(): void
    {
        // Deliberately retain normalized configuration and the uniqueness safeguard.
        // Reverting account_type to `other` would make payment behavior less safe.
        echo "No rollback: normalized payment-method configuration is retained.\n";
    }

    private function ensureTenantKindUniqueIndex(): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT non_unique, column_name, seq_in_index
             FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = 'payment_methods'
               AND index_name = 'uq_tenant_kind'
             ORDER BY seq_in_index"
        );
        $stmt->execute();
        $indexRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if ($indexRows !== []) {
            $isExpected = count($indexRows) === 2
                && (int) $indexRows[0]['non_unique'] === 0
                && (int) $indexRows[1]['non_unique'] === 0
                && $indexRows[0]['column_name'] === 'tenant_id'
                && $indexRows[1]['column_name'] === 'kind';
            if (!$isExpected) {
                throw new RuntimeException('uq_tenant_kind exists but does not enforce UNIQUE (tenant_id, kind).');
            }
            return;
        }

        $duplicates = $this->pdo->query(
            "SELECT tenant_id, kind, COUNT(*) AS row_count
             FROM payment_methods
             WHERE tenant_id IS NOT NULL
             GROUP BY tenant_id, kind
             HAVING COUNT(*) > 1
             LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        if ($duplicates) {
            throw new RuntimeException(
                'Cannot add uq_tenant_kind while duplicate tenant payment methods exist '
                . '(tenant_id=' . $duplicates['tenant_id'] . ', kind=' . $duplicates['kind'] . ').'
            );
        }

        $this->pdo->exec(
            'ALTER TABLE payment_methods ADD UNIQUE KEY uq_tenant_kind (tenant_id, kind)'
        );
        echo "Added UNIQUE (tenant_id, kind) payment method index.\n";
    }
}
