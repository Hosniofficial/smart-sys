<?php

declare(strict_types=1);

namespace App\Handlers;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Services\MonologHandler;
use App\Services\AccountingService;
use App\Services\InventoryOpeningBalanceService;
use App\Resources\ProductListResource;
use App\Resources\ProductDetailResource;
use App\Security\Permissions;
use App\Services\PriceCampaignService;

/**
 * Handles product search, reads, CRUD operations, and unit lookup.
 *
 * Product and inventory records are scoped to the request tenant; list/detail
 * responses are shaped by ProductListResource and ProductDetailResource, which
 * also enforce purchase-cost visibility. Creation can assign branches and post
 * opening stock through InventoryOpeningBalanceService.
 */
class ProductsHandler extends BaseHandler
{
    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->logger = MonologHandler::getInstance('products');
    }

    /** Search active products, limiting branch-scoped stock results to active mappings with stock. */
    public function list(Request $request, Response $response): Response
    {
        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
        }

        $query    = '';
        $branchId = null;

        try {
            $queryParams = $request->getQueryParams();
            $query       = trim((string) ($queryParams['q'] ?? ''));
            $branchId    = isset($queryParams['branch_id']) && $queryParams['branch_id'] !== ''
                ? (int) $queryParams['branch_id']
                : null;

            if ($query === '') {
                return $this->successResponse($response, [], 200);
            }

            $productQuery = "
                SELECT p.*
                FROM products p
                WHERE (p.name LIKE ? OR p.barcode LIKE ? OR p.product_code LIKE ?)
                  AND p.active = 1
                  AND p.tenant_id = ?
            ";

            $productParams = ['%' . $query . '%', '%' . $query . '%', '%' . $query . '%', (int) $tenantId];
            if ($branchId) {
                $productQuery .= "
                    AND (
                        COALESCE(p.product_type, 'stock') = 'service'
                        OR (
                            EXISTS (
                                SELECT 1 FROM product_branch_gl_mapping pbm
                                WHERE pbm.tenant_id = p.tenant_id
                                  AND pbm.product_id = p.id
                                  AND pbm.branch_id = ?
                                  AND pbm.activation_status IN ('ACTIVE_IN_BRANCH', 'GL_POSTED', 'RECONCILED')
                            )
                            AND EXISTS (
                                SELECT 1 FROM branch_products bp
                                WHERE bp.tenant_id = p.tenant_id
                                  AND bp.product_id = p.id
                                  AND bp.branch_id = ?
                                  AND bp.quantity > 0
                            )
                        )
                    )
                ";
                $productParams[] = $branchId;
                $productParams[] = $branchId;
            }
            $productQuery .= ' LIMIT 10';

            $stmt = $this->db->prepare($productQuery);
            $stmt->execute($productParams);
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            if (empty($products)) {
                return $this->successResponse($response, [], 200);
            }

            $productIds = array_column($products, 'id');

            $branchProducts = [];
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

            foreach ($branchData as $bp) {
                $pid = (int) $bp['product_id'];
                $branchProducts[$pid] = $bp;
            }

            $allUnits = [];
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

            $productUnits = [];
            foreach ($products as $product) {
                $productId = (int) $product['id'];
                foreach ($allUnits[$productId] ?? [] as $unit) {
                    if (!empty($unit['is_main_unit'])) {
                        $productUnits[$productId] = (int) $unit['unit_id'];
                        break;
                    }
                }
                if (!isset($productUnits[$productId]) && isset($allUnits[$productId][0]['unit_id'])) {
                    $productUnits[$productId] = (int) $allUnits[$productId][0]['unit_id'];
                }
            }
            $campaignPrices = $branchId && $productUnits
                ? PriceCampaignService::findActivePrices($this->db, (int) $tenantId, $branchId, $productUnits)
                : [];

            $includeCostData = $this->can($request, Permissions::PRODUCT_PURCHASE_PRICE_VIEW);
            $branchMappings = [];
            if ($branchId) {
                $mappingStmt = $this->db->prepare(
                    "SELECT product_id, activation_status
                     FROM product_branch_gl_mapping
                     WHERE tenant_id = ? AND branch_id = ?
                       AND product_id IN (" . implode(',', array_fill(0, count($productIds), '?')) . ")"
                );
                $mappingStmt->execute(array_merge([(int) $tenantId, $branchId], $productIds));
                foreach ($mappingStmt->fetchAll(PDO::FETCH_ASSOC) as $mapping) {
                    $branchMappings[(int) $mapping['product_id']] = $mapping['activation_status'];
                }
            }
            $transformedProducts = [];
            foreach ($products as $product) {
                $pid = (int) $product['id'];
                $unitId = $productUnits[$pid] ?? null;
                $campaign = $unitId === null ? null : ($campaignPrices[$pid . ':' . $unitId] ?? null);
                if ($campaign !== null) {
                    $product['campaign_id'] = $campaign['campaign_id'];
                    $product['campaign_name'] = $campaign['campaign_name'];
                    $product['campaign_price'] = $campaign['campaign_price'];
                }
                $transformedProducts[] = ProductListResource::transform(
                    $product,
                    $branchProducts[$pid] ?? [],
                    $allUnits[$pid] ?? [],
                    $branchMappings[$pid] ?? null,
                    $includeCostData,
                    $branchId !== null
                );
            }

            return $this->successResponse($response, $transformedProducts, 200);
        } catch (\Throwable $e) {
            $this->logger->error('Error listing products with search', [
                'error'        => $e->getMessage(),
                'search_query' => $query,
                'branch_id'    => $branchId,
                'tenant_id'    => $tenantId,
            ]);

            return $this->errorResponse($response, 'Error retrieving products', 500);
        }
    }

    /** List active products, optionally including only products assigned to a branch. */
    public function getAll(Request $request, Response $response): Response
    {
        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
        }

        try {
            $queryParams = $request->getQueryParams();
            $branchId    = isset($queryParams['branch_id']) && $queryParams['branch_id'] !== ''
                ? (int) $queryParams['branch_id']
                : null;

            $productQuery = "
                SELECT p.*, c.name AS category_name
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.id
            ";

            $params = [];

            if ($branchId) {
                $productQuery .= "
                    INNER JOIN branch_products bp 
                        ON p.id = bp.product_id 
                        AND bp.branch_id = ?
                        AND bp.tenant_id = p.tenant_id
                    WHERE p.active = 1 AND p.tenant_id = ?
                ";
                $params = [(int) $branchId, (int) $tenantId];
            } else {
                $productQuery .= "
                    WHERE p.active = 1 AND p.tenant_id = ?
                ";
                $params = [(int) $tenantId];
            }

            $productQuery .= " ORDER BY p.name";

            $stmt = $this->db->prepare($productQuery);
            $stmt->execute($params);
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            if (empty($products)) {
                return $this->successResponse($response, [], 200);
            }

            $productIds = array_column($products, 'id');

            $branchProducts = [];
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

            foreach ($branchData as $bp) {
                $pid = (int) $bp['product_id'];
                $branchProducts[$pid] = $bp;
            }

            $allUnits = [];
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

            $glMappings = [];
            if ($branchId) {
                $glQuery = "
                    SELECT product_id, activation_status
                    FROM product_branch_gl_mapping
                    WHERE product_id IN (" . implode(',', array_fill(0, count($productIds), '?')) . ")
                      AND branch_id = ?
                      AND tenant_id = ?
                ";
                $glParams = array_merge($productIds, [(int) $branchId, (int) $tenantId]);
                $glStmt = $this->db->prepare($glQuery);
                $glStmt->execute($glParams);
                $glData = $glStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

                foreach ($glData as $gl) {
                    $pid = (int) $gl['product_id'];
                    $glMappings[$pid] = $gl['activation_status'];
                }
            }

            $includeCostData = $this->can($request, Permissions::PRODUCT_PURCHASE_PRICE_VIEW);
            $transformedProducts = [];
            foreach ($products as $product) {
                $pid = (int) $product['id'];
                $transformedProducts[] = ProductListResource::transform(
                    $product,
                    $branchProducts[$pid] ?? [],
                    $allUnits[$pid] ?? [],
                    $glMappings[$pid] ?? null,
                    $includeCostData,
                    $branchId !== null
                );
            }

            return $this->successResponse($response, $transformedProducts, 200);
        } catch (\Throwable $e) {
            $this->logger->error('Error getting all products', [
                'error'     => $e->getMessage(),
                'tenant_id' => $tenantId,
            ]);

            return $this->errorResponse($response, 'Error retrieving products', 500);
        }
    }

    /** Return one active product with branch stock, units, and optional mapping status. */
    public function get(Request $request, Response $response, array $args): Response
    {
        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
        }

        try {
            $productId = (int) ($args['id'] ?? 0);

            $stmt = $this->db->prepare("
                SELECT 
                    p.*,
                    c.name AS category_name
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.id
                WHERE p.id = ? AND p.active = 1 AND p.tenant_id = ?
            ");
            $stmt->execute([$productId, (int) $tenantId]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$product) {
                return $this->errorResponse($response, 'Product not found', 404);
            }

            // Scope inventory details to the selected branch when provided.
            $queryParams = $request->getQueryParams();
            $branchId = isset($queryParams['branch_id']) && $queryParams['branch_id'] !== ''
                ? (int) $queryParams['branch_id']
                : null;
            $branchFilter = $branchId ? ' AND branch_id = ?' : '';

            $stmt = $this->db->prepare("
                SELECT
                    product_id,
                    SUM(quantity) as quantity,
                    MIN(minimum_quantity) as minimum_quantity,
                    SUM(quantity_cost) as quantity_cost
                FROM branch_products
                WHERE product_id = ? AND tenant_id = ?{$branchFilter}
                GROUP BY product_id
            ");
            $params = [$productId, (int) $tenantId];
            if ($branchId) {
                $params[] = $branchId;
            }
            $stmt->execute($params);
            $branchProduct = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

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
            $stmt->execute([$productId, (int) $tenantId]);
            $units = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $glActivationStatus = null;
            if ($branchId) {
                $glActivationStatus = 'DRAFT';
            }

            if ($branchId) {
                $glStmt = $this->db->prepare("
                    SELECT activation_status
                    FROM product_branch_gl_mapping
                    WHERE product_id = ? AND branch_id = ? AND tenant_id = ?
                    LIMIT 1
                ");
                $glStmt->execute([$productId, $branchId, (int) $tenantId]);
                $glData = $glStmt->fetch(PDO::FETCH_ASSOC);
                if ($glData) {
                    $glActivationStatus = $glData['activation_status'];
                }
            }

            $transformedProduct = ProductDetailResource::transform(
                $product,
                $branchProduct,
                $units,
                $glActivationStatus,
                $this->can($request, Permissions::PRODUCT_PURCHASE_PRICE_VIEW),
                $branchId !== null
            );

            return $this->successResponse($response, $transformedProduct, 200);
        } catch (\Throwable $e) {
            $this->logger->error('Error getting product', [
                'error'      => $e->getMessage(),
                'product_id' => $args['id'] ?? null,
                'tenant_id'  => $tenantId,
            ]);

            return $this->errorResponse($response, 'Error retrieving product', 500);
        }
    }

    /** Create a product, its units and branch mappings, and any requested opening stock. */
    public function create(Request $request, Response $response): Response
    {
        $data     = $request->getParsedBody() ?? [];
        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
        }
        if (isset($data['fixed_discount_percentage']) && (
            !is_numeric($data['fixed_discount_percentage'])
            || (float) $data['fixed_discount_percentage'] < 0
            || (float) $data['fixed_discount_percentage'] > 100
        )) {
            return $this->errorResponse($response, 'يجب أن تكون نسبة الخصم بين 0 و100.', 400);
        }

        $startedTransaction = false;

        try {
            if (!$this->db->inTransaction()) {
                $this->db->beginTransaction();
                $startedTransaction = true;
            }

            $stmt = $this->db->prepare("
                INSERT INTO products (
                    name, sale_price, purchase_price, min_sale_price, fixed_discount_percentage, min_quantity,
                    description, active, category_id, barcode, unit_id,
                    has_expiry_date, has_serial_number, has_batch_number, tenant_id,
                    product_type, default_expiry_date, default_batch_number, default_serial_number
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $data['name'],
                $data['sale_price'],
                $data['purchase_price']  ?? null,
                $data['min_sale_price']  ?? null,
                $data['fixed_discount_percentage'] ?? 0,
                $data['min_quantity']    ?? null,
                $data['description']     ?? null,
                $data['category_id']     ?? null,
                $data['barcode']         ?? null,
                $data['unit_id']         ?? null,
                isset($data['has_expiry_date']) ? ($data['has_expiry_date'] ? 1 : 0) : 0,
                isset($data['has_serial_number']) ? ($data['has_serial_number'] ? 1 : 0) : 0,
                isset($data['has_batch_number']) ? ($data['has_batch_number'] ? 1 : 0) : 0,
                $tenantId,
                $data['product_type'] ?? 'stock',
                $data['default_expiry_date']  ?? null,
                $data['default_batch_number'] ?? null,
                $data['default_serial_number'] ?? null,
            ]);

            $productId = (int) $this->db->lastInsertId();

            $sku = 'PRD-' . str_pad((string) $productId, 6, '0', STR_PAD_LEFT);
            $this->db->prepare("
                UPDATE products 
                SET product_code = ?
                WHERE id = ? AND tenant_id = ?
            ")->execute([$sku, $productId, (int) $tenantId]);

            if (isset($data['units']) && is_array($data['units']) && !empty($data['units'])) {
                $unitStmt = $this->db->prepare("
                    INSERT INTO product_units (
                        product_id, unit_id, conversion_factor, is_main_unit, tenant_id
                    ) VALUES (?, ?, ?, ?, ?)
                ");
                foreach ($data['units'] as $unit) {
                    $unitId = $unit['unit_id'] ?? null;
                    if ($unitId) {
                        $unitStmt->execute([
                            $productId,
                            $unitId,
                            $unit['conversion_factor'] ?? 1.0,
                            ($unit['is_main_unit'] ?? false) ? 1 : 0,
                            $tenantId,
                        ]);
                    }
                }
            } elseif (isset($data['unit_id'])) {
                $this->db->prepare("
                    INSERT INTO product_units (
                        product_id, unit_id, conversion_factor, is_main_unit, tenant_id
                    ) VALUES (?, ?, ?, ?, ?)
                ")->execute([$productId, $data['unit_id'], 1.0, 1, $tenantId]);
            }

            $branchIds = [];
            $branchActivation = [];
            if (isset($data['branch_assignments']) && is_array($data['branch_assignments'])) {
                $branchIds       = $data['branch_assignments']['branch_ids']       ?? [];
                $initialQuantity = (float) ($data['branch_assignments']['initial_quantity'] ?? 0);
                $initialUnitCost = (float) ($data['branch_assignments']['initial_unit_cost'] ?? 0);
                $isServiceProduct = ($data['product_type'] ?? 'stock') === 'service';
                $openingBalanceRequested = $initialQuantity != 0.0 || $initialUnitCost != 0.0;

                if ($isServiceProduct && $openingBalanceRequested) {
                    throw new \InvalidArgumentException('لا يمكن تعيين رصيد مخزني لمنتج من نوع خدمة.');
                }
                if ($openingBalanceRequested && empty($branchIds)) {
                    throw new \InvalidArgumentException('يجب تحديد فرع قبل إدخال الرصيد الافتتاحي.');
                }
                if ($openingBalanceRequested && ($initialQuantity <= 0 || $initialUnitCost <= 0)) {
                    throw new \InvalidArgumentException(
                        'يجب أن تكون الكمية الافتتاحية وتكلفة الوحدة أكبر من صفر.'
                    );
                }

                if (!empty($branchIds)) {
                    $userId             = $this->extractUserId($request);
                    $obService          = new InventoryOpeningBalanceService($this->db);

                    foreach ($branchIds as $branchId) {
                        $branchId = (int) $branchId;

                        $branchStmt = $this->db->prepare("
                            SELECT id, account_id, name
                            FROM branches
                            WHERE id = ? AND tenant_id = ?
                            LIMIT 1
                        ");
                        $branchStmt->execute([$branchId, $tenantId]);
                        $branch = $branchStmt->fetch(PDO::FETCH_ASSOC);

                        if (!$branch) {
                            throw new \InvalidArgumentException(
                                "الفرع #{$branchId} غير موجود أو لا ينتمي لهذه المنشأة."
                            );
                        }

                        $inventoryGLId = !$isServiceProduct
                            ? $this->accounting->resolveInventoryAccountForBranch((int) $tenantId, $branchId)
                            : null;
                        $purchaseGLId = !$isServiceProduct
                            ? $this->getGLAccountByCode((int) $tenantId, '5001')
                            : null;
                        $cogsGLId = !$isServiceProduct
                            ? $this->getGLAccountByCode((int) $tenantId, '5103')
                            : null;

                        if ($initialQuantity > 0 && !$isServiceProduct && !$inventoryGLId) {
                            $this->logger->warning('Opening balance skipped — missing GL accounts for branch', [
                                'product_id' => $productId,
                                'branch_id'  => $branchId,
                            ]);
                            throw new \InvalidArgumentException(
                                "لا يمكن ترحيل الرصيد الافتتاحي للفرع #{$branchId}: حساب المخزون غير مُهيأ. "
                                . 'تم إلغاء إنشاء المنتج بالكامل.'
                            );
                        }

                        if ($isServiceProduct) {
                            $branchActivation[] = [
                                'branch_id' => $branchId,
                                'branch_name' => $branch['name'],
                                'status' => 'not_required',
                            ];
                        } elseif ($inventoryGLId && $purchaseGLId && $cogsGLId) {
                            $this->db->prepare("
                                INSERT INTO product_branch_gl_mapping (
                                    tenant_id, product_id, branch_id,
                                    inventory_gl_account_id, purchase_gl_account_id, cogs_gl_account_id,
                                    activation_status, activation_date,
                                    created_by_user_id, updated_by_user_id
                                ) VALUES (?, ?, ?, ?, ?, ?, 'ACTIVE_IN_BRANCH', NOW(), ?, ?)
                                ON DUPLICATE KEY UPDATE
                                    inventory_gl_account_id = VALUES(inventory_gl_account_id),
                                    purchase_gl_account_id = VALUES(purchase_gl_account_id),
                                    cogs_gl_account_id = VALUES(cogs_gl_account_id),
                                    activation_status = 'ACTIVE_IN_BRANCH',
                                    activation_date = NOW(),
                                    updated_by_user_id = VALUES(updated_by_user_id)
                            ")->execute([
                                (int) $tenantId,
                                $productId,
                                $branchId,
                                $inventoryGLId,
                                $purchaseGLId,
                                $cogsGLId,
                                $userId,
                                $userId,
                            ]);
                            $branchActivation[] = [
                                'branch_id' => $branchId,
                                'branch_name' => $branch['name'],
                                'status' => 'active',
                            ];
                        } else {
                            $missingAccounts = [];
                            if (!$inventoryGLId) {
                                $missingAccounts[] = 'inventory';
                            }
                            if (!$purchaseGLId) {
                                $missingAccounts[] = 'purchases';
                            }
                            if (!$cogsGLId) {
                                $missingAccounts[] = 'cogs';
                            }
                            $branchActivation[] = [
                                'branch_id' => $branchId,
                                'branch_name' => $branch['name'],
                                'status' => 'setup_required',
                                'missing_accounts' => $missingAccounts,
                            ];
                            $this->logger->warning('Product branch activation deferred — required GL accounts are missing', [
                                'product_id' => $productId,
                                'branch_id' => $branchId,
                                'missing_accounts' => $missingAccounts,
                            ]);
                        }

                        // The service updates inventory, opening-balance, cost-layer, and GL records.
                        if (!$isServiceProduct) {
                            $totalCost = $initialQuantity > 0 && $initialUnitCost > 0
                                ? $initialQuantity * $initialUnitCost
                                : 0;

                            if ($totalCost > 0) {
                                try {
                                    $jeId = $obService->post(
                                        tenantId:             (int) $tenantId,
                                        productId:            $productId,
                                        branchId:             $branchId,
                                        unitId:               (int) ($data['unit_id'] ?? 1),
                                        quantity:             $initialQuantity,
                                        unitCost:             $initialUnitCost,
                                        entryDate:            date('Y-m-d'),
                                        userId:               $userId,
                                        productName:          $data['name'],
                                        branchName:           $branch['name'] ?? "فرع #{$branchId}",
                                        inventoryGLAccountId: $inventoryGLId,
                                        costCenterId:         null,
                                        movementType:         'initial_stock',
                                    );
                                    $this->logger->info('Opening balance posted via InventoryOpeningBalanceService', [
                                        'product_id'       => $productId,
                                        'branch_id'        => $branchId,
                                        'journal_entry_id' => $jeId,
                                        'total_cost'       => $totalCost,
                                    ]);
                                } catch (\Throwable $obError) {
                                    $this->logger->error('Opening balance failed — rolling back product creation', [
                                        'product_id' => $productId,
                                        'branch_id'  => $branchId,
                                        'error'      => $obError->getMessage(),
                                    ]);
                                    throw $obError;
                                }
                            } else {
                                $this->db->prepare("
                                    INSERT INTO branch_products
                                        (tenant_id, branch_id, product_id, quantity, last_update, quantity_cost)
                                    VALUES (?, ?, ?, 0, NOW(), 0)
                                    ON DUPLICATE KEY UPDATE last_update = NOW()
                                ")->execute([(int) $tenantId, $branchId, $productId]);
                            }
                        }
                    }
                }
            }
            if (isset($data['branch_assignments']) && !empty($branchIds)) {
                $this->db->prepare("
                    UPDATE products
                    SET product_status = 'ACTIVE',
                        active         = 1,
                        updated_at     = NOW()
                    WHERE id = ? AND tenant_id = ?
                ")->execute([$productId, (int) $tenantId]);
            }

            if ($startedTransaction) {
                $this->db->commit();
            }

            $responseData = [
                'id'          => $productId,
                'name'        => $data['name'],
                'sale_price'  => $data['sale_price'],
                'description' => $data['description'] ?? null,
                'category_id' => $data['category_id'] ?? null,
                'barcode'     => $data['barcode']      ?? null,
                'branch_activation' => $branchActivation,
            ];

            return $this->successResponse($response, $responseData, 200);

        } catch (\InvalidArgumentException $e) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return $this->errorResponse($response, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }

            $this->logger->error('Error creating product', [
                'error'        => $e->getMessage(),
                'product_name' => $data['name'] ?? null,
                'tenant_id'    => $tenantId,
            ]);

            return $this->errorResponse($response, 'Error creating product', 500);
        }
    }

    /** Update product fields and, when requested, replace its unit assignments. */
    public function update(Request $request, Response $response, array $args): Response
    {
        $data     = $request->getParsedBody() ?? [];
        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
        }
        if (isset($data['fixed_discount_percentage']) && (
            !is_numeric($data['fixed_discount_percentage'])
            || (float) $data['fixed_discount_percentage'] < 0
            || (float) $data['fixed_discount_percentage'] > 100
        )) {
            return $this->errorResponse($response, 'يجب أن تكون نسبة الخصم بين 0 و100.', 400);
        }

        $startedTransaction = false;

        try {
            $productId = (int) ($args['id'] ?? 0);

            $stmt = $this->db->prepare("
                SELECT id FROM products
                WHERE id = ? AND active = 1 AND tenant_id = ?
            ");
            $stmt->execute([$productId, $tenantId]);
            if (!$stmt->fetch()) {
                return $this->errorResponse($response, 'Product not found', 404);
            }

            if (isset($data['product_code']) && $data['product_code'] !== '') {
                $skuStmt = $this->db->prepare("
                    SELECT COUNT(*) FROM products
                    WHERE product_code = ?
                      AND id != ?
                      AND tenant_id = ?
                ");
                $skuStmt->execute([$data['product_code'], $productId, (int) $tenantId]);
                if ((int) $skuStmt->fetchColumn() > 0) {
                    return $this->errorResponse($response, 'كود المنتج (SKU) مكرر بالفعل في النظام.', 409);
                }
            }

            $updates = [];
            $params  = [];

            $fields = [
                'name', 'sale_price', 'purchase_price', 'min_sale_price',
                'fixed_discount_percentage', 'min_quantity', 'description', 'category_id', 'barcode', 'unit_id',
                'product_code',
                'default_expiry_date', 'default_batch_number', 'default_serial_number',
            ];
            foreach ($fields as $field) {
                if (isset($data[$field])) {
                    $updates[] = "$field = ?";
                    $params[]  = $data[$field];
                }
            }
            foreach (['has_expiry_date', 'has_serial_number', 'has_batch_number'] as $bool) {
                if (isset($data[$bool])) {
                    $updates[] = "$bool = ?";
                    $params[]  = $data[$bool] ? 1 : 0;
                }
            }

            $hasUnitsUpdate = isset($data['units']) || isset($data['unit_id']);

            if (empty($updates) && !$hasUnitsUpdate) {
                return $this->errorResponse($response, 'No fields to update', 400);
            }

            if (!$this->db->inTransaction()) {
                $this->db->beginTransaction();
                $startedTransaction = true;
            }

            if (!empty($updates)) {
                $params[] = $productId;
                $params[] = $tenantId;
                $this->db->prepare("
                    UPDATE products
                    SET " . implode(', ', $updates) . "
                    WHERE id = ? AND tenant_id = ?
                ")->execute($params);
            }

            if ($hasUnitsUpdate) {
                $this->db->prepare("
                    DELETE FROM product_units
                    WHERE product_id = ? AND tenant_id = ?
                ")->execute([$productId, $tenantId]);

                if (isset($data['units']) && is_array($data['units']) && !empty($data['units'])) {
                    $unitStmt = $this->db->prepare("
                        INSERT INTO product_units (
                            product_id, unit_id, conversion_factor, is_main_unit, tenant_id
                        ) VALUES (?, ?, ?, ?, ?)
                    ");
                    foreach ($data['units'] as $unit) {
                        $unitId = $unit['unit_id'] ?? null;
                        if ($unitId) {
                            $unitStmt->execute([
                                $productId,
                                $unitId,
                                $unit['conversion_factor'] ?? 1.0,
                                ($unit['is_main_unit'] ?? false) ? 1 : 0,
                                $tenantId,
                            ]);
                        }
                    }
                } elseif (isset($data['unit_id'])) {
                    $this->db->prepare("
                        INSERT INTO product_units (
                            product_id, unit_id, conversion_factor, is_main_unit, tenant_id
                        ) VALUES (?, ?, ?, ?, ?)
                    ")->execute([$productId, $data['unit_id'], 1.0, 1, $tenantId]);
                }
            }

            if ($startedTransaction) {
                $this->db->commit();
            }

            return $this->jsonResponse($response, [
                'status'  => 'success',
                'message' => 'تم تحديث المنتج بنجاح',
            ], 200);

        } catch (\Throwable $e) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }

            $this->logger->error('Error updating product', [
                'error'      => $e->getMessage(),
                'product_id' => $args['id'] ?? null,
                'tenant_id'  => $tenantId,
            ]);

            return $this->errorResponse($response, 'Error updating product', 500);
        }
    }

    /** Deactivate a product without deleting its stored records. */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
        }

        try {
            $productId = (int) ($args['id'] ?? 0);

            $stmt = $this->db->prepare("
                SELECT id, active FROM products
                WHERE id = ? AND tenant_id = ?
            ");
            $stmt->execute([$productId, $tenantId]);
            if (!$stmt->fetch()) {
                return $this->errorResponse($response, 'المنتج غير موجود', 404);
            }

            $this->db->prepare("
                UPDATE products SET active = 0
                WHERE id = ? AND tenant_id = ?
            ")->execute([$productId, $tenantId]);

            return $this->jsonResponse($response, [
                'status'  => 'success',
                'message' => 'تم إلغاء تفعيل المنتج بنجاح',
            ], 200);

        } catch (\Throwable $e) {
            $this->logger->error('Error deleting product', [
                'error'      => $e->getMessage(),
                'product_id' => $args['id'] ?? null,
                'tenant_id'  => $tenantId,
            ]);

            return $this->errorResponse($response, 'Error deleting product', 500);
        }
    }

    /** Search tenant-owned and shared units for product-entry forms. */
    public function listUnits(Request $request, Response $response): Response
    {
        $tenantId = null;
        $search   = '';

        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $qp     = $request->getQueryParams();
            $search = trim((string) ($qp['search'] ?? ''));

            $sql    = "SELECT id, name FROM units WHERE (tenant_id = ? OR tenant_id IS NULL)";
            $params = [$tenantId];

            if ($search !== '') {
                $sql    .= " AND name LIKE ?";
                $params[] = "%{$search}%";
            }

            $sql .= " ORDER BY (tenant_id IS NULL) ASC, name ASC LIMIT 200";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            return $this->successResponse($response, $rows, 200);
        } catch (\Throwable $e) {
            $this->logger->error('Error listing units', [
                'error'     => $e->getMessage(),
                'search'    => $search,
                'tenant_id' => $tenantId,
            ]);

            return $this->errorResponse($response, 'Error retrieving units', 500);
        }
    }

    /** Resolve a tenant GL account by code through AccountingService. */
    private function getGLAccountByCode(int $tenantId, string $code): ?int
    {
        return $this->accounting->getAccountByCode($tenantId, $code);
    }
}
