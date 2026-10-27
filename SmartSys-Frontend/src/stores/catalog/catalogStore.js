import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import catalogService from '@/services/catalog';
import { useProductStore } from '@/stores/product/productStore';

const nowMs = () => Date.now();

export const useCatalogStore = defineStore('catalog', () => {
  // Cache TTLs
  const TTL = {
    categories: 5 * 60 * 1000,      // 5 minutes
    units: 10 * 60 * 1000,          // 10 minutes (rarely changes)
  };

  // State
  const categoriesByBranch = ref({});
  const categoriesFetchedAtByBranch = ref({});
  const categoriesInFlightByBranch = ref({});

  const units = ref([]);
  const unitsFetchedAt = ref(0);
  const unitsInFlight = ref(null);

  // Helpers
  const isFresh = (fetchedAt, ttl) => !!fetchedAt && (nowMs() - fetchedAt) < ttl;

  const normalizeArray = (res) => {
    const root = res?.data || res || {};
    const data = root?.data ?? root;
    if (Array.isArray(data)) return data;
    if (Array.isArray(data?.items)) return data.items;
    return [];
  };

  // Categories (branch-specific)
  const fetchCategories = async (branchId, { force = false } = {}) => {
    const bid = branchId == null || branchId === '' ? null : String(branchId);
    if (!bid) return [];

    const fetchedAt = categoriesFetchedAtByBranch.value[bid] || 0;
    if (!force && isFresh(fetchedAt, TTL.categories) && Array.isArray(categoriesByBranch.value[bid])) {
      return categoriesByBranch.value[bid];
    }

    const inFlight = categoriesInFlightByBranch.value[bid];
    if (!force && inFlight) return await inFlight;

    const promise = (async () => {
      try {
        const res = await catalogService.getCategories(bid);
        const list = normalizeArray(res);
        categoriesByBranch.value = { ...categoriesByBranch.value, [bid]: list };
        categoriesFetchedAtByBranch.value = { ...categoriesFetchedAtByBranch.value, [bid]: nowMs() };
        return list;
      } catch (error) {
        throw error;
      } finally {
        const next = { ...categoriesInFlightByBranch.value };
        delete next[bid];
        categoriesInFlightByBranch.value = next;
      }
    })();

    categoriesInFlightByBranch.value = { ...categoriesInFlightByBranch.value, [bid]: promise };
    return await promise;
  };

  const createCategory = async (payload) => {
    const response = await catalogService.createCategory(payload);
    if (payload?.branch_id) invalidateCategoriesForBranch(payload.branch_id);
    return response;
  };

  const updateCategory = async (categoryId, payload) => {
    if (!categoryId) throw new Error('Category ID is required');
    const response = await catalogService.updateCategory(categoryId, payload);
    if (payload?.branch_id) invalidateCategoriesForBranch(payload.branch_id);
    return response;
  };

  const deleteCategory = async (categoryId, branchId) => {
    if (!categoryId) throw new Error('Category ID is required');
    const response = await catalogService.deleteCategory(categoryId, branchId);
    if (branchId) invalidateCategoriesForBranch(branchId);
    return response;
  };

  // Units (global)
  const fetchUnits = async ({ force = false } = {}) => {
    if (!force && isFresh(unitsFetchedAt.value, TTL.units) && Array.isArray(units.value) && units.value.length) {
      return units.value;
    }

    if (!force && unitsInFlight.value) return await unitsInFlight.value;

    const promise = (async () => {
      try {
        const res = await catalogService.getUnits();
        const list = normalizeArray(res);
        units.value = Array.isArray(list) ? list : [];
        unitsFetchedAt.value = nowMs();
        return units.value;
      } finally {
        unitsInFlight.value = null;
      }
    })();

    unitsInFlight.value = promise;
    return await promise;
  };

  const fetchProducts = async ({ branchId = null } = {}) => {
    try {
      const productStore = useProductStore();
      const res = await productStore.fetchProducts(branchId ? { branchId } : {});
      const list = Array.isArray(res?.data) ? res.data : (res?.data?.items || []);
      return { status: 'success', data: list, message: null };
    } catch (e) {
      return { status: 'error', data: [], message: e?.message || 'فشل تحميل المنتجات' };
    }
  };

  // Computed helpers
  const getCategoriesForBranch = (branchId) => {
    const bid = branchId == null || branchId === '' ? null : String(branchId);
    if (!bid) return [];
    const categories = categoriesByBranch.value[bid];
    return Array.isArray(categories) ? categories : [];
  };

  const invalidateCategoriesForBranch = (branchId) => {
    const bid = branchId == null || branchId === '' ? null : String(branchId);
    if (!bid) return;
    const categories = { ...categoriesByBranch.value };
    const fetchedAt = { ...categoriesFetchedAtByBranch.value };
    const inFlight = { ...categoriesInFlightByBranch.value };
    delete categories[bid];
    delete fetchedAt[bid];
    delete inFlight[bid];
    categoriesByBranch.value = categories;
    categoriesFetchedAtByBranch.value = fetchedAt;
    categoriesInFlightByBranch.value = inFlight;
  };

  // Clear cache
  const clear = () => {
    categoriesByBranch.value = {};
    categoriesFetchedAtByBranch.value = {};
    categoriesInFlightByBranch.value = {};

    units.value = [];
    unitsFetchedAt.value = 0;
    unitsInFlight.value = null;

  };

  return {
    // State
    categoriesByBranch,
    units,

    // Actions
    fetchCategories,
    createCategory,
    updateCategory,
    deleteCategory,
    fetchProducts,
    fetchUnits,
    invalidateCategoriesForBranch,
    clear,

    // Computed helpers
    getCategoriesForBranch,
  };
});
