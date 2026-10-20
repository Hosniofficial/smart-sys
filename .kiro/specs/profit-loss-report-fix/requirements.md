# Requirements Document

## Introduction

تقرير الأرباح والخسائر في SmartSys (`GET /reports/profit-loss`) يُعيد حالياً قيماً خاطئة؛
`total_revenue` سالب وأرقام الربح غير متسقة، بينما البيانات الفعلية في قاعدة البيانات صحيحة.
سبب المشكلة هو ثلاثة أخطاء متداخلة في `AdvancedReportsHandler::getProfitLossReport()`:

1. **تطبيع التواريخ**: الدالة لا تستخدم `DateRangeResolver` وتمرّر الـ ISO 8601 timestamp مباشرةً إلى `DATE()` في SQL مما قد يُسبّب حذف سجلات من طرفَي النطاق.
2. **فلتر مرتجعات الإيراد**: استعلام المرتجعات لا يُفلتر بـ `return_type = 'sale'`، فيشمل مرتجعات المشتريات (`return_type = 'purchase'`) التي تُقلّص الإيراد بشكل خاطئ.
3. **scope التأكد**: لا يوجد تحقق موحّد يضمن أن كل استعلام في التقرير يستخدم نفس منطق النطاق الزمني.

هذا الـ spec يُعرِّف متطلبات إصلاح هذه الأخطاء الثلاثة دون تغيير بنية الاستجابة أو سلوك الـ endpoint للمستدعين الحاليين.

---

## Glossary

- **ProfitLossReport**: تقرير الأرباح والخسائر، يُعيده `AdvancedReportsHandler::getProfitLossReport()`.
- **DateRangeResolver**: خدمة `App\Services\DateRangeResolver` — المرجع الوحيد لتطبيع نطاقات التواريخ في النظام. تُحوّل أي صيغة تاريخ مُدخلة إلى نطاق نصف مفتوح `[from, to)` جاهز للـ SQL.
- **Half-Open Range**: نطاق `created_at >= from AND created_at < to_exclusive` حيث `to_exclusive = endDate + 1 day 00:00:00`. يضمن تغطية كامل اليوم الأخير.
- **SaleReturn**: مرتجع مبيعات — صف في جدول `returns` بقيمة `return_type = 'sale'`. يُخفّض الإيراد.
- **PurchaseReturn**: مرتجع مشتريات — صف في جدول `returns` بقيمة `return_type = 'purchase'`. لا يؤثر على إيراد المبيعات.
- **COGS**: تكلفة البضاعة المباعة (Cost of Goods Sold).
- **WAC**: المتوسط المرجّح للتكلفة (Weighted Average Cost).
- **NetRevenue**: إجمالي المبيعات مطروحاً منه إجمالي مرتجعات المبيعات فقط.
- **GrossProfit**: NetRevenue ناقص COGS.
- **NetProfit**: GrossProfit ناقص إجمالي المصروفات.
- **TenantId**: معرّف المؤسسة/المستأجر في النظام متعدد المستأجرين.

---

## Requirements

### Requirement 1: توحيد تطبيع نطاق التواريخ

**User Story:** كمطوّر يُصلح التقرير، أريد أن يستخدم `getProfitLossReport` خدمة `DateRangeResolver` لتطبيع التواريخ، حتى تكون جميع الاستعلامات داخل الدالة متسقة ومغطية للنطاق الزمني الصحيح كاملاً.

#### Acceptance Criteria

1. WHEN يستقبل `ProfitLossReport` معاملَي `start_date` و`end_date` بأي صيغة (ISO 8601، أو `YYYY-MM-DD`، أو فارغَين)، THE `ProfitLossReport` SHALL يستدعي `DateRangeResolver::resolve()` لتطبيعهما إلى `[fromInclusive, toExclusive]` قبل تنفيذ أي استعلام.
2. THE `ProfitLossReport` SHALL يستخدم النطاق النصف المفتوح `{column} >= :from AND {column} < :to_exclusive` في جميع استعلامات SQL داخل الدالة، بديلاً عن `DATE({column}) BETWEEN :start AND :end`.
3. WHEN تكون `start_date` و`end_date` فارغتَين، THE `ProfitLossReport` SHALL يطبّق نطاقاً افتراضياً مدته 30 يوماً يبدأ من اليوم الحالي.
4. THE `ProfitLossReport` SHALL يُعيد `period.start_date` و`period.end_date` في الاستجابة بصيغة `YYYY-MM-DD` بغض النظر عن صيغة المدخلات الأصلية.

---

### Requirement 2: استبعاد مرتجعات المشتريات من حساب الإيراد

**User Story:** كمستخدم يطّلع على تقرير الأرباح والخسائر، أريد أن يعكس `total_revenue` إجمالي المبيعات مطروحاً منه مرتجعات المبيعات فقط، حتى لا تُشوّه مرتجعات المشتريات قيمة الإيراد.

