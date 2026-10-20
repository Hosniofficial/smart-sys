<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use Exception;
use App\Services\AccountingService;
use App\Services\MonologHandler;
use App\Repositories\SettingsRepository;

/**
 * SalePaymentService
 *
 * Handles payment operations for sales invoices.
 *
 * Methods with real logic extracted from SalesService:
 *   - addSalePayment()   ✅ real logic
 *   - payCustomerDebt()  ✅ real logic
 */
class SalePaymentService
{
    private PDO $pdo;
    private ?int $userId;
    private AccountingService $accounting;
    private SettingsRepository $settingsRepo;
    private $logger;

    public function __construct(PDO $pdo, ?int $userId = null)
    {
        $this->pdo          = $pdo;
        $this->userId       = $userId;
        $this->accounting   = new AccountingService($pdo);
        $this->settingsRepo = new SettingsRepository($pdo);
        $this->logger       = MonologHandler::getInstance('sales');
    }

    // -------------------------------------------------------------------------

    private function findOpenCashierSession(int $tenantId, int $branchId, ?int $cashierId = null): ?int
    {
        $sql    = "SELECT id FROM cashier_sessions WHERE tenant_id = ? AND branch_id = ? AND status = 'open'";
        $params = [$tenantId, $branchId];
        if ($cashierId) {
            $sql .= ' AND cashier_id = ?';
            $params[] = $cashierId;
        }
        $sql .= ' ORDER BY id DESC LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn() ?: null;
    }

    private function findOpenGlobalCashierSession(int $tenantId, ?int $cashierId = null): ?int
    {
        $sql    = "SELECT id FROM cashier_sessions WHERE tenant_id = ? AND branch_id IS NULL AND status = 'open'";
        $params = [$tenantId];
        if ($cashierId) {
            $sql .= ' AND cashier_id = ?';
            $params[] = $cashierId;
        }
        $sql .= ' ORDER BY id DESC LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn() ?: null;
    }

