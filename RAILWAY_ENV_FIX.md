# 🔧 Railway Environment Variables - Fixes Required

## 🚨 Critical Issues

### ❌ Issue 1: PORT is set to 80 (WRONG!)
```diff
- PORT="80"
+ # DELETE THIS VARIABLE COMPLETELY
```
**Why**: Railway sets `$PORT` dynamically. Manual override breaks deployment.

**Action**: Delete `PORT` variable from Railway dashboard.

---

### ❌ Issue 2: DB_SSL_CA points to Aiven certificate
```diff
- DB_SSL_CA="/var/www/html/certs/aiven-project-ca.pem"
- DB_SSL_MODE="REQUIRED"
+ DB_SSL_CA=""
+ DB_SSL_MODE=""
```
**Why**: Railway MySQL doesn't use Aiven certificates. This causes connection failures.

**Action**: 
1. Set `DB_SSL_CA` to empty string
2. Set `DB_SSL_MODE` to empty string
3. Railway handles SSL internally

---

### ⚠️ Issue 3: Missing PROXY_SHARED_SECRET
```diff
+ PROXY_SHARED_SECRET="generate_random_32_char_secret"
```
**Why**: Cloudflare Function needs this to forward real client IP.

**Action**: 
```bash
# Generate secret
openssl rand -hex 32
# Add to both Railway AND Cloudflare
```

---

### ⚠️ Issue 4: APP_BASE_PATH wrong
```diff
- APP_BASE_PATH="/"
+ APP_BASE_PATH=""
```
**Why**: Empty string is correct for Railway (no base path).

---

## ✅ Correct Railway Environment Variables

```env
# Application
APP_ENV=production
APP_DEBUG=false
APP_BASE_PATH=

# Database (Railway MySQL - no SSL config needed)
DB_HOST=${{MySQL.MYSQLHOST}}
DB_PORT=${{MySQL.MYSQLPORT}}
DB_DATABASE=${{MySQL.MYSQLDATABASE}}
DB_USERNAME=${{MySQL.MYSQLUSER}}
DB_PASSWORD=${{MySQL.MYSQLPASSWORD}}
DB_SSL_MODE=
DB_SSL_CA=

# JWT
JWT_SECRET=your_secret_here
JWT_REFRESH_SECRET=your_refresh_secret_here
JWT_ACCESS_TOKEN_EXPIRY=3600
JWT_REFRESH_TOKEN_EXPIRY=2592000
JWT_ALGORITHM=HS256

# Encryption
ENCRYPTION_KEY=your_encryption_key_here
TWO_FA_ENCRYPTION_KEY=your_2fa_key_here

# CORS
CORS_ALLOW_ALL=false
CORS_ALLOWED_ORIGINS=https://smart-sys.pages.dev
FRONTEND_URL=https://smart-sys.pages.dev

# Proxy (IMPORTANT!)
TRUST_PROXY=true
TRUSTED_PROXY_IPS=::1,127.0.0.1,100.64.0.0/10
PROXY_SHARED_SECRET=GENERATE_THIS_OPENSSL_RAND_HEX_32

# Rate Limiting
RATE_LIMIT_ENABLED=true
RATE_LIMIT_MAX_ATTEMPTS=60
RATE_LIMIT_WINDOW=60
RATE_LIMIT_LOGIN_ATTEMPTS=10
RATE_LIMIT_LOGIN_WINDOW=600

# Security
SECURITY_LOGGING_ENABLED=true
SECURITY_LOG_RETENTION_DAYS=365

# Logging
LOG_LEVEL=info
SESSIONS_DEBUG=0
MONOLOG_CHANNEL=erp
MONOLOG_MAX_FILES=30

# SMTP
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_SECURE=tls
SMTP_USER=your_email@gmail.com
SMTP_PASS=your_app_password
SMTP_FROM=your_email@gmail.com
SMTP_FROM_NAME=SmartSys
SMTP_TIMEOUT=15
EMAIL_MAX_EXECUTION_TIME=60

# Cron
CRON_SECRET=your_cron_secret_here

# Health Check
HEALTH_DETAILS_TOKEN=

# Redis (disabled)
REDIS_ENABLED=false
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=
REDIS_DATABASE=0

# Cookies
REFRESH_COOKIE_PATH=/api/v1/auth

# Sentry (optional)
SENTRY_ENABLED=true
SENTRY_DSN=your_sentry_dsn
SENTRY_TRACES_SAMPLE_RATE=0

# ⚠️ DO NOT SET PORT - Railway sets this automatically
```

---

## 🔧 Quick Fix Steps (5 minutes)

### On Railway Dashboard:

1. **Delete `PORT` variable**:
   - Variables → PORT → Delete

2. **Clear SSL variables**:
   - `DB_SSL_MODE` → Set to empty string (not delete, just clear value)
   - `DB_SSL_CA` → Set to empty string

3. **Fix `APP_BASE_PATH`**:
   - Change from `/` to empty string

4. **Add `PROXY_SHARED_SECRET`**:
   ```bash
   # Generate locally
   openssl rand -hex 32
   ```
   - Variables → Add Variable
   - Name: `PROXY_SHARED_SECRET`
   - Value: (paste generated secret)

5. **Redeploy**:
   - Deployments → Redeploy latest

---

## ⏱️ After Changes - Wait 5 minutes

Railway will redeploy automatically. Check:

```bash
# Health check
curl https://smartsys-api-production.up.railway.app/api/v1/check

# Should return 200 OK
```

---

## 🔗 Then Update Cloudflare

Add same `PROXY_SHARED_SECRET` to Cloudflare Pages:
- Settings → Environment variables
- Add variable: `PROXY_SHARED_SECRET` = (same value from Railway)
- Redeploy
