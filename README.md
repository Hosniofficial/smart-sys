# SmartSys ERP

نظام ERP متعدد المستأجرين لإدارة المبيعات والمشتريات والمخزون والمحاسبة والعملاء والموردين. يتكون من API بـ PHP وSlim 4 وواجهة Vue 3، ويستخدم MySQL.

## الحالة الحالية

اعتبارًا من 25 نوفمبر 2026، الـAPI منشور على Render بآخر commit `cfa58c8`، وتعيد نقاط `/health` و`/check` استجابة HTTP 200. واجهة Cloudflare Pages تستجيب بـHTTP 200، كما أعاد مسار تسجيل الدخول عبرها `405` عند استخدام GET، ما يؤكد وصول الطلب إلى المسار دون اختبار تسجيل دخول فعلي. هذه فحوص تشغيل أساسية فقط ولا تثبت جاهزية تشغيلية كاملة. بحسب إفادة مالك المشروع، لا يوجد مستخدمون فعليون حتى الآن.

## المكونات

- `api/v1/`, `app/`, `config/`, `public/`: API ونقطة دخول التطبيق.
- `database/migrations/`: migrations قاعدة البيانات.
- `SmartSys-Frontend/`: تطبيق Vue وملفات Cloudflare Pages Functions.
- `tests/`: اختبارات PHPUnit واختبارات تكامل MySQL.
- `docs/`: أدلة التطوير والتشغيل.

`vendor/` و`node_modules/` و`SmartSys-Frontend/dist/` مخرجات/اعتماديات محلية وليست ملفات مصدر مطلوبة في Git.

## المتطلبات

- PHP 8.1 أو أحدث، مع `pdo_mysql`, `pdo_sqlite`, `mysqli` و`sodium` للاختبارات والميزات المرتبطة بها.
- Composer 2.
- MySQL 8 للاختبارات المتكاملة والإنتاج.
- Node.js 22 وnpm للواجهة.

## التشغيل المحلي على Windows / PowerShell

### Backend

من جذر المستودع:

```powershell
composer install
if (-not (Test-Path .env)) { Copy-Item .env.example .env }
```

عدّل `.env` محليًا بقيم قاعدة تطوير معزولة وأسرار جديدة؛ لا ترفع `.env` أو بيانات الاعتماد. تحقق من حالة migrations قبل تطبيق أي منها:

```powershell
php database/migrate.php status
```

لتشغيل خادم PHP المحلي مع توجيه المسارات إلى التطبيق:

```powershell
php -S 127.0.0.1:8000 -t public public/index.php
```

### Frontend

في نافذة PowerShell أخرى:

```powershell
Set-Location .\SmartSys-Frontend
npm.cmd ci
```

أنشئ `SmartSys-Frontend/.env.local` للتطوير المحلي:

```dotenv
VITE_API_BASE_URL=http://127.0.0.1:8000/api/v1
```

ثم شغّل الواجهة على `http://localhost:5173`:

```powershell
npm.cmd run dev
```

## الاختبارات والتحقق

من جذر المستودع:

```powershell
composer test
```

واختبارات الواجهة وبناؤها:

```powershell
Set-Location .\SmartSys-Frontend
npm.cmd test
npm.cmd run build
```

تشغيل تكاملات MySQL اختياريًا يتطلب خادم MySQL 8 محليًا وقاعدة اختبار مخصصة قابلة للحذف. الاختبارات ترفض قواعد البيانات البعيدة، وتنشئ مخططات اختبار مؤقتة ثم تحذفها؛ لا تستخدم إعدادات الإنتاج أو قاعدة تحتوي بيانات مهمة:

```powershell
$env:DB_HOST = '127.0.0.1'
$env:DB_PORT = '3306'
$env:DB_DATABASE = 'inventory_test'
$env:DB_USERNAME = 'root'
$env:DB_PASSWORD = '' # أدخل كلمة مرور خادم الاختبار إن كانت مضبوطة
$env:RUN_MYSQL_MIGRATION_TESTS = '1'
$env:RUN_MYSQL_CASH_VOUCHER_TESTS = '1'
$env:RUN_MYSQL_SUPPLIER_PAYMENT_TESTS = '1'
$env:RUN_MYSQL_REPAIR_TESTS = '1'
$env:RUN_MYSQL_SALES_TESTS = '1'
php vendor\bin\phpunit
```

GitHub Actions يشغّل PHPUnit مع تكاملات MySQL 8 على PHP 8.1 و8.2، واختبارات الواجهة وبناء Vite على Node 22، إضافة إلى تدقيق Composer وnpm.

## النشر والاستعداد للإنتاج

