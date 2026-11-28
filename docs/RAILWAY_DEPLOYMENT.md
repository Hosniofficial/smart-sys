# نشر SmartSys على Railway (API + MySQL) مع Cloudflare Pages

يشرح هذا الدليل نقل الـ Backend وقاعدة البيانات إلى مشروع Railway واحد، مع بقاء الواجهة على Cloudflare Pages.
المتغيرات الكاملة في [`.env.production.example`](../.env.production.example)، وإعداد البناء في [`railway.toml`](../railway.toml).

## 1) البنية

| المكوّن | الخدمة | ملاحظات |
|---|---|---|
| الواجهة | Cloudflare Pages | تمرّر `/api/*` إلى الـ API عبر `functions/api/[[path]].js` (نفس الأصل، فلا CORS ولا مشكلة كوكي) |
| الـ API | خدمة Railway من `Dockerfile` | Apache + PHP 8.2، يسمع تلقائياً على `$PORT` الذي يحقنه Railway |
| قاعدة البيانات | خدمة MySQL على Railway | اتصال عبر الشبكة الخاصة، بلا TLS وبلا Public Access بعد الاستيراد |
| الملفات المرفوعة | Railway Volume على خدمة الـ API | مسار التركيب `/data` ومتغير `PERSIST_DIR=/data` |

## 2) ما الذي تغيّر في الكود لدعم Railway

- **البورت:** `docker/entrypoint.sh` يقرأ `PORT` ويجعل Apache يسمع عليه (الافتراضي 80). لا تضبط `PORT` يدوياً.
- **الـ Healthcheck:** `railway.toml` يستخدم `/check` (حيّة التطبيق، بدون قاعدة بيانات، ومستثناة من تحويل HTTPS). راقب `/health` (جاهزية قاعدة البيانات) بأداة Uptime منفصلة.
- **الثقة في الـ Proxy:** `HttpsEnforcementMiddleware` و`RequestHelper` يدعمان نطاقات CIDR (IPv4 وIPv6) في `TRUSTED_PROXY_IPS`، لأن عناوين Proxy الخاصة بـ Railway ليست ثابتة.
- **عنوان الـ IP الحقيقي:** `CLIENT_IP_HEADER=X-Real-IP` (يستبدله Edge Railway)، أو `PROXY_SHARED_SECRET` لتمرير IP الزائر من دالة Cloudflare بتوقيع سر مشترك. لا تُقبل أي Headers أخرى.
- **المرفقات:** تُخزَّن خارج `public/` بامتداد يُشتق من محتوى الملف (صور وPDF فقط)، وتُقدَّم برابط موقّع قصير العمر. مجلد `public/uploads` لا يُنفَّذ فيه أي سكربت.

## 3) الإنشاء على Railway

1. أنشئ مشروعاً جديداً، وأضف **MySQL** (اختر إصدار **8.4** ليطابق Aiven، والاختبارات تغطي MySQL 8.x فقط).
2. أضف خدمة من مستودع GitHub (`smart-sys`): سيكتشف Railway الـ `Dockerfile` و`railway.toml` تلقائياً.
3. اجعل الخدمتين في **نفس المنطقة** (الأقرب لمصر: أوروبا).
4. على خدمة الـ API: أضف **Volume** مركّباً على `/data`.
5. ضع المتغيرات من `.env.production.example` (استخدم مراجع المتغيرات لقاعدة البيانات). **احتفظ بنفس** `JWT_SECRET` و`JWT_REFRESH_SECRET` و`TWO_FA_ENCRYPTION_KEY` و`ENCRYPTION_KEY` عند نقل البيانات.
6. فعّل **Public Networking** للـ API (دومين `*.up.railway.app` أو دومين مخصص). لا تفعّل Public Access لـ MySQL إلا مؤقتاً للاستيراد.

## 4) متغيرات Cloudflare Pages

| المتغير | القيمة |
|---|---|
| `BACKEND_URL` | رابط الـ API على Railway (بدون `/api`) |
| `VITE_API_BASE_URL` | `/api/v1` |
| `PROXY_SHARED_SECRET` | نفس قيمة المتغير على Railway (سر عشوائي طويل) |

بعد أي تغيير أعد نشر الـ Pages (متغيرات الدوال تسري على النشرات الجديدة).

## 5) النقل التاريخي من Aiven (لم يعد المصدر مستخدماً)

> قاعدة Aiven حُذفت؛ هذه الخطوات مرجعية فقط وليست جزءاً من تشغيل Railway الحالي. شهادة Aiven لم تعد مضمنة في المستودع. عند تكرار استيراد تاريخي من نسخة Aiven متاحة، استخدم شهادة CA التي يوفرها مزود قاعدة البيانات.

1. على Aiven: `SELECT VERSION(), @@time_zone, @@system_time_zone, @@sql_mode;` وقارنها بـ Railway (يجب أن يكون المنطقة الزمنية UTC على الاثنين، والكود لا يضبطها).
2. **أوقف الكتابة:** أوقف الـ API القديم (أو اجعله Maintenance).
3. من جهاز مسموح له في Aiven:
   ```bash
   mysqldump --single-transaction --routines --triggers --events \
     --set-gtid-purged=OFF --no-tablespaces --column-statistics=0 \
     --default-character-set=utf8mb4 \
     --ssl-mode=VERIFY_CA --ssl-ca=/path/to/aiven-ca.pem \
     -h <AIVEN_HOST> -P <AIVEN_PORT> -u avnadmin -p defaultdb > smartsys.sql
   ```
   لو ظهرت `DEFINER=` في الملف احذفها بـ `sed`. المايجريشنز لا تحتوي Views أو Triggers.
