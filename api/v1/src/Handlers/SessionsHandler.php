<?php

declare(strict_types=1);

namespace App\Handlers;

use PDO;
use Exception;
use Throwable;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Services\MonologHandler;
use App\Utils\SuperAdminHelper;
use App\Services\CashierSessionService;
use App\Services\SessionDeniedException;
use App\Utils\RequestHelper;
use App\Utils\PaginationHelper;

/**
 * Handles cashier-session lifecycle and reporting endpoints.
 *
 * Lifecycle and individual-session summaries use CashierSessionService. Current,
 * list, and daily-summary reads query PDO directly; batch summaries validate
 * branch access before delegating bulk construction to the service. Tenant scope
 * comes from request context; list and daily-summary branch access is resolved
 * against user context, and actions use the BaseHandler audit logger.
 * Routes are registered in app/routes/sessions.php.
 */
class SessionsHandler extends BaseHandler
{
    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->logger = MonologHandler::getInstance('sessions');
    }

    private function sessionService(): CashierSessionService
    {
        static $svc = null;
        return $svc ??= new CashierSessionService($this->db);
    }

    private function logAction(int $tenantId, string $action, array $details = []): void
    {
        try {
            $userId   = isset($details['user_id']) ? (int)$details['user_id'] : null;
            $entityId = isset($details['session_id']) ? (int)$details['session_id'] : null;

            $details['ip']         = RequestHelper::getClientIpFromServer();
            $details['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? null;

            $this->audit->logAction($action, 'cashier_sessions', $entityId, $details, $tenantId, $userId);
        } catch (Throwable $e) {
            // Ignore audit failures
        }
    }

    /** Open a cashier session through the domain service and audit the result. */
    public function open(Request $request, Response $response): Response
    {
        $tenantId  = null;
        $jwtUserId = null;

        try {
            $tenantId = $request->getAttribute('tenant_id');
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 400);
            }

            $data      = $request->getParsedBody() ?? [];
            $user      = $request->getAttribute('user');
            $jwtUserId = is_array($user) ? ($user['id']      ?? null) : null;
            $jwtRoleId = is_array($user) ? ($user['role_id'] ?? null) : null;

            $result = $this->sessionService()->openSession(
                (int) $tenantId,
                $data,
                $jwtUserId ? (int)$jwtUserId : null,
                $jwtRoleId ? (int)$jwtRoleId : null,
                SuperAdminHelper::isAdminOrAbove(is_array($user) ? $user : null)
            );

            $this->logAction((int)$tenantId, 'pos_session_opened', [
                'user_id'    => $jwtUserId,
                'session_id' => $result['session_id'],
            ]);

            return $this->successResponse($response, [
                'id'                 => $result['session_id'],
                'session_type'       => $result['session_type'],
                'session_type_label' => $result['session_type_label'],
                'shift_id'           => $result['shift_id'],
                'auto_closed'        => $result['auto_closed'],
            ], 200);
        } catch (SessionDeniedException $e) {
            // سجّل الرفض مع الـ context التفصيلي من الـ Service
            $this->logAction((int)($tenantId ?? 0), $e->auditEvent, array_merge(
                $e->auditContext,
                ['user_id' => $jwtUserId ?? null]
            ));
            return $this->errorResponse($response, $e->getMessage(), $e->httpCode);
        } catch (Exception $e) {
            $this->logger->error('Session open failed', ['message' => $e->getMessage()]);
            return $this->errorResponse($response, 'فشل فتح الجلسة', 500);
        }
    }

    /** Close a session and return its cash reconciliation summary. */
    public function close(Request $request, Response $response, array $args = []): Response
    {
        try {
            $tenantId = $request->getAttribute('tenant_id');
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $data        = $request->getParsedBody() ?? [];
            $sessionId   = $args['id'] ?? $data['session_id'] ?? null;
            $closingCash = isset($data['closing_cash_amount']) ? (float)$data['closing_cash_amount'] : null;

            $user      = $request->getAttribute('user');
            $jwtUserId = is_array($user) ? ($user['id'] ?? null) : null;
            $closedBy  = $data['closed_by'] ?? $jwtUserId;

            if (!$sessionId) {
                return $this->errorResponse($response, 'مطلوب معرف الجلسة (session_id).', 400);
            }
            if ($closingCash === null || $closingCash < 0) {
                return $this->errorResponse($response, 'حقل closing_cash_amount مطلوب ويجب أن يكون رقمًا غير سالب.', 400);
            }

            $svc     = $this->sessionService();
            $summary = $svc->closeSession(
                (int)$tenantId,
                (int)$sessionId,
                $closingCash,
                $closedBy ? (int)$closedBy : null,
                $data['variance_reason'] ?? null
            );

            // Audit — حقول صريحة كما في الأصل (pre-close snapshot مع variance)
            try {
                $closing = $summary['closing'] ?? [];
                $this->audit->logAction(
                    'session_closed',
                    'cashier_sessions',
                    (int)$sessionId,
                    [
                        'session_id'          => (int)$sessionId,
                        'tenant_id'           => (int)$tenantId,
                        'user_id'             => $jwtUserId,
                        'closed_by'           => $closedBy,
                        'closing_cash_amount' => $closing['closing_cash_amount'] ?? $closingCash,
                        'expected_cash'       => $closing['expected_cash']       ?? null,
                        'variance'            => $closing['variance']            ?? null,
                        'variance_reason'     => $closing['variance_reason']     ?? null,
                        'closed_at'           => $closing['closed_at']           ?? null,
                        'summary'             => $summary,
                    ],
                    (int)$tenantId,
                    $jwtUserId ? (int)$jwtUserId : null
                );
            } catch (Throwable $e) {
                $this->logger->warning('Failed to log close session action', [
                    'message' => $e->getMessage()
                ]);
            }

            return $this->successResponse($response, $summary, 200);
        } catch (SessionDeniedException $e) {
            return $this->errorResponse($response, $e->getMessage(), $e->httpCode);
        } catch (Exception $e) {
            $this->logger->error('Session close failed', ['message' => $e->getMessage()]);
            return $this->errorResponse($response, 'فشل إغلاق الجلسة', 500);
        }
    }

    /** Return the open session matching the supplied cashier, branch, or device filters. */
    public function current(Request $request, Response $response): Response
    {
        try {
            $tenantId = $request->getAttribute('tenant_id');
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $this->sessionService()->checkAndCloseInactiveAdminSessions((int)$tenantId);

            $q         = $request->getQueryParams();
            $branchId  = $q['branch_id']  ?? null;
            $jwtUser   = $request->getAttribute('user');
            $jwtUserId = is_array($jwtUser) ? ($jwtUser['id'] ?? null) : null;
            $cashierId = $q['cashier_id'] ?? $q['user_id'] ?? $jwtUserId ?? null;
            $deviceId  = $q['device_id']  ?? null;

            $baseSql = "SELECT * FROM cashier_sessions WHERE tenant_id = ? AND status = 'open'";
            $session = null;

            if ($branchId !== null && $branchId !== '' && $cashierId !== null && $cashierId !== '' && $deviceId !== null && $deviceId !== '') {
                $s = $this->db->prepare($baseSql . " AND branch_id = ? AND cashier_id = ? AND device_id = ? ORDER BY start_time DESC LIMIT 1");
                $s->execute([$tenantId, $branchId, $cashierId, $deviceId]);
                $session = $s->fetch(PDO::FETCH_ASSOC);
            }
            if (!$session && $branchId !== null && $branchId !== '' && $cashierId !== null && $cashierId !== '') {
                $s = $this->db->prepare($baseSql . " AND branch_id = ? AND cashier_id = ? ORDER BY start_time DESC LIMIT 1");
                $s->execute([$tenantId, $branchId, $cashierId]);
                $session = $s->fetch(PDO::FETCH_ASSOC);
            }
            if (!$session && $cashierId !== null && $cashierId !== '') {
                $s = $this->db->prepare($baseSql . " AND cashier_id = ? ORDER BY start_time DESC LIMIT 1");
                $s->execute([$tenantId, $cashierId]);
                $session = $s->fetch(PDO::FETCH_ASSOC);
            }
            if (!$session && $deviceId !== null && $deviceId !== '') {
                $s = $this->db->prepare($baseSql . " AND device_id = ? ORDER BY start_time DESC LIMIT 1");
                $s->execute([$tenantId, $deviceId]);
                $session = $s->fetch(PDO::FETCH_ASSOC);
            }

            if ($session) {
                $session['session_type_label'] = $this->sessionService()->getSessionTypeLabel($session['session_type'] ?? 'manual');
            }

            return $this->successResponse($response, $session ?: null, 200);
        } catch (Exception $e) {
            $this->logger->error('Get current session failed', ['message' => $e->getMessage()]);
            return $this->errorResponse($response, 'فشل في جلب الجلسة الحالية', 500);
        }
    }

    /** Return paginated sessions and cash KPIs for the resolved tenant/branch scope. */
    public function listSessions(Request $request, Response $response): Response
    {
        try {
            $tenantId = $request->getAttribute('tenant_id');
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $q           = $request->getQueryParams();
            $status      = $q['status']       ?? null;
            $branchScope = $this->resolveBranchScope(
                $request,
                (int) $tenantId,
                $q['branch_id'] ?? null
            );
            if (isset($branchScope['error'])) {
                return $this->errorResponse(
                    $response,
                    $branchScope['error'],
                    $branchScope['status']
                );
            }
            $branchId    = $branchScope['branch_id'];
            $cashierId   = $q['cashier_id']   ?? null;
            $sessionType = $q['session_type'] ?? null;
            $device      = $q['device']       ?? $q['device_name'] ?? null;
            $fromDate    = $q['from_date']    ?? null;
            $toDate      = $q['to_date']      ?? null;
            // Normalize variance filter: accept 'yes', 'true', '1' uniformly
            $hasVarianceRaw = $q['has_variance'] ?? null;
            $hasVariance    = in_array($hasVarianceRaw, ['yes','true','1'], true)
                                ? 'yes'
                                : (in_array($hasVarianceRaw, ['no','false','0'], true) ? 'no' : null);
            [$page, $limit, $offset] = PaginationHelper::fromArray($q, 20, 100);

            $baseSql = "
                FROM cashier_sessions cs
                LEFT JOIN users u  ON cs.cashier_id = u.id  AND u.tenant_id  = cs.tenant_id
                LEFT JOIN users u2 ON cs.closed_by  = u2.id AND u2.tenant_id = cs.tenant_id
                LEFT JOIN (
                    SELECT
                        session_id,
                        COALESCE(SUM(CASE WHEN type IN ('income','return_receipt','sale','deposit')              THEN amount ELSE 0 END), 0) AS cash_in,
                        COALESCE(SUM(CASE WHEN type IN ('expense','return_payment','purchase','withdrawal')      THEN amount ELSE 0 END), 0) AS cash_out
                    FROM cash_transactions
                    WHERE tenant_id = ? AND COALESCE(status, '') <> 'cancelled'
                    GROUP BY session_id
                ) ct ON ct.session_id = cs.id
                WHERE cs.tenant_id = ?
            ";
            $params = [$tenantId, $tenantId];

            if ($status      !== null && $status      !== '') {
                $baseSql .= " AND cs.status = ?";
                $params[] = $status;
            }
            if ($branchId    !== null && $branchId    !== '') {
                $baseSql .= " AND cs.branch_id = ?";
                $params[] = $branchId;
            }
            if ($cashierId   !== null && $cashierId   !== '') {
                $baseSql .= " AND cs.cashier_id = ?";
                $params[] = $cashierId;
            }
            if ($sessionType !== null && $sessionType !== '') {
                $baseSql .= " AND cs.session_type = ?";
                $params[] = $sessionType;
            }
            if ($device      !== null && $device      !== '') {
                $baseSql .= " AND (cs.device_name = ? OR cs.device_id = ?)";
                $params[] = $device;
                $params[] = $device;
            }
            if ($fromDate    !== null && $fromDate    !== '') {
                $baseSql .= " AND cs.start_time >= ?";
                $params[] = $fromDate . ' 00:00:00';
            }
            if ($toDate      !== null && $toDate      !== '') {
                $baseSql .= " AND cs.start_time < ?";
                $params[] = date('Y-m-d', strtotime($toDate . ' +1 day')) . ' 00:00:00';
            }
            if ($hasVariance !== null) {
                if ($hasVariance === 'yes') {
                    $baseSql .= " AND cs.closing_cash_amount IS NOT NULL AND ABS(cs.closing_cash_amount - cs.opening_cash_amount - COALESCE(ct.cash_in,0) + COALESCE(ct.cash_out,0)) > 0.01";
                } else {
                    $baseSql .= " AND (cs.closing_cash_amount IS NULL OR ABS(cs.closing_cash_amount - cs.opening_cash_amount - COALESCE(ct.cash_in,0) + COALESCE(ct.cash_out,0)) <= 0.01)";
                }
            }

            $selectFields = "
                cs.*,
                u.name  AS cashier_name,
                u2.name AS closed_by_name,
                COALESCE(ct.cash_in,  0) AS cash_in,
                COALESCE(ct.cash_out, 0) AS cash_out,
                -- variance = closing - expected = closing - (opening + cash_in - cash_out)
                CASE WHEN cs.closing_cash_amount IS NOT NULL
                     THEN cs.closing_cash_amount - cs.opening_cash_amount - COALESCE(ct.cash_in,0) + COALESCE(ct.cash_out,0)
                     ELSE NULL
                END AS variance_amount
            ";

            $stmt = $this->db->prepare("SELECT $selectFields $baseSql ORDER BY cs.start_time DESC LIMIT ? OFFSET ?");
            $stmt->execute(array_merge($params, [$limit, $offset]));
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $svc = $this->sessionService();
            foreach ($items as &$item) {
                $item['session_type_label'] = $svc->getSessionTypeLabel($item['session_type'] ?? 'manual');
                if (empty($item['cashier_name'])) {
                    $item['cashier_name']    = 'مستخدم #' . ($item['cashier_id'] ?? '');
                }
                if (!empty($item['closed_by']) && empty($item['closed_by_name'])) {
                    $item['closed_by_name'] = 'مستخدم #' . $item['closed_by'];
                }
                $item['variance_amount'] = isset($item['variance_amount']) ? (float)$item['variance_amount'] : null;
                $item['cash_in']         = (float)($item['cash_in']  ?? 0);
                $item['cash_out']        = (float)($item['cash_out'] ?? 0);
            }
            unset($item);

            $countStmt = $this->db->prepare("SELECT COUNT(*) $baseSql");
            $countStmt->execute($params);
            $total = (int) $countStmt->fetchColumn();

            $kpiSql = "
                SELECT
                    COUNT(*) AS total_sessions,
                    COALESCE(SUM(cs.opening_cash_amount + COALESCE(ct.cash_in,0) - COALESCE(ct.cash_out,0)), 0) AS total_expected_cash,
                    COUNT(CASE
                        WHEN cs.closing_cash_amount IS NOT NULL
                         AND ABS(cs.closing_cash_amount - cs.opening_cash_amount - COALESCE(ct.cash_in,0) + COALESCE(ct.cash_out,0)) > 0.01
                        THEN 1 END
                    ) AS sessions_with_variance
                $baseSql
            ";
            $kpiStmt = $this->db->prepare($kpiSql);
            $kpiStmt->execute($params);
            $kpi = $kpiStmt->fetch(PDO::FETCH_ASSOC);

            return $this->successResponse($response, [
                'items'       => $items,
                'total'       => $total,
                'page'        => $page,
                'limit'       => $limit,
                'total_pages' => (int)ceil($total / $limit),
                'kpi'         => [
                    'total_sessions'         => (int)($kpi['total_sessions']         ?? 0),
                    'total_expected_cash'    => (float)($kpi['total_expected_cash']  ?? 0),
                    'sessions_with_variance' => (int)($kpi['sessions_with_variance'] ?? 0),
                ],
            ], 200);
        } catch (Exception $e) {
            $this->logger->error('List sessions failed', ['message' => $e->getMessage()]);
            return $this->errorResponse($response, 'فشل في جلب قائمة الجلسات', 500);
        }
    }

    /** Return a session summary after checking tenant and branch access. */
    public function summary(Request $request, Response $response, array $args): Response
    {
        try {
            $tenantId  = $request->getAttribute('tenant_id');
            $sessionId = $args['id'] ?? null;

            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }
            if (!$sessionId) {
                return $this->errorResponse($response, 'مطلوب معرف الجلسة.', 400);
            }

            $summary = $this->sessionService()->buildSessionSummary((int)$tenantId, (int)$sessionId);

            if (!$summary['session']) {
                return $this->errorResponse($response, 'لم يتم العثور على الجلسة.', 404);
            }
            if (!$this->canAccessSessionBranch(
                $request,
                (int) ($summary['session']['branch_id'] ?? 0)
            )) {
                return $this->errorResponse($response, 'لم يتم العثور على الجلسة.', 404);
            }

            return $this->successResponse($response, $summary, 200);
        } catch (Exception $e) {
            $this->logger->error('Session summary failed', ['message' => $e->getMessage()]);
            return $this->errorResponse($response, 'فشل في جلب ملخص الجلسة', 500);
        }
    }

    private function resolveBranchScope(
        Request $request,
        int $tenantId,
        mixed $requestedBranchId
    ): array {
        $user = $request->getAttribute('user');
        $user = is_array($user) ? $user : null;
        $branchId = null;

        if ($requestedBranchId !== null && $requestedBranchId !== '') {
            $branchId = filter_var(
                $requestedBranchId,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );
            if ($branchId === false) {
                return ['error' => 'معرف الفرع غير صالح.', 'status' => 400];
            }
        }

        if (!$this->canViewAllBranches($user)) {
            $userBranchId = (int) ($user['branch_id'] ?? 0);
            if ($userBranchId <= 0) {
                return ['error' => 'لم يتم تعيين فرع لحسابك.', 'status' => 403];
            }
            if ($branchId !== null && (int) $branchId !== $userBranchId) {
                return ['error' => 'غير مصرح لك بعرض بيانات هذا الفرع.', 'status' => 403];
            }
            $branchId = $userBranchId;
        }

        if ($branchId !== null) {
            $branchStmt = $this->db->prepare(
                'SELECT 1 FROM branches WHERE id = ? AND tenant_id = ? LIMIT 1'
            );
            $branchStmt->execute([(int) $branchId, $tenantId]);
            if (!$branchStmt->fetchColumn()) {
                return ['error' => 'الفرع المحدد غير موجود.', 'status' => 404];
            }
        }

        return ['branch_id' => $branchId === null ? null : (int) $branchId];
    }

    /** Managers and administrators can query across branches. */
    private function canViewAllBranches(?array $user): bool
    {
        if ($user === null) {
            return false;
        }

        $roles = [];
        if (isset($user['roles']) && is_array($user['roles'])) {
            $roles = array_map(
                static fn ($role): string => strtolower((string) $role),
                $user['roles']
            );
        } elseif (isset($user['role']) && is_string($user['role'])) {
            $roles = [strtolower($user['role'])];
        }

        return SuperAdminHelper::isAdminOrAbove($user)
            || in_array('manager', $roles, true);
    }

    private function canAccessSessionBranch(Request $request, int $branchId): bool
    {
        $user = $request->getAttribute('user');
        $user = is_array($user) ? $user : null;
        if ($this->canViewAllBranches($user)) {
            return true;
        }

        return $branchId > 0 && $branchId === (int) ($user['branch_id'] ?? 0);
    }

    /** Return up to 100 session summaries after tenant and branch access checks. */
    public function batchSummaries(Request $request, Response $response): Response
    {
        try {
            $tenantId = $request->getAttribute('tenant_id');
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $data = $request->getParsedBody() ?? [];
            $sessionIds = $data['session_ids'] ?? [];

            if (!is_array($sessionIds) || count($sessionIds) === 0) {
                return $this->errorResponse($response, 'مطلوب قائمة معرفات الجلسات (session_ids).', 400);
            }

            if (count($sessionIds) > 100) {
                return $this->errorResponse($response, 'يمكن طلب ملخصات لـ 100 جلسة كحد أقصى فقط.', 400);
            }

            $sessionIds = array_unique(array_map(
                fn($id) => (int)$id,
                array_filter($sessionIds, fn($id) => !empty($id))
            ));

            if (count($sessionIds) === 0) {
                return $this->successResponse($response, [], 200);
            }
            $user = $request->getAttribute('user');
            $user = is_array($user) ? $user : null;
            if (!$this->canViewAllBranches($user)) {
                $userBranchId = (int) ($user['branch_id'] ?? 0);
                if ($userBranchId <= 0) {
                    return $this->errorResponse($response, 'لم يتم تعيين فرع لحسابك.', 403);
                }
                $placeholders = implode(',', array_fill(0, count($sessionIds), '?'));
                $accessStmt = $this->db->prepare(
                    "SELECT COUNT(DISTINCT id)
                     FROM cashier_sessions
                     WHERE tenant_id = ? AND branch_id = ? AND id IN ({$placeholders})"
                );
                $accessStmt->execute(array_merge(
                    [(int) $tenantId, $userBranchId],
                    $sessionIds
                ));
                if ((int) $accessStmt->fetchColumn() !== count($sessionIds)) {
                    return $this->errorResponse($response, 'غير مصرح لك بعرض بعض الجلسات المطلوبة.', 404);
                }
            }

            $summaries = $this->sessionService()->buildBulkSessionSummaries((int)$tenantId, $sessionIds);

            return $this->successResponse($response, $summaries, 200);
        } catch (Throwable $e) {
            $this->logger->error('Batch summaries failed', ['message' => $e->getMessage()]);
            return $this->errorResponse($response, 'فشل في جلب ملخصات الجلسات', 500);
        }
    }
}
