<?php

declare(strict_types=1);

namespace App\Handlers;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Services\MonologHandler;
use App\Services\CostCenter\CostCenterService;

class OpeningBalanceHandler extends BaseHandler
{
    private CostCenterService $costCenterService;

    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->logger = MonologHandler::getInstance('opening_balance');
        $this->costCenterService = new CostCenterService($db, 'opening_balance');
    }

    private function findBranch(int $tenantId, int $branchId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id, name
            FROM branches
            WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL)
            LIMIT 1
        ");
        $stmt->execute([$branchId, $tenantId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function findBranchByCode(int $tenantId, string $code): ?array
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }

        $candidates = [
            "SELECT id, name FROM branches WHERE code = ? AND (tenant_id = ? OR tenant_id IS NULL) LIMIT 1",
            "SELECT id, name FROM branches WHERE branch_code = ? AND (tenant_id = ? OR tenant_id IS NULL) LIMIT 1",
            "SELECT id, name FROM branches WHERE short_code = ? AND (tenant_id = ? OR tenant_id IS NULL) LIMIT 1",
            "SELECT id, name FROM branches WHERE name = ? AND (tenant_id = ? OR tenant_id IS NULL) LIMIT 1",
        ];

        foreach ($candidates as $sql) {
            try {
                $stmt = $this->db->prepare($sql);
                $stmt->execute([$code, $tenantId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($row) {
                    return $row;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Error searching for branch by code', [
                    'code' => $code,
                    'message' => $e->getMessage()
                ]);
                continue;
            }
        }

        return null;
    }

    private function findProduct(int $tenantId, int $productId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id, name, purchase_price
            FROM products
            WHERE id = ? AND tenant_id = ?
            LIMIT 1
        ");
        $stmt->execute([$productId, $tenantId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function findProductByCodeOrBarcode(int $tenantId, ?string $productCode, ?string $barcode): ?array
    {
        $productCode = $productCode !== null ? trim($productCode) : '';
        $barcode = $barcode !== null ? trim($barcode) : '';

        if ($productCode === '' && $barcode === '') {
            return null;
        }

        if ($barcode !== '') {
            $stmt = $this->db->prepare("
                SELECT id, name, product_code, barcode, purchase_price
                FROM products
                WHERE barcode = ? AND tenant_id = ?
                LIMIT 1
            ");
            $stmt->execute([$barcode, $tenantId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                return $row;
            }
        }

        if ($productCode !== '') {
            $stmt = $this->db->prepare("
                SELECT id, name, product_code, barcode, purchase_price
                FROM products
                WHERE product_code = ? AND tenant_id = ?
                LIMIT 1
            ");
            $stmt->execute([$productCode, $tenantId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                return $row;
            }
        }

        return null;
    }

    private function findUnitByCode(int $tenantId, string $unitCode): ?array
    {
        $unitCode = trim($unitCode);
        if ($unitCode === '') {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT id, code, name
            FROM units
            WHERE code = ? AND (tenant_id = ? OR tenant_id IS NULL)
            LIMIT 1
        ");
        $stmt->execute([$unitCode, $tenantId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function resolveRowToIds(int $tenantId, array $row, int $line, array &$errors): array
    {
        $branchId = (int) ($row['branch_id'] ?? 0);
        $branchCode = isset($row['branch_code']) ? trim((string) $row['branch_code']) : '';

        $productId = (int) ($row['product_id'] ?? 0);
        $productCode = isset($row['product_code']) ? trim((string) $row['product_code']) : '';
        $barcode = isset($row['barcode']) ? trim((string) $row['barcode']) : '';

        $unitId = (int) ($row['unit_id'] ?? 0);
        $unitCode = isset($row['unit_code']) ? trim((string) $row['unit_code']) : '';

        if ($branchId <= 0 && $branchCode !== '') {
            if (ctype_digit($branchCode)) {
                $branchId = (int) $branchCode;
            } else {
                $branch = $this->findBranchByCode($tenantId, $branchCode);
                if ($branch) {
                    $branchId = (int) $branch['id'];
                } else {
                    $errors[] = "السطر {$line}: المخزن بالكود {$branchCode} غير موجود";
                }
            }
        }

        if ($productId <= 0 && ($productCode !== '' || $barcode !== '')) {
            $product = $this->findProductByCodeOrBarcode($tenantId, $productCode, $barcode);
            if ($product) {
                $productId = (int) $product['id'];
            } else {
                $ref = $barcode !== '' ? "الباركود {$barcode}" : "الكود {$productCode}";
                $errors[] = "السطر {$line}: المنتج بـ {$ref} غير موجود";
            }
        }

        if ($unitId <= 0 && $unitCode !== '') {
            if (ctype_digit($unitCode)) {
                $unitId = (int) $unitCode;
            } else {
                $unit = $this->findUnitByCode($tenantId, $unitCode);
                if ($unit) {
                    $unitId = (int) $unit['id'];
                } else {
                    $errors[] = "السطر {$line}: الوحدة بالكود {$unitCode} غير موجودة";
                }
            }
        }

        return [
            'branch_id' => $branchId,
            'product_id' => $productId,
            'unit_id' => $unitId > 0 ? $unitId : 1,
        ];
    }

    public function template(Request $request, Response $response): Response
    {
        $csv = "branch_id,product_id,unit_id,quantity,cost,notes\n" .
            "1,1001,1,10,25.50,Opening stock example\n\n" .
            "# Alternative by codes (preferred):\n" .
            "branch_code,product_code_or_barcode,unit_code,quantity,cost,notes\n" .
            "MAIN,SKU-001,PCS,10,25.50,Opening stock example\n";

        $response->getBody()->write($csv);

        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="opening_balance_template.csv"')
            ->withStatus(200);
    }

    public function preview(Request $request, Response $response): Response
    {
        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
        }

        $data = $request->getParsedBody() ?? [];
        $items = $data['items'] ?? [];

        if (!is_array($items) || count($items) === 0) {
            return $this->jsonResponse($response, [
                'status' => 'error',
                'message' => 'الرجاء إدخال عناصر الرصيد الافتتاحي',
                'field' => 'items'
            ], 400);
        }

        $tenantId = (int) $tenantId;
        $errors = [];
        $warnings = [];
        $normalized = [];
        $totalQty = 0.0;
        $totalCost = 0.0;

        foreach ($items as $idx => $row) {
            $line = $idx + 1;

            $resolved = $this->resolveRowToIds($tenantId, $row, $line, $errors);
            $branchId = (int) $resolved['branch_id'];
            $productId = (int) $resolved['product_id'];
            $unitId = (int) $resolved['unit_id'];

            $qty = (float) ($row['quantity'] ?? 0);
            $cost = (float) ($row['cost'] ?? 0);
            $notes = trim((string) ($row['notes'] ?? ''));

            if ($branchId <= 0) {
                $errors[] = "السطر {$line}: رقم المخزن غير صالح";
            }

            if ($productId <= 0) {
                $errors[] = "السطر {$line}: رقم المنتج غير صالح";
            }

            if ($qty <= 0) {
                $errors[] = "السطر {$line}: الكمية يجب أن تكون أكبر من صفر";
            }

            if ($cost < 0) {
                $errors[] = "السطر {$line}: التكلفة لا يمكن أن تكون سالبة";
            }

            if ($branchId > 0) {
                $branch = $this->findBranch($tenantId, $branchId);
                if (!$branch) {
                    $errors[] = "السطر {$line}: المخزن غير موجود";
                }
            }

            if ($productId > 0) {
                $product = $this->findProduct($tenantId, $productId);
                if (!$product) {
                    $errors[] = "السطر {$line}: المنتج غير موجود";
                } elseif ($cost == 0.0) {
                    $warnings[] = "السطر {$line}: التكلفة = 0؛ سيتم اعتبارها صفرية (قد يؤثر على COGS)";
                }
            }

            $subtotal = $qty * $cost;
            $totalQty += max(0, $qty);
            $totalCost += max(0, $subtotal);

            $normalized[] = [
                'branch_id' => $branchId,
                'product_id' => $productId,
                'unit_id' => $unitId,
                'quantity' => $qty,
                'cost' => $cost,
                'subtotal' => $subtotal,
                'notes' => $notes,
                'branch_code' => isset($row['branch_code']) ? (string) $row['branch_code'] : null,
                'product_code' => isset($row['product_code']) ? (string) $row['product_code'] : null,
                'barcode' => isset($row['barcode']) ? (string) $row['barcode'] : null,
                'unit_code' => isset($row['unit_code']) ? (string) $row['unit_code'] : null,
            ];
        }

        if (!empty($errors)) {
            return $this->jsonResponse($response, [
                'status' => 'error',
                'message' => 'فشل التحقق من البيانات',
                'errors' => $errors
            ], 400);
        }

        return $this->jsonResponse($response, [
            'status' => 'success',
            'data' => [
                'items' => $normalized,
                'summary' => [
                    'total_quantity' => round($totalQty, 4),
                    'total_cost' => round($totalCost, 2)
                ],
                'warnings' => $warnings
            ]
        ], 200);
    }

    public function commit(Request $request, Response $response): Response
    {
        $this->validateAuth();

        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
        }

        $tenantId = (int) $tenantId;
        $userId = $this->extractUserId($request) ?? null;
        $data = $request->getParsedBody() ?? [];
        $items = $data['items'] ?? [];

        if (empty($data['cost_center_id'])) {
            try {
                $data['cost_center_id'] = $this->costCenterService->resolve($tenantId, $userId, null);
            } catch (\Throwable $e) {
                $this->logger->error('Cost center resolution failed', [
                    'tenant_id' => $tenantId,
                    'error' => $e->getMessage()
                ]);

                return $this->errorResponse($response, 'فشل في تحديد مركز التكلفة', 400);
            }
        }

        $qp = $request->getQueryParams() ?? [];
        $setPurchasePriceIfZero = !empty($data['set_purchase_price_if_zero']) || !empty($qp['set_purchase_price_if_zero']);
        $postAccounting = array_key_exists('post_accounting', $data)
            ? (bool) $data['post_accounting']
            : (array_key_exists('post_accounting', $qp) ? (bool) $qp['post_accounting'] : true);

        if (!is_array($items) || count($items) === 0) {
            return $this->jsonResponse($response, [
                'status' => 'error',
                'message' => 'الرجاء إدخال عناصر الرصيد الافتتاحي',
                'field' => 'items'
            ], 400);
        }

        $errors = [];
        foreach ($items as $idx => &$row) {
            $line = $idx + 1;
            $resolved = $this->resolveRowToIds($tenantId, $row, $line, $errors);

            $branchId = (int) $resolved['branch_id'];
            $productId = (int) $resolved['product_id'];
            $qty = (float) ($row['quantity'] ?? 0);
            $cost = (float) ($row['cost'] ?? 0);

            if ($branchId <= 0 || !$this->findBranch($tenantId, $branchId)) {
                $errors[] = "السطر {$line}: المخزن غير موجود";
            }

            if ($productId <= 0 || !$this->findProduct($tenantId, $productId)) {
                $errors[] = "السطر {$line}: المنتج غير موجود";
            }

            if ($qty <= 0) {
                $errors[] = "السطر {$line}: الكمية يجب أن تكون أكبر من صفر";
            }

            if ($cost < 0) {
                $errors[] = "السطر {$line}: التكلفة لا يمكن أن تكون سالبة";
            }

            $row['branch_id'] = $branchId;
            $row['product_id'] = $productId;
            $row['unit_id'] = (int) ($resolved['unit_id'] ?? ($row['unit_id'] ?? 1));
        }
        unset($row);

        if (!empty($errors)) {
            return $this->jsonResponse($response, [
                'status' => 'error',
                'message' => 'فشل التحقق من البيانات',
                'errors' => $errors
            ], 400);
        }

        // ─── Guard: منع تكرار الرصيد الافتتاحي لنفس المنتج/الفرع ──────────────
        $duplicates = [];
        foreach ($items as $row) {
            $chkStmt = $this->db->prepare("
                SELECT id FROM product_branch_gl_mapping
                WHERE tenant_id = ? AND product_id = ? AND branch_id = ?
                  AND gl_reconciliation_status = 'RECONCILED'
                LIMIT 1
            ");
            $chkStmt->execute([$tenantId, (int)$row['product_id'], (int)$row['branch_id']]);
            if ($chkStmt->fetchColumn()) {
                $duplicates[] = "المنتج #{$row['product_id']} في الفرع #{$row['branch_id']} لديه رصيد افتتاحي مُرحَّل مسبقاً";
            }
        }
        if (!empty($duplicates)) {
            return $this->jsonResponse($response, [
                'status' => 'error',
                'message' => 'بعض المنتجات لديها رصيد افتتاحي مُرحَّل مسبقاً',
                'errors' => $duplicates
            ], 409);
        }

        $batchNote = 'Opening balance commit @ ' . date('Y-m-d H:i:s');

        $this->logger->info('Starting opening balance commit', [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'items_count' => count($items),
            'cost_center_id' => $data['cost_center_id'] ?? null,
            'post_accounting' => $postAccounting
        ]);

        $transactionActive = false;

        try {
            try {
                $this->db->setAttribute(PDO::ATTR_AUTOCOMMIT, 0);
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to set autocommit', [
                    'message' => $e->getMessage()
                ]);
            }

            if (!$this->db->inTransaction()) {
                $this->db->beginTransaction();
            }

            $transactionActive = true;
            $this->logger->debug('Transaction started');

            $insInv = $this->db->prepare("
                INSERT INTO inventory_transactions (
                    tenant_id, product_id, unit_id, branch_from, branch_to, quantity, unit_cost, total_cost,
                    movement_type, movement_date, batch_number, expiry_date, serial, reference_type, reference_id, notes, user_id, created_at
                ) VALUES (?, ?, ?, NULL, ?, ?, ?, ?, 'opening_balance_bulk', NOW(), ?, ?, ?, 'opening_balance_bulk', ?, ?, ?, NOW())
            ");

            $upsertWP = $this->db->prepare("
                INSERT INTO branch_products (tenant_id, branch_id, product_id, quantity, last_update, quantity_cost, gl_reconciled)
                VALUES (?, ?, ?, ?, NOW(), ?, 0)
                ON DUPLICATE KEY UPDATE
                    quantity = quantity + VALUES(quantity),
                    quantity_cost = quantity_cost + VALUES(quantity_cost),
                    gl_reconciled = 0,
                    last_update = NOW()
            ");

            $updPurchasePrice = $this->db->prepare("
                UPDATE products
                SET purchase_price = ?
                WHERE id = ? AND tenant_id = ? AND (purchase_price IS NULL OR purchase_price = 0)
            ");

            $totalQty = 0.0;
            $totalCost = 0.0;
            $rows = 0;

            $insOB = $this->db->prepare("
                INSERT INTO opening_balances (tenant_id, product_id, branch_id, quantity, unit_cost, entry_date)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");

            $upsertGLMapping = $this->db->prepare("
                INSERT INTO product_branch_gl_mapping
                (
                    tenant_id, product_id, branch_id, inventory_gl_account_id, purchase_gl_account_id,
                    cogs_gl_account_id, activation_status, activation_date, created_by_user_id
                )
                VALUES (?, ?, ?, ?, ?, ?, 'ACTIVE_IN_BRANCH', NOW(), ?)
                ON DUPLICATE KEY UPDATE
                    activation_status = 'ACTIVE_IN_BRANCH',
                    activation_date = NOW(),
                    updated_by_user_id = ?
            ");

            $inventoryGLId   = $this->getGLAccountByCode($tenantId, '1301');
            $purchaseGLId   = $this->getGLAccountByCode($tenantId, '5001');
            $cogsGLId       = $this->getGLAccountByCode($tenantId, '5103');
            $equityAccountId = $this->getGLAccountByCode($tenantId, '2001');

            // Pre-fetch per-branch GL accounts and names
            $branchGLMap   = [];
            $branchNameMap = [];
            $productNameMap = [];
            foreach ($items as $row) {
                $bid = (int) $row['branch_id'];
                $pid = (int) $row['product_id'];
                if (!isset($branchGLMap[$bid])) {
                    $bs = $this->db->prepare('SELECT name, account_id FROM branches WHERE id = ? AND tenant_id = ? LIMIT 1');
                    $bs->execute([$bid, $tenantId]);
                    $br = $bs->fetch(PDO::FETCH_ASSOC);
                    $branchGLMap[$bid]   = ($br && !empty($br['account_id'])) ? (int) $br['account_id'] : $inventoryGLId;
                    $branchNameMap[$bid] = $br['name'] ?? "فرع #{$bid}";
                }
                if (!isset($productNameMap[$pid])) {
                    $ps = $this->db->prepare('SELECT name FROM products WHERE id = ? AND tenant_id = ? LIMIT 1');
                    $ps->execute([$pid, $tenantId]);
                    $productNameMap[$pid] = $ps->fetchColumn() ?: "منتج #{$pid}";
                }
            }

            $jeId     = null;
            $warnings = [];

            foreach ($items as $row) {
                $branchId = (int) $row['branch_id'];
                $productId = (int) $row['product_id'];
                $unitId = (int) ($row['unit_id'] ?? 1);
                $qty = (float) $row['quantity'];
                $cost = (float) $row['cost'];
                $notes = trim((string) ($row['notes'] ?? ''));
                $lineTotalCost = $qty * $cost;
                $batchNumber = $row['batch_number'] ?? null;
                $expiryDate = $row['expiry_date'] ?? null;
                $serial = $row['serial'] ?? null;

                // ── GL mapping first → get mapping_id for reference_id ──────────
                $upsertGLMapping->execute([
                    $tenantId,
                    $productId,
                    $branchId,
                    $branchGLMap[$branchId] ?? $inventoryGLId,
                    $purchaseGLId,
                    $cogsGLId,
                    $userId,
                    $userId
                ]);

                $mstmt = $this->db->prepare('SELECT id FROM product_branch_gl_mapping WHERE tenant_id = ? AND product_id = ? AND branch_id = ? LIMIT 1');
                $mstmt->execute([$tenantId, $productId, $branchId]);
                $mappingId = (int) $mstmt->fetchColumn();

                $insInv->execute([
                    $tenantId,
                    $productId,
                    $unitId,
                    $branchId,
                    $qty,
                    $cost,
                    $lineTotalCost,
                    $batchNumber,
                    $expiryDate,
                    $serial,
                    $mappingId,
                    $notes !== '' ? $notes : $batchNote,
                    $userId
                ]);

                $upsertWP->execute([$tenantId, $branchId, $productId, $qty, $lineTotalCost]);

                if ($setPurchasePriceIfZero && $cost > 0) {
                    $updPurchasePrice->execute([$cost, $productId, $tenantId]);
                }

                // ── Per-branch Journal Entry ──────────────────────────────
                if ($postAccounting && !empty($equityAccountId)) {
                    $branchInvGLId = $branchGLMap[$branchId]  ?? $inventoryGLId;
                    $productName   = $productNameMap[$productId] ?? "منتج #{$productId}";
                    $branchName    = $branchNameMap[$branchId]   ?? "فرع #{$branchId}";

                    try {
                        $jeIdItem = $this->accounting->postJournalEntry(
                            $tenantId,
                            'opening_balance_bulk',
                            $mappingId,
                            "رصيد افتتاحي: {$productName} - {$branchName}",
                            [
                                [
                                    'account_id'  => $branchInvGLId,
                                    'debit'       => $lineTotalCost,
                                    'credit'      => 0,
                                    'description' => "مخزون - {$productName}",
                                ],
                                [
                                    'account_id'  => $equityAccountId,
                                    'debit'       => 0,
                                    'credit'      => $lineTotalCost,
                                    'description' => 'مقابل رصيد افتتاحي',
                                ],
                            ],
                            date('Y-m-d'),
                            $userId ? (int) $userId : 1,
                            null,
                            "ob_bulk:t{$tenantId}:p{$productId}:b{$branchId}"
                        );

                        if ($jeIdItem) {
                            $jeId = $jeIdItem;

                            $this->db->prepare("
                                UPDATE inventory_transactions
                                SET journal_entry_id = ?
                                WHERE tenant_id = ? AND reference_id = ?
                                  AND product_id = ? AND branch_to = ?
                                  AND journal_entry_id IS NULL
                                ORDER BY id DESC LIMIT 1
                            ")->execute([$jeIdItem, $tenantId, $mappingId, $productId, $branchId]);

                            $this->db->prepare("
                                UPDATE product_branch_gl_mapping
                                SET gl_reconciliation_status = 'RECONCILED',
                                    last_gl_posting_date     = NOW(),
                                    average_cost             = ?,
                                    gl_balance               = gl_balance + ?,
                                    updated_at               = NOW()
                                WHERE tenant_id = ? AND product_id = ? AND branch_id = ?
                            ")->execute([$cost, $lineTotalCost, $tenantId, $productId, $branchId]);

                            $this->db->prepare("
                                UPDATE branch_products
                                SET gl_reconciled        = 1,
                                    last_gl_posting_date = NOW()
                                WHERE tenant_id = ? AND branch_id = ? AND product_id = ?
                            ")->execute([$tenantId, $branchId, $productId]);

                            $this->db->prepare("
                                INSERT IGNORE INTO inventory_cost_snapshot
                                    (tenant_id, product_id, branch_id, product_branch_mapping_id,
                                     layer_date, layer_sequence, unit_cost,
                                     quantity_received, quantity_remaining, source_type)
                                SELECT ?, ?, ?, pbm.id, NOW(), 1, ?, ?, ?, 'OPENING_BALANCE'
                                FROM product_branch_gl_mapping pbm
                                WHERE pbm.tenant_id = ? AND pbm.product_id = ? AND pbm.branch_id = ?
                                LIMIT 1
                            ")->execute([
                                $tenantId, $productId, $branchId,
                                $cost, $qty, $qty,
                                $tenantId, $productId, $branchId,
                            ]);
                        } else {
                            $warnings['journal_entry_skipped'] = true;
                            $warnings['reason']  = 'missing_accounts_or_zero_total';
                            $warnings['message'] = 'تم تخطي القيد المحاسبي لأحد الأصناف.';
                        }
                    } catch (\Throwable $e) {
                        $this->logger->error('Error creating per-branch JE', [
                            'product_id' => $productId,
                            'branch_id'  => $branchId,
                            'message'    => $e->getMessage(),
                        ]);
                        $warnings['journal_entry_error'] = $e->getMessage();
                    }
                }

                $total = $qty * $cost;

                $insOB->execute([$tenantId, $productId, $branchId, $qty, $cost]);

                $totalQty += $qty;
                $totalCost += $total;
                $rows++;
            }

            // Per-branch JEs are created inside the items loop above

            // ── Mark all posted products with opening_balance_posted = 1 ────────────
            // This ensures ProductListResource correctly calculates gl_status = 'posted'
            // (see ProductListResource::transform line 43-44)
            $productIds = array_unique(array_map(fn ($row) => (int)$row['product_id'], $items));
            if (!empty($productIds)) {
                $placeholders = implode(',', array_fill(0, count($productIds), '?'));
                $this->db->prepare("
                    UPDATE products
                    SET opening_balance_posted = 1,
                        updated_at             = NOW()
                    WHERE tenant_id = ? AND id IN ({$placeholders})
                ")->execute(array_merge([$tenantId], $productIds));
            }

            if ($transactionActive) {
                $this->db->commit();
                $transactionActive = false;
            }

            return $this->jsonResponse($response, [
                'status' => 'success',
                'message' => 'تم ترحيل الرصيد الافتتاحي بنجاح',
                'data' => [
                    'rows' => $rows,
                    'total_quantity' => round($totalQty, 4),
                    'total_cost' => round($totalCost, 2),
                    'journal_entry_id' => $jeId
                ],
                'warnings' => array_merge($warnings, $jeId === null ? ['journal_entry_skipped' => true] : [])
            ], 201);
        } catch (\Throwable $e) {
            if ($transactionActive) {
                try {
                    $this->db->rollBack();
                    $transactionActive = false;
                } catch (\Throwable $rollbackError) {
                    $this->logger->error('Error rolling back transaction', [
                        'message' => $rollbackError->getMessage()
                    ]);
                }
            }

            $this->logger->error('Opening balance commit failed', [
                'tenant_id' => $tenantId,
                'message' => $e->getMessage()
            ]);

            return $this->jsonResponse($response, [
                'status' => 'error',
                'message' => 'فشل ترحيل الرصيد الافتتاحي: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get GL account ID by code — delegates to AccountingService (single source of truth)
     */
    private function getGLAccountByCode(int $tenantId, string $code): ?int
    {
        return $this->accounting->getAccountByCode($tenantId, $code);
    }
}
