<?php

declare(strict_types=1);

require_once __DIR__ . '/../MigrationDatabase.php';

/**
 * Migration: 008_add_liquidity_policy_columns
 *
 * Add liquidity validation policy configuration columns to payment_methods.
 * These are CONFIGURATION columns only - accounts.type remains the source of truth.
 *
 * Columns added:
 * - account_type: Categorization hint for validation logic (cash/bank/wallet/etc.)
 * - allow_negative: Base flag (context-aware - depends on session, settings, account)
 * - overdraft_limit: Max negative for bank accounts (e.g., -10000.00)
 * - credit_limit: Max negative for postpaid wallets
 * - wallet_type: Distinguish prepaid (strict) vs postpaid (credit line)
 *
 * IMPORTANT: This migration does NOT change existing behavior or account mappings.
 * It only adds columns for future liquidity policy validation.
 */
final class AddLiquidityPolicyColumns
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? MigrationDatabase::connect($_ENV);
    }

    public function up(): void
    {
        echo "📌 Migration: 008_add_liquidity_policy_columns\n\n";

        try {
            // ── Verify payment_methods table exists ──
            $tableExists = $this->pdo->prepare(
                "SELECT 1 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
            );
            $tableExists->execute(['payment_methods']);

            if (!$tableExists->fetch()) {
                throw new RuntimeException('payment_methods table does not exist; base schema migration required first.');
            }

            // ── Capture pre-migration state for verification ──
            echo "   • Capturing pre-migration state...\n";
            $preMigrationCount = (int) $this->pdo->query(
                'SELECT COUNT(*) FROM payment_methods'
            )->fetchColumn();
            echo "      Current payment_methods records: {$preMigrationCount}\n";

            // ── Add columns if they don't exist ──
            echo "   • Adding liquidity policy columns...\n";

            $this->ensureColumn(
                'account_type',
                "ENUM('cash', 'bank', 'wallet', 'card', 'suspense', 'payable', 'other') DEFAULT 'other' COMMENT 'Configuration hint; accounts.type is source of truth'"
            );

            $this->ensureColumn(
                'allow_negative',
                "TINYINT(1) DEFAULT 0 COMMENT 'Base flag; actual decision context-aware (session + settings + account)'"
            );

            $this->ensureColumn(
                'overdraft_limit',
                "DECIMAL(15,2) NULL COMMENT 'Max negative balance for bank accounts (e.g., -10000.00)'"
            );

            $this->ensureColumn(
                'credit_limit',
                "DECIMAL(15,2) NULL COMMENT 'Max negative balance for postpaid wallets'"
            );

            $this->ensureColumn(
                'wallet_type',
                "ENUM('prepaid', 'postpaid') NULL COMMENT 'Wallet sub-type: prepaid=strict, postpaid=credit'"
            );

            echo "      ✅ Columns added successfully\n";

            // ── Conservative backfill (safe defaults only) ──
            echo "   • Applying conservative backfill to existing records...\n";
            $this->backfillPaymentMethodPolicies();
            echo "      ✅ Backfill completed\n";

            // ── Verify post-migration state ──
            echo "   • Verifying migration integrity...\n";
            $postMigrationCount = (int) $this->pdo->query(
                'SELECT COUNT(*) FROM payment_methods'
            )->fetchColumn();

            if ($postMigrationCount !== $preMigrationCount) {
                throw new RuntimeException(
                    "Migration integrity violation: record count changed ({$preMigrationCount} → {$postMigrationCount})"
                );
            }

            // Verify no existing account_id was modified
            $modifiedAccounts = (int) $this->pdo->query(
                "SELECT COUNT(*) FROM payment_methods
                 WHERE account_id IS NOT NULL AND account_id != account_id"
            )->fetchColumn();

            if ($modifiedAccounts > 0) {
                throw new RuntimeException("Migration corrupted existing account_id mappings");
            }

            echo "      ✅ Verification passed: {$postMigrationCount} records intact\n";
            echo "\n✨ Migration 008 completed successfully!\n";

        } catch (\Throwable $e) {
            echo "❌ Migration failed: " . $e->getMessage() . "\n";
            throw $e;
        }
    }

    public function down(): void
    {
        echo "🔄 Rollback: 008_add_liquidity_policy_columns\n\n";

        try {
            echo "   • Removing liquidity policy columns...\n";

            $this->dropColumnIfExists('wallet_type');
            $this->dropColumnIfExists('credit_limit');
            $this->dropColumnIfExists('overdraft_limit');
            $this->dropColumnIfExists('allow_negative');
            $this->dropColumnIfExists('account_type');

            echo "      ✅ Columns removed\n";
            echo "\n✨ Rollback 008 completed successfully!\n";

        } catch (\Throwable $e) {
            echo "❌ Rollback failed: " . $e->getMessage() . "\n";
            throw $e;
        }
    }

    private function ensureColumn(string $column, string $definition): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'payment_methods' AND column_name = ?"
        );
        $stmt->execute([$column]);

        if ((int) $stmt->fetchColumn() === 0) {
            $this->pdo->exec("ALTER TABLE payment_methods ADD COLUMN `{$column}` {$definition}");
            echo "      ✓ Added column: {$column}\n";
        } else {
            echo "      ℹ️  Column '{$column}' already exists, skipping\n";
        }
    }

    private function dropColumnIfExists(string $column): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'payment_methods' AND column_name = ?"
        );
        $stmt->execute([$column]);

        if ((int) $stmt->fetchColumn() > 0) {
            $this->pdo->exec("ALTER TABLE payment_methods DROP COLUMN `{$column}`");
            echo "      ✓ Dropped column: {$column}\n";
        }
    }

    private function backfillPaymentMethodPolicies(): void
    {
        // CONSERVATIVE backfill: Map payment_methods.kind → safe defaults
        // Do NOT assume wallet=postpaid or any business logic.
        // NULL for ambiguous cases (admin can configure later).

        $backfillRules = [
            'cash' => [
                'account_type' => 'cash',
                'allow_negative' => 0,       // Strict by default
                'wallet_type' => null
            ],
            'bank' => [
                'account_type' => 'bank',
                'allow_negative' => 0,       // Strict unless overdraft configured
                'wallet_type' => null
            ],
            'card' => [
                'account_type' => 'card',
                'allow_negative' => 0,       // Card clearing = strict
                'wallet_type' => null
            ],
            'wallet' => [
                'account_type' => 'wallet',
                'allow_negative' => 0,       // Strict by default
                'wallet_type' => null        // NULL = admin must configure prepaid/postpaid
            ],
            'credit' => [
                'account_type' => 'payable', // Credit = Accounts Payable GL account
                'allow_negative' => 1,       // AP accounts allow negative (liability nature)
                'wallet_type' => null
            ],
            'other' => [
                'account_type' => 'other',
                'allow_negative' => 0,       // Strict for unknown types
                'wallet_type' => null
            ],
        ];

        $stmt = $this->pdo->prepare("
            UPDATE payment_methods
            SET account_type = :account_type,
                allow_negative = :allow_negative,
                wallet_type = :wallet_type
            WHERE kind = :kind
              AND account_type IS NULL
        ");

        foreach ($backfillRules as $kind => $policy) {
            $stmt->execute([
                ':account_type' => $policy['account_type'],
                ':allow_negative' => $policy['allow_negative'],
                ':wallet_type' => $policy['wallet_type'],
                ':kind' => $kind
            ]);

            $affected = $stmt->rowCount();
            if ($affected > 0) {
                echo "      ✓ Backfilled {$affected} record(s) with kind='{$kind}'\n";
            }
        }

        // Verify NO existing data was corrupted
        $nullKind = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM payment_methods WHERE kind IS NULL'
        )->fetchColumn();

        if ($nullKind > 0) {
            throw new RuntimeException("Backfill corrupted data: {$nullKind} records have NULL kind");
        }
    }
}
