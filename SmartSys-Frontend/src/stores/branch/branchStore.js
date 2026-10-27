import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import branchesService from '@/services/branches';

export const useBranchStore = defineStore('branch', () => {

  // ─── State ────────────────────────────────────────────────────────────────
  //
  // selectedBranchId semantics (IMPORTANT - read before modifying):
  //   null  → "All Branches" — no branch filter, admin sees all data
  //   <id>  → specific branch selected, data filtered to that branch
  //
  // localStorage 'selectedBranchId' values:
  //   'all'         → user explicitly chose all branches (null state)
  //   '<number>'    → specific branch ID
  //   absent/null   → first-time user, auto-assign default branch
  //
  // Rules:
  //   • Non-exempt users are ALWAYS locked to authStore.user.branch_id
  //   • Exempt (admin/manager) users can switch between specific or null
  //   • Pages that REQUIRE a branch (POS, ProductManagement) use setTemporaryBranch()
  //     so they don't override the global 'all' preference in localStorage
  const branches         = ref([]);
  const selectedBranchId = ref(null);
  const loading          = ref(false);
  const error            = ref(null);
  let initialized        = false;

  // ─── Cache Management ─────────────────────────────────────────────────────
  let cacheTimestamp     = null;
  let ongoingFetchPromise = null;
  const CACHE_TTL_MS     = 5 * 60 * 1000; // 5 minutes

  // ─── Helpers ──────────────────────────────────────────────────────────────
  const resolveDefaultBranchId = (list) => {
    if (!Array.isArray(list) || list.length === 0) return null;
    const active = list.find(b =>
      b?.active === 1    || b?.active === true ||
      b?.is_active === 1 || b?.is_active === true
    );
    return (active || list[0])?.id ?? null;
  };

  // ─── Computed ─────────────────────────────────────────────────────────────
  const selectedBranch = computed(() =>
    branches.value.find(b => String(b.id) === String(selectedBranchId.value))
  );

  // ─── fetchBranches ────────────────────────────────────────────────────────
  /**
   * Fetches all available branches with caching, deduplication, and force refresh.
   * 
   * Features:
   * - ✅ Cache: Reuses data within TTL (5 minutes)
   * - ✅ Deduplication: Concurrent calls share the same request
   * - ✅ Force refresh: Pass force=true to bypass cache
   * 
   * Usage:
   *   await branchStore.fetchBranches();        // uses cache if fresh
   *   await branchStore.fetchBranches(true);    // forces fresh data
   * 
   * @param {boolean} force - If true, bypasses cache and fetches fresh data
   * @returns {Promise<Array>} branches array
   */
  const fetchBranches = async (force = false) => {
    // ✅ Deduplication: If a fetch is already in progress, return that promise
    if (!force && ongoingFetchPromise) {
      return ongoingFetchPromise;
    }

    // ✅ Cache: If data is fresh and force is false, return cached data
    if (!force && branches.value.length > 0 && cacheTimestamp) {
      const cacheAge = Date.now() - cacheTimestamp;
      if (cacheAge < CACHE_TTL_MS) {
        return branches.value;
      }
    }

    // ✅ Start new fetch
    loading.value = true;
    error.value   = null;

    ongoingFetchPromise = (async () => {
      try {
        const response = await branchesService.list();
        const raw      = response.data?.data ?? response.data ?? [];
        branches.value = Array.isArray(raw) ? raw : (raw?.items || []);
        cacheTimestamp = Date.now(); // ✅ Update cache timestamp

        if (branches.value.length > 0) {
          const storedBranchId = localStorage.getItem('selectedBranchId');
          if (storedBranchId === 'all') {
            selectedBranchId.value = null; // احترم اختيار المستخدم "كل الفروع"
          } else {
            const savedBranch = storedBranchId
              ? branches.value.find(b => String(b.id) === storedBranchId)
              : null;
            const currentBranchExists = selectedBranchId.value
              && branches.value.some(b => String(b.id) === String(selectedBranchId.value));

            if (savedBranch) {
              selectedBranchId.value = savedBranch.id;
            } else if (!currentBranchExists || storedBranchId) {
              const defaultId = resolveDefaultBranchId(branches.value);
              if (defaultId !== null) {
                selectedBranchId.value = defaultId;
                localStorage.setItem('selectedBranchId', String(defaultId));
              }
            }
          }
        }

        return branches.value;
      } catch (err) {
        error.value    = err.response?.data?.message || err.message || 'Failed to fetch branches';
        console.error('fetchBranches failed:', err);
        branches.value = [];
        cacheTimestamp = null; // ✅ Invalidate cache on error
        return [];
      } finally {
        loading.value = false;
        ongoingFetchPromise = null; // ✅ Clear ongoing promise
      }
    })();

    return ongoingFetchPromise;
  };

  const fetchBranchTransfers = async (branchId) => {
    if (!branchId) throw new Error('Branch ID is required');
    const response = await branchesService.listBranchTransfers(branchId);
    const data = response?.data?.data ?? response?.data ?? [];
    return Array.isArray(data) ? data : (Array.isArray(data?.items) ? data.items : []);
  };

  const fetchStockTransfers = async (branchId) => {
    if (!branchId) throw new Error('Branch ID is required');
    const response = await branchesService.listStockTransfers(branchId);
    const data = response?.data?.data ?? response?.data ?? [];
    return Array.isArray(data) ? data : (Array.isArray(data?.items) ? data.items : []);
  };

  const fetchStockTransfer = async (transferId) => {
    if (!transferId) throw new Error('Transfer ID is required');
    const response = await branchesService.getStockTransfer(transferId);
    return response?.data?.data ?? response?.data ?? null;
  };

  /**
   * Alias for fetchBranches(true) - forces fresh data fetch
   * Usage: await branchStore.refreshBranches();
   */
  const refreshBranches = () => fetchBranches(true);

  // ─── setSelectedBranch ────────────────────────────────────────────────────
  /**
   * يختار فرع معين ويحفظه في localStorage
   * الـ component يستخدمها هكذا:
   *   branchStore.setSelectedBranch(branchId);
   */
  const setSelectedBranch = (branchId) => {
    if (branchId === null || branchId === undefined || branchId === '' || branchId === 'all') {
      selectedBranchId.value = null;
      localStorage.setItem('selectedBranchId', 'all');
      return true;
    }
    const exists = branches.value.find(b => String(b.id) === String(branchId));
    if (exists) {
      selectedBranchId.value = exists.id;
      localStorage.setItem('selectedBranchId', String(exists.id));
      return true;
    }
    return false;
  };

  // ─── loadFromStorage ──────────────────────────────────────────────────────
  /**
   * يحمّل الفرع المختار من localStorage عند بدء التطبيق
   */
  const loadFromStorage = () => {
    const stored = localStorage.getItem('selectedBranchId');
    if (stored === 'all') {
      selectedBranchId.value = null; // المستخدم اختار "كل الفروع" عمداً
    } else if (stored) {
      const parsed = parseInt(stored, 10);
      if (Number.isFinite(parsed)) {
        selectedBranchId.value = parsed;
      } else {
        selectedBranchId.value = null;
        localStorage.removeItem('selectedBranchId');
      }
    }
  };

  // ─── setTemporaryBranch ──────────────────────────────────────────────────
  /**
   * Sets a branch for the current session WITHOUT persisting to localStorage.
   * Use this in pages that REQUIRE a branch (POS, ProductManagement) to avoid
   * overriding the user's global 'all branches' preference.
   */
  const setTemporaryBranch = (branchId) => {
    const exists = branches.value.find(b => String(b.id) === String(branchId));
    if (exists) {
      selectedBranchId.value = exists.id;
      return true;
    }
    return false;
  };

  // ─── hasAccessToBranch ────────────────────────────────────────────────────
  const hasAccessToBranch = (branchId) =>
    branches.value.some(b => String(b.id) === String(branchId));

  // ─── createBranch ─────────────────────────────────────────────────────────
  /**
   * ينشئ فرع جديد ويضيفه للـ cache المحلي
   * الـ component يستخدمها هكذا:
   *   const res = await branchStore.createBranch(payload);
   *   if (res.status === 'success') { ... }
   */
  const createBranch = async (payload) => {
    try {
      const response = await branchesService.create(payload);
      const responseData = response?.data?.data || response?.data;

      // Merge payload (has name/location/etc.) with response data (has id/account_id)
      // Backend only returns { id, account_id, account_code } — not the full branch
      const branch = responseData?.id
        ? { ...payload, ...responseData, active: 1 }
        : responseData;

      if (branch) {
        branches.value.push(branch);
        cacheTimestamp = Date.now(); // ✅ Update cache timestamp after mutation
      }

      return {
        status: 'success',
        data:   branch,
        message: response?.data?.message || null
      };
    } catch (err) {
      console.error('createBranch failed:', err);
      return {
        status: 'error',
        data:   null,
        message: err.response?.data?.message || err.message
      };
    }
  };

  // ─── updateBranch ─────────────────────────────────────────────────────────
  /**
   * يعدّل فرع ويحدّث الـ cache المحلي
   * الـ component يستخدمها هكذا:
   *   const res = await branchStore.updateBranch(id, payload);
   *   if (res.status === 'success') { ... }
   */
  const updateBranch = async (id, payload) => {
    try {
      const response = await branchesService.update(id, payload);
      const updated  = response?.data?.data || response?.data;

      const index = branches.value.findIndex(b => String(b.id) === String(id));
      if (index >= 0) {
        branches.value[index] = { ...branches.value[index], ...updated };
        cacheTimestamp = Date.now(); // ✅ Update cache timestamp after mutation
      }

      return {
        status: 'success',
        data:   updated,
        message: response?.data?.message || null
      };
    } catch (err) {
      console.error('updateBranch failed:', err);
      return {
        status: 'error',
        data:   null,
        message: err.response?.data?.message || err.message
      };
    }
  };

  // ─── deleteBranch ─────────────────────────────────────────────────────────
  /**
   * يحذف فرع ويحدّث الـ cache المحلي + يعالج حالة الفرع المختار
   * الـ component يستخدمها هكذا:
   *   const res = await branchStore.deleteBranch(id);
   *   if (res.status === 'success') { ... }
   */
  const deleteBranch = async (id) => {
    try {
      await branchesService.delete(id);

      branches.value = branches.value.filter(b => String(b.id) !== String(id));
      cacheTimestamp = Date.now(); // ✅ Update cache timestamp after mutation

      // لو الفرع المحذوف هو المختار حالياً — انتقل للفرع الافتراضي
      if (String(selectedBranchId.value) === String(id)) {
        const fallback = resolveDefaultBranchId(branches.value);
        selectedBranchId.value = fallback;
        if (fallback !== null) localStorage.setItem('selectedBranchId', String(fallback));
        else                   localStorage.removeItem('selectedBranchId');
      }

      return {
        status: 'success',
        data:   { id },
        message: null
      };
    } catch (err) {
      console.error('deleteBranch failed:', err);
      return {
        status: 'error',
        data:   null,
        message: err.response?.data?.message || err.message
      };
    }
  };

  // ─── initialize ───────────────────────────────────────────────────────────
  /**
   * يُشغَّل مرة واحدة عند إنشاء الـ store
   */
  const initialize = async () => {
    if (initialized) return;
    initialized = true;
    loadFromStorage();
    if (branches.value.length === 0) await fetchBranches();
  };

  // ─── Return ───────────────────────────────────────────────────────────────
  return {
    // State
    branches,
    selectedBranchId,
    loading,
    error,

    // Computed
    selectedBranch,

    // Read Actions
    fetchBranches,
    fetchBranchTransfers,
    fetchStockTransfers,
    fetchStockTransfer,
    refreshBranches,  // ✅ Alias for fetchBranches(true)
    setSelectedBranch,
    setTemporaryBranch,
    loadFromStorage,
    hasAccessToBranch,
    initialize,

    // Write Actions
    createBranch,
    updateBranch,
    deleteBranch,

    // Helpers
    resolveDefaultBranchId,
  };
});