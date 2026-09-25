<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/database/MigrationHistoryGuard.php';

use PHPUnit\Framework\TestCase;

final class MigrationHistoryGuardTest extends TestCase
{
    private const MIGRATIONS = [
        '001_create_system_configuration',
        '002_create_roles_and_permissions',
        '003_insert_core_data',
        '004_add_accounting_period_close_override',
        '005_allow_optional_opening_snapshot_mapping',
    ];

    public function testAllowsAnEmptyDatabaseToBootstrapItsLedger(): void
    {
        $this->expectNotToPerformAssertions();
        MigrationHistoryGuard::assertFreshDatabase([]);
    }

    public function testRefusesToAdoptAnExistingDatabaseWithoutHistory(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('history is missing');

        MigrationHistoryGuard::assertFreshDatabase(['permissions', 'roles', 'accounting_periods']);
    }

    public function testAllowsAContiguousAppliedPrefix(): void
    {
        $this->expectNotToPerformAssertions();
        MigrationHistoryGuard::assertContiguousHistory(
            self::MIGRATIONS,
            ['001_create_system_configuration', '002_create_roles_and_permissions']
        );
    }

    public function testRefusesAGapOrOutOfOrderMigrationHistory(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not a contiguous prefix');

        MigrationHistoryGuard::assertContiguousHistory(
            self::MIGRATIONS,
            ['001_create_system_configuration', '003_insert_core_data']
        );
    }

    public function testRefusesUnknownMigrationEntries(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not a contiguous prefix');

        MigrationHistoryGuard::assertContiguousHistory(self::MIGRATIONS, ['legacy_unknown_migration']);
    }
}
