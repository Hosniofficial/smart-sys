<?php

declare(strict_types=1);

use App\Services\LiquidityPolicyService;
use App\Services\AccountingService;
use App\Services\PurchaseService;
use App\Services\OwnerContributionService;
use App\Exceptions\InsufficientLiquidityException;
use PHPUnit\Framework\TestCase;

/**
 * Integration Tests — Liquidity Framework
 *
 * Tests the complete Owner Contribution & Liquidity Balance Framework
 * across all 13 required scenarios including concurrency simulation.
 *
 * Uses SQLite in-memory database to mirror the production schema.
 * DB triggers (which update accounts.debit_balance / credit_balance on
 * journal_entry_lines INSERT) are not available in SQLite, so this test
 * suite seeds balances directly via UPDATE accounts to simulate the
 * trigger-maintained state.
 *
 * Scenarios covered:
 *  T01 — Sufficient cash balance → allowed
 *  T02 — Insufficient cash balance → rejected (402)
 *  T03 — Cash negative: setting enabled + open session → allowed
 *  T04 — Cash negative: setting enabled + closed session → rejected
 *  T05 — Cash negative: setting disabled → rejected
 *  T06 — Bank overdraft: within limit → allowed
 *  T07 — Bank overdraft: exceeded → rejected
 *  T08 — Prepaid wallet → rejected when negative
 *  T09 — Postpaid wallet: within credit limit → allowed
 *  T10 — Postpaid wallet: credit limit exceeded → rejected
 *  T11 — Sale return with cash refund + insufficient balance → rejected (402)
 *  T12 — OwnerContribution: invalid targetAccountId type → rejected
 *  T13 — OwnerContribution: invalid relatedGlAccountId type for owner_loan → rejected
 *  T14 — Concurrent payments against same account (FOR UPDATE lock simulation)
 *  T15 — Rollback: liquidity rejection leaves no payments/JE in DB
 */
final class LiquidityFrameworkIntegrationTest extends TestCase
{
    private PDO $db;

    // Fixed IDs for test fixtures
    private const TENANT_ID  = 1;
    private const CASH_ACCT  = 10;   // type=asset, kind=cash
    private const BANK_ACCT  = 11;   // type=asset, kind=bank
    private const WALLET_ACCT = 12;  // type=asset, kind=wallet
    private const EQUITY_ACCT = 20;  // type=equity  (capital / opening)
    private const LIAB_ACCT   = 21;  // type=liability (owner loan)
    private const EXP_ACCT    = 30;  // type=expense  (invalid target)
    private const PM_CASH     = 1;
    private const PM_BANK     = 2;
    private const PM_WALLET_PRE  = 3;
    private const PM_WALLET_POST = 4;
    private const SESSION_OPEN   = 100;
    private const SESSION_CLOSED = 101;

    // =========================================================================
    // Setup
    // =========================================================================

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('PRAGMA foreign_keys = OFF'); // SQLite FK off for simplicity

