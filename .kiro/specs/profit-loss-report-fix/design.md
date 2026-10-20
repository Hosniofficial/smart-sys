# Design Document

## Feature: profit-loss-report-fix

---

## Overview

يُصلح هذا التصميم ثلاثة أخطاء متداخلة في `AdvancedReportsHandler::getProfitLossReport()` تُسبّب ظهور `total_revenue` سالباً وأرقام ربح غير صحيحة:

1. **معالجة التواريخ الخام**: الدالة تمرّر `start_date`/`end_date` مباشرةً إلى `DATE(col) BETWEEN` في SQL دون تطبيع، مما يُسبّب استبعاد سجلات عند إرسال ISO 8601 timestamps.
2. **غياب فلتر نوع المرتجع في الإيراد**: استعلام `$returnsSql` يشمل كل المرتجعات (بما فيها `return_type = 'purchase'`) فيُقلّص الإيراد بشكل خاطئ. *(ملاحظة: تبيّن من قراءة الكود أن الفلتر `return_type = 'sale'` موجود فعلاً في `$returnsSql`، لكنه مفقود في استعلام returns-COGS sub-query في بعض مسارات الـ `cogs` mode — التصميم يُوحِّد الحالتين.)*
3. **عدم اتساق نطاق الاستعلامات**: كل استعلام داخل الدالة يُطبِّق منطق التاريخ بطريقة مختلفة بدلاً من مصدر موحَّد واحد.

النهج: استخدام `DateRangeResolver::resolve()` مرة واحدة في بداية الدالة وتمرير `[$from, $toExclusive]` إلى جميع الاستعلامات.

---

## Architecture

### المكوّنات المعنية

```
AdvancedReportsHandler::getProfitLossReport()
    │
    ├── DateRangeResolver::resolve()          ← مرجع موحَّد للتاريخ (موجود، يُستخدم للمرة الأولى هنا)
    │
    ├── revenueSql          (sales)           ← تغيير: DATE(BETWEEN) → half-open range
    ├── returnsSql          (returns)         ← تغيير: DATE(BETWEEN) → half-open range
    │                                            تأكيد: return_type = 'sale' موجود
    ├── expensesSql1        (cash_vouchers)   ← cv.date هو DATE column: يبقى BETWEEN بعد substr
    ├── expensesSql2        (cash_transactions) ← تغيير: DATE(BETWEEN) → half-open range
    │
    └── [mode = 'cogs']
        ├── cogsFromAccounting (journal_entry_lines) ← تغيير: DATE(BETWEEN) → half-open range
        └── fallback CostingService
            ├── periodSales query             ← تغيير: DATE(BETWEEN) → half-open range
            └── returnedProducts query        ← تأكيد: return_type = 'sale' موجود
```

### تدفق البيانات بعد الإصلاح

```
HTTP GET /reports/profit-loss
  ?start_date=2026-08-31T21:00:00.000Z
  &end_date=2026-09-30T20:59:59.000Z
          │
          ▼
DateRangeResolver::resolve(start_date, end_date)
  → $from        = '2026-08-31 00:00:00'
  → $toExclusive = '2026-10-01 00:00:00'
          │
          ▼
revenueSql:   sales.created_at >= $from AND sales.created_at < $toExclusive
returnsSql:   returns.created_at >= $from AND returns.created_at < $toExclusive
              AND return_type = 'sale'   ← موجود بالفعل
expensesSql2: cash_transactions.created_at >= $from AND created_at < $toExclusive
cogsSQL:      je.entry_date >= $from AND je.entry_date < $toExclusive
periodSales:  sales.created_at >= $from AND sales.created_at < $toExclusive
          │
          ▼
NetRevenue = total_sales - total_returns  (returns_type='sale' only)
GrossProfit = NetRevenue - netCOGS        (if mode='cogs')
NetProfit  = GrossProfit - totalExpenses
          │
          ▼
JSON Response (same structure as before)
  period.start_date = substr($from, 0, 10)   → '2026-08-31'
  period.end_date   = substr(toExclusive -1day) → '2026-09-30'
```

---

## Component Design

### 1. DateRangeResolver Integration

**قبل الإصلاح:**
```php
$startDate = $params['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$endDate   = $params['end_date'] ?? date('Y-m-d');
// ...
AND DATE(s.created_at) BETWEEN :start_date AND :end_date
// bind: ':start_date' => $startDate, ':end_date' => $endDate
```

