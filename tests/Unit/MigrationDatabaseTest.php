<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/database/MigrationDatabase.php';

use PHPUnit\Framework\TestCase;

final class MigrationDatabaseTest extends TestCase
{
    public function testUsesApplicationDatabaseVariableNames(): void
    {
        self::assertSame([
            'host' => 'staging-db.internal',
            'port' => '3307',
            'database' => 'smart_staging',
            'username' => 'stage_user',
            'password' => 'stage_secret',
        ], MigrationDatabase::resolveConfig([
            'DB_HOST' => 'staging-db.internal',
            'DB_PORT' => '3307',
            'DB_DATABASE' => 'smart_staging',
            'DB_USERNAME' => 'stage_user',
            'DB_PASSWORD' => 'stage_secret',
        ]));
    }

    public function testApplicationVariablesTakePrecedenceOverLegacyAliases(): void
    {
        $config = MigrationDatabase::resolveConfig([
            'DB_DATABASE' => 'new_database',
            'DB_NAME' => 'legacy_database',
            'DB_USERNAME' => 'new_user',
            'DB_USER' => 'legacy_user',
            'DB_PASSWORD' => 'new_password',
            'DB_PASS' => 'legacy_password',
        ]);

        self::assertSame('new_database', $config['database']);
        self::assertSame('new_user', $config['username']);
        self::assertSame('new_password', $config['password']);
    }

    public function testSupportsLegacyVariableNamesDuringTransition(): void
    {
        $config = MigrationDatabase::resolveConfig([
            'DB_HOST' => 'legacy-host',
            'DB_NAME' => 'legacy_database',
            'DB_USER' => 'legacy_user',
            'DB_PASS' => 'legacy_password',
        ]);

        self::assertSame('legacy_database', $config['database']);
        self::assertSame('legacy_user', $config['username']);
        self::assertSame('legacy_password', $config['password']);
        self::assertSame('3306', $config['port']);
    }

    public function testRequiresExplicitDatabaseNameInsteadOfDefaulting(): void
    {
        $this->expectException(RuntimeException::class);
        MigrationDatabase::resolveConfig([]);
    }
}
