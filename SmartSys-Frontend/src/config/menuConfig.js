/**
 * SINGLE SOURCE OF TRUTH: Menu, Permissions & Section Configuration
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CRITICAL INVARIANTS
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * 1. NO DUPLICATE PATHS
 *    Each path is defined EXACTLY ONCE. validateMenuConfig() enforces this
 *    at build/boot time and throws if violated.
 *
 * 2. QUERY PARAMETERS ARE NOT PATH VARIANTS
 *    /settings/details?tab=invoice-settings and ?tab=pos-settings both map
 *    to the base path /settings/details. Tab selection is handled by the
 *    page component, never by the menu or the router guard.
 *
 * 3. BREADCRUMB = BASE PATH ONLY
 *    Query params never change the breadcrumb section/label.
 *
 * 4. DYNAMIC ROUTES MUST BE PRECISE
 *    /sales/:id matches /sales/123 but never /sales/approvals.
 *    relatedRoutes are for DETAIL pages only. validateMenuConfig() also
 *    rejects two dynamic patterns that would overlap on the same segment
 *    count (e.g. "/sales/:id" and "/sales/:something" under the same
 *    static prefix), since that is almost certainly a copy-paste bug.
 *
 * 5. PERMISSIONS ARE CENTRALIZED — THIS FILE IS THE ONLY SOURCE
 *    - The router guard (router/index.js) calls checkAccessForPath() /
 *      hasMenuPermission() from THIS file. It no longer keeps its own
 *      parallel copy of `requiresAdmin` / `permissions` meta logic.
 *    - Sidebar renders strictly from getMenuItems().
 *    - Breadcrumb reads access from getBreadcrumbForPath().
 *
 * 6. PERMISSION SEMANTICS (explicit policy — do not change silently)
 *    - `permission: 'x'`            → user must have exactly that permission.
 *    - `permissions: ['a','b']`     → OR by default: user needs AT LEAST ONE.
 *    - `permissions: ['a','b']` AND
 *      `permissionsMode: 'all'`     → user needs ALL listed permissions.
 *    Rationale: most menu items only gate "can you see this feature at all",
 *    so ANY relevant permission is normally sufficient. Items that
 *    genuinely require multiple simultaneous permissions must opt in
 *    explicitly via `permissionsMode: 'all'` — this was undocumented
 *    before and is now a first-class, tested flag.
 *
 * 7. ROLE HIERARCHY (explicit — fixes the manager/admin conflation bug)
 *    - superAdmin: full system access, bypasses permission checks.
 *    - admin:      tenant-wide administrative access.
 *    - manager:    branch/operational management — NOT full admin.
 *                  Only sees items whose access is 'user' or 'manager',
 *                  never bare 'admin'-only items unless also granted the
 *                  specific permission.
 *    Use access: 'admin' ONLY for things that must be tenant-admin-only
 *    (e.g. system data integrity). Use access: 'manager' for
 *    branch-manager-level screens that should NOT be exposed to every admin
 *    tier below superAdmin either, if that distinction matters — otherwise
 *    'user' + a specific permission is usually the right, more granular tool.
 *
 * 8. SIDEBAR RENDERS FROM menuConfig — Layout.vue holds no menu structure.
 * ═══════════════════════════════════════════════════════════════════════════
 */

import {
  LayoutDashboard, ShoppingCart, FileText, ClipboardCheck,
  Users, Truck, Wallet, Banknote, Receipt, Shield,
  Undo2, History, ShoppingBag, Package2, Warehouse,
  Building2, Share2, CreditCard, Link, TrendingUp,
  ArrowLeftRight, Clock, BarChart2, Settings, PieChart,
  UserCog, ShieldCheck, Layers, BookOpen,
  ClipboardList, CalendarCheck
} from 'lucide-vue-next'

