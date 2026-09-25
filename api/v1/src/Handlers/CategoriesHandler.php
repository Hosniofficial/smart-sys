<?php

declare(strict_types=1);

namespace App\Handlers;

use PDO;
use App\Services\MonologHandler;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class CategoriesHandler extends BaseHandler
{
    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->logger = MonologHandler::getInstance('categories');
    }

    // =====================================================================
    // GET ALL — branch-specific
    // =====================================================================
    public function getAll(Request $request, Response $response): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $branchId = $this->getValidatedBranchId($request, (int)$tenantId);

            $stmt = $this->db->prepare('
                SELECT *
                FROM categories
                WHERE branch_id = ? AND tenant_id = ?
                ORDER BY name
            ');
            $stmt->execute([$branchId, $tenantId]);
            $categories = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $this->logger->info('Categories listed successfully', [
                'tenant_id' => $tenantId,
                'branch_id' => $branchId,
                'count'     => count($categories),
            ]);

            return $this->successResponse($response, $categories, 200);

        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($response, 'غير مصرح', 403);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($response, 'غير موجود', 404);
        } catch (\Throwable $e) {
            $this->logger->error('Error listing categories', [
                'error'     => $e->getMessage(),
                'branch_id' => $branchId ?? null,
                'tenant_id' => $tenantId ?? null,
            ]);
            return $this->errorResponse($response, 'خطأ في الاسترجاع', 500);
        }
    }

    // =====================================================================
    // GET SINGLE — with branch-specific product quantities
    // =====================================================================
    public function get(Request $request, Response $response, array $args): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'غير مصرح', 403);
            }

            $categoryId = isset($args['id']) ? (int)$args['id'] : 0;
            if ($categoryId <= 0) {
                return $this->errorResponse($response, 'غير موجود', 404);
            }

            $branchId = $this->getValidatedBranchId($request, (int)$tenantId);

            $stmt = $this->db->prepare('
                SELECT *
                FROM categories
                WHERE id = ? AND branch_id = ? AND tenant_id = ?
                LIMIT 1
            ');
            $stmt->execute([$categoryId, $branchId, $tenantId]);
            $category = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$category) {
                return $this->errorResponse($response, 'غير موجود', 404);
            }

            // Stock filtered to THIS branch only
            $stmt = $this->db->prepare('
                SELECT p.*,
                       COALESCE(SUM(bp.quantity), 0) AS quantity
                FROM products p
                LEFT JOIN branch_products bp
                    ON p.id = bp.product_id
                   AND bp.branch_id = ?
                   AND bp.tenant_id = p.tenant_id
                WHERE p.category_id = ?
                  AND p.active = 1
                  AND p.tenant_id = ?
                GROUP BY p.id
                ORDER BY p.name
            ');
            $stmt->execute([$branchId, $categoryId, $tenantId]);
            $category['products'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $this->logger->info('Category retrieved', [
                'tenant_id'   => $tenantId,
                'branch_id'   => $branchId,
                'category_id' => $categoryId,
            ]);

            return $this->successResponse($response, $category, 200);

        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($response, 'غير مصرح', 403);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($response, 'غير موجود', 404);
        } catch (\Throwable $e) {
            $this->logger->error('Error retrieving category', [
                'error'       => $e->getMessage(),
                'branch_id'   => $branchId ?? null,
                'tenant_id'   => $tenantId ?? null,
                'category_id' => $args['id'] ?? null,
            ]);
            return $this->errorResponse($response, 'خطأ في الاسترجاع', 500);
        }
    }

    // =====================================================================
    // CREATE
    // =====================================================================
    public function create(Request $request, Response $response): Response
    {
        try {
            $data = $request->getParsedBody();
            $data = is_array($data) ? $data : [];

            if (!isset($data['name']) || trim((string)$data['name']) === '') {
                return $this->errorResponse($response, 'اسم التصنيف مطلوب', 422);
            }

            if (!isset($data['branch_id']) || (int)$data['branch_id'] <= 0) {
                return $this->errorResponse($response, 'معرف الفرع مطلوب', 422);
            }

            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'غير مصرح', 403);
            }

            $branchId = $this->assertBranchBelongsToTenant((int)$data['branch_id'], (int)$tenantId);
            $name     = trim((string)$data['name']);
            $parentId = isset($data['parent_id']) && $data['parent_id'] !== '' && $data['parent_id'] !== null
                ? (int)$data['parent_id']
                : null;

            if ($parentId !== null) {
                $this->assertCategoryExistsInBranch($parentId, $branchId, (int)$tenantId);
            }

            // Duplicate name guard
            $stmt = $this->db->prepare('
                SELECT id FROM categories
                WHERE name = ? AND branch_id = ? AND tenant_id = ?
                LIMIT 1
            ');
            $stmt->execute([$name, $branchId, $tenantId]);
            if ($stmt->fetch()) {
                return $this->errorResponse($response, 'اسم التصنيف مستخدم مسبقاً في هذا الفرع', 409);
            }

            $stmt = $this->db->prepare('
                INSERT INTO categories (name, parent_id, branch_id, tenant_id)
                VALUES (?, ?, ?, ?)
            ');
            $stmt->execute([$name, $parentId, $branchId, $tenantId]);
            $categoryId = (int)$this->db->lastInsertId();

            $this->logger->info('Category created successfully', [
                'tenant_id'     => $tenantId,
                'branch_id'     => $branchId,
                'category_id'   => $categoryId,
                'category_name' => $name,
                'parent_id'     => $parentId,
            ]);

            return $this->successResponse($response, [
                'id'        => $categoryId,
                'name'      => $name,
                'parent_id' => $parentId,
                'branch_id' => $branchId,
                'tenant_id' => (int)$tenantId,
            ], 201);

        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($response, 'غير مصرح', 403);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($response, 'غير موجود', 404);
        } catch (\Throwable $e) {
            $this->logger->error('Error creating category', [
                'error'         => $e->getMessage(),
                'branch_id'     => $branchId ?? null,
                'tenant_id'     => $tenantId ?? null,
                'category_name' => $data['name'] ?? null,
            ]);
            return $this->errorResponse($response, 'خطأ في الاسترجاع', 500);
        }
    }

    // =====================================================================
    // UPDATE
    // =====================================================================
    public function update(Request $request, Response $response, array $args): Response
    {
        try {
            $data = $request->getParsedBody();
            $data = is_array($data) ? $data : [];

            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'غير مصرح', 403);
            }

            $categoryId = isset($args['id']) ? (int)$args['id'] : 0;
            if ($categoryId <= 0) {
                return $this->errorResponse($response, 'غير موجود', 404);
            }

            $branchId = $this->getValidatedBranchId($request, (int)$tenantId);
            $this->assertCategoryExistsInBranch($categoryId, $branchId, (int)$tenantId);

            // Validate name if provided
            if (isset($data['name'])) {
                $name = trim((string)$data['name']);
                if ($name === '') {
                    return $this->errorResponse($response, 'غير موجود', 404);
                }

                $stmt = $this->db->prepare('
                    SELECT id FROM categories
                    WHERE name = ? AND id != ? AND branch_id = ? AND tenant_id = ?
                    LIMIT 1
                ');
                $stmt->execute([$name, $categoryId, $branchId, $tenantId]);
                if ($stmt->fetch()) {
                    return $this->errorResponse($response, 'غير موجود', 404);
                }
            }

            $updates = [];
            $params  = [];

            if (isset($data['name'])) {
                $updates[] = 'name = ?';
                $params[]  = trim((string)$data['name']);
            }

            // array_key_exists allows explicit null to detach parent
            if (array_key_exists('parent_id', $data)) {
                $parentId = ($data['parent_id'] === '' || $data['parent_id'] === null)
                    ? null
                    : (int)$data['parent_id'];

                if ($parentId === $categoryId) {
                    return $this->errorResponse($response, 'غير موجود', 404);
                }

                if ($parentId !== null) {
                    $this->assertCategoryExistsInBranch($parentId, $branchId, (int)$tenantId);
                }

                $updates[] = 'parent_id = ?';
                $params[]  = $parentId;
            }

            if (empty($updates)) {
                return $this->errorResponse($response, 'غير موجود', 404);
            }

            $params[] = $categoryId;
            $params[] = $branchId;
            $params[] = $tenantId;

            $stmt = $this->db->prepare('
                UPDATE categories
                SET ' . implode(', ', $updates) . '
                WHERE id = ? AND branch_id = ? AND tenant_id = ?
            ');
            $stmt->execute($params);

            $this->logger->info('Category updated', [
                'tenant_id'   => $tenantId,
                'branch_id'   => $branchId,
                'category_id' => $categoryId,
            ]);

            return $this->successResponse($response, ['message' => 'Category updated successfully'], 200);

        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($response, 'غير مصرح', 403);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($response, 'غير موجود', 404);
        } catch (\Throwable $e) {
            $this->logger->error('Error updating category', [
                'error'       => $e->getMessage(),
                'branch_id'   => $branchId ?? null,
                'tenant_id'   => $tenantId ?? null,
                'category_id' => $args['id'] ?? null,
            ]);
            return $this->errorResponse($response, 'خطأ في الاسترجاع', 500);
        }
    }

    // =====================================================================
    // DELETE (guarded by products + children check)
    // =====================================================================
    public function delete(Request $request, Response $response, array $args): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'غير مصرح', 403);
            }

            $categoryId = isset($args['id']) ? (int)$args['id'] : 0;
            if ($categoryId <= 0) {
                return $this->errorResponse($response, 'غير موجود', 404);
            }

            $branchId = $this->getValidatedBranchId($request, (int)$tenantId);
            $this->assertCategoryExistsInBranch($categoryId, $branchId, (int)$tenantId);

            // Guard: products
            $stmt = $this->db->prepare('
                SELECT COUNT(*) FROM products
                WHERE category_id = ? AND tenant_id = ?
            ');
            $stmt->execute([$categoryId, $tenantId]);
            if ((int)$stmt->fetchColumn() > 0) {
                return $this->errorResponse(
                    $response,
                    'غير موجود',
                    404
                );
            }

            // Guard: child categories
            $stmt = $this->db->prepare('
                SELECT COUNT(*) FROM categories
                WHERE parent_id = ? AND branch_id = ? AND tenant_id = ?
            ');
            $stmt->execute([$categoryId, $branchId, $tenantId]);
            if ((int)$stmt->fetchColumn() > 0) {
                return $this->errorResponse(
                    $response,
                    'غير موجود',
                    404
                );
            }

            $stmt = $this->db->prepare('
                DELETE FROM categories
                WHERE id = ? AND branch_id = ? AND tenant_id = ?
            ');
            $stmt->execute([$categoryId, $branchId, $tenantId]);

            $this->logger->info('Category deleted', [
                'tenant_id'   => $tenantId,
                'branch_id'   => $branchId,
                'category_id' => $categoryId,
            ]);

            return $this->successResponse($response, ['message' => 'Category deleted successfully'], 200);

        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($response, 'غير مصرح', 403);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($response, 'غير موجود', 404);
        } catch (\Throwable $e) {
            $this->logger->error('Error deleting category', [
                'error'       => $e->getMessage(),
                'branch_id'   => $branchId ?? null,
                'tenant_id'   => $tenantId ?? null,
                'category_id' => $args['id'] ?? null,
            ]);
            return $this->errorResponse($response, 'خطأ في الاسترجاع', 500);
        }
    }

    // =====================================================================
    // PRIVATE HELPERS
    // =====================================================================

    /**
     * Extracts and validates branch_id from query params, checks ownership.
     * Throws InvalidArgumentException (missing) or RuntimeException (not found).
     */
    private function getValidatedBranchId(Request $request, int $tenantId): int
    {
        $query    = $request->getQueryParams() ?? [];
        $branchId = isset($query['branch_id']) ? (int)$query['branch_id'] : 0;

        if ($branchId <= 0) {
            throw new \InvalidArgumentException('مطلوب معرف المستودع (branch ID).');
        }

        return $this->assertBranchBelongsToTenant($branchId, $tenantId);
    }

    /**
     * Confirms branch belongs to tenant. Returns branchId on success.
     * Throws RuntimeException if not found.
     */
    private function assertBranchBelongsToTenant(int $branchId, int $tenantId): int
    {
        $stmt = $this->db->prepare('
            SELECT id FROM branches
            WHERE id = ? AND tenant_id = ?
            LIMIT 1
        ');
        $stmt->execute([$branchId, $tenantId]);

        if (!$stmt->fetch()) {
            throw new \RuntimeException('المستودع غير موجود أو لا ينتمي لهذا المستأجر');
        }

        return $branchId;
    }

    /**
     * Confirms category exists within the given branch + tenant.
     * Throws RuntimeException if not found.
     */
    private function assertCategoryExistsInBranch(int $categoryId, int $branchId, int $tenantId): void
    {
        $stmt = $this->db->prepare('
            SELECT id FROM categories
            WHERE id = ? AND branch_id = ? AND tenant_id = ?
            LIMIT 1
        ');
        $stmt->execute([$categoryId, $branchId, $tenantId]);

        if (!$stmt->fetch()) {
            throw new \RuntimeException('الفئة غير موجودة');
        }
    }
}
