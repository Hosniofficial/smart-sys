# ✅ Post-Merge Checklist - Railway Migration

## 🚨 عاجل - فور الـ Merge (أول 15 دقيقة)

### 1️⃣ فحص الثغرة الأمنية
```sql
-- على Railway MySQL
SELECT id, tenant_id, filename FROM warranty_attachments
WHERE filename REGEXP '\\.(php|phtml|phar|pht|php[0-9])$';
```

**لو رجع أي صف**:
- 🔴 **الخادم القديم كان مخترق**
- 🔐 **غيّر كل الـ secrets فوراً**:
  ```bash
  # JWT Secrets
  openssl rand -hex 32  # JWT_SECRET
  openssl rand -hex 32  # JWT_REFRESH_SECRET
  
  # Encryption Keys
  php scripts/generate_2fa_key.php  # TWO_FA_ENCRYPTION_KEY
  openssl rand -base64 32  # ENCRYPTION_KEY
  
  # Proxy Secret
  openssl rand -hex 32  # PROXY_SHARED_SECRET
  ```
- 🗄️ **غيّر DB password على Railway**
- 🧹 **امسح الصفوف المشبوهة**:
  ```sql
  DELETE FROM warranty_attachments
  WHERE filename REGEXP '\\.(php|phtml|phar|pht|php[0-9])$';
  ```

---

## 📋 GitHub Actions CI

### انتظر CI يخلص:
- افتح: https://github.com/Hosniofficial/smart-sys/actions
- تأكد من:
  - ✅ Build passing
  - ✅ Unit tests (431) passing
  - ✅ Integration tests (57) passing
  - ✅ Frontend tests (14) passing

**لو فشل CI**:
- شيك الـ logs
- أصلح المشكلة
- Push fix

---

## 🚂 Railway Deployment Verification

### 1. Health Checks
```bash
# Liveness check (no DB)
curl https://smartsys-XXXXX.up.railway.app/api/v1/check
# Expected: 200 OK

# Health check (with DB)
curl https://smartsys-XXXXX.up.railway.app/api/v1/health
# Expected: {"status":"ok","timestamp":"..."}
```

### 2. Login Test
```bash
curl -X POST https://smartsys-XXXXX.up.railway.app/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -H "Origin: https://smart-sys.pages.dev" \
  -d '{"username":"wrong","password":"wrong"}'
# Expected: 401 with CORS headers
```

### 3. Rate Limiting Test
```bash
# Run 11 times
for i in {1..11}; do 
  curl -X POST https://smartsys-XXXXX.up.railway.app/api/v1/auth/login \
    -H "Content-Type: application/json" \
    -d '{"username":"test","password":"test"}'
done
# Expected: 11th request returns 429 Too Many Requests
```

### 4. Check Railway Logs
```
Railway Dashboard → Service → Logs
```
تأكد مفيش:
- ❌ Fatal errors
- ❌ Database connection failures
- ❌ Port binding errors
- ✅ Apache started on correct PORT

---

## ☁️ Cloudflare Pages Update

### 1. Environment Variables
```env
VITE_API_BASE_URL=/api/v1
BACKEND_URL=https://smartsys-XXXXX.up.railway.app
PROXY_SHARED_SECRET=SAME_AS_RAILWAY
NODE_VERSION=20
```

### 2. Redeploy
- Cloudflare Dashboard → Pages → smart-sys
- Deployments → Retry deployment (latest)
- أو: Push dummy commit to trigger redeploy

### 3. Test Frontend
```bash
# Open in browser
https://smart-sys.pages.dev

# Try login
# Try upload attachment in Warranty Management
# Try download attachment
```

---

## 🧪 End-to-End Testing

### Login Flow
- [ ] Login بيشتغل من Frontend
- [ ] Access token بيتولد
- [ ] Refresh token بيتبعت في cookie
- [ ] User data بيرجع صح

### Warranty Attachments (الأهم!)
- [ ] رفع صورة (JPG/PNG) → ✅ Success
- [ ] رفع PDF → ✅ Success
- [ ] رفع .php file → ❌ Rejected (400/415)
- [ ] رفع .txt file → ❌ Rejected
- [ ] تحميل attachment → ✅ Works (signed URL)
- [ ] Signed URL تنتهي بعد 15 دقيقة → ❌ Expired
- [ ] User من tenant تاني مش يقدر يحمل attachment → ❌ 403

### Rate Limiting
- [ ] 10 login attempts → ✅ Allowed
- [ ] 11th attempt → ❌ 429 Too Many Requests
- [ ] انتظار 10 دقايق → ✅ Reset

### Performance
- [ ] API response time < 500ms
- [ ] Login < 1s
- [ ] File upload < 3s (for 5MB file)

---

## 💾 Database Migration (Aiven → Railway)

