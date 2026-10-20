<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Canonical permission identifiers used by RBAC checks.
 *
 * Keep these values aligned with the permission records managed by database
 * migrations. Super-admin (role_id 1) is bypassed by middleware; legacy
 * role_permissions rows are not an authorization source for that role.
 */
final class Permissions
{
    // ──────────────────────────────────────────────────────────────────
    // User Management
    // ──────────────────────────────────────────────────────────────────
    public const USER_VIEW   = 'user.view';
    public const USER_CREATE = 'user.create';
    public const USER_EDIT   = 'user.edit';
    public const USER_DELETE = 'user.delete';

    // ──────────────────────────────────────────────────────────────────
    // Role / Permission Management (RBAC self-management)
    // ──────────────────────────────────────────────────────────────────
    public const ROLE_VIEW       = 'role.view';
    public const ROLE_CREATE     = 'role.create';
    public const ROLE_EDIT       = 'role.edit';
    public const ROLE_DELETE     = 'role.delete';
    public const PERMISSION_VIEW   = 'permission.view';
    public const PERMISSION_ASSIGN = 'permission.assign';

    // ──────────────────────────────────────────────────────────────────
    // Products / Categories
    // ──────────────────────────────────────────────────────────────────
    public const PRODUCT_VIEW   = 'product.view';
    public const PRODUCT_CREATE = 'product.create';
    public const PRODUCT_EDIT   = 'product.edit';
    public const PRODUCT_DELETE = 'product.delete';

    // ──────────────────────────────────────────────────────────────────
    // Inventory
    // ──────────────────────────────────────────────────────────────────
    public const INVENTORY_VIEW     = 'inventory.view';
    public const INVENTORY_ADJUST   = 'inventory.adjust';
    public const INVENTORY_TRANSFER = 'inventory.transfer';

    // ──────────────────────────────────────────────────────────────────
    // Sales
    // ──────────────────────────────────────────────────────────────────
    public const SALE_VIEW     = 'sale.view';
    public const SALE_CREATE   = 'sale.create';
    public const SALE_EDIT     = 'sale.edit';
    public const SALE_VOID     = 'sale.void';
    public const SALE_DISCOUNT = 'sale.discount';
    public const PRODUCT_PURCHASE_PRICE_VIEW = 'product.purchase_price.view';

    // Sales Approval Workflow
    public const SALE_APPROVAL_VIEW    = 'sales.approval.view';
    public const SALE_APPROVAL_APPROVE = 'sales.approval.approve';
    public const SALE_APPROVAL_REJECT  = 'sales.approval.reject';

    // Sales Payments (customer payment collection)
    // Introduced in Week 1 — was previously unprotected.
    public const SALE_PAYMENT_CREATE = 'sale.payment.create';

    // ──────────────────────────────────────────────────────────────────
    // Purchases
    // ──────────────────────────────────────────────────────────────────
    public const PURCHASE_VIEW    = 'purchase.view';
    public const PURCHASE_CREATE  = 'purchase.create';
    public const PURCHASE_EDIT    = 'purchase.edit';
    public const PURCHASE_DELETE  = 'purchase.delete';
    public const PURCHASE_PAYMENT_CREATE = 'purchase.payment.create';

    // ──────────────────────────────────────────────────────────────────
    // Returns (sales + purchase)
    // ──────────────────────────────────────────────────────────────────
    public const RETURN_VIEW    = 'return.view';
    public const RETURN_CREATE  = 'return.create';
    public const RETURN_APPROVE = 'return.approve';
    public const RETURN_DELETE  = 'return.delete';

    // ──────────────────────────────────────────────────────────────────
    // Reports
    // ──────────────────────────────────────────────────────────────────
    public const REPORT_SALES     = 'report.sales';
    public const REPORT_INVENTORY = 'report.inventory';
    public const REPORT_PURCHASES = 'report.purchases';
    public const REPORT_FINANCIAL = 'report.financial';

    // ──────────────────────────────────────────────────────────────────
    // Settings
    // ──────────────────────────────────────────────────────────────────
    public const SETTINGS_VIEW   = 'settings.view';
    public const SETTINGS_MANAGE = 'settings.manage';

    // ──────────────────────────────────────────────────────────────────
    // Customers / Suppliers (Contacts)
    // ──────────────────────────────────────────────────────────────────
    public const CUSTOMER_VIEW   = 'customer.view';
    public const CUSTOMER_CREATE = 'customer.create';
    public const CUSTOMER_EDIT   = 'customer.edit';
    public const CUSTOMER_DELETE = 'customer.delete';

