<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use App\Repositories\SettingsRepository;
use App\Services\MonologHandler;

/**
 * Validates liquidity outflows against the selected payment method and account policy.
 *
 * Reads account balances and policy settings but does not post journal entries.
 * MySQL callers must run inside a transaction so account locks protect the balance check.
 */
class LiquidityPolicyService
{
    private PDO $db;
    private SettingsRepository $settings;
    private $logger;

    public function __construct(PDO $db, $logger = null)
    {
        $this->db = $db;
        $this->settings = new SettingsRepository($db);
        $this->logger = $logger ?? MonologHandler::getInstance('liquidity');
    }

    /**
     * Validate a debit against the selected method's account balance and negative-balance policy.
     *
     * Returns an explicit allow/deny result with the reason and available balance context.
     */
    public function validateLiquidityBalance(
        int $tenantId,
        int $paymentMethodId,
        float $debitAmount,
        ?int $cashierSessionId = null,
        ?int $branchId = null
    ): array {
        $accountId = null;
        try {
            if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' && !$this->db->inTransaction()) {
                return [
                    'allowed' => false,
                    'reason' => 'Liquidity validation requires an active transaction',
                    'current_balance' => null,
                    'shortfall' => null,
                    'payment_method_id' => $paymentMethodId,
                ];
            }

            if (!is_finite($debitAmount) || $debitAmount < 0) {
                return [
                    'allowed' => false,
                    'reason' => 'Debit amount cannot be negative or non-finite',
                    'current_balance' => null,
                    'shortfall' => null,
                ];
            }
            if ($debitAmount === 0.0) {
                return [
                    'allowed' => true,
                    'reason' => 'No liquidity movement',
                    'current_balance' => null,
                    'projected_balance' => null,
                    'shortfall' => 0.0,
                ];
            }

            // Use the exact selected method so policies cannot be borrowed from another method of the same kind.
            $paymentMethod = $this->getPaymentMethodById($tenantId, $paymentMethodId);
            if (!$paymentMethod) {
                return [
                    'allowed' => false,
                    'reason' => 'Payment method not found or inactive',
                    'current_balance' => null,
                    'shortfall' => null,
                    'payment_method_id' => $paymentMethodId,
                ];
            }

            $paymentMethodKind = strtolower(trim((string) $paymentMethod['kind']));
            if ($paymentMethodKind === 'credit') {
                return [
                    'allowed' => false,
                    'reason' => 'Credit method has no liquidity account',
                    'current_balance' => null,
                    'shortfall' => null,
                    'payment_method_id' => $paymentMethodId,
                    'payment_method_kind' => $paymentMethodKind,
                ];
            }

            $accountId = (int) ($paymentMethod['account_id'] ?? 0);
            if ($accountId <= 0) {
                return [
                    'allowed' => false,
                    'reason' => 'Payment method has no linked liquidity account',
                    'current_balance' => null,
                    'shortfall' => null,
                    'payment_method_id' => $paymentMethodId,
                    'payment_method_kind' => $paymentMethodKind,
                ];
            }

            $account = $this->getAccountById($tenantId, $accountId);

            if (!$account) {
                $this->logger->warning('Account not found for liquidity validation', [
                    'tenant_id' => $tenantId,
                    'account_id' => $accountId
                ]);
                return [
                    'allowed' => false,
                    'reason' => 'Account not found',
                    'current_balance' => null,
                    'shortfall' => null,
                    'account_id' => $accountId,
                    'payment_method_id' => $paymentMethodId,
                ];
            }

            $currentBalance = $this->getCurrentAccountBalance($tenantId, $account, $branchId);
            if (strtolower(trim((string) $account['type'])) !== 'asset') {
                return [
                    'allowed' => false,
                    'reason' => 'Liquidity account must be an asset account',
                    'current_balance' => $currentBalance,
                    'shortfall' => round(max(0.0, $debitAmount - $currentBalance), 2),
                    'account_id' => $accountId,
                    'payment_method_id' => $paymentMethodId,
                    'payment_method_kind' => $paymentMethodKind,
                ];
            }

            $expectedAccountType = match ($paymentMethodKind) {
                'cash' => 'cash',
                'bank' => 'bank',
                'wallet' => 'wallet',
                'card' => 'card',
                'other' => 'other',
                default => null,
            };
            $configuredAccountType = strtolower(trim((string) ($paymentMethod['account_type'] ?? '')));
            if ($expectedAccountType === null || $configuredAccountType !== $expectedAccountType) {
                return [
                    'allowed' => false,
                    'reason' => 'Payment method account type does not match its kind',
                    'current_balance' => $currentBalance,
                    'shortfall' => round(max(0.0, $debitAmount - $currentBalance), 2),
                    'account_id' => $accountId,
                    'payment_method_id' => $paymentMethodId,
                    'payment_method_kind' => $paymentMethodKind,
                ];
            }

            // Match the tolerance used for journal balancing.
            $balanceAfterDebit = round($currentBalance - $debitAmount, 4);

            if ($balanceAfterDebit >= -0.001) {
                return [
                    'allowed' => true,
                    'reason' => 'Sufficient balance',
                    'current_balance' => $currentBalance,
                    'projected_balance' => $balanceAfterDebit,
                    'shortfall' => 0.0,
                    'account_id' => $accountId,
                    'payment_method_id' => $paymentMethodId,
                    'payment_method_kind' => $paymentMethodKind,
                ];
            }

            $policyResult = $this->applyNegativeBalancePolicy(
                $tenantId,
                $account,
                $paymentMethodKind,
                $paymentMethod,
                $balanceAfterDebit,
                $cashierSessionId
            );

            if (!$policyResult['allowed']) {
                $this->logger->info('Liquidity validation rejected', [
                    'tenant_id' => $tenantId,
                    'account_id' => $accountId,
                    'account_code' => $account['code'],
                    'account_type' => $account['type'],
                    'payment_method_kind' => $paymentMethodKind,
                    'current_balance' => $currentBalance,
                    'debit_amount' => $debitAmount,
                    'balance_after' => $balanceAfterDebit,
                    'reason' => $policyResult['reason']
                ]);
            }

            return array_merge($policyResult, [
                'current_balance' => $currentBalance,
                'projected_balance' => $balanceAfterDebit,
                'shortfall' => round(max(0.0, -$balanceAfterDebit), 2),
                'account_id' => $accountId,
                'payment_method_id' => $paymentMethodId,
                'payment_method_kind' => $paymentMethodKind,
            ]);

        } catch (\Throwable $e) {
            $this->logger->error('Liquidity validation error', [
                'tenant_id' => $tenantId,
                'payment_method_id' => $paymentMethodId,
                'account_id' => $accountId,
                'error' => $e->getMessage()
            ]);

            return [
                'allowed' => false,
                'reason' => 'Validation error: ' . $e->getMessage(),
                'current_balance' => null,
                'shortfall' => null,
                'payment_method_id' => $paymentMethodId,
            ];
        }
    }

    /** Apply the account-type guard and dispatch to the method-specific negative-balance policy. */
    private function applyNegativeBalancePolicy(
        int $tenantId,
        array $account,
        string $paymentMethodKind,
        array $paymentMethod,
        float $balanceAfterDebit,
        ?int $cashierSessionId
    ): array {
        $accountType = strtolower($account['type']); // asset, liability, equity, revenue, expense

        // Liability/equity balances must not bypass the asset-account requirement.
        if ($accountType !== 'asset') {
            return [
                'allowed' => false,
                'reason' => 'Liquidity account must be an asset account'
            ];
        }

        return $this->validateAssetAccountNegativeBalance(
            $tenantId,
            $account,
            $paymentMethodKind,
            $paymentMethod,
            $balanceAfterDebit,
            $cashierSessionId
        );
    }

    /** Apply the negative-balance rule for the selected asset payment method. */
    private function validateAssetAccountNegativeBalance(
        int $tenantId,
        array $account,
        string $paymentMethodKind,
        array $paymentMethod,
        float $balanceAfterDebit,
        ?int $cashierSessionId
    ): array {
        switch ($paymentMethodKind) {
            case 'cash':
                return $this->validateCashNegativeBalance(
                    $tenantId,
                    $account,
                    $balanceAfterDebit,
                    $cashierSessionId
                );

            case 'bank':
                return $this->validateBankNegativeBalance(
                    $account,
                    $balanceAfterDebit,
                    $paymentMethod
                );

            case 'wallet':
                return $this->validateWalletNegativeBalance(
                    $account,
                    $balanceAfterDebit,
                    $paymentMethod
                );

            case 'card':
            case 'suspense':
                return [
                    'allowed' => false,
                    'reason' => 'Card/Suspense accounts cannot go negative'
                ];

            case 'payable':
                return [
                    'allowed' => true,
                    'reason' => 'Payable account allows credit balance'
                ];

            case 'other':
            default:
                return [
                    'allowed' => false,
                    'reason' => 'Account type does not allow negative balance'
                ];
        }
    }

    /** Allow a temporary cash deficit only when the setting, session enforcement, and open session permit it. */
    private function validateCashNegativeBalance(
        int $tenantId,
        array $account,
        float $balanceAfterDebit,
        ?int $cashierSessionId
    ): array {
        $allowNegativeIntraday = $this->settings->getBool(
            $tenantId,
            'pos.cash_session.allow_negative_intraday',
            false // Default: strict (no negative)
        );

        if (!$allowNegativeIntraday) {
            return [
                'allowed' => false,
                'reason' => 'Cash account cannot go negative (setting disabled)'
            ];
        }

        $sessionsEnabled = $this->settings->getBool(
            $tenantId,
            'pos.sessions.enabled',
            false
        );
        if (!$sessionsEnabled) {
            return [
                'allowed' => false,
                'reason' => 'Cash account cannot go negative when cashier sessions are disabled'
            ];
        }

        if ($cashierSessionId === null) {
            return [
                'allowed' => false,
                'reason' => 'Cash account requires active cashier session for negative balance'
            ];
        }

        $session = $this->getCashierSession($tenantId, $cashierSessionId);

        if (!$session || $session['status'] !== 'open') {
            return [
                'allowed' => false,
                'reason' => 'Cashier session not open (required for intra-day negative balance)'
            ];
        }

        // All conditions met → allow temporary negative
        return [
            'allowed' => true,
            'reason' => 'Temporary negative allowed during open cashier session'
        ];
    }

    /** Allow a bank deficit only within the configured positive overdraft limit. */
    private function validateBankNegativeBalance(
        array $account,
        float $balanceAfterDebit,
        array $paymentMethod
    ): array {
        $rawLimit = isset($paymentMethod['overdraft_limit'])
            ? (float) $paymentMethod['overdraft_limit']
            : null;

        if ($rawLimit === null || $rawLimit === 0.0) {
            return [
                'allowed' => false,
                'reason' => 'Bank account has no overdraft facility'
            ];
        }

        $overdraftFloor = -abs($rawLimit);

        if ($balanceAfterDebit < $overdraftFloor) {
            return [
                'allowed' => false,
                'reason' => sprintf(
                    'Overdraft limit exceeded (limit: %s, balance after debit: %s)',
                    number_format($rawLimit, 2),
                    number_format($balanceAfterDebit, 2)
                )
            ];
        }

        return [
            'allowed' => true,
            'reason' => 'Within overdraft limit'
        ];
    }

    /**
     * Apply prepaid/postpaid wallet rules; a positive credit limit sets the postpaid floor.
     *
     * payment_methods.allow_negative is metadata and does not override these rules.
     */
    private function validateWalletNegativeBalance(
        array $account,
        float $balanceAfterDebit,
        array $paymentMethod
    ): array {
        $walletType = $paymentMethod['wallet_type'] ?? null;

        if ($walletType === 'prepaid' || $walletType === null) {
            return [
                'allowed' => false,
                'reason' => 'Prepaid wallet cannot go negative'
            ];
        }

        if ($walletType === 'postpaid') {
            $rawLimit = isset($paymentMethod['credit_limit'])
                ? (float) $paymentMethod['credit_limit']
                : null;

            if ($rawLimit === null || $rawLimit === 0.0) {
                return [
                    'allowed' => false,
                    'reason' => 'Postpaid wallet has no credit limit configured'
                ];
            }

            $creditFloor = -abs($rawLimit);

            if ($balanceAfterDebit < $creditFloor) {
                return [
                    'allowed' => false,
                    'reason' => sprintf(
                        'Credit limit exceeded (limit: %s, balance after debit: %s)',
                        number_format($rawLimit, 2),
                        number_format($balanceAfterDebit, 2)
                    )
                ];
            }

            return [
                'allowed' => true,
                'reason' => 'Within credit limit'
            ];
        }

        return [
            'allowed' => false,
            'reason' => "Unknown wallet type: {$walletType}"
        ];
    }

    /**
     * Load the tenant/global account row, locking it on MySQL to serialize balance checks.
     *
     * Requires the caller's active transaction for the row lock to be effective.
     */
    private function getAccountById(int $tenantId, int $accountId): ?array
    {
        // MySQL applies the row lock; SQLite tests run without it.
        $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $forUpdate = ($driver === 'mysql') ? 'FOR UPDATE' : '';

        $stmt = $this->db->prepare("
            SELECT id, code, name, type, debit_balance, credit_balance, tenant_id
            FROM accounts
            WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL)
            ORDER BY CASE WHEN tenant_id = ? THEN 0 ELSE 1 END
            LIMIT 1
            {$forUpdate}
        ");
        $stmt->execute([$accountId, $tenantId, $tenantId]);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Use journal lines as the balance source when available, matching the
     * trial-balance report. Keep account totals as a legacy fallback for
     * accounts whose opening balance has not been journalized.
     */
    private function getCurrentAccountBalance(int $tenantId, array $account, ?int $branchId = null): float
    {
        $branchFilter = $this->journalEntryBranchFilter($branchId);
        $forUpdate = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        // The account lock serializes outflows; a locking read avoids a stale transaction snapshot afterward.
        $stmt = $this->db->prepare("
            SELECT
                COUNT(je.id) AS journal_entry_count,
                COALESCE(SUM(
                    CASE WHEN je.id IS NOT NULL THEN COALESCE(jel.debit_amount, 0) ELSE 0 END
                ), 0) AS total_debit,
                COALESCE(SUM(
                    CASE WHEN je.id IS NOT NULL THEN COALESCE(jel.credit_amount, 0) ELSE 0 END
                ), 0) AS total_credit
            FROM journal_entry_lines jel
            LEFT JOIN journal_entries je
                ON je.id = jel.journal_entry_id
               AND je.tenant_id = jel.tenant_id
               {$branchFilter}
            WHERE jel.account_id = ?
              AND jel.tenant_id = ?
            {$forUpdate}
        ");
        $stmt->execute([(int) $account['id'], $tenantId]);
        $ledgerBalance = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($branchId !== null || (int) ($ledgerBalance['journal_entry_count'] ?? 0) > 0) {
            return (float) $ledgerBalance['total_debit'] - (float) $ledgerBalance['total_credit'];
        }

        return $this->calculateNetBalance($account);
    }

    /** Build the trusted journal-source filter used for branch-scoped balances. */
    private function journalEntryBranchFilter(?int $branchId): string
    {
        if ($branchId === null) {
            return '';
        }

        return " AND (
            EXISTS (SELECT 1 FROM sales s
                WHERE s.tenant_id = je.tenant_id AND s.branch_id = {$branchId}
                  AND (s.journal_entry_id = je.id OR (je.reference_type IN ('sale', 'cogs') AND s.id = je.reference_id)))
            OR EXISTS (SELECT 1 FROM purchases p
                WHERE p.tenant_id = je.tenant_id AND p.branch_id = {$branchId}
                  AND (p.journal_entry_id = je.id OR (je.reference_type = 'purchase' AND p.id = je.reference_id)))
            OR EXISTS (SELECT 1 FROM returns r
                WHERE r.tenant_id = je.tenant_id AND r.branch_id = {$branchId}
                  AND (r.journal_entry_id = je.id OR (je.reference_type IN ('return', 'sale_return', 'sales_return', 'purchase_return') AND r.id = je.reference_id)))
            OR EXISTS (SELECT 1 FROM cash_vouchers cv
                WHERE cv.tenant_id = je.tenant_id AND cv.branch_id = {$branchId}
                  AND (cv.journal_entry_id = je.id OR je.voucher_id = cv.id OR (je.reference_type = 'voucher' AND cv.id = je.reference_id)))
            OR EXISTS (SELECT 1 FROM cash_transactions ct
                WHERE ct.tenant_id = je.tenant_id AND ct.branch_id = {$branchId}
                  AND (ct.journal_entry_id = je.id OR (je.reference_type = 'payment' AND ct.id = je.reference_id)))
            OR EXISTS (SELECT 1 FROM inventory_transactions it
                WHERE it.tenant_id = je.tenant_id AND it.journal_entry_id = je.id
                  AND (it.branch_from = {$branchId} OR it.branch_to = {$branchId}))
        )";
    }

    /** Get policy configuration for the exact selected, active payment method. */
    private function getPaymentMethodById(int $tenantId, int $paymentMethodId): ?array
    {
        $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $forUpdate = $driver === 'mysql' ? 'FOR UPDATE' : '';
        $stmt = $this->db->prepare("
            SELECT id, kind, account_id, account_type, allow_negative,
                   overdraft_limit, credit_limit, wallet_type
            FROM payment_methods
            WHERE tenant_id = ? AND id = ? AND is_active = 1
            LIMIT 1
            {$forUpdate}
        ");
        $stmt->execute([$tenantId, $paymentMethodId]);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Get cashier session by ID.
     */
    private function getCashierSession(int $tenantId, int $sessionId): ?array
    {
        $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $forUpdate = $driver === 'mysql' ? 'FOR UPDATE' : '';
        $stmt = $this->db->prepare("
            SELECT id, status
            FROM cashier_sessions
            WHERE id = ? AND tenant_id = ?
            LIMIT 1
            {$forUpdate}
        ");
        $stmt->execute([$sessionId, $tenantId]);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /** Return debit minus credit; policy interpretation depends on account type. */
    private function calculateNetBalance(array $account): float
    {
        $debit = (float) ($account['debit_balance'] ?? 0.0);
        $credit = (float) ($account['credit_balance'] ?? 0.0);

        return $debit - $credit;
    }
}
