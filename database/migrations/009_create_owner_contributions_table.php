<?php

declare(strict_types=1);

require_once __DIR__ . '/../MigrationDatabase.php';

/**
 * Migration: 009_create_owner_contributions_table
 *
 * Create table to track Owner Capital, Owner Loan, and Opening Balance contributions.
 *
 * Key Features:
 * - Idempotency via request deduplication (not business uniqueness)
 * - Reversible void via journal entry reversal
 * - Full audit trail linking to journal_entries
 * - Support for loan terms (interest_rate, due_date, repayment_schedule)
 *
 * Important: idempotency_key is for preventing duplicate API requests,
 * NOT for enforcing "one capital contribution per tenant" business rule.
 */
final class CreateOwnerContributionsTable
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? MigrationDatabase::connect($_ENV);
    }

    public function up(): void
    {
        echo "📌 Migration: 009_create_owner_contributions_table\n\n";

        try {
            // ── Check if table already exists ──
            $tableExists = $this->pdo->prepare(
                "SELECT 1 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
            );
            $tableExists->execute(['owner_contributions']);

            if ($tableExists->fetch()) {
                echo "   ℹ️  Table 'owner_contributions' already exists, skipping creation\n";
                return;
            }

            // ── Verify prerequisite tables exist ──
            echo "   • Verifying prerequisite tables...\n";
            $requiredTables = ['accounts', 'tenants', 'users', 'journal_entries'];
            foreach ($requiredTables as $table) {
                $stmt = $this->pdo->prepare(
                    "SELECT 1 FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
                );
                $stmt->execute([$table]);
                if (!$stmt->fetch()) {
                    throw new RuntimeException("Required table '{$table}' does not exist");
                }
            }
            echo "      ✅ All prerequisite tables exist\n";

            // ── Create owner_contributions table ──
            echo "   • Creating owner_contributions table...\n";

            $this->pdo->exec("
                CREATE TABLE `owner_contributions` (
                  `id` INT(11) NOT NULL AUTO_INCREMENT,
                  `tenant_id` INT(11) NOT NULL,
                  `contribution_type` ENUM('capital', 'owner_loan', 'opening_balance') NOT NULL
                    COMMENT 'Type of funding: capital=permanent equity, owner_loan=liability, opening_balance=pre-system reconciliation',
                  `amount` DECIMAL(15,2) NOT NULL
                    COMMENT 'Contribution amount (always positive)',
                  `target_account_id` INT(11) NOT NULL
                    COMMENT 'Liquidity GL account receiving funds (Cash/Bank/Wallet)',
                  `related_gl_account_id` INT(11) NOT NULL
                    COMMENT 'Equity/Liability GL account (Capital/Due to Owner/Opening Equity)',

                  -- Loan-specific fields (NULL for capital/opening_balance)
                  `interest_rate` DECIMAL(5,2) NULL
                    COMMENT 'Annual interest rate % for owner_loan (e.g., 5.00 = 5%)',
                  `due_date` DATE NULL
                    COMMENT 'Repayment due date for owner_loan',
                  `repayment_terms` VARCHAR(255) NULL
                    COMMENT 'Human-readable repayment terms (e.g., \"12 monthly installments\")',
                  `repayment_schedule` ENUM('monthly', 'quarterly', 'at_due_date') NULL
                    COMMENT 'Repayment frequency (for future automation)',

                  -- GL linkage and idempotency
                  `journal_entry_id` INT(11) UNIQUE
                    COMMENT 'FK to posted journal entry (UNIQUE = one contribution per JE)',
                  `idempotency_key` VARCHAR(255) NULL
                    COMMENT 'Request deduplication key (prevents duplicate API submissions)',

                  -- Status and lifecycle
                  `status` ENUM('recorded', 'settled', 'voided') DEFAULT 'recorded'
                    COMMENT 'recorded=active, settled=repaid (for loans), voided=reversed',
                  `created_by_user_id` INT(11) NOT NULL,
                  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

                  -- Void/reversal tracking
                  `voided_at` TIMESTAMP NULL,
                  `voided_by_user_id` INT(11) NULL,
                  `reversal_je_id` INT(11) NULL
                    COMMENT 'FK to reversal journal entry if voided',

                  PRIMARY KEY (`id`),

                  -- Indexes
                  UNIQUE KEY `uk_oc_idempotency` (`tenant_id`, `idempotency_key`),
                  KEY `idx_oc_tenant` (`tenant_id`),
                  KEY `idx_oc_je` (`journal_entry_id`),
                  KEY `idx_oc_status` (`status`),
                  KEY `idx_oc_type` (`contribution_type`),
                  KEY `idx_oc_created` (`created_at`),

                  -- Foreign Keys (matching existing table types: INT(11))
                  CONSTRAINT `fk_oc_target_account`
                    FOREIGN KEY (`target_account_id`)
                    REFERENCES `accounts` (`id`)
                    ON DELETE RESTRICT ON UPDATE CASCADE,

                  CONSTRAINT `fk_oc_gl_account`
                    FOREIGN KEY (`related_gl_account_id`)
                    REFERENCES `accounts` (`id`)
                    ON DELETE RESTRICT ON UPDATE CASCADE,

                  CONSTRAINT `fk_oc_tenant`
                    FOREIGN KEY (`tenant_id`)
                    REFERENCES `tenants` (`id`)
                    ON DELETE CASCADE,

                  CONSTRAINT `fk_oc_je`
                    FOREIGN KEY (`journal_entry_id`)
                    REFERENCES `journal_entries` (`id`)
                    ON DELETE RESTRICT ON UPDATE CASCADE,

                  CONSTRAINT `fk_oc_created_by`
                    FOREIGN KEY (`created_by_user_id`)
                    REFERENCES `users` (`id`)
                    ON DELETE RESTRICT,

                  CONSTRAINT `fk_oc_voided_by`
                    FOREIGN KEY (`voided_by_user_id`)
                    REFERENCES `users` (`id`)
                    ON DELETE RESTRICT,

                  CONSTRAINT `fk_oc_reversal_je`
                    FOREIGN KEY (`reversal_je_id`)
                    REFERENCES `journal_entries` (`id`)
                    ON DELETE RESTRICT ON UPDATE CASCADE

                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                COMMENT='Owner contributions: Capital/Loan/Opening Balance with full GL linkage and void support'
            ");

            echo "      ✅ Table created successfully\n";

            // ── Verify table structure ──
            echo "   • Verifying table structure...\n";
            $columnCount = (int) $this->pdo->query(
                "SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'owner_contributions'"
            )->fetchColumn();

            if ($columnCount < 15) {
                throw new RuntimeException("Table creation incomplete: expected 15+ columns, found {$columnCount}");
            }

            echo "      ✅ Verification passed: {$columnCount} columns created\n";
            echo "\n✨ Migration 009 completed successfully!\n";

        } catch (\Throwable $e) {
            echo "❌ Migration failed: " . $e->getMessage() . "\n";
            throw $e;
        }
    }

    public function down(): void
    {
        echo "🔄 Rollback: 009_create_owner_contributions_table\n\n";

        try {
            // ── Check for existing records ──
            $tableExists = $this->pdo->prepare(
                "SELECT 1 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
            );
            $tableExists->execute(['owner_contributions']);

            if (!$tableExists->fetch()) {
                echo "   ℹ️  Table 'owner_contributions' does not exist, nothing to rollback\n";
                return;
            }

            $recordCount = (int) $this->pdo->query(
                'SELECT COUNT(*) FROM owner_contributions'
            )->fetchColumn();

            if ($recordCount > 0) {
                throw new RuntimeException(
                    "Cannot drop owner_contributions table: {$recordCount} records exist. " .
                    "Manual data cleanup required before rollback."
                );
            }

            // ── Drop table ──
            echo "   • Dropping owner_contributions table...\n";
            $this->pdo->exec('DROP TABLE IF EXISTS `owner_contributions`');
            echo "      ✅ Table dropped\n";

            echo "\n✨ Rollback 009 completed successfully!\n";

        } catch (\Throwable $e) {
            echo "❌ Rollback failed: " . $e->getMessage() . "\n";
            throw $e;
        }
    }
}