    private function getCurrentUserRoleId(): ?int
    {
        $stmt = $this->pdo->prepare("SELECT role_id FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$this->userId]);
        $rid = $stmt->fetchColumn();
        return $rid !== false ? (int) $rid : null;
    }

    private function isRoleEnforced(int $tenantId, ?int $roleId): bool
    {
        if (!$roleId) {
            return false;
        }
        $raw = (string) $this->settingsRepo->get($tenantId, 'pos.sessions.enforce_for_roles', '');
        if ($raw === '') {
            return false;
        }
        $trim    = trim($raw);
        $enforce = strpos($trim, '[') === 0
            ? (array) json_decode($trim, true)
            : array_filter(array_map('trim', explode(',', $trim)));
        return in_array((int) $roleId, array_map('intval', $enforce), true);
    }

    private function getCompanyCurrency(int $tenantId): string
    {
        return $this->settingsRepo->get($tenantId, 'company.currency', 'EGP') ?: 'EGP';
    }

    private function logAudit(string $action, string $entity, int $entityId, array $details, int $tenantId): void
    {
        try {
            (new \App\Handlers\AuditHandler($this->pdo))->logAction(
                $action,
                $entity,
                $entityId,
                $details,
                $tenantId,
                $this->userId
            );
        } catch (\Throwable $e) {
        }
    }

    // -------------------------------------------------------------------------
    // addSalePayment — real logic extracted from SalesService
    // -------------------------------------------------------------------------

    public function addSalePayment(
        int $tenantId,
        int $saleId,
        float $amount,
        string $paymentDate,
        int $paymentMethodId,
        ?int $customerId = null,
        ?int $branchId = null,
        ?int $costCenterId = null,
        ?string $idempotencyKey = null,
        ?int $cashierSessionId = null,
        ?bool $requireCashierSession = null,
        ?string $referenceNumber = null,
        ?string $notes = null
    ): array {
        if ($amount <= 0) {
            throw new Exception('قيمة الدفعة يجب أن تكون أكبر من صفر');
        }

        $ownsTransaction = !$this->pdo->inTransaction();
        $savepoint = 'sale_payment_operation';
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec("SAVEPOINT {$savepoint}");
        }

        try {
            $customerIdStmt = $this->pdo->prepare(
                'SELECT customer_id FROM sales WHERE id = ? AND tenant_id = ? LIMIT 1'
            );
            $customerIdStmt->execute([$saleId, $tenantId]);
            $lockedCustomerId = $customerIdStmt->fetchColumn();
            if ($lockedCustomerId !== false && $lockedCustomerId !== null) {
                $customerLock = $this->pdo->prepare(
                    'SELECT id FROM customers WHERE id = ? AND tenant_id = ? FOR UPDATE'
                );
                $customerLock->execute([(int) $lockedCustomerId, $tenantId]);
                if (!$customerLock->fetchColumn()) {
                    throw new Exception('Customer not found for sale payment.');
                }
            }

            $stmt = $this->pdo->prepare(
                "SELECT status, branch_id, customer_id, net_total_amount, tax_amount, paid_amount
                 FROM sales WHERE id = ? AND tenant_id = ? FOR UPDATE"
            );
            $stmt->execute([$saleId, $tenantId]);
            $sale = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$sale) {
                throw new Exception('Sale not found');
            }

            \App\Security\SaleWorkflowPolicy::assertPaymentAllowed((string) $sale['status']);

            $creditStmt = $this->pdo->prepare(
                "SELECT COALESCE(SUM(allocated_amount), 0)
                 FROM return_credit_allocations
                 WHERE tenant_id = ? AND sale_id = ?"
            );
            $creditStmt->execute([$tenantId, $saleId]);
            $returnCredits = (float) $creditStmt->fetchColumn();
            $grandTotal = round((float) $sale['net_total_amount'] + (float) ($sale['tax_amount'] ?? 0), 2);
            $remaining = max(0.0, round($grandTotal - (float) ($sale['paid_amount'] ?? 0) - $returnCredits, 2));
            if ($amount > $remaining + 0.01) {
                throw new \DomainException(
                    sprintf('الدفعة (%.2f) تتجاوز المبلغ المتبقي (%.2f).', $amount, $remaining)
                );
            }

            $branchId   = $branchId   ?? ($sale['branch_id']   ?? null);
            $customerId = $customerId ?? ($sale['customer_id'] ?? null);

            // Cashier session
            $sessionId = $cashierSessionId;
            $isCash    = $this->accounting->isCashMethod($paymentMethodId, $tenantId);
            $sessionRequired = $requireCashierSession
                ?? $this->settingsRepo->getBool($tenantId, 'pos.sessions.enabled', false);
            if ($isCash) {
                if (!$branchId && $sessionRequired) {
                    throw new Exception('branch_id مطلوب لإتمام دفعة نقدية.');
                }
                if (!$sessionId && $branchId) {
                    $sessionId = $this->findOpenCashierSession($tenantId, (int) $branchId, (int) $this->userId);
                }
                if (!$sessionId && $branchId) {
                    $roleId   = $this->getCurrentUserRoleId();
                    $enforced = $this->isRoleEnforced($tenantId, $roleId);
                    if (!$enforced) {
                        $sessionId = $this->findOpenGlobalCashierSession($tenantId, (int) $this->userId);
                    }
                }
                if (!$sessionId && $sessionRequired) {
                    throw new Exception('لا توجد جلسة كاشير مفتوحة.');
                }
            }

            if ($costCenterId === null && $branchId) {
                $costCenterId = $this->accounting->resolveCostCenterForService($tenantId, $this->userId, null, (int) $branchId);
            }

            $currency = $this->getCompanyCurrency($tenantId);
            $insertPayment = $this->pdo->prepare(
                "INSERT INTO payments (tenant_id, sale_id, amount, payment_date, payment_method_id, created_by,
                                       customer_id, reference_number, notes, type, status, created_at, session_id,
                                       cost_center_id, journal_entry_id, currency)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'sale', 'completed', NOW(), ?, ?, NULL, ?)"
            );
            $insertPayment->execute([
                $tenantId, $saleId, $amount, $paymentDate, $paymentMethodId, $this->userId,
                $customerId, $referenceNumber, $notes, $sessionId, $costCenterId, $currency,
            ]);
            $paymentId = (int) $this->pdo->lastInsertId();
            if ($paymentId <= 0) {
                throw new Exception('Failed to create sale payment record.');
            }

            $journalEntryId = $this->accounting->postSalePaymentJournalEntry(
                $tenantId,
                $saleId,
                $paymentId,
                $amount,
                $paymentDate,
                $paymentMethodId,
                (int) $this->userId,
                $costCenterId,
                $idempotencyKey
            );
            if (!$journalEntryId) {
                throw new \RuntimeException('تعذر إنشاء القيد المحاسبي لدفعة البيع.');
            }

            // An existing idempotent journal means this is a retry, not a second
            // receipt. The placeholder row is rolled back in that case.
            $dupStmt = $this->pdo->prepare(
                "SELECT id, amount, payment_date, payment_method_id FROM payments
                 WHERE tenant_id = ? AND sale_id = ? AND journal_entry_id = ? AND type = 'sale'
                 LIMIT 1"
            );
            $dupStmt->execute([$tenantId, $saleId, $journalEntryId]);
            $existingPayment = $dupStmt->fetch(PDO::FETCH_ASSOC);

            if ($existingPayment) {
                if (
                    round((float) $existingPayment['amount'], 2) !== round($amount, 2)
                    || (string) $existingPayment['payment_date'] !== $paymentDate
                    || (int) $existingPayment['payment_method_id'] !== $paymentMethodId
                ) {
                    throw new \DomainException('مفتاح منع التكرار مستخدم لبيانات دفعة مختلفة.');
                }

                $this->logAudit('sale_payment_duplicate_retry_blocked', 'sales', $saleId, [
                    'tenant_id'           => (int) $tenantId,
                    'user_id'             => (int) $this->userId,
                    'sale_id'             => (int) $saleId,
                    'existing_payment_id' => (int) $existingPayment['id'],
                    'existing_amount'     => (float) $existingPayment['amount'],
                    'journal_entry_id'    => $journalEntryId,
                    'attempted_amount'    => $amount,
                    'payment_date'        => $paymentDate,
                ], (int) $tenantId);

                if ($ownsTransaction) {
                    $this->pdo->rollBack();
                } else {
                    $this->pdo->exec("ROLLBACK TO SAVEPOINT {$savepoint}");
                    $this->pdo->exec("RELEASE SAVEPOINT {$savepoint}");
                }
                return [
                    'payment_id'       => (int) $existingPayment['id'],
                    'journal_entry_id' => $journalEntryId,
                    'duplicate_retry'  => true,
                ];
            }

            $linkStmt = $this->pdo->prepare(
                'UPDATE payments SET journal_entry_id = ? WHERE id = ? AND tenant_id = ?'
            );
            $linkStmt->execute([$journalEntryId, $paymentId, $tenantId]);
            if ($linkStmt->rowCount() !== 1) {
                throw new \RuntimeException('تعذر ربط دفعة البيع بقيدها المحاسبي.');
            }

            // ── AUDIT FIX #4 (CRITICAL), superseded by AUDIT SELF-REVIEW FIX 15-A ──
            // Previously (Fix #4): `paid_amount = paid_amount + ?` (accumulation)
            // and status only ever flipped to 'paid', never to 'partial'. Fix #4
            // corrected that by recomputing paid_amount from SUM(payments), but
            // still compared against the FULL invoice total — missing any credit
            // already applied via return_credit_allocations (Finding 15-A: an
            // invoice settled entirely by a return credit, with zero cash, stayed
            // 'pending_payment' forever). Now delegates to the shared
            // AccountingService::recalculateSaleStatus(), which factors in both
            // cash payments AND return credits — the single source of truth this
            // logic should have been from the start.
            $statusResult = $this->accounting->recalculateSaleStatus($tenantId, $saleId);
            $totalPaid    = $statusResult['paid_amount'];

            if ($isCash) {
                $this->pdo->prepare(
                    "INSERT INTO cash_transactions (customer_id, amount, type, reference_type, reference_id,
                                                    payment_method_id, created_by, created_at, tenant_id, status,
                                                    session_id, cost_center_id, journal_entry_id)
                     VALUES (?, ?, 'income', 'sale', ?, ?, ?, NOW(), ?, 'completed', ?, ?, ?)"
                )->execute([$customerId, $amount, $saleId, $paymentMethodId, $this->userId, $tenantId, $sessionId, $costCenterId, $journalEntryId]);
            }

            $this->logAudit('sale_payment_added', 'sales', $saleId, [
                'tenant_id'         => (int) $tenantId,
                'user_id'           => (int) $this->userId,
                'branch_id'         => $branchId,
                'session_id'        => $sessionId,
                'sale_id'           => (int) $saleId,
                'payment_id'        => $paymentId,
                'amount'            => $amount,
                'payment_method_id' => $paymentMethodId,
                'payment_date'      => $paymentDate,
                'reference_number'  => $referenceNumber,
                'notes'             => $notes,
            ], (int) $tenantId);

            if ($ownsTransaction) {
                $this->pdo->commit();
            } else {
                $this->pdo->exec("RELEASE SAVEPOINT {$savepoint}");
            }
            return ['payment_id' => $paymentId, 'journal_entry_id' => $journalEntryId];
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            } elseif (!$ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->exec("ROLLBACK TO SAVEPOINT {$savepoint}");
                $this->pdo->exec("RELEASE SAVEPOINT {$savepoint}");
            }
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // payCustomerDebt — real logic extracted from SalesService
    // -------------------------------------------------------------------------

    public function payCustomerDebt(
        int $tenantId,
        int $customerId,
        float $paymentAmount,
        int $paymentMethodId,
        ?string $paymentDate = null,
        ?int $branchId = null,
        ?int $cashierSessionId = null,
        ?bool $requireCashierSession = null,
        ?string $idempotencyKey = null
    ): array {
        if (!is_finite($paymentAmount)) {
            throw new Exception('مبلغ الدفع غير صالح.');
        }
        $paymentAmount = round($paymentAmount, 2);
        if ($paymentAmount <= 0) {
            throw new Exception('مبلغ الدفع غير صالح.');
        }

        $paymentDate = $paymentDate ?? date('Y-m-d H:i:s');
        $ownsTransaction = !$this->pdo->inTransaction();
        $savepoint = 'customer_debt_payment_operation';
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec("SAVEPOINT {$savepoint}");
        }

        try {
            $customerStmt = $this->pdo->prepare(
                'SELECT account_id FROM customers WHERE id = ? AND tenant_id = ? FOR UPDATE'
            );
            $customerStmt->execute([$customerId, $tenantId]);
            $customerAccountId = $customerStmt->fetchColumn();
            if (!$customerAccountId) {
                throw new Exception("Account ID not found for customer {$customerId}.");
            }

            $branchFilter = $branchId !== null ? ' AND s.branch_id = ?' : '';
            $outstandingStmt = $this->pdo->prepare("
                SELECT COALESCE(SUM(GREATEST(
                    ROUND(s.net_total_amount + IFNULL(s.tax_amount, 0), 2)
                    - COALESCE(dp.direct_paid, 0)
                    - COALESCE(ap.allocated_paid, 0)
                    - COALESCE(rca.return_credits, 0),
                    0
                )), 0)
                FROM sales s
                LEFT JOIN (
                    SELECT tenant_id, sale_id,
                           SUM(CASE WHEN type = 'return_payment' THEN -amount ELSE amount END) AS direct_paid
                    FROM payments
                    WHERE tenant_id = ? AND status = 'completed' AND COALESCE(is_draft, 0) = 0
                    GROUP BY tenant_id, sale_id
                ) dp ON dp.tenant_id = s.tenant_id AND dp.sale_id = s.id
                LEFT JOIN (
                    SELECT cpa.tenant_id, cpa.sale_id, SUM(cpa.allocated_amount) AS allocated_paid
                    FROM customer_payment_allocations cpa
                    JOIN payments p ON p.id = cpa.payment_id AND p.tenant_id = cpa.tenant_id
                    WHERE cpa.tenant_id = ? AND p.status = 'completed' AND COALESCE(p.is_draft, 0) = 0
                    GROUP BY cpa.tenant_id, cpa.sale_id
                ) ap ON ap.tenant_id = s.tenant_id AND ap.sale_id = s.id
                LEFT JOIN (
                    SELECT tenant_id, sale_id, SUM(allocated_amount) AS return_credits
                    FROM return_credit_allocations
                    WHERE tenant_id = ?
                    GROUP BY tenant_id, sale_id
                ) rca ON rca.sale_id = s.id AND rca.tenant_id = s.tenant_id
                WHERE s.tenant_id = ?
                  AND s.customer_id = ?
                  AND LOWER(COALESCE(s.status, '')) IN ('pending_payment', 'partial', 'due')
                  {$branchFilter}
            ");
            $outstandingParams = [$tenantId, $tenantId, $tenantId, $tenantId, $customerId];
            if ($branchId !== null) {
                $outstandingParams[] = $branchId;
            }
            $outstandingStmt->execute($outstandingParams);
            $outstanding = round((float) $outstandingStmt->fetchColumn(), 2);
            if ($paymentAmount > $outstanding + 0.01) {
                throw new \DomainException(
                    sprintf(
                        'المبلغ المدخل (%.2f) يتجاوز الرصيد المستحق للعميل (%.2f).',
                        $paymentAmount,
                        $outstanding
                    ),
                    422
                );
            }

            $isCash    = $this->accounting->isCashMethod($paymentMethodId, $tenantId);
            $sessionId = $cashierSessionId;
            $sessionRequired = $requireCashierSession
                ?? $this->settingsRepo->getBool($tenantId, 'pos.sessions.enabled', false);

            if ($isCash) {
                if (!$branchId && $sessionRequired) {
                    $roleId   = $this->getCurrentUserRoleId();
                    $enforced = $this->isRoleEnforced($tenantId, $roleId);
                    if ($enforced) {
                        throw new Exception('branch_id مطلوب لربط دفعة الدين النقدية بجلسة الكاشير.');
                    }
                } elseif (!$branchId && !$sessionId) {
                    $sessionId = $this->findOpenGlobalCashierSession($tenantId, (int) $this->userId);
                } elseif (!$sessionId) {
                    $sessionId = $this->findOpenCashierSession($tenantId, (int) $branchId, (int) $this->userId);
                    if (!$sessionId) {
                        $roleId   = $this->getCurrentUserRoleId();
                        $enforced = $this->isRoleEnforced($tenantId, $roleId);
                        if (!$enforced) {
                            $sessionId = $this->findOpenGlobalCashierSession($tenantId, (int) $this->userId);
                        }
                    }
                }
                if (!$sessionId && $sessionRequired) {
                    throw new Exception('لا توجد جلسة كاشير مفتوحة لهذا المخزن.');
                }
            }

            $currency = $this->getCompanyCurrency($tenantId);
            $stmt = $this->pdo->prepare(
                "INSERT INTO payments (tenant_id, amount, payment_date, payment_method_id, customer_id,
                                       branch_id, type, status, created_at, created_by, session_id, journal_entry_id, currency)
                 VALUES (?, ?, ?, ?, ?, ?, 'debt_payment', 'completed', NOW(), ?, ?, NULL, ?)"
            );
            $stmt->execute([
                $tenantId, $paymentAmount, $paymentDate, $paymentMethodId, $customerId, $branchId,
                $this->userId, $sessionId, $currency,
            ]);
            $paymentId = (int) $this->pdo->lastInsertId();
            if ($paymentId <= 0) {
                throw new Exception('Failed to create customer payment record.');
            }

            $journalEntryId = $this->accounting->postDebtPaymentJournalEntry(
                $tenantId,
                $customerId,
                (int) $customerAccountId,
                $paymentAmount,
                $paymentDate,
                $paymentMethodId,
                (int) $this->userId,
                null,
                $paymentId,
                $idempotencyKey
            );
            if ($journalEntryId <= 0) {
                throw new \RuntimeException('تعذر إنشاء القيد المحاسبي لتحصيل الدين.');
            }

            $linkPayment = $this->pdo->prepare(
                'UPDATE payments SET journal_entry_id = ? WHERE id = ? AND tenant_id = ?'
            );
            $linkPayment->execute([$journalEntryId, $paymentId, $tenantId]);
            if ($linkPayment->rowCount() !== 1) {
                throw new \RuntimeException('تعذر ربط دفعة الدين بقيدها المحاسبي.');
            }

            $allocatedSaleIds = (new CustomerPaymentAllocationService($this->pdo))->allocatePayment(
                $tenantId,
                $customerId,
                $paymentId,
                $paymentAmount,
                $branchId
            );
            foreach ($allocatedSaleIds as $allocatedSaleId) {
                $this->accounting->recalculateSaleStatus($tenantId, $allocatedSaleId);
            }

            if ($isCash) {
                $this->pdo->prepare(
                    "INSERT INTO cash_transactions (customer_id, amount, type, reference_type, reference_id,
                                                    payment_method_id, created_by, created_at, tenant_id, status,
                                                    session_id, journal_entry_id, branch_id)
                     VALUES (?, ?, 'income', 'debt_payment', ?, ?, ?, NOW(), ?, 'completed', ?, ?, ?)"
                )->execute([$customerId, $paymentAmount, $paymentId, $paymentMethodId, $this->userId, $tenantId, $sessionId, $journalEntryId, $branchId]);
            }

            if ($ownsTransaction) {
                $this->pdo->commit();
            } else {
                $this->pdo->exec("RELEASE SAVEPOINT {$savepoint}");
            }
            return [
                'payment_id' => $paymentId,
                'journal_entry_id' => $journalEntryId,
                'allocated_sale_ids' => $allocatedSaleIds,
            ];
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            } elseif (!$ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->exec("ROLLBACK TO SAVEPOINT {$savepoint}");
                $this->pdo->exec("RELEASE SAVEPOINT {$savepoint}");
            }
            throw $e;
        }
    }
}
