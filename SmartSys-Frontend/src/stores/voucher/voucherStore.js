import { defineStore } from 'pinia';
import { ref } from 'vue';
import vouchersService from '@/services/vouchers';
import { useStatementStore } from '@/stores/statement/statementStore';
import { useSalesStore } from '@/stores/sales/salesStore';

const nowMs = () => Date.now();

export const useVoucherStore = defineStore('voucher', () => {

  // ─── Cache TTL ────────────────────────────────────────────────────────────
  const TTL = {
    vouchersList: 5 * 60 * 1000, // 5 دقائق
  };

  // ─── State ────────────────────────────────────────────────────────────────
  const vouchersList          = ref({});
  const vouchersListFetchedAt = ref({});
  const vouchersListInFlight  = ref(null);

  const searchCache           = ref({});
  const searchCacheFetchedAt  = ref({});

  // ─── Helpers ──────────────────────────────────────────────────────────────
  const isFresh = (fetchedAt, ttl) =>
    !!fetchedAt && (nowMs() - fetchedAt) < ttl;

  // ─── fetchVouchersList ────────────────────────────────────────────────────
  const fetchVouchersList = async ({
    branchId  = null,
    page      = 1,
    perPage   = 50,
    type      = null,
    status    = null,
    dateFrom  = null,
    dateTo    = null,
    search    = null,
    force     = false,
  } = {}) => {
    const cacheKey = [
      branchId || 'all', page, perPage,
      type || 'all', status || 'all',
      dateFrom || 'all', dateTo || 'all',
      search || 'all',
    ].join('_');

    const cached   = vouchersList.value[cacheKey];
    const cachedAt = vouchersListFetchedAt.value[cacheKey];

    if (!force && cached && isFresh(cachedAt, TTL.vouchersList)) return cached;
    if (!force && vouchersListInFlight.value?.[cacheKey])
      return await vouchersListInFlight.value[cacheKey];

    const promise = (async () => {
      try {
        const params = { page: String(page), per_page: String(perPage) };
        if (branchId) params.branch_id = String(branchId);
        if (type)     params.type       = type;
        if (status)   params.status     = status;
        if (dateFrom) params.start_date = dateFrom;
        if (dateTo)   params.end_date   = dateTo;
        if (search)   params.search     = search;

        const response = await vouchersService.list(params);
        const data  = response?.data?.data || response?.data || {};
        const items = Array.isArray(data?.items)
          ? data.items
          : (Array.isArray(data) ? data : []);

        vouchersList.value[cacheKey]          = items;
        vouchersListFetchedAt.value[cacheKey] = nowMs();
        return items;
      } finally {
        if (vouchersListInFlight.value?.[cacheKey])
          delete vouchersListInFlight.value[cacheKey];
      }
    })();

    if (!vouchersListInFlight.value) vouchersListInFlight.value = {};
    vouchersListInFlight.value[cacheKey] = promise;
    return await promise;
  };

  // ─── searchVouchers ───────────────────────────────────────────────────────
  const searchVouchers = async ({
    query    = '',
    branchId = null,
    type     = null,
    limit    = 50,
    force    = false,
  } = {}) => {
    if (!query || query.trim().length < 2) return [];

    const q        = query.trim();
    const cacheKey = `${q}_${branchId || 'all'}_${type || 'all'}_${limit}`;
    const cached   = searchCache.value[cacheKey];
    const cachedAt = searchCacheFetchedAt.value[cacheKey];

    if (!force && cached && isFresh(cachedAt, TTL.vouchersList)) return cached;

    const params = { q, limit: String(limit) };
    if (branchId) params.branch_id = String(branchId);
    if (type)     params.type      = type;

    const response = await vouchersService.list(params);
    const data     = response?.data?.data || response?.data || {};
    const items    = Array.isArray(data?.items)
      ? data.items
      : (Array.isArray(data) ? data : []);

    searchCache.value[cacheKey]          = items;
    searchCacheFetchedAt.value[cacheKey] = nowMs();
    return items;
  };

  // ─── createVoucher ────────────────────────────────────────────────────────
  /**
   * ينشئ سند جديد ويمسح الـ cache عشان القائمة تتحدث
   * @param {Object} payload
   * @returns {{ status: 'success', data: Object }}
   */
  const createVoucher = async (payload, idempotencyKey) => {
    const response = await vouchersService.create(payload, idempotencyKey);

    // امسح قائمة السندات عشان الـ refetch يجيب البيانات الجديدة
    clearVouchersListCache();
    clearSearchCache();
    useStatementStore().clear();
    useSalesStore().clearSalesListCache();
    useSalesStore().clearDetailsCache();
    useSalesStore().clearSearchCache();

    return {
      status: 'success',
      data:   response?.data?.data || response?.data || {},
    };
  };

  // ─── updateVoucher ────────────────────────────────────────────────────────
  /**
   * يحدث سند موجود ويمسح الـ cache المرتبط بيه
   * @param {number|string} voucherId
   * @param {Object}        payload
   * @returns {{ status: 'success', data: Object }}
   */
  const updateVoucher = async (voucherId, payload) => {
    if (!voucherId) throw new Error('Voucher ID is required');

    const response = await vouchersService.update(voucherId, payload);

    clearVouchersListCache();
    clearSearchCache();
    useStatementStore().clear();
    useSalesStore().clearSalesListCache();
    useSalesStore().clearDetailsCache();
    useSalesStore().clearSearchCache();

    return {
      status: 'success',
      data:   response?.data?.data || response?.data || {},
    };
  };

  // ─── cancelVoucher ────────────────────────────────────────────────────────
  /**
   * يلغي سندًا دون حذف أثره ويمسح الـ cache
   * @param {number|string} voucherId
   * @returns {Promise<{ status: 'success' }>}
   */
  const cancelVoucher = async (voucherId) => {
    if (!voucherId) throw new Error('Voucher ID is required');

    await vouchersService.delete(voucherId);

    clearVouchersListCache();
    clearSearchCache();
    useStatementStore().clear();
    useSalesStore().clearSalesListCache();
    useSalesStore().clearDetailsCache();
    useSalesStore().clearSearchCache();

    return { status: 'success' };
  };

  // ─── Cache Clearers ───────────────────────────────────────────────────────
  const clear = () => {
    vouchersList.value          = {};
    vouchersListFetchedAt.value = {};
    vouchersListInFlight.value  = null;
    searchCache.value           = {};
    searchCacheFetchedAt.value  = {};
  };

  const clearVouchersListCache = () => {
    vouchersList.value          = {};
    vouchersListFetchedAt.value = {};
    vouchersListInFlight.value  = null;
  };

  const clearSearchCache = () => {
    searchCache.value          = {};
    searchCacheFetchedAt.value = {};
  };

  // ─── Return ───────────────────────────────────────────────────────────────
  return {
    // State
    vouchersList,

    // Read Actions
    fetchVouchersList,
    searchVouchers,

    // Write Actions
    createVoucher,
    updateVoucher,
    cancelVoucher,

    // Cache
    clear,
    clearVouchersListCache,
    clearSearchCache,
  };
});