### 1. Backup Aiven (قبل الحذف)
```bash
# Full backup
mysqldump -h mysql-2cf825ee-hosniofficial99-d985.l.aivencloud.com \
  -P 10371 -u avnadmin -p \
  --ssl-mode=REQUIRED \
  --single-transaction \
  --routines \
  --triggers \
  --events \
  defaultdb > aiven_backup_$(date +%Y%m%d_%H%M%S).sql
```

### 2. Verify Backup
```bash
# Check file size (should be > 0 bytes)
ls -lh aiven_backup_*.sql

# Check content (should have INSERT statements)
grep -c "INSERT INTO" aiven_backup_*.sql
```

### 3. Test Restore على Railway (Dry Run)
```bash
# على database مؤقت
mysql -h railway-host -u root -p test_restore < aiven_backup_*.sql

# تحقق من data integrity
mysql -h railway-host -u root -p test_restore -e "
  SELECT 
    'users' as table_name, COUNT(*) as count FROM users
  UNION ALL
  SELECT 'sales', COUNT(*) FROM sales
  UNION ALL
  SELECT 'products', COUNT(*) FROM products
  UNION ALL
  SELECT 'warranty_attachments', COUNT(*) FROM warranty_attachments;
"
```

### 4. مقارنة Data بين Aiven و Railway
```sql
-- على Aiven
SELECT 
  (SELECT COUNT(*) FROM users) as users_count,
  (SELECT COUNT(*) FROM sales) as sales_count,
  (SELECT COUNT(*) FROM products) as products_count,
  (SELECT COUNT(*) FROM warranty_attachments) as attachments_count;

-- نفس الـ query على Railway
-- الأرقام لازم تطابق تماماً
```

---

## 🗑️ Cleanup (بعد فترة أمان)

### بعد 7 أيام من التشغيل الناجح:

#### 1. حذف Render Service
- [ ] Render Dashboard → smartsys-api
- [ ] Settings → Delete Service
- [ ] أكد الحذف

#### 2. حذف Aiven Database (بعد 30 يوم)
- [ ] Aiven Dashboard → Database
- [ ] Delete service
- [ ] احتفظ بالـ backup محلياً

#### 3. تنظيف Git Branches
```bash
cd c:\xampp\htdocs\SmartSys
git checkout main
git pull smart-sys main
git branch -d railway-migration  # local
git push smart-sys --delete railway-migration  # remote (if not auto-deleted)
```

---

## 📊 Monitoring (أول 24 ساعة)

### Railway Logs
- [ ] Check every 2 hours
- [ ] Monitor for errors
- [ ] Watch memory/CPU usage

### Key Metrics
- [ ] Response time < 500ms avg
- [ ] Error rate < 1%
- [ ] No database connection drops
- [ ] No memory leaks

### Alerts Setup (Optional)
- Railway Dashboard → Observability
- Set alerts for:
  - CPU > 80%
  - Memory > 90%
  - Error rate > 5%

---

## 🔄 Rollback Plan (لو حصلت مشكلة)

### إذا فشل Railway:

1. **Immediate**: Point Cloudflare back to Render
   ```env
   BACKEND_URL=https://smartsys-api.onrender.com
   ```

2. **Restore Aiven** (if deleted):
   ```bash
   mysql -h aiven-host -u avnadmin -p defaultdb < aiven_backup_*.sql
   ```

3. **Revert merge** (if needed):
   ```bash
   git revert -m 1 <merge_commit_hash>
   git push smart-sys main
   ```

---

## ✅ Success Criteria

الـ migration تعتبر ناجحة لما:
- [ ] ✅ No malicious files found in warranty_attachments
- [ ] ✅ CI passing on GitHub
- [ ] ✅ Railway health checks green
- [ ] ✅ Login working from Frontend
- [ ] ✅ File upload/download working securely
- [ ] ✅ Rate limiting enforced
- [ ] ✅ No errors in Railway logs (24 hours)
- [ ] ✅ Performance acceptable (< 500ms avg)
- [ ] ✅ Data integrity verified (Aiven vs Railway)
- [ ] ✅ Backups stored securely
- [ ] ✅ Old services cleaned up

---

## 📞 Support

**إذا واجهت مشكلة**:
1. Check Railway logs first
2. Check Cloudflare Pages deployment logs
3. Review `docs/RAILWAY_DEPLOYMENT.md`
4. Check GitHub Actions CI logs
5. Refer to this checklist

**Files to review**:
- `WarrantyHandler.php` - file upload logic
- `SecureUpload.php` - content validation
- `AttachmentUrlSigner.php` - signed URLs
- `railway.toml` - deployment config
- `docker/entrypoint.sh` - PORT binding

---

**Last Updated**: After railway-migration merge
**Estimated Time**: 2-3 hours total
**Priority**: 🔴 HIGH (security-critical)
