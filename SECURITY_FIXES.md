# Security & Production Readiness Fixes

## Status Tracking

### Critical Issues
- [x] C1: 301 redirect on login - FIXED (HttpsEnforcementMiddleware)
- [x] C2: Case-sensitive imports - ALREADY FIXED
- [ ] C3: SameSite=Strict cookie - IN PROGRESS (Cloudflare Proxy)

### High Priority  
- [x] H1: XSS in PageHeader - FIXED (removed v-html)
- [ ] H2: Rate limiting behind proxy - TODO
- [ ] H3: 2FA plaintext storage - TODO
- [ ] H4: Default secrets not validated - TODO
- [ ] H5: /admin/owner-contribution-tenants 403 - TODO

### Medium/Low
- [ ] PHPSESSID without Secure/HttpOnly
- [ ] Password policy not enforced
- [ ] Handlers built on every request
- [ ] Missing deployment files
- [ ] Old .htaccess RewriteBase

## Next Steps
1. Commit current fixes
2. Fix H2 (Rate limiting)
3. Fix H3 (2FA encryption)
4. Fix H4 (Secrets validation)
5. Add deployment files
6. Run full test suite
