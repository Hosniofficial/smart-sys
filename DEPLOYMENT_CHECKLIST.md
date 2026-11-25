# 🚀 Deployment Checklist - Security and Production Readiness

**الحالة الفعلية حتى 25 نوفمبر 2026:** إصلاحات H1-H5 أدناه موجودة في التاريخ المنشور. آخر تغيير تطبيقي هو `cfa58c8`، ثم نشر Render تلقائيًا commit التنظيف `aa87bb7` من `smart-sys/main`. بعده أعادت `/health` و`/check` وواجهة Cloudflare Pages HTTP 200. لم يتغير منطق التطبيق في commit التنظيف. هذه النتائج لا تعني أن الخدمة جاهزة لاستقبال المستخدمين؛ انظر المخاطر التشغيلية أدناه.

## ✅ Completed High Priority Security Fixes

The high-priority fixes below are present in the deployed history. The operational blockers and follow-up checks are listed below.

### H1: XSS Vulnerability in PageHeader ✅
- **Issue**: v-html directive rendering unsanitized user input
- **Fix**: Removed v-html, using text interpolation instead
- **File**: `SmartSys-Frontend/src/components/PageHeader.vue`
- **Commit**: `2675673`

### H2: Rate Limiting Behind Proxy ✅
- **Issue**: `::1` in IP whitelist defeating rate limiting when behind Render proxy
- **Fix**: Removed `::1` from whitelist, trust_proxy now handles real client IPs
- **File**: `config/security.php`
- **Commit**: `48f29c1`

### H3: 2FA Plaintext Storage ✅
- **Issue**: 2FA secrets stored in plaintext without encryption key requirement
- **Fix**: Added `requireKey: true` to TwoFactorEncryptionService constructor
- **Files**: 
  - `api/v1/src/Handlers/AuthHandler.php`
  - `scripts/generate_2fa_key.php` (new script)
- **Commit**: `af9a1c5`

### H4: Default Secrets Not Validated ✅
- **Issue**: Container doesn't validate secrets, allows weak/default values
- **Fix**: Added `validateSecuritySecrets()` function to check all secrets on startup
- **File**: `config/container.php`
- **Commit**: `b0e8edf`

### H5: Missing Authentication Middleware ✅
- **Issue**: `/admin/owner-contribution-tenants` missing JwtAuthMiddleware, always returns 403
- **Fix**: Moved route into JWT-authenticated group with proper middleware chain
- **File**: `config/routes.php`
- **Commit**: `d5ac635`

---

## 🔐 Encryption keys

`ENCRYPTION_KEY` was added to Render configuration to avoid the startup warning. This does not mean all application data is encrypted with it.

`TWO_FA_ENCRYPTION_KEY` is configured in Render according to the prior deployment setup, but an earlier value was published in deployment documentation and must be treated as compromised. Do not rotate the active key before checking for encrypted 2FA secrets and taking a backup; changing it without migration can make existing data unreadable.

The application uses the bundled public Aiven CA at `/var/www/html/certs/aiven-project-ca.pem` with `DB_SSL_MODE=VERIFY_IDENTITY`. Replace the certificate and redeploy if Aiven rotates the project CA. Never commit secret values or include them in deployment logs.

**⚠️ Important Notes**:
- `TWO_FA_ENCRYPTION_KEY` must be Base64 for a 32-byte key.
- Use separate keys per environment.
- Rotate the exposed key only after verifying current 2FA data and the backup.

---

## 📋 Deployment Status

### Backend (Render)
- ✅ Last successful commit: `cfa58c8` (Live on Render)
- ✅ `/health` and `/check` returned HTTP 200 in the latest check
- ⚠️ The free plan may sleep when idle; evaluate an upgrade before onboarding
- 🌐 URL: https://smartsys-api.onrender.com

### Frontend (Cloudflare Pages)
- ✅ The home page returned HTTP 200 in the latest check
- ✅ `GET /api/v1/auth/login` through Pages returned HTTP 405 (route reached; method not allowed)
- ✅ One form-encoded POST using a deliberately invalid, synthetic username/password returned the expected JSON `401` after Render was awake; this confirms that request reached the API and database, not that a real user can log in.
- ⚠️ An earlier POST returned an HTML `502`. A subsequent direct Render `/health` returned `200`, and the form-encoded retry through Pages returned `401`; the cause of the initial `502` was not established.
- ⚠️ Browser login, refresh-cookie attributes, reload persistence, logout, and email links were not tested with a staging account.
- 🌐 URL: https://smart-sys.pages.dev