    public const SUPPLIER_VIEW   = 'supplier.view';
    public const SUPPLIER_CREATE = 'supplier.create';
    public const SUPPLIER_EDIT   = 'supplier.edit';
    public const SUPPLIER_DELETE = 'supplier.delete';

    // ──────────────────────────────────────────────────────────────────
    // Branches
    // ──────────────────────────────────────────────────────────────────
    public const BRANCH_VIEW   = 'branch.view';
    public const BRANCH_CREATE = 'branch.create';
    public const BRANCH_EDIT   = 'branch.edit';
    public const BRANCH_DELETE = 'branch.delete';

    // ──────────────────────────────────────────────────────────────────
    // Cash Vouchers
    // ──────────────────────────────────────────────────────────────────
    public const VOUCHER_VIEW   = 'voucher.view';
    public const VOUCHER_CREATE = 'voucher.create';
    public const VOUCHER_EDIT   = 'voucher.edit';
    public const VOUCHER_DELETE = 'voucher.delete';

    // ──────────────────────────────────────────────────────────────────
    // Categories
    // ──────────────────────────────────────────────────────────────────
    public const CATEGORY_VIEW   = 'category.view';
    public const CATEGORY_CREATE = 'category.create';

    // ──────────────────────────────────────────────────────────────────
    // Documents
    // ──────────────────────────────────────────────────────────────────
    public const DOCUMENT_VIEW   = 'document.view';
    public const DOCUMENT_UPLOAD = 'document.upload';
    public const DOCUMENT_EDIT   = 'document.edit';
    public const DOCUMENT_DELETE = 'document.delete';

    // ──────────────────────────────────────────────────────────────────
    // Payment Methods (configuration — not customer payments)
    // ──────────────────────────────────────────────────────────────────
    public const PAYMENT_METHOD_VIEW   = 'payment_method.view';
    public const PAYMENT_METHOD_MANAGE = 'payment_method.manage';

    // ──────────────────────────────────────────────────────────────────
    // Warranty
    // ──────────────────────────────────────────────────────────────────
    public const WARRANTY_VIEW   = 'warranty.view';
    public const WARRANTY_CREATE = 'warranty.create';
    public const WARRANTY_EDIT   = 'warranty.edit';
    public const WARRANTY_DELETE = 'warranty.delete';

    // ──────────────────────────────────────────────────────────────────
    // Accounting (periods, journal entries, account statements)
    // ──────────────────────────────────────────────────────────────────
    public const ACCOUNTING_VIEW          = 'accounting.view';
    public const ACCOUNTING_PERIOD_MANAGE = 'accounting.period.manage';
    // Separate elevated grant: required only to bypass period-close blockers, never assigned automatically.
    public const ACCOUNTING_PERIOD_CLOSE_OVERRIDE = 'accounting.period.close.override';
    public const ACCOUNTING_JE_CREATE     = 'accounting.je.create';
    public const ACCOUNTING_JE_DELETE     = 'accounting.je.delete';
    public const ACCOUNTING_JE_REVERSE    = 'accounting.je.reverse';
    public const ACCOUNTING_OPENING_BALANCE = 'accounting.opening_balance.commit';

    // Owner Contributions (capital injections, owner loans, opening balances)
    // Added in migration 011_add_owner_contribution_permissions.php
    public const ACCOUNTING_OWNER_CONTRIBUTION_CREATE = 'accounting.owner_contribution.create';
    public const ACCOUNTING_OWNER_CONTRIBUTION_VIEW   = 'accounting.owner_contribution.view';
    public const ACCOUNTING_OWNER_CONTRIBUTION_VOID   = 'accounting.owner_contribution.void';

    // ──────────────────────────────────────────────────────────────────
    // POS (sessions, shifts, terminals)
    // ──────────────────────────────────────────────────────────────────
    public const POS_SESSION_OPEN    = 'pos.session.open';
    public const POS_SESSION_CLOSE   = 'pos.session.close';
    public const POS_SESSION_MANAGE  = 'pos.session.manage';
    public const POS_TERMINAL_MANAGE = 'pos.terminal.manage';

    // ──────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────

    /** Return all permission identifiers defined by this catalog. */
    public static function all(): array
    {
        $ref = new \ReflectionClass(self::class);
        return array_values($ref->getConstants());
    }

    /** Group identifiers by the segment before their first dot. */
    public static function grouped(): array
    {
        $groups = [];
        foreach (self::all() as $perm) {
            $category = explode('.', $perm, 2)[0];
            $groups[$category][] = $perm;
        }
        return $groups;
    }
}
