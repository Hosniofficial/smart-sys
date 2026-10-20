<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use App\Services\MonologHandler;
use Throwable;

/**
 * Creates and manages tenant ledger accounts for parties, branches, contacts,
 * and liquidity methods.
 *
 * Uses PDO for account reads/writes and MonologHandler for operational logs.
 */
class AccountManagementService
{
    private PDO $db;
    private $logger;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->logger = MonologHandler::getInstance('account_management');
    }

    /**
     * Create a unified party account (customer, supplier, employee, etc.)
     *
     * @param string $partyType Must be one of: 'customer', 'supplier', 'employee'
     * @param string $partyName Display name for the party
     * @param int $tenantId
     * @param ?string $customCode Optional custom account code (if null, auto-generated)
     * @param ?int $parentAccountId Optional parent account ID (if null, resolved from type)
     *
     * @return ?int Created account ID, or null on failure
     */
    public function createPartyAccount(
        string $partyType,
        string $partyName,
        int $tenantId,
        ?string $customCode = null,
        ?int $parentAccountId = null
    ): ?int {
        try {
            $inTransaction = $this->db->inTransaction();
            if (!$inTransaction) {
                $this->db->beginTransaction();
            }

            $typeMapping = [
                'customer' => ['code' => '1101', 'type' => 'asset', 'label' => 'حساب العميل'],
                'supplier' => ['code' => '2101', 'type' => 'liability', 'label' => 'حساب المورد'],
                'employee' => ['code' => '1201', 'type' => 'asset', 'label' => 'حساب الموظف'],
            ];

            if (!isset($typeMapping[$partyType])) {
                throw new \InvalidArgumentException("Invalid party type: {$partyType}");
            }

            $mapping = $typeMapping[$partyType];

            if (!$parentAccountId) {
                $parentAccountId = $this->getAccountIdByCode($mapping['code'], $tenantId);
                if (!$parentAccountId) {
                    $this->logger->error('Parent account not found for party type', [
                        'party_type' => $partyType,
                        'parent_code' => $mapping['code'],
                        'tenant_id' => $tenantId
                    ]);
                    if (!$inTransaction) {
                        $this->db->rollBack();
                    }
                    return null;
                }
            }

            if (!$customCode) {
                $customCode = $this->generateAccountCodeForParent(
                    $tenantId,
                    $parentAccountId,
                    $mapping['code']
                );
            }

            $accountName = "{$mapping['label']}: {$partyName}";

            $stmt = $this->db->prepare("
                INSERT INTO accounts (
                    tenant_id,
                    parent_id,
                    code,
                    name,
                    type,
                    debit_balance,
                    credit_balance,
                    is_active
                ) VALUES (?, ?, ?, ?, ?, 0, 0, 1)
            ");
            $stmt->execute([
                $tenantId,
                $parentAccountId,
                $customCode,
                $accountName,
                $mapping['type']
            ]);

            $accountId = (int) $this->db->lastInsertId();

            $this->logger->info('Party account created successfully', [
                'party_type' => $partyType,
                'party_name' => $partyName,
                'account_id' => $accountId,
                'account_code' => $customCode,
                'tenant_id' => $tenantId
            ]);

            if (!$inTransaction) {
                $this->db->commit();
            }
            return $accountId;

        } catch (Throwable $e) {
            try {
                if (!($inTransaction ?? false) && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }
            } catch (Throwable $rollbackErr) {
                $this->logger->error('Rollback failed in createPartyAccount', [
                    'error' => $rollbackErr->getMessage()
                ]);
            }

            $this->logger->error('Failed to create party account', [
                'party_type' => $partyType,
                'party_name' => $partyName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return null;
        }
    }

    /**
     * Create a branch inventory sub-account under inventory account 1301.
     *
     * @param string $branchName
     * @param int $tenantId
     * @param ?string $location Optional branch location description
     *
     * @return ?int Created account ID, or null on failure
     */
    public function createBranchAccount(
        string $branchName,
        int $tenantId,
        ?string $location = null
    ): ?int {
        try {
            $inTransaction = $this->db->inTransaction();
            if (!$inTransaction) {
                $this->db->beginTransaction();
            }

            $parentAccountId = $this->getAccountIdByCode('1301', $tenantId);
            if (!$parentAccountId) {
                $this->logger->error('Parent account 1301 not found for branch inventory', [
                    'tenant_id' => $tenantId,
                    'branch_name' => $branchName
                ]);
                if (!$inTransaction) {
                    $this->db->rollBack();
                }
                return null;
            }

            $accountCode = $this->generateAccountCodeForParent(
                $tenantId,
                $parentAccountId,
                '1301-'
            );

            $accountName = "مخزون - {$branchName}";
            $stmt = $this->db->prepare("
                INSERT INTO accounts (
                    tenant_id,
                    parent_id,
                    code,
                    name,
                    type,
                    debit_balance,
                    credit_balance,
                    is_active
                ) VALUES (?, ?, ?, ?, 'asset', 0, 0, 1)
            ");
            $stmt->execute([
                $tenantId,
                $parentAccountId,
                $accountCode,
                $accountName
            ]);

            $accountId = (int) $this->db->lastInsertId();

            $this->logger->info('Branch account created successfully', [
                'branch_name' => $branchName,
                'account_id' => $accountId,
                'account_code' => $accountCode,
                'tenant_id' => $tenantId
            ]);

            if (!$inTransaction) {
                $this->db->commit();
            }
            return $accountId;

        } catch (Throwable $e) {
            try {
                if (!($inTransaction ?? false) && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }
            } catch (Throwable $rollbackErr) {
                $this->logger->error('Rollback failed in createBranchAccount', [
                    'error' => $rollbackErr->getMessage()
                ]);
            }

            $this->logger->error('Failed to create branch account', [
                'branch_name' => $branchName,
                'error' => $e->getMessage()
            ]);

            return null;
        }
    }

    /**
     * Create a contact sub-account under the supplied parent account.
     *
     * @return ?int Created account ID, or null when creation fails.
     */
    public function createContactAccount(
        int $contactId,
        string $contactName,
        int $parentAccountId,
        int $tenantId,
        ?string $accountType = 'asset'
    ): ?int {
        try {
            $inTransaction = $this->db->inTransaction();
            if (!$inTransaction) {
                $this->db->beginTransaction();
            }

            $accountCode = $this->generateAccountCodeForParent(
                $tenantId,
                $parentAccountId,
                null // Will use parent code + suffix
            );

            $stmt = $this->db->prepare("
                INSERT INTO accounts (
                    code,
                    name,
                    parent_id,
                    tenant_id,
                    created_at,
                    type
                ) VALUES (?, ?, ?, ?, NOW(), ?)
            ");
            $stmt->execute([
                $accountCode,
                $contactName,
                $parentAccountId,
                $tenantId,
                $accountType
            ]);

            $accountId = (int) $this->db->lastInsertId();

            $this->logger->info('Contact account created successfully', [
                'contact_id' => $contactId,
                'contact_name' => $contactName,
                'account_id' => $accountId,
                'account_code' => $accountCode,
                'tenant_id' => $tenantId
            ]);

            if (!$inTransaction) {
                $this->db->commit();
            }
            return $accountId;

        } catch (Throwable $e) {
            try {
                if (!($inTransaction ?? false) && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }
            } catch (Throwable $rollbackErr) {
                $this->logger->error('Rollback failed in createContactAccount', [
                    'error' => $rollbackErr->getMessage()
                ]);
            }

            $this->logger->error('Failed to create contact account', [
                'contact_id' => $contactId,
                'contact_name' => $contactName,
                'error' => $e->getMessage()
            ]);

            return null;
        }
    }

    /**
     * Return an existing liquidity account by code or create a new one.
     */
    public function provisionLiquidityAccount(
        string $code,
        string $name,
        string $kind,
        int    $tenantId
    ): int {
        $existing = $this->getAccountIdByCode($code, $tenantId);
        if ($existing) {
            $this->logger->info('provisionLiquidityAccount: account already exists', [
                'code'      => $code,
                'tenant_id' => $tenantId,
                'account_id' => $existing,
            ]);
            return $existing;
        }

        $cashAccountId = $this->getAccountIdByCode('1001', $tenantId);
        if (!$cashAccountId) {
            throw new \Exception("حساب الصندوق (1001) غير موجود للـ tenant {$tenantId} — لا يمكن إنشاء حساب {$name}.");
        }

        $stmtParent = $this->db->prepare(
            "SELECT parent_id FROM accounts WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL) LIMIT 1"
        );
        $stmtParent->execute([$cashAccountId, $tenantId]);
        $parentId = $stmtParent->fetchColumn();

        if (!$parentId) {
            throw new \Exception("لم يتم العثور على والد حساب الصندوق (1001) — يرجى مراجعة شجرة الحسابات.");
        }

        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare("
                INSERT INTO accounts (
                    tenant_id, parent_id, code, name, type,
                    debit_balance, credit_balance, is_active
                ) VALUES (?, ?, ?, ?, 'asset', 0, 0, 1)
            ");
            $stmt->execute([$tenantId, $parentId, $code, $name]);
            $accountId = (int) $this->db->lastInsertId();

            if (!$accountId) {
                $this->db->rollBack();
                throw new \Exception("فشل إنشاء حساب {$name} ({$code}).");
            }

            $this->db->commit();

            $this->logger->info('provisionLiquidityAccount: account created', [
                'code'       => $code,
                'name'       => $name,
                'kind'       => $kind,
                'account_id' => $accountId,
                'parent_id'  => $parentId,
                'tenant_id'  => $tenantId,
            ]);

            return $accountId;

        } catch (\Throwable $e) {
            try {
                $this->db->rollBack();
            } catch (\Throwable $re) {
            }
            $this->logger->error('provisionLiquidityAccount: failed', [
                'code'  => $code,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Delete a tenant account after checking child accounts and journal lines.
     *
     * @return bool Whether the delete statement completed without an exception.
     */
    public function deleteAccount(int $accountId, int $tenantId, bool $forceDelete = false): bool
    {
        try {
            $this->db->beginTransaction();

            $stmtChild = $this->db->prepare("
                SELECT COUNT(*) FROM accounts 
                WHERE parent_id = ? AND tenant_id = ?
            ");
            $stmtChild->execute([$accountId, $tenantId]);
            $childCount = (int) $stmtChild->fetchColumn();

            if ($childCount > 0 && !$forceDelete) {
                $this->logger->warning('Cannot delete account with child accounts', [
                    'account_id' => $accountId,
                    'child_count' => $childCount
                ]);
                $this->db->rollBack();
                return false;
            }

            $stmtLines = $this->db->prepare("
                SELECT COUNT(*) FROM journal_entry_lines 
                WHERE account_id = ? AND tenant_id = ?
            ");
            $stmtLines->execute([$accountId, $tenantId]);
            $lineCount = (int) $stmtLines->fetchColumn();

            if ($lineCount > 0 && !$forceDelete) {
                $this->logger->warning('Cannot delete account with journal entries', [
                    'account_id' => $accountId,
                    'line_count' => $lineCount
                ]);
                $this->db->rollBack();
                return false;
            }

            $stmtDel = $this->db->prepare("
                DELETE FROM accounts 
                WHERE id = ? AND tenant_id = ?
            ");
            $stmtDel->execute([$accountId, $tenantId]);

            $this->logger->info('Account deleted successfully', [
                'account_id' => $accountId,
                'tenant_id' => $tenantId
            ]);

            $this->db->commit();
            return true;

        } catch (Throwable $e) {
            try {
                $this->db->rollBack();
            } catch (Throwable $rollbackErr) {
                $this->logger->error('Rollback failed in deleteAccount', [
                    'error' => $rollbackErr->getMessage()
                ]);
            }

            $this->logger->error('Failed to delete account', [
                'account_id' => $accountId,
                'tenant_id' => $tenantId,
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    /**
     * Find an account by code, preferring a tenant-owned row over a global row.
     */
    public function getAccountIdByCode(string $code, int $tenantId): ?int
    {
        $stmt = $this->db->prepare("
            SELECT id FROM accounts 
            WHERE code = ? AND (tenant_id = ? OR tenant_id IS NULL)
            ORDER BY tenant_id DESC
            LIMIT 1
        ");
        $stmt->execute([$code, $tenantId]);
        $id = $stmt->fetchColumn();
        return $id ? (int) $id : null;
    }

    /**
     * Generate a child code from existing siblings, with a fallback on query errors.
     */
    private function generateAccountCodeForParent(
        int $tenantId,
        int $parentAccountId,
        ?string $prefixHint = null
    ): string {
        try {
            $stmtParent = $this->db->prepare("
                SELECT code FROM accounts 
                WHERE id = ? AND tenant_id = ?
            ");
            $stmtParent->execute([$parentAccountId, $tenantId]);
            $parentCode = $stmtParent->fetchColumn();

            if (!$parentCode) {
                throw new \RuntimeException('Parent account not found');
            }

            $prefix = $prefixHint ?? ($parentCode . '-');

            $stmtCodes = $this->db->prepare("
                SELECT code FROM accounts 
                WHERE tenant_id = ? AND parent_id = ? AND code LIKE ?
            ");
            $stmtCodes->execute([$tenantId, $parentAccountId, $prefix . '%']);
            $codes = $stmtCodes->fetchAll(PDO::FETCH_COLUMN) ?: [];

            $maxSuffix = 0;
            $prefixLen = strlen(str_replace('-', '', $prefix));

            foreach ($codes as $code) {
                $cleanCode = str_replace('-', '', (string) $code);
                $suffix = (int) substr($cleanCode, $prefixLen);
                if ($suffix > $maxSuffix) {
                    $maxSuffix = $suffix;
                }
            }

            $nextSuffix = $maxSuffix + 1;
            $paddedSuffix = str_pad((string) $nextSuffix, 4, '0', STR_PAD_LEFT);

            return $prefix . $paddedSuffix;

        } catch (Throwable $e) {
            $this->logger->error('Failed to generate account code', [
                'tenant_id' => $tenantId,
                'parent_account_id' => $parentAccountId,
                'error' => $e->getMessage()
            ]);

            return ($prefixHint ?? 'ACC') . '-' . date('YmdHis');
        }
    }

    /**
     * Check whether an account belongs to the specified tenant.
     */
    public function accountExists(int $accountId, int $tenantId): bool
    {
        $stmt = $this->db->prepare("
            SELECT 1 FROM accounts 
            WHERE id = ? AND tenant_id = ?
        ");
        $stmt->execute([$accountId, $tenantId]);
        return (bool) $stmt->fetchColumn();
    }
}
