import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import purchasesService from '@/services/purchases';
import { useStatementStore } from '@/stores/statement/statementStore';
import { useProductStore } from '@/stores/product/productStore';

const nowMs = () => Date.now();

export const usePurchaseStore = defineStore('purchase', () => {

  // ─── Cache TTL ────────────────────────────────────────────────────────────
  const TTL = {
    purchasesList:   3 * 60 * 1000, // 3 دقائق
    purchaseDetails: 5 * 60 * 1000, // 5 دقائق
  };

  // ─── State ────────────────────────────────────────────────────────────────
  const purchasesList          = ref({});
  const purchasesListFetchedAt = ref({});
  const purchasesListInFlight  = ref({});

  const detailsCache          = ref({});
  const detailsCacheFetchedAt = ref({});

  const searchCache          = ref({});
  const searchCacheFetchedAt = ref({});

  // ─── Helpers ──────────────────────────────────────────────────────────────
  const isFresh = (fetchedAt, ttl) =>
    !!fetchedAt && (nowMs() - fetchedAt) < ttl;

  const pick = (candidates) =>
    candidates
      .map(v => (v == null ? null : parseFloat(v)))
      .find(v => typeof v === 'number' && !isNaN(v)) ?? null;

  // ─── fetchPurchasesList ───────────────────────────────────────────────────
  const fetchPurchasesList = async ({
    branchId   = null,
    page       = 1,
    perPage    = 50,
    status     = null,
    supplierId = null,
    search     = null,
    dateFrom   = null,
    dateTo     = null,
    force      = false,
  } = {}) => {
    try {
      const cacheKey = [
        branchId || 'all', page, perPage,
        status || 'all', supplierId || 'all', search || 'all',
        dateFrom || 'all', dateTo || 'all',
      ].join('_');
      const cached   = purchasesList.value[cacheKey];
      const cachedAt = purchasesListFetchedAt.value[cacheKey];

      if (!force && cached && isFresh(cachedAt, TTL.purchasesList)) {
        return {
          status: 'success',
          data: cached,
          message: null
        };
      }
      
      if (!force && purchasesListInFlight.value?.[cacheKey]) {
        const result = await purchasesListInFlight.value[cacheKey];
        return {
          status: 'success',
          data: result,
          message: null
        };
      }

      const promise = (async () => {
        try {
          const params = { page: String(page), per_page: String(perPage) };
          if (branchId) params.branch_id = String(branchId);
          if (status)     params.status      = status;
          if (supplierId) params.supplier_id = String(supplierId);
          if (search)     params.search      = String(search);
          if (dateFrom)   params.date_from   = dateFrom;
          if (dateTo)     params.date_to     = dateTo;

          const response = await purchasesService.list(params);
          const data     = response?.data?.data || response?.data || [];
          const list     = Array.isArray(data) ? data : (data?.items || []);
          const summary  = data.summary || {};
          const kpiSum   = pick([data.sum, data.total, summary.sum, summary.total]);
          const kpiTax   = pick([data.sum_tax, data.total_tax, summary.sum_tax, summary.tax_total, summary.tax]);
          const kpiDiscount = pick([data.sum_discount, data.total_discount, summary.sum_discount, summary.discount_total, summary.discount]);

          const fullResponse = {
            items:      list,
            page:       parseInt(data.page || page),
            perPage:    parseInt(data.per_page || perPage),
            total:      parseInt(data.total || data.count || list.length),
            summary,
            kpiSum,
            kpiTax,
            kpiDiscount,
            ...data,
          };

          purchasesList.value[cacheKey]          = fullResponse;
          purchasesListFetchedAt.value[cacheKey] = nowMs();
          return fullResponse;
        } finally {
          if (purchasesListInFlight.value?.[cacheKey])
            delete purchasesListInFlight.value[cacheKey];
        }
      })();

      if (!purchasesListInFlight.value) purchasesListInFlight.value = {};
      purchasesListInFlight.value[cacheKey] = promise;
      const result = await promise;
      
      return {
        status: 'success',
        data: result,
        message: null
      };
    } catch (error) {
      console.error('fetchPurchasesList failed:', error);
      return {
        status: 'error',
        data: null,
        message: error.response?.data?.message || error.message
      };
    }
  };

  // ─── fetchPurchases ───────────────────────────────────────────────────────
  /**
   * Alias بسيط لـ fetchPurchasesList بدون فلاتر
   * الـ component يستخدمها هكذا:
   *   const purRes = await purchaseStore.fetchPurchases();
   *   purchases.value = purRes.items || [];
   */
  const fetchPurchases = async (options = {}) => {
    const {
      branch_id,
      per_page,
      supplier_id,
      date_from,
      date_to,
      q,
      ...filters
    } = options;

    return await fetchPurchasesList({
      ...filters,
      branchId: filters.branchId ?? branch_id ?? null,
      perPage: filters.perPage ?? per_page ?? 50,
      supplierId: filters.supplierId ?? supplier_id ?? null,
      dateFrom: filters.dateFrom ?? date_from ?? null,
      dateTo: filters.dateTo ?? date_to ?? null,
      search: filters.search ?? q ?? null,
    });
  };

  // ─── fetchPurchaseDetails ─────────────────────────────────────────────────
  const fetchPurchaseDetails = async (purchaseId, { force = false, signal } = {}) => {
    if (!purchaseId) throw new Error('Purchase ID is required');

    const cacheKey = String(purchaseId);
    const cached   = detailsCache.value[cacheKey];
    const cachedAt = detailsCacheFetchedAt.value[cacheKey];

    if (!force && cached && isFresh(cachedAt, TTL.purchaseDetails)) return cached;

    const response = await purchasesService.get(purchaseId, signal);
    const data     = response?.data?.data || response?.data;

    detailsCache.value[cacheKey]          = data;
    detailsCacheFetchedAt.value[cacheKey] = nowMs();
    return data;
  };

  // ─── getPurchaseById ──────────────────────────────────────────────────────
  /**
   * returns standardized response like settingsStore
   */
  const getPurchaseById = async (purchaseId, options = {}) => {
    try {
      if (!purchaseId) {
        return {
          status: 'error',
          data: null,
          message: 'Purchase ID is required'
        };
      }
      
      const cacheKey = String(purchaseId);
      const cached   = detailsCache.value[cacheKey];
      const cachedAt = detailsCacheFetchedAt.value[cacheKey];

      if (cached && isFresh(cachedAt, TTL.purchaseDetails)) {
        return {
          status: 'success',
          data: cached,
          message: null
        };
      }
      
      const result = await fetchPurchaseDetails(purchaseId, options);
      return {
        status: 'success',
        data: result,
        message: null
      };
    } catch (error) {
      console.error('getPurchaseById failed:', error);
      return {
        status: 'error',
        data: null,
        message: error.response?.data?.message || error.message
      };
    }
  };

  // ─── searchPurchases ──────────────────────────────────────────────────────
  const searchPurchases = async ({ query, branchId, limit = 50, force = false } = {}) => {
    try {
      if (!query || query.trim().length < 2) {
        return {
          status: 'success',
          data: [],
          message: null
        };
      }

      const q        = query.trim();
      const cacheKey = `${q}_${branchId || 'all'}_${limit}`;
      const cached   = searchCache.value[cacheKey];
      const cachedAt = searchCacheFetchedAt.value[cacheKey];

      if (!force && cached && isFresh(cachedAt, TTL.purchasesList)) {
        return {
          status: 'success',
          data: cached,
          message: null
        };
      }

      const params = { q, limit: String(limit) };
      if (branchId) params.branch_id = String(branchId);

      const response = await purchasesService.list(params);
      const data     = response?.data?.data || response?.data || [];
      const list     = Array.isArray(data) ? data : (data?.items || []);

      searchCache.value[cacheKey]          = list;
      searchCacheFetchedAt.value[cacheKey] = nowMs();
      
      return {
        status: response?.data?.status === 'success' ? 'success' : 'error',
        data: list,
        message: response?.data?.message
      };
    } catch (error) {
      console.error('searchPurchases failed:', error);
      return {
        status: 'error',
        data: null,
        message: error.response?.data?.message || error.message
      };
    }
  };

  // ─── createPurchase ───────────────────────────────────────────────────────
  const createPurchase = async (payload, idempotencyKey) => {
    try {
      const response = await purchasesService.create(payload, idempotencyKey);

      // ✅ تحديث تلقائي للكميات — امسح cache المنتجات في المستودع المتأثر
      const productStore = useProductStore();
      if (payload?.branch_id) {
        productStore.invalidateCacheForBranch(payload.branch_id);
      }

      clearPurchasesListCache();
      clearSearchCache();
      useStatementStore().clearSupplierCache();
      
      return {
        status: response?.data?.status === 'success' ? 'success' : 'error',
        data: response?.data?.data || response?.data || {},
        message: response?.data?.message
      };
    } catch (error) {
      console.error('createPurchase failed:', error);
      return {
        status: 'error',
        data: null,
        message: error.response?.data?.message || error.message,
        statusCode: error.response?.status ?? null,
        outcomeUnknown: !error.response || error.response.status >= 500,
      };
    }
  };

  // ─── updatePurchase ───────────────────────────────────────────────────────
  const updatePurchase = async (purchaseId, payload) => {
    try {
      if (!purchaseId) throw new Error('Purchase ID is required');

      const response = await purchasesService.update(purchaseId, payload);

    // حدّث الـ details cache
      const updated  = response?.data?.data || response?.data || {};
      const cacheKey = String(purchaseId);
      if (Object.keys(updated).length) {
        detailsCache.value[cacheKey]          = updated;
        detailsCacheFetchedAt.value[cacheKey] = nowMs();
      } else {
        delete detailsCache.value[cacheKey];
        delete detailsCacheFetchedAt.value[cacheKey];
      }

      clearPurchasesListCache();
      clearSearchCache();
      useStatementStore().clearSupplierCache();
      
      return {
        status: response?.data?.status === 'success' ? 'success' : 'error',
        data: updated,
        message: response?.data?.message
      };
    } catch (error) {
      console.error('updatePurchase failed:', error);
      return {
        status: 'error',
        data: null,
        message: error.response?.data?.message || error.message
      };
    }
  };

  // ─── deletePurchase ───────────────────────────────────────────────────────
  const deletePurchase = async (purchaseId) => {
    try {
      if (!purchaseId) throw new Error('Purchase ID is required');

      const response = await purchasesService.delete(purchaseId);

      const cacheKey = String(purchaseId);
      delete detailsCache.value[cacheKey];
      delete detailsCacheFetchedAt.value[cacheKey];
      clearPurchasesListCache();
      clearSearchCache();
      useStatementStore().clearSupplierCache();
      
      return {
        status: response?.data?.status === 'success' ? 'success' : 'error',
        data: response?.data ?? {},
        message: response?.data?.message
      };
    } catch (error) {
      console.error('deletePurchase failed:', error);
      return {
        status: 'error',
        data: null,
        message: error.response?.data?.message || error.message
      };
    }
  };

  // ─── getNextInvoiceNumber ─────────────────────────────────────────────────
  /**
   * returns standardized response like settingsStore
   */
  const getNextInvoiceNumber = async () => {
    try {
      const response = await purchasesService.getNextInvoiceNumber();
      
      return {
        status: response?.data?.status === 'success' ? 'success' : 'error',
        data: {
          invoice_number: response?.data?.data?.invoice_number || response?.data?.invoice_number || ''
        },
        message: response?.data?.message
      };
    } catch (error) {
      console.error('getNextInvoiceNumber failed:', error);
      return {
        status: 'error',
        data: { invoice_number: '' },
        message: error.response?.data?.message || error.message
      };
    }
  };

  // ─── addPayment ───────────────────────────────────────────────────────────
  /**
   * يضيف دفعة على فاتورة مشتريات
   * الـ component يستخدمها هكذا:
   *   const res = await purchaseStore.addPayment(purchaseId, payload);
   *   if (res?.status === 'success') { ... }
   */
  const addPayment = async (purchaseId, payload, idempotencyKey) => {
    try {
      if (!purchaseId) throw new Error('Purchase ID is required');

      const response = await purchasesService.addPayment(purchaseId, payload, idempotencyKey);

      // clear details cache for this invoice so balance updates
      const cacheKey = String(purchaseId);
      delete detailsCache.value[cacheKey];
      delete detailsCacheFetchedAt.value[cacheKey];
      clearPurchasesListCache();
      useStatementStore().clearSupplierCache();

      return {
        status: response?.data?.status === 'success' ? 'success' : 'error',
        data: response?.data ?? {},
        message: response?.data?.message
      };
    } catch (error) {
      console.error('addPayment failed:', error);
      return {
        status: 'error',
        data: null,
        message: error.response?.data?.message || error.message,
        outcomeUnknown: !error.response || error.response.status >= 500,
      };
    }
  };

  // ─── Cache Clearers ───────────────────────────────────────────────────────
  const clear = () => {
    purchasesList.value          = {};
    purchasesListFetchedAt.value = {};
    purchasesListInFlight.value  = {};
    detailsCache.value          = {};
    detailsCacheFetchedAt.value = {};
    searchCache.value          = {};
    searchCacheFetchedAt.value = {};
  };

  const clearPurchasesListCache = () => {
    purchasesList.value          = {};
    purchasesListFetchedAt.value = {};
    purchasesListInFlight.value  = {};
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
    purchasesList,

    // Read Actions
    fetchPurchases,
    fetchPurchasesList,
    fetchPurchaseDetails,
    getPurchaseById,
    searchPurchases,
    getNextInvoiceNumber,

    // Write Actions
    createPurchase,
    updatePurchase,
    deletePurchase,
    addPayment,

    // Cache
    clear,
    clearPurchasesListCache,
    clearDetailsCache,
    clearSearchCache,
  };
});