# مراجعة وتوحيد دوال التنسيق

تم حصر الدوال المتكررة في الواجهة وتوحيد السلوك المتطابق فقط. لم تتغير إعدادات اللغة أو الدقة أو خيارات التاريخ أو القيم الاحتياطية المعروضة.

## الدوال التي تم توحيدها

- أغلفة `formatCurrency` و`formatPrice` التي كانت تستدعي `formatCurrencyLocale(value, 2)` أصبحت تشير مباشرة إلى الدالة المشتركة في:
  - `src/components/PurchaseForm.vue`
  - `src/views/admin/DataIntegrity.vue`
  - `src/views/contacts/CustomersManagement.vue` و`SuppliersManagement.vue`
  - `src/views/dashboards/CashierDashboard.vue` و`Dashboard.vue`
  - `src/views/inventory/InventoryManagement.vue`
  - `src/views/purchases/PurchaseHistory.vue` و`PurchaseManagement.vue`
  - `src/views/repairs/RepairOrders.vue`
  - `src/views/returns/ReturnsHistory.vue` و`ReturnsManagement.vue`
  - `src/views/reports/CashVouchersReport.vue` و`InventoryMovementsReport.vue` و`InventoryValueBybranch.vue` و`InventoryValueReport.vue` و`SalesAnalyticsReport.vue` و`SalesSummaryReport.vue`
  - `src/views/sales/SalesApprovals.vue` و`SalesHistory.vue` و`SalesPoint.vue`
  - `src/views/sessions/SessionsList.vue`
  - `src/views/vouchers/CashVouchers.vue`
- نسخ تنسيق الأرقام الخمس التي كانت تستخدم `(Number(value) || 0).toLocaleString('en-US')` تستخدم الآن `formatNumberEnUS` في:
  - `src/views/dashboards/CashierDashboard.vue`
  - `src/views/reports/InventoryMovementsReport.vue`
  - `src/views/reports/InventoryValueReport.vue`
  - `src/views/reports/SalesAnalyticsReport.vue`
  - `src/views/reports/SalesSummaryReport.vue`
- `OpeningBalance.vue` يستخدم `formatNumberEnUS` بخيارات `maximumFractionDigits: 4` و`minimumFractionDigits/maximumFractionDigits: 2` بدل تكرار المنطق؛ الأداة تقبل خيارات locale اختيارية وتحافظ على `Number(value) || 0`.
- نسختا `formatPrice` المتطابقتان في `src/components/VoucherForm.vue` و`ReturnForm.vue` تستخدمان الآن `formatPriceEnUSMinimumTwoDecimals`. تحافظ الأداة على `'0'` للقيم الفارغة/الصفرية، وعلى `en-US` ومنزلتين عشريتين كحد أدنى (دون حد أعلى).
- تنسيق السعر الثابت بمنزلتين في `ContactDetails.vue` و`AccountStatement.vue` يستخدم الآن `formatPriceEnUSFixedTwoDecimals`. النسخ البديلة (`formatPriceEn` و`formatPriceEn` داخل الملفين) تشير إلى الدالة نفسها دون تغيير نتائجها.
- تنسيق السعر في `SalesQuotations.vue` يستخدم `formatPriceEnUSFixedTwoDecimalsOrZero`؛ وهو يطابق تحويل `Number(value) || 0` تحديدًا.
- إعداد `Intl.NumberFormat('ar-EG', { notation: 'compact', maximumFractionDigits: 1 })` مشترك الآن بين رسم ومؤشرات لوحتي Dashboard وCashierDashboard باستخدام formatter واحد.
- نسخ تنسيق التاريخ والتاريخ-والوقت في `ReturnsManagement.vue` و`ReturnsHistory.vue`، ونسختا تنسيق الوقت في `SalesSummaryReport.vue` و`SessionsList.vue` تستخدم الآن أدوات مشتركة تستدعي `Date.toLocale*` مباشرةً مع نفس locale والخيارات، وتحافظ على السلوك السابق عند القيم الفارغة والتواريخ غير الصالحة.
- دالتا `formatReturnDateTime` المتطابقتان في شاشتي المرتجعات أصبحتا أداة مشتركة، مع الإبقاء على أولوية وقت تاريخ المرتجع ثم وقت الإنشاء، وعلى fallback التاريخ وحده.
- دالتا `formatDateTime` المتطابقتان في `PurchaseManagement.vue` و`PurchaseHistory.vue` تستخدمان `formatDateTimeWithSqlSpace`؛ تم الإبقاء على استبدال المسافة بـ`T` وعلى إعادة النص كما هو عند التاريخ غير الصالح.
- تنسيق التاريخ والوقت في `SessionsList.vue` وقالب طباعة الجلسة يستخدم الأداة المشتركة ذات `en-US` والخيارات نفسها، مع الإبقاء على الشرطة عند القيمة الفارغة.
- تنسيق التاريخ والوقت في `SalesHistory.vue` و`SalesApprovals.vue` يستخدم الأداة المشتركة ذات الخيارات نفسها؛ احتُفظ بغلاف `try/catch` الأصلي في شاشة الموافقات.
- تنسيقا التاريخ القابل للخيارات في `SalesAnalyticsReport.vue` و`InventoryMovementsReport.vue` يستخدمان الأداة المشتركة نفسها مع الإبقاء على `en-US` والخيارات الخاصة بكل استدعاء.
- `SalesPoint.vue` و`CashVouchersReport.vue` يستخدمان أداة التاريخ نفسها مع الحفاظ على fallback المختلف بين `'-'` والسلسلة الفارغة.
- `Dashboard.vue` و`CashierDashboard.vue` و`PaymentsList.vue` تستخدم أداة الوقت المشتركة بنفس locale والخيارات.
- تكرار عرض تاريخ التحويل في جدولين داخل `BranchDetails.vue` أصبح دالة محلية واحدة؛ أبقيت استدعاء `Date` دون guard كي لا يتغير عرض `Invalid Date` عند قيمة مفقودة.
- الدالتان المتطابقتان لاستخراج اليوم المحلي في `SalesQuotations.vue` و`SalesPriceCampaigns.vue` تستخدمان `formatLocalDateDayEnUS`، مع الحفاظ على معالجة اليوم المحلي والشرطة للقيم الفارغة وغير الصالحة.
- في `ContactDetails.vue`، الاسمان `formatDate`/`formatDateEn` و`formatPrice`/`formatPriceEn` كان لكل زوج نفس التنفيذ تماماً؛ أصبحت أسماء العرض البديلة تشير للدالة نفسها.