4. فعّل Public Access لـ MySQL على Railway مؤقتاً، واستورد: `mysql -h <HOST> -P <PORT> -u root -p railway < smartsys.sql`، ثم **أغلقه**.
5. تحقق: عدد صفوف كل جدول، وعدد صفوف جدول `migrations`، وإجماليات المبيعات والقيود (مدين = دائن)، والتحقق من أن `sales_items.profit` يُحسب. **لا تشغّل Migrations** على القاعدة المستوردة.

## 6) التحويل (Cutover) والرجوع

1. انشر الـ API على Railway، واختبره بالرابط المباشر: `GET /check` يعيد 200، و`GET /health` يعيد 200، وتسجيل دخول ببيانات وهمية يعيد 401.
2. غيّر `BACKEND_URL` في Cloudflare وأعد نشر الـ Pages، ثم اختبر: دخول حقيقي، Reload (الجلسة تبقى)، بيع، مرتجع، رفع مرفق ضمان وعرضه.
3. لا تعتمد على Aiven كخطة رجوع؛ المصدر السابق حُذف. تحقّق من نسخ Railway الاحتياطية واختبر استعادة فعلية قبل أي تغييرات على قاعدة الإنتاج.

## 7) بعد النقل

- **النسخ الاحتياطي:** فعّل Backups على Volume قاعدة MySQL، وPoint-in-Time Recovery إن توفرت في خطتك، وأضف Dump أسبوعياً لمكان خارجي، وجرّب الاستعادة فعلاً على خدمة مؤقتة.
- **الـ Cron:** لا يوجد جدولة تلقائية. الـ Endpoint `POST /api/v1/admin/subscriptions/cron` بالـ Header `X-Cron-Token` يغيّر حالات الاشتراكات ويرسل بريداً حقيقياً؛ اختبره على بيانات معزولة قبل الجدولة (Railway Cron أو GitHub Actions أو cron-job.org).
- **الأداء:** اضبط `MaxRequestWorkers` في Apache حسب ذاكرة الخطة إن لزم، وراقب الاستهلاك.
- **PHP:** الصورة ما زالت على PHP 8.2 (ينتهي دعمه الأمني 31 ديسمبر 2026). الانتقال إلى 8.3 خطوة منفصلة بعد استقرار Railway.

## 8) استكشاف فشل الـ Healthcheck

| العَرَض | السبب المحتمل | الحل |
|---|---|---|
| `AH00534: More than one MPM loaded` عند بدء الحاوية | Apache حمّل أكثر من MPM رغم نجاح إعداد البناء | يفرض `docker/entrypoint.sh` استخدام `mpm_prefork` ويفحص الوحدات قبل التشغيل. إذا ظهر الخطأ قبل تطبيق هذا الإصلاح، أعد النشر بمسح build cache وتأكد أن **Custom Start Command** فارغ أو `apache2-foreground` |
| انتهاء مهلة الـ Healthcheck بلا استجابة | الحاوية لا تسمع على `$PORT` (صور قديمة ببورت 80 ثابت) | تأكد من استخدام الـ `Dockerfile` الحالي وأن `PORT` غير مضبوط يدوياً بقيمة خاطئة |
| 301/302 على `/check` | `/check` ليس مستثنى من HTTPS (نسخة قديمة) | تأكد أن `HttpsEnforcementMiddleware` الحالي منشور |
| 500 على كل المسارات (حتى `/check`) | فحص الأسرار عند الإقلاع فشل (JWT مفقود/ضعيف/متطابق، أو مفتاح 2FA غير صالح) | افتح Deploy Logs وابحث عن `SECURITY CONFIGURATION ERROR`. الكود يقرأ متغيرات المنصة حتى لو `variables_order` في php.ini لا يتضمن `E` |
| `/check` يعمل و`/health` 503 | متغيرات قاعدة البيانات غير مضبوطة أو الخدمة غير مربوطة | تحقق من مراجع `${{MySQL.*}}` وأن `DB_SSL_MODE` فارغ |
| 301 على تسجيل الدخول من الواجهة | `TRUSTED_PROXY_IPS` لا يغطي عنوان Proxy | استخدم `100.0.0.0/8,fc00::/7,::1,127.0.0.1` وتأكد من `TRUST_PROXY=true` |

## 9) ملاحظات أمنية خاصة بالنقل

- **ثغرة رفع الملفات اتصلحت في هذا الإصدار:** كان الامتداد يؤخذ من العميل وتُحفظ الملفات داخل `public/`. افحص السجلات القديمة بحثاً عن أي ملف مشبوه:
  ```sql
  SELECT id, tenant_id, filename FROM warranty_attachments
  WHERE filename REGEXP '\\.(php|phtml|phar|pht|php[0-9])$';
  ```
  لو رجّع أي صف، اعتبر الخادم القديم مخترقاً ودوّر كل الأسرار (DB وJWT وSMTP وSentry ومفاتيح التشفير) بعد النقل.
- لا تفتح MySQL للعامة بعد الاستيراد، وأبقِ الـ API مُتاحاً عبر Edge Railway فقط (قيمة `TRUSTED_PROXY_IPS` تفترض ذلك).
- دوّر الأسرار إن ظهرت في أي محادثة أو لقطة شاشة.
