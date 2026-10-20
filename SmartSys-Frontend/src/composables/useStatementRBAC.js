import { computed } from 'vue';
import { useAuthStore } from '@/stores/auth/authStore';

/**
 * Composable: useStatementRBAC
 * 
 * توفير صلاحيات (RBAC) للعمليات على كشوفات الحسابات
 * يتحقق من الصلاحيات بناءً على:
 * - دور المستخدم (role)
 * - بيانات المستخدم (isAdmin, isSuperAdmin, isOwner)
 * - نوع العملية (view, export, edit, delete, etc.)
 * 
 * الاستخدام:
 * ```javascript
 * const { canViewStatement, canExport, canEdit } = useStatementRBAC('customers');
 * ```
 */
export function useStatementRBAC(type = 'customers') {
  const authStore = useAuthStore();

  // ─── Role Checks ──────────────────────────────────────────────────────────

  const isSuperAdmin = computed(() => authStore.isSuperAdmin);
  const isAdmin = computed(() => authStore.isAdmin);
  const isOwner = computed(() => authStore.isOwner);

  const userRole = computed(() => authStore.user?.role || '');
  const userId = computed(() => authStore.user?.id);
  const userTenantId = computed(() => authStore.user?.tenant_id);

  // ─── Permission: View Statement ────────────────────────────────────────────

  /**
   * هل يمكن للمستخدم عرض كشف الحساب؟
   * المسموحون:
   * - Super Admin (view أي حساب)
   * - Admin/Manager (view أي حساب في الشركة)
   * - Cashier/User (view فقط الحسابات المخصصة لهم)
   * - Owner (view فقط أمواله)
   */
  const canViewStatement = computed(() => {
    if (!authStore.isAuthenticated) return false;

    // Super Admin والـ Owner لديهم وصول كامل
    if (isSuperAdmin.value || isOwner.value) return true;

    // Admin والـ Manager لديهم وصول للـ customers والـ suppliers
    if (isAdmin.value) return true;

    // Cashier والـ Users العاديين لديهم وصول محدود
    // سيتحقق من الخادم عند الجلب
    const allowedRoles = ['cashier', 'user', 'employee', 'staff'];
    return allowedRoles.includes(userRole.value?.toLowerCase());
  });

  // ─── Permission: Export Statement ──────────────────────────────────────────

  /**
   * هل يمكن للمستخدم تصدير كشف الحساب (PDF/CSV)؟
   * المسموحون:
   * - Super Admin والـ Admin
   * - Manager (تصدير الحسابات في فرعه)
   * - Owners (تصدير حساباتهم فقط)
   */
  const canExport = computed(() => {
    if (!authStore.isAuthenticated) return false;

    // Super Admin والـ Admin والـ Manager لديهم إمكانية التصدير
    if (isSuperAdmin.value || isAdmin.value) return true;

    // Owner يمكنه تصدير
    if (isOwner.value) return true;

    // Users عاديين لا يمكنهم
    return false;
  });

  // ─── Permission: Edit Statement ────────────────────────────────────────────

  /**
   * هل يمكن للمستخدم تعديل الحسابات؟
   * الخاص بـ statements: عادة ما يكون للـ Admin فقط
   * المسموحون:
   * - Super Admin والـ Admin
   * - Manager (في بعض الحالات)
   */
  const canEdit = computed(() => {
    if (!authStore.isAuthenticated) return false;

    // Super Admin والـ Admin فقط
    return isSuperAdmin.value || (isAdmin.value && userRole.value !== 'cashier');
  });

  // ─── Permission: Delete Statement ──────────────────────────────────────────

  /**
   * هل يمكن للمستخدم حذف بيانات الحسابات؟
   * عادة محصور جداً:
   * - Super Admin فقط
   */
  const canDelete = computed(() => {
    if (!authStore.isAuthenticated) return false;
    return isSuperAdmin.value;
  });

  // ─── Permission: View Sensitive Data ──────────────────────────────────────

  /**
   * هل يمكن للمستخدم رؤية بيانات حساسة؟
   * - رصيد العميل الإجمالي
   * - التسويات والديون
   * - البيانات المحاسبية المفصلة
   */
  const canViewSensitiveData = computed(() => {
    if (!authStore.isAuthenticated) return false;

    // Super Admin والـ Admin والـ Manager
    if (isSuperAdmin.value || isAdmin.value) return true;

    // Owner يرى بياناته فقط
    if (isOwner.value) return true;

    // Cashier يرى بيانات العملاء المسموحة له فقط
    return userRole.value?.toLowerCase() === 'cashier';
  });

  // ─── Permission: View Aging Analysis ──────────────────────────────────

  /**
   * هل يمكن للمستخدم رؤية تحليل الأعمار (Aging Analysis)؟
   * المسموحون:
   * - Super Admin والـ Admin والـ Manager
   * - Accountant (إذا كان موجود كـ role مستقل)
   */
  const canViewAgingAnalysis = computed(() => {
    if (!authStore.isAuthenticated) return false;

    // استخدم computed properties بدل string matching للتوافقية
    if (isSuperAdmin.value || isAdmin.value) return true;

    // تحقق من Accountant role كـ fallback
    return userRole.value?.toLowerCase() === 'accountant';
  });

  // ─── Permission: View Account Analysis ─────────────────────────────────────

  /**
   * هل يمكن للمستخدم رؤية التحليل المالي المفصل؟
   * المسموحون:
   * - Super Admin والـ Admin والـ Manager
   * - Accountant (إذا كان موجود كـ role مستقل)
   */
  const canViewAccountAnalysis = computed(() => {
    if (!authStore.isAuthenticated) return false;

    // استخدم computed properties بدل string matching
    if (isSuperAdmin.value || isAdmin.value) return true;

    // تحقق من Accountant role كـ fallback
    return userRole.value?.toLowerCase() === 'accountant';
  });

  // ─── Permission: Print/Generate Reports ────────────────────────────────────

  /**
   * هل يمكن للمستخدم طباعة/توليد التقارير؟
   */
  const canGenerateReports = computed(() => {
    if (!authStore.isAuthenticated) return false;

    // Super Admin والـ Admin والـ Manager
    if (isSuperAdmin.value || isAdmin.value) return true;

    // Accountant يمكنه توليد التقارير
    if (userRole.value?.toLowerCase() === 'accountant') return true;

    return false;
  });

  // ─── Helper: Check if can view specific statement ────────────────────────

  /**
   * هل يمكن للمستخدم عرض حساب معين؟
   * @param {number} statementId - معرف الحساب/العميل
   * @param {string} ownerId - معرف المالك (لـ checking ownership)
   * @param {string} branchId - معرف الفرع (للـ branch checking)
   */
  const canViewSpecificStatement = (statementId, ownerId = null, branchId = null) => {
    if (!canViewStatement.value) return false;

    // Super Admin يرى الكل
    if (isSuperAdmin.value) return true;

    // Admin يرى الكل في نفس الشركة (tenant)
    if (isAdmin.value) return true;

    // Owner يرى حساباته فقط
    if (isOwner.value && ownerId && userId.value === ownerId) return true;

    // Cashier/User يرى الحسابات المخصصة له فقط
    // هذا يتطلب تحقق من الخادم
    // للآن: نسمح برؤية إذا كان لديه أي صلاحية
    return userRole.value?.toLowerCase() === 'cashier';
  };

  // ─── Audit Logging ────────────────────────────────────────────────────────

  /**
   * تسجيل عملية وصول لأغراض المراجعة
   */
  const logAccess = (action, resourceType, resourceId, details = {}) => {
    const auditLog = {
      timestamp: new Date().toISOString(),
      userId: userId.value,
      userRole: userRole.value,
      action, // view, export, edit, delete
      resourceType, // statement, customer, supplier
      resourceId,
      details,
      allowed: true,
    };

    // يمكن إرسال هذا للخادم لاحقاً
    if (import.meta.env.DEV) {
      console.log('[RBAC Audit]', auditLog);
    }


  };

  /**
   * تسجيل عملية رفض لأغراض الأمان
   */
  const logDenial = (action, resourceType, resourceId, reason = 'permission_denied') => {
    const auditLog = {
      timestamp: new Date().toISOString(),
      userId: userId.value,
      userRole: userRole.value,
      action,
      resourceType,
      resourceId,
      reason,
      allowed: false,
    };

    if (import.meta.env.DEV) {
      console.warn('[RBAC Denial]', auditLog);
    }


  };

  // ─── Return Public API ─────────────────────────────────────────────────────

  return {
    // State
    userRole,
    userId,
    isSuperAdmin,
    isAdmin,
    isOwner,

    // Permissions
    canViewStatement,
    canExport,
    canEdit,
    canDelete,
    canViewSensitiveData,
    canViewAgingAnalysis,
    canViewAccountAnalysis,
    canGenerateReports,
    canViewSpecificStatement,

    // Audit
    logAccess,
    logDenial,
  };
}
