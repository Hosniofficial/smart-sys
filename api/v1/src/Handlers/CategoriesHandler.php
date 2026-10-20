<?php

declare(strict_types=1);

namespace App\Handlers;

use PDO;
use App\Services\MonologHandler;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Manages tenant categories within branch scope.
 *
 * Uses PDO for category, branch, and product queries; app/routes/categories.php
 * applies the category permissions to each endpoint.
 */
class CategoriesHandler extends BaseHandler
{
    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->logger = MonologHandler::getInstance('categories');
    }

    /** List categories for the requested branch in the authenticated tenant. */
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

    /** Create a category under a tenant branch, optionally attaching a same-branch parent. */
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

    /**
     * Read branch_id from query parameters and confirm it belongs to the tenant.
     *
     * @throws \InvalidArgumentException If branch_id is missing or invalid.
     * @throws \RuntimeException If the branch does not belong to the tenant.
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

    /** Confirm branch ownership and return its ID. */
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

    /** Confirm category ownership within the specified branch and tenant. */
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
