<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Services\PriceCampaignService;
use App\Utils\PaginationHelper;
use App\Utils\SuperAdminHelper;
use DateTimeImmutable;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

final class SalesPriceCampaignsHandler extends BaseHandler
{
    public function __construct(PDO $db)
    {
        parent::__construct($db);
    }

    public function list(Request $request, Response $response): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId || !$this->canManage($request)) {
                return $this->errorResponse($response, 'لا تملك صلاحية إدارة الحملات السعرية.', 403);
            }

            [$page, $perPage, $offset] = PaginationHelper::fromRequest($request, 20, 100);
            $query = trim((string) ($request->getQueryParams()['q'] ?? ''));
            $status = trim((string) ($request->getQueryParams()['status'] ?? ''));
            $where = ['c.tenant_id = ?'];
            $params = [(int) $tenantId];
            if ($this->isBranchManager($request)) {
                $branchId = $this->branchManagerBranchId($request, (int) $tenantId);
                $where[] = '(c.branch_id = ? OR c.branch_id IS NULL)';
                $params[] = $branchId;
            }
            if ($query !== '') {
                $where[] = 'c.name LIKE ?';
                $params[] = '%' . $query . '%';
            }
            if (in_array($status, ['active', 'paused'], true)) {
                $where[] = 'c.status = ?';
                $params[] = $status;
            }
            $whereSql = implode(' AND ', $where);

            $count = $this->db->prepare("SELECT COUNT(*) FROM sales_price_campaigns c WHERE {$whereSql}");
            $count->execute($params);
            $total = (int) $count->fetchColumn();

            $stmt = $this->db->prepare(
                "SELECT c.id, c.branch_id, b.name AS branch_name, c.name, c.campaign_type,
                        c.start_date, c.end_date, c.priority, c.status, c.created_at,
                        COUNT(i.id) AS product_count
                 FROM sales_price_campaigns c
                 LEFT JOIN branches b ON b.id = c.branch_id AND b.tenant_id = c.tenant_id
                 LEFT JOIN sales_price_campaign_items i ON i.campaign_id = c.id AND i.tenant_id = c.tenant_id
                 WHERE {$whereSql}
                 GROUP BY c.id
                 ORDER BY c.status ASC, c.priority DESC, c.id DESC
                 LIMIT {$perPage} OFFSET {$offset}"
            );
            $stmt->execute($params);
            $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            return $this->successResponse($response, [
                'items' => $campaigns,
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
            ]);
        } catch (Throwable $error) {
            return $this->failureResponse($response, $error, 'list');
        }
    }

    public function create(Request $request, Response $response): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            $userId = $this->extractUserId($request);
            if (!$tenantId || !$userId || !$this->canManage($request)) {
                return $this->errorResponse($response, 'لا تملك صلاحية إنشاء الحملات السعرية.', 403);
            }
            $data = $request->getParsedBody();
            if (!is_array($data)) {
                return $this->errorResponse($response, 'بيانات الحملة غير صالحة.');
            }

            $name = trim((string) ($data['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 160) {
                return $this->errorResponse($response, 'اسم الحملة مطلوب وبحد أقصى 160 حرفاً.');
            }
            $campaignType = (string) ($data['campaign_type'] ?? 'custom');
            if (!in_array($campaignType, ['opening', 'clearance', 'launch', 'custom'], true)) {
                return $this->errorResponse($response, 'نوع الحملة غير صالح.');
            }
            $startDate = $this->parseDate($data['start_date'] ?? null);
            $endDate = $this->parseDate($data['end_date'] ?? null);
            if ($startDate === null || $endDate === null || $endDate < $startDate) {
                return $this->errorResponse($response, 'تاريخ بداية الحملة ونهايتها غير صالحين.');
            }

            $branchId = null;
            if (isset($data['branch_id']) && $data['branch_id'] !== '') {
                $branchId = filter_var($data['branch_id'], FILTER_VALIDATE_INT);
                if (!$branchId || $branchId <= 0 || !$this->branchExists((int) $tenantId, (int) $branchId)) {
                    return $this->errorResponse($response, 'الفرع المحدد غير متاح لهذا المستأجر.', 403);
                }
            } elseif (!$this->canUseAllBranches($request)) {
                return $this->errorResponse($response, 'إنشاء حملة لجميع الفروع متاح للمدير أو المشرف فقط.', 403);
            }
            if ($this->isBranchManager($request)
                && (int) $branchId !== $this->branchManagerBranchId($request, (int) $tenantId)) {
                return $this->errorResponse($response, 'لا يمكنك إنشاء حملة إلا لفرعك المحدد.', 403);
            }

            $priority = filter_var($data['priority'] ?? 0, FILTER_VALIDATE_INT);
            if ($priority === false || $priority < -100 || $priority > 100) {
                return $this->errorResponse($response, 'أولوية الحملة يجب أن تكون بين -100 و100.');
            }
            $inputItems = $data['items'] ?? null;
            if (!is_array($inputItems) || $inputItems === [] || count($inputItems) > 300) {
                return $this->errorResponse($response, 'أضف منتجاً واحداً على الأقل وبحد أقصى 300 منتج.');
            }

            $productQuery = $this->db->prepare(
                'SELECT p.name, p.sale_price, pu.conversion_factor
                 FROM products p
                 JOIN product_units pu ON pu.product_id = p.id AND pu.tenant_id = p.tenant_id
                 WHERE p.id = ? AND pu.unit_id = ? AND p.tenant_id = ? AND p.active = 1
                 LIMIT 1'
            );
            $items = [];
            $seen = [];
            foreach ($inputItems as $item) {
                if (!is_array($item)) {
                    return $this->errorResponse($response, 'أحد منتجات الحملة غير صالح.');
                }
                $productId = filter_var($item['product_id'] ?? null, FILTER_VALIDATE_INT);
                $unitId = filter_var($item['unit_id'] ?? null, FILTER_VALIDATE_INT);
                $price = $item['campaign_price'] ?? null;
                if (!$productId || !$unitId || !is_numeric($price) || !is_finite((float) $price) || (float) $price <= 0) {
                    return $this->errorResponse($response, 'سعر الحملة أو المنتج غير صالح.');
                }
                $key = (int) $productId . ':' . (int) $unitId;
                if (isset($seen[$key])) {
                    return $this->errorResponse($response, 'لا يمكن تكرار المنتج نفسه أكثر من مرة في الحملة.');
                }
                $seen[$key] = true;

                $productQuery->execute([(int) $productId, (int) $unitId, (int) $tenantId]);
                $product = $productQuery->fetch(PDO::FETCH_ASSOC);
                if (!$product) {
                    return $this->errorResponse($response, 'أحد المنتجات أو وحداته غير متاح لهذا المستأجر.');
                }
                $regularPrice = (float) $product['sale_price'] * (float) $product['conversion_factor'];
                if ((float) $price >= $regularPrice) {
                    return $this->errorResponse(
                        $response,
                        'سعر الحملة للمنتج "' . $product['name'] . '" يجب أن يكون أقل من سعره الحالي.'
                    );
                }
                $items[] = [
                    'product_id' => (int) $productId,
                    'unit_id' => (int) $unitId,
                    'campaign_price' => round((float) $price, 4),
                ];
            }

            $this->db->beginTransaction();
            $insert = $this->db->prepare(
                "INSERT INTO sales_price_campaigns
                    (tenant_id, branch_id, name, campaign_type, start_date, end_date, priority, status, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?)"
            );
            $insert->execute([
                (int) $tenantId,
                $branchId,
                $name,
                $campaignType,
                $startDate,
                $endDate,
                $priority,
                (int) $userId,
            ]);
            $campaignId = (int) $this->db->lastInsertId();
            $insertItem = $this->db->prepare(
                'INSERT INTO sales_price_campaign_items (tenant_id, campaign_id, product_id, unit_id, campaign_price)
                 VALUES (?, ?, ?, ?, ?)'
            );
            foreach ($items as $item) {
                $insertItem->execute([
                    (int) $tenantId,
                    $campaignId,
                    $item['product_id'],
                    $item['unit_id'],
                    $item['campaign_price'],
                ]);
            }
            $this->db->commit();

            return $this->successResponse($response, [
                'id' => $campaignId,
                'name' => $name,
                'branch_id' => $branchId,
                'campaign_type' => $campaignType,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'priority' => $priority,
                'status' => 'active',
                'product_count' => count($items),
            ], 201);
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return $this->failureResponse($response, $error, 'create');
        }
    }

    public function setStatus(Request $request, Response $response, array $args): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId || !$this->canManage($request)) {
                return $this->errorResponse($response, 'لا تملك صلاحية إدارة الحملات السعرية.', 403);
            }
            $data = $request->getParsedBody();
            $status = is_array($data) ? (string) ($data['status'] ?? '') : '';
            if (!in_array($status, ['active', 'paused'], true)) {
                return $this->errorResponse($response, 'حالة الحملة غير صالحة.');
            }

            $sql = 'UPDATE sales_price_campaigns SET status = ? WHERE id = ? AND tenant_id = ?';
            $params = [$status, (int) ($args['id'] ?? 0), (int) $tenantId];
            $branchId = null;
            if ($this->isBranchManager($request)) {
                $branchId = $this->branchManagerBranchId($request, (int) $tenantId);
                $sql .= ' AND branch_id = ?';
                $params[] = $branchId;
            }
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            if ($stmt->rowCount() === 0) {
                $existsSql = 'SELECT 1 FROM sales_price_campaigns WHERE id = ? AND tenant_id = ?';
                $existsParams = [(int) ($args['id'] ?? 0), (int) $tenantId];
                if ($branchId !== null) {
                    $existsSql .= ' AND branch_id = ?';
                    $existsParams[] = $branchId;
                }
                $exists = $this->db->prepare($existsSql);
                $exists->execute($existsParams);
                if (!$exists->fetchColumn()) {
                    return $this->errorResponse($response, 'الحملة غير موجودة.', 404);
                }
            }
            return $this->successResponse($response, ['id' => (int) $args['id'], 'status' => $status]);
        } catch (Throwable $error) {
            return $this->failureResponse($response, $error, 'set_status');
        }
    }

    private function canManage(Request $request): bool
    {
        $user = $request->getAttribute('user');
        return $this->canUseAllBranches($request)
            || $this->isBranchManager($request);
    }

    private function canUseAllBranches(Request $request): bool
    {
        $user = $request->getAttribute('user');
        return SuperAdminHelper::isAdminOrAbove(is_array($user) ? $user : null)
            || (is_array($user)
                && (int) ($user['tenant_id'] ?? 0) === (int) $this->extractTenantId($request)
                && strtolower((string) ($user['role'] ?? '')) === 'manager');
    }

    private function isBranchManager(Request $request): bool
    {
        $user = $request->getAttribute('user');
        return is_array($user)
            && (int) ($user['tenant_id'] ?? 0) === (int) $this->extractTenantId($request)
            && strtolower((string) ($user['role'] ?? '')) === 'branch_manager';
    }

    private function branchManagerBranchId(Request $request, int $tenantId): int
    {
        $userId = $this->extractUserId($request);
        if (!$userId) {
            throw new \DomainException('تعذر تحديد فرع حسابك.', 403);
        }

        $stmt = $this->db->prepare(
            'SELECT branch_id FROM users WHERE id = ? AND tenant_id = ? LIMIT 1'
        );
        $stmt->execute([$userId, $tenantId]);
        $branchId = (int) $stmt->fetchColumn();
        if ($branchId <= 0 || !$this->branchExists($tenantId, $branchId)) {
            throw new \DomainException('لم يتم تعيين فرع متاح لحسابك.', 403);
        }

        return $branchId;
    }

    private function branchExists(int $tenantId, int $branchId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM branches WHERE id = ? AND tenant_id = ? LIMIT 1');
        $stmt->execute([$branchId, $tenantId]);
        return (bool) $stmt->fetchColumn();
    }

    private function parseDate($value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value ? $value : null;
    }

    private function failureResponse(Response $response, Throwable $error, string $operation): Response
    {
        if ($error instanceof \DomainException) {
            $status = in_array((int) $error->getCode(), [400, 403, 404], true)
                ? (int) $error->getCode()
                : 400;
            return $this->errorResponse($response, $error->getMessage(), $status);
        }
        $this->logger->error('Sales price campaign operation failed.', [
            'operation' => $operation,
            'exception' => $error,
        ]);
        return $this->errorResponse($response, 'تعذر إتمام طلب الحملة السعرية.', 500);
    }
}
