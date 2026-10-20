# Liquidity Policy & Validation Framework

## Overview

سياسة شاملة للتحكم بالسيولة ومنع الأرصدة السالبة غير المصرح بها في نظام SmartSys.

**Version:** 1.0
**Last Updated:** 2026-09-29
**Status:** Production Ready

---

## Table of Contents

1. [Policy Overview](#policy-overview)
2. [Validation Rules](#validation-rules)
3. [Account Types](#account-types)
4. [Cash Policy](#cash-policy)
5. [Bank Policy](#bank-policy)
6. [Wallet Policy](#wallet-policy)
7. [Integration Points](#integration-points)
8. [Configuration](#configuration)
9. [Error Handling](#error-handling)
10. [Troubleshooting](#troubleshooting)

---

## Policy Overview

### Purpose
منع المعاملات المالية الصادرة التي تتجاوز السيولة المتاحة، مع توفير مرونة للحالات الخاصة.

### Scope
- **يُطبَّق على:** جميع العمليات الصادرة (Outflow Operations)
- **لا يُطبَّق على:** العمليات الواردة (Inflow Operations)

### Decision Flow
```
┌─────────────────────────────┐
│   Payment Operation         │
│   (Purchase/Expense/etc)    │
└──────────┬──────────────────┘
           │
           ▼
┌────────────────────────────────────┐
│  Is this an OUTFLOW operation?     │
│  (Cr. Cash/Bank/Wallet)           │
└───────┬────────────────────────────┘
        │ YES
        ▼
┌────────────────────────────────────┐
│  LiquidityPolicyService            │
│  validateLiquidityBalance()        │
└───────┬────────────────────────────┘
        │
        ├─► allowed = true  ──► Post Journal Entry
        │
        └─► allowed = false ──► Throw InsufficientLiquidityException
                                (HTTP 402 Payment Required)
```

---

## Validation Rules

### Global Principles

1. **Source of Truth:** `accounts.type` (NOT `payment_methods.account_type`)
2. **Fail-Safe:** Reject on error or ambiguity
3. **Pre-Posting:** Validation occurs BEFORE journal entry
4. **Multi-Condition:** Account type + Payment method + Settings + Session

### Validation Algorithm

```php
function validateLiquidityBalance(
    int $tenantId,
    int $accountId,           // GL account being debited
    string $paymentMethodKind, // 'cash', 'bank', 'card', etc.
    float $debitAmount,       // Amount leaving the account
    ?int $cashierSessionId    // For cash session check
): array {
    // 1. Get account type from accounts.type
    $accountType = getAccountType($accountId);

    // 2. Get current balance
    $currentBalance = getAccountBalance($accountId);

    // 3. Calculate projected balance
    $projectedBalance = $currentBalance - $debitAmount;

    // 4. Apply policy by account type
    switch ($accountType) {
        case 'asset':
            return validateAssetAccount(...);
        case 'liability':
            return validateLiabilityAccount(...);
        // ...
    }
}
```

---

## Account Types

### Asset Accounts (أصول)

**Examples:** Cash, Bank, Wallets

**Default Policy:** **Strict** (no negative balance)

**Exceptions:**
- Cash accounts with open cashier session (conditional)
- Bank accounts with overdraft facility (limited)
- Postpaid wallets with credit limit (limited)

---

### Liability Accounts (التزامات / خصوم)

**Examples:** Accounts Payable, Loans Payable, Due to Owner

**Default Policy:** **Allow Negative** (natural liability behavior)

**Reason:** Negative balance in liability = increased obligation (normal)

**Example:**
```
Accounts Payable balance: -5000 (company owes suppliers)
Payment to supplier: 1000
New balance: -4000 (still owes, but less)
✅ ALLOWED
```

---

### Equity Accounts (حقوق الملكية)

**Examples:** Capital, Retained Earnings, Owner's Draw

**Default Policy:** **Strict** (no negative equity from payment operations)

**Reason:** Equity changes via dedicated equity transactions, not payments

---

### Revenue Accounts (إيرادات)

**Default Policy:** **Not Applicable** (revenue accounts don't participate in outflow payments)

---

### Expense Accounts (مصروفات)

**Default Policy:** **Not Applicable** (expenses are debited, not credited in payment operations)

---

## Cash Policy

### Standard Rule
```
Cash accounts CANNOT go negative
UNLESS all 4 conditions are met:

1. accounts.type = 'asset'
2. payment_methods.kind = 'cash'
3. pos.cash_session.allow_negative_intraday = 1
4. Open cashier session exists
```

### Validation Logic

```php
private function validateCashPolicy(
    int $tenantId,
    int $accountId,
    float $currentBalance,
    float $debitAmount,
    ?int $cashierSessionId
): array {
    $projectedBalance = $currentBalance - $debitAmount;

    if ($projectedBalance >= 0) {
        return ['allowed' => true];
    }

    // Negative balance check
    $allowNegativeIntraday = $this->settings->getBool(
        $tenantId,
        'pos.cash_session.allow_negative_intraday',
        false
    );

    if (!$allowNegativeIntraday) {
        return [
            'allowed' => false,
            'reason' => 'الرصيد النقدي لا يسمح بالسالب (الإعداد معطل)'
        ];
    }

    if (!$cashierSessionId) {
        return [
            'allowed' => false,
            'reason' => 'الرصيد النقدي لا يسمح بالسالب خارج الجلسة'
        ];
    }

    $sessionOpen = $this->isSessionOpen($cashierSessionId, $tenantId);
    if (!$sessionOpen) {
        return [
            'allowed' => false,
            'reason' => 'الجلسة المحددة غير مفتوحة'
        ];
    }

    // All conditions met
    return ['allowed' => true];
}
```

### Use Cases

#### Scenario 1: Retail Store - High Volume
```
Setting: pos.cash_session.allow_negative_intraday = 1
Session: Open (cashier working)
Balance: 500.00
Payment: 1,000.00 (supplier delivery COD)

Result: ✅ ALLOWED
Reason: Intraday negative allowed, will be reconciled at session close
```

#### Scenario 2: Office - Low Volume
```
Setting: pos.cash_session.allow_negative_intraday = 0
Session: Open
Balance: 500.00
Payment: 1,000.00

Result: ❌ REJECTED
Reason: Setting disabled - strict control preferred
```

#### Scenario 3: After Hours
```
Setting: pos.cash_session.allow_negative_intraday = 1
Session: Closed (end of day)
Balance: 500.00
Payment: 1,000.00

Result: ❌ REJECTED
Reason: No open session - negative not allowed
```

---

## Bank Policy

### Standard Rule
```
Bank accounts can go negative up to overdraft_limit
```

### Validation Logic

```php
private function validateBankPolicy(
    int $accountId,
    float $currentBalance,
    float $debitAmount,
    int $paymentMethodId,
    int $tenantId
): array {
    $projectedBalance = $currentBalance - $debitAmount;

    if ($projectedBalance >= 0) {
        return ['allowed' => true];
    }

    // Get overdraft limit from payment_methods
    $overdraftLimit = $this->getOverdraftLimit($paymentMethodId, $tenantId);

    if ($overdraftLimit <= 0) {
        return [
            'allowed' => false,
            'reason' => 'حساب البنك لا يملك حد سحب على المكشوف'
        ];
    }

    if (abs($projectedBalance) > $overdraftLimit) {
        return [
            'allowed' => false,
            'reason' => "تجاوز حد السحب على المكشوف ({$overdraftLimit})"
        ];
    }

    return ['allowed' => true];
}
```

### Configuration

```sql
-- Set overdraft limit for bank account
UPDATE payment_methods
SET overdraft_limit = 10000.00
WHERE id = 2 AND tenant_id = 1;
```

### Example

```
Bank Balance: 5,000.00
Overdraft Limit: 10,000.00
Payment: 12,000.00

Projected Balance: -7,000.00
Limit Check: |-7,000| ≤ 10,000 ? ✅ YES

Result: ✅ ALLOWED
```

---

## Wallet Policy

### Two Types

#### 1. Prepaid Wallet (محفظة مسبقة الدفع)

**Policy:** **Strict** (no negative balance)

**Example:** Customer wallet, gift cards

```sql
UPDATE payment_methods
SET wallet_type = 'prepaid'
WHERE id = 5;
```

**Validation:**
```
Balance: 100.00
Payment: 150.00
Result: ❌ REJECTED (insufficient prepaid balance)
```

---

#### 2. Postpaid Wallet (محفظة آجلة / ائتمان)

**Policy:** **Credit Limit**

**Example:** Corporate card, credit line

```sql
UPDATE payment_methods
SET wallet_type = 'postpaid',
    credit_limit = 50000.00
WHERE id = 6;
```

**Validation:**
```
Balance: 10,000.00
Credit Limit: 50,000.00
Payment: 40,000.00

Projected Balance: -30,000.00
Limit Check: |-30,000| ≤ 50,000 ? ✅ YES

Result: ✅ ALLOWED
```

---

## Integration Points

### Purchase Payments
```php
// api/v1/src/Services/PurchaseService.php
public function recordPayment(...) {
    // ✅ LIQUIDITY VALIDATION
    $liquidityPolicy = new LiquidityPolicyService($db, $logger);
    $validation = $liquidityPolicy->validateLiquidityBalance(
        $tenantId,
        $liquidityAccountId,
        $methodKind,
        $amount,
        $sessionId
    );

    if (!$validation['allowed']) {
        throw new InsufficientLiquidityException($validation['reason']);
    }

    // Proceed with payment...
}
```

### Cash Voucher Expenses
```php
// api/v1/src/Services/CashVoucherService.php
public function postToJournal(array $voucherData, ?int $voucherId) {
    if ($type === 'صرف' || $type === 'payment') {
        // ✅ LIQUIDITY VALIDATION (expense only, NOT receipt)
        $liquidityPolicy = new LiquidityPolicyService($db, $logger);
        $validation = $liquidityPolicy->validateLiquidityBalance(...);

        if (!$validation['allowed']) {
            throw new InsufficientLiquidityException($validation['reason']);
        }
    }

    // Proceed...
}
```

### Supplier Direct Payments
```php
// api/v1/src/Handlers/SuppliersHandler.php
public function addPayment(...) {
    // ✅ LIQUIDITY VALIDATION
    $liquidityPolicy = new LiquidityPolicyService($db, $logger);
    $validation = $liquidityPolicy->validateLiquidityBalance(...);

    if (!$validation['allowed']) {
        throw new InsufficientLiquidityException($validation['reason']);
    }

    // Proceed...
}
```

### ❌ NOT Applied To
- Owner Contributions (INFLOW - adds liquidity)
- Sale Payments (INFLOW - receives money)
- Customer Payments (INFLOW)
- Purchase Invoices (no immediate cash impact)
- Sale Invoices (no immediate cash impact)

---

## Configuration

### Required Settings

```sql
-- Enable/disable intraday negative cash
INSERT INTO settings (tenant_id, key_name, value, type)
VALUES (1, 'pos.cash_session.allow_negative_intraday', '0', 'boolean')
ON DUPLICATE KEY UPDATE value = VALUES(value);
```

### Payment Method Configuration

```sql
-- Configure payment method liquidity policy
UPDATE payment_methods SET
    account_type = 'asset',          -- Hint (accounts.type is source of truth)
    allow_negative = 0,              -- For UI display
    overdraft_limit = 5000.00,       -- For bank accounts
    credit_limit = NULL,             -- For postpaid wallets
    wallet_type = NULL               -- 'prepaid' or 'postpaid'
WHERE id = 1 AND tenant_id = 1;
```

---

## Error Handling

### Exception: InsufficientLiquidityException

**HTTP Status:** 402 Payment Required

**Response Format:**
```json
{
  "status": "error",
  "message": "الرصيد غير كافٍ: الرصيد الحالي 500.00، المطلوب 1000.00",
  "context": {
    "current_balance": 500.00,
    "required_amount": 1000.00,
    "account_id": 1001,
    "payment_method_kind": "cash"
  }
}
```

### Handler Pattern

```php
try {
    // Validate liquidity
    $liquidityPolicy->validateLiquidityBalance(...);

    // Post payment
    $accounting->postJournalEntry(...);

} catch (InsufficientLiquidityException $e) {
    // Return 402
    return $this->errorResponse($response, $e->getMessage(), 402);

} catch (\Throwable $e) {
    // Other errors
    return $this->errorResponse($response, $e->getMessage(), 400);
}
```

---

## Troubleshooting

### Issue: Payment Rejected Despite Positive Balance

**Symptom:**
```
Current Balance: 1000.00
Payment: 500.00
Error: "الرصيد النقدي لا يسمح بالسالب خارج الجلسة"
```

**Diagnosis:**
```sql
-- Check account type
SELECT id, code, name, type FROM accounts WHERE id = 1001;
-- Should be: type = 'asset'

-- Check session
SELECT * FROM cashier_sessions
WHERE tenant_id = 1 AND status = 'open' AND user_id = ?;
-- Should return open session

-- Check setting
SELECT * FROM settings
WHERE key_name = 'pos.cash_session.allow_negative_intraday' AND tenant_id = 1;
-- Should be: value = '1'
```

**Solution:** One of the 4 conditions is not met. Check each condition.

---

### Issue: Overdraft Limit Not Working

**Symptom:**
```
Bank Balance: 5000
Overdraft: 10000
Payment: 8000
Error: "الرصيد غير كافٍ"
```

**Diagnosis:**
```sql
-- Check payment method configuration
SELECT
    id,
    name,
    kind,
    account_id,
    overdraft_limit
FROM payment_methods
WHERE id = ? AND tenant_id = ?;

-- Check account type
SELECT type FROM accounts WHERE id = (
    SELECT account_id FROM payment_methods WHERE id = ?
);
```

**Solution:** Ensure `overdraft_limit > 0` and `accounts.type = 'asset'`

---

### Issue: Validation Bypassed

**Symptom:** Negative balances created without validation

**Diagnosis:**
```php
// Check if validation is called
grep -r "validateLiquidityBalance" api/v1/src/Services/

// Check for try-catch blocks
grep -A5 "InsufficientLiquidityException" api/v1/src/Handlers/
```

**Solution:** Ensure all outflow operations call `validateLiquidityBalance()` **before** posting.

---

## Audit & Monitoring

### Rejection Logging

All rejections logged via MonologHandler:

```php
$this->logger->warning('liquidity_validation_rejected', [
    'tenant_id' => $tenantId,
    'account_id' => $accountId,
    'account_type' => $accountType,
    'payment_method_kind' => $methodKind,
    'current_balance' => $currentBalance,
    'debit_amount' => $debitAmount,
    'reason' => $reason,
]);
```

### Query Rejected Operations

```bash
# View rejection log
tail -f logs/liquidity.log | grep "liquidity_validation_rejected"
```

---

## Best Practices

### 1. Session Management
- ✅ Open session at start of day
- ✅ Close session at end of day with reconciliation
- ❌ Don't leave sessions open overnight

### 2. Setting Configuration
- ✅ Enable negative cash only for high-volume retail
- ✅ Disable for low-volume or high-control environments
- ✅ Review setting quarterly

### 3. Overdraft Limits
- ✅ Set realistic limits based on bank agreement
- ✅ Review and adjust monthly
- ✅ Monitor usage via reports

### 4. Monitoring
- ✅ Review rejection logs weekly
- ✅ Investigate patterns of rejections
- ✅ Train staff on proper procedures

---

## Performance Considerations

### Database Queries
- Account balance lookup: **1 query**
- Session status check: **1 query**
- Settings fetch: **Cached** (SettingsRepository)
- Payment method config: **1 query**

**Total:** ~3 queries per validation (acceptable overhead)

### Caching Strategy
```php
// Settings are cached by SettingsRepository
$this->settings->getBool($tenantId, $key, $default);
// Uses internal cache, hits DB only once per request
```

---

## References

- [OWNER_CONTRIBUTIONS.md](./OWNER_CONTRIBUTIONS.md) - Framework overview
- [SETTINGS_REFERENCE.md](./SETTINGS_REFERENCE.md) - Settings documentation

---

**Document Version:** 1.0
**Last Updated:** 2026-09-29
**Status:** ✅ Production Ready
**Maintained By:** SmartSys Development Team
