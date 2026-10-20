/**
 * Composable للتحقق الإجباري من عزل البيانات على مستوى Branch
 * 
 * يضمن أن جميع طلبات API تتضمن branch_id بشكل إجباري
 * ويمنع تسرب البيانات بين الفروع المختلفة
 */

import { computed } from 'vue';
import { useAuthStore } from '@/stores/auth/authStore';
import { useBranchStore } from '@/stores/branch/branchStore';

export function useBranchIsolation() {
  const authStore = useAuthStore();
  const branchStore = useBranchStore();

  // ─── isExempt: computed مباشر من authStore بدلاً من useSessionExemption() instance منفصل
  // نفس المعيار بالضبط — بدون async ولا ref(false) ينتظر ensureLoaded()
  const isExempt = computed(() => {
    const role = String(authStore.user?.role || '').toLowerCase();
    return (
      authStore.isSuperAdmin ||
      authStore.isAdmin ||
      role === 'manager'
    );
  });
  
  // الفرع المختار (للمستخدمين المعفيين) - مصدر وحيد من Pinia
  const selectedBranchId = computed({
    get: () => branchStore.selectedBranchId,
    set: (val) => branchStore.setSelectedBranch(val)
  });
  
  /**
   * الحصول على branch_id الإجباري
   * @returns {string|null} فرع المستخدم أو المختار
   * @throws {Error} إذا لم يكن هناك branch_id
   */
  const getRequiredBranchId = () => {
    if (!isExempt.value) {
      // المستخدم العادي - استخدم فرعه المعين
      const userbranchId = authStore?.user?.branch_id;
      if (!userbranchId) {
        throw new Error('لم يتم تعيين مخزن لحسابك. يرجى التواصل مع الإدارة.');
      }
      return String(userbranchId);
    } else {
      // المستخدم المعفى (Admin/Manager) - استخدم الفرع المختار
      if (!selectedBranchId.value) {
        throw new Error('يجب تحديد مخزن للمتابعة');
      }
      return String(selectedBranchId.value);
    }
  };
  
  /**
   * التحقق من صحة branch_id في query params
   * @param {Object} params - معاملات الطلب
   * @returns {Object} معاملات معدلة مع branch_id إجباري
   */
  const enforceBranchIdInParams = (params = {}) => {
    const branchId = getRequiredBranchId();
    return {
      ...params,
      branch_id: branchId
    };
  };
  
  /**
   * التحقق من أن بيانات معينة تنتمي للفرع الحالي
   * @param {Object} item - العنصر المراد التحقق منه
   * @param {string} branchId - branch_id في العنصر
   * @returns {boolean} هل البيانات للفرع الحالي
   */
  const isDataFromCurrentBranch = (item, branchId) => {
    if (!branchId) return false;
    try {
      const currentBranchId = getRequiredBranchId();
      return String(branchId) === String(currentBranchId);
    } catch {
      return false;
    }
  };
  
  /**
   * تصفية البيانات للفرع الحالي فقط
   * @param {Array} items - قائمة العناصر
   * @returns {Array} العناصر المصفاة للفرع الحالي
   */
  const filterByCurrentBranch = (items = []) => {
    try {
      const currentBranchId = getRequiredBranchId();
      return items.filter(item => String(item.branch_id) === String(currentBranchId));
    } catch {
      return [];
    }
  };
  
  /**
   * Computed للحصول على branch_id الآمن
   */
  const currentBranchId = computed(() => {
    try {
      return getRequiredBranchId();
    } catch {
      return null;
    }
  });
  
  /**
   * Computed للتحقق من وجود branch_id صالح
   */
  const hasValidBranchId = computed(() => {
    try {
      getRequiredBranchId();
      return true;
    } catch {
      return false;
    }
  });
  
  return {
    isExempt,
    selectedBranchId,
    getRequiredBranchId,
    enforceBranchIdInParams,
    isDataFromCurrentBranch,
    filterByCurrentBranch,
    currentBranchId,
    hasValidBranchId
  };
}
