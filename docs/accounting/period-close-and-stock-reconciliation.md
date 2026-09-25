# Period close and stock/GL reconciliation

## Approved operating rules

- `product_branch_gl_mapping` is supporting branch/accounting metadata, not a precondition for a purchase, sale, or return. Those operations must not create a mapping implicitly.
- Inventory account resolution is centralized in `AccountingService::resolveInventoryAccountForBranch()`: branch `account_id`, branch-specific tenant setting (`branch.{id}.inventory_account_id` or `inventory.branch.{id}.account_id`), tenant inventory setting, then account `1301` as the approved fallback. COGS (`5103`) remains tenant-level. `purchase_gl_account_id` is not used by posting paths at present.
- A stock-changing operation invalidates `branch_products.gl_reconciled` only when the row was reconciled (`1`). The invalidation is made by `BranchInventoryReconciliationService::markUnreconciled()` inside the same transaction as the stock mutation. The call sites are purchase, sale creation/approval/deletion, return, transfer (both branches), and all stock-adjustment entry points.
- Opening balance is the exception to the invalidation rule: its branch-product row starts unreconciled and becomes reconciled only after the opening journal entry posts successfully. The opening-balance work and that status update must remain in the same transaction.
- Opening balance may reuse an existing, explicitly configured branch mapping, but it never creates or activates a mapping. Without a mapping, journal and stock references remain valid with a NULL mapping reference; migration `005` is required for the cost snapshot FK to permit this.
- `ProductBranchHandler::activateProductInBranch()` is the explicit operational activation path that may create/update a mapping. Ordinary stock posting and opening-balance posting must not invoke it implicitly.

## Closing an accounting period

`AccountingPeriodsHandler::close()` requires the ordinary `accounting.period.manage` permission through route middleware. A request with `override=true` additionally requires `accounting.period.close.override` and a non-empty `override_reason`.

`AccountingPeriodCloseService` checks two blockers, scoped to the tenant and the inclusive calendar dates of the period:

1. Branch/product rows with `gl_reconciled = 0` that have at least one inventory movement in the period.
2. Journal entries with `draft` or `NULL` status and an `entry_date` in the period.

Without override, blockers return HTTP 409 and the period remains open. With an authorized override, the service closes the period and writes `accounting_period_close_override` to `audit_log` in the same PDO transaction. The audit detail stores the reason and blocker snapshot. If the close or audit write fails, the transaction rolls back. An override request without blockers closes normally but is not recorded as an override event, because no blocker was bypassed.

Example request body:

```json
{
  "override": true,
  "override_reason": "Approved reason recorded for audit"
}
```

Migration `004_add_accounting_period_close_override` creates the permission definition only. It does not create a role or assign the permission to a role/user; that grant requires an explicit access-control decision. Migration `005_allow_optional_opening_snapshot_mapping` makes only the snapshot's mapping FK nullable; it preserves the FK for non-NULL ids and does not alter historical rows.

## Migration safety and current environment warning

`MigrationDatabase` resolves canonical `.env` keys (`DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`) before legacy aliases (`DB_NAME`, `DB_USER`, `DB_PASS`). The migration runner orders migrations `001` through `005` and shares one PDO connection with each migration class.

The runner is fail-closed:

- `status` is read-only and reports missing or inconsistent history.
- `up` may bootstrap the ledger only when the target database has no application tables.
- Existing tables with no ledger, an empty ledger beside existing tables, an unknown migration, or a gap/out-of-order history cause `up` and `down` to stop. The runner does not infer that a migration ran from schema contents.
- Existing database history must be reconciled and reviewed explicitly before `migrate.php up`; do not create a baseline merely to silence the guard.
- Migration `004` is intentionally irreversible because the permission row must remain available to existing role links; `down` refuses to remove its ledger entry while retaining the permission.
- Migration `005` can be reversed only if no snapshot row has a NULL mapping reference; otherwise `down` refuses to invent a mapping id.

**Development database note (2026-09-25):** database `smartsys` on `DESKTOP-CQLDNVF` has its application schema and permission `accounting.period.close.override`, but no `migrations` ledger table. This is a known mismatch, not evidence that migrations `001`–`005` were all applied; migration `005` has not been run there. Do not run `migrate.php up` against this database until its migration baseline is reconciled. The same rule applies more strongly to production: take a verified backup, reconcile the existing baseline, review `status`, then apply only the approved migration plan.

## Returns and original-document references

For return persistence, `invoice_id` records the source invoice/document id; `sale_id` and `purchase_id` remain typed foreign references used by their respective workflows. Preserve both where the request identifies both values. A cash sale with no customer has no customer balance to offset; customer-outstanding calculations are performed only when a real customer id is present. Credit offsets must represent an actual customer receivable, not balancing-only customer ledger lines.

## MariaDB cascade caveat for journal-line triggers

On the development MariaDB 10.4.32 instance, deleting a `journal_entries` parent cascades its `journal_entry_lines` rows but does **not** execute the child table's DELETE trigger. This was observed during the QA cleanup; the test-only account balance was restored from its exact pre-test snapshot in the same cleanup transaction. Do not rely on a cascade to reverse trigger-maintained account balances. Any future journal deletion workflow must either explicitly reverse accounting balances through a supported service or avoid deleting posted journal history.
