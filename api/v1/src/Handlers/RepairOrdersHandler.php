<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Services\MonologHandler;
use App\Repositories\SettingsRepository;
use App\Utils\SuperAdminHelper;
use App\Utils\PaginationHelper;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

final class RepairOrdersHandler extends BaseHandler
{
    private const STATUSES = [
        'received',
        'diagnosing',
        'waiting_approval',
        'waiting_parts',
        'in_repair',
        'ready',
        'delivered',
        'cancelled',
    ];

    private const TRANSITIONS = [
        'received' => ['diagnosing', 'cancelled'],
        'diagnosing' => ['waiting_approval', 'waiting_parts', 'in_repair', 'cancelled'],
        'waiting_approval' => ['waiting_parts', 'in_repair', 'cancelled'],
        'waiting_parts' => ['in_repair', 'cancelled'],
        'in_repair' => ['waiting_parts', 'ready', 'cancelled'],
        'ready' => ['in_repair', 'delivered', 'cancelled'],
        'delivered' => [],
        'cancelled' => [],
    ];

    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->logger = MonologHandler::getInstance('repair_orders');
    }

    public function list(Request $request, Response $response): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر.', 403);
            }
            $branchId = $this->resolveBranchId($request, $tenantId);
            $query = $request->getQueryParams();

            // ── Pagination ──────────────────────────────────────────────
            [$page, $limit, $offset] = PaginationHelper::fromArray($query, 20, 200);

            // ── Filters ──────────────────────────────────────────────────
            $status = trim((string) ($query['status'] ?? ''));
            if ($status !== '' && !in_array($status, self::STATUSES, true)) {
                return $this->errorResponse($response, 'حالة أمر الصيانة غير صالحة.', 400);
            }
            $search    = trim((string) ($query['q']         ?? ''));
            $fromDate  = trim((string) ($query['from_date'] ?? ''));
            $toDate    = trim((string) ($query['to_date']   ?? ''));

            // ── Build WHERE clause (shared between count + data queries) ─
            $where  = ' WHERE ro.tenant_id = ?';
            $params = [$tenantId];

            if ($branchId !== null) {
                $where    .= ' AND ro.branch_id = ?';
                $params[]  = $branchId;
            }
            if ($status !== '') {
                $where    .= ' AND ro.status = ?';
                $params[]  = $status;
            }
            if ($search !== '') {
                $where .= " AND (ro.order_number LIKE ? OR ro.serial_number LIKE ? OR ro.model LIKE ?
                            OR c.name LIKE ? OR c.phone LIKE ?)";
                $like = '%' . $search . '%';
                array_push($params, $like, $like, $like, $like, $like);
            }
            if ($fromDate !== '') {
                $where    .= ' AND DATE(ro.received_at) >= ?';
                $params[]  = $fromDate;
            }
            if ($toDate !== '') {
                $where    .= ' AND DATE(ro.received_at) <= ?';
                $params[]  = $toDate;
            }

            $joins = "FROM repair_orders ro
                      JOIN customers c ON c.id = ro.customer_id AND c.tenant_id = ro.tenant_id
                      LEFT JOIN sales s ON s.id = ro.invoice_id AND s.tenant_id = ro.tenant_id";

            // ── Total count ───────────────────────────────────────────────
            $countStmt = $this->db->prepare("SELECT COUNT(*) {$joins}{$where}");
            $countStmt->execute($params);
            $total = (int) $countStmt->fetchColumn();

            // ── Data query ────────────────────────────────────────────────
            $dataParams   = $params;
            $dataParams[] = $limit;
            $dataParams[] = $offset;

            $dataStmt = $this->db->prepare(
                "SELECT ro.*, c.name AS customer_name, c.phone AS customer_phone, s.invoice_number
                 {$joins}{$where}
                 ORDER BY ro.id DESC
                 LIMIT ? OFFSET ?"
            );
            $dataStmt->execute($dataParams);
            $items = $dataStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            return $this->successResponse($response, [
                'items'       => $items,
                'total'       => $total,
                'page'        => $page,
                'limit'       => $limit,
                'total_pages' => (int) ceil($total / $limit),
            ]);
        } catch (\DomainException $e) {
            return $this->errorResponse($response, $e->getMessage(), (int) $e->getCode() ?: 400);
        } catch (Throwable $e) {
            $this->logger->error('Failed to list repair orders', ['error' => $e->getMessage()]);
            return $this->errorResponse($response, 'فشل في تحميل أوامر الصيانة.', 500);
        }
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر.', 403);
            }
            $branchId = $this->resolveBranchId($request, $tenantId);
            $order = $this->findOrder((int) ($args['id'] ?? 0), $tenantId, $branchId);
            if (!$order) {
                return $this->errorResponse($response, 'أمر الصيانة غير موجود.', 404);
            }
            $parts = $this->db->prepare(
                'SELECT rp.*, p.name AS product_name, u.name AS unit_name
                 FROM repair_order_parts rp
                 JOIN products p ON p.id = rp.product_id AND p.tenant_id = rp.tenant_id
                 JOIN units u ON u.id = rp.unit_id
                 WHERE rp.repair_order_id = ? AND rp.tenant_id = ? ORDER BY rp.id'
            );
            $parts->execute([$order['id'], $tenantId]);
            $order['parts'] = $parts->fetchAll(PDO::FETCH_ASSOC) ?: [];
            return $this->successResponse($response, $order);
        } catch (\DomainException $e) {
            return $this->errorResponse($response, $e->getMessage(), (int) $e->getCode() ?: 400);
        } catch (Throwable $e) {
            $this->logger->error('Failed to load repair order', ['error' => $e->getMessage()]);
            return $this->errorResponse($response, 'فشل في تحميل أمر الصيانة.', 500);
        }
    }

    public function create(Request $request, Response $response): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر.', 403);
            }
            $data = $request->getParsedBody();
            if (!is_array($data)) {
                return $this->errorResponse($response, 'بيانات الطلب غير صالحة.', 400);
            }

            $branchId = $this->resolveBranchId($request, $tenantId, $data['branch_id'] ?? null);
            $customerId = (int) ($data['customer_id'] ?? 0);
            $deviceType = trim((string) ($data['device_type'] ?? ''));
            $issue = trim((string) ($data['issue_description'] ?? ''));
            if (!$branchId || $customerId <= 0 || $deviceType === '' || $issue === '') {
                return $this->errorResponse($response, 'الفرع والعميل ونوع الجهاز ووصف العطل مطلوبة.', 400);
            }
            $customer = $this->db->prepare('SELECT id FROM customers WHERE id = ? AND tenant_id = ? AND active = 1');
            $customer->execute([$customerId, $tenantId]);
            if (!$customer->fetchColumn()) {
                return $this->errorResponse($response, 'العميل غير موجود أو غير نشط.', 422);
            }
            if (!$this->branchExists($branchId, $tenantId)) {
                return $this->errorResponse($response, 'الفرع المحدد غير متاح لهذا المستأجر.', 403);
            }
            $estimatedCost = $this->nonNegativeAmount($data['estimated_cost'] ?? 0, 'التكلفة التقديرية');
            if ($estimatedCost === null) {
                return $this->errorResponse($response, 'التكلفة التقديرية غير صالحة.', 400);
            }
            $expectedDate = $this->nullableDate($data['expected_completion_date'] ?? null);
            if (($data['expected_completion_date'] ?? '') !== '' && $expectedDate === null) {
                return $this->errorResponse($response, 'تاريخ الإنجاز المتوقع غير صالح.', 400);
            }

            $this->db->beginTransaction();
            $stmt = $this->db->prepare(
                "INSERT INTO repair_orders
                 (tenant_id, branch_id, customer_id, device_type, brand, model, serial_number,
                  accessories, issue_description, condition_received, estimated_cost,
                  expected_completion_date, status, received_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'received', ?)"
            );
            $stmt->execute([
                $tenantId,
                $branchId,
                $customerId,
                $deviceType,
                $this->nullableText($data['brand'] ?? null),
                $this->nullableText($data['model'] ?? null),
                $this->nullableText($data['serial_number'] ?? null),
                $this->nullableText($data['accessories'] ?? null),
                $issue,
                $this->nullableText($data['condition_received'] ?? null),
                $estimatedCost,
                $expectedDate,
                $this->extractUserId($request),
            ]);
            $id = (int) $this->db->lastInsertId();
            $number = 'REP-' . date('ym') . '-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
            $this->db->prepare('UPDATE repair_orders SET order_number = ? WHERE id = ? AND tenant_id = ?')
                ->execute([$number, $id, $tenantId]);
            $this->db->commit();
            return $this->successResponse($response, ['id' => $id, 'order_number' => $number], 201);
        } catch (\DomainException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return $this->errorResponse($response, $e->getMessage(), (int) $e->getCode() ?: 400);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logger->error('Failed to create repair order', ['error' => $e->getMessage()]);
            return $this->errorResponse($response, 'فشل في تسجيل استلام الجهاز.', 500);
        }
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر.', 403);
            }
            $data = $request->getParsedBody();
            if (!is_array($data)) {
                return $this->errorResponse($response, 'بيانات الطلب غير صالحة.', 400);
            }
            $branchId = $this->resolveBranchId($request, $tenantId);
            $order = $this->findOrder((int) ($args['id'] ?? 0), $tenantId, $branchId);
            if (!$order) {
                return $this->errorResponse($response, 'أمر الصيانة غير موجود.', 404);
            }
            if (in_array($order['status'], ['delivered', 'cancelled'], true)) {
                return $this->errorResponse($response, 'لا يمكن تعديل أمر صيانة مغلق.', 409);
            }
            if (!empty($order['invoice_id']) && isset($data['parts'])) {
                return $this->errorResponse($response, 'لا يمكن تعديل قطع الغيار بعد إصدار الفاتورة.', 409);
            }
            if (!empty($order['invoice_id'])
                && (array_key_exists('labor_amount', $data) || array_key_exists('labor_product_id', $data))) {
                return $this->errorResponse($response, 'لا يمكن تعديل أجرة الصيانة بعد إصدار الفاتورة.', 409);
            }

            $sets = [];
            $params = [];
            foreach (['diagnosis', 'resolution', 'notes'] as $field) {
                if (array_key_exists($field, $data)) {
                    $sets[] = "{$field} = ?";
                    $params[] = $this->nullableText($data[$field]);
                }
            }
            if (array_key_exists('labor_amount', $data)) {
                $labor = $this->nonNegativeAmount($data['labor_amount'], 'أجرة الصيانة');
                if ($labor === null) {
                    return $this->errorResponse($response, 'أجرة الصيانة غير صالحة.', 400);
                }
                $sets[] = 'labor_amount = ?';
                $params[] = $labor;
            }
            if (array_key_exists('labor_product_id', $data)) {
                $laborProductId = $data['labor_product_id'] !== null && $data['labor_product_id'] !== ''
                    ? (int) $data['labor_product_id']
                    : null;
                if ($laborProductId !== null && !$this->isServiceProduct($laborProductId, $tenantId)) {
                    return $this->errorResponse($response, 'يجب اختيار منتج مصنف كخدمة لأجرة الصيانة.', 422);
                }
                $sets[] = 'labor_product_id = ?';
                $params[] = $laborProductId;
            }
            if (array_key_exists('expected_completion_date', $data)) {
                $expected = $this->nullableDate($data['expected_completion_date']);
                if ($data['expected_completion_date'] !== '' && $expected === null) {
                    return $this->errorResponse($response, 'تاريخ الإنجاز المتوقع غير صالح.', 400);
                }
                $sets[] = 'expected_completion_date = ?';
                $params[] = $expected;
            }

            $nextStatus = isset($data['status']) ? (string) $data['status'] : (string) $order['status'];
            if (!in_array($nextStatus, self::STATUSES, true)) {
                return $this->errorResponse($response, 'حالة أمر الصيانة غير صالحة.', 400);
            }
            if ($nextStatus !== $order['status']
                && !in_array($nextStatus, self::TRANSITIONS[$order['status']] ?? [], true)) {
                return $this->errorResponse($response, 'انتقال الحالة المطلوب غير مسموح.', 409);
            }
            if ($nextStatus === 'ready' && trim((string) ($data['resolution'] ?? $order['resolution'] ?? '')) === '') {
                return $this->errorResponse($response, 'سجل وصف الإصلاح قبل نقل الجهاز إلى جاهز للتسليم.', 422);
            }
            if ($nextStatus === 'delivered' && empty($order['invoice_id'])) {
                return $this->errorResponse($response, 'يجب إصدار فاتورة أمر الصيانة قبل التسليم.', 409);
            }
            if ($nextStatus !== $order['status']) {
                $sets[] = 'status = ?';
                $params[] = $nextStatus;
                if ($nextStatus === 'delivered') {
                    $sets[] = 'delivered_by = ?';
                    $params[] = $this->extractUserId($request);
                    $sets[] = 'delivered_at = CURRENT_TIMESTAMP';
                }
            }

            $partsProvided = array_key_exists('parts', $data);
            if ($partsProvided && !is_array($data['parts'])) {
                return $this->errorResponse($response, 'قائمة قطع الغيار غير صالحة.', 400);
            }
            $this->db->beginTransaction();
            if ($sets !== []) {
                $params[] = $order['id'];
                $params[] = $tenantId;
                $this->db->prepare(
                    'UPDATE repair_orders SET ' . implode(', ', $sets) . ', updated_at = CURRENT_TIMESTAMP WHERE id = ? AND tenant_id = ?'
                )->execute($params);
            }
            if ($partsProvided) {
                $this->saveParts((int) $order['id'], $tenantId, $data['parts']);
            }
            $this->db->commit();
            return $this->successResponse($response, ['id' => (int) $order['id'], 'status' => $nextStatus]);
        } catch (\DomainException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return $this->errorResponse($response, $e->getMessage(), (int) $e->getCode() ?: 400);
        } catch (\InvalidArgumentException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return $this->errorResponse($response, $e->getMessage(), 422);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logger->error('Failed to update repair order', ['error' => $e->getMessage()]);
            return $this->errorResponse($response, 'فشل في تحديث أمر الصيانة.', 500);
        }
    }

    public function createInvoice(Request $request, Response $response, array $args): Response
    {
        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر.', 403);
        }
        $userId = $this->extractUserId($request);
        $lockName = 'repair_invoice_' . substr(hash('sha256', $tenantId . ':' . (int) ($args['id'] ?? 0)), 0, 40);
        $lockAcquired = false;
        try {
            if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $lock = $this->db->prepare('SELECT GET_LOCK(?, 10)');
                $lock->execute([$lockName]);
                if ((int) $lock->fetchColumn() !== 1) {
                    return $this->errorResponse($response, 'تعذر حجز أمر الصيانة لإصدار الفاتورة؛ حاول مرة أخرى.', 409);
                }
                $lockAcquired = true;
            }
            $branchId = $this->resolveBranchId($request, $tenantId);
            $order = $this->findOrder((int) ($args['id'] ?? 0), $tenantId, $branchId);
            if (!$order) {
                return $this->errorResponse($response, 'أمر الصيانة غير موجود.', 404);
            }
            if (!empty($order['invoice_id'])) {
                return $this->successResponse($response, ['sale_id' => (int) $order['invoice_id'], 'already_created' => true]);
            }
            if ($order['status'] !== 'ready') {
                return $this->errorResponse($response, 'يجب نقل أمر الصيانة إلى حالة جاهز قبل إصدار الفاتورة.', 409);
            }
            $invoiceMarker = 'صيانة: ' . $order['order_number'];
            $recovery = $this->db->prepare(
                'SELECT id, invoice_number FROM sales
                 WHERE tenant_id = ? AND customer_id = ? AND branch_id = ? AND notes = ?
                 ORDER BY id DESC LIMIT 1'
            );
            $recovery->execute([
                $tenantId,
                $order['customer_id'],
                $order['branch_id'],
                $invoiceMarker,
            ]);
            $existingSale = $recovery->fetch(PDO::FETCH_ASSOC);
            if ($existingSale) {
                $this->db->prepare(
                    'UPDATE repair_orders SET invoice_id = ?, updated_at = CURRENT_TIMESTAMP
                     WHERE id = ? AND tenant_id = ? AND invoice_id IS NULL'
                )->execute([$existingSale['id'], $order['id'], $tenantId]);
                return $this->successResponse($response, [
                    'sale_id' => (int) $existingSale['id'],
                    'invoice_number' => $existingSale['invoice_number'],
                    'recovered' => true,
                ]);
            }
            $itemsStmt = $this->db->prepare(
                'SELECT product_id, unit_id, quantity, sale_price FROM repair_order_parts
                 WHERE repair_order_id = ? AND tenant_id = ? ORDER BY id'
            );
            $itemsStmt->execute([$order['id'], $tenantId]);
            $items = array_map(static fn (array $part): array => [
                'product_id' => (int) $part['product_id'],
                'unit_id' => (int) $part['unit_id'],
                'quantity' => (float) $part['quantity'],
                'sale_price' => (float) $part['sale_price'],
            ], $itemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: []);

            $laborAmount = (float) $order['labor_amount'];
            if ($laborAmount > 0) {
                $laborProductId = (int) ($order['labor_product_id'] ?? 0);
                if ($laborProductId <= 0 || !$this->isServiceProduct($laborProductId, $tenantId)) {
                    return $this->errorResponse($response, 'اختر منتج خدمة لأجرة الصيانة قبل إصدار الفاتورة.', 422);
                }
                $unitStmt = $this->db->prepare(
                    'SELECT unit_id FROM product_units WHERE product_id = ? AND tenant_id = ?
                     ORDER BY is_main_unit DESC, unit_id ASC LIMIT 1'
                );
                $unitStmt->execute([$laborProductId, $tenantId]);
                $unitId = (int) $unitStmt->fetchColumn();
                if ($unitId <= 0) {
                    return $this->errorResponse($response, 'منتج الخدمة غير مرتبط بوحدة قياس.', 422);
                }
                $items[] = [
                    'product_id' => $laborProductId,
                    'unit_id' => $unitId,
                    'quantity' => 1,
                    'sale_price' => $laborAmount,
                ];
            }
            if ($items === []) {
                return $this->errorResponse($response, 'أضف قطع غيار أو أجرة صيانة قبل إصدار الفاتورة.', 422);
            }

            $taxRateValue = (new SettingsRepository($this->db))->get((int) $tenantId, 'company.tax_rate', '0');
            if ($taxRateValue === null || !is_numeric($taxRateValue) || !is_finite((float) $taxRateValue)
                || (float) $taxRateValue < 0 || (float) $taxRateValue > 100) {
                throw new \RuntimeException('Invalid company.tax_rate setting for repair invoice.');
            }
            $paymentMethod = $this->db->prepare(
                "SELECT id FROM payment_methods WHERE tenant_id = ? AND kind = 'credit' ORDER BY id ASC LIMIT 1"
            );
            $paymentMethod->execute([$tenantId]);
            $paymentMethodId = $paymentMethod->fetchColumn();
            if (!$paymentMethodId) {
                $paymentMethod = $this->db->prepare(
                    'SELECT id FROM payment_methods WHERE tenant_id = ? ORDER BY id ASC LIMIT 1'
                );
                $paymentMethod->execute([$tenantId]);
                $paymentMethodId = $paymentMethod->fetchColumn();
            }
            if (!$paymentMethodId) {
                return $this->errorResponse($response, 'أضف طريقة دفع للمستأجر قبل إصدار فاتورة الصيانة.', 422);
            }
            $sale = $this->services->saleCreation((int) $tenantId, $userId)->createSale([
                'tenant_id' => (int) $tenantId,
                'branch_id' => (int) $order['branch_id'],
                'customer_id' => (int) $order['customer_id'],
                'payment_method_id' => (int) $paymentMethodId,
                'items' => $items,
                'tax_rate' => (float) $taxRateValue,
                'paid_amount' => 0,
                'notes' => $invoiceMarker,
                'cashier_session_exempt' => false,
            ]);
            $saleId = (int) ($sale['sale_id'] ?? $sale['id'] ?? 0);
            if ($saleId <= 0) {
                throw new \RuntimeException('SaleCreationService returned no sale id.');
            }
            $updated = $this->db->prepare(
                'UPDATE repair_orders SET invoice_id = ?, updated_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND tenant_id = ? AND invoice_id IS NULL'
            );
            $updated->execute([$saleId, $order['id'], $tenantId]);
            if ($updated->rowCount() !== 1) {
                throw new \RuntimeException('Repair order invoice link was not persisted.');
            }
            return $this->successResponse($response, ['sale_id' => $saleId, 'invoice_number' => $sale['invoice_number'] ?? null], 201);
        } catch (\DomainException $e) {
            return $this->errorResponse($response, $e->getMessage(), (int) $e->getCode() ?: 422);
        } catch (Throwable $e) {
            $this->logger->error('Failed to create repair invoice', [
                'error' => $e->getMessage(),
                'repair_order_id' => $args['id'] ?? null,
                'tenant_id' => $tenantId,
            ]);
            return $this->errorResponse($response, 'فشل في إصدار فاتورة الصيانة.', 500);
        } finally {
            if ($lockAcquired) {
                $release = $this->db->prepare('SELECT RELEASE_LOCK(?)');
                $release->execute([$lockName]);
            }
        }
    }

    private function saveParts(int $orderId, int $tenantId, array $parts): void
    {
        if (count($parts) > 50) {
            throw new \InvalidArgumentException('لا يمكن إضافة أكثر من 50 بند قطع غيار للأمر الواحد.');
        }
        $prepared = [];
        foreach ($parts as $part) {
            if (!is_array($part)) {
                throw new \InvalidArgumentException('بند قطعة الغيار غير صالح.');
            }
            $productId = (int) ($part['product_id'] ?? 0);
            $unitId = (int) ($part['unit_id'] ?? 0);
            $quantity = $part['quantity'] ?? null;
            $price = $part['sale_price'] ?? null;
            if ($productId <= 0 || $unitId <= 0 || !is_numeric($quantity) || !is_finite((float) $quantity)
                || (float) $quantity <= 0 || !is_numeric($price) || !is_finite((float) $price) || (float) $price < 0) {
                throw new \InvalidArgumentException('بيانات قطعة الغيار أو الكمية أو السعر غير صالحة.');
            }
            $product = $this->db->prepare(
                'SELECT 1 FROM products p JOIN product_units pu ON pu.product_id = p.id AND pu.tenant_id = p.tenant_id
                 WHERE p.id = ? AND p.tenant_id = ? AND p.active = 1
                   AND COALESCE(p.product_type, ?) = ? AND pu.unit_id = ? LIMIT 1'
            );
            $product->execute([$productId, $tenantId, 'stock', 'stock', $unitId]);
            if (!$product->fetchColumn()) {
                throw new \InvalidArgumentException('كل قطع الغيار يجب أن تكون منتجات مخزنية نشطة بوحدة قياس مرتبطة.');
            }
            $prepared[] = [$productId, $unitId, (float) $quantity, round((float) $price, 4)];
        }

        $this->db->prepare('DELETE FROM repair_order_parts WHERE repair_order_id = ? AND tenant_id = ?')
            ->execute([$orderId, $tenantId]);
        $insert = $this->db->prepare(
            'INSERT INTO repair_order_parts (tenant_id, repair_order_id, product_id, unit_id, quantity, sale_price)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($prepared as [$productId, $unitId, $quantity, $price]) {
            $insert->execute([$tenantId, $orderId, $productId, $unitId, $quantity, $price]);
        }
    }

    private function findOrder(int $id, int $tenantId, ?int $branchId): ?array
    {
        $sql = 'SELECT ro.*, c.name AS customer_name, c.phone AS customer_phone, s.invoice_number
                FROM repair_orders ro
                JOIN customers c ON c.id = ro.customer_id AND c.tenant_id = ro.tenant_id
                LEFT JOIN sales s ON s.id = ro.invoice_id AND s.tenant_id = ro.tenant_id
                WHERE ro.id = ? AND ro.tenant_id = ?';
        $params = [$id, $tenantId];
        if ($branchId !== null) {
            $sql .= ' AND ro.branch_id = ?';
            $params[] = $branchId;
        }
        $stmt = $this->db->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        return $order ?: null;
    }

    private function resolveBranchId(Request $request, int $tenantId, mixed $requestedBranchId = null): ?int
    {
        $user = $request->getAttribute('user');
        $privileged = SuperAdminHelper::isAdminOrAbove(is_array($user) ? $user : null)
            || (is_array($user) && strtolower((string) ($user['role'] ?? '')) === 'manager');
        if (!$privileged) {
            $userId = $this->extractUserId($request);
            $stmt = $this->db->prepare('SELECT branch_id FROM users WHERE id = ? AND tenant_id = ? LIMIT 1');
            $stmt->execute([$userId, $tenantId]);
            $assigned = (int) $stmt->fetchColumn();
            if ($assigned <= 0) {
                throw new \DomainException('لم يتم تعيين فرع لحسابك.', 403);
            }
            if ($requestedBranchId !== null && $requestedBranchId !== '' && (int) $requestedBranchId !== $assigned) {
                throw new \DomainException('لا تملك صلاحية استخدام الفرع المحدد.', 403);
            }
            $requestedBranchId = $assigned;
        }
        if ($requestedBranchId === null || $requestedBranchId === '') {
            return null;
        }
        if (!is_numeric($requestedBranchId) || (int) $requestedBranchId <= 0) {
            throw new \DomainException('معرف الفرع غير صالح.', 400);
        }
        return (int) $requestedBranchId;
    }

    private function branchExists(int $branchId, int $tenantId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM branches WHERE id = ? AND tenant_id = ? LIMIT 1');
        $stmt->execute([$branchId, $tenantId]);
        return (bool) $stmt->fetchColumn();
    }

    private function isServiceProduct(int $productId, int $tenantId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM products WHERE id = ? AND tenant_id = ? AND active = 1
             AND COALESCE(product_type, 'stock') = 'service' LIMIT 1"
        );
        $stmt->execute([$productId, $tenantId]);
        return (bool) $stmt->fetchColumn();
    }

    private function nonNegativeAmount(mixed $value, string $label): ?float
    {
        if (!is_numeric($value) || !is_finite((float) $value) || (float) $value < 0) {
            return null;
        }
        return round((float) $value, 2);
    }

    private function nullableText(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_scalar($value)) {
            throw new \InvalidArgumentException('قيمة نصية غير صالحة.');
        }
        return trim((string) $value);
    }

    private function nullableDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value ? $value : null;
    }
}
