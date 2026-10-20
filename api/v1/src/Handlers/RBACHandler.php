<?php

declare(strict_types=1);

namespace App\Handlers;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Services\MonologHandler;
use App\Utils\SuperAdminHelper;
use App\Security\TenantRoleAssignmentPolicy;
use App\Security\PermissionResolver;
use App\Services\UserSessionService;

/**
 * Handles tenant user/role administration and platform permission-catalog management.
 *
 * TenantRoleAssignmentPolicy validates assignable roles, PermissionResolver backs
 * permission checks, and the global permission catalog requires platform
 * super-admin access. Route registrations are in rbac.php and users.php.
 */
class RBACHandler extends BaseHandler
{
    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->logger = MonologHandler::getInstance('rbac');
    }

    /** Return the caller IDs or a 403 response when tenant-admin access is absent. */
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

    /** List users and their assigned roles for the current tenant. */
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

    /** Create a tenant user with validated role and branch assignments. */
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
                !isset($data['name'], $data['username'], $data['password'], $data['email']) ||
                trim((string) $data['name']) === '' ||
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
                SELECT id, is_owner
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
            $rolesList = TenantRoleAssignmentPolicy::validate(
                $this->db,
                $requestedRoles,
                (int) $tenantId
            );
            if ($rolesList === null) {
                return $this->errorResponse($response, 'دور واحد أو أكثر غير متاح لهذا المستأجر.', 403);
            }
            $primaryRoleId = $rolesList[0] ?? null;

            $branchId = null;
            if (isset($data['branch_id']) && $data['branch_id'] !== '') {
                if (filter_var($data['branch_id'], FILTER_VALIDATE_INT) === false || (int) $data['branch_id'] <= 0) {
                    return $this->errorResponse($response, 'الفرع المحدد غير صالح.', 400);
                }
                $branchId = (int) $data['branch_id'];
                $branchStmt = $this->db->prepare('SELECT id FROM branches WHERE id = ? AND tenant_id = ? LIMIT 1');
                $branchStmt->execute([$branchId, $tenantId]);
                if (!$branchStmt->fetch()) {
                    return $this->errorResponse($response, 'الفرع المحدد غير متاح لهذا المستأجر.', 403);
                }
            }

            $this->db->beginTransaction();

            $stmt = $this->db->prepare("
                INSERT INTO users (
                    name, username, password, email, phone, status, tenant_id, role_id, branch_id, created_by, is_owner
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0
                )
            ");
            $stmt->execute([
                trim((string) $data['name']),
                trim((string) $data['username']),
                password_hash((string) $data['password'], PASSWORD_DEFAULT),
                trim((string) $data['email']),
                isset($data['phone']) ? trim((string) $data['phone']) : null,
                $data['status'] ?? 'active',
                $tenantId,
                $primaryRoleId,
                $branchId,
                $this->extractUserId($request)
            ]);

            $userId    = (int) $this->db->lastInsertId();
            $createdBy = $this->extractUserId($request);

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

    /** Update a tenant user and optionally replace their roles or branch assignment. */
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
                $rolesList = TenantRoleAssignmentPolicy::validate(
                    $this->db,
                    array_values($data['roles']),
                    (int) $tenantId
                );
                if ($rolesList === null) {
                    return $this->errorResponse($response, 'دور واحد أو أكثر غير متاح لهذا المستأجر.', 403);
                }
                if ($rolesList === []) {
                    return $this->errorResponse($response, 'يجب إسناد دور واحد على الأقل للمستخدم.', 400);
                }
            }

            $branchId = null;
            if (array_key_exists('branch_id', $data) && $data['branch_id'] !== null && $data['branch_id'] !== '') {
                if (filter_var($data['branch_id'], FILTER_VALIDATE_INT) === false || (int) $data['branch_id'] <= 0) {
                    return $this->errorResponse($response, 'الفرع المحدد غير صالح.', 400);
                }
                $branchId = (int) $data['branch_id'];
                $branchStmt = $this->db->prepare('SELECT id FROM branches WHERE id = ? AND tenant_id = ? LIMIT 1');
                $branchStmt->execute([$branchId, $tenantId]);
                if (!$branchStmt->fetch()) {
                    return $this->errorResponse($response, 'الفرع المحدد غير متاح لهذا المستأجر.', 403);
                }
            }

            $stmt = $this->db->prepare("
                SELECT id, is_owner
                FROM users
                WHERE id = ? AND tenant_id = ?
                LIMIT 1
            ");
            $stmt->execute([$userId, $tenantId]);

            $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$targetUser || (int) $targetUser['is_owner'] === 1) {
                return $this->errorResponse($response, 'المستخدم غير موجود', 404);
            }

            $updates = [];
            $params = [];

            if (isset($data['name'])) {
                if (trim((string) $data['name']) === '') {
                    return $this->errorResponse($response, 'الاسم الكامل مطلوب.', 400);
                }
                $updates[] = 'name = ?';
                $params[] = trim((string) $data['name']);
            }

            $identityChecks = [];
            $identityParams = [];
            if (isset($data['username'])) {
                if (trim((string) $data['username']) === '') {
                    return $this->errorResponse($response, 'اسم المستخدم مطلوب.', 400);
                }
                $updates[] = 'username = ?';
                $params[] = trim((string) $data['username']);
                $identityChecks[] = 'username = ?';
                $identityParams[] = trim((string) $data['username']);
            }

            if (isset($data['email'])) {
                if (trim((string) $data['email']) === '' || !filter_var(trim((string) $data['email']), FILTER_VALIDATE_EMAIL)) {
                    return $this->errorResponse($response, 'صيغة البريد الإلكتروني غير صحيحة.', 400);
                }
                $updates[] = "email = ?";
                $params[] = trim((string) $data['email']);
                $identityChecks[] = 'email = ?';
                $identityParams[] = trim((string) $data['email']);
            }

            if ($identityChecks !== []) {
                $identityStmt = $this->db->prepare("
                    SELECT id
                    FROM users
                    WHERE id <> ? AND (" . implode(' OR ', $identityChecks) . ")
                    LIMIT 1
                ");
                $identityStmt->execute(array_merge([$userId], $identityParams));
                if ($identityStmt->fetch()) {
                    return $this->errorResponse($response, 'اسم المستخدم أو البريد الإلكتروني موجود بالفعل.', 400);
                }
            }

            if (array_key_exists('branch_id', $data)) {
                $updates[] = 'branch_id = ?';
                $params[] = $branchId;
            }

            if (isset($data['phone'])) {
                $updates[] = "phone = ?";
                $params[] = trim((string) $data['phone']);
            }

            if (isset($data['status'])) {
                $updates[] = "status = ?";
                $params[] = $data['status'];
            }

            $newPassword = isset($data['password']) && trim((string) $data['password']) !== ''
                ? (string) $data['password']
                : null;

            $this->db->beginTransaction();

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

            if ($newPassword !== null) {
                (new UserSessionService($this->db))->changePassword($userId, (int) $tenantId, $newPassword);
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

    /** Delete a tenant user, except the caller or the tenant owner. */
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

    /** List tenant roles and assignable shared system roles with their permissions. */
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
                LEFT JOIN role_permissions rp
                  ON r.id = rp.role_id AND (rp.tenant_id IS NULL OR rp.tenant_id = ?)
                LEFT JOIN permissions p
                  ON rp.permission_id = p.id AND (p.tenant_id IS NULL OR p.tenant_id = ?)
                WHERE (r.tenant_id = ? AND LOWER(r.name) <> 'administrator')
                   OR (
                       r.tenant_id IS NULL
                       AND (
                           (
                               r.is_system_role = 1
                               AND LOWER(r.name) NOT IN ('super_admin', 'admin', 'manager', 'branch_manager', 'finance_officer', 'administrator')
                           )
                           OR LOWER(r.name) IN ('manager', 'cashier', 'finance_officer', 'inventory_clerk')
                       )
                       AND LOWER(r.name) <> 'administrator'
                       AND NOT EXISTS (
                           SELECT 1
                           FROM roles tenant_role
                           WHERE tenant_role.tenant_id = ?
                             AND LOWER(tenant_role.name) = LOWER(r.name)
                       )
                   )
                GROUP BY r.id
                ORDER BY COALESCE(r.tenant_id, 0) DESC, r.name
            ");
            $stmt->execute([$tenantId, $tenantId, $tenantId, $tenantId]);
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

    /** Resolve a user's effective permission; errors are logged and denied. */
    public function hasPermission($userId, $permissionName): bool
    {
        try {
            return PermissionResolver::hasPermission(
                $this->db,
                (int) $userId,
                (string) $permissionName
            );
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