**بعد الإصلاح:**
```php
use App\Services\DateRangeResolver;

// في بداية getProfitLossReport() بعد قراءة $params:
[$from, $toExclusive] = DateRangeResolver::resolve(
    $params['start_date'] ?? null,
    $params['end_date']   ?? null,
    30   // defaultDaysBack
);

// استخراج YYYY-MM-DD للاستجابة فقط (لا للاستعلامات)
$startDateDisplay = substr($from, 0, 10);
$endDateDisplay   = date('Y-m-d', strtotime($toExclusive . ' -1 day'));
```

---

### 2. تغييرات استعلامات SQL

#### 2.1 revenueSql

**قبل:**
```sql
WHERE s.tenant_id = :tenant_id
  AND DATE(s.created_at) BETWEEN :start_date AND :end_date
```

**بعد:**
```sql
WHERE s.tenant_id = :tenant_id
  AND s.created_at >= :from
  AND s.created_at <  :to_exclusive
```

```php
$stmt->execute([
    ':tenant_id'    => $tenantId,
    ':from'         => $from,
    ':to_exclusive' => $toExclusive,
]);
```

---

#### 2.2 returnsSql

**قبل:**
```sql
WHERE r.tenant_id = :tenant_id
  AND r.return_type = 'sale'
  AND DATE(r.created_at) BETWEEN :start_date AND :end_date
```

**بعد:**
```sql
WHERE r.tenant_id = :tenant_id
  AND r.return_type = 'sale'
  AND r.created_at >= :from
  AND r.created_at <  :to_exclusive
```

*(الفلتر `return_type = 'sale'` كان موجوداً — لا تغيير فيه)*

---

#### 2.3 expensesSql1 (cash_vouchers)

عمود `cv.date` هو `DATE` في قاعدة البيانات (ليس `DATETIME`). يكفي تمرير جزء التاريخ فقط. `DateRangeResolver::resolve()` يُنتج `$from = 'YYYY-MM-DD 00:00:00'`، لذا نأخذ `substr($from, 0, 10)` و`substr($toExclusive, 0, 10)` مع تعديل حدّ النهاية:

**قبل:**
```sql
AND cv.date BETWEEN :start_date AND :end_date
// bind: $startDate, $endDate
```

**بعد:**
```sql
AND cv.date >= :from_date AND cv.date < :to_date
// bind: substr($from, 0, 10), substr($toExclusive, 0, 10)
```

*(النطاق النصف المفتوح يعمل مع أعمدة DATE أيضاً)*

---

#### 2.4 expensesSql2 (cash_transactions)

**قبل:**
```sql
WHERE tenant_id = :tenant_id
  AND type = 'expense'
  AND DATE(created_at) BETWEEN :start_date AND :end_date
```

**بعد:**
```sql
WHERE tenant_id = :tenant_id
  AND type = 'expense'
  AND created_at >= :from
  AND created_at <  :to_exclusive
```

---

#### 2.5 COGS من journal_entry_lines (mode = 'cogs')

**قبل:**
```sql
WHERE jel.account_id = :acc
  AND je.tenant_id = :tenant
  AND DATE(je.entry_date) BETWEEN :start AND :end
```

**بعد:**
```sql
WHERE jel.account_id = :acc
  AND je.tenant_id = :tenant
  AND je.entry_date >= :from
  AND je.entry_date <  :to_exclusive
```

```php
$stmt->execute([
    ':acc'          => $cogsAccountId,
    ':tenant'       => $tenantId,
    ':from'         => $from,
    ':to_exclusive' => $toExclusive,
]);
```

---

#### 2.6 periodSales (CostingService fallback)

**قبل:**
```sql
SELECT id, sale_date FROM sales
WHERE tenant_id = :tenant_id
  AND DATE(created_at) BETWEEN :start_date AND :end_date
```

**بعد:**
```sql
SELECT id, sale_date FROM sales
WHERE tenant_id = :tenant_id
  AND created_at >= :from
  AND created_at <  :to_exclusive
```

---

#### 2.7 returnedProducts sub-query (CostingService fallback)

هذا الاستعلام موجود تحت مسار `cogsSource = 'costing_engine_wac'`. الفلتر `return_type = 'sale'` موجود بالفعل؛ نُغيِّر تطبيق التاريخ فقط:

**قبل:**
```sql
WHERE r.tenant_id = :tenant_id
  AND r.return_type = 'sale'
  AND DATE(r.created_at) BETWEEN :start_date AND :end_date
```

**بعد:**
```sql
WHERE r.tenant_id = :tenant_id
  AND r.return_type = 'sale'
  AND r.created_at >= :from
  AND r.created_at <  :to_exclusive
```

---

### 3. استجابة period

**قبل:**
```php
'period' => [
    'start_date' => $startDate,   // قد يكون ISO timestamp
    'end_date'   => $endDate
]
```

**بعد:**
```php
'period' => [
    'start_date' => $startDateDisplay,   // دائماً YYYY-MM-DD
    'end_date'   => $endDateDisplay      // دائماً YYYY-MM-DD
]
```

