<?php

declare(strict_types=1);

namespace App\Handlers;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Services\MonologHandler;
use App\Services\AccountManagementService;
use App\Handlers\AuditHandler;

/**
 * Handles supplier records and ledger statements.
 *
 * BaseContactHandler supplies shared account maintenance. PDO reads and writes
 * are coordinated with account and accounting services; statements are
 * delegated to AccountStatementHandler.
 */
class SuppliersHandler extends BaseContactHandler
{
    protected string $contactType = 'supplier';

    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->logger = MonologHandler::getInstance('suppliers');
    }

    /**
     * Returns detailed account statement for a supplier by delegating to AccountStatementHandler.
     */
    public function getStatement(Request $request, Response $response, array $args): Response
    {
        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
        }

        $supplierId = $args['id'] ?? null;
        if (!$supplierId) {
            return $this->errorResponse($response, 'مطلوب رقم المورد', 400);
        }

        $stmt = $this->db->prepare(
            "SELECT account_id
             FROM suppliers
             WHERE id = :id AND tenant_id = :tenant_id
             LIMIT 1"
        );
        $stmt->execute([
            ':id' => $supplierId,
            ':tenant_id' => $tenantId
        ]);
        $accountId = $stmt->fetchColumn();

        if (!$accountId) {
            return $this->errorResponse($response, 'لم يتم العثور على حساب المورد', 403);
        }

        $accountStatementHandler = new AccountStatementHandler($this->db);
        return $accountStatementHandler->getStatement($request, $response, ['account_id' => $accountId]);
    }

    private function logAction(string $action, array $details = []): void
    {
        try {
            $tenantId = isset($details['tenant_id']) ? (int) $details['tenant_id'] : null;
            $userId   = isset($details['user_id']) ? (int) $details['user_id'] : null;
            $entityId = isset($details['supplier_id']) ? (int) $details['supplier_id'] : null;

            $this->logger->info('Supplier action logged', [
                'action'      => $action,
                'tenant_id'   => $tenantId,
                'user_id'     => $userId,
                'supplier_id' => $entityId,
            ]);

            $this->audit->logAction(
                $action,
                'suppliers',
                $entityId,
                $details,
                $tenantId,
                $userId
            );
        } catch (\Throwable $e) {
            // ignore logging errors
        }
    }

    /**
     * Retrieves all active suppliers for a specific tenant, with their correct balance.
     * The balance is calculated from the journal entries linked to the supplier's specific account.
     */
    public function getSuppliers(Request $request, Response $response)
    {
        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
        }

        $queryParams = $request->getQueryParams();
        $branchId = isset($queryParams['branch_id']) && $queryParams['branch_id'] !== ''
            ? (int) $queryParams['branch_id']
            : null;

        $sql = "
            SELECT
                s.id,
                s.tenant_id,
                s.branch_id,
                s.name,
                s.phone,
                s.email,
                s.tax_number,
                s.address,
                s.active,
                s.discount_percentage,
                s.credit_limit,
                s.account_id,
                (
                    COALESCE((
                        SELECT SUM(jel.credit_amount - jel.debit_amount)
                        FROM journal_entry_lines jel
                        WHERE jel.account_id = s.account_id
                          AND jel.tenant_id = s.tenant_id
                    ), 0)
                ) AS balance
            FROM suppliers s
            WHERE s.active = 1
              AND s.tenant_id = :tenant_id
        ";

        $bindings = [':tenant_id' => $tenantId];

        if ($branchId !== null) {
            $sql .= ' AND (s.branch_id = :branch_id OR s.branch_id IS NULL)';
            $bindings[':branch_id'] = $branchId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($bindings);
        $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $this->jsonResponse($response, [
            'status' => 'success',
            'message' => '',
            'data' => $suppliers
        ], 200);
    }

    /**
     * Gets or creates the tenant-wide supplier used for cash purchases.
     */
    public function ensureCashSupplier(Request $request, Response $response): Response
    {
        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
        }

        try {
            $this->db->beginTransaction();

            $tenantStmt = $this->db->prepare('SELECT id FROM tenants WHERE id = ? FOR UPDATE');
            $tenantStmt->execute([$tenantId]);
            if (!$tenantStmt->fetchColumn()) {
                throw new \RuntimeException('Tenant not found');
            }

            $supplierStmt = $this->db->prepare("
                SELECT id, tenant_id, branch_id, name, phone, email, address, active,
                       discount_percentage, credit_limit, account_id
                FROM suppliers
                WHERE tenant_id = ? AND name = ? AND branch_id IS NULL AND active = 1
                ORDER BY id ASC
                LIMIT 1
                FOR UPDATE
            ");
            $supplierStmt->execute([$tenantId, 'مورد نقدي']);
            $supplier = $supplierStmt->fetch(PDO::FETCH_ASSOC);
            $created = false;

            if (!$supplier) {
                $supplier = [
                    'tenant_id' => $tenantId,
                    'branch_id' => null,
                    'name' => 'مورد نقدي',
                    'phone' => null,
                    'email' => '',
                    'address' => '',
                    'active' => 1,
                    'discount_percentage' => 0,
                    'credit_limit' => 0,
                    'account_id' => null,
                ];
            }

            if (empty($supplier['account_id'])) {
                $accountMgmt = new AccountManagementService($this->db);
                $accountId = $accountMgmt->createPartyAccount('supplier', 'مورد نقدي', $tenantId);
                if (!$accountId) {
                    throw new \RuntimeException('Failed to create the cash supplier account');
                }

                $supplier['account_id'] = $accountId;
            }

            if (empty($supplier['id'])) {
                $insert = $this->db->prepare("
                    INSERT INTO suppliers (
                        tenant_id, branch_id, name, phone, email, address,
                        active, discount_percentage, credit_limit, account_id
                    ) VALUES (?, NULL, ?, NULL, '', '', 1, 0, 0, ?)
                ");
                $insert->execute([$tenantId, 'مورد نقدي', $supplier['account_id']]);
                $supplier['id'] = (int) $this->db->lastInsertId();
                $created = true;
            } else {
                $update = $this->db->prepare('UPDATE suppliers SET account_id = ? WHERE id = ? AND tenant_id = ?');
                $update->execute([$supplier['account_id'], $supplier['id'], $tenantId]);
            }

            $this->db->commit();

            if ($created) {
                $this->logAction('create_cash_supplier', [
                    'tenant_id' => $tenantId,
                    'user_id' => $this->extractUserId($request),
                    'supplier_id' => (int) $supplier['id'],
                    'name' => 'مورد نقدي',
                ]);
            }

            return $this->jsonResponse($response, [
                'status' => 'success',
                'message' => '',
                'data' => $supplier,
            ], $created ? 201 : 200);
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            $this->logger->error('Failed to ensure cash supplier', [
                'tenant_id' => $tenantId,
                'message' => $e->getMessage(),
            ]);

            return $this->errorResponse($response, 'تعذر تجهيز المورد النقدي.', 500);
        }
    }

    /**
     * Creates a new supplier and its corresponding sub-account in the chart of accounts.
     */
    public function createSupplier(Request $request, Response $response): Response
    {
        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
        }

        $data = $request->getParsedBody() ?? [];
        if (!is_array($data)) {
            $data = [];
        }

        $branchId = isset($data['branch_id']) && $data['branch_id'] !== '' && $data['branch_id'] !== null
            ? (int) $data['branch_id']
            : null;
        if ($branchId !== null) {
            $branchStmt = $this->db->prepare(
                'SELECT id FROM branches WHERE id = ? AND tenant_id = ? AND (active = 1 OR active IS NULL) LIMIT 1'
            );
            $branchStmt->execute([$branchId, $tenantId]);
            if (!$branchStmt->fetchColumn()) {
                return $this->errorResponse($response, 'الفرع المحدد غير موجود أو غير نشط ضمن هذا المستأجر.', 400);
            }
        }

        try {
            $this->db->beginTransaction();

            // Use single source of truth: AccountManagementService::createPartyAccount()
            $accountMgmt = new AccountManagementService($this->db);
            $accountId = $accountMgmt->createPartyAccount('supplier', $data['name'] ?? '', $tenantId);
            if (!$accountId) {
                throw new \Exception('Failed to create a sub-account for the supplier.');
            }

            $sql = "
                INSERT INTO suppliers (
                    tenant_id, branch_id, name, phone, email, tax_number, address,
                    active, discount_percentage, credit_limit, account_id
                ) VALUES (
                    :tenant_id, :branch_id, :name, :phone, :email, :tax_number, :address,
                    :active, :discount_percentage, :credit_limit, :account_id
                )
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':tenant_id' => $tenantId,
                ':branch_id' => $branchId,
                ':name' => $data['name'] ?? null,
                ':phone' => $data['phone'] ?? null,
                ':email' => $data['email'] ?? null,
                ':tax_number' => $data['tax_number'] ?? null,
                ':address' => $data['address'] ?? null,
                ':active' => $data['active'] ?? 1,
                ':discount_percentage' => $data['discount_percentage'] ?? 0,
                ':credit_limit' => $data['credit_limit'] ?? 0,
                ':account_id' => $accountId
            ]);

            $data['id'] = $this->db->lastInsertId();

            $this->db->commit();

            return $this->jsonResponse($response, [
                'status' => 'success',
                'message' => 'تم إنشاء المورد وحسابه بنجاح',
                'data' => $data
            ], 201);
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            $this->logger->error('Failed to create supplier', [
                'message' => $e->getMessage()
            ]);

            return $this->errorResponse($response, 'فشل في إنشاء المورد', 500);
        }
    }

    /**
     * Updates an existing supplier's information.
     */
    public function updateSupplier(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
        }

        $data = $request->getParsedBody() ?? [];
        if (!is_array($data)) {
            $data = [];
        }

        $userId = $this->extractUserId($request);

        try {
            $this->db->beginTransaction();

            $branchId = isset($data['branch_id']) && $data['branch_id'] !== '' && $data['branch_id'] !== null
                ? (int) $data['branch_id']
                : null;

            $sql = "
                UPDATE suppliers
                SET branch_id = :branch_id,
                    name = :name,
                    phone = :phone,
                    email = :email,
                    tax_number = :tax_number,
                    address = :address,
                    active = :active,
                    discount_percentage = :discount_percentage,
                    credit_limit = :credit_limit
                WHERE id = :id
                  AND tenant_id = :tenant_id
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':branch_id' => $branchId,
                ':name' => $data['name'] ?? null,
                ':phone' => $data['phone'] ?? null,
                ':email' => $data['email'] ?? null,
                ':tax_number' => $data['tax_number'] ?? null,
                ':address' => $data['address'] ?? null,
                ':active' => $data['active'] ?? 1,
                ':discount_percentage' => $data['discount_percentage'] ?? 0,
                ':credit_limit' => $data['credit_limit'] ?? 0,
                ':id' => $id,
                ':tenant_id' => $tenantId
            ]);

            $this->db->commit();

            try {
                $this->logAction('supplier_updated', [
                    'supplier_id' => $id,
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'supplier_name' => $data['name'] ?? null
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('logAction failed', ['message' => $e->getMessage()]);
            }

            $this->logger->info('Supplier updated successfully', [
                'tenant_id' => $tenantId,
                'supplier_id' => $id,
                'user_id' => $userId
            ]);

            return $this->jsonResponse($response, [
                'status' => 'success',
                'message' => "تم تحديث المورد #$id بنجاح",
                'data' => $data
            ]);
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                try {
                    $this->db->rollBack();
                    $this->logger->info('Supplier update transaction rolled back successfully', [
                        'tenant_id' => $tenantId,
                        'supplier_id' => $id
                    ]);
                } catch (\Throwable $rollbackError) {
                    $this->logger->error('Error during rollback', [
                        'message' => $rollbackError->getMessage()
                    ]);
                }
            }

            $this->logger->error('Supplier update failed', [
                'message' => $e->getMessage(),
                'tenant_id' => $tenantId,
                'supplier_id' => $id
            ]);

            return $this->errorResponse($response, 'فشل في تحديث المورد', 500);
        }
    }

    /**
     * Deletes a supplier and its associated sub-account, only if there are no active transactions.
     */
    public function deleteSupplier(Request $request, Response $response, array $args): Response
    {
        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
        }

        try {
            if (!isset($args['id'])) {
                return $this->errorResponse($response, 'مطلوب رقم المورد', 400);
            }

            $id = $args['id'];

            $this->db->beginTransaction();

            $stmt = $this->db->prepare(
                "SELECT account_id
                 FROM suppliers
                 WHERE id = :id AND tenant_id = :tenant_id"
            );
            $stmt->execute([
                ':id' => $id,
                ':tenant_id' => $tenantId
            ]);
            $supplierAccountId = $stmt->fetchColumn();

            if (!$supplierAccountId) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                return $this->errorResponse($response, 'لم يتم العثور على المورد', 403);
            }

            $stmt = $this->db->prepare(
                "SELECT COUNT(*)
                 FROM journal_entry_lines
                 WHERE account_id = :account_id
                   AND tenant_id = :tenant_id"
            );
            $stmt->execute([
                ':account_id' => $supplierAccountId,
                ':tenant_id' => $tenantId
            ]);
            $count = $stmt->fetchColumn();

            if ($count > 0) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                return $this->errorResponse(
                    $response,
                    'لا يمكن حذف المورد لوجود حركات محاسبية مرتبطة به. سدّد المبلغ المستحق للمورد إن وُجد، ثم عطّل حسابه بدلًا من حذفه للحفاظ على السجل المالي.',
                    409
                );
            }

            $stmt = $this->db->prepare(
                "DELETE FROM accounts
                 WHERE id = :account_id AND tenant_id = :tenant_id"
            );
            $stmt->execute([
                ':account_id' => $supplierAccountId,
                ':tenant_id' => $tenantId
            ]);

            $stmt = $this->db->prepare(
                "DELETE FROM suppliers
                 WHERE id = :id AND tenant_id = :tenant_id"
            );
            if (!$stmt->execute([
                ':id' => $id,
                ':tenant_id' => $tenantId
            ])) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                throw new \Exception('Failed to delete supplier');
            }

            $this->db->commit();

            try {
                $this->logAction('supplier_deleted', [
                    'supplier_id' => $id,
                    'tenant_id' => $tenantId
                ]);
            } catch (\Exception $e) {
                $this->logger->error('logAction failed', [
                    'message' => $e->getMessage()
                ]);
            }

            return $this->jsonResponse($response, [
                'status' => 'success',
                'message' => 'تم حذف المورد وحسابه بنجاح'
            ]);
        } catch (\Exception $e) {
            if ($this->db->inTransaction()) {
                try {
                    $this->db->rollBack();
                    $this->logger->info('Supplier deletion transaction rolled back successfully', [
                        'tenant_id' => $tenantId,
                        'supplier_id' => $id
                    ]);
                } catch (\Throwable $rollbackError) {
                    $this->logger->error('Error during rollback', [
                        'message' => $rollbackError->getMessage(),
                        'tenant_id' => $tenantId
                    ]);
                }
            }

            $this->logger->error('Supplier deletion failed', [
                'message' => $e->getMessage(),
                'tenant_id' => $tenantId,
                'supplier_id' => $id
            ]);

            return $this->errorResponse($response, 'فشل في حذف المورد', 500);
        }
    }


}
