<?php

declare(strict_types=1);

namespace App\Handlers;

use PDO;
use PDOException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Services\CostingService;
use App\Services\BranchInventoryReconciliationService;
use App\Services\MonologHandler;

/**
 * Handles inter-branch stock transfers and their read models.
 *
 * A transfer updates source and destination stock in one transaction, records
 * the transfer and paired inventory movements, and uses one calculated
 * valuation throughout. ProductBranchActivationGuard validates both branch
 * mappings; BranchInventoryReconciliationService marks both balances
 * unreconciled. Read endpoints query transfer or inventory-transaction records.
 * Routes and permissions are defined in app/routes/branches.php.
 */
class StockTransferHandler extends BaseHandler
{
    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->logger = MonologHandler::getInstance('branch');
    }

    /** Transfer stock between active branches and report zero-valuation transfers. */
    public function transferStock(Request $request, Response $response, array $args): Response
    {
        $tenantId = $this->extractTenantId($request);
        $data     = $request->getParsedBody();
        $data     = is_array($data) ? $data : [];
        $userAttr = $request->getAttribute('user');
        $userId   = $request->getAttribute('user_id') ?? ($userAttr['id'] ?? null);

        $fromBranchId = filter_var($args['id'] ?? null, FILTER_VALIDATE_INT);
        $toBranchId = filter_var($data['to_branch'] ?? null, FILTER_VALIDATE_INT);
        $productId = filter_var($data['product_id'] ?? null, FILTER_VALIDATE_INT);
        if ($fromBranchId === false || $fromBranchId <= 0) {
            return $this->errorResponse($response, 'معرّف فرع المصدر غير صالح.', 400);
        }
        if ($toBranchId === false || $toBranchId <= 0 || $productId === false || $productId <= 0) {
            return $this->errorResponse($response, 'يجب تحديد فرع الهدف والمنتج بمعرّفات صحيحة.', 400);
        }
        if (isset($data['from_branch']) && (int) $data['from_branch'] !== $fromBranchId) {
            return $this->errorResponse($response, 'فرع المصدر لا يطابق الفرع المحدد في المسار.', 400);
        }
        if ($fromBranchId === $toBranchId) {
            return $this->errorResponse($response, 'يجب أن يختلف فرع المصدر عن فرع الهدف.', 400);
        }
        if (!isset($data['quantity']) || !is_numeric($data['quantity'])) {
            return $this->errorResponse($response, 'يجب تحديد كمية نقل صالحة.', 400);
        }

        $transferQty = (float) $data['quantity'];
        if (!is_finite($transferQty) || $transferQty <= 0) {
            return $this->errorResponse($response, 'كمية النقل يجب أن تكون أكبر من صفر.', 400);
        }

        $data['from_branch'] = $fromBranchId;
        $data['to_branch'] = $toBranchId;
        $data['product_id'] = $productId;
        $data['quantity'] = $transferQty;

        try {
            $this->db->beginTransaction();

            \App\Services\ProductBranchActivationGuard::assertActiveForStock(
                $this->db,
                (int) $tenantId,
                (int) $data['from_branch'],
                (int) $data['product_id']
            );
            \App\Services\ProductBranchActivationGuard::assertActiveForStock(
                $this->db,
                (int) $tenantId,
                (int) $data['to_branch'],
                (int) $data['product_id']
            );

            // Lock the source balance and recorded value before calculating transfer cost.
            $checkStmt = $this->db->prepare(
                "SELECT quantity, quantity_cost FROM branch_products
                 WHERE branch_id = ? AND product_id = ? AND tenant_id = ? FOR UPDATE"
            );
            $checkStmt->execute([$data['from_branch'], $data['product_id'], $tenantId]);
            $sourceRow = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($sourceRow === false) {
                throw new \Exception('المنتج غير موجود في الفرع المصدر');
            }
            $sourceQtyBefore  = (float) $sourceRow['quantity'];
            $sourceCostBefore = (float) ($sourceRow['quantity_cost'] ?? 0);
            $transferQty      = (float) $data['quantity'];

            if ($sourceQtyBefore < $transferQty) {
                throw new \Exception('الكمية المتاحة غير كافية للنقل');
            }

            // Calculate unit cost from the locked pre-transfer balance.
            $unitCostAtSource = $sourceQtyBefore > 0 ? ($sourceCostBefore / $sourceQtyBefore) : 0.0;

            // If the source has no recorded valuation, fall back to tenant WAC then purchase price.
            if ($unitCostAtSource <= 0.0) {
                try {
                    $unitCostAtSource = (float) (new CostingService($this->db))
                        ->getWeightedAverageCost((int) $tenantId, (int) $data['product_id'], date('Y-m-d H:i:s'));
                } catch (\Throwable $e) {
                    $unitCostAtSource = 0.0;
                }
                if ($unitCostAtSource <= 0.0) {
                    $costStmt = $this->db->prepare(
                        "SELECT COALESCE(purchase_price, 0) FROM products WHERE id = ? AND tenant_id = ?"
                    );
                    $costStmt->execute([(int) $data['product_id'], (int) $tenantId]);
                    $unitCostAtSource = (float) ($costStmt->fetchColumn() ?: 0);
                }
            }

            // Apply one rounded amount to both branches so the transfer preserves total inventory value.
            $deductedCost = round($unitCostAtSource * $transferQty, 2);
            $deductedCost = min($deductedCost, $sourceCostBefore);

            // Subtract the quantity and calculated value from the source.
            $this->db->prepare(
                "UPDATE branch_products
                 SET quantity      = quantity - ?,
                     quantity_cost = GREATEST(0, quantity_cost - ?)
                 WHERE branch_id = ? AND product_id = ? AND tenant_id = ?"
            )->execute([$transferQty, $deductedCost, $data['from_branch'], $data['product_id'], $tenantId]);
            BranchInventoryReconciliationService::markUnreconciled(
                $this->db,
                (int) $tenantId,
                (int) $data['from_branch'],
                (int) $data['product_id']
            );

            // Add the same quantity and value to the destination.
            $transferUnitCost  = $unitCostAtSource;
            $transferTotalCost = $deductedCost;
            $this->db->prepare(
                "INSERT INTO branch_products (branch_id, product_id, quantity, quantity_cost, tenant_id, gl_reconciled)
                 VALUES (?, ?, ?, ?, ?, 0)
                 ON DUPLICATE KEY UPDATE
                     quantity      = quantity + VALUES(quantity),
                     quantity_cost = quantity_cost + VALUES(quantity_cost)"
            )->execute([$data['to_branch'], $data['product_id'], $data['quantity'], $transferTotalCost, $tenantId]);
            BranchInventoryReconciliationService::markUnreconciled(
                $this->db,
                (int) $tenantId,
                (int) $data['to_branch'],
                (int) $data['product_id']
            );

            // Record transfer metadata.
            $this->db->prepare(
                "INSERT INTO stock_transfers (tenant_id, from_branch, to_branch, product_id, quantity, batch_number, expiry_date, serial, notes, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            )->execute([
                $tenantId, $data['from_branch'], $data['to_branch'],
                $data['product_id'], $data['quantity'],
                $data['batch_number'] ?? null,
                $data['expiry_date'] ?? null,
                $data['serial'] ?? null,
                $data['notes'] ?? null, $userId,
            ]);
            $transferId = (int) $this->db->lastInsertId();

            // Record paired outbound and inbound inventory movements at the transfer valuation.
            $unitId      = (int) ($data['unit_id'] ?? 1);
            $unitCost    = $transferUnitCost;
            $totalCost   = $transferTotalCost;
            $batchNumber = $data['batch_number'] ?? null;
            $expiryDate  = $data['expiry_date']  ?? null;
            $serial      = $data['serial']       ?? null;

            $txInsert = $this->db->prepare(
                "INSERT INTO inventory_transactions
                 (tenant_id, product_id, unit_id, branch_from, branch_to, movement_type,
                  quantity, unit_cost, total_cost, batch_number, expiry_date, serial,
                  notes, user_id, reference_type, reference_id, journal_entry_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'transfer', ?, NULL)"
            );

            $txInsert->execute([
                $tenantId, $data['product_id'], $unitId,
                $data['from_branch'], $data['to_branch'], 'transfer_out',
                $data['quantity'], $unitCost, $totalCost,
                $batchNumber, $expiryDate, $serial,
                'نقل إلى فرع آخر: ' . ($data['notes'] ?? ''),
                $userId, $transferId,
            ]);
            $transferOutId = (int) $this->db->lastInsertId();

            $txInsert->execute([
                $tenantId, $data['product_id'], $unitId,
                $data['from_branch'], $data['to_branch'], 'transfer_in',
                $data['quantity'], $unitCost, $totalCost,
                $batchNumber, $expiryDate, $serial,
                'نقل من فرع آخر: ' . ($data['notes'] ?? ''),
                $userId, $transferId,
            ]);
            $transferInId = (int) $this->db->lastInsertId();

            $warnings = null;
            $amount = $deductedCost;
            if ($amount > 0) {
                $fromAccountId = $this->accounting->resolveInventoryAccountForBranch(
                    (int) $tenantId,
                    (int) $data['from_branch']
                );
                $toAccountId = $this->accounting->resolveInventoryAccountForBranch(
                    (int) $tenantId,
                    (int) $data['to_branch']
                );
                if ($fromAccountId === null || $toAccountId === null) {
                    throw new \DomainException(
                        'تعذر تحديد حساب المخزون لأحد الفرعين؛ لم يتم تنفيذ نقل المخزون.'
                    );
                }

                $desc = 'نقل مخزون من مخزن #' . (int) $data['from_branch']
                      . ' إلى مخزن #' . (int) $data['to_branch']
                      . ' - منتج #' . (int) $data['product_id']
                      . ' - كمية ' . (float) $data['quantity'];

                $journalEntryId = $this->accounting->postJournalEntry(
                    (int) $tenantId,
                    'stock_transfer',
                    $transferId,
                    $desc,
                    [
                        ['account_id' => $toAccountId,   'debit' => $amount, 'credit' => 0,       'description' => 'نقل مخزون وارد'],
                        ['account_id' => $fromAccountId, 'debit' => 0,       'credit' => $amount, 'description' => 'نقل مخزون صادر'],
                    ],
                    date('Y-m-d'),
                    $userId,
                    null
                );

                if ($journalEntryId === null) {
                    throw new \RuntimeException(
                        'تعذر ترحيل قيد نقل المخزون؛ تم إلغاء النقل بالكامل.'
                    );
                }

                $linkStmt = $this->db->prepare(
                    "UPDATE inventory_transactions
                     SET journal_entry_id = ?
                     WHERE tenant_id = ? AND reference_type = 'transfer' AND reference_id = ?"
                );
                $linkStmt->execute([$journalEntryId, (int) $tenantId, $transferId]);
                if ($linkStmt->rowCount() !== 2) {
                    throw new \RuntimeException(
                        'تعذر ربط حركتي المخزون بقيد النقل؛ تم إلغاء النقل بالكامل.'
                    );
                }
            } else {
                $this->logger->warning('[StockTransfer] Zero valuation; no journal entry is required.', [
                    'from_branch' => $data['from_branch'],
                    'to_branch' => $data['to_branch'],
                    'product_id' => $data['product_id'],
                    'quantity' => $data['quantity'],
                ]);
                $warnings = ['journal_entry_skipped' => true, 'reason' => 'zero_amount'];
            }

            $this->db->commit();

            $responsePayload = [
                'status'  => 'success',
                'message' => 'تم نقل المخزون بنجاح',
                'data'    => [
                    'transfer_out_tx_id' => $transferOutId,
                    'transfer_in_tx_id'  => $transferInId,
                    'stock_transfer_id'  => $transferId,
                ],
            ];
            if ($warnings !== null) {
                $responsePayload['warnings'] = $warnings;
            }

            return $this->jsonResponse($response, $responsePayload);
        } catch (\Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logger->error('فشل نقل المخزون: ' . $e->getMessage());
            return $this->errorResponse(
                $response,
                $e instanceof \DomainException ? $e->getMessage() : 'فشل نقل المخزون',
                $e instanceof \DomainException ? 422 : 400
            );
        }
    }

    /** List tenant transfers with optional branch, product, and date filters. */
    public function listTransfers(Request $request, Response $response, array $args): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $qp        = $request->getQueryParams();
            $branchId  = isset($qp['branch_id']) ? (int) $qp['branch_id'] : null;
            $productId = isset($qp['product_id']) ? (int) $qp['product_id'] : null;
            $dateFrom  = $qp['date_from'] ?? null;
            $dateTo    = $qp['date_to']   ?? null;

            $where  = ['st.tenant_id = ?'];
            $params = [$tenantId];

            if ($branchId) {
                $where[]  = '(st.from_branch = ? OR st.to_branch = ?)';
                $params[] = $branchId;
                $params[] = $branchId;
            }
            if ($productId) {
                $where[] = 'st.product_id = ?';
                $params[] = $productId;
            }
            if ($dateFrom) {
                $where[] = 'st.created_at >= ?';
                $params[] = $dateFrom;
            }
            if ($dateTo) {
                $nextDay  = date('Y-m-d', strtotime($dateTo . ' +1 day'));
                $where[]  = 'st.created_at < ?';
                $params[] = $nextDay . ' 00:00:00';
            }

            $sql = "
                SELECT
                    st.id, st.product_id, p.name AS product_name, p.barcode,
                    st.from_branch, w1.name AS from_branch_name,
                    st.to_branch,   w2.name AS to_branch_name,
                    st.quantity, st.notes,
                    st.created_by, u.name AS created_by_name, st.created_at
                FROM stock_transfers st
                LEFT JOIN products p  ON p.id  = st.product_id  AND (p.tenant_id  = st.tenant_id OR p.tenant_id  IS NULL)
                LEFT JOIN branches w1 ON w1.id = st.from_branch
                LEFT JOIN branches w2 ON w2.id = st.to_branch
                LEFT JOIN users u     ON u.id  = st.created_by
                WHERE " . implode(' AND ', $where) . "
                ORDER BY st.created_at DESC
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $this->successResponse($response, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], 200);
        } catch (\Throwable $e) {
            $this->logger->error('خطأ في جلب تحويلات الفرع: ' . $e->getMessage());
            return $this->errorResponse($response, 'فشل في جلب تحويلات الفرع', 500);
        }
    }

    /** Return one transfer and its paired inventory-transaction records. */
    public function getTransferById(Request $request, Response $response, array $args): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }
            $id = (int) ($args['id'] ?? 0);
            if ($id <= 0) {
                return $this->errorResponse($response, 'معرّف عملية النقل غير صالح', 400);
            }

            $stmt = $this->db->prepare("
                SELECT st.*, p.name AS product_name, p.barcode,
                       b1.name AS from_branch_name, b2.name AS to_branch_name,
                       u.name AS created_by_name
                FROM stock_transfers st
                LEFT JOIN products p  ON p.id  = st.product_id  AND (p.tenant_id  = st.tenant_id OR p.tenant_id  IS NULL)
                LEFT JOIN branches b1 ON b1.id = st.from_branch
                LEFT JOIN branches b2 ON b2.id = st.to_branch
                LEFT JOIN users u     ON u.id  = st.created_by
                WHERE st.tenant_id = ? AND st.id = ?
            ");
            $stmt->execute([$tenantId, $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return $this->errorResponse($response, 'لم يتم العثور على عملية النقل', 404);
            }

            $stmtTx = $this->db->prepare(
                "SELECT it.* FROM inventory_transactions it
                 WHERE it.tenant_id = ? AND it.reference_type = 'transfer' AND it.reference_id = ?
                 ORDER BY it.created_at ASC"
            );
            $stmtTx->execute([$tenantId, $id]);
            $row['inventory_transactions'] = $stmtTx->fetchAll(PDO::FETCH_ASSOC) ?: [];

            return $this->successResponse($response, $row, 200);
        } catch (\Throwable $e) {
            $this->logger->error('خطأ في جلب تفاصيل التحويل: ' . $e->getMessage());
            return $this->errorResponse($response, 'فشل في جلب تفاصيل التحويل', 500);
        }
    }

    /** List transfer movements that involve the requested branch. */
    public function listBranchTransfersByInventory(Request $request, Response $response, array $args): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            $branchId = (int) ($args['id'] ?? 0);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $stmt = $this->db->prepare("
                SELECT it.id, it.product_id, p.name AS product_name, p.barcode,
                       it.branch_from AS from_branch, w1.name AS from_branch_name,
                       it.branch_to   AS to_branch,   w2.name AS to_branch_name,
                       it.quantity, it.movement_type, it.notes,
                       it.user_id AS created_by, u.name AS created_by_name, it.created_at
                FROM inventory_transactions it
                LEFT JOIN products p  ON p.id  = it.product_id AND (p.tenant_id  = it.tenant_id OR p.tenant_id  IS NULL)
                LEFT JOIN branches w1 ON w1.id = it.branch_from
                LEFT JOIN branches w2 ON w2.id = it.branch_to
                LEFT JOIN users u     ON u.id  = it.user_id
                WHERE it.tenant_id = ?
                  AND (it.branch_from = ? OR it.branch_to = ?)
                  AND it.movement_type IN ('transfer_out', 'transfer_in')
                ORDER BY it.created_at DESC
            ");
            $stmt->execute([$tenantId, $branchId, $branchId]);
            return $this->successResponse($response, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], 200);
        } catch (\Throwable $e) {
            $this->logger->error('خطأ في جلب تحويلات الفرع: ' . $e->getMessage());
            return $this->errorResponse($response, 'فشل في جلب التحويلات', 500);
        }
    }

}
