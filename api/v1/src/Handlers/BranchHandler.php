<?php

declare(strict_types=1);

namespace App\Handlers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use PDO;
use PDOException;
use App\Services\MonologHandler;
use App\Services\AccountManagementService;

/**
 * Handles branch listing, lookup, creation, updates, and soft deletion.
 *
 * Branch account and cost-center setup uses AccountManagementService and PDO;
 * app/routes/branches.php applies branch permissions and delegates inventory operations.
 */
class BranchHandler extends BaseHandler
{
    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->logger = MonologHandler::getInstance('branch');
    }

    /** List active tenant branches with stock quantity, value, and low-stock aggregates. */
    public function listWithAggregates(Request $request, Response $response, array $args): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $sql = "
                SELECT
                    b.id, b.name, b.location, b.phone, b.email,
                    b.description, b.active, b.account_id, b.cost_center_id,
                    b.created_at, b.updated_at,
                    COUNT(DISTINCT p.id) AS items_count,
                    COALESCE(SUM(wp.quantity * (p.id IS NOT NULL)), 0) AS total_quantity,
                    COALESCE(SUM(CASE WHEN p.id IS NOT NULL
                        THEN COALESCE(wp.quantity_cost, wp.quantity * COALESCE(p.purchase_price, 0))
                        ELSE 0 END), 0) AS total_value,
                    COALESCE(SUM(CASE
                        WHEN p.id IS NOT NULL AND p.min_quantity IS NOT NULL
                             AND wp.quantity > 0 AND wp.quantity <= p.min_quantity THEN 1
                        ELSE 0 END), 0) AS low_stock_count
                FROM branches b
                LEFT JOIN branch_products wp ON wp.branch_id = b.id AND wp.tenant_id = b.tenant_id
                LEFT JOIN products p         ON p.id = wp.product_id AND p.tenant_id = b.tenant_id AND p.active = 1
                WHERE b.tenant_id = ? AND (b.active = 1 OR b.active IS NULL)
                GROUP BY b.id, b.name, b.location, b.phone, b.email,
                         b.description, b.active, b.account_id, b.cost_center_id,
                         b.created_at, b.updated_at
                ORDER BY b.created_at ASC, b.id ASC
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$tenantId]);
            return $this->successResponse($response, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], 200);
        } catch (\Throwable $e) {
            $this->logger->error('خطأ في جلب الفروع مع المجاميع: ' . $e->getMessage());
            return $this->errorResponse($response, 'فشل في جلب بيانات الفروع', 500);
        }
    }

    /** Create a branch with an inventory account and optional cost center in one transaction. */
    public function createBranch(Request $request, Response $response, array $args): Response
    {
        try {
            $data     = $request->getParsedBody();
            $tenantId = $this->extractTenantId($request);
            $userId   = $request->getAttribute('user_id');

            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $name = trim($data['name'] ?? '');
            if ($name === '') {
                return $this->errorResponse($response, 'Branch name is required.', 400);
            }

            $location    = trim($data['location']    ?? '');
            $phone       = trim($data['phone']       ?? '');
            $email       = trim($data['email']       ?? '');
            $description = trim($data['description'] ?? '');
            $active      = isset($data['active']) ? (int) $data['active'] : 1;

            $this->db->beginTransaction();

            // Provision the branch inventory account under parent account 1301.
            $accountMgmt  = new AccountManagementService($this->db);
            $newAccountId = $accountMgmt->createBranchAccount($name, $tenantId, $location);
            if (!$newAccountId) {
                $this->db->rollBack();
                return $this->errorResponse($response, 'فشل إنشاء حساب الفرع. تأكد من وجود حساب الأب (1301) في النظام.', 400);
            }

            $stmtGetCode = $this->db->prepare("SELECT code FROM accounts WHERE id = ? AND tenant_id = ?");
            $stmtGetCode->execute([$newAccountId, $tenantId]);
            $newCode = $stmtGetCode->fetchColumn();

            // Reuse a tenant cost center with the branch name or create one.
            $newCostCenterId = null;
            try {
                $stmtFindCc = $this->db->prepare("SELECT id FROM cost_centers WHERE tenant_id = ? AND name = ? LIMIT 1");
                $stmtFindCc->execute([$tenantId, $name]);
                $existingCc = $stmtFindCc->fetchColumn();
                if ($existingCc) {
                    $newCostCenterId = (int) $existingCc;
                } else {
                    $stmtCc = $this->db->prepare(
                        "INSERT INTO cost_centers (tenant_id, name, code, description, is_active, created_at)
                         VALUES (?, ?, ?, ?, 1, NOW())"
                    );
                    $stmtCc->execute([$tenantId, $name, $newCode ?? null, $description]);
                    $newCostCenterId = (int) $this->db->lastInsertId();
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to create/find cost center for branch: ' . $e->getMessage());
            }

            $stmt = $this->db->prepare(
                "INSERT INTO branches (name, location, phone, email, description, active, tenant_id, account_id, cost_center_id, created_by, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
            );
            $stmt->execute([
                $name, $location, $phone ?: null, $email ?: null,
                $description, $active, $tenantId,
                $newAccountId, $newCostCenterId, $userId,
            ]);

            $newBranchId = (int) $this->db->lastInsertId();
            $this->db->commit();

            return $this->jsonResponse($response, [
                'status'  => 'success',
                'message' => 'branch created successfully',
                'data'    => ['id' => $newBranchId, 'account_id' => $newAccountId, 'account_code' => $newCode],
            ], 201);
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logger->error('خطأ في إنشاء الفرع: ' . $e->getMessage());
            return $this->errorResponse($response, 'فشل في إنشاء الفرع. يرجى المحاولة مرة أخرى.', 400);
        }
    }

    /** Update supplied branch fields and synchronize the linked account name when renamed. */
    public function updateBranch(Request $request, Response $response, array $args): Response
    {
        try {
            $data     = $request->getParsedBody();
            $tenantId = $this->extractTenantId($request);

            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $stmt = $this->db->prepare("SELECT id, account_id FROM branches WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$args['id'], $tenantId]);
            $branchRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$branchRow) {
                return $this->errorResponse($response, 'Branch not found', 404);
            }

            $updates = [];
            $params  = [];

            if (isset($data['name']) && trim($data['name']) !== '') {
                $updates[] = 'name = ?';
                $params[] = trim($data['name']);
            }
            if (isset($data['location'])) {
                $updates[] = 'location = ?';
                $params[] = trim($data['location']);
            }
            if (array_key_exists('phone', $data)) {
                $updates[] = 'phone = ?';
                $params[] = trim($data['phone']) ?: null;
            }
            if (array_key_exists('email', $data)) {
                $updates[] = 'email = ?';
                $params[] = trim($data['email']) ?: null;
            }
            if (isset($data['description'])) {
                $updates[] = 'description = ?';
                $params[] = trim($data['description']);
            }
            if (isset($data['account_id'])) {
                $updates[] = 'account_id = ?';
                $params[] = ($data['account_id'] !== '' && $data['account_id'] !== null) ? (int) $data['account_id'] : null;
            }
            if (isset($data['active'])) {
                $updates[] = 'active = ?';
                $params[] = (int) $data['active'];
            }

            if (empty($updates)) {
                return $this->errorResponse($response, 'No valid fields to update', 400);
            }

            $updates[] = 'updated_at = NOW()';
            $params[]  = $args['id'];
            $params[]  = $tenantId;

            $this->db->beginTransaction();

            $this->db->prepare("UPDATE branches SET " . implode(', ', $updates) . " WHERE id = ? AND tenant_id = ?")
                ->execute($params);

            if (isset($data['name']) && trim($data['name']) !== '' && !empty($branchRow['account_id'])) {
                $this->db->prepare("UPDATE accounts SET name = ? WHERE id = ? AND tenant_id = ?")
                    ->execute(['مخزون - ' . trim($data['name']), (int) $branchRow['account_id'], (int) $tenantId]);
            }

            $this->db->commit();

            $stmt = $this->db->prepare(
                "SELECT id, name, location, phone, email, description, active, account_id, cost_center_id, created_at, updated_at
                 FROM branches
                 WHERE id = ? AND tenant_id = ?"
            );
            $stmt->execute([$args['id'], $tenantId]);
            $updatedBranch = $stmt->fetch(PDO::FETCH_ASSOC);

            return $this->successResponse($response, $updatedBranch, 200, 'Branch updated successfully');
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logger->error('خطأ في تحديث الفرع: ' . $e->getMessage());
            return $this->errorResponse($response, 'فشل في تحديث بيانات الفرع', 400);
        }
    }

    /** Soft-delete a tenant branch after preventing deletion when inventory transfers exist. */
    public function deleteBranch(Request $request, Response $response, array $args): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $stmt = $this->db->prepare("SELECT id FROM branches WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$args['id'], $tenantId]);
            if (!$stmt->fetch()) {
                return $this->errorResponse($response, 'Branch not found', 404);
            }

            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM inventory_transactions
                 WHERE (branch_from = ? OR branch_to = ?) AND tenant_id = ?"
            );
            $stmt->execute([$args['id'], $args['id'], $tenantId]);
            if ($stmt->fetchColumn() > 0) {
                return $this->errorResponse($response, 'Cannot delete branch with existing inventory transactions', 400);
            }

            $this->db->prepare("UPDATE branches SET active = 0, updated_at = NOW() WHERE id = ? AND tenant_id = ?")
                ->execute([$args['id'], $tenantId]);

            return $this->jsonResponse($response, ['status' => 'success', 'message' => 'Branch deleted successfully']);
        } catch (\Throwable $e) {
            $this->logger->error('خطأ في حذف الفرع: ' . $e->getMessage());
            return $this->errorResponse($response, 'فشل في حذف الفرع', 400);
        }
    }
}
