<?php

declare(strict_types=1);

namespace App\Handlers;

use PDO;
use Throwable;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Services\AccountManagementService;
use App\Utils\PaginationHelper;

/**
 * Shared contact listing and ledger-account helpers for customer/supplier handlers.
 *
 * Concrete subclasses set contactType, which selects the tenant table and
 * contact-specific account mappings.
 */
abstract class BaseContactHandler extends BaseHandler
{
    protected string $contactType; // 'customer' or 'supplier'

    /** Accept the legacy Database wrapper or PDO and initialize the shared handler. */
    public function __construct($db)
    {
        parent::__construct($db instanceof \Database ? $db->pdo : $db);
    }

    /**
     * Admin helper: list contacts missing linked sub-accounts (account_id).
     */
    public function listMissingAccounts(Request $request, Response $response): Response
    {
        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID)', 403);
        }

        $table = $this->getTableName();
        $sql = "
            SELECT id, name, phone, email, account_id
            FROM {$table}
            WHERE tenant_id = :tenant_id
              AND (account_id IS NULL OR account_id = 0)
            ORDER BY id ASC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':tenant_id' => $tenantId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return $this->successResponse($response, [
            'count' => count($rows),
            'items' => $rows
        ], 200);
    }

    /** Use AccountManagementService to create and link a missing sub-account transactionally. */
    public function ensureAccount(Request $request, Response $response, array $args): Response
    {
        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
        }

        $id = isset($args['id']) ? (int) $args['id'] : 0;
        if ($id <= 0) {
            return $this->errorResponse($response, 'Contact ID is required', 400);
        }

        $table = $this->getTableName();

        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare("
                SELECT id, name, account_id
                FROM {$table}
                WHERE id = ? AND tenant_id = ?
                FOR UPDATE
            ");
            $stmt->execute([$id, $tenantId]);
            $contact = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$contact) {
                throw new \Exception('Contact not found');
            }

            if (!empty($contact['account_id'])) {
                $this->db->commit();

                return $this->successResponse($response, [
                    'account_id' => (int) $contact['account_id']
                ], 200);
            }

            $parentAccountId = $this->getParentAccountId((int) $tenantId);
            if (!$parentAccountId) {
                throw new \RuntimeException('Parent account not found for this contact type');
            }

            $accountMgmt = new AccountManagementService($this->db);
            $accountId = $accountMgmt->createContactAccount(
                $id,
                $contact['name'],
                $parentAccountId,
                $tenantId,
                $this->getLedgerAccountType()
            );

            if (!$accountId) {
                throw new \Exception('Failed to create contact account');
            }

            $stmtGetCode = $this->db->prepare("SELECT code FROM accounts WHERE id = ? AND tenant_id = ?");
            $stmtGetCode->execute([$accountId, $tenantId]);
            $accountCode = $stmtGetCode->fetchColumn();

            $stmt = $this->db->prepare("
                UPDATE {$table}
                SET account_id = ?
                WHERE id = ? AND tenant_id = ?
            ");
            $stmt->execute([$accountId, $id, $tenantId]);

            $this->db->commit();

            return $this->successResponse($response, [
                'contact_id' => $id,
                'account_id' => $accountId,
                'account_code' => $accountCode
            ], 200);
        } catch (Throwable $e) {
            if ($this->db && $this->db->inTransaction()) {
                $this->db->rollBack();
            }

            return $this->errorResponse(
                $response,
                'Failed to create account: ' . $e->getMessage(),
                400
            );
        }
    }

    /** Return the contact table selected by the concrete handler's contactType. */
    protected function getTableName(): string
    {
        return $this->contactType === 'supplier' ? 'suppliers' : 'customers';
    }

    /** Return the application-level contact type used by account creation. */
    protected function getAccountType(): string
    {
        return $this->contactType === 'supplier' ? 'supplier' : 'customer';
    }

    /** Return the ledger account classification for this contact type. */
    protected function getLedgerAccountType(): string
    {
        return $this->contactType === 'supplier' ? 'liability' : 'asset';
    }

    /** Generate the next tenant account code using the contact type's numeric prefix. */
    protected function generateAccountCode(int $tenantId): string
    {
        $prefix = $this->contactType === 'supplier' ? '200' : '100';

        $stmt = $this->db->prepare("
            SELECT MAX(CAST(SUBSTRING(REPLACE(code, '-', ''), 4) AS UNSIGNED)) AS max_num
            FROM accounts
            WHERE tenant_id = ?
              AND REPLACE(code, '-', '') LIKE ?
        ");
        $stmt->execute([$tenantId, $prefix . '%']);
        $maxNum = (int) ($stmt->fetchColumn() ?: 0);

        return $prefix . str_pad((string) ($maxNum + 1), 4, '0', STR_PAD_LEFT);
    }

    /** Find the tenant's receivables or payables parent account. */
    protected function getParentAccountId(int $tenantId): ?int
    {
        $parentCode = $this->contactType === 'supplier' ? '2100' : '1100';

        $stmt = $this->db->prepare("
            SELECT id
            FROM accounts
            WHERE tenant_id = ?
              AND code = ?
            LIMIT 1
        ");
        $stmt->execute([$tenantId, $parentCode]);

        $value = $stmt->fetchColumn();
        return $value !== false ? (int) $value : null;
    }

    /** Validate a required name and optional email and phone formats. */
    protected function validateContactData(array $data): array
    {
        $errors = [];

        if (empty($data['name'])) {
            $errors[] = 'Name is required';
        }

        if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email format';
        }

        if (!empty($data['phone']) && !preg_match('/^[0-9+\-\s()]+$/', (string) $data['phone'])) {
            $errors[] = 'Invalid phone format';
        }

        return $errors;
    }

    /** Return paginated tenant contacts, optionally searched by name, phone, or email. */
    protected function listContacts(Request $request, Response $response): Response
    {
        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
        }

        $params = $request->getQueryParams();
        [$page, $perPage, $offset] = PaginationHelper::fromArray($params, 20, 100);
        $search = trim((string) ($params['search'] ?? ''));

        $table = $this->getTableName();
        $where = ['tenant_id = ?'];
        $bindings = [$tenantId];

        if ($search !== '') {
            $where[] = '(name LIKE ? OR phone LIKE ? OR email LIKE ?)';
            $searchTerm = '%' . $search . '%';
            $bindings[] = $searchTerm;
            $bindings[] = $searchTerm;
            $bindings[] = $searchTerm;
        }

        $whereClause = implode(' AND ', $where);

        $countSql = "SELECT COUNT(*) FROM {$table} WHERE {$whereClause}";
        $stmt = $this->db->prepare($countSql);
        $stmt->execute($bindings);
        $total = (int) $stmt->fetchColumn();

        $sql = "SELECT * FROM {$table} WHERE {$whereClause} ORDER BY id DESC LIMIT ? OFFSET ?";
        $stmt = $this->db->prepare($sql);

        $index = 1;
        foreach ($bindings as $binding) {
            $stmt->bindValue($index++, $binding);
        }
        $stmt->bindValue($index++, $perPage, PDO::PARAM_INT);
        $stmt->bindValue($index, $offset, PDO::PARAM_INT);

        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return $this->successResponse($response, [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'pages' => $perPage > 0 ? (int) ceil($total / $perPage) : 0
            ]
        ], 200);
    }
}
