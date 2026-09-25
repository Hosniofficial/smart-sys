<?php

declare(strict_types=1);

namespace App\Handlers;

use PDO;
use Throwable;
use Exception;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Services\MonologHandler;
use App\Services\InventoryOpeningBalanceService;

class ProductBranchHandler extends BaseHandler
{
    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->logger = MonologHandler::getInstance('product-branch');
    }

    /**
     * Get all products with their branch activation status
     * Filters by tenant_id and optionally by branch_id
     */
    public function getProductsStatus(Request $request, Response $response): Response
    {
        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر.', 403);
        }

        try {
            $queryParams = $request->getQueryParams();
            $branchId = !empty($queryParams['branch_id']) ? (int) $queryParams['branch_id'] : null;
            $status = !empty($queryParams['status']) ? (string) $queryParams['status'] : null;

            $allowedStatuses = ['DRAFT', 'ACTIVE_IN_BRANCH', 'INACTIVE', 'GL_POSTED', 'RECONCILED'];
            if ($status !== null && !in_array($status, $allowedStatuses, true)) {
                return $this->errorResponse($response, 'قيمة status غير صالحة.', 422);
            }

            $sql = "
                SELECT 
                    pbm.id AS mapping_id,
                    p.id AS product_id,
                    p.name AS product_name,
                    p.product_code,
                    b.id AS branch_id,
                    b.name AS branch_name,
                    pbm.activation_status,
                    pbm.gl_reconciliation_status,
                    pbm.average_cost,
                    pbm.last_gl_posting_date,
                    COALESCE(bp.quantity, 0) AS quantity,
                    ROUND(COALESCE(bp.quantity, 0) * COALESCE(pbm.average_cost, 0), 2) AS gl_value,
                    a_inv.code AS inventory_gl_code,
                    a_inv.name AS inventory_gl_name
                FROM product_branch_gl_mapping pbm
                INNER JOIN products p
                    ON pbm.product_id = p.id
                    AND p.tenant_id = pbm.tenant_id
                INNER JOIN branches b
                    ON pbm.branch_id = b.id
                    AND b.tenant_id = pbm.tenant_id
                LEFT JOIN branch_products bp
                    ON bp.product_id = p.id
                    AND bp.branch_id = b.id
                    AND bp.tenant_id = pbm.tenant_id
                LEFT JOIN accounts a_inv
                    ON pbm.inventory_gl_account_id = a_inv.id
                    AND (a_inv.tenant_id = pbm.tenant_id OR a_inv.tenant_id IS NULL)
                WHERE pbm.tenant_id = ?
            ";

            $params = [(int) $tenantId];

            if ($branchId) {
                $sql .= " AND pbm.branch_id = ?";
                $params[] = $branchId;
            }

            if ($status) {
                $sql .= " AND pbm.activation_status = ?";
                $params[] = $status;
            }

            $sql .= " ORDER BY b.name ASC, p.name ASC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $this->logger->info('Products status retrieved', [
                'tenant_id' => (int) $tenantId,
                'branch_id' => $branchId,
                'status' => $status,
                'count' => count($results)
            ]);

            return $this->successResponse($response, [
                'products' => $results
            ], 200);
        } catch (Throwable $e) {
            $this->logger->error('Failed to get products status', [
                'tenant_id' => (int) $tenantId,
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse($response, 'فشل جلب حالة المنتجات', 500);
        }
    }

    /**
     * Explicitly activate a product in a branch and create/update its GL mapping.
     * Routine stock operations and opening-balance posting must never call this implicitly.
     * Moves from: DRAFT → ACTIVE_IN_BRANCH.
     */
    public function activateProductInBranch(Request $request, Response $response): Response
    {
        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر.', 403);
        }

        try {
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];

            $productId = !empty($body['product_id']) ? (int) $body['product_id'] : null;
            $branchId = !empty($body['branch_id']) ? (int) $body['branch_id'] : null;
            $userId = $this->extractUserId($request);

            if (!$productId || !$branchId) {
                return $this->errorResponse($response, 'مطلوب معرف المنتج والفرع.', 422);
            }

            $productStmt = $this->db->prepare("
                SELECT id
                FROM products
                WHERE id = ? AND tenant_id = ?
                LIMIT 1
            ");
            $productStmt->execute([$productId, $tenantId]);
            if (!$productStmt->fetch(PDO::FETCH_ASSOC)) {
                return $this->errorResponse($response, 'المنتج غير موجود.', 404);
            }

            $branchStmt = $this->db->prepare("
                SELECT id, account_id
                FROM branches
                WHERE id = ? AND tenant_id = ?
                LIMIT 1
            ");
            $branchStmt->execute([$branchId, $tenantId]);
            $branch = $branchStmt->fetch(PDO::FETCH_ASSOC);
            if (!$branch) {
                return $this->errorResponse($response, 'الفرع غير موجود.', 404);
            }

            $inventoryGLId = $this->accounting->resolveInventoryAccountForBranch((int) $tenantId, $branchId);
            $purchaseGLId = $this->getGLAccountByCode((int) $tenantId, '5001');
            $cogsGLId = $this->getGLAccountByCode((int) $tenantId, '5103');

            if (!$inventoryGLId || !$purchaseGLId || !$cogsGLId) {
                return $this->errorResponse($response, 'لم يتم العثور على حسابات GL المطلوبة. يرجى التحقق من إعداد النظام.', 500);
            }

            $checkStmt = $this->db->prepare("
                SELECT id, activation_status
                FROM product_branch_gl_mapping
                WHERE product_id = ? AND branch_id = ? AND tenant_id = ?
                LIMIT 1
            ");
            $checkStmt->execute([$productId, $branchId, $tenantId]);
            $existingMapping = $checkStmt->fetch(PDO::FETCH_ASSOC);

            $this->db->beginTransaction();

            $sql = "
                INSERT INTO product_branch_gl_mapping
                (
                    tenant_id,
                    product_id,
                    branch_id,
                    inventory_gl_account_id,
                    purchase_gl_account_id,
                    cogs_gl_account_id,
                    activation_status,
                    activation_date,
                    created_by_user_id,
                    updated_by_user_id
                )
                VALUES (?, ?, ?, ?, ?, ?, 'ACTIVE_IN_BRANCH', NOW(), ?, ?)
                ON DUPLICATE KEY UPDATE
                    inventory_gl_account_id = VALUES(inventory_gl_account_id),
                    purchase_gl_account_id = VALUES(purchase_gl_account_id),
                    cogs_gl_account_id = VALUES(cogs_gl_account_id),
                    activation_status = 'ACTIVE_IN_BRANCH',
                    activation_date = NOW(),
                    updated_by_user_id = VALUES(updated_by_user_id)
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                (int) $tenantId,
                $productId,
                $branchId,
                $inventoryGLId,
                $purchaseGLId,
                $cogsGLId,
                $userId,
                $userId
            ]);

            $mappingId = $existingMapping['id'] ?? (int) $this->db->lastInsertId();
            $isNewActivation = !$existingMapping;

            if ($isNewActivation) {
                $this->logProductActivation(
                    (int) $tenantId,
                    $productId,
                    $branchId,
                    'ACTIVATED_IN_BRANCH',
                    $userId
                );
            }

            $this->db->commit();

            $this->logger->info('Product activated in branch', [
                'tenant_id' => (int) $tenantId,
                'product_id' => $productId,
                'branch_id' => $branchId,
                'mapping_id' => $mappingId,
                'user_id' => $userId,
                'is_new_activation' => $isNewActivation
            ]);

            return $this->successResponse($response, [
                'mapping_id' => $mappingId,
                'product_id' => $productId,
                'branch_id' => $branchId,
                'status' => 'ACTIVE_IN_BRANCH'
            ], $isNewActivation ? 201 : 200);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            $this->logger->error('Failed to activate product', [
                'tenant_id' => (int) $tenantId,
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse($response, 'فشل تفعيل المنتج', 500);
        }
    }

    /**
     * Post opening balance with GL journal entries
     * Moves from: ACTIVE_IN_BRANCH → RECONCILED
     */
    public function postOpeningBalance(Request $request, Response $response): Response
    {
        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر.', 403);
        }

        try {
            $body      = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
            // Support both mapping_id (legacy) and product_id + branch_id (new)
            $mappingId = !empty($body['mapping_id']) ? (int) $body['mapping_id'] : null;
            $productId = !empty($body['product_id']) ? (int) $body['product_id'] : null;
            $branchId  = !empty($body['branch_id']) ? (int) $body['branch_id'] : null;
            $quantity  = isset($body['quantity']) ? (float) $body['quantity'] : null;
            $unitCost  = isset($body['unit_cost']) ? (float) $body['unit_cost'] : null;
            $entryDate = !empty($body['entry_date']) ? (string) $body['entry_date'] : date('Y-m-d');
            $unitId    = !empty($body['unit_id']) ? (int) $body['unit_id'] : 1;
            $userId    = $this->extractUserId($request);

            if (($quantity === null || $unitCost === null)) {
                return $this->errorResponse($response, 'مطلوب الكمية والتكلفة.', 422);
            }
            if ($quantity <= 0 || $unitCost <= 0) {
                return $this->errorResponse($response, 'الكمية والتكلفة يجب أن تكونا أكبر من صفر.', 422);
            }

            // Determine mapping by ID or product_id + branch_id
            if (!$mappingId && ($productId && $branchId)) {
                // New mode: lookup mapping by product_id + branch_id
                $lookupStmt = $this->db->prepare("
                    SELECT id FROM product_branch_gl_mapping
                    WHERE product_id = ? AND branch_id = ? AND tenant_id = ?
                    LIMIT 1
                ");
                $lookupStmt->execute([$productId, $branchId, $tenantId]);
                $mappingRow = $lookupStmt->fetch(PDO::FETCH_ASSOC);
                if ($mappingRow) {
                    $mappingId = (int) $mappingRow['id'];
                }
            }

            if (!$mappingId) {
                return $this->errorResponse($response, 'مطلوب معرف الخريطة أو معرف المنتج والفرع.', 422);
            }

            // Fetch mapping with product/branch names
            $mapStmt = $this->db->prepare("
                SELECT pbm.*, p.name, b.name AS branch_name, b.cost_center_id
                FROM product_branch_gl_mapping pbm
                INNER JOIN products  p ON pbm.product_id = p.id AND p.tenant_id = pbm.tenant_id
                INNER JOIN branches  b ON pbm.branch_id  = b.id AND b.tenant_id = pbm.tenant_id
                WHERE pbm.id = ? AND pbm.tenant_id = ?
                LIMIT 1
            ");
            $mapStmt->execute([$mappingId, $tenantId]);
            $mapping = $mapStmt->fetch(PDO::FETCH_ASSOC);

            if (!$mapping) {
                return $this->errorResponse($response, 'الخريطة غير موجودة.', 404);
            }

            $costCenterId = !empty($mapping['cost_center_id']) ? (int) $mapping['cost_center_id'] : null;
            $totalCost    = round($quantity * $unitCost, 2);

            $this->db->beginTransaction();

            try {
                $service = new InventoryOpeningBalanceService($this->db);
                $journalEntryId = $service->post(
                    tenantId:             (int) $tenantId,
                    productId:            (int) $mapping['product_id'],
                    branchId:             (int) $mapping['branch_id'],
                    unitId:               $unitId,
                    quantity:             $quantity,
                    unitCost:             $unitCost,
                    entryDate:            $entryDate,
                    userId:               $userId,
                    productName:          $mapping['name'],
                    branchName:           $mapping['branch_name'],
                    inventoryGLAccountId: !empty($mapping['inventory_gl_account_id'])
                                              ? (int) $mapping['inventory_gl_account_id']
                                              : null,
                    costCenterId:         $costCenterId,
                    movementType:         'opening_balance_manual',
                );

                $this->db->commit();

                try {
                    $this->logProductActivation(
                        (int) $tenantId,
                        (int) $mapping['product_id'],
                        (int) $mapping['branch_id'],
                        'OPENING_BALANCE_POSTED',
                        $userId,
                        $journalEntryId,
                        $totalCost,
                        $totalCost
                    );
                } catch (Throwable $logError) {
                    $this->logger->warning('Failed to log opening balance event', [
                        'mapping_id' => $mappingId,
                        'error'      => $logError->getMessage(),
                    ]);
                }

                $this->logger->info('Opening balance posted successfully', [
                    'tenant_id'        => (int) $tenantId,
                    'mapping_id'       => $mappingId,
                    'product_id'       => (int) $mapping['product_id'],
                    'branch_id'        => (int) $mapping['branch_id'],
                    'quantity'         => $quantity,
                    'unit_cost'        => $unitCost,
                    'total_cost'       => $totalCost,
                    'journal_entry_id' => $journalEntryId,
                ]);

                return $this->successResponse($response, [
                    'mapping_id'       => $mappingId,
                    'journal_entry_id' => $journalEntryId,
                    'total_cost'       => $totalCost,
                    'status'           => 'RECONCILED',
                ], 200);

            } catch (Throwable $e) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                throw $e;
            }
        } catch (Throwable $e) {
            $this->logger->error('Failed to post opening balance', [
                'tenant_id' => (int) $tenantId,
                'error'     => $e->getMessage(),
            ]);
            return $this->errorResponse($response, 'فشل تسجيل الرصيد', 500);
        }
    }

    /**
     * Get GL reconciliation status for a product-branch
     */
    public function getReconciliationStatus(Request $request, Response $response): Response
    {
        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر.', 403);
        }

        try {
            $mappingId = (int) $request->getAttribute('id');
            if ($mappingId <= 0) {
                return $this->errorResponse($response, 'مطلوب معرف الربط.', 422);
            }

            $sql = "
                SELECT 
                    pbm.*,
                    p.name AS product_name,
                    b.name AS branch_name,
                    a_inv.code AS inventory_gl_code,
                    a_inv.name AS inventory_gl_name,
                    a_purch.code AS purchase_gl_code,
                    a_purch.name AS purchase_gl_name,
                    a_cogs.code AS cogs_gl_code,
                    a_cogs.name AS cogs_gl_name,
                    COALESCE(bp.quantity, 0) AS quantity,
                    ROUND(COALESCE(bp.quantity, 0) * COALESCE(pbm.average_cost, 0), 2) AS expected_balance,
                    COALESCE(bp.quantity_cost, 0) AS actual_bp_value
                FROM product_branch_gl_mapping pbm
                LEFT JOIN products p
                    ON pbm.product_id = p.id
                    AND p.tenant_id = pbm.tenant_id
                LEFT JOIN branches b
                    ON pbm.branch_id = b.id
                    AND b.tenant_id = pbm.tenant_id
                LEFT JOIN branch_products bp
                    ON bp.product_id = p.id
                    AND bp.branch_id = b.id
                    AND bp.tenant_id = pbm.tenant_id
                LEFT JOIN accounts a_inv
                    ON pbm.inventory_gl_account_id = a_inv.id
                    AND (a_inv.tenant_id = pbm.tenant_id OR a_inv.tenant_id IS NULL)
                LEFT JOIN accounts a_purch
                    ON pbm.purchase_gl_account_id = a_purch.id
                    AND (a_purch.tenant_id = pbm.tenant_id OR a_purch.tenant_id IS NULL)
                LEFT JOIN accounts a_cogs
                    ON pbm.cogs_gl_account_id = a_cogs.id
                    AND (a_cogs.tenant_id = pbm.tenant_id OR a_cogs.tenant_id IS NULL)
                WHERE pbm.id = ? AND pbm.tenant_id = ?
                LIMIT 1
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$mappingId, (int) $tenantId]);
            $data = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$data) {
                return $this->errorResponse($response, 'البيانات غير موجودة.', 404);
            }

            return $this->successResponse($response, $data, 200);
        } catch (Throwable $e) {
            $this->logger->error('Failed to get reconciliation status', [
                'tenant_id' => (int) $tenantId,
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse($response, 'فشل جلب حالة التوفيق', 500);
        }
    }

    /**
     * Get GL account ID by code — delegates to AccountingService (single source of truth)
     */
    private function getGLAccountByCode(int $tenantId, string $code): ?int
    {
        return $this->accounting->getAccountByCode($tenantId, $code);
    }

    /**
     * Log product activation event
     */
    private function logProductActivation(
        int $tenantId,
        int $productId,
        int $branchId,
        string $eventType,
        ?int $userId,
        ?int $glJournalId = null,
        ?float $debitAmount = null,
        ?float $creditAmount = null
    ): void {
        try {
            $mappingStmt = $this->db->prepare("
                SELECT id
                FROM product_branch_gl_mapping
                WHERE product_id = ? AND branch_id = ? AND tenant_id = ?
                LIMIT 1
            ");
            $mappingStmt->execute([$productId, $branchId, $tenantId]);
            $mapping = $mappingStmt->fetch(PDO::FETCH_ASSOC);

            if (!$mapping) {
                return;
            }

            $sql = "
                INSERT INTO product_activation_log
                (
                    tenant_id,
                    product_id,
                    product_branch_mapping_id,
                    branch_id,
                    event_type,
                    gl_journal_entry_id,
                    debit_amount,
                    credit_amount,
                    user_id
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $tenantId,
                $productId,
                (int) $mapping['id'],
                $branchId,
                $eventType,
                $glJournalId,
                $debitAmount,
                $creditAmount,
                $userId
            ]);
        } catch (Throwable $e) {
            $this->logger->warning('Failed to log activation event', [
                'tenant_id' => $tenantId,
                'product_id' => $productId,
                'branch_id' => $branchId,
                'event_type' => $eventType,
                'error' => $e->getMessage()
            ]);
        }
    }
}
