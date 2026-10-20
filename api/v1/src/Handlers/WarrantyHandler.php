<?php

declare(strict_types=1);

namespace App\Handlers;

use PDO;
use Exception;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Services\MonologHandler;
use App\Utils\PaginationHelper;

/**
 * Manages tenant warranty requests, their notes, items, and attachments.
 *
 * PDO persists request data and attachment metadata; PaginationHelper builds
 * list pagination, while uploaded files are written to a local uploads directory.
 * app/routes/warranty.php applies the warranty permission checks.
 */
class WarrantyHandler extends BaseHandler
{
    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->logger = MonologHandler::getInstance('warranty');
    }

    /** List tenant warranty requests with filters and pagination. */
    public function list(Request $request, Response $response): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $query = $request->getQueryParams();
            $status = $query['status'] ?? null;
            $priority = $query['priority'] ?? null;
            $search = trim((string) ($query['search'] ?? ''));
            $customerId = isset($query['customer_id']) && $query['customer_id'] !== '' ? (int) $query['customer_id'] : null;
            $invoiceId = isset($query['invoice_id']) && $query['invoice_id'] !== '' ? (int) $query['invoice_id'] : null;
            $branchId = isset($query['branch_id']) && ctype_digit((string) $query['branch_id'])
                ? (int) $query['branch_id']
                : null;
            $fromDate = $query['from_date'] ?? $query['date_from'] ?? null;
            $toDate = $query['to_date'] ?? $query['date_to'] ?? null;
            [$page, $limit, $offset] = PaginationHelper::fromArray($query, 20, 100);

            $this->logger->info('Warranty requests list', [
                'tenant_id' => $tenantId,
                'status' => $status,
                'priority' => $priority,
                'search' => $search,
                'customer_id' => $customerId,
                'invoice_id' => $invoiceId,
                'branch_id' => $branchId,
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'page' => $page,
                'limit' => $limit
            ]);

            $where = ["wr.tenant_id = :tenant_id"];
            $params = [':tenant_id' => $tenantId];

            if ($status) {
                $where[] = "wr.status = :status";
                $params[':status'] = $status;

                $this->logger->debug('Filtering by status', [
                    'tenant_id' => $tenantId,
                    'status' => $status
                ]);
            }

            if ($priority) {
                $where[] = "wr.priority = :priority";
                $params[':priority'] = $priority;
            }

            if ($search !== '') {
                $where[] = "(
                    CAST(wr.id AS CHAR) LIKE :search_id
                    OR COALESCE(wr.product_serial, '') LIKE :search_serial
                    OR COALESCE(wr.issue_description, '') LIKE :search_description
                    OR EXISTS (
                        SELECT 1 FROM customers c
                        WHERE c.id = wr.customer_id
                          AND c.tenant_id = wr.tenant_id
                          AND c.name LIKE :search_customer
                    )
                )";
                $searchLike = '%' . $search . '%';
                $params[':search_id'] = $searchLike;
                $params[':search_serial'] = $searchLike;
                $params[':search_description'] = $searchLike;
                $params[':search_customer'] = $searchLike;
            }

            if ($customerId) {
                $where[] = "wr.customer_id = :customer_id";
                $params[':customer_id'] = $customerId;

                $this->logger->debug('Filtering by customer', [
                    'tenant_id' => $tenantId,
                    'customer_id' => $customerId
                ]);
            }

            if ($invoiceId) {
                $where[] = "wr.invoice_id = :invoice_id";
                $params[':invoice_id'] = $invoiceId;

                $this->logger->debug('Filtering by invoice', [
                    'tenant_id' => $tenantId,
                    'invoice_id' => $invoiceId
                ]);
            }

            if ($branchId) {
                $where[] = "EXISTS (
                    SELECT 1 FROM sales s
                    WHERE s.id = wr.invoice_id
                      AND s.tenant_id = wr.tenant_id
                      AND s.branch_id = :branch_id
                )";
                $params[':branch_id'] = $branchId;
            }

            if ($fromDate) {
                $where[] = "wr.created_at >= :from_date";
                $params[':from_date'] = $fromDate . ' 00:00:00';

                $this->logger->debug('Filtering from date', [
                    'tenant_id' => $tenantId,
                    'from_date' => $fromDate
                ]);
            }

            if ($toDate) {
                $nextDay = date('Y-m-d', strtotime($toDate . ' +1 day'));
                $where[] = "wr.created_at < :to_date";
                $params[':to_date'] = $nextDay . ' 00:00:00';

                $this->logger->debug('Filtering to date', [
                    'tenant_id' => $tenantId,
                    'to_date' => $toDate,
                    'next_day' => $nextDay
                ]);
            }

            $whereSql = implode(' AND ', $where);

            $this->logger->debug('Executing warranty requests query', [
                'tenant_id' => $tenantId,
                'where_sql' => $whereSql,
                'limit' => $limit,
                'offset' => $offset
            ]);

            $stmt = $this->db->prepare(
                "SELECT SQL_CALC_FOUND_ROWS wr.*, c.name AS customer_name
                 FROM warranty_requests wr
                 LEFT JOIN customers c ON c.id = wr.customer_id AND c.tenant_id = wr.tenant_id
                 WHERE $whereSql
                 ORDER BY wr.id DESC
                 LIMIT :limit OFFSET :offset"
            );

            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v);
            }

            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();

            $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $total = (int) $this->db->query('SELECT FOUND_ROWS()')->fetchColumn();
            $totalPages = PaginationHelper::buildMeta($total, $page, $limit)['last_page'];

            $this->logger->info('Warranty requests retrieved successfully', [
                'tenant_id' => $tenantId,
                'count' => count($items),
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'total_pages' => $totalPages,
                'filters' => [
                    'status' => $status,
                    'priority' => $priority,
                    'search' => $search,
                    'customer_id' => $customerId,
                    'invoice_id' => $invoiceId,
                    'branch_id' => $branchId,
                    'from_date' => $fromDate,
                    'to_date' => $toDate
                ]
            ]);

            return $this->successResponse($response, [
                'items' => $items,
                'total' => $total,
                'total_pages' => $totalPages
            ], 200);
        } catch (Exception $e) {
            $this->logger->error('Warranty requests list failed', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'tenant_id' => $tenantId ?? 'unknown'
            ]);

            return $this->errorResponse($response, 'فشل في جلب طلبات الضمان', 500);
        }
    }

    /** Create a warranty request and any supplied item rows. */
    public function create(Request $request, Response $response): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $userId = $this->extractUserId($request) ?? 0;
            $data = $request->getParsedBody();
            if (!is_array($data)) {
                $data = [];
            }

            $status = $data['status'] ?? 'open';
            $priority = $data['priority'] ?? 'medium';
            $customerId = $this->parseOptionalPositiveId($data['customer_id'] ?? null, 'معرف العميل');
            $invoiceId = $this->parseOptionalPositiveId($data['invoice_id'] ?? null, 'معرف فاتورة البيع');
            if ($invoiceId !== null) {
                if ($customerId === null) {
                    return $this->errorResponse($response, 'يجب تحديد العميل قبل ربط فاتورة البيع.', 400);
                }
                if (!$this->saleBelongsToCustomer($invoiceId, $tenantId, $customerId)) {
                    return $this->errorResponse($response, 'فاتورة البيع المحددة غير موجودة لهذا العميل.', 400);
                }
            }
            $issueDescription = $data['issue_description'] ?? null;
            $purchaseDate = $data['purchase_date'] ?? null;
            $productSerial = $data['product_serial'] ?? null;
            $assignedTo = isset($data['assigned_to']) ? (int) $data['assigned_to'] : null;
            $slaDueAt = $data['sla_due_at'] ?? null;

            $stmt = $this->db->prepare(
                "INSERT INTO warranty_requests (
                    tenant_id, customer_id, invoice_id, status, priority,
                    issue_description, purchase_date, product_serial,
                    created_by, assigned_to, sla_due_at, created_at, updated_at
                ) VALUES (
                    :tenant_id, :customer_id, :invoice_id, :status, :priority,
                    :issue_description, :purchase_date, :product_serial,
                    :created_by, :assigned_to, :sla_due_at, NOW(), NOW()
                )"
            );

            $stmt->execute([
                ':tenant_id' => $tenantId,
                ':customer_id' => $customerId,
                ':invoice_id' => $invoiceId,
                ':status' => $status,
                ':priority' => $priority,
                ':issue_description' => $issueDescription,
                ':purchase_date' => $purchaseDate,
                ':product_serial' => $productSerial,
                ':created_by' => $userId,
                ':assigned_to' => $assignedTo,
                ':sla_due_at' => $slaDueAt
            ]);

            $wrId = (int) $this->db->lastInsertId();

            if (!empty($data['items']) && is_array($data['items'])) {
                $stmtItem = $this->db->prepare(
                    "INSERT INTO warranty_items (
                        tenant_id, warranty_request_id, product_id,
                        quantity, issue_notes, created_at
                    ) VALUES (?, ?, ?, ?, ?, NOW())"
                );

                foreach ($data['items'] as $it) {
                    $pid = isset($it['product_id']) ? (int) $it['product_id'] : null;
                    if (!$pid) {
                        continue;
                    }

                    $qty = isset($it['quantity']) ? (float) $it['quantity'] : 1;
                    $notes = $it['issue_notes'] ?? null;
                    $stmtItem->execute([$tenantId, $wrId, $pid, $qty, $notes]);
                }
            }

            return $this->successResponse($response, ['id' => $wrId], 200);
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($response, $e->getMessage(), 400);
        } catch (Exception $e) {
            $this->logger->error('Warranty request creation failed', ['message' => $e->getMessage()]);
            return $this->errorResponse($response, 'فشل في إنشاء طلب الضمان', 500);
        }
    }

    /** Return one tenant request with its customer, items, attachments, and notes. */
    public function get(Request $request, Response $response, array $args): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $id = (int) $args['id'];

            $stmt = $this->db->prepare(
                "SELECT wr.*, c.name as customer_name
                 FROM warranty_requests wr
                 LEFT JOIN customers c ON c.id = wr.customer_id AND c.tenant_id = wr.tenant_id
                 WHERE wr.id = ? AND wr.tenant_id = ?"
            );
            $stmt->execute([$id, $tenantId]);
            $wr = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$wr) {
                return $this->errorResponse($response, 'لم يتم العثور على السجل', 403);
            }

            $stmt = $this->db->prepare(
                "SELECT wi.*, p.name as product_name, p.barcode as product_barcode
                 FROM warranty_items wi
                 LEFT JOIN products p ON p.id = wi.product_id AND p.tenant_id = wi.tenant_id
                 WHERE wi.warranty_request_id = ? AND wi.tenant_id = ?"
            );
            $stmt->execute([$id, $tenantId]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $stmt = $this->db->prepare(
                "SELECT id, original_name, filename, mime_type, size, uploaded_by, uploaded_at
                 FROM warranty_attachments
                 WHERE warranty_request_id = ? AND tenant_id = ?
                 ORDER BY id DESC"
            );
            $stmt->execute([$id, $tenantId]);
            $attachments = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $stmt = $this->db->prepare(
                "SELECT n.id, n.note, n.is_internal, n.created_by, n.created_at, u.name as created_by_name
                 FROM warranty_notes n
                 LEFT JOIN users u ON u.id = n.created_by AND u.tenant_id = n.tenant_id
                 WHERE n.warranty_request_id = ? AND n.tenant_id = ?
                 ORDER BY n.id DESC"
            );
            $stmt->execute([$id, $tenantId]);
            $notes = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            return $this->successResponse($response, [
                'request' => $wr,
                'items' => $items,
                'attachments' => $attachments,
                'notes' => $notes,
                'customer' => [
                    'id' => $wr['customer_id'] ?? null,
                    'name' => $wr['customer_name'] ?? null
                ]
            ], 200);
        } catch (Exception $e) {
            $this->logger->error('Warranty request retrieval failed', ['message' => $e->getMessage()]);
            return $this->errorResponse($response, 'فشل في جلب تفاصيل طلب الضمان', 500);
        }
    }

    /** Update the supplied allowlisted request fields. */
    public function update(Request $request, Response $response, array $args): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $id = (int) $args['id'];
            $data = $request->getParsedBody();
            if (!is_array($data)) {
                $data = [];
            }

            if (array_key_exists('customer_id', $data) || array_key_exists('invoice_id', $data)) {
                $currentStmt = $this->db->prepare(
                    'SELECT customer_id, invoice_id
                     FROM warranty_requests
                     WHERE id = ? AND tenant_id = ?
                     LIMIT 1'
                );
                $currentStmt->execute([$id, $tenantId]);
                $current = $currentStmt->fetch(PDO::FETCH_ASSOC);
                if (!$current) {
                    return $this->errorResponse($response, 'لم يتم العثور على طلب الضمان.', 404);
                }

                $customerId = array_key_exists('customer_id', $data)
                    ? $this->parseOptionalPositiveId($data['customer_id'], 'معرف العميل')
                    : ($current['customer_id'] !== null ? (int) $current['customer_id'] : null);
                $invoiceId = array_key_exists('invoice_id', $data)
                    ? $this->parseOptionalPositiveId($data['invoice_id'], 'معرف فاتورة البيع')
                    : ($current['invoice_id'] !== null ? (int) $current['invoice_id'] : null);

                if ($invoiceId !== null) {
                    if ($customerId === null) {
                        return $this->errorResponse($response, 'يجب تحديد العميل قبل ربط فاتورة البيع.', 400);
                    }
                    if (!$this->saleBelongsToCustomer($invoiceId, $tenantId, $customerId)) {
                        return $this->errorResponse($response, 'فاتورة البيع المحددة غير موجودة لهذا العميل.', 400);
                    }
                }

                if (array_key_exists('customer_id', $data)) {
                    $data['customer_id'] = $customerId;
                }
                if (array_key_exists('invoice_id', $data)) {
                    $data['invoice_id'] = $invoiceId;
                }
            }

            $updates = [];
            $params = [];
            $allowed = [
                'status',
                'priority',
                'customer_id',
                'invoice_id',
                'issue_description',
                'purchase_date',
                'product_serial',
                'assigned_to',
                'sla_due_at',
                'closed_at'
            ];

            foreach ($allowed as $f) {
                if (array_key_exists($f, $data)) {
                    $updates[] = "$f = :$f";
                    $params[":$f"] = $data[$f];
                }
            }

            if (empty($updates)) {
                return $this->errorResponse($response, 'No fields to update', 400);
            }

            $params[':id'] = $id;
            $params[':tenant_id'] = $tenantId;

            $sql = "UPDATE warranty_requests
                    SET " . implode(', ', $updates) . ", updated_at = NOW()
                    WHERE id = :id AND tenant_id = :tenant_id";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);

            return $this->successResponse($response, [
                'status' => 'success',
                'message' => 'Updated'
            ], 200);
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($response, $e->getMessage(), 400);
        } catch (Exception $e) {
            $this->logger->error('Warranty request update failed', ['message' => $e->getMessage()]);
            return $this->errorResponse($response, 'فشل في تحديث طلب الضمان', 500);
        }
    }

    /** Change request status and optionally add a reason note. */
    public function changeStatus(Request $request, Response $response, array $args): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $userId = $this->extractUserId($request) ?? 0;
            $id = (int) $args['id'];
            $data = $request->getParsedBody();
            if (!is_array($data)) {
                $data = [];
            }

            $status = $data['status'] ?? null;
            $reason = $data['reason'] ?? null;

            if (!$status) {
                return $this->errorResponse($response, 'حقل الحالة (status) مطلوب', 400);
            }

            $stmt = $this->db->prepare(
                "UPDATE warranty_requests
                 SET status = ?, updated_at = NOW(),
                     closed_at = IF(? IN ('closed','rejected','replaced','repaired'), NOW(), closed_at)
                 WHERE id = ? AND tenant_id = ?"
            );
            $stmt->execute([$status, $status, $id, $tenantId]);

            if ($reason) {
                $stmt = $this->db->prepare(
                    "INSERT INTO warranty_notes (
                        tenant_id, warranty_request_id, note,
                        is_internal, created_by, created_at
                    ) VALUES (?, ?, ?, 0, ?, NOW())"
                );
                $stmt->execute([$tenantId, $id, $reason, $userId]);
            }

            return $this->successResponse($response, ['status' => 'success'], 200);
        } catch (Exception $e) {
            $this->logger->error('Warranty status change failed', ['message' => $e->getMessage()]);
            return $this->errorResponse($response, 'فشل في تغيير حالة طلب الضمان', 500);
        }
    }

    /** Add a tenant note to a warranty request. */
    public function addNote(Request $request, Response $response, array $args): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $userId = $this->extractUserId($request) ?? 0;
            $id = (int) $args['id'];
            $data = $request->getParsedBody();
            if (!is_array($data)) {
                $data = [];
            }

            $note = trim((string) ($data['note'] ?? ''));
            $isInternal = !empty($data['is_internal']) ? 1 : 0;

            if ($note === '') {
                return $this->errorResponse($response, 'حقل الملاحظة (note) مطلوب', 400);
            }

            $stmt = $this->db->prepare(
                "INSERT INTO warranty_notes (
                    tenant_id, warranty_request_id, note,
                    is_internal, created_by, created_at
                ) VALUES (?, ?, ?, ?, ?, NOW())"
            );
            $stmt->execute([$tenantId, $id, $note, $isInternal, $userId]);

            return $this->successResponse($response, ['status' => 'success'], 200);
        } catch (Exception $e) {
            $this->logger->error('Warranty note addition failed', ['message' => $e->getMessage()]);
            return $this->errorResponse($response, 'فشل في إضافة الملاحظة', 500);
        }
    }

    /** Store an uploaded file locally and persist its request metadata. */
    public function uploadAttachment(Request $request, Response $response, array $args): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $userId = $this->extractUserId($request) ?? 0;
            $id = (int) $args['id'];

            $files = $request->getUploadedFiles();
            if (!isset($files['file'])) {
                return $this->errorResponse($response, 'حقل الملف (file) مطلوب', 400);
            }

            $file = $files['file'];
            if ($file->getError() !== UPLOAD_ERR_OK) {
                return $this->errorResponse($response, 'خطأ أثناء رفع الملف', 400);
            }

            $uploadsDir = realpath(__DIR__ . '/../../../public/uploads');
            if ($uploadsDir === false) {
                $uploadsDir = __DIR__ . '/../../../public/uploads';
            }

            if (!is_dir($uploadsDir)) {
                @mkdir($uploadsDir, 0777, true);
            }

            $original = $file->getClientFilename();
            $ext = pathinfo($original, PATHINFO_EXTENSION);
            $safeName = 'wr_' . $id . '_' . time() . '_' . bin2hex(random_bytes(4)) . ($ext ? ('.' . $ext) : '');
            $target = rtrim($uploadsDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $safeName;

            $file->moveTo($target);

            $mime = $file->getClientMediaType();
            $size = $file->getSize();

            $stmt = $this->db->prepare(
                "INSERT INTO warranty_attachments (
                    tenant_id, warranty_request_id, filename,
                    original_name, mime_type, size, uploaded_by, uploaded_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
            );
            $stmt->execute([$tenantId, $id, $safeName, $original, $mime, $size, $userId]);

            $attId = (int) $this->db->lastInsertId();

            return $this->successResponse($response, [
                'id' => $attId,
                'filename' => $safeName
            ], 200);
        } catch (Exception $e) {
            $this->logger->error('Warranty attachment upload failed', ['message' => $e->getMessage()]);
            return $this->errorResponse($response, 'فشل في رفع المرفق', 500);
        }
    }

    /** Remove a tenant attachment record and its local file when present. */
    public function deleteAttachment(Request $request, Response $response, array $args): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $attId = (int) $args['attachmentId'];

            $stmt = $this->db->prepare("SELECT filename FROM warranty_attachments WHERE id = ? AND tenant_id = ? LIMIT 1");
            $stmt->execute([$attId, $tenantId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                return $this->errorResponse($response, 'لم يتم العثور على السجل', 403);
            }

            $uploadsDir = realpath(__DIR__ . '/../../../public/uploads');
            if ($uploadsDir === false) {
                $uploadsDir = __DIR__ . '/../../../public/uploads';
            }

            $target = rtrim($uploadsDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $row['filename'];
            if (is_file($target)) {
                @unlink($target);
            }

            $stmt = $this->db->prepare("DELETE FROM warranty_attachments WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$attId, $tenantId]);

            return $this->successResponse($response, ['status' => 'success'], 200);
        } catch (Exception $e) {
            $this->logger->error('Warranty attachment deletion failed', ['message' => $e->getMessage()]);
            return $this->errorResponse($response, 'فشل في حذف المرفق', 500);
        }
    }

    private function parseOptionalPositiveId($value, string $label): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_int($value) && !is_string($value)) {
            throw new \InvalidArgumentException("{$label} يجب أن يكون رقمًا صحيحًا موجبًا.");
        }

        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) {
            throw new \InvalidArgumentException("{$label} يجب أن يكون رقمًا صحيحًا موجبًا.");
        }

        return $id;
    }

    private function saleBelongsToCustomer(int $saleId, int $tenantId, int $customerId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1
             FROM sales
             WHERE id = ? AND tenant_id = ? AND customer_id = ?
             LIMIT 1'
        );
        $stmt->execute([$saleId, $tenantId, $customerId]);

        return $stmt->fetchColumn() !== false;
    }
}
