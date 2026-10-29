<?php

declare(strict_types=1);

namespace App\Handlers;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Services\MonologHandler;
use App\Utils\SuperAdminHelper;
use App\Utils\PaginationHelper;
use App\Security\TenantRoleAssignmentPolicy;
use App\Security\PermissionResolver;
use App\Security\Permissions;
use App\Services\UserSessionService;
use App\Utils\RequestHelper;

/**
 * Handles tenant user/role administration and platform permission-catalog management.
 *
 * TenantRoleAssignmentPolicy validates assignable roles, PermissionResolver backs
 * permission checks. Role and user permission overrides are tenant-scoped and
 * route registrations are in rbac.php and users.php.
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
            // ── Pagination ──────────────────────────────────────────────
            $query = $request->getQueryParams();
            [$page, $limit, $offset] = PaginationHelper::fromArray($query, 20, 100);

            // ── Filters ──────────────────────────────────────────────────
            $search = trim((string) ($query['search'] ?? ''));
            $roleFilter = trim((string) ($query['role_id'] ?? ''));
            $statusFilter = trim((string) ($query['status'] ?? ''));

            // ── Build WHERE clause ───────────────────────────────────────
            $where = "WHERE u.tenant_id = ?";
            $params = [$tenantId];

            if ($search) {
                $where .= " AND (u.name LIKE ? OR u.username LIKE ? OR u.email LIKE ?)";
                $searchParam = "%{$search}%";
                $params[] = $searchParam;
                $params[] = $searchParam;
                $params[] = $searchParam;
            }

            if ($statusFilter && in_array($statusFilter, ['active', 'inactive'], true)) {
                $where .= " AND u.status = ?";
                $params[] = $statusFilter;
            }

            // ── Count total (before GROUP BY, count distinct user ids) ───
            $countSql = "SELECT COUNT(DISTINCT u.id) FROM users u {$where}";
            $countStmt = $this->db->prepare($countSql);
            $countStmt->execute($params);
            $total = (int) $countStmt->fetchColumn();

            // ── Data query with role filter applied after GROUP BY ───────
            $dataParams = $params;
            $dataParams[] = $limit;
            $dataParams[] = $offset;

            $havingClause = '';
            if ($roleFilter) {
                $havingClause = "HAVING FIND_IN_SET(?, role_ids) > 0";
                // Insert role filter param before limit/offset
                array_splice($dataParams, -2, 0, [$roleFilter]);
            }

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
                    u.is_owner,
                    u.last_login,
                    GROUP_CONCAT(DISTINCT r.id   ORDER BY r.name SEPARATOR ',') AS role_ids,
                    GROUP_CONCAT(DISTINCT r.name ORDER BY r.name SEPARATOR ', ') AS roles,
                    b.name AS branch_name
                FROM users u
                LEFT JOIN users_role ur ON u.id = ur.user_id
                LEFT JOIN roles r       ON ur.role_id = r.id
                LEFT JOIN branches b   ON u.branch_id = b.id AND b.tenant_id = u.tenant_id
                {$where}
                GROUP BY u.id, u.name, u.username, u.email, u.phone, u.status, u.role_id, u.branch_id, u.is_owner, u.last_login, b.name
                {$havingClause}
                ORDER BY u.id ASC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute($dataParams);
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            return $this->successResponse($response, [
                'items'       => $users,
                'total'       => $total,
                'page'        => $page,
                'limit'       => $limit,
                'total_pages' => (int) ceil($total / $limit),
            ], 200);
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
                               AND LOWER(r.name) NOT IN ('super_admin', 'manager', 'branch_manager', 'finance_officer', 'administrator')
                           )
                           OR LOWER(r.name) IN ('admin', 'manager', 'cashier', 'finance_officer', 'inventory_clerk')
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
                ORDER BY COALESCE(r.sort_order, 1000), COALESCE(r.tenant_id, 0) DESC, r.name
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

    /** List the active permission catalog that can be configured by this tenant. */
    public function getPermissionCatalog(Request $request, Response $response): Response
    {
        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
        }

        try {
            return $this->successResponse(
                $response,
                array_values($this->permissionCatalogMap((int) $tenantId)),
                200
            );
        } catch (\Throwable $e) {
            $this->logger->error('Failed to get permission catalog', [
                'tenant_id' => $tenantId,
                'message' => $e->getMessage(),
            ]);
            return $this->errorResponse($response, 'تعذر تحميل قائمة الصلاحيات.', 500);
        }
    }

    /** Get a role's effective permission set for the current tenant. */
    public function getRolePermissions(Request $request, Response $response, array $args): Response
    {
        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
        }

        $role = $this->findAssignableRole((int) ($args['id'] ?? 0), (int) $tenantId);
        if (!$role) {
            return $this->errorResponse($response, 'الدور غير موجود أو غير متاح لهذا المستأجر.', 404);
        }

        try {
            return $this->successResponse($response, [
                'role' => $role,
                'permissions' => PermissionResolver::getRoleGrantedPermissionNames(
                    $this->db,
                    (int) $tenantId,
                    (int) $role['id']
                ),
                'base_permissions' => $this->getBaselineRolePermissions(
                    (int) $role['id'],
                    (int) $tenantId
                ),
            ], 200);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to get role permissions', [
                'tenant_id' => $tenantId,
                'role_id' => $role['id'],
                'message' => $e->getMessage(),
            ]);
            return $this->errorResponse($response, 'تعذر تحميل صلاحيات الدور.', 500);
        }
    }

    /** Replace a tenant's role-specific permission overrides, never shared templates. */
    public function updateRolePermissions(Request $request, Response $response, array $args): Response
    {
        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
        }

        $callerId = $this->extractUserId($request);
        $role = $this->findAssignableRole((int) ($args['id'] ?? 0), (int) $tenantId);
        if (!$callerId || !$role) {
            return $this->errorResponse($response, 'الدور غير موجود أو غير متاح لهذا المستأجر.', 404);
        }

        $data = $request->getParsedBody() ?? [];
        $permissionNames = $data['permissions'] ?? null;
        $reason = trim((string) ($data['reason'] ?? ''));
        if (!is_array($permissionNames) || $reason === '' || mb_strlen($reason) > 500) {
            return $this->errorResponse($response, 'حدد الصلاحيات واحفظ سبب التعديل (بحد أقصى 500 حرف).', 400);
        }
        if (array_filter($permissionNames, static fn($name) => !is_string($name)) !== []) {
            return $this->errorResponse($response, 'قائمة الصلاحيات غير صالحة.', 400);
        }
        $permissionNames = array_values(array_unique($permissionNames));

        try {
            $catalog = $this->permissionCatalogMap((int) $tenantId);
            if (array_diff($permissionNames, array_keys($catalog)) !== []) {
                return $this->errorResponse($response, 'تتضمن القائمة صلاحية غير متاحة لهذا المستأجر.', 400);
            }
            $callerPermissions = $this->getCallerAssignablePermissions((int) $callerId, $catalog);
            $protectedPermissions = Permissions::rbacManagement();
            $requestedGrants = array_values(array_diff($permissionNames, $protectedPermissions));
            $current = PermissionResolver::getRoleGrantedPermissionNames(
                $this->db,
                (int) $tenantId,
                (int) $role['id']
            );
            $newGrants = array_diff($requestedGrants, $current);
            if (array_diff($newGrants, $callerPermissions) !== []) {
                return $this->errorResponse($response, 'لا يمكنك إسناد صلاحية لا تملكها.', 403);
            }

            $baseline = $this->getBaselineRolePermissions((int) $role['id'], (int) $tenantId);
            $desired = array_values(array_unique(array_merge(
                array_values(array_intersect($permissionNames, array_keys($catalog))),
                array_values(array_intersect($current, $protectedPermissions))
            )));
            $changes = $this->permissionSetChanges($current, $desired, array_keys($catalog));

            $this->db->beginTransaction();
            $delete = $this->db->prepare(
                "DELETE FROM permission_overrides
                 WHERE tenant_id = ? AND subject_type = 'role' AND subject_id = ?
                   AND permission_id NOT IN (
                       SELECT id FROM permissions WHERE name IN (" .
                       implode(',', array_fill(0, count($protectedPermissions), '?')) .
                   '))'
            );
            $delete->execute(array_merge([(int) $tenantId, (int) $role['id']], $protectedPermissions));

            $insert = $this->db->prepare(
                "INSERT INTO permission_overrides
                    (tenant_id, subject_type, subject_id, permission_id, effect, reason, created_by)
                 VALUES (?, 'role', ?, ?, ?, ?, ?)"
            );
            foreach (array_keys($catalog) as $name) {
                if (in_array($name, $protectedPermissions, true)) {
                    continue;
                }
                $wanted = in_array($name, $desired, true);
                $hasBase = in_array($name, $baseline, true);
                if ($wanted !== $hasBase) {
                    $insert->execute([
                        (int) $tenantId,
                        (int) $role['id'],
                        (int) $catalog[$name]['id'],
                        $wanted ? 'allow' : 'deny',
                        $reason,
                        (int) $callerId,
                    ]);
                }
            }
            $this->writePermissionAudit(
                (int) $tenantId,
                (int) $callerId,
                'role_permissions_updated',
                'roles',
                (int) $role['id'],
                ['role' => $role['name'], 'reason' => $reason, 'changes' => $changes],
                $request
            );
            $this->db->commit();

            return $this->successResponse($response, [
                'role' => $role,
                'permissions' => $desired,
            ], 200);
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logger->error('Failed to update role permissions', [
                'tenant_id' => $tenantId,
                'role_id' => $role['id'],
                'message' => $e->getMessage(),
            ]);
            return $this->errorResponse($response, 'تعذر حفظ صلاحيات الدور.', 500);
        }
    }

    /** Get role-derived permissions and user-specific overrides for one user. */
    public function getUserPermissions(Request $request, Response $response, array $args): Response
    {
        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
        }

        $userId = (int) ($args['id'] ?? 0);
        $target = $this->findAssignableUser($userId, (int) $tenantId);
        if (!$target) {
            return $this->errorResponse($response, 'المستخدم غير موجود أو لا يمكن تخصيص صلاحياته.', 404);
        }

        try {
            $catalog = $this->permissionCatalogMap((int) $tenantId);
            $rolePermissions = PermissionResolver::getGrantedPermissionNames($this->db, $userId, false);
            $overrides = $this->getUserPermissionOverrides((int) $tenantId, $userId);
            $effective = array_fill_keys($rolePermissions, true);
            foreach ($overrides as $name => $override) {
                if ($override['effect'] === 'allow') {
                    $effective[$name] = true;
                } else {
                    unset($effective[$name]);
                }
            }

            $permissions = [];
            foreach ($catalog as $name => $permission) {
                $override = $overrides[$name] ?? null;
                $permissions[] = $permission + [
                    'role_granted' => in_array($name, $rolePermissions, true),
                    'override' => $override,
                    'effective' => isset($effective[$name]),
                    'source' => $override
                        ? 'user_override'
                        : (in_array($name, $rolePermissions, true) ? 'role' : 'none'),
                ];
            }

            return $this->successResponse($response, [
                'user' => ['id' => $userId, 'name' => $target['name']],
                'permissions' => $permissions,
            ], 200);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to get user permissions', [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'message' => $e->getMessage(),
            ]);
            return $this->errorResponse($response, 'تعذر تحميل تخصيصات المستخدم.', 500);
        }
    }

    /** Replace a user's explicit allow/deny overrides; role grants remain unchanged. */
    public function updateUserPermissions(Request $request, Response $response, array $args): Response
    {
        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
        }

        $callerId = $this->extractUserId($request);
        $userId = (int) ($args['id'] ?? 0);
        $target = $this->findAssignableUser($userId, (int) $tenantId);
        if (!$callerId || !$target) {
            return $this->errorResponse($response, 'المستخدم غير موجود أو لا يمكن تخصيص صلاحياته.', 404);
        }
        if ($callerId === $userId) {
            return $this->errorResponse($response, 'لا يمكن تخصيص صلاحيات حسابك بنفسك.', 403);
        }

        $data = $request->getParsedBody() ?? [];
        $requested = $data['overrides'] ?? null;
        $reason = trim((string) ($data['reason'] ?? ''));
        if (!is_array($requested) || $reason === '' || mb_strlen($reason) > 500) {
            return $this->errorResponse($response, 'حدد الاستثناءات واحفظ سبب التعديل (بحد أقصى 500 حرف).', 400);
        }

        try {
            $catalog = $this->permissionCatalogMap((int) $tenantId);
            $callerPermissions = $this->getCallerAssignablePermissions((int) $callerId, $catalog);
            $before = $this->getUserPermissionOverrides((int) $tenantId, $userId);
            $normalized = [];
            foreach ($requested as $item) {
                if (!is_array($item) || !isset($item['permission'], $item['effect'])) {
                    return $this->errorResponse($response, 'صيغة استثناء الصلاحية غير صالحة.', 400);
                }
                $name = (string) $item['permission'];
                $effect = (string) $item['effect'];
                if (
                    !isset($catalog[$name]) ||
                    in_array($name, Permissions::rbacManagement(), true) ||
                    !in_array($effect, ['allow', 'deny'], true) ||
                    isset($normalized[$name])
                ) {
                    return $this->errorResponse($response, 'تتضمن الاستثناءات قيمة غير مسموح بها أو مكررة.', 400);
                }
                if (
                    $effect === 'allow' &&
                    !in_array($name, $callerPermissions, true) &&
                    ($before[$name]['effect'] ?? null) !== 'allow'
                ) {
                    return $this->errorResponse($response, 'لا يمكنك منح المستخدم صلاحية لا تملكها.', 403);
                }
                $normalized[$name] = $effect;
            }

            $this->db->beginTransaction();
            $delete = $this->db->prepare(
                "DELETE FROM permission_overrides
                 WHERE tenant_id = ? AND subject_type = 'user' AND subject_id = ?"
            );
            $delete->execute([(int) $tenantId, $userId]);

            $insert = $this->db->prepare(
                "INSERT INTO permission_overrides
                    (tenant_id, subject_type, subject_id, permission_id, effect, reason, created_by)
                 VALUES (?, 'user', ?, ?, ?, ?, ?)"
            );
            foreach ($normalized as $name => $effect) {
                $insert->execute([
                    (int) $tenantId,
                    $userId,
                    (int) $catalog[$name]['id'],
                    $effect,
                    $reason,
                    (int) $callerId,
                ]);
            }

            $this->writePermissionAudit(
                (int) $tenantId,
                (int) $callerId,
                'user_permissions_updated',
                'users',
                $userId,
                ['user' => $target['name'], 'reason' => $reason, 'before' => $before, 'after' => $normalized],
                $request
            );
            $this->db->commit();

            return $this->successResponse($response, [
                'user_id' => $userId,
                'overrides' => $normalized,
            ], 200);
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logger->error('Failed to update user permission overrides', [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'message' => $e->getMessage(),
            ]);
            return $this->errorResponse($response, 'تعذر حفظ تخصيصات المستخدم.', 500);
        }
    }

    private function findAssignableRole(int $roleId, int $tenantId): ?array
    {
        if ($roleId <= 0) {
            return null;
        }
        $stmt = $this->db->prepare(
            "SELECT id, name, display_name, description, tenant_id
             FROM roles
             WHERE id = ?
               AND LOWER(name) NOT IN ('super_admin', 'administrator')
               AND (
                   tenant_id = ?
                   OR (
                       tenant_id IS NULL
                       AND LOWER(name) IN ('admin', 'manager', 'cashier', 'inventory_clerk', 'finance_officer')
                   )
               )
             LIMIT 1"
        );
        $stmt->execute([$roleId, $tenantId]);
        $role = $stmt->fetch(PDO::FETCH_ASSOC);
        return $role ?: null;
    }

    private function findAssignableUser(int $userId, int $tenantId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT u.id, u.name
             FROM users u
             WHERE u.id = ? AND u.tenant_id = ? AND u.is_owner = 0
               AND NOT EXISTS (
                   SELECT 1 FROM users_role ur JOIN roles r ON r.id = ur.role_id
                   WHERE ur.user_id = u.id AND LOWER(r.name) IN ('super_admin', 'administrator')
               )
               AND NOT EXISTS (
                   SELECT 1 FROM roles r
                   WHERE r.id = u.role_id AND LOWER(r.name) IN ('super_admin', 'administrator')
               )
             LIMIT 1"
        );
        $stmt->execute([$userId, $tenantId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        return $user ?: null;
    }

    private function permissionCatalogMap(int $tenantId): array
    {
        $hasDisplayName = $this->hasPermissionCatalogColumn('display_name');
        $hasActiveFlag = $this->hasPermissionCatalogColumn('is_active');
        $displayName = $hasDisplayName ? 'display_name' : 'NULL AS display_name';
        $activeFilter = $hasActiveFlag ? 'AND is_active = 1' : '';
        $stmt = $this->db->prepare(
            "SELECT id, name, {$displayName}, description, category
             FROM permissions
             WHERE 1 = 1 {$activeFilter}
               AND (tenant_id IS NULL OR tenant_id = ?)
               AND name NOT LIKE 'platform.%'
             ORDER BY category, name"
        );
        $stmt->execute([$tenantId]);
        $permissions = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $permission) {
            $permissions[(string) $permission['name']] = $permission;
        }
        return $permissions;
    }

    private function hasPermissionCatalogColumn(string $column): bool
    {
        if (!in_array($column, ['display_name', 'is_active'], true)) {
            throw new \InvalidArgumentException('Unsupported permission catalog column.');
        }
        $stmt = $this->db->prepare(
            'SELECT 1 FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
        );
        $stmt->execute(['permissions', $column]);
        return $stmt->fetchColumn() !== false;
    }

    private function getCallerAssignablePermissions(
        int $callerId,
        array $catalog
    ): array
    {
        $user = $this->currentUser;
        if (SuperAdminHelper::is(is_array($user) ? $user : null)) {
            return array_keys($catalog);
        }
        return PermissionResolver::getGrantedPermissionNames($this->db, $callerId);
    }

    private function getBaselineRolePermissions(int $roleId, int $tenantId): array
    {
        $activeFilter = $this->hasPermissionCatalogColumn('is_active') ? 'AND p.is_active = 1' : '';
        $stmt = $this->db->prepare(
            'SELECT DISTINCT p.name
             FROM role_permissions rp
             JOIN permissions p ON p.id = rp.permission_id
             WHERE rp.role_id = ?
               AND (rp.tenant_id IS NULL OR rp.tenant_id = ?)
               AND (p.tenant_id IS NULL OR p.tenant_id = ?)
               ' . $activeFilter
        );
        $stmt->execute([$roleId, $tenantId, $tenantId]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    private function getUserPermissionOverrides(int $tenantId, int $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT p.name, po.effect, po.reason, po.created_by, po.updated_at
             FROM permission_overrides po
             JOIN permissions p ON p.id = po.permission_id
             WHERE po.tenant_id = ? AND po.subject_type = 'user' AND po.subject_id = ?
               AND (p.tenant_id IS NULL OR p.tenant_id = ?)
             ORDER BY p.name"
        );
        $stmt->execute([$tenantId, $userId, $tenantId]);
        $overrides = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $override) {
            $overrides[(string) $override['name']] = [
                'effect' => (string) $override['effect'],
                'reason' => (string) $override['reason'],
                'created_by' => (int) $override['created_by'],
                'updated_at' => $override['updated_at'],
            ];
        }
        return $overrides;
    }

    private function permissionSetChanges(array $before, array $after, array $catalogNames): array
    {
        $changes = [];
        foreach ($catalogNames as $name) {
            $wasGranted = in_array($name, $before, true);
            $isGranted = in_array($name, $after, true);
            if ($wasGranted !== $isGranted) {
                $changes[$name] = $isGranted ? 'allow' : 'deny';
            }
        }
        return $changes;
    }

    private function writePermissionAudit(
        int $tenantId,
        int $userId,
        string $action,
        string $entity,
        int $entityId,
        array $details,
        Request $request
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO audit_log (user_id, action, entity, entity_id, details, tenant_id, ip_address, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $userId,
            $action,
            $entity,
            $entityId,
            json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $tenantId,
            RequestHelper::getClientIp($request),
        ]);
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
