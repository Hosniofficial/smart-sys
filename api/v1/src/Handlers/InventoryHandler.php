<?php

declare(strict_types=1);

namespace App\Handlers;

use PDO;
use App\Services\MonologHandler;
use App\Handlers\AuditHandler;
use App\Utils\PaginationHelper;
use App\Resources\ProductListResource;
use App\Resources\ProductDetailResource;
use App\Security\Permissions;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class InventoryHandler extends BaseHandler
{
    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->logger = MonologHandler::getInstance('inventory');
    }

    public function list(Request $request, Response $response): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $queryParams = $request->getQueryParams();
            [$page, $limit, $offset] = PaginationHelper::fromArray($queryParams, 10, 100);
            $search   = trim((string) ($queryParams['search'] ?? ''));
            $category = $queryParams['category'] ?? null;
            $branchId = isset($queryParams['branch_id']) && $queryParams['branch_id'] !== ''
                ? (int) $queryParams['branch_id']
                : null;

            // Build products query with proper JOINs
            $query = "
                SELECT
                    p.*,
                    c.name AS category_name
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.id
                WHERE p.tenant_id = ?
            ";

            $params = [(int) $tenantId];

            if ($search !== '') {
                $query .= " AND (p.name LIKE ? OR p.barcode LIKE ? OR p.product_code LIKE ?)";
                $params[] = '%' . $search . '%';
                $params[] = '%' . $search . '%';
                $params[] = '%' . $search . '%';
            }

            if (!empty($category)) {
                $query .= " AND p.category_id = ?";
                $params[] = (int) $category;
            }

            $query .= " ORDER BY p.id DESC LIMIT ? OFFSET ?";

            $stmt = $this->db->prepare($query);

            $bindIndex = 1;
            foreach ($params as $value) {
                $stmt->bindValue($bindIndex++, $value);
            }
            $stmt->bindValue($bindIndex++, $limit, PDO::PARAM_INT);
            $stmt->bindValue($bindIndex, $offset, PDO::PARAM_INT);

            $stmt->execute();
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // Get branch inventory data for each product
            $branchProducts = [];
            if (!empty($products)) {
                $productIds = array_column($products, 'id');
                $branchQuery = "
                    SELECT
                        product_id,
                        SUM(quantity) as quantity,
                        MIN(minimum_quantity) as minimum_quantity,
                        SUM(quantity_cost) as quantity_cost
                    FROM branch_products
                    WHERE product_id IN (" . implode(',', array_fill(0, count($productIds), '?')) . ")
                      AND tenant_id = ?
                ";

                if ($branchId) {
                    $branchQuery .= " AND branch_id = ? GROUP BY product_id";
                    $branchParams = array_merge($productIds, [(int) $tenantId, (int) $branchId]);
                } else {
                    $branchQuery .= " GROUP BY product_id HAVING SUM(quantity) > 0";
                    $branchParams = array_merge($productIds, [(int) $tenantId]);
                }

                $branchStmt = $this->db->prepare($branchQuery);
                $branchStmt->execute($branchParams);
                $branchData = $branchStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

                foreach ($branchData as $bp) {
                    $pid = (int) $bp['product_id'];
                    $branchProducts[$pid] = $bp;
                }
            }

            // Get units for each product
            $allUnits = [];
            if (!empty($products)) {
                $productIds = array_column($products, 'id');
                $unitsQuery = "
                    SELECT
                        pu.product_id,
                        pu.unit_id,
                        pu.conversion_factor,
                        pu.is_main_unit,
                        u.name as unit_name,
                        u.code as unit_code
                    FROM product_units pu
                    JOIN units u ON pu.unit_id = u.id
                    WHERE pu.product_id IN (" . implode(',', array_fill(0, count($productIds), '?')) . ")
                      AND pu.tenant_id = ?
                    ORDER BY pu.is_main_unit DESC, u.name ASC
                ";

                $unitsStmt = $this->db->prepare($unitsQuery);
                $unitsStmt->execute(array_merge($productIds, [(int) $tenantId]));
                $unitsData = $unitsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

                foreach ($unitsData as $unit) {
                    $pid = (int) $unit['product_id'];
                    if (!isset($allUnits[$pid])) {
                        $allUnits[$pid] = [];
                    }
                    $allUnits[$pid][] = $unit;
                }
            }

            // Transform products using ProductListResource
            $includeCostData = $this->can($request, Permissions::PRODUCT_PURCHASE_PRICE_VIEW);
            $transformedProducts = [];
            foreach ($products as $product) {
                $pid = (int) $product['id'];
                $transformedProducts[] = ProductListResource::transform(
                    $product,
                    $branchProducts[$pid] ?? [],
                    $allUnits[$pid] ?? [],
                    null,
                    $includeCostData
                );
            }

            // Count total
            $countQuery = "
                SELECT COUNT(*)
                FROM products p
                WHERE p.tenant_id = ?
            ";
            $countParams = [(int) $tenantId];

            if ($search !== '') {
                $countQuery .= " AND (p.name LIKE ? OR p.barcode LIKE ? OR p.product_code LIKE ?)";
                $countParams[] = '%' . $search . '%';
                $countParams[] = '%' . $search . '%';
                $countParams[] = '%' . $search . '%';
            }

            if (!empty($category)) {
                $countQuery .= " AND p.category_id = ?";
                $countParams[] = (int) $category;
            }

            $countStmt = $this->db->prepare($countQuery);
            $countStmt->execute($countParams);
            $total = (int) $countStmt->fetchColumn();

            return $this->successResponse($response, [
                'items' => $transformedProducts,
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'total_pages' => $limit > 0 ? (int) ceil($total / $limit) : 0
            ], 200);
        } catch (\Throwable $e) {
            $this->logger->error('Error listing inventory', [
                'error' => $e->getMessage(),
                'tenant_id' => $tenantId ?? null
            ]);

            return $this->errorResponse($response, 'Error retrieving inventory', 500);
        }
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $id = (int) ($args['id'] ?? 0);
            if ($id <= 0) {
                return $this->errorResponse($response, 'Product ID is required', 400);
            }

            // Get product details
            $stmt = $this->db->prepare("
                SELECT
                    p.*,
                    c.name AS category_name
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.id
                WHERE p.id = ? AND p.tenant_id = ?
            ");
            $stmt->execute([$id, (int) $tenantId]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$product) {
                return $this->errorResponse($response, 'المنتج غير موجود', 404);
            }

            // Get branch inventory data (total across all branches)
            $stmt = $this->db->prepare("
                SELECT
                    product_id,
                    SUM(quantity) as quantity,
                    MIN(minimum_quantity) as minimum_quantity,
                    SUM(quantity_cost) as quantity_cost
                FROM branch_products
                WHERE product_id = ? AND tenant_id = ?
                GROUP BY product_id
            ");
            $stmt->execute([$id, (int) $tenantId]);
            $branchProduct = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

            // Get all units for the product
            $stmt = $this->db->prepare("
                SELECT
                    pu.unit_id,
                    pu.conversion_factor,
                    pu.is_main_unit,
                    u.name as unit_name,
                    u.code as unit_code
                FROM product_units pu
                JOIN units u ON pu.unit_id = u.id
                WHERE pu.product_id = ? AND pu.tenant_id = ?
                ORDER BY pu.is_main_unit DESC, u.name ASC
            ");
            $stmt->execute([$id, (int) $tenantId]);
            $units = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // Transform using ProductDetailResource
            $transformedProduct = ProductDetailResource::transform(
                $product,
                $branchProduct,
                $units,
                null,
                $this->can($request, Permissions::PRODUCT_PURCHASE_PRICE_VIEW)
            );

            return $this->successResponse($response, $transformedProduct, 200);
        } catch (\Throwable $e) {
            $this->logger->error('Error getting product', [
                'error' => $e->getMessage(),
                'tenant_id' => $tenantId ?? null,
                'product_id' => $id ?? null
            ]);

            return $this->errorResponse($response, 'Error retrieving product', 500);
        }
    }

    public function create(Request $request, Response $response): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $data = $request->getParsedBody();
            if (!is_array($data)) {
                $data = [];
            }
            $requiredFields = [
                'name',
                'category_id'
            ];

            foreach ($requiredFields as $field) {
                if (!array_key_exists($field, $data) || $data[$field] === '' || $data[$field] === null) {
                    return $this->errorResponse($response, "الحقل '{$field}' مطلوب", 400);
                }
            }

            $this->db->beginTransaction();

            $finalUnitId = $data['unit_id'] ?? null;

            if ($finalUnitId) {
                $stmtCheck = $this->db->prepare("
                    SELECT COUNT(*)
                    FROM units
                    WHERE id = ?
                      AND (tenant_id = ? OR tenant_id IS NULL)
                ");
                $stmtCheck->execute([$finalUnitId, (int) $tenantId]);

                if ((int) $stmtCheck->fetchColumn() === 0) {
                    $finalUnitId = null;
                }
            }

            if (!$finalUnitId) {
                $stmtDefault = $this->db->prepare("
                    SELECT id
                    FROM units
                    WHERE tenant_id = ? OR tenant_id IS NULL
                    ORDER BY is_default DESC, id ASC
                    LIMIT 1
                ");
                $stmtDefault->execute([(int) $tenantId]);
                $defaultUnitId = $stmtDefault->fetchColumn();

                if ($defaultUnitId) {
                    $finalUnitId = (int) $defaultUnitId;
                } else {
                    throw new \RuntimeException('الوحدة الأساسية للمنتج مطلوبة ولم يتم العثور على وحدة افتراضية.');
                }
            }

            $stmt = $this->db->prepare("
                INSERT INTO products (
                    tenant_id,
                    name,
                    min_quantity,
                    sale_price,
                    has_expiry_date,
                    has_batch_number,
                    category_id,
                    barcode,
                    created_at,
                    active,
                    purchase_price,
                    min_sale_price,
                    fixed_discount_percentage,
                    has_serial_number,
                    unit_name,
                    product_code,
                    supplier_id,
                    description,
                    maximum_quantity,
                    unit_id,
                    created_by,
                    updated_by
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                )
            ");

            $stmt->execute([
                (int) $tenantId,
                $data['name'],
                $data['min_quantity'] ?? 0,
                $data['sale_price'] ?? 0,
                $data['has_expiry_date'] ?? 0,
                $data['has_batch_number'] ?? 0,
                $data['category_id'],
                $data['barcode'] ?? null,
                $data['active'] ?? 1,
                $data['purchase_price'] ?? $data['price'] ?? 0,
                $data['min_sale_price'] ?? 0,
                $data['fixed_discount_percentage'] ?? 0,
                $data['has_serial_number'] ?? 0,
                $data['unit_name'] ?? null,
                $data['product_code'] ?? null,
                $data['supplier_id'] ?? null,
                $data['description'] ?? null,
                $data['maximum_quantity'] ?? null,
                $finalUnitId,
                $data['created_by'] ?? $this->extractUserId($request),
                $data['updated_by'] ?? $this->extractUserId($request)
            ]);

            $productId = (int) $this->db->lastInsertId();

            // ── Auto-generate SKU based on product ID ───────────────────────
            $sku = 'PRD-' . str_pad((string) $productId, 6, '0', STR_PAD_LEFT);
            $this->db->prepare("
                UPDATE products 
                SET product_code = ?
                WHERE id = ? AND tenant_id = ?
            ")->execute([$sku, $productId, (int) $tenantId]);

            $stmt = $this->db->prepare("
                INSERT INTO product_units (
                    product_id,
                    unit_id,
                    conversion_factor,
                    is_main_unit,
                    tenant_id
                ) VALUES (?, ?, 1, 1, ?)
            ");
            $stmt->execute([$productId, $finalUnitId, (int) $tenantId]);

            $this->db->commit();

            try {
                $audit = $this->audit;
                $userId = $this->extractUserId($request) ?? ($data['created_by'] ?? null);

                $audit->logAction(
                    'product_created',
                    'products',
                    $productId,
                    [
                        'tenant_id' => (int) $tenantId,
                        'user_id' => $userId,
                        'branch_id' => null,
                        'session_id' => null,
                        'product_id' => $productId,
                        'name' => (string) $data['name'],
                        'category_id' => (int) $data['category_id'],
                        'barcode' => $data['barcode'] ?? null,
                        'unit_id' => (int) $finalUnitId,
                        'price' => (float) ($data['price'] ?? 0),
                        'sale_price' => (float) ($data['sale_price'] ?? 0),
                        'purchase_price' => (float) ($data['purchase_price'] ?? 0)
                    ]
                );
            } catch (\Throwable $e) {
                $this->logger->error('Error logging audit', [
                    'error' => $e->getMessage(),
                    'tenant_id' => $tenantId
                ]);
            }

            return $this->jsonResponse($response, [
                'status' => 'success',
                'message' => 'تم إنشاء المنتج بنجاح',
                'data' => ['id' => $productId]
            ], 201);
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            $this->logger->error('Error creating product', [
                'error' => $e->getMessage(),
                'tenant_id' => $tenantId ?? null
            ]);

            return $this->errorResponse($response, 'فشل في إنشاء المنتج', 400);
        }
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
        }

        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            return $this->errorResponse($response, 'Product ID is required', 400);
        }

        $data = $this->extractAndValidateRequestData($request, [
            'name',
            'price',
            'min_quantity',
            'sale_price',
            'has_expiry_date',
            'has_batch_number',
            'category_id',
            'barcode',
            'active',
            'purchase_price',
            'min_sale_price',
            'fixed_discount_percentage',
            'has_serial_number',
            'unit_name',
            'product_code',
            'supplier_id',
            'description',
            'cost',
            'maximum_quantity',
            'unit_id',
            'created_by',
            'updated_by'
        ]);

        try {
            // ── Validate SKU uniqueness if provided ────────────────────────
            if (isset($data['product_code']) && $data['product_code'] !== '') {
                $skuStmt = $this->db->prepare("
                    SELECT COUNT(*) FROM products
                    WHERE product_code = ?
                      AND id != ?
                      AND tenant_id = ?
                ");
                $skuStmt->execute([$data['product_code'], $id, (int) $tenantId]);
                if ((int) $skuStmt->fetchColumn() > 0) {
                    return $this->errorResponse($response, 'كود المنتج (SKU) مكرر بالفعل في النظام.', 409);
                }
            }

            $this->db->beginTransaction();

            $stmt = $this->db->prepare("
                SELECT unit_id, purchase_price
                FROM products
                WHERE id = ? AND tenant_id = ?
            ");
            $stmt->execute([$id, (int) $tenantId]);
            $existingProduct = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $oldUnitId = $existingProduct['unit_id'] ?? null;
            $data['purchase_price'] = $data['purchase_price']
                ?? $data['price']
                ?? $existingProduct['purchase_price']
                ?? 0;

            $stmt = $this->db->prepare("
                UPDATE products SET
                    name = ?,
                    min_quantity = ?,
                    sale_price = ?,
                    has_expiry_date = ?,
                    has_batch_number = ?,
                    category_id = ?,
                    barcode = ?,
                    active = ?,
                    purchase_price = ?,
                    min_sale_price = ?,
                    fixed_discount_percentage = ?,
                    has_serial_number = ?,
                    unit_name = ?,
                    product_code = ?,
                    supplier_id = ?,
                    description = ?,
                    maximum_quantity = ?,
                    unit_id = ?,
                    created_by = ?,
                    updated_by = ?,
                    updated_at = NOW()
                WHERE id = ? AND tenant_id = ?
            ");

            $stmt->execute([
                $data['name'],
                $data['min_quantity'] ?? 0,
                $data['sale_price'] ?? 0,
                $data['has_expiry_date'] ?? 0,
                $data['has_batch_number'] ?? 0,
                $data['category_id'],
                $data['barcode'] ?? null,
                $data['active'] ?? 1,
                $data['purchase_price'] ?? $data['price'] ?? 0,
                $data['min_sale_price'] ?? 0,
                $data['fixed_discount_percentage'] ?? 0,
                $data['has_serial_number'] ?? 0,
                $data['unit_name'] ?? null,
                $data['product_code'] ?? null,
                $data['supplier_id'] ?? null,
                $data['description'] ?? null,
                $data['maximum_quantity'] ?? null,
                $data['unit_id'],
                $data['created_by'] ?? null,
                $data['updated_by'] ?? $this->extractUserId($request),
                $id,
                (int) $tenantId
            ]);

            if ((string) $oldUnitId !== (string) $data['unit_id']) {
                $stmt = $this->db->prepare("
                    DELETE FROM product_units
                    WHERE product_id = ? AND is_main_unit = 1 AND tenant_id = ?
                ");
                $stmt->execute([$id, (int) $tenantId]);

                $stmt = $this->db->prepare("
                    INSERT INTO product_units (
                        product_id,
                        unit_id,
                        conversion_factor,
                        is_main_unit,
                        tenant_id
                    ) VALUES (?, ?, 1, 1, ?)
                ");
                $stmt->execute([$id, $data['unit_id'], (int) $tenantId]);
            }

            $this->db->commit();

            try {
                $audit = $this->audit;
                $userId = $this->extractUserId($request) ?? ($data['updated_by'] ?? null);

                $audit->logAction(
                    'product_updated',
                    'products',
                    (int) $id,
                    [
                        'tenant_id' => (int) $tenantId,
                        'user_id' => $userId,
                        'branch_id' => null,
                        'session_id' => null,
                        'product_id' => (int) $id,
                        'name' => (string) $data['name'],
                        'category_id' => (int) $data['category_id'],
                        'unit_id' => (int) $data['unit_id']
                    ]
                );
            } catch (\Throwable $e) {
                $this->logger->warning('Audit log failed after product update', [
                    'error' => $e->getMessage(),
                    'tenant_id' => $tenantId,
                    'product_id' => $id
                ]);
            }

            return $this->successResponse($response, [], 200);
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            $this->logger->error('Error updating product', [
                'error' => $e->getMessage(),
                'tenant_id' => $tenantId,
                'product_id' => $id
            ]);

            return $this->errorResponse($response, 'فشل في تحديث المنتج', 500);
        }
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
        }

        $this->validateAuth();

        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            return $this->errorResponse($response, 'Product ID is required', 400);
        }

        try {
            // ── Check if product has posted opening balance ──────────────
            $postedStmt = $this->db->prepare("
                SELECT opening_balance_posted 
                FROM products 
                WHERE id = ? AND tenant_id = ?
            ");
            $postedStmt->execute([$id, (int) $tenantId]);
            $product = $postedStmt->fetch(PDO::FETCH_ASSOC);

            if ($product && (int) ($product['opening_balance_posted'] ?? 0) === 1) {
                return $this->errorResponse(
                    $response,
                    'لا يمكن حذف منتج تم ترصيد رصيده الافتتاحي. يرجى عمل قيد عكسي أولاً من خلال صفحة المحاسبة.',
                    409
                );
            }

            $stmt = $this->db->prepare("
                SELECT
                    (
                        SELECT COUNT(*)
                        FROM sales_items si
                        JOIN sales s
                          ON s.id = si.sale_id
                         AND s.tenant_id = si.tenant_id
                        WHERE si.product_id = ? AND si.tenant_id = ?
                    ) AS sales_count,
                    (
                        SELECT COUNT(*)
                        FROM purchase_items pi
                        JOIN purchases p
                          ON p.id = pi.purchase_id
                         AND p.tenant_id = pi.tenant_id
                        WHERE pi.product_id = ? AND pi.tenant_id = ?
                    ) AS purchases_count
            ");
            $stmt->execute([$id, (int) $tenantId, $id, (int) $tenantId]);
            $counts = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['sales_count' => 0, 'purchases_count' => 0];

            if ((int) $counts['sales_count'] > 0 || (int) $counts['purchases_count'] > 0) {
                return $this->errorResponse($response, 'لا يمكن حذف المنتج لوجود عمليات بيع أو شراء مرتبطة به', 409);
            }

            $this->db->beginTransaction();

            $stmt = $this->db->prepare("
                DELETE FROM product_units
                WHERE product_id = ? AND tenant_id = ?
            ");
            $stmt->execute([$id, (int) $tenantId]);

            $stmt = $this->db->prepare("
                DELETE FROM branch_products
                WHERE product_id = ? AND tenant_id = ?
            ");
            $stmt->execute([$id, (int) $tenantId]);

            $stmt = $this->db->prepare("
                DELETE FROM product_branch_gl_mapping
                WHERE product_id = ? AND tenant_id = ?
            ");
            $stmt->execute([$id, (int) $tenantId]);

            $stmt = $this->db->prepare("
                DELETE FROM products
                WHERE id = ? AND tenant_id = ?
            ");
            $stmt->execute([$id, (int) $tenantId]);

            $this->db->commit();

            try {
                $audit = $this->audit;
                $userId = $this->extractUserId($request) ?? null;

                $audit->logAction(
                    'product_deleted',
                    'products',
                    (int) $id,
                    [
                        'tenant_id' => (int) $tenantId,
                        'user_id' => $userId,
                        'branch_id' => null,
                        'session_id' => null,
                        'product_id' => (int) $id
                    ]
                );
            } catch (\Throwable $e) {
                $this->logger->warning('Audit log failed after product delete', [
                    'error' => $e->getMessage(),
                    'tenant_id' => $tenantId,
                    'product_id' => $id
                ]);
            }

            return $this->successResponse($response, [], 200);
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            $this->logger->error('Error deleting product', [
                'error' => $e->getMessage(),
                'tenant_id' => $tenantId,
                'product_id' => $id
            ]);

            return $this->errorResponse($response, 'فشل في حذف المنتج', 500);
        }
    }

    public function listActive(Request $request, Response $response): Response
    {
        $this->validateAuth();

        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $queryParams = $request->getQueryParams();
            $branchId = isset($queryParams['branch_id']) && $queryParams['branch_id'] !== ''
                ? (int) $queryParams['branch_id']
                : null;

            // Get all active products
            $query = "
                SELECT p.*
                FROM products p
                WHERE p.active = 1 AND p.tenant_id = ?
                ORDER BY p.name ASC
            ";

            $stmt = $this->db->prepare($query);
            $stmt->execute([(int) $tenantId]);
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            if (empty($products)) {
                return $this->successResponse($response, [], 200);
            }

            // Batch fetch branch products and units
            $productIds = array_column($products, 'id');

            $branchQuery = "
                SELECT 
                    product_id,
                    SUM(quantity) as quantity,
                    MIN(minimum_quantity) as minimum_quantity,
                    SUM(quantity_cost) as quantity_cost
                FROM branch_products
                WHERE product_id IN (" . implode(',', array_fill(0, count($productIds), '?')) . ")
                  AND tenant_id = ?
            ";

            if ($branchId) {
                $branchQuery .= " AND branch_id = ?";
                $branchParams = array_merge($productIds, [(int) $tenantId, (int) $branchId]);
            } else {
                $branchParams = array_merge($productIds, [(int) $tenantId]);
            }

            $branchQuery .= " GROUP BY product_id";

            $branchStmt = $this->db->prepare($branchQuery);
            $branchStmt->execute($branchParams);
            $branchData = $branchStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $branchProducts = [];
            foreach ($branchData as $bp) {
                $pid = (int) $bp['product_id'];
                $branchProducts[$pid] = $bp;
            }

            // Batch fetch units
            $unitsQuery = "
                SELECT
                    pu.product_id,
                    pu.unit_id,
                    pu.conversion_factor,
                    pu.is_main_unit,
                    u.name as unit_name,
                    u.code as unit_code
                FROM product_units pu
                JOIN units u ON pu.unit_id = u.id
                WHERE pu.product_id IN (" . implode(',', array_fill(0, count($productIds), '?')) . ")
                  AND pu.tenant_id = ?
                ORDER BY pu.is_main_unit DESC, u.name ASC
            ";

            $unitsStmt = $this->db->prepare($unitsQuery);
            $unitsStmt->execute(array_merge($productIds, [(int) $tenantId]));
            $unitsData = $unitsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $allUnits = [];
            foreach ($unitsData as $unit) {
                $pid = (int) $unit['product_id'];
                if (!isset($allUnits[$pid])) {
                    $allUnits[$pid] = [];
                }
                $allUnits[$pid][] = $unit;
            }

            // Transform using ProductListResource
            $includeCostData = $this->can($request, Permissions::PRODUCT_PURCHASE_PRICE_VIEW);
            $transformedProducts = [];
            foreach ($products as $product) {
                $pid = (int) $product['id'];
                $transformedProducts[] = ProductListResource::transform(
                    $product,
                    $branchProducts[$pid] ?? [],
                    $allUnits[$pid] ?? [],
                    null,
                    $includeCostData
                );
            }

            return $this->successResponse($response, $transformedProducts, 200);
        } catch (\Throwable $e) {
            $this->logger->error('Error listing active products', [
                'error' => $e->getMessage(),
                'tenant_id' => $tenantId ?? null
            ]);

            return $this->errorResponse($response, 'فشل في جلب المنتجات النشطة', 500);
        }
    }

    /**
     * Get available batches and serials for a product in a branch
     */
    public function getBatches(Request $request, Response $response, array $args): Response
    {
        $this->validateAuth();

        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
        }

        $productId = $request->getQueryParams()['product_id'] ?? null;
        $branchId = $request->getQueryParams()['branch_id'] ?? null;

        if (!$productId || !$branchId) {
            return $this->errorResponse($response, 'product_id and branch_id are required', 400);
        }

        try {
            $stmt = $this->db->prepare("
                SELECT
                    batch_number,
                    expiry_date,
                    SUM(
                        CASE
                            WHEN movement_type IN ('in', 'adjustment_in', 'transfer_in', 'opening_balance', 'initial_stock', 'opening_balance_manual', 'opening_balance_bulk')
                                 AND branch_to = ? THEN quantity
                            WHEN movement_type IN ('out', 'adjustment_out', 'transfer_out')
                                 AND branch_from = ? THEN -quantity
                            ELSE 0
                        END
                    ) AS quantity
                FROM inventory_transactions
                WHERE tenant_id = ?
                  AND product_id = ?
                  AND (branch_to = ? OR branch_from = ?)
                  AND batch_number IS NOT NULL
                GROUP BY batch_number, expiry_date
                HAVING quantity > 0
                ORDER BY expiry_date ASC
            ");
            $stmt->execute([$branchId, $branchId, (int) $tenantId, $productId, $branchId, $branchId]);
            $batches = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $stmt = $this->db->prepare("
                SELECT DISTINCT it.serial
                FROM inventory_transactions it
                WHERE it.tenant_id = ?
                  AND it.product_id = ?
                  AND it.branch_to = ?
                  AND it.movement_type IN ('in', 'opening_balance', 'initial_stock', 'opening_balance_manual', 'opening_balance_bulk')
                  AND it.serial IS NOT NULL
                  AND it.serial NOT IN (
                      SELECT serial
                      FROM inventory_transactions
                      WHERE tenant_id = ?
                        AND product_id = ?
                        AND branch_from = ?
                        AND movement_type = 'out'
                        AND serial IS NOT NULL
                  )
                ORDER BY it.serial ASC
            ");
            $stmt->execute([(int) $tenantId, $productId, $branchId, (int) $tenantId, $productId, $branchId]);
            $serials = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

            return $this->jsonResponse($response, [
                'status' => 'success',
                'batches' => $batches,
                'serials' => $serials
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('فشل في جلب بيانات الدفعات', [
                'error' => $e->getMessage(),
                'tenant_id' => $tenantId,
                'product_id' => $productId,
                'branch_id' => $branchId
            ]);

            return $this->errorResponse($response, 'فشل في تحديث المخزون', 400);
        }
    }

    /**
     * Get stock levels with low stock status
     */
    public function getStock(Request $request, Response $response): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $query = "
                SELECT
                    p.id,
                    p.name,
                    p.barcode,
                    COALESCE(SUM(wp.quantity), 0) AS quantity,
                    p.min_quantity,
                    c.name AS category_name,
                    CASE
                        WHEN COALESCE(SUM(wp.quantity), 0) = 0 THEN 'out'
                        WHEN COALESCE(SUM(wp.quantity), 0) <= p.min_quantity THEN 'low'
                        ELSE 'normal'
                    END AS stock_status
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.id
                LEFT JOIN branch_products wp
                    ON p.id = wp.product_id
                   AND wp.tenant_id = p.tenant_id
                WHERE p.active = 1 AND p.tenant_id = ?
                GROUP BY p.id, p.name, p.barcode, p.min_quantity, c.name
                ORDER BY p.name
            ";

            $stmt = $this->db->prepare($query);
            $stmt->execute([(int) $tenantId]);
            $stock = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            return $this->successResponse($response, $stock, 200);
        } catch (\Throwable $e) {
            $this->logger->error('Error getting stock', [
                'error' => $e->getMessage(),
                'tenant_id' => $tenantId ?? null
            ]);

            return $this->errorResponse($response, 'فشل في جلب حالة المخزون', 400);
        }
    }

    /**
     * Get inventory movements history
     */
    public function getMovements(Request $request, Response $response): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $params    = [(int) $tenantId];
            $queryParams = $request->getQueryParams();
            $productId = isset($queryParams['product_id']) ? (int) $queryParams['product_id'] : null;
            $branchId  = isset($queryParams['branch_id']) ? (int) $queryParams['branch_id'] : null;
            $limit     = max(1, min(500, (int) ($queryParams['limit'] ?? 100)));

            $where = 'WHERE t.tenant_id = ?';
            if ($productId) {
                $where .= ' AND t.product_id = ?';
                $params[] = $productId;
            }
            if ($branchId) {
                $where .= ' AND (t.branch_from = ? OR t.branch_to = ?)';
                $params[] = $branchId;
                $params[] = $branchId;
            }

            $query = "
                SELECT
                    t.*,
                    p.name AS product_name,
                    p.barcode,
                    w_from.name AS branch_from_name,
                    w_to.name AS branch_to_name
                FROM inventory_transactions t
                JOIN products p ON t.product_id = p.id
                LEFT JOIN branches w_from ON t.branch_from = w_from.id
                LEFT JOIN branches w_to ON t.branch_to = w_to.id
                {$where}
                ORDER BY t.movement_date DESC
                LIMIT {$limit}
            ";

            $stmt = $this->db->prepare($query);
            $stmt->execute($params);
            $movements = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            return $this->successResponse($response, $movements, 200);
        } catch (\Throwable $e) {
            $this->logger->error('Error getting inventory movements', [
                'error' => $e->getMessage(),
                'tenant_id' => $tenantId ?? null
            ]);

            return $this->errorResponse($response, 'فشل في جلب حركات المخزون', 400);
        }
    }

    /**
     * Get inventory summary KPIs (total products, value, low stock, expiring soon)
     * Optional query param: branch_id (integer) — omit for all branches
     */
    public function getSummary(Request $request, Response $response): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $queryParams = $request->getQueryParams();
            $branchId    = isset($queryParams['branch_id']) && $queryParams['branch_id'] !== ''
                           ? (int) $queryParams['branch_id'] : null;

            // ── 1. Counts from branch_products ───────────────────────────────
            $joinType    = $branchId ? 'INNER' : 'LEFT';
            $branchFilter = $branchId ? 'AND bp.branch_id = ?' : '';

            $countSql = "
                SELECT
                    COUNT(*)                                                                  AS total_products,
                    SUM(CASE WHEN sub.total_qty > 0
                              AND sub.total_qty <= sub.min_quantity THEN 1 ELSE 0 END)       AS low_stock,
                    SUM(CASE WHEN sub.total_qty > sub.min_quantity
                              AND sub.total_qty <= sub.min_quantity * 1.5 THEN 1 ELSE 0 END) AS about_to_finish
                FROM (
                    SELECT
                        p.id,
                        p.min_quantity,
                        COALESCE(SUM(bp.quantity), 0) AS total_qty
                    FROM products p
                    {$joinType} JOIN branch_products bp
                        ON p.id = bp.product_id
                       AND bp.tenant_id = p.tenant_id
                       {$branchFilter}
                    WHERE p.active = 1 AND p.tenant_id = ?
                    GROUP BY p.id, p.min_quantity
                ) AS sub
            ";

            $countParams = [];
            if ($branchId) {
                $countParams[] = $branchId;
            }
            $countParams[] = (int) $tenantId;

            $countStmt = $this->db->prepare($countSql);
            $countStmt->execute($countParams);
            $countRow = $countStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            // ── 2. Total value from branch_products (actual inventory) ───
            // NOTE 1: Changed from GL balance to actual inventory to avoid stale GL values
            // when products are deleted without proper GL reversals.
            //
            // ⚠️ تحديث: كان هذا الموضع يتجنّب quantity_cost عمداً بحجّة أنه "قديم/غير محدَّث
            // إلا عند الرصيد الافتتاحي فقط". تم التحقق من هذا الادعاء فعلياً بتتبّع كل نقاط
            // الكتابة على quantity_cost في النظام: هو مُحدَّث فعلاً في المشتريات والمبيعات
            // والمرتجعات ونقل المخزون — لكن اكتشفنا أن مساري التسوية الجماعية في
            // StockAdjustmentHandler (bulkAdjustments وbulkAdjustmentsCsv) كانا فعلاً
            // يُحدِّثان quantity فقط ويتجاهلان quantity_cost، ما يؤكد صحة القلق الأصلي لكن
            // لسبب قابل للإصلاح تحديداً، لا كعيب جوهري في quantity_cost نفسه. تم إصلاح
          // هذين المسارين (راجع StockAdjustmentHandler.php)، وبالتالي أصبح quantity_cost
            // موثوقاً الآن — ويُستخدم هنا بنفس الأولوية المُطبَّقة في
            // BranchInventoryReportHandler.php لإزالة التعارض بين الملفين على نفس القيمة.
            // ⚠️ يعتمد هذا على نشر إصلاحات StockAdjustmentHandler.php معاً؛ إن نُشر هذا
            // التعديل بمفرده دون تلك الإصلاحات، يبقى الانحراف القديم قائماً جزئياً.
            if ($branchId) {
                $valueSql = "
                    SELECT COALESCE(SUM(COALESCE(bp.quantity_cost, bp.quantity * p.purchase_price)), 0) AS total_value
                    FROM branch_products bp
                    JOIN products p 
                        ON p.id = bp.product_id 
                       AND p.tenant_id = bp.tenant_id
                    WHERE bp.branch_id = ? 
                      AND bp.tenant_id = ?
                      AND p.active = 1
                ";
                $valueParams = [$branchId, (int) $tenantId];
            } else {
                $valueSql = "
                    SELECT COALESCE(SUM(COALESCE(bp.quantity_cost, bp.quantity * p.purchase_price)), 0) AS total_value
                    FROM branch_products bp
                    JOIN products p 
                        ON p.id = bp.product_id 
                       AND p.tenant_id = bp.tenant_id
                    WHERE bp.tenant_id = ?
                      AND p.active = 1
                ";
                $valueParams = [(int) $tenantId];
            }

            $valueStmt = $this->db->prepare($valueSql);
            $valueStmt->execute($valueParams);
            $valueRow = $valueStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $summary = [
                'total_products'  => (int)   ($countRow['total_products']  ?? 0),
                'total_value'     => (float) ($valueRow['total_value']     ?? 0),
                'low_stock'       => (int)   ($countRow['low_stock']       ?? 0),
                'about_to_finish' => (int)   ($countRow['about_to_finish'] ?? 0),
            ];

            return $this->successResponse($response, $summary, 200);
        } catch (\Throwable $e) {
            $this->logger->error('Error getting inventory summary', [
                'error'     => $e->getMessage(),
                'tenant_id' => $tenantId ?? null
            ]);
            return $this->errorResponse($response, 'فشل في جلب ملخص المخزون', 400);
        }
    }

    /**
     * Get low stock alerts
     */
    public function getAlerts(Request $request, Response $response): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $query = "
                SELECT
                    p.id,
                    p.name,
                    p.barcode,
                    COALESCE(SUM(wp.quantity), 0) AS quantity,
                    p.min_quantity,
                    c.name AS category_name
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.id
                LEFT JOIN branch_products wp
                    ON p.id = wp.product_id
                   AND wp.tenant_id = p.tenant_id
                WHERE p.active = 1 AND p.tenant_id = ?
                GROUP BY p.id, p.name, p.barcode, p.min_quantity, c.name
                HAVING COALESCE(SUM(wp.quantity), 0) <= p.min_quantity
                ORDER BY quantity ASC
            ";

            $stmt = $this->db->prepare($query);
            $stmt->execute([(int) $tenantId]);
            $alerts = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            return $this->successResponse($response, $alerts, 200);
        } catch (\Throwable $e) {
            $this->logger->error('Error getting stock alerts', [
                'error' => $e->getMessage(),
                'tenant_id' => $tenantId ?? null
            ]);

            return $this->errorResponse($response, 'فشل في جلب تنبيهات المخزون', 400);
        }
    }
}