/**
 * Menu Configuration: Single Source of Truth.
 *
 * RULES:
 * - Each path MUST be unique (no duplicates) — enforced by validateMenuConfig().
 * - Query params are handled by components, not the menu.
 * - Dynamic routes go in relatedRoutes with their patterns.
 * - Permissions/roles are centralized in 'access' / 'permission(s)' fields —
 *   see policy notes #6-#7 above.
 */
export const menuConfig = [
  // ═══════════════════════════════════════════════════════════════════════
  // I. CORE OPERATIONS — الأساسية (المبيعات والمشتريات والمخزون)
  // ═══════════════════════════════════════════════════════════════════════

  {
    name: 'لوحة التحكم',
    path: '/dashboard',
    icon: LayoutDashboard,
    access: 'user'
  },

  {
    name: 'المبيعات',
    key: 'sales',
    icon: ShoppingCart,
    access: 'user',
    items: [
      { name: 'نقطة البيع', path: '/sales/pos', icon: ShoppingCart, access: 'user', permissions: ['sale.view', 'sale.create'] },
      { name: 'سجل المبيعات', path: '/sales/history', icon: FileText, access: 'user', permission: 'sale.view' },
      { name: 'موافقات المبيعات', path: '/sales/approvals', icon: ClipboardCheck, access: 'user', permission: 'sales.approval.view' },
      { name: 'لوحة تحكم الكاشير', path: '/cashier', icon: LayoutDashboard, access: 'user' },
      { name: 'جلسات الكاشير', path: '/sessions', icon: Clock, access: 'user', permission: 'pos.session.manage' },
    ],
    relatedRoutes: [
      { path: '/sales/:id', label: 'تفاصيل المبيعة', access: 'user' },
    ]
  },

  {
    name: 'المرتجعات',
    key: 'returns',
    icon: Undo2,
    access: 'user',
    items: [
      { name: 'إدارة المرتجعات', path: '/returns', icon: Undo2, access: 'user', permission: 'return.view' },
      { name: 'سجل المرتجعات', path: '/returns/history', icon: History, access: 'user', permission: 'return.view' },
    ],
    relatedRoutes: []
  },

  {
    name: 'الضمان',
    key: 'warranty',
    icon: Shield,
    access: 'user',
    items: [
      { name: 'طلبات الضمان', path: '/warranty', icon: Shield, access: 'user' },
    ],
    relatedRoutes: []
  },

  {
    name: 'صيانة الأجهزة',
    path: '/repairs',
    icon: CalendarCheck,
    access: 'user',
    permission: 'sale.view'
  },

  {
    name: 'المشتريات',
    key: 'purchases',
    icon: ShoppingBag,
    access: 'user',
    items: [
      { name: 'إدارة المشتريات', path: '/purchases', icon: ShoppingBag, access: 'user', permission: 'purchase.view' },
      { name: 'سجل المشتريات', path: '/purchases/history', icon: History, access: 'user', permission: 'purchase.view' },
    ],
    relatedRoutes: [
      { path: '/purchases/:id', label: 'تفاصيل المشتريات', access: 'user' },
    ]
  },

  {
    name: 'المخزون',
    key: 'inventory',
    icon: Package2,
    access: 'user',
    items: [
      { name: 'إدارة المنتجات', path: '/products', icon: Package2, access: 'user', permission: 'product.view' },
      { name: 'إدارة المخزون', path: '/inventory', icon: Warehouse, access: 'user', permission: 'inventory.view' },
      { name: 'إدارة الفروع', path: '/branches', icon: Building2, access: 'user', permission: 'branch.view' },
      { name: 'توزيع جماعي', path: '/branches/bulk-distribution', icon: Share2, access: 'user', permission: 'inventory.transfer' },
      { name: 'الرصيد الافتتاحي', path: '/opening-balance', icon: CreditCard, access: 'user', permission: 'accounting.opening_balance.commit' },
    ],
    relatedRoutes: [
      { path: '/branches/:id', label: 'تفاصيل الفرع', access: 'admin' },
    ]
  },

  // ═══════════════════════════════════════════════════════════════════════
  // II. RELATIONSHIPS — العلاقات (العملاء والموردين)
  // ═══════════════════════════════════════════════════════════════════════

  {
    name: 'العملاء والموردين',
    key: 'contacts',
    icon: Users,
    access: 'user',
    items: [
      { name: 'العملاء', path: '/customers', icon: Users, access: 'user', permission: 'customer.view' },
      { name: 'الموردين', path: '/suppliers', icon: Truck, access: 'user', permission: 'supplier.view' },
    ],
    relatedRoutes: [
      { path: '/contacts/:type/:id/details', label: 'تفاصيل الجهة', access: 'user' },
      { path: '/contacts/:type/:id/statement', label: 'كشف حساب', access: 'user' },
    ]
  },

  // ═══════════════════════════════════════════════════════════════════════
  // III. FINANCE & ACCOUNTING — المالية والحسابات
  // ═══════════════════════════════════════════════════════════════════════

  {
    name: 'المالية',
    key: 'finance',
    icon: Wallet,
    access: 'user',
    items: [
      { name: 'العمليات المالية', path: '/payments', icon: Banknote, access: 'user', permissions: ['sale.payment.create', 'purchase.payment.create'] },
      { name: 'طرق الدفع', path: '/payment-methods', icon: CreditCard, access: 'user', permission: 'payment_method.view' },
      { name: 'سندات القبض والصرف', path: '/vouchers', icon: Receipt, access: 'user', permission: 'voucher.view' },
      { name: 'الفترات المحاسبية', path: '/accounting/periods', icon: CalendarCheck, access: 'user', permission: 'accounting.period.manage' },
      { name: 'مساهمات المالك', path: '/accounting/owner-contributions', icon: Wallet, access: 'admin' },
    ],
    relatedRoutes: []
  },

  {
    name: 'التقارير',
    key: 'reports',
    icon: BarChart2,
    access: 'user',
    items: [
      { name: 'تحليلات المبيعات', path: '/reports/sales-analytics', icon: PieChart, access: 'user', permission: 'report.sales', group: 'sales' },
      { name: 'ملخص المبيعات', path: '/reports/sales-summary', icon: Receipt, access: 'user', permission: 'report.sales', group: 'sales' },
      { name: 'ملخص الجلسات', path: '/reports/sessions-summary', icon: Clock, access: 'user', permission: 'report.sales', group: 'sales' },
      { name: 'تقرير الأرباح والخسائر', path: '/reports/profit-loss', icon: TrendingUp, access: 'user', permission: 'report.financial', group: 'financial' },
      { name: 'تقرير الصرف والقبض', path: '/reports/cash-vouchers', icon: Banknote, access: 'user', permission: 'report.financial', group: 'financial' },
      { name: 'التقارير المحاسبية', path: '/reports/accounting', icon: BookOpen, access: 'user', permission: 'report.financial', group: 'financial' },
      { name: 'حركة المخزون', path: '/reports/inventory-movements', icon: ArrowLeftRight, access: 'user', permission: 'report.inventory', group: 'inventory' },
      { name: 'قيمة المخزون', path: '/reports/inventory-value', icon: TrendingUp, access: 'user', permission: 'report.inventory', group: 'inventory' },
      { name: 'قيمة المخزون حسب الفرع', path: '/reports/inventory-value/by-branch', icon: Building2, access: 'user', permission: 'report.inventory', group: 'inventory' },
      { name: 'سجل تدقيق النظام', path: '/reports/audit-logs', icon: ClipboardList, access: 'user', permission: 'report.inventory', group: 'system' },
    ],
    relatedRoutes: []
  },

  // ═══════════════════════════════════════════════════════════════════════
  // IV. ADMINISTRATION & SYSTEM — الإدارة والنظام
  // ═══════════════════════════════════════════════════════════════════════

  {
    name: 'الإعدادات',
    key: 'settings',
    icon: Settings,
    access: 'user',
    items: [
      { name: 'الإعدادات', path: '/settings/details', icon: Settings, access: 'user', permission: 'settings.view' },
      { name: 'ربط حسابات المخازن', path: '/settings/branches-accounting', icon: Link, access: 'user', permission: 'settings.manage' },
      { name: 'إدارة المحطات', path: '/settings/terminals', icon: Layers, access: 'admin' },
    ],
    relatedRoutes: []
  },

  {
    name: 'النظام والتدقيق',
    key: 'system',
    icon: UserCog,
    access: 'user',
    items: [
      { name: 'إدارة الموظفين والصلاحيات', path: '/users', icon: UserCog, access: 'user', permissions: ['user.view', 'role.view', 'permission.view'] },
      { name: 'سلامة البيانات', path: '/data-integrity', icon: ShieldCheck, access: 'admin' },
    ],
    relatedRoutes: []
  },

  {
    name: 'الاشتراكات',
    key: 'subscriptions',
    icon: CreditCard,
    access: 'superAdmin',
    items: [
      { name: 'الاشتراكات', path: '/admin/subscriptions', icon: CreditCard, access: 'superAdmin' },
      { name: 'الخطط', path: '/admin/plans', icon: Layers, access: 'superAdmin' },
    ],
    relatedRoutes: []
  }
]

