# Owner Contributions & Liquidity Balance Framework

## نظرة عامة / Overview

إطار عمل شامل لإدارة مساهمات المالك المالية والتحكم بالسيولة في نظام SmartSys.

A comprehensive framework for managing owner financial contributions and liquidity control in SmartSys ERP.

**Version:** 1.0
**Last Updated:** 2026-09-29
**Status:** Production Ready (Pending Migration Execution)

---

## جدول المحتويات / Table of Contents

1. [المقدمة](#المقدمة)
2. [أنواع المساهمات](#أنواع-المساهمات)
3. [البنية التقنية](#البنية-التقنية)
4. [واجهات برمجة التطبيقات](#واجهات-برمجة-التطبيقات)
5. [سياسة السيولة](#سياسة-السيولة)
6. [الصلاحيات والأمان](#الصلاحيات-والأمان)
7. [سجل التدقيق](#سجل-التدقيق)
8. [أمثلة الاستخدام](#أمثلة-الاستخدام)
9. [استكشاف الأخطاء](#استكشاف-الأخطاء)

---

## المقدمة

### المشكلة
- **أرصدة نقدية سالبة** بدون مصدر تمويل موثق
- **عدم وجود آلية** لتسجيل استثمارات المالك
- **غياب ضوابط السيولة** عند المعاملات المالية الصادرة
- **صعوبة التتبع** للقروض والتمويل من المالك

### الحل
إطار عمل متكامل يوفر:
- ✅ تسجيل محاسبي دقيق لمساهمات المالك
- ✅ التحقق من السيولة قبل المدفوعات الصادرة
- ✅ سجل تدقيق شامل لجميع العمليات
- ✅ ضوابط أمان متقدمة مع نظام الصلاحيات
- ✅ إمكانية العكس (Void) مع حفظ سجل كامل

### الفوائد
1. **محاسبية دقيقة:** جميع المساهمات مسجلة في دفتر القيود
2. **رقابة مالية:** منع العمليات التي تتجاوز السيولة المتاحة
3. **شفافية:** تتبع كامل للتمويل والقروض
4. **أمان:** صلاحيات محددة وسجل تدقيق شامل
5. **مرونة:** إعدادات قابلة للتخصيص حسب نوع العمل

---

## أنواع المساهمات

### 1. رأس المال / Capital

**الوصف:** مساهمة دائمة من المالك (حقوق ملكية)

**القيد المحاسبي:**
```
Dr. Cash/Bank (1001)     10,000.00
   Cr. Capital (3001)                10,000.00
```

**الاستخدام:**
- بداية النشاط التجاري
- زيادة رأس المال
- تحويل قرض إلى رأس مال

**الخصائص:**
- لا يُرد للمالك (إلا بتصفية الشركة)
- لا يحمل فائدة
- يزيد من حقوق الملكية
- يُسجل في قسم حقوق الملكية بالميزانية

**مثال:**
```json
{
  "contribution_type": "capital",
  "amount": 50000.00,
  "target_account_id": 1001,
  "related_gl_account_id": 3001,
  "cost_center_id": 1
}
```

---

### 2. قرض المالك / Owner Loan

**الوصف:** تمويل مؤقت من المالك (التزام على الشركة)

**القيد المحاسبي:**
```
Dr. Cash/Bank (1001)           25,000.00
   Cr. Due to Owner (2105)                  25,000.00
```

**الاستخدام:**
- تمويل قصير الأجل
- سد فجوة سيولة مؤقتة
- تمويل مشروع محدد

**الخصائص:**
- قابل للسداد حسب الاتفاق
- قد يحمل فائدة (اختياري)
- يُسجل كالتزام
- له تاريخ استحقاق (due_date)

**مثال:**
```json
{
  "contribution_type": "owner_loan",
  "amount": 25000.00,
  "target_account_id": 1001,
  "related_gl_account_id": 2105,
  "interest_rate": 5.0,
  "due_date": "2027-12-31",
  "repayment_terms": "12 equal monthly installments",
  "cost_center_id": 1
}
```

---

### 3. الرصيد الافتتاحي / Opening Balance

**الوصف:** تسوية رصيد بداية استخدام النظام

**القيد المحاسبي:**
```
Dr. Cash/Bank (1001)              15,000.00
   Cr. Opening Equity (2001)                    15,000.00
```

**الاستخدام:**
- بداية تشغيل النظام
- تسوية الأرصدة السابقة
- مرة واحدة عند الإعداد الأولي

**الخصائص:**
- يُستخدم مرة واحدة فقط
- لا يحمل فائدة
- يُسجل في حقوق الملكية
- لأغراض التسوية المحاسبية

**مثال:**
```json
{
  "contribution_type": "opening_balance",
  "amount": 15000.00,
  "target_account_id": 1001,
  "related_gl_account_id": 2001,
  "cost_center_id": 1
}
```

---

## البنية التقنية

### هيكل قاعدة البيانات

#### جدول owner_contributions
```sql
CREATE TABLE owner_contributions (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT(11) NOT NULL,
    contribution_type ENUM('capital','owner_loan','opening_balance') NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    target_account_id INT(11) NOT NULL COMMENT 'Cash/Bank account',
    related_gl_account_id INT(11) NOT NULL COMMENT 'Capital/Liability/Equity account',
    interest_rate DECIMAL(5,2) DEFAULT NULL COMMENT 'For owner_loan only',
    due_date DATE DEFAULT NULL COMMENT 'For owner_loan repayment',
    repayment_terms TEXT DEFAULT NULL,
    repayment_schedule JSON DEFAULT NULL,
    journal_entry_id INT(11) DEFAULT NULL UNIQUE,
    cost_center_id INT(11) DEFAULT NULL,
    idempotency_key VARCHAR(255) DEFAULT NULL,
    status ENUM('active','voided') DEFAULT 'active',
    reversal_je_id INT(11) DEFAULT NULL,
    created_by INT(11) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY idx_idempotency (tenant_id, idempotency_key),
    KEY idx_tenant_type (tenant_id, contribution_type),
    KEY idx_status (status),

    CONSTRAINT fk_oc_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_oc_target_account FOREIGN KEY (target_account_id)
        REFERENCES accounts(id),
    CONSTRAINT fk_oc_related_account FOREIGN KEY (related_gl_account_id)
        REFERENCES accounts(id),
    CONSTRAINT fk_oc_journal_entry FOREIGN KEY (journal_entry_id)
        REFERENCES journal_entries(id),
    CONSTRAINT fk_oc_reversal_je FOREIGN KEY (reversal_je_id)
        REFERENCES journal_entries(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### مكونات النظام

```
┌─────────────────────────────────────────────────────────┐
│                   Frontend Layer                         │
│              (Vue.js / React Component)                  │
└────────────────┬────────────────────────────────────────┘
                 │
                 │ HTTP/JSON
                 ▼
┌─────────────────────────────────────────────────────────┐
│              OwnerContributionHandler                    │
│         - Permission checks (RBAC)                       │
│         - Request validation                             │
│         - Response formatting                            │
└────────────────┬────────────────────────────────────────┘
                 │
                 ▼
┌─────────────────────────────────────────────────────────┐
│           OwnerContributionService                       │
│         - Business logic                                 │
│         - Idempotency checks                            │
│         - Database transactions                          │
│         - Audit logging                                  │
└────────────────┬────────────────────────────────────────┘
                 │
      ┌──────────┴──────────┐
      ▼                     ▼
┌─────────────┐    ┌──────────────────┐
│ Accounting  │    │ AuditHandler     │
│ Service     │    │ (audit_log)      │
│ (GL Posts)  │    └──────────────────┘
└─────────────┘
      │
      ▼
┌─────────────────────────────────────────────────────────┐
│                     Database                             │
│  - owner_contributions                                   │
│  - journal_entries                                       │
│  - journal_entry_lines                                   │
│  - audit_log                                             │
│  - accounts                                              │
└─────────────────────────────────────────────────────────┘
```

---

## واجهات برمجة التطبيقات

### 1. POST /setup/owner-contribution/create

**الوصف:** تسجيل مساهمة مالك جديدة

**الصلاحية:** `accounting.owner_contribution.create`

**Request:**
```json
{
  "contribution_type": "capital",
  "amount": 10000.00,
  "target_account_id": 1001,
  "related_gl_account_id": 3001,
  "interest_rate": null,
  "due_date": null,
  "repayment_terms": null,
  "repayment_schedule": null,
  "cost_center_id": 1,
  "idempotency_key": "contrib_20260929_001"
}
```

**Response (201 Created):**
```json
{
  "status": "success",
  "data": {
    "id": 1,
    "journal_entry_id": 12345,
    "status": "active",
    "duplicate": false
  }
}
```

**Response (409 Conflict - Duplicate):**
```json
{
  "status": "success",
  "data": {
    "id": 1,
    "journal_entry_id": 12345,
    "status": "active",
    "duplicate": true
  }
}
```

---

### 2. GET /setup/owner-contribution/:id

**الوصف:** عرض تفاصيل مساهمة محددة

**الصلاحية:** `accounting.owner_contribution.view`

**Response (200 OK):**
```json
{
  "status": "success",
  "data": {
    "id": 1,
    "contribution_type": "capital",
    "amount": 10000.00,
    "target_account_id": 1001,
    "target_account_name": "Cash",
    "related_gl_account_id": 3001,
    "related_account_name": "Capital",
    "journal_entry_id": 12345,
    "cost_center_id": 1,
    "status": "active",
    "created_by": 1,
    "created_at": "2026-09-29 14:30:00"
  }
}
```

---

### 3. GET /setup/owner-contributions

**الوصف:** قائمة المساهمات مع فلترة

**الصلاحية:** `accounting.owner_contribution.view`

**Query Parameters:**
- `contribution_type`: capital, owner_loan, opening_balance
- `status`: active, voided
- `limit`: عدد السجلات (افتراضي: 50)
- `offset`: موضع البداية (افتراضي: 0)

**Response (200 OK):**
```json
{
  "status": "success",
  "data": {
    "data": [
      {
        "id": 1,
        "contribution_type": "capital",
        "amount": 10000.00,
        "status": "active",
        "created_at": "2026-09-29 14:30:00"
      }
    ],
    "total": 1,
    "limit": 50,
    "offset": 0
  }
}
```

---

### 4. POST /setup/owner-contribution/:id/void

**الوصف:** إلغاء مساهمة موجودة

**الصلاحية:** `accounting.owner_contribution.void`

**Request:**
```json
{
  "reason": "Incorrect amount entered"
}
```

**Response (200 OK):**
```json
{
  "status": "success",
  "data": {
    "id": 1,
    "status": "voided",
    "reversal_je_id": 12346
  }
}
```

---

## سياسة السيولة

### مبدأ التحقق
يتم التحقق من السيولة **قبل** كل عملية صادرة (Outflow):
- ✅ دفعات الموردين
- ✅ سندات الصرف (المصروفات)
- ✅ مرتجعات المبيعات (رد نقدي)
- ❌ **لا يتم التحقق** في عمليات الإيرادات (Inflow)

### شروط السماح بالرصيد السالب النقدي

يُسمح بالرصيد السالب **فقط** عند توفر **جميع** الشروط التالية:

1. ✅ نوع الحساب = `asset` (نقدية)
2. ✅ طريقة الدفع = `cash`
3. ✅ الإعداد `pos.cash_session.allow_negative_intraday = 1`
4. ✅ جلسة كاشير مفتوحة

**مثال:**
```php
// في LiquidityPolicyService::validateCashPolicy()

$allowNegative =
    $accountType === 'asset' &&
    $methodKind === 'cash' &&
    $setting_enabled &&
    $session_open;

if (!$allowNegative) {
    return ['allowed' => false, 'reason' => 'الرصيد النقدي لا يسمح بالسالب'];
}
```

### أنواع الحسابات الأخرى

| نوع الحساب | السياسة | الوصف |
|------------|---------|-------|
| **Bank** | Overdraft Limit | يُسمح بالسالب حتى حد `overdraft_limit` |
| **Wallet (Prepaid)** | Strict | لا يُسمح بالسالب مطلقاً |
| **Wallet (Postpaid)** | Credit Limit | يُسمح بالسالب حتى `credit_limit` |
| **Card** | Always Allow | يُسمح دائماً (بطاقة ائتمان) |
| **Accounts Payable** | Allow Negative | طبيعة الحساب (التزام) |

---

## الصلاحيات والأمان

### الصلاحيات المطلوبة

| العملية | الصلاحية | الوصف |
|---------|----------|-------|
| إنشاء مساهمة | `accounting.owner_contribution.create` | تسجيل رأس مال/قرض/رصيد افتتاحي |
| عرض المساهمات | `accounting.owner_contribution.view` | قراءة السجلات فقط |
| إلغاء مساهمة | `accounting.owner_contribution.void` | عكس القيد المحاسبي |

### التوزيع الموصى به

| الدور | Create | View | Void |
|------|--------|------|------|
| المالك | ✅ | ✅ | ✅ |
| المدير المالي (CFO) | ✅ | ✅ | ✅ |
| مراقب مالي | ✅ | ✅ | ✅ |
| محاسب أول | ✅ | ✅ | ⚠️ |
| محاسب | ⚠️ | ✅ | ❌ |
| مدقق حسابات | ❌ | ✅ | ❌ |
| كاشير | ❌ | ❌ | ❌ |

---

## سجل التدقيق

### العمليات المسجلة

1. **owner_contribution_created**
   - تسجيل مساهمة جديدة
   - البيانات: النوع، المبلغ، الحسابات، القيد

2. **owner_contribution_voided**
   - إلغاء مساهمة
   - البيانات: السبب، قيد العكس

### الاستعلام
```sql
SELECT
    al.action,
    al.details,
    u.email AS user,
    al.created_at
FROM audit_log al
LEFT JOIN users u ON u.id = al.user_id
WHERE al.entity = 'owner_contributions'
  AND al.entity_id = 1
ORDER BY al.created_at DESC;
```

---

## أمثلة الاستخدام

### سيناريو 1: بداية نشاط تجاري

```bash
# 1. المالك يستثمر رأس مال
curl -X POST /api/v1/setup/owner-contribution/create \
  -H "Authorization: Bearer $TOKEN" \
  -d '{
    "contribution_type":"capital",
    "amount":100000.00,
    "target_account_id":1001,
    "related_gl_account_id":3001
  }'

# النتيجة:
# - زيادة النقدية 100,000
# - زيادة رأس المال 100,000
# - القيد: Dr. Cash 100K / Cr. Capital 100K
```

### سيناريو 2: تمويل قصير الأجل

```bash
# المالك يقرض الشركة لسد فجوة سيولة
curl -X POST /api/v1/setup/owner-contribution/create \
  -d '{
    "contribution_type":"owner_loan",
    "amount":50000.00,
    "target_account_id":1001,
    "related_gl_account_id":2105,
    "interest_rate":3.5,
    "due_date":"2027-06-30",
    "repayment_terms":"Quarterly installments"
  }'

# النتيجة:
# - زيادة النقدية 50,000
# - زيادة الالتزامات (قرض مالك) 50,000
# - يجب السداد بحلول 2027-06-30
```

### سيناريو 3: تصحيح خطأ

```bash
# إلغاء مساهمة خاطئة
curl -X POST /api/v1/setup/owner-contribution/123/void \
  -d '{
    "reason":"Incorrect amount - should be 60000 not 50000"
  }'

# النتيجة:
# - قيد عكسي: Dr. Capital / Cr. Cash
# - الحالة = voided
# - السجل الأصلي محفوظ للتدقيق
```

---

## استكشاف الأخطاء

### المشكلة: 403 Forbidden

**السبب:** عدم وجود صلاحية

**الحل:**
```sql
-- منح الصلاحية للدور
INSERT INTO role_permissions (role_id, permission_id, tenant_id)
SELECT
    (SELECT id FROM roles WHERE name='CFO' AND tenant_id=1),
    (SELECT id FROM permissions WHERE name='accounting.owner_contribution.create'),
    1;
```

### المشكلة: 402 Payment Required على Owner Contribution

**السبب:** خطأ في الكود - المساهمات لا تحتاج تحقق سيولة

**الحل:** المساهمات هي عمليات **إيرادات** (Inflow) ولا يجب التحقق منها في `LiquidityPolicyService`

### المشكلة: Duplicate Idempotency Key

**السبب:** إعادة إرسال نفس المفتاح

**الحل:** هذا **ليس خطأ** - النظام يرجع السجل الموجود. تحقق من `duplicate: true` في الاستجابة.

### المشكلة: Journal Entry Not Balanced

**السبب:** خلل في منطق القيود

**الحل:**
```sql
-- تحقق من التوازن
SELECT
    je.id,
    SUM(jel.debit) AS debit,
    SUM(jel.credit) AS credit,
    ABS(SUM(jel.debit) - SUM(jel.credit)) AS diff
FROM journal_entries je
INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
WHERE je.id = 12345
GROUP BY je.id;

-- يجب أن يكون diff = 0.00
```

---

## الخطوات التالية

### للمطورين
1. ✅ **تنفيذ الميجريشن** (migrations 008-011)
2. ✅ **اختبار الصلاحيات** في بيئة التطوير
3. ✅ **مراجعة الكود** مع الفريق
4. ⏳ **اختبارات النهاية إلى النهاية** (Phase 11)

### للمستخدمين
1. ⏳ **تدريب المحاسبين** على الواجهة الجديدة
2. ⏳ **إعداد الصلاحيات** للأدوار المختلفة
3. ⏳ **إعداد الحسابات** (Capital 3001, Due to Owner 2105)
4. ⏳ **اختبار السيناريوهات** الشائعة

---

## المراجع

- [AUDIT_TRAIL.md](./AUDIT_TRAIL.md) - نظام سجل التدقيق
- [PERMISSIONS.md](./PERMISSIONS.md) - دليل الصلاحيات
- [SETTINGS_REFERENCE.md](./SETTINGS_REFERENCE.md) - الإعدادات

---

**Document Version:** 1.0
**Last Updated:** 2026-09-29
**Status:** ✅ Ready for Production (Pending Migration)
**Maintained By:** SmartSys Development Team
