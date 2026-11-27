# 🚂 Railway Quick Verification Tests

## 📍 Replace YOUR_RAILWAY_URL with your actual Railway URL

Find it in: Railway Dashboard → Service → Settings → Domains

---

## Test 1: Liveness Check (No DB)
```bash
curl https://YOUR_RAILWAY_URL/api/v1/check
```
**Expected**: `200 OK`

---

## Test 2: Health Check (With DB)
```bash
curl https://YOUR_RAILWAY_URL/api/v1/health
```
**Expected**: 
```json
{
  "status": "ok",
  "timestamp": "2024-..."
}
```

---

## Test 3: Login CORS
```bash
curl -i -X POST https://YOUR_RAILWAY_URL/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -H "Origin: https://smart-sys.pages.dev" \
  -d '{"username":"wrong","password":"wrong"}'
```
**Expected**:
- Status: `401 Unauthorized`
- Header: `access-control-allow-origin: https://smart-sys.pages.dev`
- Header: `access-control-allow-credentials: true`

**NOT Expected**: `301`, `404`, or missing CORS headers

---

## Test 4: File Upload Security (من Frontend)

1. افتح: https://smart-sys.pages.dev
2. Login
3. روح Warranty Management
4. حاول ترفع ملف `.php` أو `.txt`

**Expected**: ❌ رسالة خطأ "Invalid file type" أو "Only images and PDF allowed"

---

## Test 5: File Upload Success

1. ارفع صورة (JPG/PNG) أو PDF
2. الملف يترفع ✅
3. اضغط "تحميل" على المرفق
4. الملف يتحمل ✅

---

## ✅ All Tests Pass?

If YES → Railway deployment successful! 🎉

If NO → Check Railway logs:
```
Railway Dashboard → Your Service → Deployments → View Logs
```

Common issues:
- Port binding error → Check `docker/entrypoint.sh`
- Database connection error → Check `DB_*` environment variables
- 404 on all routes → Check Apache configuration
