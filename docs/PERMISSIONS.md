# Owner Contribution Permissions

## Overview
This document describes the permission system for owner contribution operations in SmartSys.

**Last Updated:** 2026-09-29
**Version:** 1.0

---

## Permission Structure

### Schema
```sql
CREATE TABLE permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL UNIQUE,
    display_name VARCHAR(255),
    description TEXT,
    category VARCHAR(100),
    resource VARCHAR(100),
    action VARCHAR(100),
    is_active TINYINT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_category (category),
    INDEX idx_resource (resource)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## Owner Contribution Permissions

### 1. accounting.owner_contribution.create

**Display Name:** Create Owner Contribution
**Description:** إنشاء وتسجيل مساهمة مالك (رأس مال، قرض مالك، رصيد افتتاحي)
**Category:** accounting_management
**Resource:** owner_contributions
**Action:** create

**Grants Access To:**
- POST `/setup/owner-contribution/create`

**Use Cases:**
- Record capital injections from business owner
- Document owner loans to the business
- Set up opening balance equity adjustments
- Initial funding during business setup

**Required For:**
- Accountants setting up new business
- Financial managers recording owner investments
- CFO documenting major capital events

**Security Considerations:**
- High-sensitivity operation (affects equity accounts)
- Should be restricted to senior financial staff
- All operations are logged in audit_log
- Journal entries are reversible via void operation

---

### 2. accounting.owner_contribution.view

**Display Name:** View Owner Contributions
**Description:** عرض وقراءة سجلات مساهمات المالك
**Category:** accounting_management
**Resource:** owner_contributions
**Action:** view

**Grants Access To:**
- GET `/setup/owner-contribution/:id`
- GET `/setup/owner-contributions` (list)

**Use Cases:**
- Review historical capital contributions
- Verify owner loan balances
- Audit trail inspection
- Financial reporting and analysis

**Required For:**
- Accountants reviewing financial history
- Auditors inspecting equity transactions
- Financial analysts preparing reports
- Owners reviewing their investment history

**Security Considerations:**
- Medium-sensitivity (read-only access)
- Shows financial relationship between owner and business
- Does not allow modifications
- Can be granted more broadly than create/void

---

### 3. accounting.owner_contribution.void

**Display Name:** Void Owner Contribution
**Description:** إلغاء مساهمة مالك موجودة (عكس القيد المحاسبي)
**Category:** accounting_management
**Resource:** owner_contributions
**Action:** void

**Grants Access To:**
- POST `/setup/owner-contribution/:id/void`

**Use Cases:**
- Correct data entry errors
- Reverse mistaken contributions
- Handle bounced owner deposits
- Cancel duplicate entries

**Required For:**
- Senior accountants with correction authority
- Financial controllers
- CFO for major adjustments

**Security Considerations:**
- **HIGHEST sensitivity** (can reverse equity transactions)
- Should be most restricted permission
- All voids are permanently logged in audit_log
- Original records preserved (soft delete via status='voided')
- Creates reversal journal entry (full audit trail)
- Requires justification (reason field)

---

## Permission Assignment

### Recommended Role Mapping

| Role | Create | View | Void |
|------|--------|------|------|
| **Owner** | ✅ | ✅ | ✅ |
| **CFO** | ✅ | ✅ | ✅ |
| **Financial Controller** | ✅ | ✅ | ✅ |
| **Senior Accountant** | ✅ | ✅ | ⚠️ (with approval) |
| **Accountant** | ⚠️ (with approval) | ✅ | ❌ |
| **Auditor** | ❌ | ✅ | ❌ |
| **Financial Analyst** | ❌ | ✅ | ❌ |
| **Cashier** | ❌ | ❌ | ❌ |
| **Sales Staff** | ❌ | ❌ | ❌ |

---

## Implementation

### Handler Integration

```php
class OwnerContributionHandler extends BaseHandler
{
    private $rbac;

    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->rbac = new RBACHandler($db);
    }

    public function create(Request $request, Response $response): Response
    {
        $userId = $this->getUserId($request);

        // Permission check
        if (!$userId || !$this->rbac->hasPermission($userId, 'accounting.owner_contribution.create')) {
            return $this->errorResponse($response, 'ليس لديك صلاحية لإنشاء مساهمات المالك', 403);
        }

        // Proceed with operation...
    }
}
```

### Testing Permissions

```bash
# 1. Create contribution (requires create permission)
curl -X POST http://localhost/api/v1/setup/owner-contribution/create \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{
    "contribution_type": "capital",
    "amount": 10000.00,
    "target_account_id": 1001,
    "related_gl_account_id": 3001
  }'

