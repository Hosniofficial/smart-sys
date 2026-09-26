<?php

declare(strict_types=1);

use App\Security\TenantRoleAssignmentPolicy;
use PHPUnit\Framework\TestCase;

final class TenantRoleAssignmentPolicyTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('CREATE TABLE roles (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            tenant_id INTEGER NULL,
            is_system_role INTEGER NOT NULL DEFAULT 0
        )');
        $this->db->exec("INSERT INTO roles (id, name, tenant_id, is_system_role) VALUES
            (1, 'super_admin', NULL, 1),
            (2, 'admin', NULL, 1),
            (3, 'manager', NULL, 1),
            (4, 'cashier', NULL, 1),
            (9, 'finance_officer', NULL, 0),
            (10, 'branch_manager', NULL, 1),
            (11, 'accounting-period-tester', NULL, 0),
            (20, 'tenant_a_role', 7, 0),
            (21, 'tenant_b_role', 8, 0),
            (22, 'super_admin', 7, 0),
            (23, 'manager', 7, 0),
            (24, 'finance_officer', 7, 0)");
    }

    public function testReservesThePlatformSuperAdminRoleNameCaseInsensitively(): void
    {
        self::assertTrue(TenantRoleAssignmentPolicy::isReservedRoleName('super_admin'));
        self::assertTrue(TenantRoleAssignmentPolicy::isReservedRoleName('  SUPER_ADMIN  '));
        self::assertTrue(TenantRoleAssignmentPolicy::isReservedRoleName('ADMIN'));
        self::assertFalse(TenantRoleAssignmentPolicy::isReservedRoleName('tenant_manager'));
    }

    public function testAllowsTenantOwnedAndGlobalSystemRolesExceptPlatformSuperAdmin(): void
    {
        self::assertSame(
            [20, 4, 23, 24],
            TenantRoleAssignmentPolicy::validate($this->db, ['20', 4, 23, 24], 7)
        );
    }

    public function testRejectsAdministrativeAndPlatformRolesForTenantAdministrators(): void
    {
        self::assertNull(TenantRoleAssignmentPolicy::validate($this->db, [2, 1], 7));
    }

    public function testRejectsSharedLegacyOperationalTemplates(): void
    {
        self::assertNull(TenantRoleAssignmentPolicy::validate($this->db, [3], 7));
        self::assertNull(TenantRoleAssignmentPolicy::validate($this->db, [10], 7));
        self::assertNull(TenantRoleAssignmentPolicy::validate($this->db, [9], 7));
    }

    public function testAllowsCanonicalSuperAdminOnlyWhenExplicitlyAuthorized(): void
    {
        self::assertSame([1], TenantRoleAssignmentPolicy::validate($this->db, [1], 7, true));
        self::assertNull(TenantRoleAssignmentPolicy::validate($this->db, [22], 7, true));
        self::assertNull(TenantRoleAssignmentPolicy::validate($this->db, [2], 7, true));
    }

    public function testRejectsRolesOwnedByAnotherTenant(): void
    {
        self::assertNull(TenantRoleAssignmentPolicy::validate($this->db, [21], 7));
    }

    public function testRejectsUnscopedNonSystemRoles(): void
    {
        self::assertNull(TenantRoleAssignmentPolicy::validate($this->db, [9], 7));
        self::assertNull(TenantRoleAssignmentPolicy::validate($this->db, [11], 7));
    }

    public function testRejectsTenantRoleUsingReservedSuperAdminName(): void
    {
        self::assertNull(TenantRoleAssignmentPolicy::validate($this->db, [22], 7));
    }

    public function testRejectsUnknownOrMalformedRoleIds(): void
    {
        self::assertNull(TenantRoleAssignmentPolicy::validate($this->db, [999], 7));
        self::assertNull(TenantRoleAssignmentPolicy::validate($this->db, ['not-an-id'], 7));
        self::assertNull(TenantRoleAssignmentPolicy::validate($this->db, [0], 7));
    }

    public function testAllowsAnEmptyAssignmentAndDeduplicatesIds(): void
    {
        self::assertSame([], TenantRoleAssignmentPolicy::validate($this->db, [], 7));
        self::assertSame([4], TenantRoleAssignmentPolicy::validate($this->db, [4, '4'], 7));
    }
}
