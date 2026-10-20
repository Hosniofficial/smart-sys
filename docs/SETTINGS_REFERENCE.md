# SmartSys Settings Reference

## Overview
This document provides a comprehensive reference for all system settings in SmartSys, organized by category.

**Last Updated:** 2026-09-29
**Version:** 1.0

---

## Settings Table Schema

```sql
CREATE TABLE `settings` (
  `tenant_id` int(11) NOT NULL,
  `key_name` varchar(255) NOT NULL,
  `value` text DEFAULT NULL,
  `type` varchar(50) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`tenant_id`,`key_name`),
  CONSTRAINT `fk_settings_tenant` FOREIGN KEY (`tenant_id`)
    REFERENCES `tenants` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## POS Settings

### pos.sessions.enabled

**Description:** إلزام جلسة كاشير مفتوحة للحركات النقدية التي تدعم الجلسات.

**Type:** `boolean`
**Default:** `0` (false)

يُضبط هذا الخيار من خطوة «الجلسات» في التهيئة الأولية، ويؤثر على الحركات ذات طريقة الدفع المصنفة `cash` في إنشاء المبيعات والتحصيل ومدفوعات المشتريات والمرتجعات والسندات. البنك والبطاقة والمحفظة ليست نقد درج، ولا تتطلب جلسة نقدية؛ لكنها تظل خاضعة لسياسات الرصيد وحدود السحب الخاصة بها. عند تعطيل الخيار لا تُرفض حركة `cash` لمجرد عدم وجود جلسة، ويمكن ربطها بجلسة مفتوحة إن وجدت. لا يغيّر هذا الخيار سياسة منع الرصيد النقدي السالب؛ لذلك يبقى `pos.cash_session.allow_negative_intraday` مستقلًا.

**Integration points:** `SalesHandler`, `SaleCreationService`, `SalePaymentService`, `SaleApprovalService`, `PurchasesHandler`, `ReturnsHandler`, `CashVouchersHandler`.

### pos.cash_session.allow_negative_intraday

**Description:** السماح المؤقت بأن يصبح رصيد حساب الصندوق سالبًا أثناء جلسة كاشير مفتوحة.

**Category:** POS / Cashier Sessions
**Type:** `boolean`
**Default:** `0` (false)
**Migration:** `010_add_cash_negative_setting.php`

**Purpose:**
Provides flexibility for intraday cash operations where temporary negative balances may occur due to:
- Timing differences between physical cash counts and system transactions
- Change fund adjustments
- Petty cash advances
- End-of-day reconciliation processes

**Validation Logic:**
When this setting is enabled (`1`/`true`), `LiquidityPolicyService` allows negative cash balance ONLY when **ALL** of the following conditions are met:

1. **Account Type:** The GL account is an asset account (typically Cash - 1001)
2. **Payment Method:** The payment method `kind` is 'cash'
3. **Setting Enabled:** `pos.cash_session.allow_negative_intraday = 1`
4. **Session Open:** A valid, open cashier session ID is provided

If ANY condition fails, negative cash balance is **rejected**.

هذا الخيار خاص بطريقة `cash` وحساب الصندوق المرتبط بها. لا يضيف رأس مال ولا يسجل مصدر تمويل؛ ترحيل خروج أكبر من الرصيد يجعل رصيد الصندوق نفسه سالبًا مؤقتًا. لا يشمل الشراء الآجل لأنه لا يخرج نقدًا، ولا يمنح سماحًا تلقائيًا للبنك أو البطاقة أو المحفظة؛ تخضع تلك الوسائل لسياسات الأوفر درافت أو حدود الائتمان الخاصة بها.

**Usage Example:**

```sql
-- Enable for specific tenant
UPDATE settings
SET value = '1'
WHERE tenant_id = 1
  AND key_name = 'pos.cash_session.allow_negative_intraday';

-- Disable (strict mode)
UPDATE settings
SET value = '0'
WHERE tenant_id = 1
  AND key_name = 'pos.cash_session.allow_negative_intraday';

