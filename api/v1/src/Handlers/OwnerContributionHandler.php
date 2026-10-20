<?php

declare(strict_types=1);

namespace App\Handlers;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Services\OwnerContributionService;
use App\Services\MonologHandler;
use App\Handlers\RBACHandler;
use App\Security\Permissions;
use App\Utils\SuperAdminHelper;

/**
 * HTTP endpoints for recording, retrieving, listing, and voiding owner contributions.
 *
 * Enforces owner-contribution permissions through RBACHandler and delegates
 * accounting and liquidity operations to OwnerContributionService.
 */
class OwnerContributionHandler extends BaseHandler
{
    private $rbac;

    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->logger = MonologHandler::getInstance('owner_contribution_handler');
        $this->rbac = new RBACHandler($db);
    }

    /** List active tenant identities for the platform tenant-context selector. */
    public function listAvailableTenants(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        if (!SuperAdminHelper::is(is_array($user) ? $user : null)) {
            return $this->errorResponse($response, 'هذا الإجراء متاح لمدير النظام الرئيسي فقط', 403);
        }

        try {
            $stmt = $this->db->query(
                "SELECT id, company_name
                 FROM tenants
                 WHERE status = 'active'
                 ORDER BY company_name ASC, id ASC"
            );
            return $this->successResponse($response, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to list active tenants for owner-contribution context', [
                'user_id' => $this->extractUserId($request),
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse($response, 'تعذر تحميل قائمة الشركات النشطة', 500);
        }
    }

    /** Validate create permission and input before recording a contribution. */
    public function create(Request $request, Response $response): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            $userId = $this->extractUserId($request);

            if (!$tenantId) {
                return $this->errorResponse($response, 'Tenant context required', 400);
            }

            if (!$userId || !$this->rbac || !$this->rbac->hasPermission($userId, Permissions::ACCOUNTING_OWNER_CONTRIBUTION_CREATE)) {
                $this->logger->warning('Owner contribution create - permission denied', [
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                ]);
                return $this->errorResponse($response, 'ليس لديك صلاحية لإنشاء مساهمات المالك', 403);
            }

            $data = $request->getParsedBody();
            $data = is_array($data) ? $data : [];

            $required = ['contribution_type', 'amount', 'target_account_id', 'related_gl_account_id'];
            foreach ($required as $field) {
                if (!isset($data[$field])) {
                    return $this->errorResponse($response, "الحقل {$field} مطلوب", 422);
                }
            }

            $service = new OwnerContributionService($this->db, $tenantId, $userId, $this->audit);

            $result = $service->recordContribution(
                $data['contribution_type'],
                (float) $data['amount'],
                (int) $data['target_account_id'],
                (int) $data['related_gl_account_id'],
                isset($data['interest_rate']) ? (float) $data['interest_rate'] : null,
                $data['due_date'] ?? null,
                $data['repayment_terms'] ?? null,
                $data['repayment_schedule'] ?? null,
                isset($data['cost_center_id']) ? (int) $data['cost_center_id'] : null,
                $data['idempotency_key'] ?? null
            );

            $statusCode = $result['duplicate'] ? 409 : 201;

            return $this->successResponse($response, $result, $statusCode);

        } catch (\DomainException $e) {
            return $this->errorResponse($response, $e->getMessage(), 409);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to create owner contribution', [
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse($response, $e->getMessage(), 400);
        }
    }

    /** Return one contribution after checking view permission. */
    public function getById(Request $request, Response $response, array $args): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            $userId = $this->extractUserId($request);
            $contributionId = (int) $args['id'];

            if (!$tenantId) {
                return $this->errorResponse($response, 'Tenant context required', 400);
            }

            if (!$userId || !$this->rbac || !$this->rbac->hasPermission($userId, Permissions::ACCOUNTING_OWNER_CONTRIBUTION_VIEW)) {
                $this->logger->warning('Owner contribution view - permission denied', [
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'contribution_id' => $contributionId,
                ]);
                return $this->errorResponse($response, 'ليس لديك صلاحية لعرض مساهمات المالك', 403);
            }

            $service = new OwnerContributionService($this->db, $tenantId, $userId, $this->audit);
            $contribution = $service->getContributionById($contributionId);

            if (!$contribution) {
                return $this->errorResponse($response, 'المساهمة غير موجودة', 404);
            }

            return $this->successResponse($response, $contribution);

        } catch (\Throwable $e) {
            $this->logger->error('Failed to get contribution', [
                'contribution_id' => $args['id'] ?? null,
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse($response, $e->getMessage(), 400);
        }
    }

    /** List contributions with optional type and status filters. */
    public function list(Request $request, Response $response): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            $userId = $this->extractUserId($request);
            $params = $request->getQueryParams();

            if (!$tenantId) {
                return $this->errorResponse($response, 'Tenant context required', 400);
            }

            if (!$userId || !$this->rbac || !$this->rbac->hasPermission($userId, Permissions::ACCOUNTING_OWNER_CONTRIBUTION_VIEW)) {
                $this->logger->warning('Owner contribution list - permission denied', [
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                ]);
                return $this->errorResponse($response, 'ليس لديك صلاحية لعرض مساهمات المالك', 403);
            }

            $filters = [];
            if (!empty($params['contribution_type'])) {
                $filters['contribution_type'] = $params['contribution_type'];
            }
            if (!empty($params['status'])) {
                $filters['status'] = $params['status'];
            }

            $limit = isset($params['limit']) ? (int) $params['limit'] : 50;
            $offset = isset($params['offset']) ? (int) $params['offset'] : 0;

            $service = new OwnerContributionService($this->db, $tenantId, $userId, $this->audit);
            $result = $service->listContributions($filters, $limit, $offset);

            return $this->successResponse($response, $result);

        } catch (\Throwable $e) {
            $this->logger->error('Failed to list contributions', [
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse($response, $e->getMessage(), 400);
        }
    }

    /** Validate void permission and cash-session context before reversing a contribution. */
    public function void(Request $request, Response $response, array $args): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            $userId = $this->extractUserId($request);
            $contributionId = (int) $args['id'];
            $data = $request->getParsedBody();
            $data = is_array($data) ? $data : [];
            $data = is_array($data) ? $data : [];

            if (!$tenantId) {
                return $this->errorResponse($response, 'Tenant context required', 400);
            }

            // Permission check
            if (!$userId || !$this->rbac || !$this->rbac->hasPermission($userId, Permissions::ACCOUNTING_OWNER_CONTRIBUTION_VOID)) {
                $this->logger->warning('Owner contribution void - permission denied', [
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'contribution_id' => $contributionId,
                ]);
                return $this->errorResponse($response, 'ليس لديك صلاحية لإلغاء مساهمات المالك', 403);
            }

            $paymentMethodId = (int) ($data['payment_method_id'] ?? 0);
            if ($paymentMethodId <= 0) {
                return $this->errorResponse($response, 'يجب تحديد طريقة الدفع لعكس المساهمة.', 400);
            }
            $branchId = !empty($data['branch_id']) ? (int) $data['branch_id'] : null;
            $sessionId = null;
            if ($this->isCashMethod($paymentMethodId, (int) $tenantId)) {
                $sessionsEnabled = $this->isSessionsEnabled((int) $tenantId);
                $isExempt = $this->isCashierSessionExempt($request);
                if ($sessionsEnabled && !$isExempt) {
                    if (!$branchId) {
                        return $this->errorResponse($response, 'يجب تحديد الفرع لعكس المساهمة نقدًا.', 400);
                    }
                    $sessionId = $this->requireOpenCashierSession((int) $tenantId, $branchId, $userId);
                } elseif ($branchId) {
                    $sessionId = $this->findOpenCashierSession((int) $tenantId, $branchId, null);
                }
            }

            $reason = (string) ($data['reason'] ?? '');

            $service = new OwnerContributionService($this->db, $tenantId, $userId, $this->audit);
            $result = $service->voidContribution(
                $contributionId,
                $paymentMethodId,
                $sessionId,
                $branchId,
                $reason
            );

            return $this->successResponse($response, $result);

        } catch (\App\Exceptions\InsufficientLiquidityException $e) {
            return $this->errorResponse($response, $e->getMessage(), 402, [
                'error_code' => 'insufficient_liquidity',
                'details' => $e->getContext(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to void contribution', [
                'contribution_id' => $args['id'] ?? null,
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse($response, $e->getMessage(), 400);
        }
    }
}
