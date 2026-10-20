# Implementation Plan: profit-loss-report-fix

## Overview

إصلاح `AdvancedReportsHandler::getProfitLossReport()` بتوحيد معالجة التواريخ عبر `DateRangeResolver`، وتحويل جميع استعلامات SQL إلى نطاق نصف مفتوح `[from, toExclusive)`، وضمان أن حساب الإيراد لا يشمل سوى مرتجعات المبيعات. جميع التغييرات في ملف واحد فقط.

## Tasks

- [x] 1. إضافة استيراد DateRangeResolver وتطبيع التواريخ
  - [x] 1.1 إضافة `use App\Services\DateRangeResolver;` في رأس `AdvancedReportsHandler.php` إن لم تكن موجودة
    - تحقق من السطور الأولى من الملف — الاستيراد غير موجود حالياً
    - _Requirements: 1.1_

  - [x] 1.2 استبدال تعريف `$startDate`/`$endDate` باستدعاء `DateRangeResolver::resolve()`
    - أضف السطرين:
      ```php
      [$from, $toExclusive] = DateRangeResolver::resolve(
          $params['start_date'] ?? null,
          $params['end_date']   ?? null,
          30
      );
      $startDateDisplay = substr($from, 0, 10);
      $endDateDisplay   = date('Y-m-d', strtotime($toExclusive . ' -1 day'));
      ```
    - احذف السطرَين القديمَين `$startDate = ...` و`$endDate = ...`
    - _Requirements: 1.1, 1.3_

  - [ ]* 1.3 اكتب property test للتحقق من أن `DateRangeResolver::resolve()` يُنتج نطاقاً نصف مفتوحاً صحيحاً
    - **Property 1: DateRangeResolver ينتج نطاقاً نصف مفتوحاً صحيحاً**
    - **Validates: Requirements 1.1, 1.2, 1.3**
    - لكل إدخال عشوائي (ISO 8601 أو YYYY-MM-DD أو null)، تحقق أن `$from` ينتهي بـ `' 00:00:00'` وأن `$toExclusive > $from`

- [x] 2. تعديل استعلامات الإيراد والمرتجعات
  - [x] 2.1 تعديل `revenueSql` — استبدال `DATE(s.created_at) BETWEEN :start_date AND :end_date` بـ `s.created_at >= :from AND s.created_at < :to_exclusive`
    - حدّث ربط المعاملات ليستخدم `:from` و`:to_exclusive`
    - _Requirements: 1.2, 3.1_

  - [x] 2.2 تعديل `returnsSql` — استبدال `DATE(r.created_at) BETWEEN` بنطاق نصف مفتوح
    - تأكيد أن `r.return_type = 'sale'` موجود (لا تغيير مطلوب فيه)
    - حدّث ربط المعاملات ليستخدم `:from` و`:to_exclusive`
    - _Requirements: 1.2, 2.1, 2.2_

  - [ ]* 2.3 اكتب property test للتحقق من أن الإيراد الصافي = مبيعات − مرتجعات مبيعات فقط
    - **Property 2: الإيراد الصافي يساوي المبيعات مطروحاً منها مرتجعات المبيعات فقط**
    - **Validates: Requirements 2.1, 2.2, 2.3, 4.5**
    - حاكِ سيناريوهات تحتوي على مرتجعات `return_type = 'purchase'` وتأكد أنها لا تؤثر على `total_revenue`

  - [ ]* 2.4 اكتب property test للتحقق من عدم سلبية الإيراد عند غياب المبيعات
    - **Property 3: عدم سلبية الإيراد عند غياب المبيعات**
    - **Validates: Requirements 2.4**
    - لأي نطاق زمني لا تحتوي فيه `sales` على صفوف، يجب أن يكون `total_revenue = 0`

- [x] 3. Checkpoint — تأكد من صحة استعلامات الإيراد
  - Ensure all tests pass, ask the user if questions arise.

