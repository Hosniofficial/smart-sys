<?php

declare(strict_types=1);

namespace App\Handlers;

use PDO;
use Exception;
use Throwable;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Services\MonologHandler;
use App\Services\AccountManagementService;

/**
 * Handles the tenant-owner setup workflow.
 *
 * Reads tenant settings and branches, persists setup sections and progress in a
 * transaction, and provisions branch accounting records through
 * AccountManagementService when a branch is created. Each endpoint requires
 * authenticated tenant context and verifies tenant ownership. Routes are
 * registered in app/routes/setup.php.
 */
class SetupHandler extends BaseHandler
{
    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->logger = MonologHandler::getInstance('setup');
    }

    /** Return setup progress, grouped settings, and tenant branches to the owner. */
    public function getSetupStatus(Request $request, Response $response): Response
    {
        try {
            $context = $this->requireTenantContext($request);
            $tenantId = $context['tenant_id'];
            $userId = $context['user_id'];

            if (!$userId) {
                $this->logger->warning('Setup status - missing user ID', [
                    'tenant_id' => $tenantId
                ]);
                return $this->errorResponse($response, 'مطلوب معرف المستخدم.', 403);
            }

            $this->logger->info('Setup status request', [
                'tenant_id' => $tenantId,
                'user_id' => $userId
            ]);

            if (!$this->isOwner((int) $tenantId, (int) $userId)) {
                $this->logger->warning('Setup status access denied - not owner', [
                    'tenant_id' => $tenantId,
                    'user_id' => $userId
                ]);

                return $this->errorResponse($response, 'غير مصرح لك بالوصول لصفحة الإعداد.', 403);
            }

            $this->logger->debug('Fetching tenant data', [
                'tenant_id' => $tenantId
            ]);

            $stmt = $this->db->prepare(
                "SELECT id, company_name, logo_url, is_setup_complete, current_step, setup_completed_at, created_at
                 FROM tenants
                 WHERE id = ?
                 LIMIT 1"
            );
            $stmt->execute([(int) $tenantId]);
            $tenant = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$tenant) {
                $this->logger->error('Tenant not found', [
                    'tenant_id' => $tenantId
                ]);
                return $this->errorResponse($response, 'لم يتم العثور على المستأجر.', 404);
            }

            $this->logger->debug('Fetching tenant settings', [
                'tenant_id' => $tenantId
            ]);
            $settings = $this->getSettings((int) $tenantId);

            $this->logger->debug('Fetching tenant branches', [
                'tenant_id' => $tenantId
            ]);

            $branchesStmt = $this->db->prepare(
                "SELECT id, name, location, active
                 FROM branches
                 WHERE tenant_id = ? OR tenant_id IS NULL
                 ORDER BY active DESC, name ASC"
            );
            $branchesStmt->execute([(int) $tenantId]);
            $branches = $branchesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $this->logger->info('Setup status retrieved successfully', [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'setup_complete' => $tenant['is_setup_complete'] ?? 0,
                'current_step' => $tenant['current_step'] ?? null,
                'branches_count' => count($branches)
            ]);

            return $this->successResponse($response, [
                'tenant' => $tenant,
                'settings' => $settings,
                'branches' => $branches
            ], 200);
        } catch (Exception $e) {
            $code = (int) $e->getCode();
            $status = $code >= 400 && $code < 600 ? $code : 500;

            $this->logger->error('Setup status error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'code' => $code
            ]);

            return $this->errorResponse($response, 'فشل في جلب حالة الإعداد', $status);
        }
    }

    /** Persist supported setup sections, branch creation, and setup progress atomically. */
    public function saveSetup(Request $request, Response $response): Response
    {
        try {
            $context = $this->requireTenantContext($request);
            $tenantId = $context['tenant_id'];
            $userId = $context['user_id'];

            if (!$userId) {
                $this->logger->warning('Setup save - missing user ID', [
                    'tenant_id' => $tenantId
                ]);
                return $this->errorResponse($response, 'مطلوب معرف المستخدم.', 403);
            }

            $this->logger->info('Setup save request', [
                'tenant_id' => $tenantId,
                'user_id' => $userId
            ]);

            if (!$this->isOwner((int) $tenantId, (int) $userId)) {
                $this->logger->warning('Setup save access denied - not owner', [
                    'tenant_id' => $tenantId,
                    'user_id' => $userId
                ]);

                return $this->errorResponse($response, 'غير مصرح لك بالوصول لصفحة الإعداد.', 403);
            }

            $rawBody = (string) $request->getBody();
            $data = json_decode($rawBody, true);

            if (!is_array($data)) {
                $this->logger->warning('Setup save - invalid JSON body', [
                    'tenant_id' => $tenantId,
                    'user_id' => $userId
                ]);
                return $this->errorResponse($response, 'بيانات الطلب غير صالحة.', 400);
            }

            $this->logger->debug('Setup data received', [
                'tenant_id' => $tenantId,
                'data_keys' => array_keys($data),
                'current_step' => $data['current_step'] ?? 'unknown'
            ]);

            $this->db->beginTransaction();

            try {
                if (isset($data['company']) && is_array($data['company'])) {
                    $this->logger->debug('Saving company settings', [
                        'tenant_id' => $tenantId
                    ]);
                    $this->saveCompanySettings((int) $tenantId, $data['company']);
                }

                if (isset($data['tax']) && is_array($data['tax'])) {
                    $this->logger->debug('Saving tax settings', [
                        'tenant_id' => $tenantId
                    ]);
                    $this->saveTaxSettings((int) $tenantId, $data['tax']);
                }

                if (isset($data['invoice']) && is_array($data['invoice'])) {
                    $this->logger->debug('Saving invoice settings', [
                        'tenant_id' => $tenantId
                    ]);
                    $this->saveInvoiceSettings((int) $tenantId, $data['invoice']);
                }

                if (isset($data['sessions']) && is_array($data['sessions'])) {
                    $this->saveSessionSettings((int) $tenantId, $data['sessions']);
                }

                if (isset($data['print']) && is_array($data['print'])) {
                    $this->logger->debug('Saving print settings', [
                        'tenant_id' => $tenantId
                    ]);
                    $this->savePrintSettings((int) $tenantId, $data['print']);
                }

                if (isset($data['branch']) && is_array($data['branch']) && !empty($data['branch']['name'])) {
                    $this->logger->debug('Creating branch', [
                        'tenant_id' => $tenantId,
                        'branch_name' => $data['branch']['name']
                    ]);
                    $this->createBranch((int) $tenantId, $data['branch'], (int) $userId);
                }

                $currentStep = isset($data['current_step']) && $data['current_step'] !== ''
                    ? (string) $data['current_step']
                    : 'complete';

                $isComplete = $currentStep === 'complete' ? 1 : 0;

                $this->logger->info('Updating setup status', [
                    'tenant_id' => $tenantId,
                    'current_step' => $currentStep,
                    'is_complete' => $isComplete
                ]);

                $updateTenant = $this->db->prepare(
                    "UPDATE tenants
                     SET current_step = ?,
                         is_setup_complete = ?,
                         setup_completed_at = IF(? = 1, NOW(), setup_completed_at)
                     WHERE id = ?"
                );
                $updateTenant->execute([
                    $currentStep,
                    $isComplete,
                    $isComplete,
                    (int) $tenantId
                ]);

                $this->db->commit();

                $this->logger->info('Setup saved successfully', [
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'current_step' => $currentStep,
                    'is_complete' => $isComplete
                ]);

                return $this->successResponse($response, [
                    'message' => 'تم حفظ الإعدادات بنجاح'
                ], 200);
            } catch (Exception $e) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }

                $this->logger->error('Setup save transaction failed, rolled back', [
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'tenant_id' => $tenantId,
                    'user_id' => $userId
                ]);

                throw $e;
            }
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                try {
                    $this->db->rollBack();
                } catch (Throwable $rollbackErr) {
                    $this->logger->error('Rollback failed', ['message' => $rollbackErr->getMessage()]);
                }
            }

            $code = (int) $e->getCode();
            $status = $code >= 400 && $code < 600 ? $code : 500;

            $this->logger->error('Setup save error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'code' => $code
            ]);

            return $this->errorResponse($response, 'فشل في حفظ الإعدادات', $status);
        }
    }

    private function isOwner(int $tenantId, int $userId): bool
    {
        try {
            $this->logger->debug('Checking user ownership', [
                'tenant_id' => $tenantId,
                'user_id' => $userId
            ]);

            $stmt = $this->db->prepare(
                "SELECT COUNT(*)
                 FROM users
                 WHERE id = ?
                   AND tenant_id = ?
                   AND (
                        -- Roles are tenant-owned; scope this lookup to the same tenant.
                        role_id = (SELECT id FROM roles WHERE name = 'owner' AND tenant_id = ? LIMIT 1)
                        OR is_owner = 1
                   )"
            );
            $stmt->execute([$userId, $tenantId, $tenantId]);
            $isOwner = (int) $stmt->fetchColumn() > 0;

            $this->logger->debug('Ownership check result', [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'is_owner' => $isOwner
            ]);

            return $isOwner;
        } catch (Throwable $e) {
            $this->logger->error('Ownership check error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'tenant_id' => $tenantId,
                'user_id' => $userId
            ]);

            return false;
        }
    }

    /** Load settings and group recognized prefixes for the setup response. */
    private function getSettings(int $tenantId): array
    {
        $stmt = $this->db->prepare(
            "SELECT key_name, value, type
             FROM settings
             WHERE tenant_id = ?
             ORDER BY key_name"
        );
        $stmt->execute([$tenantId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $settings = [
            'company' => [],
            'tax' => [],
            'invoice' => [],
            'print' => [],
            'app' => []
        ];

        foreach ($rows as $row) {
            $key = $row['key_name'];
            $value = $row['value'];
            $type = $row['type'];

            if ($type === 'integer') {
                $value = (int) $value;
            } elseif ($type === 'boolean') {
                $value = (bool) $value;
            } elseif ($type === 'array' || $type === 'object') {
                $value = json_decode($value, true);
            }

            if (strpos($key, 'company.') === 0) {
                $settings['company'][str_replace('company.', '', $key)] = $value;
            } elseif (strpos($key, 'tax.') === 0) {
                $settings['tax'][str_replace('tax.', '', $key)] = $value;
            } elseif (strpos($key, 'invoice.') === 0) {
                $settings['invoice'][str_replace('invoice.', '', $key)] = $value;
            } elseif (strpos($key, 'print.') === 0) {
                $settings['print'][str_replace('print.', '', $key)] = $value;
            } elseif (strpos($key, 'app.') === 0) {
                $settings['app'][str_replace('app.', '', $key)] = $value;
            } else {
                $settings[$key] = $value;
            }
        }

        return $settings;
    }

    /** Persist company fields and store locale preferences under app.*. */
    private function saveCompanySettings(int $tenantId, array $company): void
    {
        $fields = [
            'name'            => 'string',
            'address'         => 'string',
            'phone'           => 'string',
            'email'           => 'string',
            'logo'            => 'string',
            'currency'        => 'string',
            'currency_code'   => 'string',
            'currency_symbol' => 'string',
        ];

        foreach ($fields as $field => $type) {
            if (isset($company[$field])) {
                $this->saveSetting($tenantId, "company.{$field}", $company[$field], $type);
            }
        }

        // Locale/format settings: canonical namespace is app.* (matches frontend SettingsView).
        // Write under app.* so the frontend always reads fresh values without namespace mismatch.
        $localeFields = [
            'timezone'    => 'string',
            'date_format' => 'string',
            'time_format' => 'string',
        ];
        foreach ($localeFields as $field => $type) {
            if (isset($company[$field])) {
                $this->saveSetting($tenantId, "app.{$field}", $company[$field], $type);
            }
        }

        if (isset($company['name'])) {
            $stmt = $this->db->prepare(
                "UPDATE tenants
                 SET company_name = ?
                 WHERE id = ?"
            );
            $stmt->execute([$company['name'], $tenantId]);
        }
    }

    /** Persist the supported tax fields with their database types. */
    private function saveTaxSettings(int $tenantId, array $tax): void
    {
        $fields = [
            'tax_enabled' => ['key' => 'tax.tax_enabled', 'type' => 'boolean'],
            'tax_name' => ['key' => 'tax.tax_name', 'type' => 'string'],
            'tax_number' => ['key' => 'tax.tax_number', 'type' => 'string'],
            'tax_rate' => ['key' => 'tax.tax_rate', 'type' => 'integer']
        ];

        foreach ($fields as $field => $config) {
            if (isset($tax[$field])) {
                $value = $tax[$field];

                if ($config['type'] === 'boolean') {
                    $value = $value ? 1 : 0;
                } elseif ($config['type'] === 'integer') {
                    $value = (int) $value;
                }

                $this->saveSetting($tenantId, $config['key'], $value, $config['type']);
            }
        }
    }

    /** Persist the supported invoice fields with their database types. */
    private function saveInvoiceSettings(int $tenantId, array $invoice): void
    {
        $fields = [
            'prefix' => 'string',
            'next_number' => 'integer',
            'footer_text' => 'string',
            'show_tax_in_price' => 'boolean',
            'template' => 'string'
        ];

        foreach ($fields as $field => $type) {
            if (isset($invoice[$field])) {
                $value = $invoice[$field];

                if ($type === 'boolean') {
                    $value = $value ? 1 : 0;
                } elseif ($type === 'integer') {
                    $value = (int) $value;
                }

                $this->saveSetting($tenantId, "invoice.{$field}", $value, $type);
            }
        }
    }

    /** Persist the supported print fields. */
    private function savePrintSettings(int $tenantId, array $print): void
    {
        $fields = [
            'header_text' => 'string',
            'footer_text' => 'string',
            'terms_text' => 'string'
        ];

        foreach ($fields as $field => $type) {
            if (isset($print[$field])) {
                $this->saveSetting($tenantId, "print.{$field}", $print[$field], $type);
            }
        }
    }

    /** Persist cashier-session switches used by cash workflows. */
    private function saveSessionSettings(int $tenantId, array $sessions): void
    {
        $fields = [
            'enabled' => 'pos.sessions.enabled',
            'allow_negative_intraday' => 'pos.cash_session.allow_negative_intraday',
        ];

        foreach ($fields as $field => $key) {
            if (array_key_exists($field, $sessions)) {
                $this->saveSetting(
                    $tenantId,
                    $key,
                    !empty($sessions[$field]) ? 1 : 0,
                    'boolean'
                );
            }
        }
    }

    private function saveSetting(int $tenantId, string $key, $value, string $type): void
    {
        if ($type === 'array' || $type === 'object') {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        $stmt = $this->db->prepare(
            "INSERT INTO settings (tenant_id, key_name, value, type, updated_at)
             VALUES (?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                 value = VALUES(value),
                 type = VALUES(type),
                 updated_at = NOW()"
        );
        $stmt->execute([$tenantId, $key, $value, $type]);
    }

    /** Create a branch and its accounting records, skipping an existing same-name branch. */
    private function createBranch(int $tenantId, array $branch, int $userId): void
    {
        $branchName = trim($branch['name'] ?? '');
        if (empty($branchName)) {
            throw new Exception('اسم المستودع مطلوب.', 400);
        }

        $checkStmt = $this->db->prepare(
            "SELECT id FROM branches WHERE tenant_id = ? AND LOWER(name) = LOWER(?) LIMIT 1"
        );
        $checkStmt->execute([$tenantId, $branchName]);
        if ($checkStmt->fetchColumn()) {
            $this->logger->info('Branch already exists, skipping creation', [
                'tenant_id' => $tenantId,
                'branch_name' => $branchName
            ]);
            return;
        }

        $inTransaction = $this->db->inTransaction();

        if (!$inTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $stmtParent = $this->db->prepare(
                "SELECT id
                 FROM accounts
                 WHERE code = '1301'
                   AND (tenant_id = ? OR tenant_id IS NULL)
                 ORDER BY tenant_id DESC
                 LIMIT 1"
            );
            $stmtParent->execute([$tenantId]);
            $parentAccountId = (int) ($stmtParent->fetchColumn() ?: 0);

            if (!$parentAccountId) {
                if (!$inTransaction && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }

                $this->logger->error('Failed to create branch in setup: Parent account 1301 not found', [
                    'tenant_id' => $tenantId,
                    'branch_name' => $branchName
                ]);

                throw new Exception('لم يتم العثور على حساب الأب 1301 لإنشاء حساب فرعي للمخزن. يرجى إنشاء الحساب 1301 أولاً.', 400);
            }

            // Use single source of truth: AccountManagementService::createBranchAccount()
            $accountMgmt = new AccountManagementService($this->db);

            $newAccountId = $accountMgmt->createBranchAccount(
                $branch['name'],
                $tenantId,
                $branch['location'] ?? null
            );

            if (!$newAccountId) {
                if (!$inTransaction && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }

                throw new Exception('Failed to create branch inventory account');
            }

            // Use the inventory account code for the branch cost center.
            $stmtGetCode = $this->db->prepare("SELECT code FROM accounts WHERE id = ? AND tenant_id = ?");
            $stmtGetCode->execute([$newAccountId, $tenantId]);
            $newCode = $stmtGetCode->fetchColumn();

            try {
                $stmtFindCc = $this->db->prepare(
                    "SELECT id
                     FROM cost_centers
                     WHERE tenant_id = ? AND name = ?
                     LIMIT 1"
                );
                $stmtFindCc->execute([$tenantId, $branch['name']]);
                $existingCc = $stmtFindCc->fetchColumn();

                if ($existingCc) {
                    $newCostCenterId = (int) $existingCc;
                } else {
                    $stmtCc = $this->db->prepare(
                        "INSERT INTO cost_centers (
                            tenant_id, name, code, description, is_active, created_at
                        ) VALUES (?, ?, ?, ?, 1, NOW())"
                    );
                    $stmtCc->execute([
                        $tenantId,
                        $branch['name'],
                        $newCode,
                        $branch['location'] ?? ''
                    ]);
                    $newCostCenterId = (int) $this->db->lastInsertId();
                }
            } catch (Throwable $e) {
                $this->logger->warning('Failed to create/find cost center for branch', [
                    'tenant_id' => $tenantId,
                    'branch_name' => $branch['name'] ?? null,
                    'message' => $e->getMessage()
                ]);
                // Cleanup orphaned account if cost center creation fails
                try {
                    $this->db->prepare("DELETE FROM accounts WHERE id = ? AND tenant_id = ?")
                        ->execute([$newAccountId, $tenantId]);
                    $this->logger->info('Cleaned up orphaned account due to cost center failure', [
                        'tenant_id' => $tenantId,
                        'account_id' => $newAccountId
                    ]);
                } catch (Throwable $cleanupErr) {
                    $this->logger->error('Cleanup of orphaned account failed', [
                        'account_id' => $newAccountId,
                        'message' => $cleanupErr->getMessage()
                    ]);
                }
                $newCostCenterId = null;
            }

            try {
                $stmt = $this->db->prepare(
                    "INSERT INTO branches (
                        tenant_id, name, location, phone, email, active,
                        account_id, cost_center_id, created_by, created_at
                    ) VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, NOW())"
                );
                $stmt->execute([
                    $tenantId,
                    $branch['name'],
                    $branch['location'] ?? '',
                    ($branch['phone'] ?? '') ?: null,
                    ($branch['email'] ?? '') ?: null,
                    $newAccountId,
                    $newCostCenterId,
                    $userId
                ]);

                if (!$inTransaction) {
                    $this->db->commit();
                }
            } catch (Exception $branchErr) {
                // Cleanup orphaned account and cost center if branch creation fails
                try {
                    $this->db->prepare("DELETE FROM accounts WHERE id = ? AND tenant_id = ?")
                        ->execute([$newAccountId, $tenantId]);

                    if ($newCostCenterId) {
                        $this->db->prepare("DELETE FROM cost_centers WHERE id = ? AND tenant_id = ?")
                            ->execute([$newCostCenterId, $tenantId]);
                    }

                    $this->logger->info('Cleaned up orphaned account and cost center due to branch creation failure', [
                        'tenant_id' => $tenantId,
                        'account_id' => $newAccountId,
                        'cost_center_id' => $newCostCenterId
                    ]);
                } catch (Throwable $cleanupErr) {
                    $this->logger->error('Cleanup of orphaned data failed', [
                        'account_id' => $newAccountId,
                        'cost_center_id' => $newCostCenterId,
                        'message' => $cleanupErr->getMessage()
                    ]);
                }

                throw $branchErr;
            }
        } catch (Exception $e) {
            if (!$inTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }

            $this->logger->error('Failed to create branch in setup', [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'branch_name' => $branch['name'] ?? null,
                'message' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    /** Mark setup as skipped for the owning tenant. */
    public function skipSetup(Request $request, Response $response): Response
    {
        try {
            $context = $this->requireTenantContext($request);
            $tenantId = $context['tenant_id'];
            $userId = $context['user_id'];

            if (!$userId) {
                return $this->errorResponse($response, 'مطلوب معرف المستخدم.', 403);
            }

            if (!$this->isOwner((int) $tenantId, (int) $userId)) {
                $this->logger->warning('Setup skip access denied - not owner', [
                    'tenant_id' => $tenantId,
                    'user_id' => $userId
                ]);

                return $this->errorResponse($response, 'غير مصرح لك بالوصول لصفحة الإعداد.', 403);
            }

            $stmt = $this->db->prepare(
                "UPDATE tenants
                 SET is_setup_complete = 1,
                     current_step = 'skipped',
                     setup_completed_at = NOW()
                 WHERE id = ?"
            );
            $stmt->execute([(int) $tenantId]);

            $this->logger->info('Setup skipped successfully', [
                'tenant_id' => $tenantId,
                'user_id' => $userId
            ]);

            return $this->successResponse($response, [
                'message' => 'تم تخطي الإعداد بنجاح'
            ], 200);
        } catch (Exception $e) {
            $code = (int) $e->getCode();
            $status = $code >= 400 && $code < 600 ? $code : 500;

            $this->logger->error('Setup skip error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'code' => $code
            ]);

            return $this->errorResponse($response, 'فشل في تخطي الإعداد', $status);
        }
    }
}
