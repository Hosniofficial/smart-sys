<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Services\MonologHandler;
use Exception;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Provides the shift lookup used by the cashier UI. */
class ShiftsHandler extends BaseHandler
{
    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->logger = MonologHandler::getInstance('shifts');
    }

    public function current(Request $request, Response $response): Response
    {
        $tenantId = null;
        $branchId = null;
        $terminalId = null;

        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                $this->logger->warning('Shifts current - missing tenant ID');
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $queryParams = $request->getQueryParams() ?? [];
            $branchId = $queryParams['branch_id'] ?? null;
            $terminalId = $queryParams['terminal_id'] ?? null;

            if (!$branchId || !$terminalId) {
                return $this->errorResponse(
                    $response,
                    'مطلوب كل من branch_id و terminal_id لاسترجاع الشفت الحالي.',
                    400
                );
            }

            $stmt = $this->db->prepare(
                "SELECT *
                 FROM cashier_shifts
                 WHERE tenant_id = ?
                   AND branch_id = ?
                   AND terminal_id = ?
                   AND status = 'open'
                 ORDER BY start_time DESC
                 LIMIT 1"
            );
            $stmt->execute([(int) $tenantId, (int) $branchId, (int) $terminalId]);
            $shift = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

            return $this->successResponse($response, $shift);
        } catch (Exception $e) {
            $this->logger->error('Current shift retrieval failed', [
                'message' => $e->getMessage(),
                'tenant_id' => $tenantId ?? 'unknown',
                'branch_id' => $branchId ?? 'unknown',
                'terminal_id' => $terminalId ?? 'unknown',
            ]);

            return $this->errorResponse($response, 'فشل في استرجاع الشفت الحالي', 400);
        }
    }
}