- إعداد Render المبدئي موجود في `render.yaml`، وصورة backend في `Dockerfile`. آخر نشر ناجح متحقق منه هو `cfa58c8`. فحص `/health` و`/check` وواجهة Cloudflare Pages أعادت HTTP 200 في آخر smoke check؛ يلزم اختبار تدفق تسجيل الدخول الكامل على staging قبل فتح التسجيل.
- واجهة Cloudflare Pages تستخدم `SmartSys-Frontend/functions/api/[[path]].js` لتمرير `/api/v1` إلى backend. تحقق من `BACKEND_URL` و`VITE_API_BASE_URL=/api/v1` في إعدادات النشر؛ استجابة الصفحة الرئيسية وحدها لا تثبت سلامة مسار API proxy.
- في Render، يضبط `render.yaml` اتصال MySQL مع `DB_SSL_MODE=VERIFY_IDENTITY` وشهادة Aiven العامة المرفقة في `/var/www/html/certs/aiven-project-ca.pem` عبر `DB_SSL_CA`. حدّث الشهادة وأعد النشر إذا دوّر Aiven شهادة المشروع. حدد عناوين `TRUSTED_PROXY_IPS` بعد التحقق من إعداد proxy الفعلي.
- `ENCRYPTION_KEY` سر اختياري للتشفير العام، و`render.yaml` يطلب ضبطه في Render لتفادي تحذير بدء التشغيل. استخدم قيمة عشوائية مستقلة بطول 32 بايت مشفرة بـBase64، ولا تدوّرها بعد استخدامها لتشفير بيانات.
- `TWO_FA_ENCRYPTION_KEY` مطلوب قبل تفعيل 2FA أو تخزين أسرارها؛ يمكن للتطبيق بدء التشغيل من دونه، لكن عمليات 2FA تفشل بأمان. لا تدوّر مفتاحًا مستخدمًا لبيانات مشفرة قبل خطة ترحيل/إعادة تسجيل مناسبة.
- قاعدة Aiven تعمل على MySQL 8.4.11 وخطة `Free-1-1gb` في منطقة Amsterdam. انتهت التجربة، والخطة المجانية مُعلنة للتقاعد في 21 ديسمبر 2026؛ كما كان استخدام الذاكرة نحو 81% في آخر مراجعة. يلزم اختيار ترقية أو ترحيل قبل الموعد، مع اختبار الاستعادة من نسخة احتياطية.
- فُعّلت حماية إنهاء خدمة قاعدة Aiven لتقليل احتمال حذفها بالخطأ؛ هذا لا يمنع تقاعد الخطة المجانية أو الحاجة إلى ترحيل/ترقية.
- قُيّدت قائمة السماح في Aiven إلى نطاقي الخروج الحاليين لخدمة Render (`74.220.48.0/24` و`74.220.56.0/24`)، وأُعيد فحص `/health` و`/check` بنجاح بعدها. النطاقان مشتركان على Render وليسا مخصصين للخدمة؛ الاتصال المباشر من أجهزة التطوير إلى Aiven لم يعد مسموحًا ما لم تُضف عناوينها صراحة.
- خدمة Render على الخطة المجانية قد تتوقف عند الخمول، ما قد يسبب تأخرًا ملحوظًا لأول طلب. لا تعد الخطة الحالية مناسبة لتوافر إنتاجي موثوق دون تقييم الترقية.
- اختبارات PHPUnit المحلية الأخيرة نجحت: 461 اختبارًا و1,266 assertion، مع 52 اختبارًا متجاوزًا وتحذير لغياب مشغل تغطية الكود. اختبارات الواجهة نجحت (12/12)، لكن بناء Vite لم يكتمل محليًا بسبب نفاد الذاكرة المتاحة أثناء التشغيل؛ يلزم إعادة البناء على جهاز/CI بموارد كافية. لم يُتحقق من بناء Docker أو نجاح CI في هذه المراجعة.

قبل تشغيل migrations على قاعدة قائمة، اختبر استعادة نسخة احتياطية قابلة للاستعادة على staging. بعض migrations غير قابلة للعكس وتتطلب مرجع نسخة حقيقية:

```powershell
php database/migrate.php up --confirm-irreversible --backup-reference=REAL_RESTORABLE_BACKUP_ID
```

الأمر لا ينشئ النسخة الاحتياطية ولا يختبر استعادتها. راجع [دليل قاعدة البيانات](database/README.md) و[قائمة النشر](DEPLOYMENT_CHECKLIST.md) قبل أي تغيير تشغيلي.

## مراجع المشروع

- [فهرس الوثائق](docs/README.md)
- [معمارية المشروع](docs/PROJECT_ARCHITECTURE.md)
- [دليل المطور](docs/DEVELOPER_GUIDE.md)
- [دليل قاعدة البيانات](database/README.md)
- [سياسة الأمان](SECURITY.md)
