# Security & Production Readiness Fixes

## Status Tracking

### Critical Issues
- [x] C1: 301 redirect on login - FIXED (HttpsEnforcementMiddleware)
- [x] C2: Case-sensitive imports - ALREADY FIXED
- [x] C3: SameSite=Strict cookie - proxy path and cookie path aligned in code; deployment still requires configuration and verification

### High Priority  
- [x] H1: XSS in PageHeader - FIXED (removed v-html)
- [x] H2: Rate limiting behind proxy - FIXED (removed ::1 from whitelist)
- [x] H3: 2FA plaintext storage - FIXED (requireKey: true + script created)
- [x] H4: Default secrets not validated - FIXED (validateSecuritySecrets() in container.php)
- [x] H5: /admin/owner-contribution-tenants 403 - protected by the parent JWT route group and SuperAdminMiddleware

### Medium/Low
- [x] PHPSESSID without Secure/HttpOnly - database construction no longer starts a PHP session
- [x] Password policy not enforced - shared 8-to-72-byte bounds are enforced at password creation/change paths
- [ ] Handlers built on every request
- [ ] Missing deployment files
- [ ] Old .htaccess RewriteBase

## Next Steps
1. ✅ Repository-side H1-H5 fixes are present; run CI before deployment.
2. ⚠️ Configure unique production secrets outside Git. A 2FA key was previously published in deployment documentation and must be treated as compromised; rotate it only after planning migration/re-encryption of existing 2FA data.
3. Configure the mounted database CA, `BACKEND_URL`, and `REFRESH_COOKIE_PATH` for the deployment.
4. Deploy and verify login, refresh, health checks, and security headers in production.
5. Address remaining deployment-file and route-laziness decisions separately.
