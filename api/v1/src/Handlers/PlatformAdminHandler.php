<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Services\MonologHandler;
use App\Services\PlatformAuditLogger;
use App\Services\RailwayOperationsService;
use DateTimeImmutable;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

final class PlatformAdminHandler extends BaseHandler
{
    private RailwayOperationsService $railwayOperations;
    private PlatformAuditLogger $platformAuditLogger;

    public function __construct(PDO $db, ?RailwayOperationsService $railwayOperations = null)
    {
        parent::__construct($db);
        $this->logger = MonologHandler::getInstance('platform_admin');
        $this->railwayOperations = $railwayOperations ?? new RailwayOperationsService();
        $this->platformAuditLogger = new PlatformAuditLogger($db);
    }

    public function listTenants(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $page = $this->positiveInteger($params['page'] ?? 1);
        $perPage = $this->positiveInteger($params['per_page'] ?? 20);
        $perPage = min($perPage ?? 20, 100);
        $status = trim((string) ($params['status'] ?? ''));
        $search = trim((string) ($params['search'] ?? ''));

        if ($page === null) {
            return $this->errorResponse($response, 'رقم الصفحة غير صالح.', 400);
        }
        if ($status !== '' && !in_array($status, ['active', 'inactive', 'trial'], true)) {
            return $this->errorResponse($response, 'حالة المستأجر غير صالحة.', 400);
        }
        if (strlen($search) > 100) {
            return $this->errorResponse($response, 'نص البحث أطول من المسموح.', 400);
        }

        $where = [];
        $bind = [];
        if ($status !== '') {
            $where[] = 't.status = :status';
            $bind[':status'] = $status;
        }
        if ($search !== '') {
            $where[] = '(CAST(t.id AS CHAR) = :tenant_id OR t.company_name LIKE :search)';
            $bind[':tenant_id'] = $search;
            $bind[':search'] = '%' . $search . '%';
        }
        $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

        try {
            $count = $this->db->prepare("SELECT COUNT(*) FROM tenants t {$whereSql}");
            $count->execute($bind);
            $total = (int) $count->fetchColumn();

            $statement = $this->db->prepare("
                SELECT
                    t.id,
                    t.company_name,
                    t.status,
                    t.is_setup_complete,
                    t.current_step,
                    t.created_at,
                    (SELECT COUNT(*) FROM users u WHERE u.tenant_id = t.id) AS user_count
                FROM tenants t
                {$whereSql}
                ORDER BY t.id DESC
                LIMIT :limit OFFSET :offset
            ");
            foreach ($bind as $key => $value) {
                $statement->bindValue($key, $value);
            }
            $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $statement->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
            $statement->execute();

            return $this->successResponse($response, [
                'items' => $statement->fetchAll(PDO::FETCH_ASSOC) ?: [],
                'pagination' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'total_pages' => (int) ceil($total / $perPage),
                ],
            ]);
        } catch (Throwable $error) {
            $this->logger->error('Failed to list platform tenants', ['message' => $error->getMessage()]);
            return $this->errorResponse($response, 'تعذر تحميل قائمة المستأجرين.', 500);
        }
    }

    public function updateTenantStatus(Request $request, Response $response, array $args): Response
    {
        $tenantId = $this->positiveInteger($args['id'] ?? null);
        $payload = $request->getParsedBody();
        $payload = is_array($payload) ? $payload : [];
        $status = trim((string) ($payload['status'] ?? ''));
        $reason = trim((string) ($payload['reason'] ?? ''));
        $user = $request->getAttribute('user');
        $userId = is_array($user) ? $this->positiveInteger($user['id'] ?? null) : null;

        if ($tenantId === null) {
            return $this->errorResponse($response, 'معرف المستأجر غير صالح.', 400);
        }
        if (!in_array($status, ['active', 'inactive', 'trial'], true)) {
            return $this->errorResponse($response, 'الحالة المطلوبة غير صالحة.', 400);
        }
        if (strlen($reason) < 5 || strlen($reason) > 500) {
            return $this->errorResponse($response, 'سبب التغيير مطلوب (من 5 إلى 500 حرف).', 400);
        }
        if ($userId === null) {
            return $this->errorResponse($response, 'تعذر تحديد المستخدم المنفذ للعملية.', 401);
        }

        try {
            $this->db->beginTransaction();
            $lockClause = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
            $lookup = $this->db->prepare('SELECT status FROM tenants WHERE id = ? LIMIT 1' . $lockClause);
            $lookup->execute([$tenantId]);
            $previousStatus = $lookup->fetchColumn();

            if ($previousStatus === false) {
                $this->db->rollBack();
                return $this->errorResponse($response, 'المستأجر غير موجود.', 404);
            }
            if ($previousStatus === $status) {
                $this->db->rollBack();
                return $this->errorResponse($response, 'المستأجر على هذه الحالة بالفعل.', 409);
            }

            $update = $this->db->prepare('UPDATE tenants SET status = ? WHERE id = ?');
            $update->execute([$status, $tenantId]);

            $this->platformAuditLogger->record($request, 'tenant_status_changed', $tenantId, [
                'from_status' => $previousStatus,
                'to_status' => $status,
                'reason' => $reason,
            ]);

            $this->db->commit();

            return $this->successResponse($response, [
                'tenant_id' => $tenantId,
                'previous_status' => $previousStatus,
                'status' => $status,
            ]);
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logger->error('Failed to update platform tenant status', [
                'tenant_id' => $tenantId,
                'message' => $error->getMessage(),
            ]);
            return $this->errorResponse($response, 'تعذر تحديث حالة المستأجر.', 500);
        }
    }

