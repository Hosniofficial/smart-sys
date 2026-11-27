# قائمة تحقق النشر: Railway

> الحالة: الكود مُجهَّز لـ Railway، لكن **النشر الفعلي وبناء Docker لم يُجرَّبا بعد**. الدليل التفصيلي في [docs/RAILWAY_DEPLOYMENT.md](docs/RAILWAY_DEPLOYMENT.md).

## قبل النقل (كود)
- [x] ثغرة رفع الملفات: قائمة سماح من محتوى الملف، وتخزين خارج `public/`، وروابط موقّعة، ومنع تنفيذ السكربتات في `public/uploads`
- [x] الثقة في الـ Proxy بنطاقات CIDR، وتحديد IP العميل عبر `CLIENT_IP_HEADER` أو `PROXY_SHARED_SECRET` فقط
- [x] الـ Dockerfile يسمع على `$PORT` وHealthcheck على `/check`
- [x] `railway.toml` و`.env.production.example` محدّثان
- [ ] تشغيل CI على GitHub والتأكد أنه يمرّ (آخر تشغيل فشل فورًا بلا سجلات)

## على Railway
- [ ] مشروع واحد: خدمة MySQL 8.4 + خدمة API، في نفس المنطقة
- [ ] Volume على خدمة الـ API مركّب على `/data` و`PERSIST_DIR=/data`
- [ ] متغيرات الـ API من `.env.production.example` (مراجع `${{MySQL.*}}` لقاعدة البيانات، و`DB_SSL_MODE` فارغ)
- [ ] نفس قيم `JWT_SECRET` و`JWT_REFRESH_SECRET` و`TWO_FA_ENCRYPTION_KEY` و`ENCRYPTION_KEY` عند النقل
- [ ] `FRONTEND_URL` مضبوط (روابط إيميل التفعيل)
- [ ] `TRUST_PROXY=true` و`TRUSTED_PROXY_IPS=100.0.0.0/8,fc00::/7,::1,127.0.0.1` و`CLIENT_IP_HEADER=X-Real-IP`
- [ ] Public Access لـ MySQL مغلق بعد الاستيراد
- [ ] `GET /check` و`GET /health` يعيدان 200 على رابط Railway المباشر

## نقل البيانات
- [ ] مقارنة الإصدار والمنطقة الزمنية بين Aiven وRailway
- [ ] إيقاف الكتابة على النظام القديم، ثم Dump واستيراد
- [ ] التحقق من عدد الصفوف و`migrations` والإجماليات المالية (مدين = دائن)
- [ ] فحص `warranty_attachments` بحثاً عن امتدادات تنفيذية (الاستعلام في دليل Railway)

## Cloudflare Pages
- [ ] `BACKEND_URL` = رابط Railway، `VITE_API_BASE_URL=/api/v1`، `PROXY_SHARED_SECRET` مطابق للـ API
- [ ] إعادة نشر الـ Pages بعد تغيير المتغيرات

## اختبار ما بعد التحويل (يدوي)
- [ ] تسجيل دخول حقيقي ثم Reload (تبقى الجلسة)
- [ ] إيميل تفعيل: الرابط يشير لدومين الواجهة
- [ ] بيع ومرتجع وسند وتقرير
- [ ] رفع مرفق ضمان وعرضه وحذفه
- [ ] `login_attempts.ip_address` يحتوي عناوين الزوار الحقيقية وليس عنوان Proxy
- [ ] لا تُجرَّب محاولات دخول فاشلة متكررة على الإنتاج (تُفعّل الـ Lockout)

## بعد التحويل
- [ ] Backups على Volume قاعدة البيانات + نسخة خارجية + اختبار استعادة ناجح
- [ ] مراقبة Uptime على `/health`
- [ ] دوّر الأسرار التي ظهرت في أي محادثة، ثم أوقف Render وAiven بعد أسبوع
- [ ] قرار جدولة الـ cron (بعد اختبار الأثر)
- [ ] خطة ترقية PHP 8.2 → 8.3 قبل 31 ديسمبر 2026
