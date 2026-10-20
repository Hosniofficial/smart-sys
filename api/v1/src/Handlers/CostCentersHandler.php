<?php

declare(strict_types=1);

namespace App\Handlers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

final class CostCentersHandler extends BaseHandler
{
    public function list(Request $request, Response $response): Response
    {
        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
        }

        try {
            $stmt = $this->db->prepare(
                'SELECT id, name, code
                 FROM cost_centers
                 WHERE tenant_id = :tenant_id AND is_active = 1
                 ORDER BY name ASC, id ASC'
            );
            $stmt->execute(['tenant_id' => $tenantId]);

            return $this->successResponse($response, $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
        } catch (Throwable $e) {
            $this->logger->error('Error fetching cost centers', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse($response, 'فشل في جلب مراكز التكلفة.', 500);
        }
    }
}
