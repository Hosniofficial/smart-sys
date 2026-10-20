# Final Test Results — SmartSys Fix Verification

**57/57 checks PASS across 8 independent test groups, run against a fresh
MariaDB database loaded from `schema.sql` + `seed.sql`, executing the real,
unmodified fixed source files.**

| Group | File | Checks | What it proves |
|---|---|---|---|
| A–C | test_status_and_payments.php | 14 | Status vocabulary, duplicate-payment retry guard, distinct-Idempotency-Key protection |
| D | test_stock_transfer.php | 7 | Reproduces the original MySQL execution-order bug (250 vs correct 200), proves the fix and value conservation |
| E | test_cogs_reversal.php | 8 | Reproduces the audit's RETURN-001 example — old logic leaked $13.33, fixed logic nets to $0.00 |
| F | test_stock_adjustment.php | 6 | Missing-accounts scenario now throws; zero-amount still safely no-ops |
| G | test_return_concurrency.php | 2 | Genuine concurrency test with two live PDO connections proving the FOR UPDATE lock blocks |
| H | test_report_filters.php | 3 | Quantifies the old dead filter's undercounting (100 vs correct 750) |
| I | test_supplier_statement.php | 10 | Purchase returns now correctly adjust purchase status/balance (new finding + fix) |
| J | test_balance_calculation.php | 7 | Purchase tax double-counting bug fixed (1140 not 1280); refund-netting convention now consistent across 3 independently-written formulas |

## Run it yourself

```bash
mysql -u root -e "CREATE DATABASE smarttest CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -e "CREATE USER IF NOT EXISTS 'testuser'@'localhost' IDENTIFIED BY 'testpass'; GRANT ALL ON smarttest.* TO 'testuser'@'localhost'; FLUSH PRIVILEGES;"
mysql -u root smarttest < schema.sql
mysql -u root smarttest < seed.sql
# edit autoload.php to point at your SmartSys checkout, then:
for f in test_*.php; do php "$f"; done
```

## Historical Group K — retired unregistered RBAC operations

The former `test_rbac_security.php` checked permission-catalog mutations and role
updates by calling `RBACHandler` methods directly. Those operations had no route
registration and no frontend consumer, so the handler methods and this direct-call
test have since been removed. The old 10-12/12 result describes that earlier
revision only; `tests/Unit/RBACHandlerSurfaceTest.php` now guards the current
registered handler surface instead.

## Group L — SetupHandler::isOwner() tenant-scoping fix

**3/3 PASS** (`test_setup_handler.php`) — found while continuing the systematic
tenant-isolation sweep. `isOwner()`'s role-name subquery
(`SELECT id FROM roles WHERE name='owner' LIMIT 1`) wasn't scoped by tenant_id,
so it always resolved to whichever tenant's 'owner' role has the lowest id in
the whole table — not necessarily the calling tenant's own. Confined by the
outer tenant_id filter on `users`, so this could not grant cross-tenant access,
but could wrongly deny a legitimate tenant owner whose `is_owner` flag wasn't
reliably set. Test reproduces the exact triggering condition and confirms both
the fix and two control cases (genuine non-owner still denied; is_owner=1 flag
path still works independently).
