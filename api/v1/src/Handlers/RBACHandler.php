<?php

declare(strict_types=1);

namespace App\Handlers;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Services\MonologHandler;
use App\Utils\SuperAdminHelper;
use App\Security\TenantRoleAssignmentPolicy;

class RBACHandler extends BaseHandler
{
    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->logger = MonologHandler::getInstance('rbac');
    }

    // ── Security helper ────────────────────────────────────────────────
    /**
     * Returns [userId, roleId, null] if caller is admin, or [null, null, errorResponse] if not.
     */
    private function requireAdminAccess(Request $request, Response $response): array
    {
        $userData = $request->getAttribute('user');
        if (is_object($userData)) {
            $userData = (array) $userData;
        }

        $callerId = isset($userData['id']) ? (int) $userData['id'] : null;
        $roleId   = isset($userData['role_id']) ? (int) $userData['role_id'] : null;

        $isAdmin    = SuperAdminHelper::isAdminOrAbove($userData)
                   || $this->hasPermission((int) $callerId, 'user.edit');

        if (!$callerId || !$isAdmin) {
            return [null, null, $this->errorResponse($response, 'ليس لديك صلاحية للوصول لإدارة المستخدمين', 403)];
        }

        return [$callerId, $roleId, null];
    }

    /**
     * ── AUDIT FIX (SECURITY, HIGH) — discovered during a systematic tenant-isolation
     * sweep across the codebase ──────────────────────────────────────────────────
     *
     * createPermission()/updatePermission()/deletePermission() below used to be
     * gated only by requireAdminAccess(), which accepts SuperAdminHelper::
     * isAdminOrAbove() — and that helper explicitly accepts the ordinary
     * per-tenant 'admin' role, not just 'super_admin' (confirmed directly in
     * SuperAdminHelper's own docblock: "Used by AdminSettingsHandler and
     * RBACHandler which allow both roles").
     *
     * The `permissions` table itself has NO tenant_id column at all (confirmed:
     * its own INSERT statement never sets one) — it is a single, global catalog
     * shared by every tenant on the platform. Combined, this meant: any ordinary
     * customer's own 'admin'-role user (not a platform operator) could create,
     * rename, or delete a system-wide permission definition (e.g. 'sales.create')
     * — silently corrupting or breaking RBAC enforcement for every OTHER tenant
     * on the platform, not just their own. This is a cross-tenant blast-radius
     * issue, not merely a same-tenant data-exposure one.
     *
     * updateRole()/deleteRole() were checked and are NOT affected — both already
     * correctly scope by `WHERE id = ? AND tenant_id = ?` before mutating a
     * tenant's own custom roles, which is the intended, tenant-scoped resource.
     * This guard is needed specifically for the global `permissions` catalog.
     *
     * Fix: a strictly narrower guard requiring true platform super_admin only,
     * used exclusively by the three permission-catalog mutation endpoints.
     * Every other requireAdminAccess() call site is untouched — those operate on
     * already tenant-scoped resources where ordinary tenant-admin access is
     * legitimate and correctly bounded.
     */
    private function requireSuperAdminAccess(Request $request, Response $response): array
    {
        $userData = $request->getAttribute('user');
        if (is_object($userData)) {
            $userData = (array) $userData;
        }

        $callerId = isset($userData['id']) ? (int) $userData['id'] : null;
        $roleId   = isset($userData['role_id']) ? (int) $userData['role_id'] : null;

        if (!$callerId || !SuperAdminHelper::is($userData)) {
            return [null, null, $this->errorResponse(
                $response,
                'هذا الإجراء يمس كتالوج صلاحيات مشترك بين كل المستأجرين على المنصة، ويتطلب صلاحية مدير النظام الرئيسي (super_admin) حصريًا.',
                403
            )];
        }

        return [$callerId, $roleId, null];
    }

    // User Management Methods
    public function getUsers(Request $request, Response $response): Response
    {
        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
        }

        [, , $err] = $this->requireAdminAccess($request, $response);
        if ($err) {
            return $err;
        }

        try {
            $stmt = $this->db->prepare("
                SELECT
                    u.id,
                    u.name,
                    u.username,
                    u.email,
                    u.phone,
                    u.status,
                    u.role_id,
                    u.branch_id,
                    u.last_login,
                    GROUP_CONCAT(DISTINCT r.id   ORDER BY r.name SEPARATOR ',') AS role_ids,
                    GROUP_CONCAT(DISTINCT r.name ORDER BY r.name SEPARATOR ', ') AS roles,
                    b.name AS branch_name
                FROM users u
                LEFT JOIN users_role ur ON u.id = ur.user_id
                LEFT JOIN roles r       ON ur.role_id = r.id
                LEFT JOIN branches b   ON u.branch_id = b.id AND b.tenant_id = u.tenant_id
                WHERE u.tenant_id = ?
                GROUP BY u.id, u.name, u.username, u.email, u.phone, u.status, u.role_id, u.branch_id, u.last_login, b.name
                ORDER BY u.id DESC
            ");
            $stmt->execute([$tenantId]);
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            return $this->successResponse($response, $users, 200);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to get users', [
                'tenant_id' => $tenantId,
                'message' => $e->getMessage()
            ]);

            return $this->errorResponse($response, 'حدث خطأ أثناء جلب المستخدمين', 500);
        }
    }

    public function createUser(Request $request, Response $response): Response
    {
        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
        }

        [, , $err] = $this->requireAdminAccess($request, $response);
        if ($err) {
            return $err;
        }

        try {
            $data = $request->getParsedBody() ?? [];

            if (
                !isset($data['username'], $data['password'], $data['email']) ||
                trim((string) $data['username']) === '' ||
                trim((string) $data['password']) === '' ||
                trim((string) $data['email']) === ''
            ) {
                return $this->errorResponse($response, 'اسم المستخدم وكلمة المرور والبريد الإلكتروني مطلوبة', 400);
            }

            if (strlen((string) $data['password']) < 8) {
                return $this->errorResponse($response, 'يجب أن تكون كلمة المرور 8 أحرف على الأقل', 400);
            }

            if (!filter_var(trim((string) $data['email']), FILTER_VALIDATE_EMAIL)) {
                return $this->errorResponse($response, 'صيغة البريد الإلكتروني غير صحيحة', 400);
            }

            $stmt = $this->db->prepare("
                SELECT id
                FROM users
                WHERE (username = ? OR email = ?)
                  AND tenant_id = ?
                LIMIT 1
            ");
            $stmt->execute([
                trim((string) $data['username']),
                trim((string) $data['email']),
                $tenantId
            ]);

            if ($stmt->fetch()) {
                return $this->errorResponse($response, 'اسم المستخدم أو البريد الإلكتروني موجود بالفعل', 400);
            }

            // Resolve and authorize every requested role before creating the account.
            $requestedRoles = [];
            if (isset($data['roles']) && is_array($data['roles']) && !empty($data['roles'])) {
                $requestedRoles = array_values($data['roles']);
            } elseif (isset($data['role_id']) && $data['role_id'] !== '') {
                $requestedRoles = [$data['role_id']];
            }
            $currentUser = $request->getAttribute('user');
            if (is_object($currentUser)) {
                $currentUser = (array) $currentUser;
            }
            $rolesList = TenantRoleAssignmentPolicy::validate(
                $this->db,
                $requestedRoles,
                (int) $tenantId,
                SuperAdminHelper::is(is_array($currentUser) ? $currentUser : null)
            );
            if ($rolesList === null) {
                return $this->errorResponse($response, 'دور واحد أو أكثر غير متاح لهذا المستأجر.', 403);
            }
            $primaryRoleId = $rolesList[0] ?? null;

            $this->db->beginTransaction();

            $stmt = $this->db->prepare("
                INSERT INTO users (
                    username, password, email, phone, status, tenant_id, role_id, is_owner
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, 0
                )
            ");
            $stmt->execute([
                trim((string) $data['username']),
                password_hash((string) $data['password'], PASSWORD_DEFAULT),
                trim((string) $data['email']),
                isset($data['phone']) ? trim((string) $data['phone']) : null,
                $data['status'] ?? 'active',
                $tenantId,
                $primaryRoleId
            ]);

            $userId    = (int) $this->db->lastInsertId();
            $createdBy = $this->extractUserId($request);

            // Always populate users_role — use primaryRoleId as fallback if rolesList empty
            $rolesToInsert = !empty($rolesList) ? $rolesList : ($primaryRoleId ? [$primaryRoleId] : []);
            if (!empty($rolesToInsert)) {
                $insertRoleStmt = $this->db->prepare("
                    INSERT IGNORE INTO users_role (user_id, role_id, created_by, tenant_id)
                    VALUES (?, ?, ?, ?)
                ");
                foreach ($rolesToInsert as $rId) {
                    $insertRoleStmt->execute([$userId, $rId, $createdBy, $tenantId]);
                }
            }

            $this->db->commit();

            return $this->jsonResponse($response, [
                'status' => 'success',
                'message' => 'تم إنشاء المستخدم بنجاح',
                'data' => ['id' => $userId]
            ]);
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            $this->logger->error('Failed to create user', [
                'tenant_id' => $tenantId,
                'message' => $e->getMessage()
            ]);

            return $this->errorResponse($response, 'حدث خطأ أثناء معالجة الطلب', 500);
        }
    }

    public function updateUser(Request $request, Response $response, array $args): Response
    {
        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
        }

        [, , $err] = $this->requireAdminAccess($request, $response);
        if ($err) {
            return $err;
        }

        try {
            $userId = (int) ($args['id'] ?? 0);
            $data = $request->getParsedBody() ?? [];

            $rolesList = null;
            if (isset($data['roles']) && is_array($data['roles'])) {
                $currentUser = $request->getAttribute('user');
                if (is_object($currentUser)) {
                    $currentUser = (array) $currentUser;
                }
                $rolesList = TenantRoleAssignmentPolicy::validate(
                    $this->db,
                    array_values($data['roles']),
                    (int) $tenantId,
                    SuperAdminHelper::is(is_array($currentUser) ? $currentUser : null)
                );
                if ($rolesList === null) {
                    return $this->errorResponse($response, 'دور واحد أو أكثر غير متاح لهذا المستأجر.', 403);
                }
                if ($rolesList === []) {
                    return $this->errorResponse($response, 'يجب إسناد دور واحد على الأقل للمستخدم.', 400);
                }
            }

            $stmt = $this->db->prepare("
                SELECT id
                FROM users
                WHERE id = ? AND tenant_id = ?
                LIMIT 1
            ");
            $stmt->execute([$userId, $tenantId]);

            if (!$stmt->fetch()) {
                return $this->errorResponse($response, 'المستخدم غير موجود', 404);
            }

            $this->db->beginTransaction();

            $updates = [];
            $params = [];

            if (isset($data['email'])) {
                $updates[] = "email = ?";
                $params[] = trim((string) $data['email']);
            }

            if (isset($data['phone'])) {
                $updates[] = "phone = ?";
                $params[] = trim((string) $data['phone']);
            }

            if (isset($data['status'])) {
                $updates[] = "status = ?";
                $params[] = $data['status'];
            }

            if (isset($data['password']) && trim((string) $data['password']) !== '') {
                $updates[] = "password = ?";
                $params[] = password_hash((string) $data['password'], PASSWORD_DEFAULT);
            }

            if (!empty($updates)) {
                $params[] = $userId;
                $params[] = $tenantId;

                $stmt = $this->db->prepare("
                    UPDATE users
                    SET " . implode(", ", $updates) . "
                    WHERE id = ? AND tenant_id = ? AND is_owner = 0
                ");
                $stmt->execute($params);
            }

            if ($rolesList !== null) {
                $primaryRole = $rolesList[0] ?? null;
                $createdBy   = $this->extractUserId($request);

                $stmt = $this->db->prepare("
                    DELETE FROM users_role
                    WHERE user_id = ? AND EXISTS (SELECT 1 FROM users u2 WHERE u2.id = ? AND u2.tenant_id = ? AND u2.is_owner = 0)
                ");
                $stmt->execute([$userId, $userId, $tenantId]);

                $insertRoleStmt = $this->db->prepare("
                    INSERT IGNORE INTO users_role (user_id, role_id, created_by, tenant_id)
                    VALUES (?, ?, ?, ?)
                ");
                foreach ($rolesList as $rId) {
                    $insertRoleStmt->execute([$userId, $rId, $createdBy, $tenantId]);
                }

                if ($primaryRole !== null) {
                    $stmt = $this->db->prepare("UPDATE users SET role_id = ? WHERE id = ? AND tenant_id = ? AND is_owner = 0");
                    $stmt->execute([$primaryRole, $userId, $tenantId]);
                }
            }

            $this->db->commit();

            return $this->jsonResponse($response, [
                'status' => 'success',
                'message' => 'تم تحديث المستخدم بنجاح'
            ]);
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            $this->logger->error('Failed to update user', [
                'tenant_id' => $tenantId,
                'user_id' => $args['id'] ?? null,
                'message' => $e->getMessage()
            ]);

            return $this->errorResponse($response, 'حدث خطأ أثناء معالجة الطلب', 500);
        }
    }

    public function deleteUser(Request $request, Response $response, array $args): Response
    {
        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
        }

        [$callerId, , $err] = $this->requireAdminAccess($request, $response);
        if ($err) {
            return $err;
        }

        try {
            $userId = (int) ($args['id'] ?? 0);

            // Cannot delete yourself
            if ($userId === $callerId) {
                return $this->errorResponse($response, 'لا يمكنك حذف حسابك الخاص', 403);
            }

            $stmt = $this->db->prepare("
                SELECT id, is_owner
                FROM users
                WHERE id = ? AND tenant_id = ?
                LIMIT 1
            ");
            $stmt->execute([$userId, $tenantId]);
            $target = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$target) {
                return $this->errorResponse($response, 'المستخدم غير موجود', 404);
            }

            // Cannot delete account owner
            if ((int) $target['is_owner'] === 1) {
                return $this->errorResponse($response, 'لا يمكن حذف حساب مالك النظام', 403);
            }

            $stmt = $this->db->prepare("
                DELETE FROM users
                WHERE id = ? AND tenant_id = ? AND is_owner = 0
            ");
            $stmt->execute([$userId, $tenantId]);

            return $this->jsonResponse($response, [
                'status' => 'success',
                'message' => 'تم حذف المستخدم بنجاح'
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to delete user', [
                'tenant_id' => $tenantId,
                'user_id' => $args['id'] ?? null,
                'message' => $e->getMessage()
            ]);

            return $this->errorResponse($response, 'حدث خطأ أثناء معالجة الطلب', 500);
        }
    }

    // Role Management Methods
    public function getRoles(Request $request, Response $response): Response
    {
        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
        }

        [, , $err] = $this->requireAdminAccess($request, $response);
        if ($err) {
            return $err;
        }

        try {
            $stmt = $this->db->prepare("
                SELECT
                    r.*,
                    GROUP_CONCAT(DISTINCT p.name ORDER BY p.name SEPARATOR ', ') AS permissions
                FROM roles r
                LEFT JOIN role_permissions rp ON r.id = rp.role_id
                LEFT JOIN permissions p ON rp.permission_id = p.id
                WHERE r.tenant_id = ?
                   OR (
                       r.tenant_id IS NULL
                       AND r.is_system_role = 1
                       AND LOWER(r.name) NOT IN ('super_admin', 'admin', 'manager', 'branch_manager', 'finance_officer')
                   )
                GROUP BY r.id
                ORDER BY COALESCE(r.tenant_id, 0) DESC, r.name
            ");
            $stmt->execute([$tenantId]);
            $roles = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($roles as &$role) {
                unset($role['tenant_scope_id']);
            }
            unset($role);

            return $this->successResponse($response, $roles, 200);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to get roles', [
                'tenant_id' => $tenantId,
                'message' => $e->getMessage()
            ]);

            return $this->errorResponse($response, 'حدث خطأ أثناء معالجة الطلب', 500);
        }
    }

    public function createRole(Request $request, Response $response): Response
    {
        [, , $err] = $this->requireAdminAccess($request, $response);
        if ($err) {
            return $err;
        }

        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
        }

        try {
            $data = $request->getParsedBody() ?? [];

            if (!isset($data['name']) || trim((string) $data['name']) === '') {
                return $this->errorResponse($response, 'اسم الدور مطلوب', 400);
            }
            if (TenantRoleAssignmentPolicy::isReservedRoleName((string) $data['name'])) {
                return $this->errorResponse($response, 'اسم الدور محجوز لدور النظام.', 403);
            }

            $stmt = $this->db->prepare("
                SELECT id
                FROM roles
                WHERE name = ? AND tenant_id = ?
                LIMIT 1
            ");
            $stmt->execute([trim((string) $data['name']), $tenantId]);

            if ($stmt->fetch()) {
                return $this->errorResponse($response, 'الدور موجود بالفعل', 400);
            }

            $this->db->beginTransaction();

            $stmt = $this->db->prepare("
                INSERT INTO roles (name, description, created_by, tenant_id)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([
                trim((string) $data['name']),
                $data['description'] ?? null,
                $this->extractUserId($request),
                $tenantId
            ]);

            $roleId = (int) $this->db->lastInsertId();

            if (isset($data['permissions']) && is_array($data['permissions'])) {
                $insertPermissionStmt = $this->db->prepare("
                    INSERT INTO role_permissions (role_id, permission_id, created_by)
                    VALUES (?, ?, ?)
                ");

                $createdBy = $this->extractUserId($request);

                foreach ($data['permissions'] as $permissionId) {
                    $insertPermissionStmt->execute([
                        $roleId,
                        (int) $permissionId,
                        $createdBy
                    ]);
                }
            }

            $this->db->commit();

            return $this->jsonResponse($response, [
                'status' => 'success',
                'message' => 'تم إنشاء الدور بنجاح',
                'data' => ['id' => $roleId]
            ]);
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            $this->logger->error('Failed to create role', [
                'tenant_id' => $tenantId,
                'message' => $e->getMessage()
            ]);

            return $this->errorResponse($response, 'حدث خطأ أثناء معالجة الطلب', 500);
        }
    }

    public function updateRole(Request $request, Response $response, array $args): Response
    {
        [, , $err] = $this->requireAdminAccess($request, $response);
        if ($err) {
            return $err;
        }

        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
        }

        try {
            $roleId = (int) ($args['id'] ?? 0);
            $data = $request->getParsedBody() ?? [];

            $stmt = $this->db->prepare("
                SELECT id, is_system_role
                FROM roles
                WHERE id = ? AND tenant_id = ?
                LIMIT 1
            ");
            $stmt->execute([$roleId, $tenantId]);
            $role = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$role) {
                return $this->errorResponse($response, 'الدور غير موجود', 404);
            }

            if (!empty($role['is_system_role'])) {
                return $this->errorResponse($response, 'لا يمكن تعديل الأدوار النظامية', 400);
            }
            if (isset($data['name']) && TenantRoleAssignmentPolicy::isReservedRoleName((string) $data['name'])) {
                return $this->errorResponse($response, 'اسم الدور محجوز لدور النظام.', 403);
            }

            $this->db->beginTransaction();

            if (isset($data['name']) || isset($data['description'])) {
                $updates = [];
                $params = [];

                if (isset($data['name'])) {
                    $updates[] = "name = ?";
                    $params[] = trim((string) $data['name']);
                }

                if (isset($data['description'])) {
                    $updates[] = "description = ?";
                    $params[] = $data['description'];
                }

                if (!empty($updates)) {
                    $params[] = $roleId;
                    $params[] = $tenantId;

                    $stmt = $this->db->prepare("
                        UPDATE roles
                        SET " . implode(", ", $updates) . "
                        WHERE id = ? AND tenant_id = ?
                    ");
                    $stmt->execute($params);
                }
            }

            if (isset($data['permissions']) && is_array($data['permissions'])) {
                $stmt = $this->db->prepare("
                    DELETE FROM role_permissions
                    WHERE role_id = ?
                ");
                $stmt->execute([$roleId]);

                $insertPermissionStmt = $this->db->prepare("
                    INSERT INTO role_permissions (role_id, permission_id, created_by)
                    VALUES (?, ?, ?)
                ");

                $createdBy = $this->extractUserId($request);

                foreach ($data['permissions'] as $permissionId) {
                    $insertPermissionStmt->execute([
                        $roleId,
                        (int) $permissionId,
                        $createdBy
                    ]);
                }
            }

            $this->db->commit();

            return $this->jsonResponse($response, [
                'status' => 'success',
                'message' => 'تم تحديث الدور بنجاح'
            ]);
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            $this->logger->error('Failed to update role', [
                'tenant_id' => $tenantId,
                'role_id' => $args['id'] ?? null,
                'message' => $e->getMessage()
            ]);

            return $this->errorResponse($response, 'حدث خطأ أثناء معالجة الطلب', 500);
        }
    }

    public function deleteRole(Request $request, Response $response, array $args): Response
    {
        [, , $err] = $this->requireAdminAccess($request, $response);
        if ($err) {
            return $err;
        }

        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
        }

        try {
            $roleId = (int) ($args['id'] ?? 0);

            $stmt = $this->db->prepare("
                SELECT id, is_system_role
                FROM roles
                WHERE id = ? AND tenant_id = ?
                LIMIT 1
            ");
            $stmt->execute([$roleId, $tenantId]);
            $role = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$role) {
                return $this->errorResponse($response, 'الدور غير موجود', 404);
            }

            if (!empty($role['is_system_role'])) {
                return $this->errorResponse($response, 'لا يمكن حذف الأدوار النظامية', 400);
            }

            $stmt = $this->db->prepare("
                DELETE FROM roles
                WHERE id = ? AND tenant_id = ?
            ");
            $stmt->execute([$roleId, $tenantId]);

            return $this->jsonResponse($response, [
                'status' => 'success',
                'message' => 'تم حذف الدور بنجاح'
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to delete role', [
                'tenant_id' => $tenantId,
                'role_id' => $args['id'] ?? null,
                'message' => $e->getMessage()
            ]);

            return $this->errorResponse($response, 'حدث خطأ أثناء معالجة الطلب', 500);
        }
    }

    // Permission Management Methods
    public function getPermissions(Request $request, Response $response): Response
    {
        [, , $err] = $this->requireAdminAccess($request, $response);
        if ($err) {
            return $err;
        }

        try {
            $stmt = $this->db->query("
                SELECT *
                FROM permissions
                ORDER BY category, name
            ");
            $permissions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $groupedPermissions = [];
            foreach ($permissions as $permission) {
                $category = $permission['category'] ?? 'general';
                if (!isset($groupedPermissions[$category])) {
                    $groupedPermissions[$category] = [];
                }
                $groupedPermissions[$category][] = $permission;
            }

            return $this->successResponse($response, $groupedPermissions, 200);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to get permissions', [
                'message' => $e->getMessage()
            ]);

            return $this->errorResponse($response, 'حدث خطأ أثناء معالجة الطلب', 500);
        }
    }

    public function getRolePermissions(Request $request, Response $response, array $args): Response
    {
        [, , $err] = $this->requireAdminAccess($request, $response);
        if ($err) {
            return $err;
        }

        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
        }

        try {
            $roleId = (int) ($args['id'] ?? 0);

            // Verify role belongs to tenant
            $stmt = $this->db->prepare("
                SELECT id FROM roles
                WHERE id = ?
                  AND (tenant_id = ? OR (tenant_id IS NULL AND is_system_role = 1))
            ");
            $stmt->execute([$roleId, $tenantId]);
            if (!$stmt->fetch()) {
                return $this->errorResponse($response, 'الدور غير موجود', 404);
            }

            $stmt = $this->db->prepare("
                SELECT
                    p.id,
                    p.name,
                    p.description,
                    p.category,
                    CASE WHEN rp.role_id IS NOT NULL THEN 1 ELSE 0 END AS assigned
                FROM permissions p
                LEFT JOIN role_permissions rp
                    ON p.id = rp.permission_id
                   AND rp.role_id = ?
                ORDER BY p.category, p.name
            ");
            $stmt->execute([$roleId]);
            $permissions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            return $this->successResponse($response, $permissions, 200);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to get role permissions', [
                'tenant_id' => $tenantId,
                'role_id' => $args['id'] ?? null,
                'message' => $e->getMessage()
            ]);

            return $this->errorResponse($response, 'حدث خطأ أثناء معالجة الطلب', 500);
        }
    }

    public function updateRolePermissions(Request $request, Response $response, array $args): Response
    {
        [, , $err] = $this->requireAdminAccess($request, $response);
        if ($err) {
            return $err;
        }

        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
        }

        try {
            $roleId = (int) ($args['id'] ?? 0);

            // Verify role belongs to tenant
            $stmt = $this->db->prepare("
                SELECT id FROM roles
                WHERE id = ? AND tenant_id = ?
            ");
            $stmt->execute([$roleId, $tenantId]);
            if (!$stmt->fetch()) {
                return $this->errorResponse($response, 'الدور غير موجود', 404);
            }

            $data = $request->getParsedBody() ?? [];

            if (!isset($data['permissions']) || !is_array($data['permissions'])) {
                return $this->errorResponse($response, 'مصفوفة الصلاحيات مطلوبة', 400);
            }

            $this->db->beginTransaction();

            $stmt = $this->db->prepare("
                DELETE FROM role_permissions
                WHERE role_id = ?
            ");
            $stmt->execute([$roleId]);

            if (!empty($data['permissions'])) {
                $insertStmt = $this->db->prepare("
                    INSERT INTO role_permissions (role_id, permission_id, created_by)
                    VALUES (?, ?, ?)
                ");

                $createdBy = $this->extractUserId($request);

                foreach ($data['permissions'] as $permissionId) {
                    $insertStmt->execute([
                        $roleId,
                        (int) $permissionId,
                        $createdBy
                    ]);
                }
            }

            $this->db->commit();

            return $this->jsonResponse($response, [
                'status' => 'success',
                'message' => 'تم تحديث صلاحيات الدور بنجاح'
            ]);
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            $this->logger->error('Failed to update role permissions', [
                'tenant_id' => $tenantId,
                'role_id' => $args['id'] ?? null,
                'message' => $e->getMessage()
            ]);

            return $this->errorResponse($response, 'حدث خطأ أثناء معالجة الطلب', 500);
        }
    }

    public function getUserPermissions(Request $request, Response $response, array $args): Response
    {
        [, , $err] = $this->requireAdminAccess($request, $response);
        if ($err) {
            return $err;
        }

        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
        }

        try {
            $userId = (int) ($args['id'] ?? 0);

            // Verify the user belongs to the same tenant
            $userStmt = $this->db->prepare("
                SELECT tenant_id FROM users WHERE id = ? LIMIT 1
            ");
            $userStmt->execute([$userId]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);

            if (!$user || $user['tenant_id'] != $tenantId) {
                return $this->errorResponse($response, 'المستخدم غير موجود أو لا ينتمي إلى المستأجر الحالي', 404);
            }

            $stmt = $this->db->prepare("
                SELECT DISTINCT
                    p.id,
                    p.name,
                    p.description,
                    p.category,
                    r.name AS role_name
                FROM permissions p
                JOIN role_permissions rp ON p.id = rp.permission_id
                JOIN roles r ON rp.role_id = r.id
                JOIN users_role ur ON r.id = ur.role_id
                WHERE ur.user_id = ?
                  AND (ur.tenant_id = ? OR ur.tenant_id IS NULL)
                  AND (r.tenant_id = ? OR (r.tenant_id IS NULL AND r.is_system_role = 1))
                ORDER BY p.category, p.name
            ");
            $stmt->execute([$userId, $tenantId, $tenantId]);
            $permissions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            return $this->successResponse($response, $permissions, 200);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to get user permissions', [
                'user_id' => $args['id'] ?? null,
                'message' => $e->getMessage()
            ]);

            return $this->errorResponse($response, 'حدث خطأ أثناء معالجة الطلب', 500);
        }
    }

    public function createPermission(Request $request, Response $response): Response
    {
        [, , $err] = $this->requireSuperAdminAccess($request, $response);
        if ($err) {
            return $err;
        }

        try {
            $data = $request->getParsedBody() ?? [];

            if (
                !isset($data['name'], $data['category']) ||
                trim((string) $data['name']) === '' ||
                trim((string) $data['category']) === ''
            ) {
                return $this->errorResponse($response, 'الاسم والتصنيف مطلوبان', 400);
            }

            $stmt = $this->db->prepare("
                INSERT INTO permissions (name, description, category, created_by)
                VALUES (?, ?, ?, ?)
            ");
            $createdBy = $this->extractUserId($request) ?? null;
            $stmt->execute([
                trim((string) $data['name']),
                $data['description'] ?? null,
                trim((string) $data['category']),
                $createdBy
            ]);

            $permissionId = (int) $this->db->lastInsertId();

            return $this->jsonResponse($response, [
                'status' => 'success',
                'message' => 'تم إنشاء الصلاحية بنجاح',
                'data' => ['id' => $permissionId]
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to create permission', [
                'message' => $e->getMessage()
            ]);

            return $this->errorResponse($response, 'حدث خطأ أثناء معالجة الطلب', 500);
        }
    }

    public function updatePermission(Request $request, Response $response, array $args): Response
    {
        [, , $err] = $this->requireSuperAdminAccess($request, $response);
        if ($err) {
            return $err;
        }

        try {
            $permissionId = (int) ($args['id'] ?? 0);
            $data = $request->getParsedBody() ?? [];

            $updates = [];
            $params = [];

            if (isset($data['name'])) {
                $updates[] = "name = ?";
                $params[] = trim((string) $data['name']);
            }

            if (isset($data['description'])) {
                $updates[] = "description = ?";
                $params[] = $data['description'];
            }

            if (isset($data['category'])) {
                $updates[] = "category = ?";
                $params[] = trim((string) $data['category']);
            }

            if (!empty($updates)) {
                $params[] = $permissionId;

                $stmt = $this->db->prepare("
                    UPDATE permissions
                    SET " . implode(", ", $updates) . "
                    WHERE id = ?
                ");
                $stmt->execute($params);
            }

            return $this->jsonResponse($response, [
                'status' => 'success',
                'message' => 'تم تحديث الصلاحية بنجاح'
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to update permission', [
                'permission_id' => $args['id'] ?? null,
                'message' => $e->getMessage()
            ]);

            return $this->errorResponse($response, 'حدث خطأ أثناء معالجة الطلب', 500);
        }
    }

    public function deletePermission(Request $request, Response $response, array $args): Response
    {
        [, , $err] = $this->requireSuperAdminAccess($request, $response);
        if ($err) {
            return $err;
        }

        try {
            $permissionId = (int) ($args['id'] ?? 0);

            $stmt = $this->db->prepare("
                SELECT COUNT(*)
                FROM role_permissions
                WHERE permission_id = ?
            ");
            $stmt->execute([$permissionId]);

            if ((int) $stmt->fetchColumn() > 0) {
                return $this->errorResponse($response, 'لا يمكن حذف الصلاحية لأنها مرتبطة بواحد أو أكثر من الأدوار', 400);
            }

            $stmt = $this->db->prepare("
                DELETE FROM permissions
                WHERE id = ?
            ");
            $stmt->execute([$permissionId]);

            return $this->jsonResponse($response, [
                'status' => 'success',
                'message' => 'تم حذف الصلاحية بنجاح'
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to delete permission', [
                'permission_id' => $args['id'] ?? null,
                'message' => $e->getMessage()
            ]);

            return $this->errorResponse($response, 'حدث خطأ أثناء معالجة الطلب', 500);
        }
    }

    // Helper method to check if user has permission
    public function hasPermission($userId, $permissionName): bool
    {
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) AS has_permission
                FROM users u
                JOIN users_role ur ON u.id = ur.user_id
                JOIN role_permissions rp ON ur.role_id = rp.role_id
                JOIN permissions p ON rp.permission_id = p.id
                WHERE u.id = ? AND p.name = ?
            ");
            $stmt->execute([(int) $userId, (string) $permissionName]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return ((int) ($result['has_permission'] ?? 0)) > 0;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to check permission', [
                'user_id' => $userId,
                'permission_name' => $permissionName,
                'message' => $e->getMessage()
            ]);

            return false;
        }
    }
}