---

## 🧪 Verification

### Completed checks
- PHPUnit: 461 tests, 1,266 assertions, 52 skipped; no failures. This is not a full database integration or coverage certification.
- Frontend tests: 12 passed.
- Vite production build: did not complete locally, including an isolated retry; Node ran out of memory. A successful CI or adequately provisioned build is still required.
- Render `/health`: HTTP 200 (database readiness).
- Render `/check`: HTTP 200 (liveness).
- Cloudflare Pages home page: HTTP 200.
- GitHub Actions run #92 for `aa87bb7`: all three jobs were marked failed within about two seconds; the logs endpoint returned 404 for each job. No job-step output was available, so this is not evidence that the PHP or frontend tests themselves failed.

### Required before onboarding
- Run login, refresh-cookie, logout, and authorization checks with a staging account.
- Rerun the Vite build on a machine/CI runner with adequate free memory, and confirm CI and Docker build succeed for the deployed commit.
- Investigate why GitHub Actions jobs terminate before producing logs, then rerun the workflow and retain successful PHP, frontend, audit, and build evidence.
- Take a production backup and test restoring it to an isolated staging database.
- Review rate limiting, trusted proxy IPs, TLS identity verification, and security headers in staging.

Do not run repeated failed-login/rate-limit tests against production; they can block the caller IP for the lockout window. Never put real passwords or tokens in shell history or deployment logs.

---

## ⛔ Production blockers and operational decisions

### Aiven database
- MySQL 8.4.11 on `Free-1-1gb` (1 CPU, 1 GB RAM, 1 GB storage) in Amsterdam; Aiven shows that the trial has ended.
- The free plan is scheduled for retirement on **21 December 2026**. Memory usage was approximately 81% in the last review.
- The IP address allowlist is restricted to Render's current shared outbound ranges: `74.220.48.0/24` and `74.220.56.0/24`. Render `/health` and `/check` remained HTTP 200 after the change. These ranges are shared with other Render services and are not unique to this service. Direct local connections to Aiven are now blocked unless an approved development IP is added.
- Termination protection is enabled to reduce accidental deletion risk; this does not prevent retirement of the free plan.
- **Decision required:** select a paid Aiven plan or a tested migration destination before the retirement date. Do not change plans without confirming cost and a restorable backup.

### Before public launch
- Choose a Render plan that does not sleep on idle if reliable response times are required.
- Verify whether production has encrypted 2FA secrets; back them up and rotate the previously exposed key using a migration or user re-enrollment plan.
- Create or verify a least-privilege application database user; do not assume the runtime account needs administrative privileges.
- Complete staging end-to-end checks and backup-restore verification.

## Review of the supplied readiness report (25 November 2026)

### Verified in this review
- Render's live environment page showed `DB_SSL_MODE=VERIFY_IDENTITY`. The configured `DB_SSL_CA` path is present in `render.yaml`; the live value/file itself was not independently inspected. The successful login handler response is evidence that a database-backed request completed, but it does not prove every TLS setting or certificate-rotation path.
- The Pages login endpoint works with the form-encoded body used by the frontend and returned the expected `401` for synthetic invalid credentials. Do not use a JSON body for this endpoint: `authService.login()` sends `application/x-www-form-urlencoded`.
- `HealthHandler.php` has no references outside its own definition and has been removed. The old `AIVEN_DB_*` sample variables had no code/script references and have been removed from `.env.example`.
- The local `.env` is ignored by Git and is not tracked. It contains an Aiven credential; treat it as potentially exposed, verify whether it is still active, and rotate it if so. Do not copy the value into logs, issues, or documentation.

