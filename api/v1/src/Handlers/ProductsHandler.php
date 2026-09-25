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

class ProductsHandler extends BaseHandler
{
    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->logger = MonologHandler::getInstance('products');
    }

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

            // Get matching products
            $productQuery = "
                SELECT p.*
                FROM products p
                WHERE (p.name LIKE ? OR p.barcode LIKE ? OR p.product_code LIKE ?)
                  AND p.active = 1
                  AND p.tenant_id = ?
                LIMIT 10
            ";

            $stmt = $this->db->prepare($productQuery);
            $stmt->execute(['%' . $query . '%', '%' . $query . '%', '%' . $query . '%', (int) $tenantId]);
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // Filter products with available quantity if branchId specified
            if (!empty($products) && $branchId) {
                $productIds = array_column($products, 'id');
                $qtyQuery = "
                    SELECT product_id FROM branch_products
                    WHERE product_id IN (" . implode(',', array_fill(0, count($productIds), '?')) . ")
                      AND branch_id = ? AND tenant_id = ? AND quantity > 0
                ";
                $qtyStmt = $this->db->prepare($qtyQuery);
                $qtyStmt->execute(array_merge($productIds, [(int) $branchId, (int) $tenantId]));
                $availableIds = array_column($qtyStmt->fetchAll(PDO::FETCH_ASSOC), 'product_id');
                $products = array_filter($products, fn ($p) => in_array($p['id'], $availableIds));
            }

            if (empty($products)) {
                return $this->successResponse($response, [], 200);
            }

            $productIds = array_column($products, 'id');

            // Get branch products data
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

            // Get units for each product
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

            // Transform products using ProductListResource
            $transformedProducts = [];
            foreach ($products as $product) {
                $pid = (int) $product['id'];
                $transformedProducts[] = ProductListResource::transform(
                    $product,
                    $branchProducts[$pid] ?? [],
                    $allUnits[$pid] ?? []
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

            // Get products - filter by branch if specified
            $productQuery = "
                SELECT p.*, c.name AS category_name
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.id
            ";

            $params = [];

            if ($branchId) {
                // INNER JOIN to get products assigned to this branch (including those with quantity = 0)
                $productQuery .= "
                    INNER JOIN branch_products bp 
                        ON p.id = bp.product_id 
                        AND bp.branch_id = ?
                        AND bp.tenant_id = p.tenant_id
                    WHERE p.active = 1 AND p.tenant_id = ?
                ";
                $params = [(int) $branchId, (int) $tenantId];
            } else {
                // Get all active products regardless of branch
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

            // Get branch products data
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

            // Get units for each product
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

            // Get GL mapping statuses for this branch if branchId provided
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

            // Transform products using ProductListResource
            $transformedProducts = [];
            foreach ($products as $product) {
                $pid = (int) $product['id'];
                $transformedProducts[] = ProductListResource::transform(
                    $product,
                    $branchProducts[$pid] ?? [],
                    $allUnits[$pid] ?? [],
                    $glMappings[$pid] ?? null
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

    public function get(Request $request, Response $response, array $args): Response
    {
        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
        }

        try {
            $productId = (int) ($args['id'] ?? 0);

            // Get product details with category
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
            $stmt->execute([$productId, (int) $tenantId]);
            $units = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // Get GL mapping status if branchId is provided
            $glActivationStatus = null;

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

            // Transform using ProductDetailResource
            $transformedProduct = ProductDetailResource::transform(
                $product,
                $branchProduct,
                $units,
                $glActivationStatus
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

    public function create(Request $request, Response $response): Response
    {
        $data     = $request->getParsedBody() ?? [];
        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
        }

        $startedTransaction = false;

        try {
            if (!$this->db->inTransaction()) {
                $this->db->beginTransaction();
                $startedTransaction = true;
            }

            // ── Insert product ────────────────────────────────────────────
            $stmt = $this->db->prepare("
                INSERT INTO products (
                    name, sale_price, purchase_price, min_sale_price, min_quantity,
                    description, active, category_id, barcode, unit_id,
                    has_expiry_date, has_serial_number, has_batch_number, tenant_id,
                    product_type, default_expiry_date, default_batch_number, default_serial_number
                ) VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $data['name'],
                $data['sale_price'],
                $data['purchase_price']  ?? null,
                $data['min_sale_price']  ?? null,
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

            // ── Auto-generate SKU based on product ID ───────────────────────
            $sku = 'PRD-' . str_pad((string) $productId, 6, '0', STR_PAD_LEFT);
            $this->db->prepare("
                UPDATE products 
                SET product_code = ?
                WHERE id = ? AND tenant_id = ?
            ")->execute([$sku, $productId, (int) $tenantId]);

            // ── Product units ─────────────────────────────────────────────
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

            // ── Branch assignments ────────────────────────────────────────
            $branchIds = [];
            if (isset($data['branch_assignments']) && is_array($data['branch_assignments'])) {
                $branchIds       = $data['branch_assignments']['branch_ids']       ?? [];
                $initialQuantity = (float) ($data['branch_assignments']['initial_quantity'] ?? 0);
                $initialUnitCost = (float) ($data['branch_assignments']['initial_unit_cost'] ?? 0);
                $isServiceProduct = ($data['product_type'] ?? 'stock') === 'service';

                if (!empty($branchIds)) {
                    $userId             = $this->extractUserId($request);
                    $obService          = new InventoryOpeningBalanceService($this->db);
                    // 🔴 إصلاح: كان تخطي فرع بسبب نقص إعداد حسابات GL يحدث بصمت تامة —
                    // لا سجل خادم ولا أي أثر في الاستجابة. الآن يُجمَّع ويُرجَع للمستدعي.
                    $openingBalanceWarnings = [];

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
                            continue;
                        }

                        $inventoryGLId = $this->accounting->resolveInventoryAccountForBranch((int) $tenantId, $branchId);

                        if (!$inventoryGLId) {
                            $this->logger->warning('Opening balance skipped — missing GL accounts for branch', [
                                'product_id' => $productId,
                                'branch_id'  => $branchId,
                            ]);
                            $openingBalanceWarnings[] = [
                                'branch_id' => $branchId,
                                'reason'    => 'missing_gl_accounts',
                            ];
                            continue;
                        }

                        // Stock products: post opening balance (service handles inv_tx, branch_products, GL, snapshot)
                        if (!$isServiceProduct) {
                            $totalCost = $initialQuantity > 0 && $initialUnitCost > 0
                                ? $initialQuantity * $initialUnitCost
                                : 0;

                            if ($totalCost > 0) {
                                // Service handles everything: inv_tx, branch_products, opening_balances,
                                // journal entry, cost_snapshot, RECONCILED status
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
                                // Zero quantity: just create branch_products placeholder
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
            // End of branch assignments

            // ── Update product status ─────────────────────────────────────
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
            ];
            if (!empty($openingBalanceWarnings)) {
                $responseData['warnings'] = ['opening_balance_skipped' => $openingBalanceWarnings];
            }

            return $this->successResponse($response, $responseData, 200);

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

    public function update(Request $request, Response $response, array $args): Response
    {
        $data     = $request->getParsedBody() ?? [];
        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
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

            // ── Validate SKU uniqueness if provided ────────────────────────
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
                'min_quantity', 'description', 'category_id', 'barcode', 'unit_id',
                'product_code',  // ← Allow SKU update
                'default_expiry_date', 'default_batch_number', 'default_serial_number',  // ← Default values
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

    private function getGLAccountByCode(int $tenantId, string $code): ?int
    {
        return $this->accounting->getAccountByCode($tenantId, $code);
    }
}
