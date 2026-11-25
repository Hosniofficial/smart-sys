# SmartSys ERP

نظام ERP متعدد المستأجرين لإدارة المبيعات والمشتريات والمخزون والمحاسبة والعملاء والموردين. يتكون من API بـ PHP وSlim 4 وواجهة Vue 3، ويستخدم MySQL.

## الحالة الحالية

المشروع في مرحلة التجهيز الأولى قبل الإنتاج، ولا يوجد مستخدمون فعليون حتى الآن بحسب تأكيد مالك المشروع. تغييرات العمل المحلية لم تُرفع أو تُنشر بعد؛ لذلك لا تعتبر أي خدمة مستضافة أو إعداد خارجي متحققًا قبل اختباره في بيئة staging.

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

- إعداد Render المبدئي موجود في `render.yaml`، وصورة backend في `Dockerfile`. فحص الجاهزية `/health` يحتاج اتصال قاعدة البيانات؛ `/check` فحص liveness لا يتصل بها.
- واجهة Cloudflare Pages تستخدم `SmartSys-Frontend/functions/api/[[path]].js` لتمرير `/api/v1` إلى backend. يلزم ضبط `BACKEND_URL` و`VITE_API_BASE_URL=/api/v1` في إعدادات البناء/النشر.
- في Render، يضبط `render.yaml` اتصال MySQL مع `DB_SSL_MODE=VERIFY_IDENTITY` وشهادة Aiven العامة المرفقة في `/var/www/html/certs/aiven-project-ca.pem` عبر `DB_SSL_CA`. حدّث الشهادة وأعد النشر إذا دوّر Aiven شهادة المشروع. حدد عناوين `TRUSTED_PROXY_IPS` بعد التحقق من إعداد proxy الفعلي.
- `ENCRYPTION_KEY` سر اختياري للتشفير العام، و`render.yaml` يطلب ضبطه في Render لتفادي تحذير بدء التشغيل. استخدم قيمة عشوائية مستقلة بطول 32 بايت مشفرة بـBase64، ولا تدوّرها بعد استخدامها لتشفير بيانات.
- `TWO_FA_ENCRYPTION_KEY` مطلوب قبل تفعيل 2FA أو تخزين أسرارها؛ يمكن للتطبيق بدء التشغيل من دونه، لكن عمليات 2FA تفشل بأمان. لا تدوّر مفتاحًا مستخدمًا لبيانات مشفرة قبل خطة ترحيل/إعادة تسجيل مناسبة.
- لم يُتحقق محليًا من بناء Docker أو إعدادات Render وCloudflare أو اتصال Aiven. نفّذ ذلك في staging قبل أي نشر إنتاجي.

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