### Still requires an owner-side or staging check
- Render hides the live values for `TRUST_PROXY`, `TRUSTED_PROXY_IPS`, `REFRESH_COOKIE_PATH`, and `DB_SSL_CA`; their actual values and secret-file availability were not confirmed. Check them in the dashboard without copying secrets into chat or logs. Keep TLS certificate verification enabled.
- Verify client IP attribution with a controlled staging login and inspect only the resulting test row in `login_attempts`. The application trusts `X-Forwarded-For` only when the direct peer is explicitly trusted; do not add proxy ranges by guesswork or trust arbitrary client headers.
- Test browser login, refresh, reload, logout, password reset/email links, and role authorization on staging. No real credentials were used in the smoke test.
- PHP 8.2 reaches the end of security support on 31 December 2026. Plan an upgrade to PHP 8.3, extend CI coverage, and build/test the Docker image before that date; do not call the runtime upgrade complete until those checks pass.
- No scheduled subscription cron workflow exists. The endpoint updates expired subscriptions and can send real reminders; first test it with isolated data, verify duplicate suppression and email effects, then add a controlled daily schedule with an explicit secret and concurrency protection. Do not schedule every five minutes without proving idempotency.
- `RBACHandler` scopes create-time identity checks to the tenant but its update-time duplicate check is global. Confirm the production uniqueness rules and intended cross-tenant identity model before changing it; ensure errors do not disclose another tenant's account existence.
- The password policy remains 8 characters minimum / 72 bytes maximum. Decide whether this is acceptable for launch, then update server validation, UI guidance, and tests together if changing it.
- Monetary calculations still use floating-point values in many places. A decimal/minor-unit migration is a broad accounting change; keep it tracked as a separate design and migration task rather than performing a partial cast cleanup.
- `.kiro/`, `test_suite/`, the obsolete Railway guide, and one-off/stale readiness and review reports were removed during cleanup. The current release gate and unresolved findings are consolidated in this checklist; the security policy remains in `SECURITY.md`.

---

## 🎯 Next Steps

1. **Secure and inventory secrets:** determine whether the local Aiven credential and previously exposed 2FA key are still active. Back up and identify encrypted 2FA data before rotating keys; update only the services that use each credential. Never commit or paste secrets.
2. **Protect and plan the database:** choose an Aiven upgrade or tested migration destination before the announced 21 December 2026 free-plan retirement. Take a production backup, restore it into isolated staging, and record the recovery owner, RPO/RTO, and rollback plan.
3. **Choose reliable hosting:** decide whether Render must move off the sleeping free plan. Verify the least-privilege DB account and keep Aiven network access restricted; add only an approved developer CIDR if local access is essential.
4. **Verify deployment configuration:** confirm the mounted CA file and `DB_SSL_MODE=VERIFY_IDENTITY`, `TRUST_PROXY`, trusted proxy addresses, `REFRESH_COOKIE_PATH`, Cloudflare `BACKEND_URL`, and frontend `VITE_API_BASE_URL`. Validate cookie flags and client-IP attribution without trusting arbitrary forwarded headers.
5. **Complete staging end-to-end tests:** exercise login, refresh, reload, logout, password reset/email links, roles/tenant boundaries, and representative sale, return, and inventory flows. Use synthetic data and verify the resulting database and UI behavior.
6. **Prove build and runtime support:** investigate the immediate, logless failures in GitHub Actions run #92 and obtain a successful workflow run. Then upgrade Docker from PHP 8.2 to PHP 8.3 before 31 December 2026, extend CI to test the target version, and require passing PHPUnit/MySQL 8 integration, frontend tests/build, dependency audits, and Docker build. The local Vite build currently fails from memory exhaustion; do not mark it passed until CI or a capable machine proves it.
7. **Enable scheduled work safely:** test the subscription cron against isolated data, verify reminder deduplication and expiry behavior, then add a controlled daily schedule with protected secrets and concurrency prevention. It can change subscription state and send real email; do not enable it before the test.
8. **Resolve launch policies:** confirm the 8-character password minimum and 72-byte maximum, decide how to address the global user-identity duplicate check in `RBACHandler`, and record an accounting plan for widespread floating-point money calculations. Do not make partial financial precision changes.
9. **Operational readiness and sign-off:** verify health/readiness, logs/alerts, security headers, CORS, TLS, backups and restoration; fill the security-reporting contact in `SECURITY.md`; obtain technical and financial owner approval. Keep the release **NO-GO** until all launch blockers are evidenced in staging.

---

## 📞 Support

If you encounter any issues:
- Check Render logs: https://dashboard.render.com/web/YOUR_SERVICE/logs
- Check Cloudflare Pages logs: https://dash.cloudflare.com/
- Review commit history: `git log --oneline -10`
- Check the current release gate: `DEPLOYMENT_CHECKLIST.md`
- Check the security reporting policy: `SECURITY.md`

---

**Last Updated**: 2026-11-25