-- Check current value
SELECT value
FROM settings
WHERE tenant_id = 1
  AND key_name = 'pos.cash_session.allow_negative_intraday';
```

**Integration Points:**
- `api/v1/src/Services/LiquidityPolicyService.php::validateCashPolicy()`
- `api/v1/src/Repositories/SettingsRepository.php::getBool()`

**Related Operations:**
The following outflow operations check this setting:
- Purchase payments (cash)
- Expense vouchers (cash)
- Supplier payments (cash)
- Customer refunds (cash)

**Security Considerations:**
- ⚠️ Enabling this setting reduces financial control
- ✅ Negative balances are ONLY allowed within active sessions
- ✅ Session closure requires reconciliation to positive balance
- ✅ All transactions are logged regardless of setting value

**Recommended Configuration:**

| Business Type | Recommended | Reason |
|---------------|-------------|--------|
| Retail (High Volume) | Enabled (1) | Frequent cash transactions, needs flexibility |
| Wholesale (Low Volume) | Disabled (0) | Fewer transactions, stricter control preferred |
| Multi-branch | Per-branch decision | Different operational needs |
| E-commerce | Disabled (0) | Primarily non-cash, strict control |

**Troubleshooting:**

| Symptom | Likely Cause | Solution |
|---------|--------------|----------|
| Payment rejected: "الرصيد النقدي لا يسمح بالسالب خارج الجلسة" | Setting disabled | Enable setting or ensure session is open |
| Negative allowed outside session | Bug in validation | Check all 4 conditions are enforced |
| Setting not found | Migration not run | Run migration 010 |

**Audit Trail:**
All liquidity validation decisions (allow/reject) are logged via `MonologHandler` with context:
- `tenant_id`
- `account_id`
- `current_balance`
- `debit_amount`
- `session_id`
- `reason` (rejection reason if denied)

**See Also:**
- [LIQUIDITY_POLICY.md](./LIQUIDITY_POLICY.md) - Liquidity validation policy

---

## Accounting Settings

### accounting.cash_account_id
**Description:** Default cash account ID
**Type:** `integer`
**Default:** `1001`

### accounting.ar_account_id
**Description:** Accounts Receivable GL account ID
**Type:** `integer`
**Default:** `1101`

### accounting.ap_account_id
**Description:** Accounts Payable GL account ID
**Type:** `integer`
**Default:** `2101`

### accounting.inventory_account_id
**Description:** Inventory asset account ID
**Type:** `integer`
**Default:** `1301`

### accounting.cogs_account_id
**Description:** Cost of Goods Sold expense account ID
**Type:** `integer`
**Default:** `5001`

### accounting.revenue_account_id
**Description:** Sales revenue account ID
**Type:** `integer`
**Default:** `4001`

### accounting.vat_payable_account_id
**Description:** VAT payable (output VAT) account ID
**Type:** `integer`
**Default:** `2102`

### accounting.vat_receivable_account_id
**Description:** VAT receivable (input VAT) account ID
**Type:** `integer`
**Default:** `1102`

---

## Inventory Settings

### inventory.costing_method
**Description:** Inventory costing method
**Type:** `string`
**Values:** `fifo`, `weighted_average`, `specific_identification`
**Default:** `weighted_average`

### inventory.track_batches
**Description:** Enable batch/lot tracking
**Type:** `boolean`
**Default:** `1` (true)

### inventory.track_expiry
**Description:** Enable expiry date tracking
**Type:** `boolean`
**Default:** `1` (true)

### inventory.low_stock_threshold
**Description:** Default low stock warning threshold
**Type:** `integer`
**Default:** `10`

---

## Session Settings

### pos.sessions.enabled
**Description:** Enable cashier session management
**Type:** `boolean`
**Default:** `1` (true)

### pos.sessions.require_start_balance
**Description:** Require starting balance when opening session
**Type:** `boolean`
**Default:** `1` (true)

### pos.sessions.require_reconciliation
**Description:** Require end-of-day reconciliation
**Type:** `boolean`
**Default:** `1` (true)

---

## Security Settings

### security.jwt_expiry_hours
**Description:** JWT token expiration time in hours
**Type:** `integer`
**Default:** `24`

### security.password_min_length
**Description:** Minimum password length
**Type:** `integer`
**Default:** `8`

### security.require_2fa
**Description:** Require two-factor authentication
**Type:** `boolean`
**Default:** `0` (false)

### security.session_timeout_minutes
**Description:** Session timeout in minutes
**Type:** `integer`
**Default:** `60`

---

## Notification Settings

### notifications.email_enabled
**Description:** Enable email notifications
**Type:** `boolean`
**Default:** `1` (true)

### notifications.sms_enabled
**Description:** Enable SMS notifications
**Type:** `boolean`
**Default:** `0` (false)

### notifications.low_stock_alerts
**Description:** Enable low stock alert notifications
**Type:** `boolean`
**Default:** `1` (true)

### notifications.expiry_alerts_days
**Description:** Days before expiry to send alerts
**Type:** `integer`
**Default:** `30`

---

## Report Settings

### reports.default_date_range
**Description:** Default date range for reports
**Type:** `string`
**Values:** `today`, `week`, `month`, `quarter`, `year`
**Default:** `month`

### reports.export_format
**Description:** Default export format
**Type:** `string`
**Values:** `pdf`, `excel`, `csv`
**Default:** `pdf`

---

## Settings API

### Reading Settings

```php
use App\Repositories\SettingsRepository;

