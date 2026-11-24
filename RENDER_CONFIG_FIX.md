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
TRUSTED_PROXY_IPS=::1,127.0.0.1
```

**ليه `::1`؟**
- من الـ logs بتاعك: `Method=POST ... IP=::1`
- Render بيستخدم proxy داخلي بـ IPv6 loopback
- لازم نضيفه للـ trusted IPs عشان نقرا `X-Forwarded-Proto` صح

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
TWO_FA_ENCRYPTION_KEY=868XqWvGd7FNv6yv2/dn9wU+u5BsIQlXUqgdxXfN09Y=
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

### Method 2: Render.yaml (أوتوماتيك)

إنشاء ملف `render.yaml` في الـ root:

```yaml
services:
  - type: web
    name: smartsys-api
    env: docker
    dockerfilePath: ./Dockerfile
    envVars:
      - key: APP_ENV
        value: production
      - key: APP_DEBUG
        value: false
      - key: TRUST_PROXY
        value: true
      - key: TRUSTED_PROXY_IPS
        value: ::1,127.0.0.1
      - key: LOG_LEVEL
        value: info
      - key: SESSIONS_DEBUG
        value: 0
      # ... باقي المتغيرات (secrets من dashboard)
```

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

### 2. Database SSL Certificate
المتغيرات `DB_SSL_CA` و `DB_SSL_MODE` **مش مستخدمين في الكود**:
```bash
# Verify
grep -r "DB_SSL_CA" api/
grep -r "DB_SSL_MODE" api/
```

الاتصال بـ Aiven ممكن يحتاج إضافة SSL options في `config/database.php`:
```php
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false, // For Aiven
];
```

### 3. PHPSESSID Cookie (Medium Priority)
في `config/database.php:31` في `session_start()` بدون flags:
```php
session_start([
    'cookie_secure' => true,
    'cookie_httponly' => true,
    'cookie_samesite' => 'Strict',
]);
```

---

## 📊 ملخص الأولويات

| Priority | Variable | Current | Required | Impact |
|----------|----------|---------|----------|--------|
| 🔴 Critical | `TRUST_PROXY` | false | true | Login 301 error |
| 🔴 Critical | `TRUSTED_PROXY_IPS` | empty | ::1,127.0.0.1 | Login 301 error |
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
3. Update TRUSTED_PROXY_IPS = ::1,127.0.0.1
4. Update APP_DEBUG = false
5. Update TWO_FA_ENCRYPTION_KEY = 868XqWvGd7FNv6yv2/dn9wU+u5BsIQlXUqgdxXfN09Y=
6. Manual Deploy → Deploy latest commit
7. Wait 5 mins
8. Test: curl https://smartsys-api.onrender.com/api/v1/health
```

---

**آخر تحديث**: بعد إصلاح `HttpsEnforcementMiddleware.php`
