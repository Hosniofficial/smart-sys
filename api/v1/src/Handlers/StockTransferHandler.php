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
 * StockTransferHandler
 *
 * Handles inter-branch stock transfer operations extracted from BranchHandler:
 *   - transferStock()                    — execute a stock transfer between branches
 *   - listTransfers()                    — list transfers with optional filters
 *   - getTransferById()                  — get transfer details + inventory transactions
 *   - listBranchTransfersByInventory()   — transfers for a branch from inventory_transactions
 *   - getTransferHistory()               — transfer history for a product
 */
class StockTransferHandler extends BaseHandler
{
    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->logger = MonologHandler::getInstance('branch');
    }

    // =========================================================================
    // Public Endpoints
    // =========================================================================

    /**
     * نقل المخزون بين الفروع
     * POST /branches/transfer
     */
    public function transferStock(Request $request, Response $response, array $args): Response
    {
        $tenantId = $this->extractTenantId($request);
        $data     = $request->getParsedBody();
        $userAttr = $request->getAttribute('user');
        $userId   = $request->getAttribute('user_id') ?? ($userAttr['id'] ?? null);

        $required = ['from_branch', 'to_branch', 'product_id', 'quantity'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return $this->errorResponse($response, "حقل مطلوب مفقود: $field", 400);
            }
        }

        try {
            $this->db->beginTransaction();

            // 1. التأكد من وجود كمية كافية في الفرع المصدر
            // ── AUDIT FIX #9/#10: نقرأ quantity_cost أيضاً لحساب متوسط تكلفة
            // الوحدة في PHP (وليس عبر تعبير SQL ذاتي المرجعية داخل نفس UPDATE).
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
                throw new \Exception('الكمية المتوفرة غير كافية للنقل');
            }

            // ── AUDIT FIX #9 (CRITICAL): كانت القيمة السابقة تُحسب داخل نفس جملة
            // UPDATE عبر `quantity_cost / NULLIF(quantity, 0) * ?`. MySQL يقيّم
            // إسنادات UPDATE بجدول واحد من اليسار لليمين ويسمح للإسناد الثاني بأن
            // يرى القيمة *المُحدَّثة بالفعل* للعمود المُسنَد أولاً (quantity) — وهذا
            // سلوك موثَّق رسمياً في دليل MySQL كمخالفة لمعيار SQL القياسي. النتيجة:
            // كان المقام يستخدم الكمية *بعد* الخصم بدل *قبله*، فيُحسب متوسط تكلفة
            // أعلى من الحقيقي ويُخصم مبلغ أكبر من المفروض من قيمة مخزون الفرع
            // المصدر في كل عملية نقل تقريباً.
            //
            // الإصلاح: نحسب متوسط التكلفة/الوحدة والمبلغ المخصوم بالكامل في PHP
            // باستخدام القيم المقروءة *قبل* أي تعديل، ثم نمرر القيمة النهائية
            // كرقم ثابت لجملة الـ UPDATE بدل تعبير يعيد قراءة عمود مُعدَّل للتو.
            $unitCostAtSource = $sourceQtyBefore > 0 ? ($sourceCostBefore / $sourceQtyBefore) : 0.0;

            // Fallback: إن كان الفرع المصدر بلا تكلفة مخزون مسجَّلة (مثلاً منتج جديد
            // لم تُرحَّل له تكلفة فرعية بعد)، نرجع لنفس تسلسل fallback المستخدَم أصلاً
            // للقيد المحاسبي (WAC على مستوى المستأجر ثم purchase_price)، بدل الاعتماد
            // على قيمة يُرسلها الطالب في الطلب (كانت مصدر تكلفة ثالث منفصل — راجع
            // AUDIT FIX #10 أدناه).
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

            // المبلغ الفعلي المنقول = نفس المبلغ يُخصم من المصدر ويُضاف للوجهة
            // بالضبط (بدل قيمتين مستقلتين قد تختلفان) — يضمن أن قيمة المخزون
            // الإجمالية للمستأجر (مجموع كل الفروع) لا تتغير بمجرد النقل.
            $deductedCost = round($unitCostAtSource * $transferQty, 2);
            $deductedCost = min($deductedCost, $sourceCostBefore); // لا يمكن خصم أكثر مما هو مسجَّل فعلياً

            // 2. خصم الكمية والتكلفة من الفرع المصدر — بقيم ثابتة محسوبة مسبقاً
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

            // 3. إضافة الكمية والتكلفة إلى الفرع الوجهة
            // ── AUDIT FIX #10 (HIGH): كانت هذه القيمة مبنية على `unit_cost` من
            // جسم الطلب (قيمة يُرسلها الطالب، تُصبح صفراً إن لم تُرسَل) — مصدر
            // تكلفة مستقل تماماً عن التكلفة الفعلية بالفرع المصدر (خطوة 2 أعلاه)
            // وعن WAC المستخدَم لاحقاً في القيد المحاسبي (خطوة 7). أصبحت الآن
            // تستخدم *نفس* $deductedCost المخصوم فعلياً من المصدر — مصدر تكلفة
            // واحد متسق عبر: تخفيض المصدر / زيادة الوجهة / سجل الحركات / القيد.
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

            // 4. سجل عملية النقل في stock_transfers (مع batch, expiry, serial - Phase 3)
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

            // 5 + 6. سجل حركات المخزون (صادر + وارد)
            // ── AUDIT FIX #10: نفس $transferUnitCost/$transferTotalCost المستخدَمة
            // فعلياً لتحديث الفرعين أعلاه — وليس `unit_cost` من جسم الطلب (كان
            // يُصبح صفراً إن لم يُرسله الطالب، فيُدرَج سجل حركة بتكلفة صفرية بينما
            // القيمة الفعلية المخصومة من الفرع المصدر مبلغ حقيقي).
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

            // 7. القيد المحاسبي
            $accStmt = $this->db->prepare(
                "SELECT id, account_id FROM branches WHERE id IN (?, ?) AND tenant_id = ?"
            );
            $accStmt->execute([(int) $data['from_branch'], (int) $data['to_branch'], (int) $tenantId]);
            $accRows = $accStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $fromAccountId = null;
            $toAccountId   = null;
            foreach ($accRows as $r) {
                if ((int) $r['id'] === (int) $data['from_branch']) {
                    $fromAccountId = $r['account_id'] ? (int) $r['account_id'] : null;
                }
                if ((int) $r['id'] === (int) $data['to_branch']) {
                    $toAccountId   = $r['account_id'] ? (int) $r['account_id'] : null;
                }
            }

            $warnings = null;

            if ($fromAccountId && $toAccountId) {
                // ── AUDIT FIX #10: كان يُعاد حساب WAC مستقل هنا على مستوى
                // المستأجر بالكامل (CostingService), وهي قيمة ثالثة منفصلة عن
                // التكلفة المخصومة فعلياً من الفرع المصدر (خطوة 2) وعن تكلفة سجل
                // الحركات (خطوة 5+6) — فكانت الثلاثة تظهر أرقاماً مختلفة لنفس
                // النقل الواحد. القيد الآن يستخدم بالضبط $deductedCost نفسها،
                // فيتطابق مع ما نقص من مخزون الفرع المصدر فعلياً.
                $amount = $deductedCost;

                if ($amount > 0) {
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

                    if ($journalEntryId) {
                        $this->db->prepare(
                            "UPDATE inventory_transactions
                             SET journal_entry_id = ?
                             WHERE tenant_id = ? AND reference_type = 'transfer' AND reference_id = ?"
                        )->execute([$journalEntryId, (int) $tenantId, $transferId]);
                    } else {
                        // 🔴 إصلاح: كانت هذه الحالة تحديداً (حسابات موجودة + مبلغ صحيح لكن
                        // postJournalEntry فشل داخلياً — مثل فترة محاسبية مغلقة أو خطأ قاعدة
                        // بيانات) لا تُنتج أي تحذير إطلاقاً، لا في الـ log ولا في الاستجابة —
                        // كان النقل ينجح بصمت تام دون أي أثر لفشل الترحيل المحاسبي.
                        $this->logger->error('[StockTransfer] postJournalEntry returned null despite valid accounts/amount.', [
                            'from_branch' => $data['from_branch'], 'to_branch' => $data['to_branch'],
                            'product_id'  => $data['product_id'],  'amount'    => $amount,
                        ]);
                        $warnings = ['journal_entry_skipped' => true, 'reason' => 'posting_failed'];
                    }
                } else {
                    $this->logger->warning('[StockTransfer] Zero amount — journal entry skipped.', [
                        'from_branch' => $data['from_branch'], 'to_branch' => $data['to_branch'],
                        'product_id'  => $data['product_id'],  'quantity'  => $data['quantity'],
                    ]);
                    $warnings = ['journal_entry_skipped' => true, 'reason' => 'zero_amount'];
                }
            } else {
                $this->logger->warning('[StockTransfer] Missing branch account_id — journal entry skipped.', [
                    'from_branch' => $data['from_branch'], 'to_branch' => $data['to_branch'],
                ]);
                $warnings = ['journal_entry_skipped' => true, 'reason' => 'missing_branch_account'];
            }

            $this->db->commit();

            // ⚠️ إصلاح: كانت التحذيرات الثلاثة أعلاه تُسجَّل في الـ log فقط ولا تصل أبداً
            // للاستجابة نفسها — لا سبيل لأي واجهة أو محاسب لمعرفة أن النقل لم يُرحَّل للـ GL
            // دون تفتيش سجلات الخادم مباشرة. أصبحت الآن تظهر في response['warnings']، بنفس
            // نمط StockAdjustmentHandler الذي يفعل هذا بشكل صحيح فعلاً.
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
            return $this->errorResponse($response, 'فشل نقل المخزون', 400);
        }
    }

    /**
     * قائمة عمليات نقل المخزون مع فلاتر اختيارية
     */
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

    /**
     * تفاصيل عملية نقل واحدة
     */
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

    /**
     * تحويلات فرع من جدول inventory_transactions (transfer_in / transfer_out)
     */
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

    /**
     * تاريخ نقل منتج معين عبر الفروع
     */
    public function getTransferHistory(Request $request, Response $response, array $args): Response
    {
        $tenantId  = $this->extractTenantId($request);
        $productId = $args['id'];

        try {
            $stmt = $this->db->prepare("
                SELECT t.*,
                       w1.name AS from_branch_name,
                       w2.name AS to_branch_name,
                       u.name  AS created_by_name
                FROM stock_transfers t
                LEFT JOIN branches w1 ON t.from_branch = w1.id
                LEFT JOIN branches w2 ON t.to_branch   = w2.id
                LEFT JOIN users u     ON t.created_by  = u.id
                WHERE t.product_id = ? AND t.tenant_id = ?
                ORDER BY t.created_at DESC
            ");
            $stmt->execute([$productId, $tenantId]);
            return $this->successResponse($response, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], 200);
        } catch (\Throwable $e) {
            $this->logger->error('خطأ في جلب تاريخ النقل: ' . $e->getMessage());
            return $this->errorResponse($response, 'فشل في جلب تاريخ النقل', 500);
        }
    }
}
