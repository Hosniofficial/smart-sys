# ✅ Railway Environment Variables - Confirmed Changes

## Based on actual code review (not assumptions)

---

## 🔴 CRITICAL - Must Fix

### 1. ❌ DELETE PORT Variable
```diff
- PORT=80
+ # DELETE COMPLETELY
```

**Why**: Railway sets `$PORT` dynamically. Manual override causes:
```
docker/entrypoint.sh:12: Apache configured to listen on $PORT
```
If `PORT=80` but Railway expects `PORT=8080`, healthcheck fails.

**Proof**: Check `docker/entrypoint.sh`:
```bash
PORT=${PORT:-80}
sed -i "s/Listen 80/Listen $PORT/" /etc/apache2/ports.conf
```

**Action**: Delete `PORT` from Railway dashboard → Variables.

---

### 2. ❌ REMOVE DB_SSL_CA and DB_SSL_MODE
```diff
- DB_SSL_CA=/var/www/html/certs/aiven-project-ca.pem
- DB_SSL_MODE=REQUIRED
+ DB_SSL_CA=
+ DB_SSL_MODE=
```

**Why**: 
1. Railway MySQL doesn't use Aiven certificates
2. Certificate path doesn't exist in Railway container
3. Connection will fail with:
   ```
   SQLSTATE[HY000] [2002] No such file or directory
   ```

**Proof**: Check `config/database.php`:
```php
if (!empty($sslCa)) {
    $options[PDO::MYSQL_ATTR_SSL_CA] = $sslCa; // Will fail if file missing
}
```

**Action**: 
- Railway dashboard → Variables
- `DB_SSL_CA` → Clear value (empty string)
- `DB_SSL_MODE` → Clear value (empty string)

---

### 3. ⚠️ FIX APP_BASE_PATH
```diff
- APP_BASE_PATH=/
+ APP_BASE_PATH=
```

**Why**: Empty string is correct for Railway (no base path in URL).

**Proof**: Check `public/index.php:normalizeRequestPath()`:
```php
$basePath = trim($basePath, '/');
if ($basePath !== '') {  // Only process if not empty
    // ...
}
```

**Action**: Railway dashboard → Variables → `APP_BASE_PATH` → Set to empty string

---

## ✅ OPTIONAL - Not Required (But Useful)

### 4. ⚠️ PROXY_SHARED_SECRET - NOT IN CODE
```diff
+ PROXY_SHARED_SECRET=  # Leave empty or delete
```

**Status**: 
- ❌ **NOT implemented** in current code
- The patch commit that adds this feature was **skipped** during `git am`
- Cloudflare Function doesn't use it (checked `[[path]].js`)
- RequestHelper doesn't check it (checked `RequestHelper.php`)

**Proof**:
```bash
# No PROXY_SHARED_SECRET in code
grep -r "PROXY_SHARED_SECRET" api/v1/src/
# Returns: nothing
```

**Action**: 
- **Leave empty** on Railway
- **Don't add** to Cloudflare
- Not needed for current deployment

---

## ✅ KEEP AS-IS - Already Correct

### 5. ✅ TRUST_PROXY Settings
```env
TRUST_PROXY=true  # ✅ KEEP
TRUSTED_PROXY_IPS=::1,127.0.0.1,100.64.0.0/10  # ✅ KEEP
```

**Why**: Required for rate limiting and security logging to work correctly.

**Proof**: Check `RequestHelper.php`:
```php
if (!$proxyTrustEnabled || !self::matchesTrustedProxy($remoteAddress, $proxyAddresses)) {
    return $remoteAddress; // Returns proxy IP, not real client
}
```

**Security Check**: ✅ Safe because:
1. CIDR ranges are specific to Railway's internal network
2. `matchesTrustedProxy()` validates each IP against CIDR
3. Only trusted IPs can set `X-Forwarded-For`

---

## 📋 Summary of Changes

| Variable | Current Value | New Value | Action |
|----------|--------------|-----------|--------|
| `PORT` | `80` | *(delete)* | ❌ **DELETE** |
| `DB_SSL_CA` | `/var/www/html/certs/...` | *(empty)* | ⚠️ **CLEAR** |
| `DB_SSL_MODE` | `REQUIRED` | *(empty)* | ⚠️ **CLEAR** |
| `APP_BASE_PATH` | `/` | *(empty)* | ⚠️ **FIX** |
| `PROXY_SHARED_SECRET` | *(not set)* | *(not needed)* | ➖ **IGNORE** |
| `TRUST_PROXY` | `true` | `true` | ✅ **KEEP** |
| `TRUSTED_PROXY_IPS` | `::1,127.0.0.1,100.64.0.0/10` | *(same)* | ✅ **KEEP** |

---

## 🧪 Test After Changes

### 1. Health Check (most important!)
```bash
curl https://smartsys-api-production.up.railway.app/api/v1/check
```
**Expected**: `200 OK` - Proves Apache started on correct PORT

**If 503/404**: PORT issue - check Railway logs

---

### 2. Database Connection
```bash
curl https://smartsys-api-production.up.railway.app/api/v1/health
```
**Expected**: `{"status":"ok","timestamp":"..."}` - Proves DB connection works

**If error**: DB_SSL_* issue - check Railway logs for PDO errors

---

### 3. Login (CORS + Proxy)
```bash
curl -i -X POST https://smartsys-api-production.up.railway.app/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -H "Origin: https://smart-sys.pages.dev" \
  -d '{"username":"wrong","password":"wrong"}'
```
**Expected**: 
- Status: `401 Unauthorized`
- Header: `access-control-allow-origin: https://smart-sys.pages.dev`

**If 301**: APP_BASE_PATH issue

---

## ☁️ Cloudflare Pages - No Changes Needed

Current config is correct:
```env
BACKEND_URL=https://smartsys-api-production.up.railway.app
VITE_API_BASE_URL=/api/v1
NODE_VERSION=20
# PROXY_SHARED_SECRET - NOT NEEDED (feature not implemented)
```

**Action**: No changes required

---

## ⏱️ Deployment Timeline

1. **Fix Railway Variables** (3 minutes):
   - Delete `PORT`
   - Clear `DB_SSL_CA` and `DB_SSL_MODE`
   - Fix `APP_BASE_PATH`

2. **Wait for Redeploy** (5-8 minutes):
   - Railway auto-redeploys on env change
   - Watch deployment logs

3. **Run Tests** (2 minutes):
   - `/check` health
   - `/health` database
   - `/auth/login` CORS

4. **If all pass** → ✅ Done!

5. **If any fail** → Check Railway logs, compare with error patterns above

---

## 🚨 If Deployment Fails

### Error Pattern Matching:

**"Port 80 already in use"**:
→ `PORT` not deleted. Delete it.

**"No such file: aiven-project-ca.pem"**:
→ `DB_SSL_CA` not cleared. Clear it.

**"301 redirect on /api/v1/health"**:
→ `APP_BASE_PATH` not fixed. Set to empty.

**"Connection refused to database"**:
→ Check `DB_*` variables match Railway MySQL service

---

**Confidence Level**: 🟢 **HIGH** - Based on actual code review, not assumptions.

**Last Verified**: After reviewing:
- `docker/entrypoint.sh` (PORT handling)
- `config/database.php` (DB_SSL_*)
- `public/index.php` (APP_BASE_PATH)
- `RequestHelper.php` (TRUST_PROXY)
- `[[path]].js` (Cloudflare Function)
- Git history (PROXY_SHARED_SECRET missing)
