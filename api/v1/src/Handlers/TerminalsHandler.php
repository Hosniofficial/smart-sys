<?php

declare(strict_types=1);

namespace App\Handlers;

use PDO;
use Exception;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Services\MonologHandler;
use App\Utils\SuperAdminHelper;

/**
 * Lists and manages tenant point-of-sale terminals.
 *
 * PDO handles terminal and branch persistence; BaseHandler resolves tenant and
 * request context, while SuperAdminHelper applies the handler-level admin check.
 */
class TerminalsHandler extends BaseHandler
{
    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->logger = MonologHandler::getInstance('terminals');
    }

    /** List tenant terminals with optional branch and status filters. */
    public function list(Request $request, Response $response): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                $this->logger->warning('Terminals list - missing tenant ID');
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $q = $request->getQueryParams();
            $branchId = $q['branch_id'] ?? null;
            $status = $q['status'] ?? 'active';

            $this->logger->info('Terminals list request', [
                'tenant_id' => $tenantId,
                'branch_id' => $branchId,
                'status' => $status
            ]);

            $sql = "SELECT id, tenant_id, branch_id, code, name, status
                    FROM terminals
                    WHERE tenant_id = ?";
            $params = [$tenantId];

            if ($branchId !== null && $branchId !== '') {
                $sql .= " AND branch_id = ?";
                $params[] = (int) $branchId;

                $this->logger->debug('Filtering by branch', [
                    'tenant_id' => $tenantId,
                    'branch_id' => $branchId
                ]);
            }

            if ($status !== null && $status !== '' && $status !== 'all') {
                $sql .= " AND status = ?";
                $params[] = $status;

                $this->logger->debug('Filtering by status', [
                    'tenant_id' => $tenantId,
                    'status' => $status
                ]);
            }

            $sql .= " ORDER BY name ASC";

            $this->logger->debug('Executing terminals query', [
                'tenant_id' => $tenantId,
                'sql' => $sql,
                'params_count' => count($params)
            ]);

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $terminals = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $this->logger->info('Terminals retrieved successfully', [
                'tenant_id' => $tenantId,
                'count' => count($terminals),
                'branch_id' => $branchId,
                'status' => $status
            ]);

            return $this->successResponse($response, $terminals, 200);
        } catch (Exception $e) {
            $this->logger->error('Terminals list failed', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'tenant_id' => $tenantId ?? 'unknown'
            ]);

            return $this->errorResponse(
                $response,
                'Failed to retrieve terminals: ' . $e->getMessage(),
                400
            );
        }
    }

    /** Create a terminal after checking tenant ownership and administrator access. */
    public function create(Request $request, Response $response): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                $this->logger->warning('Terminals create - missing tenant ID');
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $user = $request->getAttribute('user');
            $userId = is_array($user) ? ($user['id'] ?? null) : null;
            $roleId = is_array($user) ? ($user['role_id'] ?? null) : null;

            $this->logger->info('Terminal creation request', [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'role_id' => $roleId
            ]);

            if (!SuperAdminHelper::isAdminOrAbove(is_array($user) ? $user : null)) {
                $this->logger->warning('Terminal creation access denied - insufficient permissions', [
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'role_id' => $roleId
                ]);

                return $this->errorResponse($response, 'ليست لديك صلاحية لإدارة أجهزة نقطة البيع.', 403);
            }

            $data = $request->getParsedBody();
            if (!is_array($data)) {
                $data = [];
            }

            $branchId = $data['branch_id'] ?? null;
            $code = isset($data['code']) ? trim((string) $data['code']) : '';
            $name = isset($data['name']) ? trim((string) $data['name']) : '';
            $status = $data['status'] ?? 'active';

            $this->logger->debug('Terminal creation data', [
                'tenant_id' => $tenantId,
                'branch_id' => $branchId,
                'code' => $code,
                'name' => $name,
                'status' => $status
            ]);

            if (!$branchId) {
                $this->logger->warning('Terminal creation - missing branch ID', [
                    'tenant_id' => $tenantId,
                    'user_id' => $userId
                ]);

                return $this->errorResponse($response, 'branch_id مطلوب.', 400);
            }

            if ($code === '') {
                $this->logger->warning('Terminal creation - missing code', [
                    'tenant_id' => $tenantId,
                    'user_id' => $userId
                ]);

                return $this->errorResponse($response, 'code مطلوب.', 400);
            }

            if (!in_array($status, ['active', 'inactive'], true)) {
                $this->logger->warning('Terminal creation - invalid status', [
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'status' => $status
                ]);

                return $this->errorResponse(
                    $response,
                    'قيمة status غير صالحة. استخدم active أو inactive.',
                    400
                );
            }

            $this->logger->debug('Checking terminal code uniqueness', [
                'tenant_id' => $tenantId,
                'code' => $code
            ]);

            $stmt = $this->db->prepare("SELECT COUNT(*) FROM terminals WHERE tenant_id = ? AND code = ?");
            $stmt->execute([$tenantId, $code]);

            if ((int) $stmt->fetchColumn() > 0) {
                $this->logger->warning('Terminal creation - duplicate code', [
                    'tenant_id' => $tenantId,
                    'code' => $code,
                    'user_id' => $userId
                ]);

                return $this->errorResponse(
                    $response,
                    'يوجد بالفعل جهاز نقطة بيع بهذا الكود لهذا المستأجر.',
                    403
                );
            }

            $this->logger->debug('Validating branch ownership', [
                'tenant_id' => $tenantId,
                'branch_id' => $branchId
            ]);

            $stmt = $this->db->prepare(
                "SELECT COUNT(*)
                 FROM branches
                 WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL)"
            );
            $stmt->execute([(int) $branchId, $tenantId]);

            if ((int) $stmt->fetchColumn() === 0) {
                $this->logger->warning('Terminal creation - invalid branch', [
                    'tenant_id' => $tenantId,
                    'branch_id' => $branchId,
                    'user_id' => $userId
                ]);

                return $this->errorResponse(
                    $response,
                    'المخزن المحدد غير موجود أو لا يتبع هذا المستأجر.',
                    400
                );
            }

            $this->logger->debug('Creating new terminal', [
                'tenant_id' => $tenantId,
                'branch_id' => $branchId,
                'code' => $code,
                'name' => $name,
                'status' => $status,
                'user_id' => $userId
            ]);

            $stmt = $this->db->prepare(
                "INSERT INTO terminals (
                    tenant_id, branch_id, code, name, status,
                    created_by, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())"
            );
            $stmt->execute([
                (int) $tenantId,
                (int) $branchId,
                $code,
                $name !== '' ? $name : null,
                $status,
                $userId
            ]);

            $id = (int) $this->db->lastInsertId();

            $this->logger->info('Terminal created successfully', [
                'tenant_id' => $tenantId,
                'terminal_id' => $id,
                'branch_id' => $branchId,
                'code' => $code,
                'name' => $name,
                'status' => $status,
                'user_id' => $userId
            ]);

            $stmt = $this->db->prepare(
                "SELECT id, tenant_id, branch_id, code, name, status
                 FROM terminals
                 WHERE id = ? AND tenant_id = ?
                 LIMIT 1"
            );
            $stmt->execute([(int) $id, (int) $tenantId]);
            $terminal = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

            return $this->jsonResponse($response, [
                'status' => 'success',
                'message' => 'تم إنشاء جهاز نقطة البيع بنجاح.',
                'data' => $terminal
            ], 201);
        } catch (Exception $e) {
            $this->logger->error('Terminal creation failed', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'tenant_id' => $tenantId ?? 'unknown',
                'user_id' => $userId ?? 'unknown',
                'branch_id' => $branchId ?? 'unknown',
                'code' => $code ?? 'unknown'
            ]);

            return $this->errorResponse($response, 'فشل في العملية', 400);
        }
    }

    /** Update a tenant terminal's name, status, or branch. */
    public function update(Request $request, Response $response, array $args): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $user     = $request->getAttribute('user');
            $userId   = is_array($user) ? ($user['id'] ?? null) : null;
            $roleId   = is_array($user) ? ($user['role_id'] ?? null) : null;

            if (!SuperAdminHelper::isAdminOrAbove(is_array($user) ? $user : null)) {
                return $this->errorResponse($response, 'ليست لديك صلاحية لتعديل أجهزة نقطة البيع.', 403);
            }

            $id   = (int)($args['id'] ?? 0);
            $data = $request->getParsedBody() ?? [];

            // Verify terminal belongs to tenant
            $chk = $this->db->prepare("SELECT id FROM terminals WHERE id = ? AND tenant_id = ? LIMIT 1");
            $chk->execute([$id, (int)$tenantId]);
            if (!$chk->fetch()) {
                return $this->errorResponse($response, 'الترمينال غير موجود أو لا يتبع هذا المستأجر.', 404);
            }

            $fields  = [];
            $params  = [];

            if (array_key_exists('name', $data)) {
                $fields[] = 'name = ?';
                $params[] = trim((string)$data['name']) ?: null;
            }

            if (array_key_exists('status', $data)) {
                $status = $data['status'];
                if (!in_array($status, ['active', 'inactive'], true)) {
                    return $this->errorResponse($response, 'قيمة status غير صالحة.', 400);
                }
                $fields[] = 'status = ?';
                $params[] = $status;
            }

            if (array_key_exists('branch_id', $data)) {
                $branchId = (int)$data['branch_id'];
                $bChk = $this->db->prepare("SELECT COUNT(*) FROM branches WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL)");
                $bChk->execute([$branchId, (int)$tenantId]);
                if ((int)$bChk->fetchColumn() === 0) {
                    return $this->errorResponse($response, 'الفرع المحدد غير موجود.', 400);
                }
                $fields[] = 'branch_id = ?';
                $params[] = $branchId;
            }

            if (empty($fields)) {
                return $this->errorResponse($response, 'لا توجد حقول للتحديث.', 400);
            }

            $fields[]  = 'updated_at = NOW()';
            $params[]  = $id;
            $params[]  = (int)$tenantId;

            $this->db->prepare("UPDATE terminals SET " . implode(', ', $fields) . " WHERE id = ? AND tenant_id = ?")
                     ->execute($params);

            $stmt = $this->db->prepare("SELECT id, tenant_id, branch_id, code, name, status FROM terminals WHERE id = ? AND tenant_id = ? LIMIT 1");
            $stmt->execute([$id, (int)$tenantId]);
            $terminal = $stmt->fetch(PDO::FETCH_ASSOC);

            $this->logger->info('Terminal updated', ['terminal_id' => $id, 'user_id' => $userId, 'fields' => array_keys($data)]);

            return $this->jsonResponse($response, ['status' => 'success', 'message' => 'تم تحديث الجهاز بنجاح.', 'data' => $terminal], 200);
        } catch (Exception $e) {
            $this->logger->error('Terminal update failed', ['message' => $e->getMessage()]);
            return $this->errorResponse($response, 'فشل في تحديث الجهاز.', 400);
        }
    }
}
