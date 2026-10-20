<?php

declare(strict_types=1);

namespace App\Handlers;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Services\MonologHandler;
use App\Services\CostCenter\CostCenterService;

/**
 * Previews and commits tenant opening inventory balances.
 *
 * Uses PDO for product/branch resolution and inventory persistence, CostCenterService
 * for required context, and AccountingService for GL account resolution and posting.
 * The opening-balance routes gate template/preview and commit with the accounting
 * opening-balance permission.
 */
class OpeningBalanceHandler extends BaseHandler
{
    private CostCenterService $costCenterService;

    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->logger = MonologHandler::getInstance('opening_balance');
        $this->costCenterService = new CostCenterService($db, 'opening_balance');
    }

    /** Find a tenant or shared branch by ID. */
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

    /** Resolve a branch code against supported schema aliases, then its name. */
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

    /** Find a product by ID within the tenant. */
    private function findProduct(int $tenantId, int $productId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id, name, purchase_price, product_type
            FROM products
            WHERE id = ? AND tenant_id = ?
            LIMIT 1
        ");
        $stmt->execute([$productId, $tenantId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Resolve a tenant product by barcode first, then product code. */
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

    /** Resolve a tenant or shared unit by code. */
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

    /** Normalize one submitted row to IDs and append lookup failures to the error list. */
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

    /** Validate and normalize proposed opening balances without writing data. */
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
                'message' => 'يُرجى إدخال أصناف الرصيد الافتتاحي',
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
                $errors[] = "السطر {$line}: يجب أن تكون الكمية أكبر من صفر.";
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
                } elseif (strtolower((string) ($product['product_type'] ?? 'stock')) !== 'stock') {
                    $errors[] = "السطر {$line}: المنتج من نوع خدمة، ولا يُسمح بتسجيل رصيد مخزني له.";
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

    /**
     * Commit opening stock and, when requested, post its branch inventory GL entries.
     *
     * Existing branch GL mappings are reused as metadata but are not created or activated.
     */
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
                'message' => 'يُرجى إدخال أصناف الرصيد الافتتاحي',
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

            $product = $productId > 0 ? $this->findProduct($tenantId, $productId) : null;
            if (!$product) {
                $errors[] = "السطر {$line}: المنتج غير موجود";
            } elseif (strtolower((string) ($product['product_type'] ?? 'stock')) !== 'stock') {
                $errors[] = "السطر {$line}: المنتج من نوع خدمة ولا يقبل رصيدًا مخزنيًا";
            }

            if ($qty <= 0) {
                $errors[] = "السطر {$line}: يجب أن تكون الكمية أكبر من صفر.";
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

            // Lock each product in deterministic order, then check prior openings independently of GL mappings.
            $productIdsToLock = array_values(array_unique(array_map(
                static fn (array $row): int => (int) $row['product_id'],
                $items
            )));
            sort($productIdsToLock, SORT_NUMERIC);
            $productLock = $this->db->prepare(
                'SELECT id, product_type FROM products WHERE tenant_id = ? AND id = ? LIMIT 1 FOR UPDATE'
            );
            foreach ($productIdsToLock as $productIdToLock) {
                $productLock->execute([$tenantId, $productIdToLock]);
                $lockedProduct = $productLock->fetch(PDO::FETCH_ASSOC);
                if (!$lockedProduct) {
                    throw new \RuntimeException("المنتج #{$productIdToLock} غير موجود.");
                }
                if (strtolower((string) ($lockedProduct['product_type'] ?? 'stock')) !== 'stock') {
                    throw new \InvalidArgumentException(
                        "المنتج #{$productIdToLock} من نوع خدمة ولا يقبل رصيدًا مخزنيًا."
                    );
                }
            }

            $openingBalanceCheck = $this->db->prepare(
                'SELECT 1 FROM opening_balances
                 WHERE tenant_id = ? AND product_id = ? AND branch_id = ?
                   AND reversed_at IS NULL
                 LIMIT 1 FOR UPDATE'
            );
            $duplicateOpenings = [];
            $checkedPairs = [];
            foreach ($items as $row) {
                $productId = (int) $row['product_id'];
                $branchId = (int) $row['branch_id'];
                $pairKey = $productId . ':' . $branchId;
                if (isset($checkedPairs[$pairKey])) {
                    continue;
                }
                $checkedPairs[$pairKey] = true;
                $openingBalanceCheck->execute([$tenantId, $productId, $branchId]);
                if ($openingBalanceCheck->fetchColumn()) {
                    $duplicateOpenings[] = "المنتج #{$productId} في الفرع #{$branchId}: يوجد رصيد افتتاحي مسجل مسبقًا.";
                }
            }
            if ($duplicateOpenings !== []) {
                $this->db->rollBack();
                $transactionActive = false;
                return $this->jsonResponse($response, [
                    'status' => 'error',
                    'message' => 'يوجد رصيد افتتاحي مسجل مسبقًا لبعض المنتجات',
                    'errors' => $duplicateOpenings,
                ], 409);
            }

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
                    quantity = VALUES(quantity),
                    quantity_cost = VALUES(quantity_cost),
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

            $equityAccountId = $this->getGLAccountByCode($tenantId, '2001');

            // Pre-fetch per-branch GL accounts and names
            $branchGLMap   = [];
            $branchNameMap = [];
            $productNameMap = [];
            foreach ($items as $row) {
                $bid = (int) $row['branch_id'];
                $pid = (int) $row['product_id'];
                if (!isset($branchGLMap[$bid])) {
                    $bs = $this->db->prepare('SELECT name FROM branches WHERE id = ? AND tenant_id = ? LIMIT 1');
                    $bs->execute([$bid, $tenantId]);
                    $br = $bs->fetch(PDO::FETCH_ASSOC);
                    $branchGLMap[$bid] = $this->accounting->resolveInventoryAccountForBranch($tenantId, $bid);
                    $branchNameMap[$bid] = $br['name'] ?? "فرع #{$bid}";
                }
                if (!isset($productNameMap[$pid])) {
                    $ps = $this->db->prepare('SELECT name FROM products WHERE id = ? AND tenant_id = ? LIMIT 1');
                    $ps->execute([$pid, $tenantId]);
                    $productNameMap[$pid] = $ps->fetchColumn() ?: "منتج #{$pid}";
                }
            }

            if ($postAccounting) {
                $branchesWithValue = [];
                foreach ($items as $row) {
                    if ((float) $row['quantity'] * (float) $row['cost'] > 0) {
                        $branchesWithValue[(int) $row['branch_id']] = true;
                    }
                }
                if ($branchesWithValue !== [] && !$equityAccountId) {
                    throw new \RuntimeException('حساب حقوق الأرصدة الافتتاحية (2001) غير مُهيأ.');
                }
                foreach (array_keys($branchesWithValue) as $branchIdWithValue) {
                    if (empty($branchGLMap[$branchIdWithValue])) {
                        throw new \RuntimeException(
                            "حساب المخزون غير مُهيأ للفرع #{$branchIdWithValue}؛ لم يتم تسجيل أي كمية في المخزون."
                        );
                    }
                }
            }

            $jeId     = null;
            $warnings = [];
            $postedProductIds = [];

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

                // Mapping is optional metadata: reuse an explicit mapping, but never create or activate one here.
                $mstmt = $this->db->prepare(
                    'SELECT id FROM product_branch_gl_mapping
                     WHERE tenant_id = ? AND product_id = ? AND branch_id = ? LIMIT 1 FOR UPDATE'
                );
                $mstmt->execute([$tenantId, $productId, $branchId]);
                $mappingValue = $mstmt->fetchColumn();
                $mappingId = ($mappingValue === false || $mappingValue === null) ? null : (int) $mappingValue;

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
                $inventoryTransactionId = (int) $this->db->lastInsertId();

                $upsertWP->execute([$tenantId, $branchId, $productId, $qty, $lineTotalCost]);

                if ($setPurchasePriceIfZero && $cost > 0) {
                    $updPurchasePrice->execute([$cost, $productId, $tenantId]);
                }

                // ── Per-branch Journal Entry ──────────────────────────────
                $branchInvGLId = $branchGLMap[$branchId] ?? null;
                if ($postAccounting && $lineTotalCost > 0) {
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
                            $this->getBulkOpeningBalanceIdempotencyKey(
                                (int) $tenantId,
                                $productId,
                                $branchId
                            )
                        );

                        if ($jeIdItem) {
                            $jeId = $jeIdItem;
                            $postedProductIds[$productId] = true;

                            $this->db->prepare(
                                'UPDATE inventory_transactions SET journal_entry_id = ? WHERE id = ? AND tenant_id = ?'
                            )->execute([$jeIdItem, $inventoryTransactionId, $tenantId]);

                            if ($mappingId !== null) {
                                $this->db->prepare("
                                    UPDATE product_branch_gl_mapping
                                    SET gl_reconciliation_status = 'RECONCILED',
                                        last_gl_posting_date     = NOW(),
                                        average_cost             = ?,
                                        gl_balance               = gl_balance + ?,
                                        updated_at               = NOW()
                                    WHERE id = ? AND tenant_id = ? AND product_id = ? AND branch_id = ?
                                ")->execute([$cost, $lineTotalCost, $mappingId, $tenantId, $productId, $branchId]);
                            }

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
                                VALUES (?, ?, ?, ?, NOW(), 1, ?, ?, ?, 'OPENING_BALANCE')
                            ")->execute([
                                $tenantId, $productId, $branchId, $mappingId,
                                $cost, $qty, $qty,
                            ]);
                        } else {
                            throw new \RuntimeException(
                                "فشل ترحيل قيد الرصيد الافتتاحي للمنتج #{$productId} في الفرع #{$branchId}."
                            );
                        }
                    } catch (\Throwable $e) {
                        $this->logger->error('Error creating per-branch JE', [
                            'product_id' => $productId,
                            'branch_id'  => $branchId,
                            'message'    => $e->getMessage(),
                        ]);
                        throw $e;
                    }
                } elseif (!$postAccounting && $lineTotalCost > 0) {
                    $warnings['journal_entry_skipped'] = true;
                    $warnings['reason'] = 'accounting_disabled';
                    $warnings['message'] = 'تم حفظ الكمية دون ترحيل القيد المحاسبي، بناءً على الطلب.';
                } elseif ($lineTotalCost <= 0) {
                    $warnings['journal_entry_skipped'] = true;
                    $warnings['reason'] = 'zero_total_cost';
                    $warnings['message'] = 'تم حفظ الكمية بتكلفة صفرية دون إنشاء قيد محاسبي.';
                }

                $total = $qty * $cost;

                $insOB->execute([$tenantId, $productId, $branchId, $qty, $cost]);
                $openingBalanceId = (int) $this->db->lastInsertId();
                $this->db->prepare("
                    UPDATE opening_balances
                    SET inventory_transaction_id = ?
                    WHERE id = ? AND tenant_id = ?
                ")->execute([$inventoryTransactionId, $openingBalanceId, $tenantId]);

                $totalQty += $qty;
                $totalCost += $total;
                $rows++;
            }

            // Per-branch JEs are created inside the items loop above

            // ── Mark all posted products with opening_balance_posted = 1 ────────────
            // This ensures ProductListResource correctly calculates gl_status = 'posted'
            // (see ProductListResource::transform line 43-44)
            $productIds = array_keys($postedProductIds);
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

    /** Resolve an account by code through AccountingService. */
    private function getGLAccountByCode(int $tenantId, string $code): ?int
    {
        return $this->accounting->getAccountByCode($tenantId, $code);
    }

    /** Include the reversal cycle in the per-tenant/product/branch posting key. */
    private function getBulkOpeningBalanceIdempotencyKey(int $tenantId, int $productId, int $branchId): string
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM opening_balances
             WHERE tenant_id = ? AND product_id = ? AND branch_id = ?'
        );
        $stmt->execute([$tenantId, $productId, $branchId]);
        $reversalCycle = (int) $stmt->fetchColumn();

        $key = "ob_bulk:t{$tenantId}:p{$productId}:b{$branchId}";
        return $reversalCycle > 0 ? "{$key}:r{$reversalCycle}" : $key;
    }
}
