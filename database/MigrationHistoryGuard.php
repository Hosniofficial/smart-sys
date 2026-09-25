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
