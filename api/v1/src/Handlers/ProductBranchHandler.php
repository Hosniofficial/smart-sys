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

/**
 * Manages tenant-scoped product/branch GL mappings and opening balances.
 *
 * Read and activation operations use PDO directly; opening-balance posting and
 * reversal coordinate inventory and accounting services within database transactions.
 * Routes and permission middleware are defined in product-branch.php.
 */
class ProductBranchHandler extends BaseHandler
{
    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->logger = MonologHandler::getInstance('product-branch');
    }

    /** List product/branch mappings with stock and inventory-account details. */
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

    /** List unreversed opening balances for a tenant branch, including linked transaction flags. */
    public function getActiveOpeningBalances(Request $request, Response $response): Response
    {
        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر.', 403);
        }

        $branchId = (int) ($request->getQueryParams()['branch_id'] ?? 0);
        if ($branchId <= 0) {
            return $this->errorResponse($response, 'مطلوب تحديد الفرع.', 422);
        }

        try {
            $stmt = $this->db->prepare("
                SELECT ob.id AS opening_balance_id,
                       ob.product_id,
                       ob.branch_id,
                       ob.quantity AS opening_quantity,
                       ob.unit_cost AS opening_unit_cost,
                       p.name AS product_name,
                       it.id AS inventory_transaction_id,
                       CASE WHEN it.id IS NULL THEN 0 ELSE 1 END AS has_inventory_transaction,
                       CASE WHEN it.journal_entry_id IS NULL THEN 0 ELSE 1 END AS has_journal_entry
                FROM opening_balances ob
                INNER JOIN products p
                    ON p.id = ob.product_id AND p.tenant_id = ob.tenant_id
                LEFT JOIN inventory_transactions it
                    ON it.id = ob.inventory_transaction_id
                   AND (it.tenant_id = ob.tenant_id OR it.tenant_id IS NULL)
                WHERE ob.tenant_id = ?
                  AND ob.branch_id = ?
                  AND ob.reversed_at IS NULL
                ORDER BY p.name ASC
            ");
            $stmt->execute([(int) $tenantId, $branchId]);

            return $this->successResponse($response, [
                'opening_balances' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
            ]);
        } catch (Throwable $e) {
            $this->logger->error('Failed to get active opening balances', [
                'tenant_id' => (int) $tenantId,
                'branch_id' => $branchId,
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse($response, 'تعذر جلب الأرصدة الافتتاحية.', 500);
        }
    }

    /** Reverse an opening balance after checking stock, GL, cost-layer, and movement consistency. */
    public function reverseOpeningBalance(Request $request, Response $response): Response
    {
        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر.', 403);
        }

        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $productId = (int) ($body['product_id'] ?? 0);
        $branchId = (int) ($body['branch_id'] ?? 0);
        $userId = $this->extractUserId($request);

        if ($productId <= 0 || $branchId <= 0) {
            return $this->errorResponse($response, 'مطلوب معرف المنتج والفرع.', 422);
        }

        try {
            $this->db->beginTransaction();

            $productStmt = $this->db->prepare(
                'SELECT id FROM products
                 WHERE id = ? AND tenant_id = ?
                 LIMIT 1 FOR UPDATE'
            );
            $productStmt->execute([$productId, (int) $tenantId]);
            if (!$productStmt->fetchColumn()) {
                throw new \DomainException('المنتج غير موجود ضمن هذا المستأجر.');
            }

            $branchStmt = $this->db->prepare(
                'SELECT id FROM branches WHERE id = ? AND tenant_id = ? LIMIT 1'
            );
            $branchStmt->execute([$branchId, (int) $tenantId]);
            if (!$branchStmt->fetchColumn()) {
                throw new \DomainException('الفرع غير موجود ضمن هذا المستأجر.');
            }

            $openingStmt = $this->db->prepare("
                SELECT id, quantity, unit_cost, inventory_transaction_id
                FROM opening_balances
                WHERE tenant_id = ? AND product_id = ? AND branch_id = ?
                  AND reversed_at IS NULL
                ORDER BY id DESC
                LIMIT 1 FOR UPDATE
            ");
            $openingStmt->execute([(int) $tenantId, $productId, $branchId]);
            $opening = $openingStmt->fetch(PDO::FETCH_ASSOC);
            if (!$opening) {
                throw new \DomainException('لا يوجد رصيد افتتاحي نشط لهذا المنتج في الفرع.');
            }

            $openingTxStmt = $this->db->prepare("
                SELECT id, unit_id, movement_type, journal_entry_id,
                       quantity, unit_cost, total_cost
                FROM inventory_transactions
                WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL)
                LIMIT 1 FOR UPDATE
            ");
            $openingTxStmt->execute([
                (int) ($opening['inventory_transaction_id'] ?? 0),
                (int) $tenantId,
            ]);
            $openingTransaction = $openingTxStmt->fetch(PDO::FETCH_ASSOC);
            if (!$openingTransaction) {
                throw new \DomainException('لا توجد حركة مخزون مرتبطة بهذا الرصيد الافتتاحي.');
            }
            if (
                !in_array($openingTransaction['movement_type'], [
                    'opening_balance', 'initial_stock',
                    'opening_balance_manual', 'opening_balance_bulk',
                ], true)
                || abs((float) $openingTransaction['quantity'] - (float) $opening['quantity']) > 0.0001
                || abs((float) $openingTransaction['unit_cost'] - (float) $opening['unit_cost']) > 0.0001
            ) {
                throw new \DomainException('بيانات حركة المخزون لا تطابق سجل الرصيد الافتتاحي.');
            }

            $movementStmt = $this->db->prepare("
                SELECT id
                FROM inventory_transactions
                WHERE product_id = ?
                  AND (tenant_id = ? OR tenant_id IS NULL)
                  AND (branch_from = ? OR branch_to = ?)
                  AND id <> ?
                  AND NOT EXISTS (
                      SELECT 1
                      FROM opening_balances reversed_opening
                      WHERE reversed_opening.inventory_transaction_id = inventory_transactions.id
                        AND reversed_opening.reversed_at IS NOT NULL
                  )
                  AND NOT EXISTS (
                      SELECT 1
                      FROM opening_balances reversed_opening
                      WHERE reversed_opening.reversal_inventory_transaction_id = inventory_transactions.id
                        AND reversed_opening.reversed_at IS NOT NULL
                  )
                LIMIT 1
            ");
            $movementStmt->execute([
                $productId,
                (int) $tenantId,
                $branchId,
                $branchId,
                (int) $openingTransaction['id'],
            ]);
            if ($movementStmt->fetchColumn()) {
                throw new \DomainException(
                    'لا يمكن عكس الرصيد الافتتاحي لوجود حركات مخزون لاحقة في هذا الفرع.'
                );
            }

            $stockStmt = $this->db->prepare("
                SELECT quantity, quantity_cost
                FROM branch_products
                WHERE tenant_id = ? AND product_id = ? AND branch_id = ?
                LIMIT 1 FOR UPDATE
            ");
            $stockStmt->execute([(int) $tenantId, $productId, $branchId]);
            $stock = $stockStmt->fetch(PDO::FETCH_ASSOC);
            $openingQuantity = (float) $opening['quantity'];
            $openingUnitCost = (float) $opening['unit_cost'];
            $openingValue = isset($openingTransaction['total_cost'])
                ? (float) $openingTransaction['total_cost']
                : round($openingQuantity * $openingUnitCost, 2);
            if (
                !$stock
                || abs((float) $stock['quantity'] - $openingQuantity) > 0.0001
                || abs((float) $stock['quantity_cost'] - $openingValue) > 0.01
            ) {
                throw new \DomainException(
                    'لا يمكن العكس لأن الكمية أو قيمة المخزون الحالية لا تطابق الرصيد الافتتاحي.'
                );
            }

            $mappingCheck = $this->db->prepare("
                SELECT id, gl_balance
                FROM product_branch_gl_mapping
                WHERE tenant_id = ? AND product_id = ? AND branch_id = ?
                LIMIT 1 FOR UPDATE
            ");
            $mappingCheck->execute([(int) $tenantId, $productId, $branchId]);
            $mapping = $mappingCheck->fetch(PDO::FETCH_ASSOC);
            $hasJournalEntry = !empty($openingTransaction['journal_entry_id']);
            if (
                $hasJournalEntry
                && $mapping
                && abs((float) $mapping['gl_balance'] - $openingValue) > 0.01
            ) {
                throw new \DomainException(
                    'رصيد حساب المخزون لا يطابق قيمة الرصيد الافتتاحي؛ يلزم تسوية الفرق قبل العكس.'
                );
            }
            if (!$hasJournalEntry && $mapping && abs((float) $mapping['gl_balance']) > 0.01) {
                throw new \DomainException(
                    'لا يمكن عكس الرصيد المخزني غير المرحّل محاسبيًا لأن mapping الفرع يحمل رصيدًا محاسبيًا؛ راجع المطابقة أولًا.'
                );
            }

            if ($hasJournalEntry) {
                $journalStmt = $this->db->prepare("
                    SELECT status, reversal_of
                    FROM journal_entries
                    WHERE id = ? AND tenant_id = ?
                    LIMIT 1 FOR UPDATE
                ");
                $journalStmt->execute([(int) $openingTransaction['journal_entry_id'], (int) $tenantId]);
                $journal = $journalStmt->fetch(PDO::FETCH_ASSOC);
                if (!$journal || $journal['status'] !== 'posted' || $journal['reversal_of'] !== null) {
                    throw new \DomainException('القيد المرتبط غير مرحّل أو سبق عكسه.');
                }
            }

            $snapshotStmt = $this->db->prepare("
                SELECT id, quantity_received, quantity_remaining, unit_cost
                FROM inventory_cost_snapshot
                WHERE tenant_id = ? AND product_id = ? AND branch_id = ?
                  AND source_type = 'OPENING_BALANCE'
                  AND quantity_remaining > 0
                LIMIT 2 FOR UPDATE
            ");
            $snapshotStmt->execute([(int) $tenantId, $productId, $branchId]);
            $snapshots = $snapshotStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (count($snapshots) > 1) {
                throw new \DomainException('توجد طبقات تكلفة افتتاحية متعددة؛ يلزم مراجعتها محاسبيًا قبل العكس.');
            }
            $snapshot = $snapshots[0] ?? null;
            if ($snapshot && (
                abs((float) $snapshot['quantity_received'] - $openingQuantity) > 0.0001
                || abs((float) $snapshot['quantity_remaining'] - $openingQuantity) > 0.0001
                || abs((float) $snapshot['unit_cost'] - $openingUnitCost) > 0.0001
            )) {
                throw new \DomainException('طبقة التكلفة الحالية لا تطابق الرصيد الافتتاحي.');
            }

            $reversalJournalId = $hasJournalEntry
                ? $this->accounting->reverseJournalEntry(
                    (int) $openingTransaction['journal_entry_id'],
                    (int) $tenantId,
                    $userId
                )
                : null;

            $this->db->prepare("
                INSERT INTO inventory_transactions
                    (tenant_id, product_id, unit_id, branch_from, branch_to,
                     quantity, unit_cost, total_cost, movement_type, movement_date,
                     reference_type, reference_id, notes, user_id, journal_entry_id, created_at)
                VALUES (?, ?, ?, ?, NULL, ?, ?, ?, 'adjustment_out', NOW(),
                        'opening_balance_reversal', ?, ?, ?, ?, NOW())
            ")->execute([
                (int) $tenantId,
                $productId,
                (int) ($openingTransaction['unit_id'] ?: 1),
                $branchId,
                $openingQuantity,
                $openingUnitCost,
                $openingValue,
                (int) $opening['id'],
                'عكس الرصيد الافتتاحي بعد التحقق من عدم وجود حركات لاحقة.',
                $userId,
                $reversalJournalId,
            ]);
            $reversalTransactionId = (int) $this->db->lastInsertId();

            if ($hasJournalEntry) {
                $this->db->prepare("
                    UPDATE branch_products
                    SET quantity = 0,
                        quantity_cost = 0,
                        gl_reconciled = 1,
                        last_gl_posting_date = NOW(),
                        last_update = NOW()
                    WHERE tenant_id = ? AND product_id = ? AND branch_id = ?
                ")->execute([(int) $tenantId, $productId, $branchId]);
            } else {
                $this->db->prepare("
                    UPDATE branch_products
                    SET quantity = 0,
                        quantity_cost = 0,
                        gl_reconciled = 0,
                        last_gl_posting_date = NULL,
                        last_update = NOW()
                    WHERE tenant_id = ? AND product_id = ? AND branch_id = ?
                ")->execute([(int) $tenantId, $productId, $branchId]);
            }

            if ($snapshot) {
                $this->db->prepare("
                    UPDATE inventory_cost_snapshot
                    SET quantity_remaining = 0,
                        is_fully_consumed = 1,
                        depletion_date = NOW()
                    WHERE id = ? AND tenant_id = ?
                ")->execute([(int) $snapshot['id'], (int) $tenantId]);
            }

            if ($hasJournalEntry && $mapping) {
                $mappingStmt = $this->db->prepare("
                    UPDATE product_branch_gl_mapping
                    SET activation_status = 'ACTIVE_IN_BRANCH',
                        gl_reconciliation_status = 'NOT_POSTED',
                        average_cost = NULL,
                        gl_balance = 0,
                        last_gl_posting_date = NULL,
                        updated_by_user_id = ?,
                        updated_at = NOW()
                    WHERE tenant_id = ? AND product_id = ? AND branch_id = ?
                ");
                $mappingStmt->execute([$userId, (int) $tenantId, $productId, $branchId]);
            }

            $this->db->prepare("
                UPDATE opening_balances
                SET reversed_at = NOW(),
                    reversed_by = ?,
                    reversal_journal_entry_id = ?,
                    reversal_inventory_transaction_id = ?
                WHERE id = ? AND tenant_id = ? AND reversed_at IS NULL
            ")->execute([
                $userId,
                $reversalJournalId,
                $reversalTransactionId,
                (int) $opening['id'],
                (int) $tenantId,
            ]);

            $this->db->prepare("
                UPDATE products
                SET opening_balance_posted = EXISTS (
                    SELECT 1 FROM opening_balances
                    WHERE tenant_id = ? AND product_id = ? AND reversed_at IS NULL
                ),
                updated_at = NOW()
                WHERE tenant_id = ? AND id = ?
            ")->execute([(int) $tenantId, $productId, (int) $tenantId, $productId]);

            $this->db->commit();

            return $this->successResponse($response, [
                'product_id' => $productId,
                'branch_id' => $branchId,
                'opening_balance_id' => (int) $opening['id'],
                'reversal_journal_entry_id' => $reversalJournalId,
                'reversal_inventory_transaction_id' => $reversalTransactionId,
                'message' => $hasJournalEntry
                    ? 'تم عكس الرصيد الافتتاحي محاسبيًا ومخزنيًا مع الاحتفاظ بسجل المنتج.'
                    : 'تم عكس الرصيد الافتتاحي مخزنيًا؛ لم يكن له قيد محاسبي مرحّل، وتم الاحتفاظ بسجل المنتج.',
            ]);
        } catch (\DomainException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            return $this->errorResponse($response, $e->getMessage(), 409);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            $this->logger->error('Failed to reverse opening balance', [
                'tenant_id' => (int) $tenantId,
                'product_id' => $productId,
                'branch_id' => $branchId,
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse($response, 'تعذر عكس الرصيد الافتتاحي.', 500);
        }
    }

    /**
     * Activate a product/branch mapping and resolve the inventory, purchase, and COGS accounts.
     * Operational stock flows must not invoke this transition implicitly.
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

    /** Post the opening quantity and cost through InventoryOpeningBalanceService. */
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
                return $this->errorResponse($response, 'الكمية وتكلفة الوحدة مطلوبتان.', 422);
            }
            if ($quantity <= 0 || $unitCost <= 0) {
                return $this->errorResponse($response, 'يجب أن تكون الكمية وتكلفة الوحدة أكبر من صفر.', 422);
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
            return $this->errorResponse($response, 'تعذّر تسجيل رصيد المخزون', 500);
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