$settings = new SettingsRepository($db);

// Read as boolean
$allowNegative = $settings->getBool($tenantId, 'pos.cash_session.allow_negative_intraday', false);

// Read as integer
$cashAccountId = $settings->getInt($tenantId, 'accounting.cash_account_id', 1001);

// Read as string
$costingMethod = $settings->get($tenantId, 'inventory.costing_method', 'weighted_average');
```

### Writing Settings

```php
// Update setting
$stmt = $db->prepare("
    INSERT INTO settings (tenant_id, key_name, value, type, updated_at)
    VALUES (?, ?, ?, ?, NOW())
    ON DUPLICATE KEY UPDATE
        value = VALUES(value),
        updated_at = NOW()
");
$stmt->execute([
    $tenantId,
    'pos.cash_session.allow_negative_intraday',
    '1',  // Enable
    'boolean'
]);
```

---

## Best Practices

1. **Type Safety:** Always specify the `type` column correctly (`boolean`, `integer`, `string`, `json`)
2. **Defaults:** Provide sensible defaults in code when reading settings
3. **Validation:** Validate setting values before applying changes
4. **Audit:** Log setting changes for security and compliance
5. **Documentation:** Document new settings in this file
6. **Testing:** Test default behavior when setting is missing
7. **Per-Tenant:** Remember settings are tenant-scoped (multi-tenancy)

---

## Adding New Settings

When adding a new setting:

1. Create a migration file in `database/migrations/`
2. Add setting for all active tenants
3. Provide sensible default value
4. Document in this file
5. Update relevant service/handler code
6. Add to frontend settings UI if user-configurable
7. Write tests for default and custom values

**Example Migration Template:**

```php
$stmtInsert = $this->pdo->prepare("
    INSERT INTO settings (tenant_id, key_name, value, type, updated_at)
    VALUES (:tenant_id, :key_name, :value, :type, NOW())
    ON DUPLICATE KEY UPDATE
        value = IF(value IS NULL, VALUES(value), value),
        updated_at = NOW()
");

foreach ($tenants as $tenant) {
    $stmtInsert->execute([
        ':tenant_id' => $tenant['id'],
        ':key_name' => 'category.setting_name',
        ':value' => 'default_value',
        ':type' => 'boolean',
    ]);
}
```

---

**Document Version:** 1.0
**Last Updated:** 2026-09-29
**Maintained By:** SmartSys Development Team