---

## Data Models

لا تغيير في مخطط قاعدة البيانات. جميع التغييرات في طبقة الاستعلام فقط.

### جداول مُستعلَم عنها (بدون تغيير)

| الجدول | العمود الزمني | النوع في MySQL |
|--------|--------------|----------------|
| `sales` | `created_at` | `DATETIME` |
| `returns` | `created_at` | `DATETIME` |
| `cash_vouchers` | `date` | `DATE` |
| `cash_transactions` | `created_at` | `DATETIME` |
| `journal_entry_lines` / `journal_entries` | `entry_date` | `DATE` أو `DATETIME` |

---

## Error Handling

لا تغيير في معالجة الأخطاء. الـ try-catch الموجودة تبقى كما هي:

- `PDOException` → HTTP 500 + رسالة عربية
- `Throwable` → HTTP 500 + رسالة عامة
- `$tenantId` null → HTTP 403

---

## Implementation Notes

1. **الاستيراد**: `DateRangeResolver` تقع في `App\Services\DateRangeResolver`. إذا لم يكن `use App\Services\DateRangeResolver;` موجوداً في رأس الملف، يجب إضافته.
2. **اسم المعاملات**: جميع الاستعلامات المُعدَّلة تستخدم `:from` و`:to_exclusive` بدلاً من `:start_date`/`:end_date` — هذا يُسهِّل مراجعة الكود لاحقاً ويُوضِّح أن النطاق نصف مفتوح.
3. **expensesSql1 تحديداً**: يُستخدم `substr($from, 0, 10)` و`substr($toExclusive, 0, 10)` لأن العمود `cv.date` من نوع `DATE`؛ إرسال datetime كامل له آمن لكن استخدام التاريخ فقط أوضح.
4. **لا تغيير في منطق الحساب**: معادلات `NetRevenue`، `GrossProfit`، `NetProfit` تبقى كما هي.
5. **لا تغيير في بنية الاستجابة**: جميع مفاتيح JSON وترتيبها تبقى دون تعديل.

---

## Correctness Properties

*A property is a characteristic or behavior that should hold true across all valid executions of a system — essentially, a formal statement about what the system should do. Properties serve as the bridge between human-readable specifications and machine-verifiable correctness guarantees.*

### Property 1: DateRangeResolver ينتج نطاقاً نصف مفتوحاً صحيحاً

*For any* string مُمرَّر كـ `start_date` (سواء كان ISO 8601 timestamp، أو `YYYY-MM-DD`، أو فارغاً)، يجب أن يُنتج `DateRangeResolver::resolve()` قيمة `fromInclusive` تنتهي بـ `' 00:00:00'` وقيمة `toExclusive` تُمثِّل بداية اليوم التالي لتاريخ النهاية، بحيث `toExclusive > fromInclusive`.

**Validates: Requirements 1.1, 1.2, 1.3**

---

### Property 2: الإيراد الصافي يساوي المبيعات مطروحاً منها مرتجعات المبيعات فقط

*For any* نطاق زمني، يجب أن يكون `total_revenue = sum(sales.total_amount) - sum(returns.total_amount WHERE return_type = 'sale')`. أي مرتجع بـ `return_type = 'purchase'` في نفس النطاق يجب ألا يؤثر على قيمة `total_revenue`.

**Validates: Requirements 2.1, 2.2, 2.3, 4.5**

---

### Property 3: عدم سلبية الإيراد عند غياب المبيعات

*For any* نطاق زمني لا تحتوي قاعدة البيانات فيه على مبيعات، يجب أن يُعيد التقرير `total_revenue = 0` وليس قيمة سالبة.

**Validates: Requirements 2.4**

---

### Property 4: اتساق الحسابات في summary

*For any* مجموعة صحيحة من `total_revenue` و`total_expenses` و`gross_profit` و`cogs`، يجب أن تنطبق المعادلات التالية دائماً:
- `summary.net_profit = summary.gross_profit - summary.total_expenses`
- `summary.total_revenue = revenue.total_revenue`
- `summary.gross_profit = revenue.gross_profit`

**Validates: Requirements 4.1, 4.2, 4.3**

---

### Property 5: جميع استعلامات التقرير تستخدم نفس النطاق الزمني

*For any* مدخل `start_date`/`end_date`، يجب أن تستخدم جميع الاستعلامات (revenueSql، returnsSql، expensesSql، COGS queries) نفس قيمتَي `[$from, $toExclusive]` المُشتقَّتَين من `DateRangeResolver::resolve()` مرة واحدة.

**Validates: Requirements 1.2, 3.1, 3.2**
