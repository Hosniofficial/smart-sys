<?php

namespace App\Handlers;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use App\Services\SecurityLogger;
use App\Services\SecurityEventDispatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use PDO;
use App\Services\MonologHandler;
use App\Utils\SuperAdminHelper;

class UsersHandler extends BaseHandler
{
    private ?SecurityLogger $securityLogger = null;
    private ?SecurityEventDispatcher $eventDispatcher = null;

    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->logger = MonologHandler::getInstance('users');
    }

    public function setSecurityServices(
        ?SecurityLogger $securityLogger = null,
        ?SecurityEventDispatcher $eventDispatcher = null
    ): void {
        $this->securityLogger = $securityLogger;
        $this->eventDispatcher = $eventDispatcher;
    }

    public function list($request, $response)
    {
        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            $this->logger->warning('Users list - missing tenant ID');
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
        }

        $q = $request->getQueryParams();
        $page = max(1, (int) ($q['page'] ?? 1));
        $limit = min(1000, max(1, (int) ($q['limit'] ?? 10)));
        $search = $q['search'] ?? '';
        $roleId = $q['role_id'] ?? null;
        $offset = ($page - 1) * $limit;

        $this->logger->info('Users list request', [
            'tenant_id' => $tenantId,
            'page' => $page,
            'limit' => $limit,
            'search' => $search,
            'role_id' => $roleId
        ]);

        // Explicit safe fields only — never SELECT * on users table
        $query = "SELECT
                    u.id,
                    u.username,
                    u.name,
                    u.email,
                    u.phone,
                    u.role_id,
                    u.status,
                    u.branch_id,
                    u.is_owner,
                    u.two_fa_enabled,
                    u.last_login,
                    u.email_verified_at,
                    u.created_at,
                    u.updated_at,
                    GROUP_CONCAT(r.name) as roles
                  FROM users u
                  LEFT JOIN users_role ur ON u.id = ur.user_id
                  LEFT JOIN roles r ON ur.role_id = r.id
                  WHERE u.tenant_id = ?";
        $params = [$tenantId];

        if ($search) {
            $query .= " AND (u.name LIKE ? OR u.email LIKE ? OR u.username LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";

            $this->logger->debug('Applying search filter', [
                'tenant_id' => $tenantId,
                'search' => $search
            ]);
        }

        if ($roleId) {
            $query .= " AND EXISTS (
                SELECT 1 FROM users_role ur2
                WHERE ur2.user_id = u.id AND ur2.role_id = ?
            )";
            $params[] = $roleId;

            $this->logger->debug('Applying role filter', [
                'tenant_id' => $tenantId,
                'role_id' => $roleId
            ]);
        }

        $query .= " GROUP BY u.id LIMIT ? OFFSET ?";
        $params[] = (int) $limit;
        $params[] = (int) $offset;

        $this->logger->debug('Executing users query', [
            'tenant_id' => $tenantId,
            'limit' => $limit,
            'offset' => $offset,
            'params_count' => count($params)
        ]);

        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $countParams = [$tenantId];
        $countQuery = "SELECT COUNT(DISTINCT u.id) FROM users u WHERE u.tenant_id = ?";

        if ($search) {
            $countQuery .= " AND (u.name LIKE ? OR u.email LIKE ? OR u.username LIKE ?)";
            $countParams[] = "%$search%";
            $countParams[] = "%$search%";
            $countParams[] = "%$search%";
        }

        if ($roleId) {
            $countQuery .= " AND EXISTS (
                SELECT 1 FROM users_role ur2
                WHERE ur2.user_id = u.id AND ur2.role_id = ?
            )";
            $countParams[] = $roleId;
        }

        $this->logger->debug('Executing users count query', [
            'tenant_id' => $tenantId,
            'search' => $search,
            'role_id' => $roleId
        ]);

        $stmt = $this->db->prepare($countQuery);
        $stmt->execute($countParams);
        $total = $stmt->fetchColumn();

        $this->logger->info('Users retrieved successfully', [
            'tenant_id' => $tenantId,
            'count' => count($users),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'search' => $search,
            'role_id' => $roleId
        ]);

        return $this->successResponse($response, [
            'status' => 'success',
            'message' => '',
            'data' => [
                'items' => $users,
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'total_pages' => ceil($total / $limit)
            ]
        ], 200);
    }

    public function get($request, $response, $id)
    {
        // Defensive check: $id should be a single integer, not an array
        if (is_array($id)) {
            return $this->errorResponse($response, 'Invalid user ID format', 400);
        }

        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
        }

        $id = (int) $id;
        if ($id <= 0) {
            return $this->errorResponse($response, 'معرف المستخدم غير صحيح', 400);
        }

        $stmt = $this->db->prepare("
            SELECT
                u.id,
                u.username,
                u.name,
                u.email,
                u.phone,
                u.role_id,
                u.status,
                u.branch_id,
                u.is_owner,
                u.two_fa_enabled,
                u.last_login,
                u.email_verified_at,
                u.created_at,
                u.updated_at,
                GROUP_CONCAT(r.name) as roles
            FROM users u
            LEFT JOIN users_role ur ON u.id = ur.user_id
            LEFT JOIN roles r ON ur.role_id = r.id
            WHERE u.id = ? AND u.tenant_id = ?
            GROUP BY u.id
        ");
        $stmt->execute([$id, $tenantId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return $this->errorResponse($response, 'المستخدم غير موجود', 400);
        }

        $stmt = $this->db->prepare("
            SELECT DISTINCT p.id, p.name, p.description, p.category
            FROM permissions p
            JOIN role_permissions rp ON p.id = rp.permission_id
            JOIN users_role ur ON rp.role_id = ur.role_id
            WHERE ur.user_id = ?
        ");
        $stmt->execute([$id]);
        $user['permissions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $this->successResponse($response, [
            'status' => 'success',
            'message' => '',
            'data' => $user
        ], 200);
    }

    public function create($request, $response)
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $data = $this->extractAndValidateRequestData($request, [
                'name',
                'username',
                'email',
                'password',
                'roles'
            ]);

            if (!is_array($data)) {
                return $this->errorResponse($response, 'Invalid request data', 400);
            }

            if (isset($data['name']) && is_array($data['name'])) {
                $data['name'] = reset($data['name']);
            }
            if (isset($data['username']) && is_array($data['username'])) {
                $data['username'] = reset($data['username']);
            }
            if (isset($data['email']) && is_array($data['email'])) {
                $data['email'] = reset($data['email']);
            }

            $data['name'] = isset($data['name']) ? trim((string) $data['name']) : '';
            $data['username'] = isset($data['username']) ? trim((string) $data['username']) : '';
            $data['email'] = isset($data['email']) ? strtolower(trim((string) $data['email'])) : '';

            if (isset($data['roles'])) {
                if (!is_array($data['roles'])) {
                    $data['roles'] = [$data['roles']];
                }

                $data['roles'] = array_values(array_filter(array_map(function ($r) {
                    if (is_array($r) && isset($r['id'])) {
                        return (int) $r['id'];
                    }
                    if (is_object($r) && isset($r->id)) {
                        return (int) $r->id;
                    }
                    return is_numeric($r) ? (int) $r : null;
                }, $data['roles']), function ($v) {
                    return $v !== null;
                }));
            }

            $currentUser = $request->getAttribute('user');
            $currentUserRoleId = is_array($currentUser) ? ($currentUser['role_id'] ?? null) : null;

            if (isset($data['roles']) && !empty($data['roles']) && $currentUserRoleId !== null) {
                $minAssignedRoleId = min($data['roles']);
                if ($minAssignedRoleId < $currentUserRoleId) {
                    return $this->errorResponse($response, 'غير مسموح: لا يمكنك تعيين صلاحيات أعلى من صلاحيتك الخاصة', 403);
                }
            }

            if (isset($data['roles']) && in_array(1, $data['roles'])) {
                if (!SuperAdminHelper::is(is_array($currentUser) ? $currentUser : null)) {
                    return $this->errorResponse($response, 'غير مسموح: فقط مدير النظام الرئيسي يمكنه تعيين صلاحية super_admin', 403);
                }
            }

            $branchId = null;
            if (isset($data['branch_id'])) {
                $w = $data['branch_id'];
                if (is_array($w)) {
                    $w = reset($w);
                }
                if (is_object($w) && isset($w->id)) {
                    $w = $w->id;
                }
                $branchId = is_numeric($w) ? (int) $w : null;
            }

            $this->db->beginTransaction();

            $stmt = $this->db->prepare("
                SELECT COUNT(*)
                FROM users
                WHERE (username = ? OR email = ?) AND tenant_id = ?
            ");
            $dupUsername = isset($data['username']) ? (string) $data['username'] : '';
            $dupEmail = isset($data['email']) ? (string) $data['email'] : '';
            $dupTenant = is_numeric($tenantId) ? (int) $tenantId : (string) $tenantId;
            $stmt->execute([$dupUsername, $dupEmail, $dupTenant]);

            if ($stmt->fetchColumn() > 0) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                return $this->errorResponse($response, 'اسم المستخدم أو البريد الإلكتروني موجود بالفعل', 400);
            }

            $stmt = $this->db->prepare("
                INSERT INTO users (
                    name, username, email, password,
                    status, created_by, created_at, tenant_id,
                    role_id, branch_id, is_owner
                )
                VALUES (?, ?, ?, ?, 'active', ?, NOW(), ?, ?, ?, 0)
            ");

            $primaryRoleId = !empty($data['roles']) ? (int) $data['roles'][0] : null;

            $stmt->execute([
                $data['name'],
                $data['username'],
                $data['email'],
                password_hash($data['password'], PASSWORD_DEFAULT),
                $this->extractUserId($request) ?? 1,
                $tenantId,
                $primaryRoleId,
                $branchId
            ]);

            $userId    = $this->db->lastInsertId();
            $createdBy = $this->extractUserId($request) ?? 1;

            // Ensure users_role is always populated
            $rolesToInsert = !empty($data['roles']) ? $data['roles'] : ($primaryRoleId ? [$primaryRoleId] : []);
            if (!empty($rolesToInsert)) {
                $insertRoleStmt = $this->db->prepare("
                    INSERT IGNORE INTO users_role (user_id, role_id, created_by, tenant_id)
                    VALUES (?, ?, ?, ?)
                ");
                foreach ($rolesToInsert as $rId) {
                    $insertRoleStmt->execute([$userId, (int) $rId, $createdBy, $tenantId]);
                }
            }

            $this->db->commit();

            $this->securityLogger?->logSecurityEvent(
                'user.created',
                'info',
                'User created',
                [
                    'created_user_id' => $userId,
                    'username' => $data['username'],
                    'email' => $data['email'],
                    'roles' => $data['roles']
                ],
                $this->extractUserId($request),
                $tenantId
            );

            return $this->successResponse($response, [
                'status' => 'success',
                'message' => 'تم إنشاء المستخدم بنجاح',
                'data' => ['id' => $userId]
            ], 201);
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logger->error('فشل في إنشاء المستخدم', ['error' => $e->getMessage()]);
            return $this->errorResponse($response, 'فشل في إنشاء المستخدم. قد يكون البريد أو اسم المستخدم مستخدماً.', 400);
        }
    }

    public function update($request, $response, $id)
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $idParam = $id;
            if (is_array($idParam)) {
                if (isset($idParam['id'])) {
                    $idParam = $idParam['id'];
                } else {
                    $idParam = reset($idParam);
                }
            }

            $data = $this->extractAndValidateRequestData($request, [
                'name',
                'username',
                'email',
                'roles'
            ]);

            if (!is_array($data)) {
                return $this->errorResponse($response, 'Invalid request data', 400);
            }

            if (isset($data['name']) && is_array($data['name'])) {
                $data['name'] = reset($data['name']);
            }
            if (isset($data['username']) && is_array($data['username'])) {
                $data['username'] = reset($data['username']);
            }
            if (isset($data['email']) && is_array($data['email'])) {
                $data['email'] = reset($data['email']);
            }

            if (isset($data['roles'])) {
                if (!is_array($data['roles'])) {
                    $data['roles'] = [$data['roles']];
                }
                $data['roles'] = array_values(array_filter(array_map(function ($r) {
                    if (is_array($r) && isset($r['id'])) {
                        return (int) $r['id'];
                    }
                    if (is_object($r) && isset($r->id)) {
                        return (int) $r->id;
                    }
                    return is_numeric($r) ? (int) $r : null;
                }, $data['roles']), function ($v) {
                    return $v !== null;
                }));
            }

            $currentUser = $request->getAttribute('user');
            $currentUserRoleId = is_array($currentUser) ? ($currentUser['role_id'] ?? null) : null;

            if (isset($data['roles']) && !empty($data['roles']) && $currentUserRoleId !== null) {
                $minAssignedRoleId = min($data['roles']);
                if ($minAssignedRoleId < $currentUserRoleId) {
                    return $this->errorResponse($response, 'غير مسموح: لا يمكنك تعيين صلاحيات أعلى من صلاحيتك الخاصة', 400);
                }
            }

            if (isset($data['roles']) && in_array(1, $data['roles'])) {
                if (!SuperAdminHelper::is(is_array($currentUser) ? $currentUser : null)) {
                    return $this->errorResponse($response, 'غير مسموح: فقط مدير النظام الرئيسي يمكنه تعيين صلاحية super_admin', 400);
                }
            }

            $branchId = null;
            if (isset($data['branch_id'])) {
                $w = $data['branch_id'];
                if (is_array($w)) {
                    $w = reset($w);
                }
                if (is_object($w) && isset($w->id)) {
                    $w = $w->id;
                }
                $branchId = is_numeric($w) ? (int) $w : null;
            }

            $primaryRoleId = !empty($data['roles']) ? (int) $data['roles'][0] : null;

            $stmt = $this->db->prepare("
                SELECT role_id
                FROM users
                WHERE id = ? AND tenant_id = ?
            ");
            $stmt->execute([is_numeric($idParam) ? (int) $idParam : (string) $idParam, $tenantId]);
            $targetUserRoleId = $stmt->fetchColumn();

            if (SuperAdminHelper::is(['role_id' => $targetUserRoleId]) && !SuperAdminHelper::is(is_array($currentUser) ? $currentUser : null)) {
                return $this->errorResponse($response, 'غير مسموح: فقط مدير النظام الرئيسي يمكنه تعديل حسابات super_admin', 400);
            }

            $this->db->beginTransaction();

            $stmt = $this->db->prepare("
                SELECT COUNT(*)
                FROM users
                WHERE (username = ? OR email = ?) AND id != ? AND tenant_id = ?
            ");
            $dupUsername = isset($data['username']) ? (string) $data['username'] : '';
            $dupEmail = isset($data['email']) ? (string) $data['email'] : '';
            $dupId = is_numeric($idParam) ? (int) $idParam : (string) $idParam;
            $dupTenant = is_numeric($tenantId) ? (int) $tenantId : (string) $tenantId;
            $stmt->execute([$dupUsername, $dupEmail, $dupId, $dupTenant]);

            if ($stmt->fetchColumn() > 0) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                return $this->errorResponse($response, 'اسم المستخدم أو البريد الإلكتروني موجود بالفعل', 400);
            }

            $query = "
                UPDATE users
                SET name = ?,
                    username = ?,
                    email = ?,
                    role_id = ?,
                    branch_id = ?,
                    updated_at = NOW()
            ";

            $params = [
                $data['name'],
                $data['username'],
                $data['email'],
                $primaryRoleId,
                $branchId
            ];

            if (!empty($data['password'])) {
                $query .= ", password = ?";
                $params[] = password_hash($data['password'], PASSWORD_DEFAULT);
            }

            $query .= " WHERE id = ? AND tenant_id = ?";
            $params[] = is_numeric($idParam) ? (int) $idParam : (string) $idParam;
            $params[] = $tenantId;

            $stmt = $this->db->prepare($query);
            $stmt->execute($params);

            $stmt = $this->db->prepare("DELETE FROM users_role WHERE user_id = ?");
            $stmt->execute([is_numeric($idParam) ? (int) $idParam : (string) $idParam]);

            $stmt = $this->db->prepare("
                INSERT INTO users_role (user_id, role_id)
                VALUES (?, ?)
            ");
            foreach ($data['roles'] as $roleId) {
                $stmt->execute([is_numeric($idParam) ? (int) $idParam : (string) $idParam, $roleId]);
            }

            $this->db->commit();

            return $this->successResponse($response, ['data' => null]);
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logger->error('فشل في تحديث المستخدم', ['error' => $e->getMessage()]);
            return $this->errorResponse($response, 'فشل في تحديث المستخدم', 500);
        }
    }

    public function delete(Request $request, Response $response, array $args = [])
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 400);
            }

            $id = $args['id'] ?? null;
            if (!$id) {
                return $this->errorResponse($response, 'ID is required', 400);
            }

            $stmt = $this->db->prepare("SELECT id, username, email, role_id FROM users WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$id, $tenantId]);
            $userToDelete = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$userToDelete) {
                return $this->errorResponse($response, 'المستخدم غير موجود أو غير مخوّل', 400);
            }

            $currentUser = $request->getAttribute('user');
            $currentUserRoleId = is_array($currentUser) ? ($currentUser['role_id'] ?? null) : null;

            if (SuperAdminHelper::is($userToDelete) && !SuperAdminHelper::is($currentUser)) {
                return $this->errorResponse($response, 'غير مسموح: فقط مدير النظام الرئيسي يمكنه حذف حسابات super_admin', 400);
            }

            $checks = [
                ['sales', 'created_by'],
                ['purchases', 'created_by'],
                ['returns', 'created_by'],
                ['payments', 'created_by'],
                ['stock_movements', 'created_by']
            ];

            foreach ($checks as [$table, $column]) {
                $stmt = $this->db->prepare("SELECT COUNT(*) FROM $table WHERE $column = ?");
                $stmt->execute([$id]);
                if ($stmt->fetchColumn() > 0) {
                    return $this->errorResponse($response, 'لا يمكن حذف المستخدم لوجود سجلات مرتبطة في ' . $table, 400);
                }
            }

            $this->db->beginTransaction();

            $stmt = $this->db->prepare("DELETE FROM users_role WHERE user_id = ?");
            $stmt->execute([$id]);

            $stmt = $this->db->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$id]);

            $this->db->commit();

            $this->securityLogger?->logSecurityEvent(
                'user.deleted',
                'info',
                'User deleted',
                [
                    'deleted_user_id' => $id,
                    'deleted_username' => $userToDelete['username'],
                    'deleted_email' => $userToDelete['email']
                ],
                $this->extractUserId($request),
                $tenantId
            );

            return $this->successResponse($response, [
                'status' => 'success',
                'message' => 'تم حذف المستخدم بنجاح',
                'data' => null
            ], 200);
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logger->error('فشل في حذف المستخدم', ['error' => $e->getMessage()]);
            return $this->errorResponse($response, 'فشل في حذف المستخدم', 500);
        }
    }

    public function legacyLogin($request, $response)
    {
        return $this->errorResponse($response, 'This endpoint is deprecated. Use POST /auth/login instead.', 403);
    }

    public function changePassword($request, $response, $args = [])
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $currentUserId = $this->extractUserId($request);
            if (!$currentUserId) {
                return $this->errorResponse($response, 'مطلوب معرف المستخدم.', 403);
            }

            // Admin reset: POST /users/{id}/change-password — targets a specific user
            // Self-service: POST /users/change-password — targets the authenticated user
            $targetId = isset($args['id']) ? (int) $args['id'] : $currentUserId;

            // Fetch only the fields needed for verification — never return this to the client
            $stmt = $this->db->prepare("SELECT id, username, password, tenant_id FROM users WHERE id = ? AND tenant_id = ? LIMIT 1");
            $stmt->execute([$targetId, $tenantId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                return $this->errorResponse($response, 'المستخدم غير موجود', 404);
            }

            $data = $this->extractAndValidateRequestData($request, ['new_password']);

            if (!is_array($data)) {
                return $this->errorResponse($response, 'Invalid request data', 400);
            }

            // Self-service: require current_password verification
            // Admin reset (different target user): skip current_password check
            $isSelfService = ($targetId === $currentUserId);
            if ($isSelfService) {
                if (empty($data['current_password'])) {
                    return $this->errorResponse($response, 'كلمة المرور الحالية مطلوبة', 400);
                }
                if (!password_verify($data['current_password'], $user['password'])) {
                    return $this->errorResponse($response, 'كلمة المرور الحالية غير صحيحة', 400);
                }
            }

            $newPassword = trim($data['new_password'] ?? '');
            if (strlen($newPassword) < 8) {
                return $this->errorResponse($response, 'كلمة المرور يجب أن تكون 8 أحرف على الأقل', 400);
            }

            // Tenant isolation enforced in WHERE clause
            $stmt = $this->db->prepare("
                UPDATE users
                SET password = ?,
                    updated_at = NOW()
                WHERE id = ? AND tenant_id = ?
            ");
            $stmt->execute([
                password_hash($newPassword, PASSWORD_DEFAULT),
                $targetId,
                $tenantId
            ]);

            if ($stmt->rowCount() === 0) {
                return $this->errorResponse($response, 'فشل تحديث كلمة المرور', 400);
            }

            $this->securityLogger?->logSecurityEvent(
                'user.password_change',
                'info',
                'Password changed',
                [
                    'target_user_id'   => $targetId,
                    'changed_by'       => $currentUserId,
                    'changed_by_self'  => $isSelfService,
                    'username'         => $user['username'],
                ],
                $currentUserId,
                $tenantId
            );

            return $this->successResponse($response, [
                'status'  => 'success',
                'message' => 'تم تغيير كلمة المرور بنجاح',
                'data'    => null
            ], 200);
        } catch (\Throwable $e) {
            $this->logger->error('فشل في تغيير كلمة المرور', ['error' => $e->getMessage()]);
            return $this->errorResponse($response, 'فشل في تغيير كلمة المرور', 500);
        }
    }

    private function generateToken($data)
    {
        $issuedAt = time();
        $expire = $issuedAt + 3600 * 24;

        $payload = [
            'iat' => $issuedAt,
            'exp' => $expire,
            'data' => $data
        ];

        $secretKey = getenv('JWT_SECRET');

        return JWT::encode($payload, $secretKey, 'HS256');
    }

    public function getUserPreferences($request, $response)
    {
        $userId = $this->extractUserId($request);
        if (!$userId) {
            return $this->errorResponse($response, 'مطلوب معرف المستخدم (User ID).', 403);
        }

        try {
            $sql = "SELECT selected_branch_id FROM user_preferences WHERE user_id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$userId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result) {
                return $this->successResponse($response, $result, 200);
            }

            return $this->successResponse($response, ['selected_branch_id' => null], 200);
        } catch (\Exception $e) {
            $this->logger->error('Failed to get user preferences: ' . $e->getMessage());
            return $this->errorResponse($response, 'فشل جلب التفضيلات', 500);
        }
    }

    public function saveUserPreferences($request, $response)
    {
        $userId = $this->extractUserId($request);
        if (!$userId) {
            return $this->errorResponse($response, 'مطلوب معرف المستخدم (User ID).', 403);
        }

        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
        }

        try {
            $body = $request->getParsedBody();
            $branchId = $body['selected_branch_id'] ?? null;

            if ($branchId) {
                $branchSql = "SELECT id FROM branches WHERE id = ? AND tenant_id = ?";
                $branchStmt = $this->db->prepare($branchSql);
                $branchStmt->execute([$branchId, $tenantId]);
                if (!$branchStmt->fetch()) {
                    return $this->errorResponse($response, 'معرف الفرع غير صحيح (Invalid Branch ID).', 422);
                }
            }

            $checkSql = "SELECT id FROM user_preferences WHERE user_id = ?";
            $checkStmt = $this->db->prepare($checkSql);
            $checkStmt->execute([$userId]);
            $exists = $checkStmt->fetch();

            if ($exists) {
                $updateSql = "UPDATE user_preferences SET selected_branch_id = ?, updated_at = NOW() WHERE user_id = ?";
                $updateStmt = $this->db->prepare($updateSql);
                $updateStmt->execute([$branchId, $userId]);
            } else {
                $insertSql = "INSERT INTO user_preferences (user_id, selected_branch_id, created_at, updated_at) VALUES (?, ?, NOW(), NOW())";
                $insertStmt = $this->db->prepare($insertSql);
                $insertStmt->execute([$userId, $branchId]);
            }

            $this->logger->info('User preferences saved', [
                'user_id' => $userId,
                'tenant_id' => $tenantId,
                'branch_id' => $branchId
            ]);

            return $this->successResponse($response, ['selected_branch_id' => $branchId], 200);
        } catch (\Exception $e) {
            $this->logger->error('Failed to save user preferences: ' . $e->getMessage());
            return $this->errorResponse($response, 'فشل حفظ التفضيلات', 500);
        }
    }

    public function getMe($request, $response)
    {
        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
        }

        $userId = $this->extractUserId($request);
        if (!$userId) {
            return $this->errorResponse($response, 'مطلوب معرف المستخدم (User ID).', 403);
        }

        try {
            $stmt = $this->db->prepare("
                SELECT
                    id,
                    username,
                    name,
                    email,
                    phone,
                    role_id,
                    status,
                    tenant_id,
                    branch_id,
                    is_owner,
                    two_fa_enabled,
                    last_login,
                    created_at,
                    updated_at
                FROM users
                WHERE id = ? AND tenant_id = ?
                LIMIT 1
            ");
            $stmt->execute([$userId, $tenantId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                return $this->errorResponse($response, 'المستخدم غير موجود', 404);
            }

            return $this->jsonResponse($response, [
                'status' => 'success',
                'message' => 'تم جلب بيانات المستخدم بنجاح',
                'data' => $user
            ], 200, false);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to get current user', [
                'user_id' => $userId,
                'tenant_id' => $tenantId,
                'error' => $e->getMessage()
            ]);
            return $this->errorResponse($response, 'فشل في جلب بيانات المستخدم', 500);
        }
    }

    /**
     * Update current user profile (name, email, phone)
     */
    public function updateProfile($request, $response)
    {
        $tenantId = $this->extractTenantId($request);
        $userId = $this->extractUserId($request);
        $data = $request->getParsedBody() ?? [];

        $name = trim($data['name'] ?? '');
        $email = trim($data['email'] ?? '');
        $phone = trim($data['phone'] ?? '');

        // Validate name
        if (empty($name)) {
            return $this->errorResponse($response, 'الاسم مطلوب', 400);
        }

        try {
            // Check if email is already used by another user
            if (!empty($email)) {
                $checkStmt = $this->db->prepare("
                    SELECT id FROM users 
                    WHERE email = ? AND tenant_id = ? AND id != ?
                ");
                $checkStmt->execute([$email, $tenantId, $userId]);
                if ($checkStmt->fetch()) {
                    return $this->errorResponse($response, 'البريد الإلكتروني مستخدم من قبل مستخدم آخر', 400);
                }
            }

            // Update user profile
            $stmt = $this->db->prepare("
                UPDATE users 
                SET name = ?, email = ?, phone = ?, updated_at = NOW()
                WHERE id = ? AND tenant_id = ?
            ");
            $stmt->execute([$name, $email, $phone, $userId, $tenantId]);

            if ($stmt->rowCount() === 0) {
                return $this->errorResponse($response, 'لم يتم تحديث الملف الشخصي', 400);
            }

            // Log the action
            $this->logger->info('User profile updated', [
                'user_id' => $userId,
                'tenant_id' => $tenantId,
                'name' => $name,
                'email' => $email
            ]);

            return $this->jsonResponse($response, [
                'status' => 'success',
                'message' => 'تم تحديث الملف الشخصي بنجاح',
                'data' => [
                    'id' => $userId,
                    'name' => $name,
                    'email' => $email,
                    'phone' => $phone
                ]
            ], 200, false);

        } catch (\Throwable $e) {
            $this->logger->error('Failed to update profile', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return $this->errorResponse($response, 'فشل تحديث الملف الشخصي', 500);
        }
    }
}
