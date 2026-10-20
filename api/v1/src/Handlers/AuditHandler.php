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

    /** Return a paginated tenant log filtered by user, module, action, date, or IP. */
    public function getLogs(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $tenantId = isset($filters['tenant_id']) ? (int) $filters['tenant_id'] : null;
        if (!$tenantId) {
            throw new Exception('مطلوب معرف المستأجر (Tenant ID).');
        }

        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = ['al.tenant_id = ?'];
        $params = [$tenantId];

        if (!empty($filters['user_id'])) {
            $where[] = 'al.user_id = ?';
            $params[] = (int) $filters['user_id'];
        }

        if (!empty($filters['module'])) {
            $where[] = 'al.entity = ?';
            $params[] = $filters['module'];
        }

        if (!empty($filters['action'])) {
            $where[] = 'al.action = ?';
            $params[] = $filters['action'];
        }

        if (!empty($filters['start_date'])) {
            $where[] = 'al.created_at >= ?';
            $params[] = $filters['start_date'] . ' 00:00:00';
        }

        if (!empty($filters['end_date'])) {
            $nextDay = date('Y-m-d', strtotime($filters['end_date'] . ' +1 day'));
            $where[] = 'al.created_at < ?';
            $params[] = $nextDay . ' 00:00:00';
        }

        if (!empty($filters['ip_address'])) {
            $where[] = 'al.ip_address = ?';
            $params[] = $filters['ip_address'];
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $sql = "
            SELECT
                al.*,
                u.name AS user_name,
                u.email AS user_email
            FROM audit_log al
            LEFT JOIN users u ON u.id = al.user_id
            {$whereClause}
            ORDER BY al.created_at DESC
            LIMIT ? OFFSET ?
        ";

        $stmt = $this->db->prepare($sql);
        $params[] = $perPage;
        $params[] = $offset;
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Aggregate audit activity by module, action, user, and IP for a tenant and date range. */
    public function getStats(array $filters = []): array
    {
        $tenantId = isset($filters['tenant_id']) ? (int) $filters['tenant_id'] : null;
        if (!$tenantId) {
            throw new Exception('مطلوب معرف المستأجر (Tenant ID).');
        }

        $where = ['al.tenant_id = ?'];
        $params = [$tenantId];

        if (!empty($filters['start_date'])) {
            $where[] = 'al.created_at >= ?';
            $params[] = $filters['start_date'] . ' 00:00:00';
        }

        if (!empty($filters['end_date'])) {
            $nextDay = date('Y-m-d', strtotime($filters['end_date'] . ' +1 day'));
            $where[] = 'al.created_at < ?';
            $params[] = $nextDay . ' 00:00:00';
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $stmt = $this->db->prepare("
            SELECT
                al.entity,
                COUNT(*) AS total_actions,
                COUNT(DISTINCT al.user_id) AS unique_users
            FROM audit_log al
            {$whereClause}
            GROUP BY al.entity
            ORDER BY total_actions DESC
        ");
        $stmt->execute($params);
        $moduleStats = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $stmt = $this->db->prepare("
            SELECT
                al.action,
                COUNT(*) AS total_actions,
                COUNT(DISTINCT al.user_id) AS unique_users
            FROM audit_log al
            {$whereClause}
            GROUP BY al.action
            ORDER BY total_actions DESC
        ");
        $stmt->execute($params);
        $actionStats = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $stmt = $this->db->prepare("
            SELECT
                u.name AS user_name,
                COUNT(*) AS total_actions,
                COUNT(DISTINCT al.entity) AS unique_modules
            FROM audit_log al
            JOIN users u ON u.id = al.user_id
            {$whereClause}
            GROUP BY al.user_id
            ORDER BY total_actions DESC
            LIMIT 10
        ");
        $stmt->execute($params);
        $userStats = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $stmt = $this->db->prepare("
            SELECT
                al.ip_address,
                COUNT(*) AS total_actions,
                COUNT(DISTINCT al.user_id) AS unique_users
            FROM audit_log al
            {$whereClause}
            GROUP BY al.ip_address
            ORDER BY total_actions DESC
            LIMIT 10
        ");
        $stmt->execute($params);
        $ipStats = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'module_stats' => $moduleStats,
            'action_stats' => $actionStats,
            'user_stats' => $userStats,
            'ip_stats' => $ipStats
        ];
    }

    /** Fetch one audit record and user details, constrained by log and tenant IDs. */
    public function getLogDetails(int $logId, int $tenantId): array|false
    {
        $stmt = $this->db->prepare("
            SELECT
                al.*,
                u.name AS user_name,
                u.email AS user_email
            FROM audit_log al
            LEFT JOIN users u ON u.id = al.user_id
            WHERE al.id = ? AND al.tenant_id = ?
        ");

        $stmt->execute([$logId, $tenantId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /** Return a user's paginated activity using the shared audit-log filters. */
    public function getUserActivity(int $userId, array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $filters['user_id'] = $userId;
        return $this->getLogs($filters, $page, $perPage);
    }

    /** Return a module's paginated activity using the shared audit-log filters. */
    public function getModuleActivity(string $module, array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $filters['module'] = $module;
        return $this->getLogs($filters, $page, $perPage);
    }

    /** Return an IP address's paginated activity using the shared audit-log filters. */
    public function getIpActivity(string $ipAddress, array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $filters['ip_address'] = $ipAddress;
        return $this->getLogs($filters, $page, $perPage);
    }

    /** List paginated auth/security audit events, optionally filtered by severity and date. */
    public function getSecurityAlerts(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $tenantId = isset($filters['tenant_id']) ? (int) $filters['tenant_id'] : null;
        if (!$tenantId) {
            throw new Exception('مطلوب معرف المستأجر (Tenant ID).');
        }

        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = ["al.tenant_id = ?", "al.entity IN ('auth', 'security')"];
        $params = [$tenantId];

        if (!empty($filters['severity'])) {
            $where[] = "al.severity = ?";
            $params[] = $filters['severity'];
        }

        if (!empty($filters['start_date'])) {
            $where[] = "al.created_at >= ?";
            $params[] = $filters['start_date'] . ' 00:00:00';
        }

        if (!empty($filters['end_date'])) {
            $nextDay = date('Y-m-d', strtotime($filters['end_date'] . ' +1 day'));
            $where[] = "al.created_at < ?";
            $params[] = $nextDay . ' 00:00:00';
        }

        $whereClause = "WHERE " . implode(" AND ", $where);

        $stmt = $this->db->prepare("
            SELECT
                al.*,
                u.name AS user_name,
                u.email AS user_email
            FROM audit_log al
            LEFT JOIN users u ON u.id = al.user_id
            {$whereClause}
            ORDER BY al.created_at DESC
            LIMIT ? OFFSET ?
        ");

        $params[] = $perPage;
        $params[] = $offset;
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Delete this tenant's audit records older than the supplied retention period. */
    public function cleanOldLogs(int $days = 90, ?int $tenantId = null): bool
    {
        if (!$tenantId) {
            throw new Exception('مطلوب معرف المستأجر (Tenant ID).');
        }

        $stmt = $this->db->prepare("
            DELETE FROM audit_log
            WHERE tenant_id = ?
              AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");

        return $stmt->execute([$tenantId, $days]);
    }

    /** Return all matching tenant audit records for export without pagination. */
    public function exportLogs(array $filters = []): array
    {
        $tenantId = isset($filters['tenant_id']) ? (int) $filters['tenant_id'] : null;
        if (!$tenantId) {
            throw new Exception('مطلوب معرف المستأجر (Tenant ID).');
        }

        $where = ['al.tenant_id = ?'];
        $params = [$tenantId];

        if (!empty($filters['user_id'])) {
            $where[] = 'al.user_id = ?';
            $params[] = (int) $filters['user_id'];
        }

        if (!empty($filters['module'])) {
            $where[] = 'al.entity = ?';
            $params[] = $filters['module'];
        }

        if (!empty($filters['action'])) {
            $where[] = 'al.action = ?';
            $params[] = $filters['action'];
        }

        if (!empty($filters['start_date'])) {
            $where[] = 'al.created_at >= ?';
            $params[] = $filters['start_date'] . ' 00:00:00';
        }

        if (!empty($filters['end_date'])) {
            $nextDay = date('Y-m-d', strtotime($filters['end_date'] . ' +1 day'));
            $where[] = 'al.created_at < ?';
            $params[] = $nextDay . ' 00:00:00';
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $stmt = $this->db->prepare("
            SELECT
                al.id,
                al.created_at,
                u.name AS user_name,
                u.email AS user_email,
                al.entity AS module,
                al.action,
                al.details
            FROM audit_log al
            LEFT JOIN users u ON u.id = al.user_id
            {$whereClause}
            ORDER BY al.created_at DESC
        ");

        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
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
