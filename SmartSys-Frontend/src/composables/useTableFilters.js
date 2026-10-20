import { ref, computed, watch, onMounted } from 'vue';
import { useAuthStore } from '@/stores/auth/authStore';
import { useBranchStore } from '@/stores/branch/branchStore';

/**
 * Composable موحد لإدارة الفلاتر في الجداول
 * 
 * يوفر:
 * - إدارة حالة الفلاتر والـ Pagination
 * - حفظ وتحميل من localStorage
 * - دوال مساعدة للمسح والتنقل
 * - توحيد فلتر الفرع (Branch Selection) عبر الصفحات
 * - ✨ FIX: مراقبة تلقائية لتغييرات perPage/dateFrom/dateTo/page تستدعي onFilterChange
 * - ✨ FIX: totalCount مُدارة داخل الكومبوزابل (بدل ما كل صفحة تعرّفها بنفسها)
 * 
 * يُستخدم في:
 * - PurchaseManagement
 * - CashVouchers
 * - ReturnsManagement
 */
export const useTableFilters = (storagePrefix = 'table_filters', options = {}) => {
  const authStore = useAuthStore();
  const branchStore = useBranchStore();

  // ─── isExempt: computed مباشرة من authStore بدلاً من useSessionExemption() instance منفصل
  // السبب: useSessionExemption() يُنشئ ref(false) جديد لكل caller، ويحتاج ensureLoaded() منفصلة.
  // الصفحة (CashVouchers, SessionsList, ...) تستدعي ensureLoaded() على نسختها الخاصة،
  // لكن useTableFilters كان يُنشئ نسخة ثالثة لم تُشغَّل ensureLoaded() عليها أبدًا → isExempt=false دائمًا.
  // الحل: isExempt هنا computed من authStore مباشرة — نفس المنطق، بدون async، بدون state منفصل.
  const isExempt = computed(() => {
    const role = String(authStore.user?.role || '').toLowerCase();
    return (
      authStore.isSuperAdmin ||
      authStore.isAdmin ||
      role === 'manager'
    );
  });

  // ─── States ────────────────────────────────────────────────────────────────
  const showFilters = ref(false);
  const searchQuery = ref('');
  const dateFrom = ref('');
  const dateTo = ref('');
  const statusFilter = ref('');
  const perPage = ref(options.initialPageSize ?? 20);
  const page = ref(1);
  const sortKey = ref('created_at');
  const sortAsc = ref(false); // ✅ DESC by default (newest first)
  const selectedIds = ref([]);

  // ✨ NEW: totalCount مُدارة مركزيًا هنا بدل ما كل صفحة تعرّف ref خاص بيها
  // الصفحة لسه بتقدر تكتب فيها مباشرة بعد كل استجابة API: totalCount.value = response.total
  const totalCount = ref(0);
  const totalPages = computed(() => Math.max(1, Math.ceil((totalCount.value || 0) / perPage.value)));

  // ─── Unified Branch Selection (مرة واحدة فقط) ─────────────────────────────
  // ✅ SSOT FIX: selectedBranch is now a computed proxy to branchStore.selectedBranchId
  // This ensures all components using useTableFilters read from the single source of truth
  const selectedBranch = computed({
    get: () => branchStore.selectedBranchId,
    set: (val) => branchStore.setSelectedBranch(val)
  });
  
  /**
   * Computed branchId based on isExempt
   * If isExempt: use selectedBranch or null (allows filtering by branch)
   * If not exempt: use authStore.user.branch_id (fixed to user's branch)
   * 
   * يحل مكان الكود المكرر في 3 صفحات:
   * ```
   * const branchId = !isExempt.value
   *   ? (authStore?.user?.branch_id || branchStore.selectedBranchId)
   *   : (selectedBranch.value || null);
   * ```
   */
  const branchId = computed(() => {
    if (!isExempt.value) {
      return authStore.user?.branch_id || null;
    }
    return selectedBranch.value || null;
  });

  // Refs للـ date pickers
  const dateFromRef = ref(null);
  const dateToRef = ref(null);

  // ─── Handle Branch Change ─────────────────────────────────────────────────
  /**
   * عند تغيير الفرع: reset pagination وأطلب تحديث البيانات
   */
  const handleBranchChange = () => {
    page.value = 1;
    // ✅ لا نستدعي onFilterChange هنا — تغيير page سيشغّل watch(page) تلقائياً
  };

  // ✨ FIX: مراقبة تلقائية لتغييرات الفلاتر الأساسية
  // في السابق كانت كل صفحة تعتمد على @change يدوي أو watch منفصل بأسلوب مختلف،
  // مما أدى لبعض الصفحات (مثل PurchasesManagement) لا تُحدّث الجدول عند تغيير
  // التاريخ أو عدد النتائج في الصفحة. دلوقتي موحّدة هنا مركزيًا.
  watch([perPage, dateFrom, dateTo], () => {
    page.value = 1;
    // ✅ لا نستدعي onFilterChange هنا — تغيير page سيشغّل watch(page) أدناه
    // مما يُجنّب double-call عندما يكون page > 1 قبل التغيير
  });

  // تغيير رقم الصفحة نفسه (من أزرار Next/Previous أو أي مصدر آخر) يستدعي إعادة التحميل
  // هو المسؤول الوحيد عن استدعاء onFilterChange — نقطة واحدة للخروج
  watch(page, () => {
    if (options.onFilterChange) {
      options.onFilterChange();
    }
  });

  // ─── Load من localStorage ──────────────────────────────────────────────────
  const loadFromLocalStorage = () => {
    try {
      const stored = localStorage.getItem(storagePrefix);
      if (!stored) return;

      const data = JSON.parse(stored);
      if (data.searchQuery) searchQuery.value = data.searchQuery;
      if (data.dateFrom) dateFrom.value = data.dateFrom;
      if (data.dateTo) dateTo.value = data.dateTo;
      if (data.statusFilter) statusFilter.value = data.statusFilter;
      if (data.perPage) perPage.value = data.perPage;
      if (data.page) page.value = data.page;
      if (data.sortKey) sortKey.value = data.sortKey;
      if (data.sortAsc !== undefined) sortAsc.value = data.sortAsc;
    } catch (e) {
      console.warn(`[useTableFilters] Failed to load from localStorage: ${e.message}`);
    }
  };

  // ─── Save إلى localStorage ────────────────────────────────────────────────
  const saveToLocalStorage = () => {
    try {
      const data = {
        searchQuery: searchQuery.value,
        dateFrom: dateFrom.value,
        dateTo: dateTo.value,
        statusFilter: statusFilter.value,
        perPage: perPage.value,
        page: page.value,
        sortKey: sortKey.value,
        sortAsc: sortAsc.value,
      };
      localStorage.setItem(storagePrefix, JSON.stringify(data));
    } catch (e) {
      console.warn(`[useTableFilters] Failed to save to localStorage: ${e.message}`);
    }
  };

  // ─── Helpers ──────────────────────────────────────────────────────────────
  const hasActiveFilters = computed(() => {
    return !!(
      searchQuery.value ||
      dateFrom.value ||
      dateTo.value ||
      statusFilter.value
    );
  });

  const getActiveFiltersArray = () => {
    const filters = [];
    if (searchQuery.value) filters.push({ key: 'search', value: searchQuery.value, label: `بحث: ${searchQuery.value}` });
    if (dateFrom.value) filters.push({ key: 'dateFrom', value: dateFrom.value, label: `من: ${dateFrom.value}` });
    if (dateTo.value) filters.push({ key: 'dateTo', value: dateTo.value, label: `إلى: ${dateTo.value}` });
    if (statusFilter.value) filters.push({ key: 'status', value: statusFilter.value, label: `الحالة: ${statusFilter.value}` });
    return filters;
  };

  // ─── Clear Functions ──────────────────────────────────────────────────────
  const clearSearch = () => {
    searchQuery.value = '';
  };

  const clearDateRange = () => {
    dateFrom.value = '';
    dateTo.value = '';
  };

  const clearAllFilters = () => {
    searchQuery.value = '';
    dateFrom.value = '';
    dateTo.value = '';
    statusFilter.value = '';
    page.value = 1;
    saveToLocalStorage();
  };

  // ─── Sort Functions ────────────────────────────────────────────────────────
  const toggleSort = (key) => {
    if (sortKey.value === key) {
      sortAsc.value = !sortAsc.value;
    } else {
      sortKey.value = key;
      sortAsc.value = true;
    }
    page.value = 1;
    saveToLocalStorage();
  };

  // ─── Pagination Functions ─────────────────────────────────────────────────
  // ملاحظة: هذه الدوال فقط تغيّر page.value — الـ watch أعلاه هو المسؤول
  // عن استدعاء onFilterChange تلقائيًا، فلا حاجة للصفحة لاستدعاء fetch يدويًا بعدها.
  const goToPage = (newPage) => {
    page.value = Math.max(1, newPage);
  };

  const nextPage = (maxPages) => {
    const limit = maxPages ?? totalPages.value;
    page.value = Math.min(limit, page.value + 1);
  };

  const previousPage = () => {
    page.value = Math.max(1, page.value - 1);
  };

  // ─── Selection Functions ──────────────────────────────────────────────────
  const toggleSelectAll = (items) => {
    if (selectedIds.value.length === items.length) {
      selectedIds.value = [];
    } else {
      selectedIds.value = items.map(item => item.id);
    }
  };

  const clearSelection = () => {
    selectedIds.value = [];
  };

  const isSelected = (itemId) => {
    return selectedIds.value.includes(itemId);
  };

  // ─── Date Picker Helpers ──────────────────────────────────────────────────
  const showDateFromPicker = () => {
    if (dateFromRef.value?.showPicker) {
      dateFromRef.value.showPicker();
    }
  };

  const showDateToPicker = () => {
    if (dateToRef.value?.showPicker) {
      dateToRef.value.showPicker();
    }
  };

  // ─── Reset to Defaults ────────────────────────────────────────────────────
  const resetFilters = () => {
    searchQuery.value = '';
    dateFrom.value = '';
    dateTo.value = '';
    statusFilter.value = '';
    page.value = 1;
    perPage.value = 20;
    sortKey.value = 'created_at';
    sortAsc.value = false; // ✅ DESC by default (newest first)
    selectedIds.value = [];
    saveToLocalStorage();
  };

  return {
    // States
    showFilters,
    searchQuery,
    dateFrom,
    dateTo,
    statusFilter,
    perPage,
    page,
    sortKey,
    sortAsc,
    selectedIds,
    dateFromRef,
    dateToRef,
    selectedBranch,  // ✨ Unified branch selection
    branchId,        // ✨ Computed branch ID based on isExempt
    isExempt,        // ✨ Whether user is exempt
    totalCount,      // ✨ NEW: مُدارة مركزيًا — الصفحة تكتب فيها بعد كل استجابة API
    totalPages,      // ✨ NEW: computed تلقائي من totalCount/perPage

    // Computed
    hasActiveFilters,

    // Methods
    loadFromLocalStorage,
    saveToLocalStorage,
    getActiveFiltersArray,
    clearSearch,
    clearDateRange,
    clearAllFilters,
    toggleSort,
    goToPage,
    nextPage,
    previousPage,
    toggleSelectAll,
    clearSelection,
    isSelected,
    showDateFromPicker,
    showDateToPicker,
    resetFilters,
    handleBranchChange,  // ✨ Handle branch filter change
  };
};