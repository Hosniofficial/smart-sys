<?php

declare(strict_types=1);

use App\Security\PermissionResolver;
use App\Utils\SuperAdminHelper;
use PHPUnit\Framework\TestCase;

final class PermissionResolverTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NULL,
            role_id INTEGER NULL,
            is_owner INTEGER NOT NULL DEFAULT 0
        )');
        $this->db->exec('CREATE TABLE roles (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            tenant_id INTEGER NULL,
            is_system_role INTEGER NOT NULL DEFAULT 0
        )');
        $this->db->exec('CREATE TABLE users_role (
            user_id INTEGER NOT NULL,
            role_id INTEGER NOT NULL,
            tenant_id INTEGER NULL,
            PRIMARY KEY (user_id, role_id)
        )');
        $this->db->exec('CREATE TABLE role_permissions (
            role_id INTEGER NOT NULL,
            permission_id INTEGER NOT NULL,
            tenant_id INTEGER NULL,
            PRIMARY KEY (role_id, permission_id)
        )');
        $this->db->exec('CREATE TABLE permissions (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            tenant_id INTEGER NULL,
            is_active INTEGER NOT NULL DEFAULT 1
        )');
        $this->db->exec("CREATE TABLE permission_overrides (
            tenant_id INTEGER NOT NULL,
            subject_type TEXT NOT NULL,
            subject_id INTEGER NOT NULL,
            permission_id INTEGER NOT NULL,
            effect TEXT NOT NULL,
            reason TEXT NOT NULL,
            created_by INTEGER NOT NULL,
            updated_at TEXT NULL,
            PRIMARY KEY (tenant_id, subject_type, subject_id, permission_id)
        )");
        $this->db->exec("INSERT INTO users VALUES
            (1, 10, 4, 0), (2, 10, 1, 1), (3, 10, 2, 0),
            (4, 10, 3, 0), (5, 10, 1, 0), (6, 10, 4, 0),
            (7, 0, 1, 0)");
        $this->db->exec("INSERT INTO roles VALUES
            (1, 'super_admin', NULL, 1), (2, 'admin', NULL, 1),
            (3, 'cashier', 20, 0), (4, 'employee', NULL, 1),
            (5, 'manager', NULL, 1), (6, 'finance_officer', NULL, 0)");
        $this->db->exec("INSERT INTO permissions VALUES
            (1, 'sale.view', NULL, 1), (2, 'purchase.view', NULL, 1),
            (3, 'foreign.permission', 20, 1)");
        $this->db->exec("INSERT INTO role_permissions VALUES
            (1, 1, NULL), (2, 2, NULL), (2, 3, NULL),
            (3, 1, NULL), (4, 1, NULL), (5, 1, NULL), (6, 2, NULL)");
        $this->db->exec("INSERT INTO users_role VALUES
            (1, 4, 10), (2, 2, 10), (3, 3, 10), (4, 4, 20),
            (5, 1, 10), (6, 6, 10)");
    }

    public function testResolvesGlobalAndSameTenantRolePermissions(): void
    {
        self::assertSame(['sale.view'], PermissionResolver::getGrantedPermissionNames($this->db, 1));
        self::assertSame(['purchase.view'], PermissionResolver::getGrantedPermissionNames($this->db, 2));
    }

    public function testRejectsPermissionsFromForeignTenantRolesAndPermissionRows(): void
    {
        self::assertSame([], PermissionResolver::getGrantedPermissionNames($this->db, 3));
        self::assertSame([], PermissionResolver::getGrantedPermissionNames($this->db, 4));
    }

    public function testNeverResolvesPlatformRolePermissionsForATenantUser(): void
    {
        self::assertSame([], PermissionResolver::getGrantedPermissionNames($this->db, 5));
    }

    public function testTenantOwnerWithConflictingPrimarySuperAdminRoleUsesExplicitAdminAssignment(): void
    {
        self::assertSame(
            ['id' => 2, 'name' => 'admin'],
            PermissionResolver::getEffectiveRole($this->db, 2)
        );
        self::assertSame(
            ['purchase.view'],
            PermissionResolver::getGrantedPermissionNames($this->db, 2)
        );

        $identity = [
            'role_id' => 2,
            'role' => 'admin',
            'tenant_id' => 10,
            'is_owner' => 1,
        ];
        self::assertFalse(SuperAdminHelper::is($identity));
        self::assertTrue(SuperAdminHelper::isAdminOrAbove($identity));
    }

    public function testTenantOwnerDoesNotReceivePlatformPrivilegesFromOwnerFlag(): void
    {
        $identity = [
            'role_id' => 2,
            'role' => 'admin',
            'tenant_id' => 10,
            'is_owner' => 1,
        ];

        self::assertFalse(SuperAdminHelper::is($identity));
        self::assertFalse(
            PermissionResolver::hasPermission($this->db, 2, 'platform.tenants.manage')
        );
    }

    public function testSharedGlobalFinanceRoleCanGrantTenantPermissions(): void
    {
        self::assertSame(
            ['purchase.view'],
            PermissionResolver::getGrantedPermissionNames($this->db, 6)
        );
        self::assertSame(
            ['id' => 6, 'name' => 'finance_officer'],
            PermissionResolver::getEffectiveRole($this->db, 6)
        );
    }

    public function testPlatformSuperAdminIdentityRequiresPlatformScope(): void
    {
        self::assertSame(
            ['id' => 1, 'name' => 'super_admin'],
            PermissionResolver::getEffectiveRole($this->db, 7)
        );
        self::assertTrue(SuperAdminHelper::is([
            'role_id' => 1,
            'role' => 'super_admin',
            'tenant_id' => 0,
        ]));
    }

    public function testOwnerContributionPermissionsAreResolvedForAdminAndPlatformSuperAdminOnly(): void
    {
        $this->db->exec("INSERT INTO permissions VALUES
            (4, 'accounting.owner_contribution.view', NULL, 1)");
        $this->db->exec("INSERT INTO role_permissions VALUES
            (1, 4, NULL), (2, 4, NULL)");

        self::assertTrue(
            PermissionResolver::hasPermission($this->db, 2, 'accounting.owner_contribution.view')
        );
        self::assertTrue(
            PermissionResolver::hasPermission($this->db, 7, 'accounting.owner_contribution.view')
        );
        self::assertFalse(
            PermissionResolver::hasPermission($this->db, 5, 'accounting.owner_contribution.view')
        );
    }

    public function testExplicitRoleAssignmentDoesNotFallBackToPrimaryRoleWhenItHasNoPermissions(): void
    {
        $this->db->exec('DELETE FROM role_permissions WHERE role_id = 2');

        self::assertSame([], PermissionResolver::getGrantedPermissionNames($this->db, 2));
        self::assertFalse(PermissionResolver::hasPermission($this->db, 2, 'sale.view'));
    }

    public function testLegacyPrimaryRoleIsUsedOnlyWhenThereAreNoExplicitAssignments(): void
    {
        $this->db->exec('DELETE FROM users_role WHERE user_id = 1');
        $this->db->exec('DELETE FROM users_role WHERE user_id = 4');

        self::assertSame(['sale.view'], PermissionResolver::getGrantedPermissionNames($this->db, 1));
        self::assertSame([], PermissionResolver::getGrantedPermissionNames($this->db, 4));
    }

    public function testHasPermissionUsesTheSameTenantScopedResolution(): void
    {
        self::assertTrue(PermissionResolver::hasPermission($this->db, 1, 'sale.view'));
        self::assertFalse(PermissionResolver::hasPermission($this->db, 3, 'sale.view'));
    }

    public function testTenantRoleOverridesCanGrantAndDenySharedRolePermissions(): void
    {
        $this->db->exec("INSERT INTO permission_overrides
            (tenant_id, subject_type, subject_id, permission_id, effect, reason, created_by)
            VALUES (10, 'role', 4, 2, 'allow', 'Role scope test', 99)");

        self::assertSame(
            ['purchase.view', 'sale.view'],
            PermissionResolver::getGrantedPermissionNames($this->db, 1)
        );

        $this->db->exec("INSERT INTO permission_overrides
            (tenant_id, subject_type, subject_id, permission_id, effect, reason, created_by)
            VALUES (10, 'role', 4, 1, 'deny', 'Role scope test', 99)");
        self::assertSame(
            ['purchase.view'],
            PermissionResolver::getGrantedPermissionNames($this->db, 1)
        );
    }

    public function testTenantRoleOverrideCanGrantPermissionToRoleWithoutBasePermissions(): void
    {
        $this->db->exec('DELETE FROM role_permissions WHERE role_id = 4');
        $this->db->exec("INSERT INTO permission_overrides
            (tenant_id, subject_type, subject_id, permission_id, effect, reason, created_by)
            VALUES (10, 'role', 4, 2, 'allow', 'Role override', 99)");

        self::assertSame(
            ['purchase.view'],
            PermissionResolver::getGrantedPermissionNames($this->db, 1)
        );
    }

    public function testUserDenyOverridesRoleGrantAndUserAllowOverridesRoleDeny(): void
    {
        $this->db->exec("INSERT INTO permission_overrides
            (tenant_id, subject_type, subject_id, permission_id, effect, reason, created_by)
            VALUES
                (10, 'role', 4, 1, 'deny', 'Role override', 99),
                (10, 'user', 1, 1, 'allow', 'User exception', 99)");

        self::assertSame(
            ['sale.view'],
            PermissionResolver::getGrantedPermissionNames($this->db, 1)
        );

        $this->db->exec("UPDATE permission_overrides SET effect = 'deny'
            WHERE tenant_id = 10 AND subject_type = 'user' AND subject_id = 1 AND permission_id = 1");
        self::assertSame(
            [],
            PermissionResolver::getGrantedPermissionNames($this->db, 1)
        );
    }

    public function testCanResolveRolePermissionsWithoutApplyingUserOverrides(): void
    {
        $this->db->exec("INSERT INTO permission_overrides
            (tenant_id, subject_type, subject_id, permission_id, effect, reason, created_by)
            VALUES (10, 'user', 1, 1, 'deny', 'User exception', 99)");

        self::assertSame(
            ['sale.view'],
            PermissionResolver::getGrantedPermissionNames($this->db, 1, false)
        );
        self::assertSame(
            [],
            PermissionResolver::getGrantedPermissionNames($this->db, 1)
        );
    }

    public function testPermissionOverridesAreTenantScoped(): void
    {
        $this->db->exec("INSERT INTO permission_overrides
            (tenant_id, subject_type, subject_id, permission_id, effect, reason, created_by)
            VALUES (20, 'role', 4, 1, 'deny', 'Foreign tenant', 99)");

        self::assertSame(
            ['sale.view'],
            PermissionResolver::getGrantedPermissionNames($this->db, 1)
        );
    }
}
