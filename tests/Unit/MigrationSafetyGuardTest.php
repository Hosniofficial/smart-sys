<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/database/MigrationSafetyGuard.php';

final class IrreversibleMigrationSafetyFixture
{
    public const REQUIRES_BACKUP_CONFIRMATION = true;
}

final class MigrationSafetyGuardTest extends PHPUnit\Framework\TestCase
{
    public function testMigrationMarkerIdentifiesIrreversibleMigrations(): void
    {
        self::assertTrue(
            MigrationSafetyGuard::requiresBackupConfirmation(IrreversibleMigrationSafetyFixture::class)
        );
        self::assertFalse(
            MigrationSafetyGuard::requiresBackupConfirmation(stdClass::class)
        );
    }

    public function testIrreversibleMigrationsRequireExplicitConfirmationAndBackupReference(): void
    {
        try {
            MigrationSafetyGuard::assertConfirmed(['041_remove_reviewed_unused_columns'], false, '');
            self::fail('Expected missing irreversible migration confirmation to be rejected.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('restorable-backup-id', $error->getMessage());
            self::assertStringContainsString('041_remove_reviewed_unused_columns', $error->getMessage());
        }

        try {
            MigrationSafetyGuard::assertConfirmed(['045_drop_confirmed_unused_tables'], true, '');
            self::fail('Expected a missing backup reference to be rejected.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('backup-reference', $error->getMessage());
        }

        MigrationSafetyGuard::assertConfirmed(
            ['041_remove_reviewed_unused_columns'],
            true,
            'staging-backup-2026-10-27'
        );
        self::assertNull(null);
    }

    public function testReversibleMigrationDoesNotRequireBackupConfirmation(): void
    {
        MigrationSafetyGuard::assertConfirmed([], false, '');
        self::assertNull(null);
    }
}
