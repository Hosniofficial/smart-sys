# SmartSys ERP

نظام ERP متعدد المستأجرين لإدارة المبيعات والمشتريات والمخزون والمحاسبة والعملاء والموردين. يتكون من API بـ PHP وSlim 4 وواجهة Vue 3، ويستخدم MySQL.

## الحالة الحالية

اعتبارًا من 26 نوفمبر 2026 يجري نقل الـ API وقاعدة البيانات من Render/Aiven إلى Railway. الكود جاهز للنشر على Railway (الـ Dockerfile يتبع `$PORT`، وملف `railway.toml`، ومرفقات الضمان خارج `public/`، ودعم نطاقات CIDR للـ Proxy)، لكن **لم يُجرَّب بناء Docker ولا النشر الفعلي على Railway** في هذه المراجعة. الدليل الكامل: [docs/RAILWAY_DEPLOYMENT.md](docs/RAILWAY_DEPLOYMENT.md). بحسب إفادة مالك المشروع لا يوجد مستخدمون فعليون حتى الآن.

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

ملف GitHub Actions يعرّف PHPUnit مع تكاملات MySQL 8 على PHP 8.1 و8.2، واختبارات الواجهة وبناء Vite على Node 22، إضافة إلى تدقيق Composer وnpm. آخر تشغيل بعد الدفع فشل فورًا في الوظائف الثلاث، ولم تتوفر سجلات الوظائف؛ لذلك لا تُعتبر نتيجة CI تحققًا ناجحًا أو فشلًا مؤكدًا للاختبارات نفسها.

## النشر والاستعداد للإنتاج

- النشر على Railway: خدمة API من `Dockerfile` (إعداد البناء في `railway.toml`) وخدمة MySQL في نفس المشروع، مع Volume لمجلد `/data` (ضبط `PERSIST_DIR=/data`). المتغيرات الكاملة في `.env.production.example`، والخطوات في [docs/RAILWAY_DEPLOYMENT.md](docs/RAILWAY_DEPLOYMENT.md).
- الـ Healthcheck على `/check` (حيّة التطبيق بدون قاعدة بيانات). `/health` يفحص جاهزية قاعدة البيانات ويُراقَب بأداة Uptime منفصلة.
- واجهة Cloudflare Pages تستخدم `SmartSys-Frontend/functions/api/[[path]].js` لتمرير `/api/v1` إلى الـ backend. اضبط `BACKEND_URL` و`VITE_API_BASE_URL=/api/v1`، و`PROXY_SHARED_SECRET` (نفس القيمة على Railway) لتمرير IP الزائر. استجابة الصفحة الرئيسية وحدها لا تثبت سلامة الـ proxy.
- `TRUSTED_PROXY_IPS` يقبل عناوين ونطاقات CIDR (IPv4 وIPv6). على Railway استخدم `100.0.0.0/8,fc00::/7,::1,127.0.0.1` مع `CLIENT_IP_HEADER=X-Real-IP`؛ ولا تعتمد على أي Header آخر لعنوان العميل.
- رفع المرفقات: تُقبل صور وPDF فقط، ويُحدَّد النوع من محتوى الملف (وليس من اسمه)، وتُخزَّن خارج `public/` وتُقدَّم برابط موقّع قصير العمر. لا يُنفَّذ أي سكربت داخل `public/uploads`. بدون Volume تُفقد الملفات عند كل إعادة نشر.
- `ENCRYPTION_KEY` سر اختياري للتشفير العام؛ استخدم قيمة عشوائية بطول 32 بايت مشفرة بـBase64، ولا تدوّرها بعد استخدامها لتشفير بيانات. `TWO_FA_ENCRYPTION_KEY` مطلوب قبل تفعيل 2FA؛ لا تدوّر مفتاحًا مستخدمًا قبل خطة إعادة تسجيل. احتفظ بنفس المفاتيح عند نقل الاستضافة.
- لا توجد جدولة تلقائية للـ cron. الـ endpoint يغيّر حالات الاشتراك وقد يرسل بريدًا حقيقيًا؛ اختبره على بيانات معزولة قبل الجدولة.
- قاعدة Railway هي حاوية MySQL على Volume وليست خدمة مُدارة: فعّل Backups وPoint-in-Time Recovery إن توفرت، وأضف نسخة دورية خارجية، وجرّب الاستعادة فعلاً قبل حذف Aiven.
- صورة Docker ما زالت على PHP 8.2؛ يلزم الانتقال إلى 8.3 والتحقق منه قبل انتهاء تحديثات 8.2 الأمنية في 31 ديسمبر 2026. سياسة كلمة المرور الحالية تقبل 8 أحرف على الأقل و72 بايتًا كحد أقصى. استخدام `float` في الحسابات المالية وفحص تفرّد المستخدم في `RBACHandler` متابعتان معلّقتان.
- ملف GitHub Actions يشغّل PHPUnit مع MySQL 8 واختبارات الواجهة وبناء Vite وتدقيق Composer وnpm. نتيجة آخر تشغيل على GitHub لم تكن متاحة (فشل فوري بلا سجلات)، فلا يُعتبر CI مؤكدًا.

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