## دوال أُبقيت لاختلاف مقصود

- `formatCurrencyLocale` و`formatCurrency` في `src/composables/useCompanyCurrency.js` ليستا قابلتين للدمج: الأولى تستخدم رمز عملة المنشأة مع locale ثابت `en-US`، بينما الثانية تضيف رمز العملة نصيًا بصيغة ثابتة.
- `src/utils/formatters/numbers.js` يحتوي `formatNumber` و`formatPrice` بقواعد مختلفة عن النسخ الموحدة: locale افتراضي للأرقام، دقة ثابتة أو قابلة للتخصيص، ورمز عملة في `formatCurrency`.
- `src/views/reports/ProfitLossReport.vue` يطلب دقة صفر صراحةً؛ لم يُحوّل إلى دقة منزلتين.
- `formatPriceEnUSFixedTwoDecimalsOrZero` منفصل عن `formatPriceEnUSFixedTwoDecimals`: الأول يحوّل الإدخال غير الرقمي إلى صفر كما في عروض الأسعار، والثاني يحافظ على مخرجات `Intl.NumberFormat` مثل `NaN` في كشف الحساب/تفاصيل الاتصال.
- `src/views/contacts/AccountStatement.vue` يستخدم `en-GB` للتاريخ وتنسيق سعر `en-US` بلا رمز عملة؛ لم يُدمج مع تنسيق عملة الشركة.
- `src/views/inventory/InventoryManagement.vue` يعرض التاريخ بـ`ar-SA` ويستخدم شرطة للتاريخ الغائب؛ بقي تنسيق التاريخ دون تغيير.
- `src/components/VoucherForm.vue` و`ReturnForm.vue` يختلفان عن `formatPrice` في `numbers.js`: القيم الفارغة أو الصفرية تعرض `'0'`، ويُستخدم حد أدنى للكسور دون حد أقصى؛ لذلك لم تُستبدل بدالة السعر العامة.
- `formatPriceEnUSFixedTwoDecimals` يختلف عن `formatPrice` العام عند النص غير الرقمي: يحافظ على `Intl.NumberFormat` للـ`NaN` بدل إرجاع `'0.00'`، لذا بقيت له أداة مستقلة.
- صيغ التاريخ التي تستخدم `ar-SA` أو `en-GB` أو خيارات يوم/شهر مختلفة، أو تُرجع شرطة/قيمة الإدخال عند غياب التاريخ أو عدم صلاحيته، بقيت محلية للحفاظ على نتائج الصفحات.
- التنسيقات المعتمدة على `Intl.NumberFormat` أو تنسيق سعر بلا رمز عملة لم تُدمج مع `formatCurrencyLocale`.

## فروق قد تظهر للمستخدم

- رمز العملة ومكانه قد يختلفان بين `formatCurrencyLocale` الذي يعرض عملة المنشأة، و`ContactDetails.vue` و`AccountStatement.vue` اللذين يعرضان السعر كرقم `en-US` بلا رمز. كما أن `formatCurrency` في composable يستخدم رمزاً ملحقاً بصيغة ثابتة؛ لم تُدمج هذه الصيغ.
- الدقة تختلف عمداً: `ProfitLossReport.vue` يعرض بلا منازل عشرية، و`ContactDetails.vue` يعرض السعر بمنزلتين والكمية بأعداد صحيحة، بينما `formatNumberEnUS` لا يفرض دقة ثابتة.
- اللغة والتقويم يختلفان بحسب الصفحة (`en-US` و`en-GB` و`ar-SA`). كذلك تختلف معالجة التاريخ المفقود أو غير الصالح بين سلسلة فارغة وشرطة وقيمة الإدخال أو `Invalid Date`.
- بعض التنسيقات تستخدم `new Date(value)` بحسب المنطقة الزمنية المحلية، بينما أدوات `formatDateForLocale` تعيد نص الإدخال عند عدم صلاحيته، و`formatLocalDateDayEnUS` يحوّل تاريخ اليوم إلى منتصف الليل المحلي لتجنب انتقال اليوم بسبب UTC. دمج هذه السلوكيات قد يغيّر اليوم المعروض.
- في `AccountStatement.vue`، تم توحيد `formatDate` و`formatDateEn` على locale `en-GB` والدالة نفسها. التاريخ الغائب أو الفارغ يعرض سلسلة فارغة في الجدول والتصدير والطباعة، والتاريخ غير الصالح غير الفارغ يعرض قيمة الإدخال كما تفعل `formatDateForLocale`؛ وبذلك لن يظهر تاريخ epoch أو `Invalid Date` بسبب قيمة مفقودة.

## التحقق

اختبارات Node المدمجة تقارن الأدوات الجديدة مباشرةً بالسلوك السابق للأرقام والقيم الفارغة والتواريخ الصالحة وغير الصالحة:

```sh
node --test tests/formatters.test.js
```

ثم يُشغّل بناء الواجهة من مجلد `SmartSys-Frontend`.
