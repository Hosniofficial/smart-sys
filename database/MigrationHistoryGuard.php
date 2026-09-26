<?php

declare(strict_types=1);

/**
 * Fail-closed checks for the migration ledger.
 *
 * The runner may bootstrap an empty database, or continue a contiguous ledger.
 * It must not infer that migrations ran merely because their schema objects exist:
 * adopting an existing database requires an explicit, reviewed baseline operation.
 */
final class MigrationHistoryGuard
{
    /**
     * Refuse to create a new ledger beside an existing application schema.
     *
     * @param list<string> $existingTables All database tables except the absent ledger.
     */
    public static function assertFreshDatabase(array $existingTables): void
    {
        $existingTables = array_values(array_filter(
            $existingTables,
            static fn (string $table): bool => strtolower($table) !== 'migrations'
        ));

        if ($existingTables !== []) {
            throw new RuntimeException(
                'Migration history is missing while database tables already exist. '
                . 'Refusing to run migrations or infer a baseline; reconcile the ledger explicitly first.'
            );
        }
    }

    /**
     * Validate an explicitly operator-confirmed legacy baseline adoption.
     * This only supports the confirmed historical prefix 001-003; it never infers history.
     *
     * @param list<string> $availableMigrations
     * @param list<string> $existingTables
     * @return list<string> The prefix to record as adopted with batch=0.
     */
    public static function assertLegacyBaselineAdoption(
        array $availableMigrations,
        string $throughMigration,
        string $confirmation,
        bool $ledgerExists,
        array $existingTables
    ): array {
        $confirmedPrefix = array_slice($availableMigrations, 0, 3);
        $requiredTables = ['permissions', 'roles', 'role_permissions'];
        $normalizedTables = array_map('strtolower', $existingTables);
        $missingTables = array_diff($requiredTables, $normalizedTables);

        if ($throughMigration !== ($confirmedPrefix[2] ?? null)
            || $confirmation !== '--confirm-applied-001-003'
            || $ledgerExists
            || $missingTables !== []) {
            throw new RuntimeException(
                'Legacy baseline adoption refused: require an absent ledger, core RBAC tables, and explicit confirmation that 001-003 were applied.'
            );
        }

        return $confirmedPrefix;
    }

    /**
     * Require applied migrations to be an ordered prefix of the configured list.
     *
     * @param list<string> $availableMigrations Ordered migration filenames without extension.
     * @param list<string> $executedMigrations  Ledger rows ordered by migration id.
     */
    public static function assertContiguousHistory(array $availableMigrations, array $executedMigrations): void
    {
        if (count($executedMigrations) !== count(array_unique($executedMigrations))) {
            throw new RuntimeException('Migration ledger contains duplicate entries; refusing to continue.');
        }

        $expectedPrefix = array_slice($availableMigrations, 0, count($executedMigrations));
        if ($executedMigrations !== $expectedPrefix) {
            throw new RuntimeException(
                'Migration ledger is not a contiguous prefix of the configured migration order. '
                . 'Refusing to run or roll back migrations until the history is reconciled.'
            );
        }
    }
}