# Expected responses:
# - 201 Created (with permission)
# - 403 Forbidden (without permission): "ليس لديك صلاحية لإنشاء مساهمات المالك"

# 2. View contribution (requires view permission)
curl -X GET http://localhost/api/v1/setup/owner-contribution/123 \
  -H "Authorization: Bearer <token>"

# Expected responses:
# - 200 OK (with permission)
# - 403 Forbidden (without permission): "ليس لديك صلاحية لعرض مساهمات المالك"

# 3. Void contribution (requires void permission)
curl -X POST http://localhost/api/v1/setup/owner-contribution/123/void \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{
    "reason": "Incorrect amount"
  }'

# Expected responses:
# - 200 OK (with permission)
# - 403 Forbidden (without permission): "ليس لديك صلاحية لإلغاء مساهمات المالك"
```

---

## SQL Queries

### Assign Permissions to Role

```sql
-- Assign all owner contribution permissions to CFO role
INSERT INTO role_permissions (role_id, permission_id, tenant_id, created_at)
SELECT
    (SELECT id FROM roles WHERE name = 'CFO' AND tenant_id = 1 LIMIT 1) AS role_id,
    p.id AS permission_id,
    1 AS tenant_id,
    NOW() AS created_at
FROM permissions p
WHERE p.category = 'accounting_management'
  AND p.resource = 'owner_contributions'
  AND NOT EXISTS (
      SELECT 1 FROM role_permissions rp
      WHERE rp.role_id = (SELECT id FROM roles WHERE name = 'CFO' AND tenant_id = 1 LIMIT 1)
        AND rp.permission_id = p.id
        AND rp.tenant_id = 1
  );
```

### Check User Permissions

```sql
-- Check if user has specific permission
SELECT
    u.id AS user_id,
    u.email,
    r.name AS role_name,
    p.name AS permission_name,
    p.display_name
FROM users u
INNER JOIN roles r ON r.id = u.role_id
INNER JOIN role_permissions rp ON rp.role_id = r.id AND rp.tenant_id = u.tenant_id
INNER JOIN permissions p ON p.id = rp.permission_id
WHERE u.id = ?
  AND u.tenant_id = ?
  AND p.name LIKE 'accounting.owner_contribution.%';
```

### List Users with Owner Contribution Access

```sql
-- Find all users who can create owner contributions
SELECT DISTINCT
    u.id,
    u.email,
    u.full_name,
    r.name AS role_name,
    t.business_name
FROM users u
INNER JOIN roles r ON r.id = u.role_id
INNER JOIN role_permissions rp ON rp.role_id = r.id AND rp.tenant_id = u.tenant_id
INNER JOIN permissions p ON p.id = rp.permission_id
INNER JOIN tenants t ON t.id = u.tenant_id
WHERE p.name = 'accounting.owner_contribution.create'
  AND u.tenant_id = ?
  AND u.is_active = 1;
```

---

## Audit Trail

All permission checks are logged via MonologHandler:

```
[2026-09-29 14:30:15] owner_contribution_handler.WARNING: Owner contribution create - permission denied
{
  "tenant_id": 1,
  "user_id": 42,
  "requested_permission": "accounting.owner_contribution.create"
}
```

Failed authorization attempts can be tracked for security monitoring.

---

## Migration

Permissions are created via migration `011_add_owner_contribution_permissions.php`:

```bash
# Run migration
php database/migrate.php

# Verify permissions
mysql> SELECT * FROM permissions
       WHERE category = 'accounting_management'
       AND resource = 'owner_contributions';
```

---

## Troubleshooting

| Issue | Cause | Solution |
|-------|-------|----------|
| 403 Forbidden on create | User missing `create` permission | Assign permission to user's role |
| All operations return 403 | RBAC not initialized | Check `$this->rbac` in handler |
| Permission check bypassed | Missing check in handler | Verify permission check before operation |
| Super admin gets 403 | Super admin exemption not implemented | Add super admin bypass in handler |

---

## Future Enhancements

1. **Approval Workflow** - Require manager approval for large contributions
2. **Amount Thresholds** - Restrict permissions by contribution amount
3. **Time-Based Access** - Limit when contributions can be created (e.g., month-end only)
4. **Multi-Signature** - Require two users to approve large equity changes
5. **Permission Inheritance** - Auto-grant view permission with create permission

---

**Document Version:** 1.0
**Last Updated:** 2026-09-29
**Related Documents:**
- [AUDIT_TRAIL.md](./AUDIT_TRAIL.md) - Audit logging implementation
- [OWNER_CONTRIBUTIONS.md](./OWNER_CONTRIBUTIONS.md) - Feature documentation (Phase 10)