    public function listPlatformAuditLogs(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $page = $this->positiveInteger($params['page'] ?? 1);
        $perPage = min($this->positiveInteger($params['per_page'] ?? 20) ?? 20, 100);
        $tenantId = isset($params['tenant_id']) && $params['tenant_id'] !== ''
            ? $this->positiveInteger($params['tenant_id'])
            : null;
        $actorId = isset($params['actor_id']) && $params['actor_id'] !== ''
            ? $this->positiveInteger($params['actor_id'])
            : null;
        $action = trim((string) ($params['action'] ?? ''));
        $startDate = $this->validDate($params['start_date'] ?? null);
        $endDate = $this->validDate($params['end_date'] ?? null);

        if ($page === null || (isset($params['tenant_id']) && $params['tenant_id'] !== '' && $tenantId === null)
            || (isset($params['actor_id']) && $params['actor_id'] !== '' && $actorId === null)
            || (isset($params['start_date']) && $params['start_date'] !== '' && $startDate === null)
            || (isset($params['end_date']) && $params['end_date'] !== '' && $endDate === null)
            || strlen($action) > 50
        ) {
            return $this->errorResponse($response, 'أحد مرشحات سجل التدقيق غير صالح.', 400);
        }
        if ($startDate !== null && $endDate !== null && $startDate > $endDate) {
            return $this->errorResponse($response, 'تاريخ البداية يجب ألا يتجاوز تاريخ النهاية.', 400);
        }

        $where = ["a.tenant_id IS NULL", "a.entity = 'platform_admin'"];
        $bind = [];
        if ($tenantId !== null) {
            $where[] = 'a.entity_id = :tenant_id';
            $bind[':tenant_id'] = $tenantId;
        }
        if ($actorId !== null) {
            $where[] = 'a.user_id = :actor_id';
            $bind[':actor_id'] = $actorId;
        }
        if ($action !== '') {
            $where[] = 'a.action = :action';
            $bind[':action'] = $action;
        }
        if ($startDate !== null) {
            $where[] = 'a.created_at >= :start_date';
            $bind[':start_date'] = $startDate . ' 00:00:00';
        }
        if ($endDate !== null) {
            $where[] = 'a.created_at <= :end_date';
            $bind[':end_date'] = $endDate . ' 23:59:59';
        }
        $whereSql = 'WHERE ' . implode(' AND ', $where);

        try {
            $count = $this->db->prepare("SELECT COUNT(*) FROM audit_log a {$whereSql}");
            $count->execute($bind);
            $total = (int) $count->fetchColumn();

            $statement = $this->db->prepare("
                SELECT
                    a.id,
                    a.created_at AS timestamp,
                    a.user_id AS actor_id,
                    COALESCE(u.name, u.username) AS actor_name,
                    a.action,
                    a.entity_id AS tenant_id,
                    t.company_name AS tenant_name,
                    a.details
                FROM audit_log a
                LEFT JOIN users u ON u.id = a.user_id
                LEFT JOIN tenants t ON t.id = a.entity_id
                {$whereSql}
                ORDER BY a.created_at DESC, a.id DESC
                LIMIT :limit OFFSET :offset
            ");
            foreach ($bind as $key => $value) {
                $statement->bindValue($key, $value);
            }
            $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $statement->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
            $statement->execute();
            $items = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($items as &$item) {
                if (!is_string($item['actor_name'] ?? null) || $item['actor_name'] === '') {
                    $item['actor_name'] = 'User#' . (string) ($item['actor_id'] ?? '');
                }
                $details = json_decode((string) ($item['details'] ?? ''), true);
                $item['details'] = is_array($details) ? $details : [];
            }
            unset($item);

            return $this->successResponse($response, [
                'items' => $items,
                'pagination' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'total_pages' => (int) ceil($total / $perPage),
                ],
            ]);
        } catch (Throwable $error) {
            $this->logger->error('Failed to list platform audit logs', ['message' => $error->getMessage()]);
            return $this->errorResponse($response, 'تعذر تحميل سجل تدقيق المنصة.', 500);
        }
    }

    public function getOperationsOverview(Request $request, Response $response): Response
    {
        try {
            $start = hrtime(true);
            $this->db->query('SELECT 1');
            $databaseLatencyMs = round((hrtime(true) - $start) / 1_000_000, 2);
        } catch (Throwable $error) {
            $this->logger->error('Platform database readiness check failed', ['message' => $error->getMessage()]);
            return $this->errorResponse($response, 'تعذر التحقق من جاهزية قاعدة البيانات.', 503);
        }

        try {
            $railway = $this->railwayOperations->getOverview();
        } catch (Throwable $error) {
            $this->logger->error('Failed to retrieve Railway operations overview', [
                'message' => $error->getMessage(),
            ]);
            $railway = [
                'status' => 'unavailable',
                'message' => 'تعذر الاتصال بواجهة Railway.',
                'services' => [],
                'volumes' => [],
            ];
        }

        return $this->successResponse($response, [
            'checked_at' => (new DateTimeImmutable())->format(DATE_ATOM),
            'application' => ['status' => 'online'],
            'database' => [
                'status' => 'connected',
                'latency_ms' => $databaseLatencyMs,
            ],
            'railway' => $railway,
        ]);
    }

    private function positiveInteger(mixed $value): ?int
    {
        $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $validated === false ? null : (int) $validated;
    }

    private function validDate(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
    }
}
