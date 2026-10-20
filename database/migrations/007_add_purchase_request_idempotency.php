<?php

declare(strict_types=1);

/**
 * Persist purchase-create idempotency keys on the purchase row itself.
 * The unique tenant/key index serializes concurrent retries, while the request
 * hash prevents accidental reuse of one key for a different purchase payload.
 */
final class AddPurchaseRequestIdempotency
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function up(): void
    {
        $this->ensureColumn('idempotency_key', 'VARCHAR(128) NULL');
        $this->ensureColumn('request_hash', 'CHAR(64) NULL');

        $duplicateCount = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM (
                SELECT tenant_id, idempotency_key
                FROM purchases
                WHERE idempotency_key IS NOT NULL
                GROUP BY tenant_id, idempotency_key
                HAVING COUNT(*) > 1
            ) duplicate_purchase_keys"
        )->fetchColumn();
        if ($duplicateCount > 0) {
            throw new RuntimeException('Cannot add purchase idempotency index: duplicate tenant/key values already exist.');
        }

        $indexStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = 'purchases'
               AND index_name = 'uq_purchases_tenant_idempotency_key'"
        );
        $indexStmt->execute();
        if ((int) $indexStmt->fetchColumn() === 0) {
            $this->pdo->exec(
                'ALTER TABLE purchases ADD UNIQUE INDEX uq_purchases_tenant_idempotency_key (tenant_id, idempotency_key)'
            );
        }
    }

    public function down(): void
    {
        $usedKeys = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM purchases WHERE idempotency_key IS NOT NULL'
        )->fetchColumn();
        if ($usedKeys > 0) {
            throw new RuntimeException(
                'Cannot remove purchase idempotency data after keys have been used; restore a reviewed backup instead.'
            );
        }

        $indexStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = 'purchases'
               AND index_name = 'uq_purchases_tenant_idempotency_key'"
        );
        $indexStmt->execute();
        if ((int) $indexStmt->fetchColumn() > 0) {
            $this->pdo->exec('ALTER TABLE purchases DROP INDEX uq_purchases_tenant_idempotency_key');
        }

        $this->dropColumnIfExists('request_hash');
        $this->dropColumnIfExists('idempotency_key');
    }

    private function ensureColumn(string $column, string $definition): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'purchases' AND column_name = ?"
        );
        $stmt->execute([$column]);
        if ((int) $stmt->fetchColumn() === 0) {
            $this->pdo->exec("ALTER TABLE purchases ADD COLUMN `{$column}` {$definition}");
        }
    }

    private function dropColumnIfExists(string $column): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'purchases' AND column_name = ?"
        );
        $stmt->execute([$column]);
        if ((int) $stmt->fetchColumn() > 0) {
            $this->pdo->exec("ALTER TABLE purchases DROP COLUMN `{$column}`");
        }
    }
}
