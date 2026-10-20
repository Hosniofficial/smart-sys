import { ref, computed, watch } from 'vue';
import { useTableFilters } from './useTableFilters';
import { useBranchStore } from '@/stores/branch/branchStore';

/**
 * Composable موحد لإدارة الفلاتر في سجلات المبيعات والمشتريات والمرتجعات
 * يرث من useTableFilters ويضيف:
 * - فلاتر خاصة بالعملاء والمخازن
 * - دعم Quick Range Filters (هذا الشهر، هذا العام، إلخ)
 * - دعم الـ Dropdown للعملاء
 */
export const useHistoryFilters = (
  storagePrefix = 'history_filters',
  options = {}
) => {
  // ─── Get Base Filters from useTableFilters ────────────────────────────────
  const baseFilters = useTableFilters(storagePrefix);
  const branchStore = useBranchStore();

  // ─── Extended States ──────────────────────────────────────────────────────
  const customerFilter = ref('');
  const customerSearch = ref('');
  const showCustomerDropdown = ref(false);
  
  // ✅ SSOT FIX: selectedBranch is now a computed proxy to branchStore.selectedBranchId
  // This overrides baseFilters.selectedBranch and ensures SSOT compliance
  const selectedBranch = computed({
    get: () => branchStore.selectedBranchId,
    set: (val) => branchStore.setSelectedBranch(val)
  });
  
  const showBranchDropdown = ref(false);
  const total = baseFilters.totalCount;
  const kpiSum = ref(0);
  const kpiTax = ref(null);
  const kpiDiscount = ref(null);

  // ─── Load Extended من localStorage ────────────────────────────────────────
  const loadFromLocalStorage = () => {
    baseFilters.loadFromLocalStorage();
    try {
      // أولاً: حاول قراءة المفتاح الجديد الموحد
      const stored = localStorage.getItem(storagePrefix);
      if (stored) {
        const data = JSON.parse(stored);
        if (data.customerFilter) customerFilter.value = data.customerFilter;
        if (data.customerSearch) customerSearch.value = data.customerSearch;
        // ✅ عدم استرجاع selectedBranch من localStorage — يجب تحديده ديناميكيًا
        // حسب صلاحية المستخدم في fetchReturns/fetchSales
      }

      // ثانياً: كـ fallback، اقرأ المفاتيح القديمة (للعملاء القدامى)
      // هذا يضمن عدم فقدان البيانات عند التحديث
      const legacyCustomer = localStorage.getItem(storagePrefix.replace('_filters', '') + '_customer');
      
      if (legacyCustomer && !customerFilter.value) {
        customerFilter.value = legacyCustomer;
      }
      // ✅ عدم استرجاع legacyBranch — الفرع ليس تفضيل شخصي للمستخدم
    } catch (e) {
      console.warn(`[useHistoryFilters] Failed to load extended filters: ${e.message}`);
    }
  };

  // ─── Save Extended إلى localStorage ───────────────────────────────────────
  const saveToLocalStorage = () => {
    baseFilters.saveToLocalStorage();
    try {
      const stored = localStorage.getItem(storagePrefix) || '{}';
      const data = JSON.parse(stored);
      data.customerFilter = customerFilter.value;
      data.customerSearch = customerSearch.value;
      // ✅ عدم حفظ selectedBranch — الفرع يجب تحديده ديناميكيًا حسب صلاحية المستخدم
      localStorage.setItem(storagePrefix, JSON.stringify(data));
    } catch (e) {
      console.warn(`[useHistoryFilters] Failed to save extended filters: ${e.message}`);
    }
  };

  // ─── Customer Dropdown Helpers ─────────────────────────────────────────────
  const setCustomerFilter = (customerId, customerName = '') => {
    customerFilter.value = String(customerId);
    customerSearch.value = customerName;
    showCustomerDropdown.value = false;
    baseFilters.page.value = 1;
    saveToLocalStorage();
  };

  const clearCustomerFilter = () => {
    customerFilter.value = '';
    customerSearch.value = '';
    showCustomerDropdown.value = false;
    baseFilters.page.value = 1;
    saveToLocalStorage();
  };

  const hideCustomerDropdown = () => {
    setTimeout(() => {
      showCustomerDropdown.value = false;
    }, 150);
  };

  // ─── Branch/Warehouse Helpers ─────────────────────────────────────────────
  const setBranchFilter = (branchId) => {
    // ✅ SSOT: Now writes directly to branchStore via computed setter
    selectedBranch.value = branchId ? String(branchId) : null;
    baseFilters.page.value = 1;
    saveToLocalStorage();
  };

  const clearBranchFilter = () => {
    // ✅ SSOT: Now writes directly to branchStore via computed setter
    selectedBranch.value = null;
    baseFilters.page.value = 1;
    saveToLocalStorage();
  };

  // ─── Quick Range Filters ──────────────────────────────────────────────────
  const setQuickRange = (rangeType) => {
    const today = new Date();
    today.setHours(0, 0, 0, 0);

    const startDate = new Date(today);
    const endDate = new Date(today);

    switch (rangeType) {
      case 'today': {
        // اليوم
        break;
      }
      case 'yesterday': {
        startDate.setDate(startDate.getDate() - 1);
        endDate.setDate(endDate.getDate() - 1);
        break;
      }
      case 'thisWeek': {
        // بداية هذا الأسبوع (الأحد)
        const day = startDate.getDay();
        const diff = startDate.getDate() - day;
        startDate.setDate(diff);
        break;
      }
      case 'lastWeek': {
        // الأسبوع الماضي
        const day = startDate.getDay();
        const diff = startDate.getDate() - day - 7;
        startDate.setDate(diff);
        endDate.setDate(startDate.getDate() + 6);
        break;
      }
      case 'thisMonth': {
        // هذا الشهر من اليوم إلى آخره
        startDate.setDate(1);
        break;
      }
      case 'lastMonth': {
        // الشهر الماضي
        startDate.setMonth(startDate.getMonth() - 1);
        startDate.setDate(1);
        endDate.setMonth(endDate.getMonth() - 1);
        endDate.setDate(new Date(endDate.getFullYear(), endDate.getMonth() + 1, 0).getDate());
        break;
      }
      case 'thisYear': {
        // هذا العام من بدايته
        startDate.setMonth(0);
        startDate.setDate(1);
        break;
      }
      case 'allTime': {
        // كل التاريخ
        startDate.setFullYear(2020);
        startDate.setMonth(0);
        startDate.setDate(1);
        break;
      }
    }

    baseFilters.dateFrom.value = startDate.toISOString().split('T')[0];
    baseFilters.dateTo.value = endDate.toISOString().split('T')[0];
    baseFilters.page.value = 1;
    saveToLocalStorage();
  };

  // ─── Get Active Filters (Extended) ────────────────────────────────────────
  const getActiveFiltersArray = () => {
    const filters = baseFilters.getActiveFiltersArray();
    if (customerFilter.value) {
      filters.push({
        key: 'customer',
        value: customerFilter.value,
        label: `العميل: ${customerSearch.value}`,
      });
    }
    if (selectedBranch.value) {
      filters.push({
        key: 'branch',
        value: selectedBranch.value,
        label: `المخزن: ${selectedBranch.value}`,
      });
    }
    return filters;
  };

  const hasActiveFilters = computed(() => {
    return (
      baseFilters.hasActiveFilters.value ||
      !!customerFilter.value ||
      !!selectedBranch.value
    );
  });

  // ─── Clear All Filters ────────────────────────────────────────────────────
  const clearAllFilters = () => {
    baseFilters.clearAllFilters();
    customerFilter.value = '';
    customerSearch.value = '';
    selectedBranch.value = '';
    saveToLocalStorage();
  };

  // ─── Reset to Defaults ────────────────────────────────────────────────────
  const resetFilters = () => {
    baseFilters.resetFilters();
    customerFilter.value = '';
    customerSearch.value = '';
    selectedBranch.value = '';
    saveToLocalStorage();
  };

  // ─── Remove Single Filter ─────────────────────────────────────────────────
  const removeFilter = (key) => {
    switch (key) {
      case 'search':
        baseFilters.searchQuery.value = '';
        break;
      case 'dateFrom':
        baseFilters.dateFrom.value = '';
        break;
      case 'dateTo':
        baseFilters.dateTo.value = '';
        break;
      case 'status':
        baseFilters.statusFilter.value = '';
        break;
      case 'customer':
        clearCustomerFilter();
        break;
      case 'branch':
        clearBranchFilter();
        break;
    }
    baseFilters.page.value = 1;
    saveToLocalStorage();
  };

  // ─── Export Filter Config for API ──────────────────────────────────────────
  const getApiParams = (additionalParams = {}) => {
    return {
      page: baseFilters.page.value,
      perPage: baseFilters.perPage.value,
      status: baseFilters.statusFilter.value,
      customerId: customerFilter.value || null,
      branchId: selectedBranch.value || null,  // 🔧 Fixed: return null instead of empty string
      dateFrom: baseFilters.dateFrom.value,
      dateTo: baseFilters.dateTo.value,
      search: baseFilters.searchQuery.value?.trim(),
      sort: baseFilters.sortKey.value,
      order: baseFilters.sortAsc.value ? 'asc' : 'desc',
      ...additionalParams,
    };
  };

  return {
    // ─── Base Filter States أولاً (القاعدة)
    ...baseFilters,

    // ─── Extended States (تكتب بعد ...baseFilters لتفوز عند التصادم)
    customerFilter,
    customerSearch,
    showCustomerDropdown,
    selectedBranch,      // ✅ يدهس baseFilters.selectedBranch — هذا هو المقصود
    showBranchDropdown,
    total,
    kpiSum,
    kpiTax,
    kpiDiscount,

    // ─── Computed Extended (تفوز على نظيراتها في baseFilters)
    hasActiveFilters,    // ✅ يدهس baseFilters.hasActiveFilters — هذا هو المقصود

    // ─── Extended Methods (تفوز على نظيراتها في baseFilters)
    loadFromLocalStorage,
    saveToLocalStorage,
    setCustomerFilter,
    clearCustomerFilter,
    hideCustomerDropdown,
    setBranchFilter,
    clearBranchFilter,
    setQuickRange,
    getActiveFiltersArray,
    clearAllFilters,
    resetFilters,
    removeFilter,
    getApiParams,
  };
};