// ═══════════════════════════════════════════════════════════════════════════
// Shared route-pattern matching (single implementation — no more copies in
// AppSidebar.vue / Layout.vue). Also exported for composables/useActiveRoute.js.
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Convert a route pattern like "/sales/:id" into a RegExp that matches
 * exactly one path segment per ":param", anchored on both ends.
 */
export function patternToRegex(pattern) {
  const regexBody = pattern
    .split('/')
    .map(part => (part.startsWith(':') ? '[^/]+' : part.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')))
    .join('/')
  return new RegExp(`^${regexBody}$`)
}

export function matchesRoutePattern(pattern, path) {
  return patternToRegex(pattern).test(path)
}

// ═══════════════════════════════════════════════════════════════════════════
// VALIDATION
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Returns true if two route patterns (each possibly containing ":param"
 * segments) could overlap — i.e. some concrete path could match both.
 * Two patterns overlap only if they have the same segment count AND every
 * segment pair is either equal, or at least one side is a ":param".
 */
function patternsOverlap(a, b) {
  const segsA = a.split('/')
  const segsB = b.split('/')
  if (segsA.length !== segsB.length) return false
  return segsA.every((segA, i) => {
    const segB = segsB[i]
    if (segA.startsWith(':') || segB.startsWith(':')) return true
    return segA === segB
  })
}

/**
 * VALIDATION: Ensure the menuConfig is internally consistent.
 * Throws a descriptive Error if any invariant is violated. Call this once
 * at app boot (see main.js) so a bad config fails fast in CI/dev instead of
 * silently producing broken navigation in production.
 */
export function validateMenuConfig() {
  const pathsSeen = new Map() // basePath -> where it was first seen
  const dynamicPatterns = []  // { pattern, source }

  function registerPath(path, source) {
    const basePath = path.split('?')[0]
    if (pathsSeen.has(basePath)) {
      throw new Error(
        `❌ DUPLICATE PATH VIOLATION: "${basePath}" is defined in both ` +
        `"${pathsSeen.get(basePath)}" and "${source}".\n` +
        `Each path must be defined EXACTLY ONCE across menuConfig.`
      )
    }
    pathsSeen.set(basePath, source)

    if (basePath.includes(':')) {
      dynamicPatterns.push({ pattern: basePath, source })
    }
  }

  function traverseItems(items, source) {
    items.forEach(item => {
      if (item.path) registerPath(item.path, `${source} > ${item.name}`)
      if (item.items) traverseItems(item.items, `${source} > ${item.name}`)
    })
  }

  menuConfig.forEach(section => {
    if (section.path) registerPath(section.path, section.name)
    if (section.items) traverseItems(section.items, section.name)

    if (section.relatedRoutes) {
      section.relatedRoutes.forEach(route => {
        registerPath(route.path, `${section.name} > relatedRoutes`)
      })
    }
  })

  // Reject overlapping dynamic patterns (e.g. "/sales/:id" vs "/sales/:foo"
  // under a path that would ambiguously match both).
  for (let i = 0; i < dynamicPatterns.length; i++) {
    for (let j = i + 1; j < dynamicPatterns.length; j++) {
      const p1 = dynamicPatterns[i]
      const p2 = dynamicPatterns[j]
      if (p1.pattern !== p2.pattern && patternsOverlap(p1.pattern, p2.pattern)) {
        throw new Error(
          `❌ OVERLAPPING DYNAMIC ROUTES: "${p1.pattern}" (${p1.source}) and ` +
          `"${p2.pattern}" (${p2.source}) can match the same concrete path. ` +
          `Dynamic route patterns must be unambiguous.`
        )
      }
    }
  }

  return true
}

// ═══════════════════════════════════════════════════════════════════════════
// BREADCRUMBS
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Build breadcrumb information map from menuConfig. AUTO-GENERATED —
 * never edit manually, edit menuConfig above instead.
 */
function buildBreadcrumbMap() {
  validateMenuConfig()

  const map = {}

  function traverse(items, sectionName) {
    items.forEach(item => {
      if (item.path) {
        const basePath = item.path.split('?')[0]
        map[basePath] = {
          section: sectionName,
          label: item.name,
          access: item.access || 'user',
          permission: item.permission,
          permissions: item.permissions,
          permissionsMode: item.permissionsMode
        }
      }
      if (item.items) traverse(item.items, item.name || sectionName)
    })
  }

  menuConfig.forEach(section => {
    if (section.items) {
      traverse(section.items, section.name)
    } else if (section.path) {
      const basePath = section.path.split('?')[0]
      map[basePath] = {
        section: section.name,
        label: section.name,
        access: section.access || 'user',
        permission: section.permission,
        permissions: section.permissions,
        permissionsMode: section.permissionsMode
      }
    }

    if (section.relatedRoutes && section.relatedRoutes.length > 0) {
      section.relatedRoutes.forEach(route => {
        map[route.path] = {
          section: section.name,
          label: route.label || section.name,
          access: route.access || section.access || 'user',
          permission: route.permission,
          permissions: route.permissions,
          permissionsMode: route.permissionsMode,
          isDynamic: route.path.includes(':')
        }
      })
    }
  })

  return map
}

let cachedBreadcrumbMap = null

function getBreadcrumbMap() {
  if (!cachedBreadcrumbMap) {
    cachedBreadcrumbMap = buildBreadcrumbMap()
  }
  return cachedBreadcrumbMap
}

/**
 * Get breadcrumb information for a path.
 * Handles: exact matches, query params, and dynamic routes.
 * @param {string} path - route.path (NOT route.fullPath — query params are
 *   intentionally stripped before this function is ever called; see policy #2).
 * @returns {object|null} - { section, label, access, ... } or null.
 */
export function getBreadcrumbForPath(path) {
  const map = getBreadcrumbMap()

  if (map[path]) return map[path]

  const basePath = path.split('?')[0]
  if (map[basePath]) return map[basePath]

  for (const [routePath, info] of Object.entries(map)) {
    if (routePath.includes(':') && matchesRoutePattern(routePath, basePath)) {
      return info
    }
  }

  return null
}

// ═══════════════════════════════════════════════════════════════════════════
// PERMISSIONS — single implementation used by BOTH the sidebar and the
// router guard. This is what fixes the "two sources of truth" bug: the
// router previously re-implemented its own OR-only permission check from
// route.meta instead of calling into this file.
// ═══════════════════════════════════════════════════════════════════════════

function normalizeRole(role) {
  return role === 'super_admin' ? 'superAdmin' : role
}

function normalizePermissionNames(permissions = []) {
  return permissions
    .map(permission => {
      if (typeof permission === 'string') return permission
      return permission?.name || permission?.key || permission?.slug
    })
    .filter(Boolean)
}

// Manager is an operational/branch role, NOT a full tenant admin.
// Only 'admin' and 'superAdmin' are treated as full admin. See policy #7.
const FULL_ADMIN_ROLES = ['superAdmin', 'admin']
const MANAGER_ROLES = ['manager']

/**
 * Evaluate whether a role+permissions combination satisfies a menu/route
 * entry's access requirements. This is the ONLY place this logic lives.
 *
 * @param {object} entry - { access, permission, permissions, permissionsMode }
 * @param {string} role - raw role string from the user object
 * @param {Array} permissions - raw permissions array/objects from the user
 */
export function hasMenuPermission(entry, role, permissions = [], userContext = {}) {
  const hasTenantContext = Object.prototype.hasOwnProperty.call(userContext, 'tenant_id')
  const tenantUser = Number(userContext.tenant_id) > 0
  const isPlatformSuperAdmin = hasTenantContext && !tenantUser && normalizeRole(role) === 'superAdmin'
  const effectiveRole = tenantUser && normalizeRole(role) === 'superAdmin'
    ? (userContext.is_owner ? 'admin' : 'employee')
    : role
  const normalizedRole = normalizeRole(effectiveRole)
  const permissionNames = normalizePermissionNames(permissions)

  if (isPlatformSuperAdmin) return true

  const requiredPermissions = entry.permissions || (entry.permission ? [entry.permission] : [])

  if (requiredPermissions.length > 0) {
    const mode = entry.permissionsMode === 'all' ? 'all' : 'any'
    return mode === 'all'
      ? requiredPermissions.every(permission => permissionNames.includes(permission))
      : requiredPermissions.some(permission => permissionNames.includes(permission))
  }

  const access = entry.access || 'user'
  if (access === 'user') return true
  if (access === 'manager') return MANAGER_ROLES.includes(normalizedRole) || FULL_ADMIN_ROLES.includes(normalizedRole)
  if (access === 'admin') return FULL_ADMIN_ROLES.includes(normalizedRole)
  if (access === 'superAdmin') return normalizedRole === 'superAdmin'

  // Fallback: treat an unrecognized access value as a permission name itself.
  return permissionNames.includes(access)
}

/**
 * Check if a user has access to a given path, using menuConfig as the
 * single source of truth. Used by BOTH the router guard and any
 * component-level access checks (e.g. hiding action buttons).
 *
 * @param {string} path
 * @param {{ role: string, permissions: Array }} user
 * @returns {boolean}
 */
export function checkAccessForPath(path, user) {
  const info = getBreadcrumbForPath(path)
  if (!info) return false // unknown route → deny (safe fallback)
  return hasMenuPermission(info, user?.role, user?.permissions, user)
}

/**
 * Get all sections and their items for sidebar rendering, filtered by role
 * and permissions. Used by AppSidebar.vue and the mobile drawer.
 * @returns {array} - filtered menuConfig array
 */
export function getMenuItems(role = 'employee', permissions = [], userContext = {}) {
  function filterItems(items) {
    return items.reduce((visibleItems, item) => {
      if (!hasMenuPermission(item, role, permissions, userContext)) return visibleItems
      const visibleItem = { ...item }
      if (item.items) visibleItem.items = filterItems(item.items)
      if (visibleItem.items?.length || !item.items) visibleItems.push(visibleItem)
      return visibleItems
    }, [])
  }

  return filterItems(menuConfig)
}
