import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import returnsService from '@/services/returns';
import { useStatementStore } from '@/stores/statement/statementStore';
import { useProductStore } from '@/stores/product/productStore';

const nowMs = () => Date.now();

export const useReturnStore = defineStore('return', () => {

  // ─── Cache TTL ────────────────────────────────────────────────────────────
  const TTL = {
    returnsList:   3 * 60 * 1000, // 3 دقائق (يتغير كثيراً)
    returnDetails: 5 * 60 * 1000, // 5 دقائق
    searchReturns: 2 * 60 * 1000, // دقيقتان
  };

  // ─── State ────────────────────────────────────────────────────────────────
  const returnsList          = ref({});
  const returnsListFetchedAt = ref({});
  const returnsListInFlight  = ref(null);

  const detailsCache          = ref({});
  const detailsCacheFetchedAt = ref({});

  const searchCache          = ref({});
  const searchCacheFetchedAt = ref({});

  // ─── Helpers ──────────────────────────────────────────────────────────────
  const isFresh = (fetchedAt, ttl) =>
    !!fetchedAt && (nowMs() - fetchedAt) < ttl;

  // ─── fetchReturnsList ─────────────────────────────────────────────────────
  /**
   * يجيب قائمة المرتجعات مع فلاتر وpagination
   * الـ component يستخدمها هكذا:
   *   const res = await returnStore.fetchReturnsList({ type: 'sales', branchId });
   *   if (res.status === 'success') { items.value = res.data.items; }
   */
  const fetchReturnsList = async ({
    type,
    branchId = null,
    page     = 1,
    perPage  = 50,
    status   = null,
    partyId  = null,
    dateFrom = null,
    dateTo   = null,
    search   = null,
    force    = false,
    signal   = null,
  } = {}) => {
    try {
      if (!type) {
        return {
          status: 'error',
          data:   { items: [], total: 0, page: 1, perPage },
          message: 'Return type is required (sales/purchase)'
        };
      }

      const cacheKey = [
        type, branchId || 'all', page, perPage,
        status || 'all', partyId || 'all', search || 'all',
        dateFrom || 'all', dateTo || 'all',
      ].join('_');

      const cached   = returnsList.value[cacheKey];
      const cachedAt = returnsListFetchedAt.value[cacheKey];

      if (!force && cached && isFresh(cachedAt, TTL.returnsList)) {
        return {
          status: 'success',
          data:   cached,
          message: null
        };
      }

      if (!force && returnsListInFlight.value?.[cacheKey]) {
        const result = await returnsListInFlight.value[cacheKey];
        return {
          status: 'success',
          data:   result,
          message: null
        };
      }

      const promise = (async () => {
        try {
          const params   = { page: String(page), per_page: String(perPage) };

          if (branchId) params.branch_id = String(branchId);
          if (status)   params.status    = status;
          if (partyId) {
            params[type === 'sales' ? 'customer_id' : 'supplier_id'] = String(partyId);
          }
          if (dateFrom) params.start_date = dateFrom;
          if (dateTo)   params.end_date   = dateTo;
          if (search)   params.search     = search;

          const response = await returnsService.list(type, params, signal);
          const raw      = response?.data?.data ?? response?.data ?? {};
          const list     = Array.isArray(raw) ? raw : (raw.items || []);

          const structuredResponse = {
            items:   list,
            total:   parseInt(raw.total ?? raw.count ?? list.length) || 0,
            page:    parseInt(page)    || 1,
            perPage: parseInt(perPage) || 50,
          };

          returnsList.value[cacheKey]          = structuredResponse;
          returnsListFetchedAt.value[cacheKey] = nowMs();
          return structuredResponse;
        } finally {
          if (returnsListInFlight.value?.[cacheKey])
            delete returnsListInFlight.value[cacheKey];
        }
      })();

      if (!returnsListInFlight.value) returnsListInFlight.value = {};
      returnsListInFlight.value[cacheKey] = promise;
      const result = await promise;

      return {
        status: 'success',
        data:   result,
        message: null
      };
    } catch (err) {
      console.error('fetchReturnsList failed:', err);
      return {
        status: 'error',
        data:   { items: [], total: 0, page: 1, perPage },
        message: err.response?.data?.message || err.message
      };
    }
  };

  // ─── fetchReturnDetails ───────────────────────────────────────────────────
  /**
   * يجيب تفاصيل مرتجع معين
   * الـ component يستخدمها هكذا:
   *   const res = await returnStore.fetchReturnDetails(returnId, 'sales');
   *   if (res.status === 'success') { detail.value = res.data; }
   */
  const fetchReturnDetails = async (returnId, type, { force = false, signal = null } = {}) => {
    try {
      if (!returnId || !type) {
        return {
          status: 'error',
          data:   null,
          message: 'Return ID and type are required'
        };
      }

      const cacheKey = `${type}_${returnId}`;
      const cached   = detailsCache.value[cacheKey];
      const cachedAt = detailsCacheFetchedAt.value[cacheKey];

      if (!force && cached && isFresh(cachedAt, TTL.returnDetails)) {
        return {
          status: 'success',
          data:   cached,
          message: null
        };
      }

      const response = await returnsService.get(type, returnId, signal);
      const data     = response?.data?.data || response?.data;

      detailsCache.value[cacheKey]          = data;
      detailsCacheFetchedAt.value[cacheKey] = nowMs();

      return {
        status: 'success',
        data,
        message: null
      };
    } catch (err) {
      console.error('fetchReturnDetails failed:', err);
      return {
        status: 'error',
        data:   null,
        message: err.response?.data?.message || err.message
      };
    }
  };

  // ─── searchReturns ────────────────────────────────────────────────────────
  /**
   * يبحث في المرتجعات
   * الـ component يستخدمها هكذا:
   *   const res = await returnStore.searchReturns({ type: 'sales', query });
   *   if (res.status === 'success') { results.value = res.data; }
   */
  const searchReturns = async ({
    type,
    query,
    branchId = null,
    limit    = 50,
    force    = false,
    signal   = null,
  } = {}) => {
    try {
      if (!type || !query || query.trim().length < 2) {
        return {
          status: 'success',
          data:   [],
          message: null
        };
      }

      const q        = query.trim();
      const cacheKey = `${type}_${q}_${branchId || 'all'}_${limit}`;
      const cached   = searchCache.value[cacheKey];
      const cachedAt = searchCacheFetchedAt.value[cacheKey];

      if (!force && cached && isFresh(cachedAt, TTL.searchReturns)) {
        return {
          status: 'success',
          data:   cached,
          message: null
        };
      }

      const params   = { search: q, limit: String(limit) };
      if (branchId) params.branch_id = String(branchId);

      const response = await returnsService.search(type, params, signal);
      const data     = response?.data?.data || response?.data || [];
      const list     = Array.isArray(data) ? data : (data?.items || []);

      searchCache.value[cacheKey]          = list;
      searchCacheFetchedAt.value[cacheKey] = nowMs();

      return {
        status: 'success',
        data:   list,
        message: null
      };
    } catch (err) {
      console.error('searchReturns failed:', err);
      return {
        status: 'error',
        data:   [],
        message: err.response?.data?.message || err.message
      };
    }
  };

  // ─── createReturn ──────────────────────────────────────────────────────────
  /**
   * يسجل مرتجع جديد ويمسح الـ cache المرتبط
   * @param {Object} payload
   * @returns {{ status: 'success'|'error', data: Object, message: string|null }}
   */
  const createReturn = async (payload) => {
    try {
      const response = await returnsService.create(payload);

      // ✅ تحديث تلقائي للكميات — امسح cache المنتجات في المستودع المتأثر
      const productStore = useProductStore();
      if (payload?.branch_id) {
        productStore.invalidateCacheForBranch(payload.branch_id);
      }

      clearReturnsListCache();
      clearSearchCache();
      const statementStore = useStatementStore();
      if (payload?.return_type === 'sale') statementStore.clearCustomerCache();
      else if (payload?.return_type === 'purchase') statementStore.clearSupplierCache();

      return {
        status: 'success',
        data:   response?.data?.data || response?.data || {},
        message: null
      };
    } catch (err) {
      console.error('createReturn failed:', err);
      return {
        status: 'error',
        data:   null,
        message: err.response?.data?.message || err.message
      };
    }
  };

  // ─── Computed Getters ─────────────────────────────────────────────────────
  const getReturnById = (returnId, type) =>
    computed(() => detailsCache.value[`${type}_${returnId}`]);

  // ─── Cache Clearers ───────────────────────────────────────────────────────
  const clear = () => {
    returnsList.value          = {};
    returnsListFetchedAt.value = {};
    returnsListInFlight.value  = null;
    detailsCache.value          = {};
    detailsCacheFetchedAt.value = {};
    searchCache.value          = {};
    searchCacheFetchedAt.value = {};
  };

  const clearReturnsListCache = () => {
    returnsList.value          = {};
    returnsListFetchedAt.value = {};
    returnsListInFlight.value  = null;
  };

  const clearDetailsCache = () => {
    detailsCache.value          = {};
    detailsCacheFetchedAt.value = {};
  };

  const clearSearchCache = () => {
    searchCache.value          = {};
    searchCacheFetchedAt.value = {};
  };

  // ─── Return ───────────────────────────────────────────────────────────────
  return {
    // State
    returnsList,

    // Computed Getters
    getReturnById,

    // Read Actions
    fetchReturnsList,
    fetchReturnDetails,
    searchReturns,

    // Write Actions
    createReturn,

    // Cache
    clear,
    clearReturnsListCache,
    clearDetailsCache,
    clearSearchCache,
  };
});