#### Acceptance Criteria

1. THE `ProfitLossReport` SHALL يحسب `NetRevenue` بطرح مجموع `total_amount` لصفوف `returns` ذات `return_type = 'sale'` فقط من إجمالي مبيعات الفترة.
2. IF وُجدت صفوف `returns` بقيمة `return_type = 'purchase'` في نطاق التاريخ، THEN THE `ProfitLossReport` SHALL يتجاهل هذه الصفوف تماماً عند حساب `NetRevenue`.
3. THE `ProfitLossReport` SHALL يُعيد قيمة `total_revenue` مساوية لـ `NetRevenue` المحسوبة وفق البنود 1 و2.
4. WHEN لا توجد مبيعات في الفترة المحددة، THE `ProfitLossReport` SHALL يُعيد `total_revenue` بقيمة `0` بدلاً من قيمة سالبة.

---

### Requirement 3: اتساق COGS مع نطاق التاريخ المُطبَّق

**User Story:** كمستخدم يتحقق من دقة التقرير، أريد أن تُحسب تكلفة البضاعة المباعة (COGS) للمبيعات الواقعة في نفس النطاق الزمني المُطبَّق على الإيراد، حتى يكون الربح الإجمالي صحيحاً.

#### Acceptance Criteria

1. WHEN يكون `mode = 'cogs'`، THE `ProfitLossReport` SHALL يستعلم عن المبيعات الخاضعة للحساب باستخدام نفس `[fromInclusive, toExclusive]` المُستخرج من `DateRangeResolver`.
2. WHEN يكون `mode = 'cogs'` وكانت بيانات COGS متاحة من القيود المحاسبية، THE `ProfitLossReport` SHALL يستعلم عن جدول `journal_entry_lines` باستخدام نفس النطاق النصف المفتوح.
3. WHEN يكون `mode = 'cogs'`، THE `ProfitLossReport` SHALL يحسب `gross_profit = total_revenue - (cogs - returns_cogs)`.
4. WHEN يكون `mode` أي قيمة غير `'cogs'`، THE `ProfitLossReport` SHALL يحسب `gross_profit = total_revenue` ويُعيد `cogs` بقيمة `0`.

---

### Requirement 4: صحة القيم المحسوبة في الاستجابة

**User Story:** كمستخدم يقرأ التقرير، أريد أن تكون جميع حقول الملخص (`summary`) متناسقة ومشتقة من نفس المعادلات، حتى أستطيع الاعتماد على الأرقام في اتخاذ قرارات مالية.

#### Acceptance Criteria

1. THE `ProfitLossReport` SHALL يُعيد `summary.gross_profit` مساوياً للقيمة المحسوبة في كتلة `revenue`.
2. THE `ProfitLossReport` SHALL يُعيد `summary.net_profit = gross_profit - total_expenses`.
3. THE `ProfitLossReport` SHALL يُعيد `summary.total_revenue` مساوياً لـ `NetRevenue` (المبيعات مطروحاً منها مرتجعات المبيعات فقط).
4. IF كانت قيمة `total_revenue` أو `gross_profit` أو `net_profit` صحيحة حسابياً وكانت سالبة (مثل خسارة حقيقية)، THEN THE `ProfitLossReport` SHALL يُعيدها سالبة كما هي دون تعديل.
5. THE `ProfitLossReport` SHALL لا يُعيد `total_revenue` قيمة سالبة ناتجة عن تضمين مرتجعات المشتريات في حساب الخصم من الإيراد.

---

### Requirement 5: عدم تغيير عقد الاستجابة

**User Story:** كمستدعٍ لـ API، أريد أن تبقى بنية الاستجابة JSON كما هي بعد الإصلاح، حتى لا يحتاج أي client-side code إلى تعديل.

#### Acceptance Criteria

1. THE `ProfitLossReport` SHALL يُعيد استجابة JSON تحتوي على مفاتيح `period`، `revenue`، `account_transactions`، و`summary` على المستوى الأول.
2. THE `ProfitLossReport` SHALL يُعيد ضمن `revenue` المفاتيح: `total_revenue`، `gross_profit`، `cogs`، `cogs_source`.
3. THE `ProfitLossReport` SHALL يُعيد ضمن `summary` المفاتيح: `total_revenue`، `total_expenses`، `gross_profit`، `cogs`، `net_profit`.
4. IF حدث خطأ في قاعدة البيانات، THEN THE `ProfitLossReport` SHALL يُعيد HTTP 500 مع رسالة خطأ عربية دون كشف تفاصيل الاستثناء.
5. IF كانت قيمة `tenant_id` غير موجودة أو غير صالحة، THEN THE `ProfitLossReport` SHALL يُعيد HTTP 403.
