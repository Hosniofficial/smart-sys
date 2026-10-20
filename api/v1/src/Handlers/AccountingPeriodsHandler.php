<?php

declare(strict_types=1);

namespace App\Handlers;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Services\MonologHandler;
use App\Services\AccountingPeriodCloseService;
use App\Security\Permissions;

/**
 * Handles tenant-scoped accounting period listing, creation, closure, reopening, and deletion.
 *
 * Period closure and its blocker/override audit rules are delegated to
 * AccountingPeriodCloseService; the other operations use the period table directly.
 */
class AccountingPeriodsHandler extends BaseHandler
{
    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->logger = MonologHandler::getInstance('accounting_periods');
    }

    /** List tenant periods, optionally filtered by status. */
    public function list(Request $request, Response $response): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'Tenant ID مطلوب', 403);
            }

            $params   = $request->getQueryParams();
            $status   = $params['status'] ?? null;

            $sql  = "SELECT id, period_name, start_date, end_date, status, notes, closed_at, created_at, updated_at
                     FROM accounting_periods
                     WHERE tenant_id = ? AND period_name IS NOT NULL AND period_name != ''";
            $bind = [(int) $tenantId];

            if ($status) {
                $sql .= " AND status = ?";
                $bind[] = $status;
            }
            $sql .= " ORDER BY start_date DESC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($bind);
            return $this->successResponse($response, $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (\Throwable $e) {
            $this->logger->error('Error listing periods', ['error' => $e->getMessage()]);
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    /** Create an open period for the current tenant. */
    public function create(Request $request, Response $response): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'Tenant ID مطلوب', 403);
            }

            $data = $request->getParsedBody() ?? [];
            $name  = trim($data['period_name'] ?? '');
            $start = $data['start_date'] ?? '';
            $end   = $data['end_date']   ?? '';

            if (!$name || !$start || !$end) {
                return $this->errorResponse($response, 'period_name و start_date و end_date مطلوبة', 400);
            }
            if ($start > $end) {
                return $this->errorResponse($response, 'start_date يجب أن يكون قبل end_date', 400);
            }

            $userId = $this->extractUserId($request);

            $stmt = $this->db->prepare("
                INSERT INTO accounting_periods (tenant_id, period_name, start_date, end_date, status, notes, created_by)
                VALUES (?, ?, ?, ?, 'open', ?, ?)
            ");
            $stmt->execute([(int) $tenantId, $name, $start, $end, $data['notes'] ?? null, $userId]);
            $id = (int) $this->db->lastInsertId();

            return $this->successResponse($response, ['id' => $id, 'message' => 'تم إنشاء الدورة المحاسبية'], 201);
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'Duplicate entry')) {
                return $this->errorResponse($response, 'توجد دورة بنفس التواريخ مسبقاً', 409);
            }
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    /**
     * PUT /accounting-periods/{id}/close.
     * An override requires the dedicated permission and a non-empty reason; ordinary close requests
     * are blocked by the service when unresolved inventory or draft journal entries exist.
     */
    public function close(Request $request, Response $response, array $args): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'Tenant ID مطلوب', 403);
            }

            $data = $request->getParsedBody() ?? [];
            $overrideRequested = filter_var($data['override'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $overrideReason = trim((string) ($data['override_reason'] ?? ''));
            $canOverride = false;

            if ($overrideRequested) {
                if ($overrideReason === '') {
                    return $this->errorResponse($response, 'سبب التجاوز مطلوب.', 400);
                }
                $canOverride = $this->can($request, Permissions::ACCOUNTING_PERIOD_CLOSE_OVERRIDE);
                if (!$canOverride) {
                    return $this->errorResponse($response, 'لا تملك صلاحية تجاوز فحوصات إقفال الفترة.', 403);
                }
            }

            $periodId = (int) ($args['id'] ?? 0);
            $userId   = $this->extractUserId($request);
            $serverParams = $request->getServerParams();
            $result = (new AccountingPeriodCloseService())->close(
                $this->db,
                (int) $tenantId,
                $periodId,
                $userId,
                $overrideRequested,
                $canOverride,
                $overrideReason,
                isset($serverParams['REMOTE_ADDR']) ? (string) $serverParams['REMOTE_ADDR'] : null
            );

            if ($result['http_status'] !== 200) {
                $extra = isset($result['blockers']) ? ['blockers' => $result['blockers']] : [];
                return $this->errorResponse($response, $result['message'], $result['http_status'], $extra);
            }

            $this->logger->info('Accounting period closed', [
                'period_id' => $periodId,
                'tenant_id' => $tenantId,
                'overridden' => $result['overridden'],
            ]);
            return $this->successResponse($response, [
                'message' => $result['message'],
                'overridden' => $result['overridden'],
                'blockers' => $result['blockers'],
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    /** Reopen a closed period belonging to the current tenant. */
    public function reopen(Request $request, Response $response, array $args): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'Tenant ID مطلوب', 403);
            }

            $periodId = (int) ($args['id'] ?? 0);

            $stmt = $this->db->prepare("
                UPDATE accounting_periods
                SET status = 'open', closed_by = NULL, closed_at = NULL, updated_at = NOW()
                WHERE id = ? AND tenant_id = ? AND status = 'closed'
            ");
            $stmt->execute([$periodId, (int) $tenantId]);

            if ($stmt->rowCount() === 0) {
                return $this->errorResponse($response, 'الدورة غير موجودة أو مفتوحة مسبقاً', 404);
            }

            $this->logger->info('Accounting period reopened', ['period_id' => $periodId]);
            return $this->successResponse($response, ['message' => 'تم إعادة فتح الدورة المحاسبية']);
        } catch (\Throwable $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    /** Delete an open period belonging to the current tenant. */
    public function delete(Request $request, Response $response, array $args): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'Tenant ID مطلوب', 403);
            }

            $periodId = (int) ($args['id'] ?? 0);

            $stmt = $this->db->prepare("
                DELETE FROM accounting_periods WHERE id = ? AND tenant_id = ? AND status = 'open'
            ");
            $stmt->execute([$periodId, (int) $tenantId]);

            if ($stmt->rowCount() === 0) {
                return $this->errorResponse($response, 'الدورة غير موجودة أو لا يمكن حذف دورة مغلقة', 404);
            }

            return $this->successResponse($response, ['message' => 'تم حذف الدورة المحاسبية']);
        } catch (\Throwable $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }
}
