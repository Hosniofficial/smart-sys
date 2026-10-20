<?php

declare(strict_types=1);

use App\Utils\SuperAdminHelper;
use PHPUnit\Framework\TestCase;

final class SuperAdminHelperTest extends TestCase
{
    public function testRecognizesPlatformSuperAdminOnlyWithoutTenantMembership(): void
    {
        self::assertTrue(SuperAdminHelper::is(['role_id' => 1, 'tenant_id' => null]));
        self::assertTrue(SuperAdminHelper::is(['role' => 'super_admin', 'tenant_id' => 0]));
        self::assertFalse(SuperAdminHelper::is(['role_id' => 1]));
        self::assertFalse(SuperAdminHelper::is(['role_id' => 1, 'tenant_id' => 2]));
        self::assertFalse(SuperAdminHelper::is(['role' => 'super_admin', 'tenant_id' => 2]));
    }

    public function testTenantAdminDoesNotGainPlatformOrAdminIdentityFromConflictingPrimaryRole(): void
    {
        $tenantUser = ['role_id' => 1, 'role' => 'super_admin', 'tenant_id' => 2];

        self::assertFalse(SuperAdminHelper::is($tenantUser));
        self::assertFalse(SuperAdminHelper::isAdminOrAbove($tenantUser));
        self::assertTrue(SuperAdminHelper::isAdminOrAbove($tenantUser + ['is_owner' => 1]));
    }

    public function testRecognizesTenantAdminAndPlatformAdminSeparately(): void
    {
        self::assertTrue(SuperAdminHelper::isAdminOrAbove([
            'role_id' => 2,
            'role' => 'admin',
            'tenant_id' => 2,
        ]));
        self::assertTrue(SuperAdminHelper::isAdminOrAbove(['role_id' => 1, 'tenant_id' => null]));
        self::assertFalse(SuperAdminHelper::isAdminOrAbove([
            'role_id' => 2,
            'role' => 'admin',
            'tenant_id' => null,
        ]));
    }

    public function testAdministratorAliasDoesNotGrantAdminPrivileges(): void
    {
        self::assertFalse(SuperAdminHelper::isAdminOrAbove([
            'role_id' => 2,
            'role' => 'administrator',
            'tenant_id' => 2,
        ]));
    }
}
