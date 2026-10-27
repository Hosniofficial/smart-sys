<?php

declare(strict_types=1);

namespace App\Handlers;

use PDO;
use Exception;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Services\MonologHandler;

/**
 * Writes and queries tenant-scoped audit records.
 *
 * Uses PDO for audit-log persistence and retrieval; the API listing route is
 * protected by report.financial permission in app/routes/audit.php.
 */
class AuditHandler extends BaseHandler
{
    public ?array $currentUser = null;  // Public so BaseHandler.getAudit() can sync the user context

    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->logger = MonologHandler::getInstance('audit');
    }

    /** Persist an audit event, using the handler's tenant and user context when omitted. */
    public function logAction(
        string $action,
        string $module,
        ?int $recordId = null,
        array|string|null $data = null,
        ?int $tenantId = null,
        ?int $userId = null,
        ?string $ipAddress = null
    ): bool {
        $tenantId = $tenantId ?? $this->tenantId;
        if (!$tenantId) {
            throw new Exception('مطلوب معرف المستأجر (Tenant ID).');
        }

        if ($userId === null && isset($this->currentUser) && is_array($this->currentUser)) {
            $userId = $this->currentUser['id'] ?? null;
        }

        $details = $data !== null
            ? (is_array($data)
                ? json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : (string) $data)
            : null;

        $stmt = $this->db->prepare("
            INSERT INTO audit_log (
                user_id,
                action,
                entity,
                entity_id,
                details,
                tenant_id,
                ip_address,
                created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        return $stmt->execute([
            $userId,
            $action,
            $module,
            $recordId,
            $details,
            $tenantId,
            $ipAddress
        ]);
    }

    /** Serve the paginated audit-log endpoint, including optional entity-based branch filtering. */
    public function getAuditLogs(Request $request, Response $response): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $params = $request->getQueryParams();
            $branchId = null;
            if (isset($params['branch_id']) && $params['branch_id'] !== '') {
                $branchId = filter_var($params['branch_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($branchId === false) {
                    return $this->errorResponse($response, 'معرف الفرع غير صالح.', 400);
                }
                $branchStmt = $this->db->prepare('SELECT id FROM branches WHERE id = ? AND tenant_id = ? LIMIT 1');
                $branchStmt->execute([$branchId, $tenantId]);
                if (!$branchStmt->fetchColumn()) {
                    return $this->errorResponse($response, 'الفرع غير موجود.', 404);
                }
                $branchId = (int) $branchId;
            }
            $page = isset($params['page']) ? max((int) $params['page'], 1) : 1;
            $perPage = isset($params['per_page']) ? max(min((int) $params['per_page'], 100), 10) : 20;
            $offset = ($page - 1) * $perPage;

            $where = ['a.tenant_id = :tenant_id'];
            $bind = [':tenant_id' => $tenantId];

            if (!empty($params['start_date'])) {
                $where[] = 'a.created_at >= :start_date';
                $bind[':start_date'] = $params['start_date'] . ' 00:00:00';
            }

            if (!empty($params['end_date'])) {
                $nextDay = date('Y-m-d', strtotime($params['end_date'] . ' +1 day'));
                $where[] = 'a.created_at < :end_date';
                $bind[':end_date'] = $nextDay . ' 00:00:00';
            }

            if (!empty($params['action_type']) && $params['action_type'] !== 'all') {
                $where[] = 'a.action = :action';
                $bind[':action'] = $params['action_type'];
            }

            if (!empty($params['user_id']) && $params['user_id'] !== 'all') {
                $where[] = 'a.user_id = :user_id';
                $bind[':user_id'] = (int) $params['user_id'];
            }
            if ($branchId !== null) {
                $where[] = "(
                    (a.entity IN ('sale', 'sales') AND EXISTS (
                        SELECT 1 FROM sales s
                        WHERE s.id = a.entity_id AND s.tenant_id = a.tenant_id AND s.branch_id = {$branchId}
                    ))
                    OR (a.entity IN ('purchase', 'purchases') AND EXISTS (
                        SELECT 1 FROM purchases p
                        WHERE p.id = a.entity_id AND p.tenant_id = a.tenant_id AND p.branch_id = {$branchId}
                    ))
                    OR (a.entity IN ('return', 'returns') AND EXISTS (
                        SELECT 1 FROM returns r
                        WHERE r.id = a.entity_id AND r.tenant_id = a.tenant_id AND r.branch_id = {$branchId}
                    ))
                    OR (a.entity IN ('cash_voucher', 'cash_vouchers', 'voucher') AND EXISTS (
                        SELECT 1 FROM cash_vouchers cv
                        WHERE cv.id = a.entity_id AND cv.tenant_id = a.tenant_id AND cv.branch_id = {$branchId}
                    ))
                    OR (a.entity IN ('cash_transaction', 'cash_transactions') AND EXISTS (
                        SELECT 1 FROM cash_transactions ct
                        WHERE ct.id = a.entity_id AND ct.tenant_id = a.tenant_id AND ct.branch_id = {$branchId}
                    ))
                    OR (a.entity IN ('customer', 'customers') AND EXISTS (
                        SELECT 1 FROM customers c
                        WHERE c.id = a.entity_id AND c.tenant_id = a.tenant_id AND c.branch_id = {$branchId}
                    ))
                    OR (a.entity IN ('supplier', 'suppliers') AND EXISTS (
                        SELECT 1 FROM suppliers su
                        WHERE su.id = a.entity_id AND su.tenant_id = a.tenant_id AND su.branch_id = {$branchId}
                    ))
                    OR (a.entity IN ('product', 'products', 'inventory') AND EXISTS (
                        SELECT 1 FROM branch_products bp
                        WHERE bp.product_id = a.entity_id AND bp.tenant_id = a.tenant_id AND bp.branch_id = {$branchId}
                    ))
                    OR (a.entity IN ('branch', 'branches') AND a.entity_id = {$branchId})
                    OR (CASE
                        WHEN JSON_VALID(a.details) THEN
                            CAST(JSON_UNQUOTE(JSON_EXTRACT(a.details, '$.branch_id')) AS UNSIGNED) = {$branchId}
                            OR CAST(JSON_UNQUOTE(JSON_EXTRACT(a.details, '$.branchId')) AS UNSIGNED) = {$branchId}
                        ELSE 0
                    END)
                )";
            }

            $whereSql = 'WHERE ' . implode(' AND ', $where);

            $countSql = "SELECT COUNT(*) AS cnt FROM audit_log a {$whereSql}";
            $stmt = $this->db->prepare($countSql);
            $stmt->execute($bind);
            $total = (int) ($stmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);
            $totalPages = $perPage > 0 ? (int) ceil($total / $perPage) : 0;

            $sql = "
                SELECT
                    a.id,
                    a.created_at AS timestamp,
                    a.user_id,
                    COALESCE(u.name, u.username, CONCAT('User#', a.user_id)) AS user_name,
                    a.action,
                    a.entity AS module,
                    a.entity_id AS record_id,
                    a.details
                FROM audit_log a
                LEFT JOIN users u ON u.id = a.user_id
                {$whereSql}
                ORDER BY a.created_at DESC
                LIMIT :limit OFFSET :offset
            ";

            $stmt = $this->db->prepare($sql);
            foreach ($bind as $k => $v) {
                $stmt->bindValue($k, $v);
            }
            $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();

            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $usersStmt = $this->db->prepare("
                SELECT id, name
                FROM users
                WHERE tenant_id = ?
                ORDER BY name
            ");
            $usersStmt->execute([$tenantId]);
            $users = $usersStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            return $this->successResponse($response, [
                'data' => $rows,
                'pagination' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'total_pages' => $totalPages
                ],
                'meta' => [
                    'filters' => [
                        'users' => $users
                    ]
                ]
            ], 200);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to retrieve audit logs', [
                'message' => $e->getMessage()
            ]);

            return $this->errorResponse($response, 'فشل في جلب سجلات التدقيق', 400);
        }
    }
}
