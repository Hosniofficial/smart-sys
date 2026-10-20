<?php

namespace App\Handlers;

use App\Services\SecurityLogger;
use App\Services\UserSessionService;
use PDO;
use App\Services\MonologHandler;

/**
 * Handles tenant user lookups, self-service profiles, and password changes.
 *
 * User and profile data is read and written through PDO; password changes are
 * recorded by SecurityLogger.
 */
class UsersHandler extends BaseHandler
{
    private ?SecurityLogger $securityLogger = null;

    public function __construct(PDO $db, ?SecurityLogger $securityLogger = null)
    {
        parent::__construct($db);
        $this->logger = MonologHandler::getInstance('users');
        $this->securityLogger = $securityLogger;
    }

    /** Return a paginated tenant user list with optional search and role filters. */
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

    /** Change the authenticated user's password after verifying the current password. */
    public function changePassword($request, $response)
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

            // Fetch only the fields needed for verification — never return this to the client
            $stmt = $this->db->prepare("SELECT id, username, password, tenant_id FROM users WHERE id = ? AND tenant_id = ? LIMIT 1");
            $stmt->execute([$currentUserId, $tenantId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                return $this->errorResponse($response, 'المستخدم غير موجود', 404);
            }

            $data = $this->extractAndValidateRequestData($request, ['new_password']);

            if (!is_array($data)) {
                return $this->errorResponse($response, 'Invalid request data', 400);
            }

            if (empty($data['current_password'])) {
                return $this->errorResponse($response, 'كلمة المرور الحالية مطلوبة', 400);
            }
            if (!password_verify($data['current_password'], $user['password'])) {
                return $this->errorResponse($response, 'كلمة المرور الحالية غير صحيحة', 400);
            }

            $newPassword = trim($data['new_password'] ?? '');
            if (strlen($newPassword) < 8) {
                return $this->errorResponse($response, 'كلمة المرور يجب أن تكون 8 أحرف على الأقل', 400);
            }

            (new UserSessionService($this->db))->changePassword(
                $currentUserId,
                (int) $tenantId,
                $newPassword
            );

            $this->securityLogger?->logSecurityEvent(
                'user.password_change',
                'info',
                'Password changed',
                [
                    'target_user_id'   => $currentUserId,
                    'changed_by'       => $currentUserId,
                    'changed_by_self'  => true,
                    'username'         => $user['username'],
                ],
                $currentUserId,
                $tenantId
            );

            return $this->successResponse($response, [
                'status'  => 'success',
                'message' => 'تم تغيير كلمة المرور وإغلاق الجلسات الأخرى. يرجى تسجيل الدخول مجددًا.',
                'data'    => null
            ], 200);
        } catch (\Throwable $e) {
            $this->logger->error('فشل في تغيير كلمة المرور', ['error' => $e->getMessage()]);
            return $this->errorResponse($response, 'فشل في تغيير كلمة المرور', 500);
        }
    }

    /** Return the authenticated user's profile within the current tenant. */
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

    /** Update the authenticated user's name, email, and phone. */
    public function updateProfile($request, $response)
    {
        $tenantId = $this->extractTenantId($request);
        $userId = $this->extractUserId($request);
        $data = $request->getParsedBody() ?? [];

        $name = trim($data['name'] ?? '');
        $email = trim($data['email'] ?? '');
        $phone = trim($data['phone'] ?? '');

        if (empty($name)) {
            return $this->errorResponse($response, 'الاسم مطلوب', 400);
        }

        try {
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

            $stmt = $this->db->prepare("
                UPDATE users 
                SET name = ?, email = ?, phone = ?, updated_at = NOW()
                WHERE id = ? AND tenant_id = ?
            ");
            $stmt->execute([$name, $email, $phone, $userId, $tenantId]);

            if ($stmt->rowCount() === 0) {
                return $this->errorResponse($response, 'لم يتم تحديث الملف الشخصي', 400);
            }

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
