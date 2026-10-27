<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Utils\PaginationHelper;
use App\Utils\SuperAdminHelper;
use App\Security\Permissions;
use App\Security\PermissionResolver;
use App\Security\SaleDiscountPolicy;
use DateTimeImmutable;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

final class SalesQuotationsHandler extends BaseHandler
{
    public function __construct(PDO $db)
    {
        parent::__construct($db);
    }

    public function list(Request $request, Response $response): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر.', 403);
            }

            $branchScope = $this->resolveBranchScope($request, (int) $tenantId);
            [$page, $perPage, $offset] = PaginationHelper::fromRequest($request);
            $filters = ['q.tenant_id = ?'];
            $params = [(int) $tenantId];
            if ($branchScope !== null) {
                $filters[] = 'q.branch_id = ?';
                $params[] = $branchScope;
            }

            $query = trim((string) ($request->getQueryParams()['q'] ?? ''));
            if ($query !== '') {
                $filters[] = '(q.quote_number LIKE ? OR c.name LIKE ?)';
                $params[] = '%' . $query . '%';
                $params[] = '%' . $query . '%';
            }

            $status = strtolower(trim((string) ($request->getQueryParams()['status'] ?? '')));
            if ($status === 'expired') {
                $filters[] = "q.status = 'active' AND q.valid_until < CURRENT_DATE";
            } elseif ($status === 'active') {
                $filters[] = "q.status = 'active' AND q.valid_until >= CURRENT_DATE";
            }

            $where = implode(' AND ', $filters);
            $count = $this->db->prepare(
                "SELECT COUNT(*) FROM sales_quotations q
                 LEFT JOIN customers c ON c.id = q.customer_id AND c.tenant_id = q.tenant_id
                 WHERE {$where}"
            );
            $count->execute($params);
            $total = (int) $count->fetchColumn();

            $stmt = $this->db->prepare(
                "SELECT q.id, q.quote_number, q.branch_id, q.customer_id, c.name AS customer_name,
                        CASE WHEN q.status = 'active' AND q.valid_until < CURRENT_DATE THEN 'expired' ELSE q.status END AS status,
                        q.valid_until, q.subtotal, q.discount_amount, q.tax_amount,
                        q.total_amount, q.currency_code, q.notes, q.created_at
                 FROM sales_quotations q
                 LEFT JOIN customers c ON c.id = q.customer_id AND c.tenant_id = q.tenant_id
                 WHERE {$where}
                 ORDER BY q.created_at DESC, q.id DESC
                 LIMIT {$perPage} OFFSET {$offset}"
            );
            $stmt->execute($params);

            return $this->successResponse($response, [
                'items' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
            ]);
        } catch (Throwable $error) {
            return $this->failureResponse($response, $error, 'list');
        }
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر.', 403);
            }
            $branchScope = $this->resolveBranchScope($request, (int) $tenantId);

            $sql = "SELECT q.*, c.name AS customer_name
                    FROM sales_quotations q
                    LEFT JOIN customers c ON c.id = q.customer_id AND c.tenant_id = q.tenant_id
                    WHERE q.id = ? AND q.tenant_id = ?";
            $params = [(int) ($args['id'] ?? 0), (int) $tenantId];
            if ($branchScope !== null) {
                $sql .= ' AND q.branch_id = ?';
                $params[] = $branchScope;
            }

            $stmt = $this->db->prepare($sql . ' LIMIT 1');
            $stmt->execute($params);
            $quote = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$quote) {
                return $this->errorResponse($response, 'عرض السعر غير موجود أو غير متاح.', 404);
            }

            $quote['items'] = json_decode((string) $quote['items_json'], true, 512, JSON_THROW_ON_ERROR);
            unset($quote['items_json']);
            if ($quote['status'] === 'active' && $quote['valid_until'] < date('Y-m-d')) {
                $quote['status'] = 'expired';
            }

            return $this->successResponse($response, $quote);
        } catch (Throwable $error) {
            return $this->failureResponse($response, $error, 'get');
        }
    }

    public function create(Request $request, Response $response): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            $userId = $this->extractUserId($request);
            if (!$tenantId || !$userId) {
                return $this->errorResponse($response, 'تعذر تحديد المستأجر أو المستخدم الحالي.', 403);
            }

            $data = $request->getParsedBody();
            if (!is_array($data)) {
                return $this->errorResponse($response, 'بيانات عرض السعر غير صالحة.');
            }

            $branchId = filter_var($data['branch_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$branchId || $branchId <= 0 || !$this->canAccessBranch($request, (int) $tenantId, (int) $branchId)) {
                return $this->errorResponse($response, 'الفرع المحدد غير متاح لهذا المستخدم.', 403);
            }

            $validUntil = trim((string) ($data['valid_until'] ?? ''));
            $validDate = DateTimeImmutable::createFromFormat('!Y-m-d', $validUntil);
            if (!$validDate || $validDate->format('Y-m-d') !== $validUntil || $validUntil < date('Y-m-d')) {
                return $this->errorResponse($response, 'تاريخ صلاحية العرض غير صالح.');
            }

            $inputItems = $data['items'] ?? null;
            if (!is_array($inputItems) || $inputItems === [] || count($inputItems) > 200) {
                return $this->errorResponse($response, 'يجب إضافة صنف واحد على الأقل، وبحد أقصى 200 صنف.');
            }

            $customerId = isset($data['customer_id']) && $data['customer_id'] !== ''
                ? filter_var($data['customer_id'], FILTER_VALIDATE_INT)
                : null;
            if ($customerId !== null && (!$customerId || !$this->customerExists((int) $tenantId, (int) $customerId))) {
                return $this->errorResponse($response, 'العميل المحدد غير متاح لهذا المستأجر.');
            }

            $items = [];
            $policyItems = [];
            $subtotal = 0.0;
            $itemLookup = $this->db->prepare(
                'SELECT p.name AS product_name, p.product_code, p.sale_price AS catalog_sale_price,
                        p.purchase_price, p.min_sale_price, p.fixed_discount_percentage,
                        pu.conversion_factor, u.name AS unit_name
                 FROM products p
                 JOIN product_units pu ON pu.product_id = p.id AND pu.tenant_id = p.tenant_id
                 JOIN units u ON u.id = pu.unit_id
                 WHERE p.id = ? AND pu.unit_id = ? AND p.tenant_id = ? AND p.active = 1
                 LIMIT 1'
            );
            foreach ($inputItems as $item) {
                if (!is_array($item)) {
                    return $this->errorResponse($response, 'أحد أصناف العرض غير صالح.');
                }
                $productId = filter_var($item['product_id'] ?? null, FILTER_VALIDATE_INT);
                $unitId = filter_var($item['unit_id'] ?? null, FILTER_VALIDATE_INT);
                $quantity = $item['quantity'] ?? null;
                $unitPrice = $item['sale_price'] ?? null;
                if (!$productId || !$unitId
                    || !is_numeric($quantity) || !is_finite((float) $quantity) || (float) $quantity <= 0
                    || !is_numeric($unitPrice) || !is_finite((float) $unitPrice) || (float) $unitPrice < 0) {
                    return $this->errorResponse($response, 'بيانات كمية أو سعر أحد الأصناف غير صالحة.');
                }

                $itemLookup->execute([(int) $productId, (int) $unitId, (int) $tenantId]);
                $product = $itemLookup->fetch(PDO::FETCH_ASSOC);
                if (!$product) {
                    return $this->errorResponse($response, 'أحد المنتجات أو وحداته غير متاح لهذا المستأجر.');
                }

                $quantity = (float) $quantity;
                $unitPrice = (float) $unitPrice;
                $lineTotal = round($quantity * $unitPrice, 2);
                $subtotal += $lineTotal;
                $conversionFactor = (float) $product['conversion_factor'];
                $policyItems[] = [
                    'quantity' => $quantity,
                    'sale_price' => $unitPrice,
                    'catalog_sale_price' => (float) $product['catalog_sale_price'],
                    'conversion_factor' => $conversionFactor,
                    'min_sale_price' => (float) ($product['min_sale_price'] ?? 0),
                    'purchase_price' => (float) ($product['purchase_price'] ?? 0),
                    'fixed_discount_percentage' => (float) ($product['fixed_discount_percentage'] ?? 0),
                    'manual_price_override' => abs($unitPrice - ((float) $product['catalog_sale_price'] * $conversionFactor)) > 0.005,
                    'below_cost_confirmed' => filter_var($item['below_cost_confirmed'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'below_minimum_price_confirmed' => filter_var($item['below_minimum_price_confirmed'] ?? false, FILTER_VALIDATE_BOOLEAN),
                ];
                $items[] = [
                    'product_id' => (int) $productId,
                    'unit_id' => (int) $unitId,
                    'product_name' => (string) $product['product_name'],
                    'product_code' => (string) ($product['product_code'] ?? ''),
                    'unit_name' => (string) $product['unit_name'],
                    'quantity' => $quantity,
                    'sale_price' => $unitPrice,
                    'line_total' => $lineTotal,
                ];
            }

            $discountType = $data['discount_type'] ?? 'fixed';
            $discountValue = $data['discount_value'] ?? 0;
            $taxRate = $data['tax_rate'] ?? 0;
            if (!in_array($discountType, ['fixed', 'percentage'], true)
                || !is_numeric($discountValue) || !is_finite((float) $discountValue) || (float) $discountValue < 0
                || !is_numeric($taxRate) || !is_finite((float) $taxRate) || (float) $taxRate < 0 || (float) $taxRate > 100) {
                return $this->errorResponse($response, 'بيانات الخصم أو الضريبة غير صالحة.');
            }
            $discountValue = (float) $discountValue;
            $discountAmount = $discountType === 'percentage'
                ? $subtotal * min($discountValue, 100) / 100
                : min($discountValue, $subtotal);
            $user = $request->getAttribute('user');
            $privilegedSaleUser = $this->isBranchPrivileged($user);
            $canDiscount = $privilegedSaleUser || PermissionResolver::hasPermission(
                $this->db,
                (int) $userId,
                Permissions::SALE_DISCOUNT
            );
            $belowCostReason = trim((string) ($data['below_cost_reason'] ?? ''));
            SaleDiscountPolicy::assertAllowed(
                $policyItems,
                $discountAmount,
                $subtotal,
                $canDiscount,
                $privilegedSaleUser,
                $belowCostReason
            );
            $taxAmount = ($subtotal - $discountAmount) * (float) $taxRate / 100;
            $totalAmount = $subtotal - $discountAmount + $taxAmount;
            $currencyCode = strtoupper(trim((string) ($data['currency_code'] ?? 'EGP')));
            if (!preg_match('/^[A-Z]{3}$/', $currencyCode)) {
                return $this->errorResponse($response, 'رمز العملة غير صالح.');
            }
            $notes = trim((string) ($data['notes'] ?? ''));
            if (mb_strlen($notes) > 2000) {
                return $this->errorResponse($response, 'ملاحظات العرض تتجاوز الحد المسموح.');
            }

            $this->db->beginTransaction();
            $insert = $this->db->prepare(
                'INSERT INTO sales_quotations
                    (tenant_id, branch_id, customer_id, quote_number, status, valid_until,
                     items_json, discount_type, discount_value, subtotal, discount_amount,
                     tax_rate, tax_amount, total_amount, currency_code, notes, created_by)
                 VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                (int) $tenantId,
                (int) $branchId,
                $customerId,
                'active',
                $validUntil,
                json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                $discountType,
                $discountValue,
                round($subtotal, 2),
                round($discountAmount, 2),
                (float) $taxRate,
                round($taxAmount, 2),
                round($totalAmount, 2),
                $currencyCode,
                $notes !== '' ? $notes : null,
                (int) $userId,
            ]);
            $id = (int) $this->db->lastInsertId();
            $quoteNumber = 'QT-' . date('Ymd') . '-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
            $update = $this->db->prepare('UPDATE sales_quotations SET quote_number = ? WHERE id = ? AND tenant_id = ?');
            $update->execute([$quoteNumber, $id, (int) $tenantId]);
            $this->db->commit();

            return $this->successResponse($response, [
                'id' => $id,
                'quote_number' => $quoteNumber,
                'branch_id' => (int) $branchId,
                'customer_id' => $customerId,
                'status' => 'active',
                'valid_until' => $validUntil,
                'items' => $items,
                'subtotal' => round($subtotal, 2),
                'discount_amount' => round($discountAmount, 2),
                'tax_amount' => round($taxAmount, 2),
                'total_amount' => round($totalAmount, 2),
                'currency_code' => $currencyCode,
                'notes' => $notes,
            ], 201);
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return $this->failureResponse($response, $error, 'create');
        }
    }

    private function resolveBranchScope(Request $request, int $tenantId): ?int
    {
        $user = $request->getAttribute('user');
        $privileged = $this->isBranchPrivileged($user);
        if ($privileged) {
            $requested = trim((string) ($request->getQueryParams()['branch_id'] ?? ''));
            if ($requested === '') {
                return null;
            }
            if (!ctype_digit($requested) || (int) $requested <= 0 || !$this->branchExists($tenantId, (int) $requested)) {
                throw new \DomainException('الفرع المحدد غير متاح لهذا المستأجر.', 403);
            }
            return (int) $requested;
        }

        $userId = $this->extractUserId($request);
        $stmt = $this->db->prepare('SELECT branch_id FROM users WHERE id = ? AND tenant_id = ? LIMIT 1');
        $stmt->execute([$userId, $tenantId]);
        $branchId = (int) $stmt->fetchColumn();
        if ($branchId <= 0) {
            throw new \DomainException('لم يتم تعيين فرع لحسابك.', 403);
        }
        return $branchId;
    }

    private function canAccessBranch(Request $request, int $tenantId, int $branchId): bool
    {
        if (!$this->branchExists($tenantId, $branchId)) {
            return false;
        }
        if ($this->isBranchPrivileged($request->getAttribute('user'))) {
            return true;
        }
        $userId = $this->extractUserId($request);
        $stmt = $this->db->prepare('SELECT 1 FROM users WHERE id = ? AND tenant_id = ? AND branch_id = ? LIMIT 1');
        $stmt->execute([$userId, $tenantId, $branchId]);
        return (bool) $stmt->fetchColumn();
    }

    private function isBranchPrivileged($user): bool
    {
        return SuperAdminHelper::isAdminOrAbove(is_array($user) ? $user : null)
            || (is_array($user)
                && (int) ($user['tenant_id'] ?? 0) > 0
                && strtolower((string) ($user['role'] ?? '')) === 'manager');
    }

    private function branchExists(int $tenantId, int $branchId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM branches WHERE id = ? AND tenant_id = ? LIMIT 1');
        $stmt->execute([$branchId, $tenantId]);
        return (bool) $stmt->fetchColumn();
    }

    private function customerExists(int $tenantId, int $customerId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM customers WHERE id = ? AND tenant_id = ? LIMIT 1');
        $stmt->execute([$customerId, $tenantId]);
        return (bool) $stmt->fetchColumn();
    }

    private function failureResponse(Response $response, Throwable $error, string $operation): Response
    {
        if ($error instanceof \DomainException) {
            $status = in_array((int) $error->getCode(), [400, 403, 404], true)
                ? (int) $error->getCode()
                : 400;
            return $this->errorResponse($response, $error->getMessage(), $status);
        }

        $this->logger->error('Sales quotation operation failed.', [
            'operation' => $operation,
            'exception' => $error,
        ]);
        return $this->errorResponse($response, 'تعذر إتمام طلب عرض السعر.', 500);
    }
}
