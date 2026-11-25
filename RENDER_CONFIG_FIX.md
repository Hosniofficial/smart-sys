# 🔧 Render Configuration Fix - URGENT

## 🚨 المشكلة الحالية

الـ Login بيرجع **301 Moved Permanently** بدل **401 Unauthorized** بسبب:
- `TRUST_PROXY=false` → الـ middleware مش بيتحقق من `X-Forwarded-Proto`
- الكود بيشوف الطلب HTTP ويعمل redirect لـ HTTPS
- الـ 301 redirect **مفهوش CORS headers** → المتصفح بيعرضه كـ CORS error

## ✅ الحل - تحديث Environment Variables على Render

### 1️⃣ تحديث Proxy Settings (إلزامي)

```env
TRUST_PROXY=true
TRUSTED_PROXY_IPS=<عناوين الوكلاء التي تم التحقق منها>
```

أضف عناوين الوكلاء التي ثبت أنها تتصل بالتطبيق فقط. لا تخلط بين `TRUSTED_PROXY_IPS` (للتحقق من رؤوس الوكيل) و`ip_whitelist` (لتجاوز تحديد المعدل). لا تثق في `::1` أو `127.0.0.1` إلا إذا أكدت سجلات الخدمة أن الوكيل الموثوق يتصل فعلًا من هذا العنوان.

---

### 2️⃣ إصلاح Debug Settings (أمان)

```env
APP_DEBUG=false
LOG_LEVEL=info
SESSIONS_DEBUG=0
```

**المشكلة**: 
- `APP_DEBUG=true` بيفعّل detailed errors للعميل (security risk)
- Debug logs في production بتبطئ الأداء وبتملي الـ storage

---

### 3️⃣ إصلاح 2FA Encryption Key (إلزامي)

```env
TWO_FA_ENCRYPTION_KEY=<ضع ناتج php scripts/generate_2fa_key.php هنا>
```

**المشكلة**:
- القيمة الحالية placeholder وغير صالحة
- 2FA secrets بتتخزن **plaintext** (ثغرة أمان خطيرة)

---

### 4️⃣ إصلاح Encryption & Cron Keys

```env
ENCRYPTION_KEY=<generate new key>
CRON_SECRET=<generate new key>
```

**توليد keys جديدة**:
```bash
# ENCRYPTION_KEY
php -r "echo base64_encode(random_bytes(32));"

# CRON_SECRET  
php -r "echo bin2hex(random_bytes(32));"
```

---

### 5️⃣ إصلاح URLs للـ Cron

```env
API_BASE_URL=https://smartsys-api.onrender.com/api/v1
ADMIN_API_URL=https://smartsys-api.onrender.com/api/v1/admin/subscriptions/cron
APP_URL=https://smart-sys.pages.dev
```

**المشكلة**:
- القيم الحالية بتشاور على localhost
- الـ cron jobs مش هتشتغل

---

## 📋 خطوات التطبيق على Render

### Method 1: Dashboard (UI)

1. افتح https://dashboard.render.com/
2. اختار service: **smartsys-api**
3. اضغط **Environment** من القائمة الجانبية
4. لكل متغير:
   - اضغط على المتغير الموجود
   - عدل القيمة
   - اضغط **Save**
5. بعد حفظ كل التعديلات، اضغط **Manual Deploy** → **Deploy latest commit**

### Method 2: Render Blueprint

The repository includes `render.yaml` with the Docker service, `/health` check, and prompts for required secrets. Review the service name, set every prompted value in Render, and configure `DB_SSL_CA` as the path to a mounted trusted CA certificate. Verify the trusted proxy addresses before saving; do not use the example to grant broad proxy trust.

---

## 🧪 التحقق بعد الـ Deploy

### Test 1: Health Check (يجب يرجع 200)
```bash
curl -i https://smartsys-api.onrender.com/api/v1/health
```

**Expected**: 
```
HTTP/2 200 
content-type: application/json
{"status":"ok","timestamp":"..."}
```

---

