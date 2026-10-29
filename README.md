# SmartSys ERP

نظام ERP متعدد المستأجرين لإدارة المبيعات والمشتريات والمخزون والمحاسبة والعملاء والموردين، مع واجهة Vue 3 وواجهة API مبنية على PHP + Slim 4 وقاعدة MySQL.

## نظرة عامة

هذا المشروع مصمم ليكون جاهزًا للإصدار الأولي (v1) مع هيكل واضح، وثائق كافية، وبنية قائمة على الطبقات:

- Backend API: `api/v1/`
- Frontend: `SmartSys-Frontend/`
- Config & runtime: `config/`, `public/`, `app/`
- Database: `database/`
- Docs: `docs/`

## هيكل المشروع

```text
SmartSys/
├── api/v1/                 API backend
│   ├── src/Handlers/
│   ├── src/Services/
│   ├── src/Middleware/
│   ├── src/Repositories/
│   ├── src/Security/
│   └── src/Utils/
├── app/                    تطبيق PHP + routing
├── config/                 إعدادات التطبيق
├── database/               migrations + README + backups
├── docs/                   وثائق المشروع
├── public/                 نقطة دخول HTTP
├── SmartSys-Frontend/      واجهة المستخدم
│   ├── src/
│   ├── public/
│   └── package.json
├── tests/                  اختبارات PHPUnit
├── vendor/                 تبعيات PHP
├── README.md               نقطة البداية
├── SECURITY.md             سياسات الأمان
├── composer.json
├── composer.lock
└── .env.example
```

## المتطلبات

- PHP 8.1+ (يوصى باستخدام إصدار ما زال مدعومًا أمنيًا، مثل PHP 8.2+ للإنتاج)
- Composer 2
- MySQL 8+
- Node.js 20.19+
- npm

## إعداد بيئة التطوير

### 1) إعداد الـ backend

```powershell
composer install
if (-not (Test-Path .env)) { Copy-Item .env.example .env }
```

قم بتحديث ملف `.env` محليًا باستخدام بيانات قاعدة التطوير الخاصة بك، ولا ترفع الملف إلى Git.

```powershell
php database/migrate.php status
```

تشغيل الخادم محليًا:

```powershell
php -S localhost:8000 -t public
```

### 2) إعداد الـ frontend

```powershell
Set-Location SmartSys-Frontend
npm ci
```

أنشئ ملفًا محليًا باسم `SmartSys-Frontend/.env.local`:

```dotenv
VITE_API_BASE_URL=http://localhost:8000/smartsys/api/v1
```

ثم شغّل الواجهة:

```powershell
npm run dev
```

افتح: `http://localhost:5173`

## وثائق التطوير

- [docs/README.md](docs/README.md) — دليل فهرس الوثائق
- [docs/PROJECT_ARCHITECTURE.md](docs/PROJECT_ARCHITECTURE.md) — بنية المشروع ونمط التطوير
- [docs/DEVELOPER_GUIDE.md](docs/DEVELOPER_GUIDE.md) — إرشادات التطوير والمصادقة والفرع والأمان
- [database/README.md](database/README.md) — إرشادات قاعدة البيانات
- [SECURITY.md](SECURITY.md) — سياسات الأمان

## التحقق الأساسي

```powershell
# اختبارات PHP
vendor/bin/phpunit --testsuite Unit

# بناء الواجهة
Set-Location SmartSys-Frontend
npm run build
```

يتحقق GitHub Actions من PHP 8.1 و8.2، واختبارات MySQL المعزولة، وبناء الواجهة عند كل push وpull request.

قبل تشغيل migrations غير القابلة للعكس على قاعدة قائمة، اختبر استعادة نسخة احتياطية على staging ثم مرر معرّفها صراحةً:

```powershell
php database/migrate.php up --confirm-irreversible --backup-reference=backup-id-2026-10-27
```

راجع [إرشادات قاعدة البيانات](database/README.md) قبل أي ترحيل.

## إرشادات النمط والعمل

- لا تضع منطق الأعمال داخل الـ Handlers، بل داخل Services.
- استخدم `useRequiredBranch` / `useBranchIsolation` عند التعامل مع الفرع في الواجهة.
- حافظ على مصدر واحد للبيانات في Pinia، وتجنب التكرار في الصفحة.
- استخدم Axios المشترك بدل إنشاء إعدادات HTTP جديدة داخل كل صفحة.
- لا ترفع `.env` أو بيانات الإنتاج إلى المستودع.
- افحص `branch_id` وتأكد من صلاحية المستخدم/الفرع لكل تقرير أو تحديث يعتمد على الفرع.

## حالة التنظيف الحالية

تم تنظيم المستودع لتكون الصورة القابلة للتسليم أولية بشكل أساسي:

- تم توحيد وثائق التطوير في `docs/`
- تم حذف الملفات المؤقتة والمتكررة الناتجة عن التحليل أو المراجعة
- تم الاحتفاظ بالملفات التشغيلية المهمة فقط

إذا كانت هناك حاجة لاحقًا إلى مرحلة cleanup أعمق، فالأولوية تكون في: الملفات المؤقتة، التقريريات المتكررة، ملفات اللقطات غير المهمة، وتدقيق الاستيرادات داخل الواجهة.
