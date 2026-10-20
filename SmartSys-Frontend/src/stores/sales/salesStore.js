import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import apiClient from '@/config/axios';
import { useStatementStore } from '@/stores/statement/statementStore';

const nowMs = () => Date.now();

export const useSalesStore = defineStore('sales', () => {

  // ─── Cache TTL ────────────────────────────────────────────────────────────
  const TTL = {
    salesList:      2 * 60 * 1000, // 2 دقيقة
    saleDetails:    5 * 60 * 1000, // 5 دقائق
    salesAnalytics: 1 * 60 * 1000, // دقيقة واحدة
  };

  // ─── State ────────────────────────────────────────────────────────────────
  const salesList          = ref({});
  const salesListFetchedAt = ref({});
  const salesListInFlight  = ref({});

  const detailsCache          = ref({});
  const detailsCacheFetchedAt = ref({});

  const analyticsCache          = ref({});
  const analyticsCacheFetchedAt = ref({});

  const searchCache          = ref({});
  const searchCacheFetchedAt = ref({});

  // ─── Helpers ──────────────────────────────────────────────────────────────
  const isFresh = (fetchedAt, ttl) =>
    !!fetchedAt && (nowMs() - fetchedAt) < ttl;

  const pick = (candidates) =>
    candidates
      .map(v => (v == null ? null : parseFloat(v)))
      .find(v => typeof v === 'number' && !isNaN(v)) ?? null;

  // ─── fetchSalesList ───────────────────────────────────────────────────────
  const fetchSalesList = async ({
    branchId,
    page = 1,
    perPage = 20,
    status,
    customerId,
    dateFrom,
    dateTo,
    search,
    sort,
    order,
    includeTotals = false,
    force         = false,
  } = {}) => {
    try {
      const cacheKey = `${branchId || 'all'}_${page}_${perPage}_${status || 'all'}_${customerId || 'all'}_${dateFrom || 'all'}_${dateTo || 'all'}_totals-${includeTotals ? 'yes' : 'no'}`;
      const cached   = salesList.value[cacheKey];
      const cachedAt = salesListFetchedAt.value[cacheKey];

      if (!force && cached && isFresh(cachedAt, TTL.salesList)) {
        return {
          status: 'success',
          data: cached,
          message: null
        };
      }
      
      if (!force && salesListInFlight.value?.[cacheKey]) {
        const result = await salesListInFlight.value[cacheKey];
        return {
          status: 'success',
          data: result,
          message: null
        };
      }

      const promise = (async () => {
        try {
          const params = { page: String(page), per_page: String(perPage) };
          if (branchId)      params.branch_id      = String(branchId);
          if (status)        params.status         = status;
          if (customerId)    params.customer_id    = String(customerId);
          if (dateFrom)      params.date_from      = dateFrom;
          if (dateTo)        params.date_to        = dateTo;
          if (search)        params.q              = search;
          if (sort)          params.sort           = sort;
          if (order)         params.order          = order;
          if (includeTotals) params.include_totals = '1';

          const response = await apiClient.get('/sales', { params });
          const data     = response?.data?.data || response?.data || [];
          const list     = Array.isArray(data) ? data : (data?.items || []);
          const summary  = data.summary || {};
          const kpiSum = pick([
  data.sum_net_total, data.total_sum,
  summary.sum_net_total, summary.net_total, summary.total,
]) ?? list.reduce((s, v) => s + parseFloat((v.net_total_amount ?? v.total_amount) || 0), 0);
          const kpiTax   = pick([data.sum_tax, data.total_tax, summary.sum_tax, summary.tax_total, summary.tax]);
          const kpiDiscount = pick([data.sum_discount, data.total_discount, summary.sum_discount, summary.discount_total, summary.discount]);

          const fullResponse = {
            items:       list,
            page:        parseInt(data.page || page),
            perPage:     parseInt(data.per_page || perPage),
            total:       parseInt(data.total || data.count || list.length),
            summary,
            kpiSum,
            kpiTax,
            kpiDiscount,
            ...data,
          };

          salesList.value[cacheKey]          = fullResponse;
          salesListFetchedAt.value[cacheKey] = nowMs();
          return fullResponse;
        } finally {
          if (salesListInFlight.value?.[cacheKey])
            delete salesListInFlight.value[cacheKey];
        }
      })();

      if (!salesListInFlight.value) salesListInFlight.value = {};
      salesListInFlight.value[cacheKey] = promise;
      const result = await promise;
      
      return {
        status: 'success',
        data: result,
        message: null
      };
    } catch (error) {
      console.error('fetchSalesList failed:', error);
      return {
        status: 'error',
        data: null,
        message: error.response?.data?.message || error.message
      };
    }
  };

  // ─── fetchSaleDetails ─────────────────────────────────────────────────────
  const fetchSaleDetails = async (saleId, { force = false } = {}) => {
    try {
      if (!saleId) {
        return {
          status: 'error',
          data: null,
          message: 'Sale ID is required'
        };
      }

      const cacheKey = String(saleId);
      const cached   = detailsCache.value[cacheKey];
      const cachedAt = detailsCacheFetchedAt.value[cacheKey];

      if (!force && cached && isFresh(cachedAt, TTL.saleDetails)) {
        return {
          status: 'success',
          data: cached,
          message: null
        };
      }

      const response = await apiClient.get(`/sales/${saleId}`);
      const data     = response?.data?.data || response?.data;

      detailsCache.value[cacheKey]          = data;
      detailsCacheFetchedAt.value[cacheKey] = nowMs();
      
      return {
        status: 'success',
        data: data,
        message: null
      };
    } catch (error) {
      console.error('fetchSaleDetails failed:', error);
      return {
        status: 'error',
        data: null,
        message: error.response?.data?.message || error.message
      };
    }
  };

  // ─── searchSales ──────────────────────────────────────────────────────────
  const searchSales = async ({ query, branchId, limit = 50, force = false } = {}) => {
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

      if (!force && cached && isFresh(cachedAt, TTL.salesList)) {
        return {
          status: 'success',
          data: cached,
          message: null
        };
      }

      const params = { q, limit: String(limit) };
      if (branchId) params.branch_id = String(branchId);

      const response = await apiClient.get('/sales', { params });
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
      console.error('searchSales failed:', error);
      return {
        status: 'error',
        data: null,
        message: error.response?.data?.message || error.message
      };
    }
  };

  // ─── createSale ───────────────────────────────────────────────────────────
  /**
   * creates a new sales invoice - critical action requiring strict error handling
   * returns standardized response like settingsStore
   */
  const createSale = async (payload) => {
    try {
      const response = await apiClient.post('/sales', payload);

    // امسح الـ cache عشان القائمة تتحدث
      clearSalesListCache();
      clearSearchCache();
      useStatementStore().clearCustomerCache();

      return {
        status: response?.data?.status || 'success',
        data: response?.data?.data ?? response?.data
      };
    } catch (error) {
      console.error('createSale failed:', error);
      return {
        status: 'error',
        data: null,
        message: error?.response?.data?.message || 'Sale creation failed'
      };
    }
  };

  // ─── payDebt ──────────────────────────────────────────────────────────────
  /**
   * pays debt on invoice - critical action requiring strict error handling
   * returns standardized response like settingsStore
   */
  const payDebt = async (payload) => {
    try {
      const response = await apiClient.post('/sales/pay-debt', payload);

      // clear details cache for this invoice + list
      if (payload?.sale_id) {
        const cacheKey = String(payload.sale_id);
        delete detailsCache.value[cacheKey];
        delete detailsCacheFetchedAt.value[cacheKey];
      }
      clearSalesListCache();
      useStatementStore().clearCustomerCache();

      return {
        status: response?.data?.status || 'success',
        data: response?.data?.data ?? response?.data
      };
    } catch (error) {
      console.error('payDebt failed:', error);
      return {
        status: 'error',
        data: null,
        message: error?.response?.data?.message || 'Payment failed'
      };
    }
  };

  const addSalePayment = async (payload, idempotencyKey) => {
    try {
      const response = await apiClient.post('/sales/add-payment', payload, {
        headers: idempotencyKey ? { 'Idempotency-Key': idempotencyKey } : {},
      });

      if (payload?.sale_id) {
        const cacheKey = String(payload.sale_id);
        delete detailsCache.value[cacheKey];
        delete detailsCacheFetchedAt.value[cacheKey];
      }
      clearSalesListCache();
      useStatementStore().clearCustomerCache();

      return {
        status: response?.data?.status || 'success',
        data: response?.data?.data ?? response?.data,
      };
    } catch (error) {
      console.error('addSalePayment failed:', error);
      return {
        status: 'error',
        data: null,
        httpStatus: Number(error?.response?.status || 0),
        message: error?.response?.data?.message || error.message || 'Payment failed',
      };
    }
  };

  const voidSale = async (saleId, note = null) => {
    try {
      const response = await apiClient.delete(`/sales/${saleId}`, { data: { note } });
      delete detailsCache.value[String(saleId)];
      delete detailsCacheFetchedAt.value[String(saleId)];
      clearSalesListCache();
      clearSearchCache();
      useStatementStore().clearCustomerCache();

      return {
        status: response?.data?.status || 'success',
        data: response?.data?.data ?? response?.data,
      };
    } catch (error) {
      return {
        status: 'error',
        data: null,
        message: error?.response?.data?.message || 'تعذر إلغاء فاتورة البيع',
      };
    }
  };

  const updateSale = async (saleId, payload) => {
    try {
      const response = await apiClient.put(`/sales/${saleId}`, payload);
      delete detailsCache.value[String(saleId)];
      delete detailsCacheFetchedAt.value[String(saleId)];
      clearSalesListCache();
      clearSearchCache();

      return {
        status: response?.data?.status || 'success',
        data: response?.data?.data ?? response?.data,
      };
    } catch (error) {
      return {
        status: 'error',
        data: null,
        message: error?.response?.data?.message || 'تعذر تعديل فاتورة البيع',
      };
    }
  };

  // ─── getSaleById ──────────────────────────────────────────────────────────
  const getSaleById = (saleId) =>
    computed(() => detailsCache.value[String(saleId)]);

  // ─── Cache Clearers ───────────────────────────────────────────────────────
  const clear = () => {
    salesList.value          = {};
    salesListFetchedAt.value = {};
    salesListInFlight.value  = {};
    detailsCache.value          = {};
    detailsCacheFetchedAt.value = {};
    analyticsCache.value          = {};
    analyticsCacheFetchedAt.value = {};
    searchCache.value          = {};
    searchCacheFetchedAt.value = {};
  };

  const clearSalesListCache = () => {
    salesList.value          = {};
    salesListFetchedAt.value = {};
    salesListInFlight.value  = {};
  };

  const clearDetailsCache = () => {
    detailsCache.value          = {};
    detailsCacheFetchedAt.value = {};
  };

  const clearAnalyticsCache = () => {
    analyticsCache.value          = {};
    analyticsCacheFetchedAt.value = {};
  };

  const clearSearchCache = () => {
    searchCache.value          = {};
    searchCacheFetchedAt.value = {};
  };

  // ─── Return ───────────────────────────────────────────────────────────────
  return {
    // State
    salesList,

    // Read Actions
    fetchSalesList,
    fetchSaleDetails,
    searchSales,
    getSaleById,

    // Write Actions
    createSale,
    payDebt,
    addSalePayment,
    updateSale,
    voidSale,

    // Cache
    clear,
    clearSalesListCache,
    clearDetailsCache,
    clearAnalyticsCache,
    clearSearchCache,
  };
});