- [x] 4. تعديل استعلامات المصروفات
  - [x] 4.1 تعديل `expensesSql1` (cash_vouchers) — استبدال `cv.date BETWEEN :start_date AND :end_date` بـ `cv.date >= :from_date AND cv.date < :to_date`
    - `cv.date` عمود `DATE` — استخدم `substr($from, 0, 10)` و`substr($toExclusive, 0, 10)`
    - حدّث ربط المعاملات بـ `:from_date` و`:to_date`
    - _Requirements: 1.2_

  - [x] 4.2 تعديل `expensesSql2` (cash_transactions) — استبدال `DATE(created_at) BETWEEN` بنطاق نصف مفتوح على `created_at`
    - حدّث ربط المعاملات ليستخدم `:from` و`:to_exclusive`
    - _Requirements: 1.2_

- [x] 5. تعديل استعلامات COGS (mode = 'cogs')
  - [x] 5.1 تعديل استعلام `journal_entry_lines` — استبدال `DATE(je.entry_date) BETWEEN :start AND :end` بـ `je.entry_date >= :from AND je.entry_date < :to_exclusive`
    - حدّث ربط المعاملات بـ `:from` و`:to_exclusive`
    - _Requirements: 1.2, 3.2_

  - [x] 5.2 تعديل استعلام `periodSales` (CostingService fallback) — استبدال `DATE(created_at) BETWEEN :start_date AND :end_date` بنطاق نصف مفتوح
    - حدّث ربط المعاملات ليستخدم `:from` و`:to_exclusive`
    - _Requirements: 1.2, 3.1_

  - [x] 5.3 تعديل استعلام `returnedProducts` — استبدال `DATE(r.created_at) BETWEEN :start_date AND :end_date` بنطاق نصف مفتوح
    - تأكيد أن `r.return_type = 'sale'` موجود
    - حدّث ربط المعاملات ليستخدم `:from` و`:to_exclusive`
    - _Requirements: 1.2, 3.1_

  - [ ]* 5.4 اكتب property test للتحقق من اتساق جميع الاستعلامات على نفس النطاق
    - **Property 5: جميع استعلامات التقرير تستخدم نفس النطاق الزمني**
    - **Validates: Requirements 1.2, 3.1, 3.2**
    - تأكد أن جميع bindings تحمل نفس `$from` و`$toExclusive` المشتقَّين مرة واحدة

- [x] 6. تحديث حقول `period` في الاستجابة
  - [x] 6.1 استبدال `'start_date' => $startDate` و`'end_date' => $endDate` بـ `$startDateDisplay` و`$endDateDisplay` في مصفوفة `$result['period']`
    - النتيجة دائماً `YYYY-MM-DD` بغض النظر عن صيغة الإدخال
    - _Requirements: 1.4, 5.1_

  - [ ]* 6.2 اكتب property test للتحقق من اتساق حقول `summary`
    - **Property 4: اتساق الحسابات في summary**
    - **Validates: Requirements 4.1, 4.2, 4.3**
    - تأكد أن `net_profit = gross_profit - total_expenses` و`summary.total_revenue = revenue.total_revenue` و`summary.gross_profit = revenue.gross_profit`

- [ ] 7. Final Checkpoint — تشغيل كامل
  - Ensure all tests pass, ask the user if questions arise.

## Notes

- المهام المُعلَّمة بـ `*` اختيارية ويمكن تخطيها للحصول على MVP أسرع
- جميع التغييرات في ملف واحد فقط: `api/v1/src/Handlers/AdvancedReportsHandler.php`
- لا تغيير في بنية الاستجابة JSON ولا في منطق الحساب — فقط تطبيع التواريخ وتوحيد الاستعلامات
- الفلتر `return_type = 'sale'` موجود مسبقاً في `returnsSql` و`returnedProducts`، يكفي التأكيد لا الإضافة
- اسم المعاملات يتغير من `:start_date`/`:end_date` إلى `:from`/`:to_exclusive` — يُوضِّح أن النطاق نصف مفتوح
- `expensesSql1` يستخدم `:from_date`/`:to_date` (تاريخ فقط) لأن `cv.date` من نوع `DATE`

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1.1"] },
    { "id": 1, "tasks": ["1.2"] },
    { "id": 2, "tasks": ["1.3", "2.1", "2.2"] },
    { "id": 3, "tasks": ["2.3", "2.4", "4.1", "4.2", "5.1", "5.2", "5.3"] },
    { "id": 4, "tasks": ["5.4", "6.1"] },
    { "id": 5, "tasks": ["6.2"] }
  ]
}
```
