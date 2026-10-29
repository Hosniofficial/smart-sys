<?php

declare(strict_types=1);

final class MigrationSafetyGuard
{
    /**
     * @param list<string> $irreversibleMigrations
     */
    public static function assertConfirmed(
        array $irreversibleMigrations,
        bool $confirmed,
        string $backupReference
    ): void {
        if ($irreversibleMigrations === []) {
            return;
        }

        if (!$confirmed || trim($backupReference) === '') {
            throw new RuntimeException(
                'Pending irreversible migrations require --confirm-irreversible and '
                . '--backup-reference=<restorable-backup-id> after verifying the backup can be restored. '
                . 'Pending: ' . implode(', ', $irreversibleMigrations)
            );
        }
    }

    public static function requiresBackupConfirmation(string $className): bool
    {
        return defined($className . '::REQUIRES_BACKUP_CONFIRMATION')
            && constant($className . '::REQUIRES_BACKUP_CONFIRMATION') === true;
    }
}
