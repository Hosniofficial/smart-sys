<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use App\Services\AccountingService;
use App\Services\MonologHandler;
use App\Handlers\AuditHandler;

/**
 * Records, lists, and reverses owner capital, loan, and opening-balance contributions.
 */
class OwnerContributionService
{
    private PDO $db;
    private AccountingService $accounting;
    private ?AuditHandler $audit;
    private int $tenantId;
    private int $userId;
    private $logger;

    public function __construct(
        PDO $db,
        int $tenantId,
        int $userId,
        ?AuditHandler $audit = null
    ) {
        $this->db = $db;
        $this->accounting = new AccountingService($db);
        $this->audit = $audit;
        $this->tenantId = $tenantId;
        $this->userId = $userId;
        $this->logger = MonologHandler::getInstance('owner_contribution');
    }

    /** Validate and transactionally persist the contribution and its journal entry. */
    public function recordContribution(
        string $contributionType,
        float $amount,
        int $targetAccountId,
        int $relatedGlAccountId,
        ?float $interestRate = null,
        ?string $dueDate = null,
        ?string $repaymentTerms = null,
        ?string $repaymentSchedule = null,
        ?int $costCenterId = null,
        ?string $idempotencyKey = null
    ): array {
        $this->validateContributionInput(
            $contributionType,
            $amount,
            $targetAccountId,
            $relatedGlAccountId,
            $interestRate,
            $dueDate,
            $repaymentSchedule
        );

        if ($idempotencyKey !== null) {
            $existing = $this->findContributionByIdempotencyKey($idempotencyKey);
            if ($existing) {
                $this->assertMatchingIdempotencyRequest(
                    $existing,
                    $contributionType,
                    $amount,
                    $targetAccountId,
                    $relatedGlAccountId,
                    $interestRate,
                    $dueDate,
                    $repaymentTerms,
                    $repaymentSchedule,
                    $costCenterId
                );
                $this->logger->info('Duplicate contribution request (idempotency key)', [
                    'idempotency_key' => $idempotencyKey,
                    'existing_id' => $existing['id'],
                    'recorded_at' => $existing['created_at']
                ]);

                return [
                    'id' => $existing['id'],
                    'journal_entry_id' => $existing['journal_entry_id'],
                    'status' => $existing['status'],
                    'duplicate' => true
                ];
            }
        }

        $this->db->beginTransaction();

        try {
            $description = $this->buildJournalEntryDescription($contributionType, $amount);
            $referenceType = $this->mapContributionTypeToReferenceType($contributionType);

            $lines = [
                [
                    'account_id' => $targetAccountId,
                    'debit' => $amount,
                    'credit' => 0.0,
                    'description' => $description
                ],
                [
                    'account_id' => $relatedGlAccountId,
                    'debit' => 0.0,
                    'credit' => $amount,
                    'description' => $description
                ]
            ];

            $journalEntryId = $this->accounting->postJournalEntry(
                $this->tenantId,
                $referenceType,
                null, // The contribution ID is assigned after the journal entry is posted.
                $description,
                $lines,
                date('Y-m-d'),
                $this->userId,
                $costCenterId,
                $idempotencyKey // Use same key for JE idempotency
            );

            if (!$journalEntryId) {
                throw new \Exception('فشل إنشاء القيد المحاسبي للمساهمة');
            }

            $contributionId = $this->insertContribution(
                $contributionType,
                $amount,
                $targetAccountId,
                $relatedGlAccountId,
                $interestRate,
                $dueDate,
                $repaymentTerms,
                $repaymentSchedule,
                $journalEntryId,
                $idempotencyKey
            );

            $this->db->prepare("
                UPDATE journal_entries
                SET reference_id = ?
                WHERE id = ? AND tenant_id = ?
            ")->execute([$contributionId, $journalEntryId, $this->tenantId]);

            $this->db->commit();

            $this->logger->info('Owner contribution recorded', [
                'contribution_id' => $contributionId,
                'contribution_type' => $contributionType,
                'amount' => $amount,
                'journal_entry_id' => $journalEntryId
            ]);

            // Keep the audit write outside the financial transaction.
            $this->logAuditTrail('created', $contributionId, [
                'contribution_type' => $contributionType,
                'amount' => $amount,
                'target_account_id' => $targetAccountId,
                'related_gl_account_id' => $relatedGlAccountId
            ]);

            return [
                'id' => $contributionId,
                'journal_entry_id' => $journalEntryId,
                'status' => 'recorded',
                'duplicate' => false
            ];

        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            if ($idempotencyKey !== null) {
                $existing = $this->findContributionByIdempotencyKey($idempotencyKey);
                if ($existing !== null) {
                    $this->assertMatchingIdempotencyRequest(
                        $existing,
                        $contributionType,
                        $amount,
                        $targetAccountId,
                        $relatedGlAccountId,
                        $interestRate,
                        $dueDate,
                        $repaymentTerms,
                        $repaymentSchedule,
                        $costCenterId
                    );

                    $this->logger->info('Recovered duplicate contribution request after transaction failure', [
                        'idempotency_key' => $idempotencyKey,
                        'existing_id' => $existing['id'],
                    ]);
                    return [
                        'id' => $existing['id'],
                        'journal_entry_id' => $existing['journal_entry_id'],
                        'status' => $existing['status'],
                        'duplicate' => true
                    ];
                }
            }

            if ($e instanceof \DomainException) {
                throw $e;
            }

            $this->logger->error('Failed to record owner contribution', [
                'contribution_type' => $contributionType,
                'amount' => $amount,
                'error' => $e->getMessage()
            ]);

            throw new \Exception('فشل تسجيل المساهمة: ' . $e->getMessage());
        }
    }

    /** Reverse a recorded contribution; repeated calls return the existing void result. */
    public function voidContribution(
        int $contributionId,
        int $paymentMethodId,
        ?int $sessionId = null,
        ?int $branchId = null,
        string $reason = ''
    ): array
    {
        if ($paymentMethodId <= 0) {
            throw new \InvalidArgumentException('يجب تحديد طريقة الدفع المرتبطة بحساب المساهمة لعكسها.');
        }

        $this->db->beginTransaction();

        try {
            $contribution = $this->getContributionByIdForUpdate($contributionId);
            if (!$contribution) {
                throw new \Exception("المساهمة رقم {$contributionId} غير موجودة");
            }

            if ($contribution['status'] === 'voided') {
                $this->db->commit();
                return [
                    'id' => $contributionId,
                    'status' => 'voided',
                    'reversal_je_id' => $contribution['reversal_je_id']
                ];
            }
            if ($contribution['status'] === 'settled') {
                throw new \Exception('لا يمكن إلغاء مساهمة مسددة (settled)');
            }

            $liquidityAccountId = $this->accounting->resolveLiquidityAccount($paymentMethodId, $this->tenantId);
            if ($liquidityAccountId === null || $liquidityAccountId !== (int) $contribution['target_account_id']) {
                throw new \InvalidArgumentException('طريقة الدفع لا ترتبط بحساب السيولة الخاص بالمساهمة.');
            }

            $policy = new \App\Services\LiquidityPolicyService($this->db, $this->logger);
            $validation = $policy->validateLiquidityBalance(
                tenantId: $this->tenantId,
                paymentMethodId: $paymentMethodId,
                debitAmount: (float) $contribution['amount'],
                cashierSessionId: $sessionId
            );
            if (!$validation['allowed']) {
                throw new \App\Exceptions\InsufficientLiquidityException(
                    $validation['reason'],
                    402,
                    $validation + ['required_amount' => (float) $contribution['amount']]
                );
            }

            $reversalJeId = $this->accounting->reverseJournalEntry(
                $contribution['journal_entry_id'],
                $this->tenantId,
                $this->userId
            );

            $this->db->prepare("
                UPDATE owner_contributions
                SET status = 'voided',
                    voided_at = NOW(),
                    voided_by_user_id = ?,
                    reversal_je_id = ?
                WHERE id = ? AND tenant_id = ?
            ")->execute([
                $this->userId,
                $reversalJeId,
                $contributionId,
                $this->tenantId
            ]);

            if ($this->accounting->isCashMethod($paymentMethodId, $this->tenantId)) {
                $this->db->prepare(
                    "INSERT INTO cash_transactions (
                        tenant_id, amount, type, reference_type, reference_id,
                        payment_method_id, description, created_by, status, notes,
                        journal_entry_id, session_id, branch_id
                     ) VALUES (?, ?, 'expense', 'owner_contribution_void', ?, ?, ?, ?, 'completed', ?, ?, ?, ?)"
                )->execute([
                    $this->tenantId,
                    $contribution['amount'],
                    $contributionId,
                    $paymentMethodId,
                    'عكس مساهمة مالك #' . $contributionId,
                    $this->userId,
                    $reason,
                    $reversalJeId,
                    $sessionId,
                    $branchId,
                ]);
            }

            $this->db->commit();

            $this->logger->info('Owner contribution voided', [
                'contribution_id' => $contributionId,
                'reversal_je_id' => $reversalJeId,
                'reason' => $reason
            ]);

            // Keep the audit write outside the financial transaction.
            $this->logAuditTrail('voided', $contributionId, [
                'reversal_je_id' => $reversalJeId,
                'reason' => $reason
            ]);

            return [
                'id' => $contributionId,
                'status' => 'voided',
                'reversal_je_id' => $reversalJeId
            ];

        } catch (\App\Exceptions\InsufficientLiquidityException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            $this->logger->error('Failed to void owner contribution', [
                'contribution_id' => $contributionId,
                'error' => $e->getMessage()
            ]);

            throw new \Exception('فشل إلغاء المساهمة: ' . $e->getMessage());
        }
    }

    /** Return one contribution scoped to this tenant. */
    public function getContributionById(int $contributionId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id, tenant_id, contribution_type, amount,
                   target_account_id, related_gl_account_id,
                   interest_rate, due_date, repayment_terms, repayment_schedule,
                   journal_entry_id, idempotency_key, status,
                   created_by_user_id, created_at,
                   voided_at, voided_by_user_id, reversal_je_id
            FROM owner_contributions
            WHERE id = ? AND tenant_id = ?
            LIMIT 1
        ");
        $stmt->execute([$contributionId, $this->tenantId]);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    private function getContributionByIdForUpdate(int $contributionId): ?array
    {
        $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $forUpdate = $driver === 'mysql' ? 'FOR UPDATE' : '';
        $stmt = $this->db->prepare(
            "SELECT id, tenant_id, contribution_type, amount, target_account_id,
                    journal_entry_id, status, reversal_je_id, voided_at
             FROM owner_contributions
             WHERE id = ? AND tenant_id = ?
             LIMIT 1 {$forUpdate}"
        );
        $stmt->execute([$contributionId, $this->tenantId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** List tenant contributions with optional type/status filters and pagination. */
    public function listContributions(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $where = ['tenant_id = ?'];
        $params = [$this->tenantId];

        if (!empty($filters['contribution_type'])) {
            $where[] = 'contribution_type = ?';
            $params[] = $filters['contribution_type'];
        }

        if (!empty($filters['status'])) {
            $where[] = 'status = ?';
            $params[] = $filters['status'];
        }

        $whereClause = implode(' AND ', $where);

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM owner_contributions WHERE {$whereClause}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->db->prepare("
            SELECT id, contribution_type, amount, status,
                   created_at, journal_entry_id
            FROM owner_contributions
            WHERE {$whereClause}
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute(array_merge($params, [$limit, $offset]));
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'data' => $data,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset
        ];
    }

    private function validateContributionInput(
        string $contributionType,
        float $amount,
        int $targetAccountId,
        int $relatedGlAccountId,
        ?float $interestRate,
        ?string $dueDate,
        ?string $repaymentSchedule
    ): void {
        $validTypes = ['capital', 'owner_loan', 'opening_balance'];
        if (!in_array($contributionType, $validTypes, true)) {
            throw new \Exception("نوع المساهمة غير صحيح: {$contributionType}");
        }

        if ($amount <= 0) {
            throw new \Exception('مبلغ المساهمة يجب أن يكون أكبر من صفر');
        }

        if (!$this->accountExists($targetAccountId)) {
            throw new \Exception("حساب السيولة (ID: {$targetAccountId}) غير موجود");
        }

        if (!$this->accountExists($relatedGlAccountId)) {
            throw new \Exception("حساب رأس المال/الالتزام (ID: {$relatedGlAccountId}) غير موجود");
        }

        // Contributions debit the liquidity account, so it must be an asset.
        $targetType = $this->getAccountType($targetAccountId);
        if ($targetType !== 'asset') {
            throw new \Exception(
                "حساب السيولة (ID: {$targetAccountId}) يجب أن يكون حساب أصول (asset)، " .
                "النوع الحالي: {$targetType}. " .
                "يُستخدم هذا الحساب كـ Dr. في قيد المساهمة."
            );
        }

        $relatedType = $this->getAccountType($relatedGlAccountId);

        $expectedTypes = [
            'capital'         => ['equity'],
            'owner_loan'      => ['liability'],
            'opening_balance' => ['equity'],
        ];

        $allowedTypes = $expectedTypes[$contributionType] ?? [];

        if (!empty($allowedTypes) && !in_array($relatedType, $allowedTypes, true)) {
            $expectedLabel = implode(' أو ', $allowedTypes);
            throw new \Exception(
                "حساب المقابل (ID: {$relatedGlAccountId}) غير مناسب لنوع المساهمة '{$contributionType}'. " .
                "النوع المطلوب: {$expectedLabel}، النوع الحالي: {$relatedType}. " .
                "مثال: رأس المال → حساب حقوق الملكية (equity)، القرض → حساب التزامات (liability)."
            );
        }

        if ($contributionType === 'owner_loan') {
            if ($interestRate !== null && ($interestRate < 0 || $interestRate > 100)) {
                throw new \Exception('نسبة الفائدة يجب أن تكون بين 0 و 100');
            }

            if ($dueDate !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
                throw new \Exception('تاريخ الاستحقاق يجب أن يكون بصيغة YYYY-MM-DD');
            }

            if ($repaymentSchedule !== null) {
                $validSchedules = ['monthly', 'quarterly', 'at_due_date'];
                if (!in_array($repaymentSchedule, $validSchedules, true)) {
                    throw new \Exception("جدول السداد غير صحيح: {$repaymentSchedule}");
                }
            }
        }
    }

    private function accountExists(int $accountId): bool
    {
        $stmt = $this->db->prepare("
            SELECT 1 FROM accounts
            WHERE id = ? AND tenant_id = ?
            LIMIT 1
        ");
        $stmt->execute([$accountId, $this->tenantId]);
        return (bool) $stmt->fetch();
    }

    /** Return the account type used to validate the journal-entry side. */
    private function getAccountType(int $accountId): ?string
    {
        $stmt = $this->db->prepare("
            SELECT type FROM accounts
            WHERE id = ? AND tenant_id = ?
            LIMIT 1
        ");
        $stmt->execute([$accountId, $this->tenantId]);
        $result = $stmt->fetchColumn();
        return $result !== false ? (string) $result : null;
    }

    private function findContributionByIdempotencyKey(string $idempotencyKey): ?array
    {
        $stmt = $this->db->prepare("
            SELECT oc.id, oc.journal_entry_id, oc.status, oc.created_at,
                   oc.contribution_type, oc.amount, oc.target_account_id,
                   oc.related_gl_account_id, oc.interest_rate, oc.due_date,
                   oc.repayment_terms, oc.repayment_schedule, je.cost_center_id
            FROM owner_contributions oc
            LEFT JOIN journal_entries je ON je.id = oc.journal_entry_id AND je.tenant_id = oc.tenant_id
            WHERE oc.tenant_id = ? AND oc.idempotency_key = ?
            LIMIT 1
        ");
        $stmt->execute([$this->tenantId, $idempotencyKey]);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    private function assertMatchingIdempotencyRequest(
        array $existing,
        string $contributionType,
        float $amount,
        int $targetAccountId,
        int $relatedGlAccountId,
        ?float $interestRate,
        ?string $dueDate,
        ?string $repaymentTerms,
        ?string $repaymentSchedule,
        ?int $costCenterId
    ): void {
        $matches = (string) $existing['contribution_type'] === $contributionType
            && round((float) $existing['amount'], 2) === round($amount, 2)
            && (int) $existing['target_account_id'] === $targetAccountId
            && (int) $existing['related_gl_account_id'] === $relatedGlAccountId
            && $this->sameNullableFloat($existing['interest_rate'], $interestRate)
            && $this->sameNullableString($existing['due_date'], $dueDate)
            && $this->sameNullableString($existing['repayment_terms'], $repaymentTerms)
            && $this->sameNullableString($existing['repayment_schedule'], $repaymentSchedule)
            && $this->sameNullableInt($existing['cost_center_id'], $costCenterId);

        if (!$matches) {
            throw new \DomainException('مفتاح منع التكرار مستخدم لبيانات مساهمة مختلفة.');
        }
    }

    private function sameNullableFloat($stored, ?float $requested): bool
    {
        if ($stored === null || $requested === null) {
            return $stored === null && $requested === null;
        }

        return round((float) $stored, 2) === round($requested, 2);
    }

    private function sameNullableString($stored, ?string $requested): bool
    {
        return ($stored === null ? null : (string) $stored) === $requested;
    }

    private function sameNullableInt($stored, ?int $requested): bool
    {
        return ($stored === null ? null : (int) $stored) === $requested;
    }

    private function insertContribution(
        string $contributionType,
        float $amount,
        int $targetAccountId,
        int $relatedGlAccountId,
        ?float $interestRate,
        ?string $dueDate,
        ?string $repaymentTerms,
        ?string $repaymentSchedule,
        int $journalEntryId,
        ?string $idempotencyKey
    ): int {
        $stmt = $this->db->prepare("
            INSERT INTO owner_contributions (
                tenant_id, contribution_type, amount,
                target_account_id, related_gl_account_id,
                interest_rate, due_date, repayment_terms, repayment_schedule,
                journal_entry_id, idempotency_key, status,
                created_by_user_id, created_at
            ) VALUES (
                ?, ?, ?,
                ?, ?,
                ?, ?, ?, ?,
                ?, ?, 'recorded',
                ?, NOW()
            )
        ");

        $stmt->execute([
            $this->tenantId,
            $contributionType,
            $amount,
            $targetAccountId,
            $relatedGlAccountId,
            $interestRate,
            $dueDate,
            $repaymentTerms,
            $repaymentSchedule,
            $journalEntryId,
            $idempotencyKey,
            $this->userId
        ]);

        return (int) $this->db->lastInsertId();
    }

    private function buildJournalEntryDescription(string $contributionType, float $amount): string
    {
        $typeLabels = [
            'capital' => 'مساهمة رأس مال',
            'owner_loan' => 'قرض من المالك',
            'opening_balance' => 'رصيد افتتاحي'
        ];

        $label = $typeLabels[$contributionType] ?? 'مساهمة';
        return "{$label} - مبلغ " . number_format($amount, 2);
    }

    private function mapContributionTypeToReferenceType(string $contributionType): string
    {
        $mapping = [
            'capital' => 'owner_contribution',
            'owner_loan' => 'owner_loan',
            'opening_balance' => 'opening_balance'
        ];

        return $mapping[$contributionType] ?? 'owner_contribution';
    }

    /** Emit an application log entry and persist an audit record when the handler is available. */
    private function logAuditTrail(string $action, int $contributionId, array $details): void
    {
        $this->logger->info("Audit: owner_contribution_{$action}", array_merge([
            'contribution_id' => $contributionId,
            'action' => $action,
            'user_id' => $this->userId,
            'tenant_id' => $this->tenantId
        ], $details));

        if ($this->audit !== null) {
            try {
                $this->audit->logAction(
                    action: "owner_contribution_{$action}",
                    module: 'owner_contributions',
                    recordId: $contributionId,
                    data: $details,
                    tenantId: $this->tenantId,
                    userId: $this->userId,
                    ipAddress: $_SERVER['REMOTE_ADDR'] ?? null
                );
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to write audit_log entry', [
                    'action' => $action,
                    'contribution_id' => $contributionId,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }
}