        $this->createSchema();
        $this->seedFixtures();
    }

    private function createSchema(): void
    {
        $this->db->exec("
            CREATE TABLE accounts (
                id INTEGER PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                code TEXT NOT NULL,
                name TEXT NOT NULL,
                type TEXT NOT NULL,
                debit_balance REAL NOT NULL DEFAULT 0,
                credit_balance REAL NOT NULL DEFAULT 0,
                is_active INTEGER NOT NULL DEFAULT 1
            )
        ");

        $this->db->exec("
            CREATE TABLE payment_methods (
                id INTEGER PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                kind TEXT NOT NULL,
                account_id INTEGER,
                account_type TEXT NOT NULL DEFAULT 'other',
                allow_negative INTEGER NOT NULL DEFAULT 0,
                overdraft_limit REAL NULL,
                credit_limit REAL NULL,
                wallet_type TEXT NULL,
                is_active INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NULL
            )
        ");

        $this->db->exec("
            CREATE TABLE cashier_sessions (
                id INTEGER PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                status TEXT NOT NULL,
                opening_cash_amount REAL NOT NULL DEFAULT 0
            )
        ");

        $this->db->exec("
            CREATE TABLE settings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER,
                key_name TEXT NOT NULL,
                value TEXT NULL,
                updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                UNIQUE(tenant_id, key_name)
            )
        ");

        $this->db->exec("
            CREATE TABLE journal_entries (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                entry_date TEXT NOT NULL,
                description TEXT NOT NULL,
                reference_type TEXT NOT NULL,
                reference_id INTEGER NULL,
                created_by INTEGER NULL,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                status TEXT NOT NULL DEFAULT 'posted',
                cost_center_id INTEGER NULL,
                idempotency_key TEXT NULL,
                voucher_id INTEGER NULL
            )
        ");

        $this->db->exec("CREATE TABLE sales (
            id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, branch_id INTEGER,
            journal_entry_id INTEGER
        )");
        $this->db->exec("CREATE TABLE purchases (
            id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, branch_id INTEGER,
            journal_entry_id INTEGER
        )");
        $this->db->exec("CREATE TABLE returns (
            id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, branch_id INTEGER,
            journal_entry_id INTEGER
        )");
        $this->db->exec("CREATE TABLE cash_vouchers (
            id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, branch_id INTEGER,
            journal_entry_id INTEGER
        )");
        $this->db->exec("CREATE TABLE cash_transactions (
            id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, branch_id INTEGER,
            journal_entry_id INTEGER
        )");
        $this->db->exec("CREATE TABLE inventory_transactions (
            id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, branch_from INTEGER,
            branch_to INTEGER, journal_entry_id INTEGER
        )");

        $this->db->exec("
            CREATE TABLE journal_entry_lines (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                journal_entry_id INTEGER NOT NULL,
                account_id INTEGER NOT NULL,
                debit_amount REAL NOT NULL DEFAULT 0,
                credit_amount REAL NOT NULL DEFAULT 0,
                description TEXT NULL,
                tenant_id INTEGER NOT NULL,
                cost_center_id INTEGER NULL
            )
        ");

        $this->db->exec("
            CREATE TABLE owner_contributions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                contribution_type TEXT NOT NULL,
                amount REAL NOT NULL,
                target_account_id INTEGER NOT NULL,
                related_gl_account_id INTEGER NOT NULL,
                interest_rate REAL NULL,
                due_date TEXT NULL,
                repayment_terms TEXT NULL,
                repayment_schedule TEXT NULL,
                journal_entry_id INTEGER NULL UNIQUE,
                idempotency_key TEXT NULL,
                status TEXT NOT NULL DEFAULT 'active',
                created_by_user_id INTEGER NULL,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                voided_at TEXT NULL,
                voided_by_user_id INTEGER NULL,
                reversal_je_id INTEGER NULL
            )
        ");

        $this->db->exec("
            CREATE TABLE payments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                supplier_id INTEGER NULL,
                amount REAL NOT NULL,
                payment_date TEXT NOT NULL,
                payment_method_id INTEGER NOT NULL,
                reference_number TEXT NULL,
                created_by INTEGER NULL,
                is_draft INTEGER NOT NULL DEFAULT 0,
                status TEXT NOT NULL DEFAULT 'completed',
                type TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                cost_center_id INTEGER NULL,
                session_id INTEGER NULL,
                currency TEXT NULL
            )
        ");
    }

    private function seedFixtures(): void
    {
        // Accounts
        $insertAccount = $this->db->prepare("
            INSERT INTO accounts (id, tenant_id, code, name, type, debit_balance, credit_balance)
            VALUES (?, ?, ?, ?, ?, 0, 0)
        ");
        $insertAccount->execute([self::CASH_ACCT,   self::TENANT_ID, '1101', 'Cash',          'asset']);
        $insertAccount->execute([self::BANK_ACCT,   self::TENANT_ID, '1102', 'Bank',          'asset']);
        $insertAccount->execute([self::WALLET_ACCT, self::TENANT_ID, '1103', 'Wallet',        'asset']);
        $insertAccount->execute([self::EQUITY_ACCT, self::TENANT_ID, '3001', 'Capital',       'equity']);
        $insertAccount->execute([self::LIAB_ACCT,   self::TENANT_ID, '2601', 'Owner Loan',    'liability']);
        $insertAccount->execute([self::EXP_ACCT,    self::TENANT_ID, '5001', 'Office Expense','expense']);

        // Payment methods
        $insertPM = $this->db->prepare("
            INSERT INTO payment_methods
                (id, tenant_id, name, kind, account_id, account_type, allow_negative, overdraft_limit, credit_limit, wallet_type, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
        ");
        // Cash — no overdraft, allow_negative=0 (controlled by setting+session only)
        $insertPM->execute([self::PM_CASH,       self::TENANT_ID, 'Cash',         'cash',   self::CASH_ACCT,  'cash',   0, null, null, null]);
        // Bank — overdraft_limit = 5000 (stored positive)
        $insertPM->execute([self::PM_BANK,       self::TENANT_ID, 'Bank',         'bank',   self::BANK_ACCT,  'bank',   0, 5000, null, null]);
        // Prepaid wallet
        $insertPM->execute([self::PM_WALLET_PRE, self::TENANT_ID, 'Wallet Pre',  'wallet', self::WALLET_ACCT,'wallet', 0, null, null, 'prepaid']);
        // Postpaid wallet — credit_limit = 3000 (stored positive)
        $insertPM->execute([self::PM_WALLET_POST,self::TENANT_ID, 'Wallet Post', 'wallet', self::WALLET_ACCT,'wallet', 1, null, 3000, 'postpaid']);

        // Cashier sessions
        $this->db->exec("INSERT INTO cashier_sessions (id, tenant_id, status) VALUES (" . self::SESSION_OPEN   . ", " . self::TENANT_ID . ", 'open')");
        $this->db->exec("INSERT INTO cashier_sessions (id, tenant_id, status) VALUES (" . self::SESSION_CLOSED . ", " . self::TENANT_ID . ", 'closed')");

        // Default setting: cash negative intraday = false
        $this->setSetting('pos.cash_session.allow_negative_intraday', '0');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function setSetting(string $key, string $value): void
    {
        $this->db->prepare("
            INSERT INTO settings (tenant_id, key_name, value, updated_at) VALUES (?, ?, ?, datetime('now'))
            ON CONFLICT(tenant_id, key_name) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at
        ")->execute([self::TENANT_ID, $key, $value]);
    }

    /**
     * Set account balance by writing to debit_balance / credit_balance directly.
     * In production this is maintained by DB triggers on journal_entry_lines.
     * For Asset accounts: positive balance = debit_balance > credit_balance.
     */
    private function setAccountBalance(int $accountId, float $balance): void
    {
        if ($balance >= 0) {
            $this->db->prepare("UPDATE accounts SET debit_balance = ?, credit_balance = 0 WHERE id = ?")
                ->execute([$balance, $accountId]);
        } else {
            // Negative balance: credit > debit
            $this->db->prepare("UPDATE accounts SET debit_balance = 0, credit_balance = ? WHERE id = ?")
                ->execute([abs($balance), $accountId]);
        }
    }

    private function getService(): LiquidityPolicyService
    {
        return new LiquidityPolicyService($this->db);
    }

    /**
     * Run validation inside a transaction as production code does.
     * Returns the validation result array.
     */
    private function validateInsideTransaction(
        int $accountId,
        string $kind,
        float $amount,
        ?int $sessionId = null,
        ?int $paymentMethodId = null,
        ?int $branchId = null
    ): array {
        $paymentMethodId ??= match ($kind) {
            'cash' => self::PM_CASH,
            'bank' => self::PM_BANK,
            'wallet' => self::PM_WALLET_PRE,
            default => 0,
        };

        $this->db->beginTransaction();
        try {
            $result = $this->getService()->validateLiquidityBalance(
                tenantId: self::TENANT_ID,
                paymentMethodId: $paymentMethodId,
                debitAmount: $amount,
                cashierSessionId: $sessionId,
                branchId: $branchId
            );
            $this->db->rollBack(); // never commit in tests
            return $result;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // =========================================================================
    // T01 — Sufficient cash balance → allowed
    // =========================================================================

    public function testT01_SufficientCashBalance_Allowed(): void
    {
        $this->setAccountBalance(self::CASH_ACCT, 1000.00);

        $result = $this->validateInsideTransaction(self::CASH_ACCT, 'cash', 800.00);

        $this->assertTrue($result['allowed'], 'Expected allowed: sufficient cash balance');
        $this->assertEqualsWithDelta(1000.00, $result['current_balance'], 0.01);
    }

    public function testSufficientJournalBalanceOverridesStaleAccountBalance(): void
    {
        $this->db->prepare("
            INSERT INTO journal_entries (
                tenant_id, entry_date, description, reference_type, reference_id, created_by, status
            ) VALUES (?, date('now'), 'Cash opening balance', 'opening_balance', 1, 1, 'posted')
        ")->execute([self::TENANT_ID]);
        $journalEntryId = (int) $this->db->lastInsertId();
        $this->db->prepare("
            INSERT INTO journal_entry_lines (
                journal_entry_id, account_id, debit_amount, credit_amount, description, tenant_id
            ) VALUES (?, ?, 4100, 0, 'Cash opening balance', ?)
        ")->execute([$journalEntryId, self::CASH_ACCT, self::TENANT_ID]);

        $result = $this->validateInsideTransaction(self::CASH_ACCT, 'cash', 1100.00);

        $this->assertTrue($result['allowed'], 'The ledger balance should permit a refund within available cash');
        $this->assertEqualsWithDelta(4100.00, $result['current_balance'], 0.01);
    }

    public function testBranchScopedLiquidityIgnoresCashEntriesFromOtherBranches(): void
    {
        $insertEntry = $this->db->prepare("
            INSERT INTO journal_entries (
                tenant_id, entry_date, description, reference_type, reference_id, created_by, status
            ) VALUES (?, date('now'), 'Branch cash movement', 'return', ?, 1, 'posted')
        ");
        $insertLine = $this->db->prepare("
            INSERT INTO journal_entry_lines (
                journal_entry_id, account_id, debit_amount, credit_amount, description, tenant_id
            ) VALUES (?, ?, ?, ?, 'Branch cash movement', ?)
        ");

        $insertEntry->execute([self::TENANT_ID, 1]);
        $branchOneEntry = (int) $this->db->lastInsertId();
        $insertLine->execute([$branchOneEntry, self::CASH_ACCT, 4100, 0, self::TENANT_ID]);
        $this->db->prepare("
            INSERT INTO returns (id, tenant_id, branch_id, journal_entry_id) VALUES (1, ?, 5, ?)
        ")->execute([self::TENANT_ID, $branchOneEntry]);

        $insertEntry->execute([self::TENANT_ID, 2]);
        $branchTwoEntry = (int) $this->db->lastInsertId();
        $insertLine->execute([$branchTwoEntry, self::CASH_ACCT, 0, 15750, self::TENANT_ID]);
        $this->db->prepare("
            INSERT INTO returns (id, tenant_id, branch_id, journal_entry_id) VALUES (2, ?, 6, ?)
        ")->execute([self::TENANT_ID, $branchTwoEntry]);

        $result = $this->validateInsideTransaction(
            self::CASH_ACCT,
            'cash',
            900.00,
            null,
            self::PM_CASH,
            5
        );

        $this->assertTrue($result['allowed'], 'Cash balance from another branch must not block this return');
        $this->assertEqualsWithDelta(4100.00, $result['current_balance'], 0.01);
    }

    // =========================================================================
    // T02 — Insufficient cash balance → rejected (HTTP 402)
    // =========================================================================

    public function testT02_InsufficientCashBalance_Rejected(): void
    {
        $this->setAccountBalance(self::CASH_ACCT, 500.00);

        $result = $this->validateInsideTransaction(self::CASH_ACCT, 'cash', 800.00);

        $this->assertFalse($result['allowed'], 'Expected rejected: insufficient cash balance');
        $this->assertStringContainsString('cannot go negative', strtolower($result['reason']));
        $this->assertEqualsWithDelta(500.00, $result['current_balance'], 0.01);
    }

    public function testT02_InsufficientLiquidityException_CarriesCorrectHttpCode(): void
    {
        $e = new InsufficientLiquidityException('Insufficient', 402, ['account_id' => 10]);

        $this->assertSame(402, $e->getHttpStatusCode());
        $this->assertSame(['account_id' => 10], $e->getContext());
        $this->assertSame('Insufficient', $e->getMessage());
    }

    // =========================================================================
    // T03 — Cash negative: setting enabled + open session → allowed
    // =========================================================================

    public function testT03_CashNegative_SettingEnabled_OpenSession_Allowed(): void
    {
        $this->setAccountBalance(self::CASH_ACCT, 500.00);
        $this->setSetting('pos.sessions.enabled', '1');
        $this->setSetting('pos.cash_session.allow_negative_intraday', '1');

        $result = $this->validateInsideTransaction(self::CASH_ACCT, 'cash', 800.00, self::SESSION_OPEN);

        $this->assertTrue($result['allowed'], 'Expected allowed: open session + setting enabled');
        $this->assertStringContainsString('session', strtolower($result['reason']));
    }

    // =========================================================================
    // T04 — Cash negative: setting enabled + closed session → rejected
    // =========================================================================

    public function testT04_CashNegative_SettingEnabled_ClosedSession_Rejected(): void
    {
        $this->setAccountBalance(self::CASH_ACCT, 500.00);
        $this->setSetting('pos.sessions.enabled', '1');
        $this->setSetting('pos.cash_session.allow_negative_intraday', '1');

        $result = $this->validateInsideTransaction(self::CASH_ACCT, 'cash', 800.00, self::SESSION_CLOSED);

        $this->assertFalse($result['allowed'], 'Expected rejected: session is closed');
        $this->assertStringContainsString('not open', strtolower($result['reason']));
    }

    public function testCashNegative_SessionsEnabledWithoutOpenSession_IsRejected(): void
    {
        $this->setAccountBalance(self::CASH_ACCT, 500.00);
        $this->setSetting('pos.sessions.enabled', '1');
        $this->setSetting('pos.cash_session.allow_negative_intraday', '1');

        $result = $this->validateInsideTransaction(self::CASH_ACCT, 'cash', 800.00);

        $this->assertFalse($result['allowed']);
        $this->assertSame(
            'Cash account requires active cashier session for negative balance',
            $result['reason']
        );
    }

    // =========================================================================
    // T05 — Cash negative: setting disabled (default) → rejected even with open session
    // =========================================================================

    public function testT05_CashNegative_SettingDisabled_Rejected(): void
    {
        $this->setAccountBalance(self::CASH_ACCT, 500.00);
        // setting defaults to '0' from seedFixtures()

        $result = $this->validateInsideTransaction(self::CASH_ACCT, 'cash', 800.00, self::SESSION_OPEN);

        $this->assertFalse($result['allowed'], 'Expected rejected: setting disabled');
        $this->assertStringContainsString('setting disabled', strtolower($result['reason']));
    }

    public function testCashSessionsDisabled_AllowsSufficientCashDebitWithoutSession(): void
    {
        $this->setAccountBalance(self::CASH_ACCT, 1000.00);
        $this->setSetting('pos.sessions.enabled', '0');
        $this->setSetting('pos.cash_session.allow_negative_intraday', '1');

        $result = $this->validateInsideTransaction(self::CASH_ACCT, 'cash', 800.00);

        $this->assertTrue($result['allowed'], 'Disabling session enforcement must not require a session for a funded cash debit');
    }

    public function testCashSessionsDisabled_DoesNotPermitNegativeCashWithoutSession(): void
    {
        $this->setAccountBalance(self::CASH_ACCT, 500.00);
        $this->setSetting('pos.sessions.enabled', '0');
        $this->setSetting('pos.cash_session.allow_negative_intraday', '1');

        $result = $this->validateInsideTransaction(self::CASH_ACCT, 'cash', 800.00);

        $this->assertFalse($result['allowed']);
        $this->assertSame(
            'Cash account cannot go negative when cashier sessions are disabled',
            $result['reason']
        );
    }

    // =========================================================================
    // T06 — Bank overdraft: within limit → allowed
    // =========================================================================

    public function testT06_BankOverdraft_WithinLimit_Allowed(): void
    {
        // Balance = 1000, debit 4000 → balance_after = -3000, overdraft_limit = 5000
        $this->setAccountBalance(self::BANK_ACCT, 1000.00);

        $result = $this->validateInsideTransaction(self::BANK_ACCT, 'bank', 4000.00);

        $this->assertTrue($result['allowed'], 'Expected allowed: within overdraft limit of 5000');
        $this->assertStringContainsString('overdraft', strtolower($result['reason']));
    }

    // =========================================================================
    // T07 — Bank overdraft: exceeded → rejected
    // =========================================================================

    public function testT07_BankOverdraft_Exceeded_Rejected(): void
    {
        // Balance = 1000, debit 7000 → balance_after = -6000, exceeds limit of -5000
        $this->setAccountBalance(self::BANK_ACCT, 1000.00);

        $result = $this->validateInsideTransaction(self::BANK_ACCT, 'bank', 7000.00);

        $this->assertFalse($result['allowed'], 'Expected rejected: overdraft limit exceeded');
        $this->assertStringContainsString('overdraft limit exceeded', strtolower($result['reason']));
    }

    public function testT07_BankOverdraft_Convention_StoredPositive_UsedAsNegativeFloor(): void
    {
        // Verify that overdraft_limit=5000 (stored positive) correctly blocks -6000 but allows -4000
        $this->setAccountBalance(self::BANK_ACCT, 0.00);

        $within  = $this->validateInsideTransaction(self::BANK_ACCT, 'bank', 4000.00); // → -4000 >= -5000 ✅
        $exactly = $this->validateInsideTransaction(self::BANK_ACCT, 'bank', 5000.00); // → -5000 >= -5000 ✅
        $exceed  = $this->validateInsideTransaction(self::BANK_ACCT, 'bank', 5001.00); // → -5001 < -5000 ❌

        $this->assertTrue($within['allowed'],  'Within limit should be allowed');
        $this->assertTrue($exactly['allowed'], 'Exactly at limit should be allowed');
        $this->assertFalse($exceed['allowed'], 'Exceeding limit should be rejected');
    }

    // =========================================================================
    // T08 — Prepaid wallet → rejected when balance goes negative
    // =========================================================================

    public function testT08_PrepaidWallet_Rejected_WhenNegative(): void
    {
        $this->setAccountBalance(self::WALLET_ACCT, 200.00);

        $result = $this->validateInsideTransaction(
            self::WALLET_ACCT,
            'wallet',
            500.00,
            null,
            self::PM_WALLET_PRE
        );

        $this->assertFalse($result['allowed'], 'Prepaid wallet must reject negative balance');
        $this->assertStringContainsString('prepaid', strtolower($result['reason']));
    }

    // =========================================================================
    // T09 — Postpaid wallet: within credit limit → allowed
    // =========================================================================

    public function testT09_PostpaidWallet_WithinCreditLimit_Allowed(): void
    {
        $this->setAccountBalance(self::WALLET_ACCT, 0.00);
        // balance_after = 0 - 2000 = -2000, credit_limit = 3000 → allowed
        $result = $this->validateInsideTransaction(
            self::WALLET_ACCT,
            'wallet',
            2000.00,
            null,
            self::PM_WALLET_POST
        );

        $this->assertTrue($result['allowed'], 'Postpaid wallet within credit limit should be allowed');
        $this->assertStringContainsString('credit limit', strtolower($result['reason']));
    }

    // =========================================================================
    // T10 — Postpaid wallet: credit limit exceeded → rejected
    // =========================================================================

    public function testT10_PostpaidWallet_CreditLimitExceeded_Rejected(): void
    {
        $this->setAccountBalance(self::WALLET_ACCT, 0.00);
        // balance_after = 0 - 4000 = -4000, credit_limit = 3000 → rejected
        $result = $this->validateInsideTransaction(
            self::WALLET_ACCT,
            'wallet',
            4000.00,
            null,
            self::PM_WALLET_POST
        );

        $this->assertFalse($result['allowed'], 'Postpaid wallet exceeding credit limit must be rejected');
        $this->assertStringContainsString('credit limit exceeded', strtolower($result['reason']));
    }

    public function testT10_CreditLimit_Convention_StoredPositive_UsedAsNegativeFloor(): void
    {
        $this->setAccountBalance(self::WALLET_ACCT, 0.00);
        $exactly = $this->validateInsideTransaction(self::WALLET_ACCT, 'wallet', 3000.00, null, self::PM_WALLET_POST); // → -3000 >= -3000
        $exceed  = $this->validateInsideTransaction(self::WALLET_ACCT, 'wallet', 3001.00, null, self::PM_WALLET_POST); // → -3001 < -3000

        $this->assertTrue($exactly['allowed'],  'Exactly at credit limit should be allowed');
        $this->assertFalse($exceed['allowed'],  'One unit over credit limit should be rejected');
    }

    // =========================================================================
    // T11 — Sale return with cash refund + insufficient balance → rejected (402)
    // =========================================================================

    public function testT11_SaleReturnCashRefund_InsufficientBalance_Rejected(): void
    {
        // Simulate: cash balance = 300, return refund = 500 → should be rejected
        $this->setAccountBalance(self::CASH_ACCT, 300.00);
        // setting disabled (default) so cash cannot go negative

        $result = $this->validateInsideTransaction(self::CASH_ACCT, 'cash', 500.00);

        $this->assertFalse($result['allowed'],
            'Sale return cash refund with insufficient balance must be rejected');
        $this->assertEqualsWithDelta(300.00, $result['current_balance'], 0.01);
    }

    public function testT11_SaleReturnCreditNote_NoCashOutflow_NoValidationNeeded(): void
    {
        // Credit-note returns (paid_amount = 0) must pass without liquidity check.
        // We verify by ensuring debitAmount=0 → balanceAfterDebit=0 → within tolerance → allowed.
        $this->setAccountBalance(self::CASH_ACCT, 0.00);

        $result = $this->validateInsideTransaction(self::CASH_ACCT, 'cash', 0.00);

        // debitAmount=0 → balanceAfterDebit = 0 → within tolerance → allowed
        $this->assertTrue($result['allowed'],
            'Zero-amount debit must always be allowed (no cash outflow)');
    }

    // =========================================================================
    // T12 — OwnerContribution: invalid targetAccountId type → rejected
    // =========================================================================

    public function testT12_OwnerContribution_InvalidTargetAccountType_Rejected(): void
    {
        $svc = new OwnerContributionService($this->db, self::TENANT_ID, 1);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/asset/i');

        // targetAccountId = EXPENSE account (type=expense) → must throw
        $svc->recordContribution(
            contributionType: 'capital',
            amount: 10000.00,
            targetAccountId: self::EXP_ACCT,   // ← expense, not asset
            relatedGlAccountId: self::EQUITY_ACCT
        );
    }

    public function testT12_OwnerContribution_ValidTargetAssetAccount_Passes(): void
    {
        // targetAccountId = CASH (asset) + relatedGlAccountId = EQUITY → capital contribution
        // OwnerContributionService needs AccountingService which calls postJournalEntry.
        // We verify validation passes (no exception thrown before JE posting).
        // Since we have no full AccountingService wired up, we test validateContributionInput
        // indirectly by confirming the exception is NOT about account type.
        $svc = new OwnerContributionService($this->db, self::TENANT_ID, 1);

        try {
            $svc->recordContribution(
                contributionType: 'capital',
                amount: 5000.00,
                targetAccountId: self::CASH_ACCT,   // ← asset ✅
                relatedGlAccountId: self::EQUITY_ACCT // ← equity ✅ for capital
            );
            // If it reaches here, validation passed and JE was attempted
            $this->assertTrue(true);
        } catch (\Exception $e) {
            // We expect a JE-related failure (AccountingService wiring), NOT a validation failure
            $this->assertStringNotContainsString('asset', strtolower($e->getMessage()),
                'Should not fail on account type validation for valid asset target account');
            $this->assertStringNotContainsString('equity', strtolower($e->getMessage()),
                'Should not fail on related GL type validation for capital→equity');
        }
    }

    // =========================================================================
    // T13 — OwnerContribution: invalid relatedGlAccountId for owner_loan → rejected
    // =========================================================================

    public function testT13_OwnerContribution_OwnerLoan_NonLiabilityRelated_Rejected(): void
    {
        $svc = new OwnerContributionService($this->db, self::TENANT_ID, 1);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/liability/i');

        // owner_loan requires relatedGlAccountId type = liability.
        // Passing equity account for owner_loan must throw.
        $svc->recordContribution(
            contributionType: 'owner_loan',
            amount: 5000.00,
            targetAccountId: self::CASH_ACCT,    // asset ✅
            relatedGlAccountId: self::EQUITY_ACCT // equity ❌ — owner_loan requires liability
        );
    }

    public function testT13_OwnerContribution_Capital_NonEquityRelated_Rejected(): void
    {
        $svc = new OwnerContributionService($this->db, self::TENANT_ID, 1);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/equity/i');

        // capital requires relatedGlAccountId type = equity.
        // Passing liability account for capital must throw.
        $svc->recordContribution(
            contributionType: 'capital',
            amount: 5000.00,
            targetAccountId: self::CASH_ACCT,  // asset ✅
            relatedGlAccountId: self::LIAB_ACCT // liability ❌ — capital requires equity
        );
    }

    public function testT13_OwnerContribution_OpeningBalance_NonEquityRelated_Rejected(): void
    {
        $svc = new OwnerContributionService($this->db, self::TENANT_ID, 1);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/equity/i');

        $svc->recordContribution(
            contributionType: 'opening_balance',
            amount: 5000.00,
            targetAccountId: self::CASH_ACCT,  // asset ✅
            relatedGlAccountId: self::LIAB_ACCT // liability ❌ — opening_balance requires equity
        );
    }

    // =========================================================================
    // T14 — Concurrent payments: FOR UPDATE prevents double-spend
    //
    // True multi-process concurrency cannot be tested in PHPUnit.
    // This test verifies the SERIAL equivalent: two sequential calls that
    // share a balance of 1000 trying to spend 800 each.
    // With proper locking and balance reads:
    //   - Call A reads 1000, approves 800, commits → balance becomes 200
    //   - Call B reads 200 (after commit), rejects 800
    //
    // We simulate this by running two validations against the same account,
    // committing the JE from the first between them.
    // =========================================================================

    public function testT14_ConcurrentPayments_OnlyOneApproved(): void
    {
        $this->setAccountBalance(self::CASH_ACCT, 1000.00);
        $this->setSetting('pos.cash_session.allow_negative_intraday', '0');

        // ── Transaction A: validate 800, "commit" by updating balance ──────────
        $this->db->beginTransaction();
        $resultA = $this->getService()->validateLiquidityBalance(
            tenantId: self::TENANT_ID,
                paymentMethodId: self::PM_CASH,
            debitAmount: 800.00
        );
        $this->assertTrue($resultA['allowed'], 'Transaction A should be approved (balance=1000)');

        // Simulate trigger updating debit/credit balances after JE insert
        // In production: AFTER INSERT on journal_entry_lines runs UPDATE accounts
        $this->db->exec("UPDATE accounts SET debit_balance = 200, credit_balance = 0 WHERE id = " . self::CASH_ACCT);
        $this->db->commit();

        // ── Transaction B: validate 800 after A's commit ──────────────────────
        $this->db->beginTransaction();
        $resultB = $this->getService()->validateLiquidityBalance(
            tenantId: self::TENANT_ID,
                paymentMethodId: self::PM_CASH,
            debitAmount: 800.00
        );
        $this->db->rollBack();

        $this->assertFalse($resultB['allowed'],
            'Transaction B must be rejected after A committed (balance=200 < 800)');
        $this->assertEqualsWithDelta(200.00, $resultB['current_balance'], 0.01,
            'Transaction B must see updated balance of 200');
    }

    // =========================================================================
    // T15 — Rollback: liquidity rejection leaves no payments/JE in DB
    // =========================================================================

    public function testT15_Rollback_OnLiquidityRejection_LeavesNoRecords(): void
    {
        $this->setAccountBalance(self::CASH_ACCT, 100.00);

        $paymentsBefore = (int) $this->db->query("SELECT COUNT(*) FROM payments")->fetchColumn();
        $jeBefore       = (int) $this->db->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn();

        // Simulate the transactional flow: begin → validate (reject) → rollback
        $this->db->beginTransaction();
        $liquidityRejected = false;
        try {
            $result = $this->getService()->validateLiquidityBalance(
                tenantId: self::TENANT_ID,
                paymentMethodId: self::PM_CASH,
                debitAmount: 500.00
            );

            if (!$result['allowed']) {
                // Simulate handler: throw InsufficientLiquidityException
                throw new InsufficientLiquidityException($result['reason'], 402, [
                    'current_balance' => $result['current_balance'],
                    'required_amount' => 500.00,
                ]);
            }

            // This INSERT should NEVER execute
            $this->db->exec("INSERT INTO payments (tenant_id, amount, payment_date, payment_method_id, type, status)
                              VALUES (1, 500, date('now'), 1, 'supplier', 'completed')");
            $this->db->commit();

        } catch (InsufficientLiquidityException $e) {
            $this->db->rollBack();
            $liquidityRejected = true;
        }

        $paymentsAfter = (int) $this->db->query("SELECT COUNT(*) FROM payments")->fetchColumn();
        $jeAfter       = (int) $this->db->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn();

        $this->assertTrue($liquidityRejected, 'Liquidity exception must have been thrown');
        $this->assertSame($paymentsBefore, $paymentsAfter, 'No payment record must be created after rollback');
        $this->assertSame($jeBefore, $jeAfter, 'No journal entry must be created after rollback');
    }

    // =========================================================================
    // T16 — Monetary tolerance: floating-point edge cases
    // =========================================================================

    public function testT16_FloatingPointTolerance_ExactBalance_Allowed(): void
    {
        // 999.999 - 1000.000 = -0.001 — within tolerance, must be allowed
        $this->setAccountBalance(self::CASH_ACCT, 999.999);

        $result = $this->validateInsideTransaction(self::CASH_ACCT, 'cash', 1000.000);

        $this->assertTrue($result['allowed'],
            'Balance difference within 0.001 monetary tolerance must be allowed (IEEE 754 safety)');
    }

    public function testT16_FloatingPointTolerance_BeyondTolerance_Rejected(): void
    {
        // 999.998 - 1000.000 = -0.002 — beyond tolerance, must be rejected
        $this->setAccountBalance(self::CASH_ACCT, 999.998);

        $result = $this->validateInsideTransaction(self::CASH_ACCT, 'cash', 1000.000);

        $this->assertFalse($result['allowed'],
            'Balance difference beyond 0.001 tolerance must be rejected');
    }

    // =========================================================================
    // T17 — allow_negative is metadata only: does NOT bypass policy
    // =========================================================================

    public function testT17_AllowNegativeMetadata_DoesNotBypassCashPolicy(): void
    {
        // payment_methods.allow_negative = 1 for PM_CASH, but cash policy requires setting + session.
        $this->db->exec("UPDATE payment_methods SET allow_negative = 1 WHERE id = " . self::PM_CASH);
        $this->setAccountBalance(self::CASH_ACCT, 100.00);
        // setting = '0' (disabled by default)

        $result = $this->validateInsideTransaction(self::CASH_ACCT, 'cash', 500.00, self::SESSION_OPEN);

        // Restore
        $this->db->exec("UPDATE payment_methods SET allow_negative = 0 WHERE id = " . self::PM_CASH);

        $this->assertFalse($result['allowed'],
            'allow_negative=1 alone must NOT bypass cash negative policy (setting disabled)');
        $this->assertStringContainsString('setting disabled', strtolower($result['reason']),
            'Rejection reason must reference the setting, not allow_negative');
    }

    public function testCashSessionClassification_OnlyCashUsesCashDrawerSession(): void
    {
        $accounting = new AccountingService($this->db);

        $this->assertTrue($accounting->isCashMethod(self::PM_CASH, self::TENANT_ID));
        $this->assertFalse($accounting->isCashMethod(self::PM_BANK, self::TENANT_ID));
        $this->assertFalse($accounting->isCashMethod(self::PM_WALLET_PRE, self::TENANT_ID));
    }

    public function testCashPaymentRejectsNonAssetLinkedAccount(): void
    {
        $this->db->prepare(
            "INSERT INTO payment_methods (id, tenant_id, name, kind, account_id, account_type, is_active)
             VALUES (5, ?, 'Misconfigured cash', 'cash', ?, 'cash', 1)"
        )->execute([self::TENANT_ID, self::LIAB_ACCT]);

        $result = $this->validateInsideTransaction(self::LIAB_ACCT, 'cash', 100.00, null, 5);

        $this->assertFalse($result['allowed']);
        $this->assertSame('Liquidity account must be an asset account', $result['reason']);
    }

    public function testInitialPurchasePaymentReportsShortfallFromPrePurchaseBalance(): void
    {
        $this->setAccountBalance(self::CASH_ACCT, 3000.00);
        $service = new PurchaseService($this->db, self::TENANT_ID, 1);

        try {
            $service->validatePaymentLiquidity(self::PM_CASH, 10000.00, null);
            $this->fail('Expected an insufficient-liquidity exception.');
        } catch (InsufficientLiquidityException $exception) {
            $this->assertSame(3000.00, $exception->getContext()['current_balance']);
            $this->assertSame(7000.00, $exception->getContext()['shortfall']);
            $this->assertStringContainsString('3,000.00', $exception->getMessage());
            $this->assertStringContainsString('7,000.00', $exception->getMessage());
        }
    }

    public function testNewTenantGetsConfiguredDefaultPaymentMethodsIdempotently(): void
    {
        $this->db->sqliteCreateFunction('NOW', static fn (): string => date('Y-m-d H:i:s'));
        $handler = new \App\Handlers\StrictSubscriptionHandler($this->db);
        $method = new ReflectionMethod($handler, 'createDefaultPaymentMethods');
        $method->invoke($handler, 2, [
            '1001' => 101,
            '1002' => 102,
            '1003' => 103,
        ]);

        $rows = $this->db->query(
            'SELECT kind, account_type, account_id FROM payment_methods WHERE tenant_id = 2 ORDER BY kind'
        )->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(5, $rows);

        $config = [];
        foreach ($rows as $row) {
            $config[$row['kind']] = [$row['account_type'], $row['account_id']];
        }
        $this->assertSame('cash', $config['cash'][0]);
        $this->assertSame('bank', $config['bank'][0]);
        $this->assertSame('card', $config['card'][0]);
        $this->assertSame('wallet', $config['wallet'][0]);
        $this->assertSame('payable', $config['credit'][0]);
        $this->assertSame(101, (int) $config['cash'][1]);
        $this->assertSame(102, (int) $config['bank'][1]);
        $this->assertSame(102, (int) $config['card'][1]);
        $this->assertSame(103, (int) $config['wallet'][1]);
        $this->assertNull($config['credit'][1]);

        $this->db->prepare(
            "INSERT INTO accounts (id, tenant_id, code, name, type, debit_balance, credit_balance)
             VALUES (?, 2, ?, 'Tenant 2 liquidity account', 'asset', 500, 0)"
        )->execute([101, 'T2-CASH']);
        $cashMethodId = (int) $this->db->query(
            "SELECT id FROM payment_methods WHERE tenant_id = 2 AND kind = 'cash'"
        )->fetchColumn();
        $this->db->beginTransaction();
        $validation = (new LiquidityPolicyService($this->db))->validateLiquidityBalance(
            tenantId: 2,
            paymentMethodId: $cashMethodId,
            debitAmount: 100.00
        );
        $this->db->rollBack();
        $this->assertTrue($validation['allowed'], 'New tenant cash default should pass liquidity validation.');

        $method->invoke($handler, 2, [
            '1001' => 101,
            '1002' => 102,
            '1003' => 103,
        ]);
        $this->assertSame(5, (int) $this->db->query(
            'SELECT COUNT(*) FROM payment_methods WHERE tenant_id = 2'
        )->fetchColumn());
    }
}