### Test 2: Login CORS (يجب يرجع 401 مش 301)
```bash
curl -i -X POST https://smartsys-api.onrender.com/api/v1/auth/login \
  -H "Origin: https://smart-sys.pages.dev" \
  -H "Content-Type: application/json" \
  -d '{"username":"wrong","password":"wrong"}'
```

**Expected**:
```
HTTP/2 401
access-control-allow-origin: https://smart-sys.pages.dev
access-control-allow-credentials: true
{"status":"error","message":"..."}
```

**❌ NOT Expected**: `HTTP/2 301` or missing `access-control-allow-origin`

---

### Test 3: Rate Limiting (يجب يحظر بعد 10 محاولات)
```bash
# Run 11 times
for i in {1..11}; do 
  echo "Attempt $i:"
  curl -s -X POST https://smartsys-api.onrender.com/api/v1/auth/login \
    -H "Content-Type: application/json" \
    -d '{"username":"test","password":"test"}' | jq -r '.status'
done
```

**Expected**: First 10 return `error`, 11th returns `Too many requests`

---

## ⚠️ ملاحظات مهمة

### 1. الـ Refresh Token مش هيشتغل (SameSite=Strict)
- **المشكلة**: Cookie من `onrender.com` مش هتتبعت لـ `pages.dev`
- **الحل المؤقت**: User هيضطر يعمل login كل مرة
- **الحل الدائم**: 
  - Domain مخصص (`app.yourdomain.com` + `api.yourdomain.com`)
  - أو Cloudflare Pages Function كـ proxy (موجود فعلاً)

### 2. Database TLS
استخدم `DB_SSL_MODE=VERIFY_IDENTITY` مع `DB_SSL_CA` يشير إلى شهادة CA موثوقة ومقروءة داخل الخدمة. وفّر الشهادة من إعدادات/ملفات النشر؛ لا تضع أسرار الاتصال أو مفاتيح التشفير في الريبو. وضع `REQUIRED` يشفّر الاتصال دون التحقق من الشهادة، وليس بديلًا مكافئًا للتحقق.

### 3. Refresh cookie خلف Cloudflare Pages
الواجهة تستخدم المسار المحلي `/api/v1`، وPages Function يمرر هذا المسار إلى backend. اضبط `VITE_API_BASE_URL=/api/v1` في إعدادات البناء، و`BACKEND_URL` على عنوان الخدمة، و`REFRESH_COOKIE_PATH=/api/v1/auth` في إعدادات النشر حتى يطابق مسار الكوكي مسار الـproxy. إذا غُيّر أحد المسارات، حدّثها معًا واختبر تجديد الجلسة بعد إعادة تحميل الصفحة.

تطبيق API يعتمد JWT؛ إنشاء جلسة PHP من مُنشئ قاعدة البيانات غير مطلوب.

---

## 📊 ملخص الأولويات

| Priority | Variable | Current | Required | Impact |
|----------|----------|---------|----------|--------|
| 🔴 Critical | `TRUST_PROXY` | false | true | Login 301 error |
| 🔴 Critical | `TRUSTED_PROXY_IPS` | empty | verified proxy addresses | Login 301 error |
| 🟠 High | `TWO_FA_ENCRYPTION_KEY` | invalid | valid base64 | 2FA plaintext storage |
| 🟠 High | `APP_DEBUG` | true | false | Security leak |
| 🟡 Medium | `CRON_SECRET` | "change_this" | random | Cron security |
| 🟡 Medium | `ADMIN_API_URL` | localhost | render URL | Cron won't work |
| 🟢 Low | `LOG_LEVEL` | debug | info | Performance |

---

## 🚀 تطبيق سريع (5 دقائق)

```bash
# على Render dashboard
1. Environment tab
2. Update TRUST_PROXY = true
3. Set TRUSTED_PROXY_IPS to addresses verified from the service's network/logs
4. Update APP_DEBUG = false
5. Set a newly generated TWO_FA_ENCRYPTION_KEY; do not reuse any key previously published in the repository
6. Manual Deploy → Deploy latest commit
7. Wait 5 mins
8. Test: curl https://smartsys-api.onrender.com/api/v1/health
```

---

**آخر تحديث**: بعد إصلاح `HttpsEnforcementMiddleware.php`
