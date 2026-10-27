import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import accountsService from '@/services/accounts';

const nowMs = () => Date.now();

export const useAccountStore = defineStore('account', () => {

  // ─── Cache TTL ────────────────────────────────────────────────────────────
  const TTL = {
    accounts: 30 * 60 * 1000, // 30 دقيقة (نادراً ما يتغير)
  };

  // ─── State ────────────────────────────────────────────────────────────────
  const accounts = ref({
    tenant: [],
    global: [],
  });
  const accountsFetchedAt = ref(0);
  const accountsInFlight  = ref(null);

  const searchCache          = ref({});
  const searchCacheFetchedAt = ref({});

  // ─── Helpers ──────────────────────────────────────────────────────────────
  const isFresh = (fetchedAt, ttl) =>
    !!fetchedAt && (nowMs() - fetchedAt) < ttl;

  // ─── fetchGroupedAccounts ─────────────────────────────────────────────────
  /**
   * يجيب الحسابات مقسّمة إلى tenant + global
   * الـ component يستخدمها هكذا:
   *   const res = await accountStore.fetchGroupedAccounts();
   *   res.data.tenant / res.data.global
   */
  const fetchGroupedAccounts = async ({ force = false } = {}) => {
    try {
      if (
        !force &&
        isFresh(accountsFetchedAt.value, TTL.accounts) &&
        (accounts.value.tenant.length > 0 || accounts.value.global.length > 0)
      ) {
        return {
          status: 'success',
          data:   accounts.value,
          message: null
        };
      }

      if (!force && accountsInFlight.value) {
        const result = await accountsInFlight.value;
        return {
          status: 'success',
          data:   result,
          message: null
        };
      }

      const promise = (async () => {
        try {
          const response = await accountsService.listGrouped();
          const payload  = response?.data?.data || response?.data || {};

          accounts.value = {
            tenant: payload.tenant_accounts || [],
            global: payload.global_accounts || [],
          };
          accountsFetchedAt.value = nowMs();

          return accounts.value;
        } finally {
          accountsInFlight.value = null;
        }
      })();

      accountsInFlight.value = promise;
      const result = await promise;

      return {
        status: 'success',
        data:   result,
        message: null
      };
    } catch (err) {
      console.error('fetchGroupedAccounts failed:', err);
      return {
        status: 'error',
        data:   { tenant: [], global: [] },
        message: err.response?.data?.message || err.message
      };
    }
  };

  // ─── fetchAccounts ────────────────────────────────────────────────────────
  /**
   * يجيب كل الحسابات كـ flat list
   * الـ component يستخدمها هكذا:
   *   const res = await accountStore.fetchAccounts();
   *   list.value = res.data || [];
   */
  const fetchAccounts = async ({ force = false } = {}) => {
    try {
      const res = await fetchGroupedAccounts({ force });
      const grouped = res.data || { tenant: [], global: [] };
      const list    = [...grouped.tenant, ...grouped.global];

      return {
        status: 'success',
        data:   list,
        message: null
      };
    } catch (err) {
      console.error('fetchAccounts failed:', err);
      return {
        status: 'error',
        data:   [],
        message: err.response?.data?.message || err.message
      };
    }
  };

  // ─── searchAccounts ───────────────────────────────────────────────────────
  /**
   * يبحث في الحسابات محلياً لو البيانات موجودة، وإلا يكلم الـ API
   * الـ component يستخدمها هكذا:
   *   const res = await accountStore.searchAccounts(query);
   *   results.value = res.data || [];
   */
  const searchAccounts = async (query, { force = false } = {}) => {
    try {
      if (!query || query.trim().length < 2) {
        return {
          status: 'success',
          data:   [],
          message: null
        };
      }

      const q          = query.trim().toLowerCase();
      const cacheKey   = q;
      const cached     = searchCache.value[cacheKey];
      const cachedAt   = searchCacheFetchedAt.value[cacheKey];

      if (!force && cached && isFresh(cachedAt, TTL.accounts)) {
        return {
          status: 'success',
          data:   cached,
          message: null
        };
      }

      const allAccs = [...accounts.value.tenant, ...accounts.value.global];

      // فلتر محلي لو البيانات موجودة
      if (allAccs.length > 0 && !force) {
        const filtered = allAccs.filter(a =>
          a.name?.toLowerCase().includes(q)        ||
          a.code?.toLowerCase().includes(q)        ||
          a.description?.toLowerCase().includes(q)
        );
        searchCache.value[cacheKey]          = filtered;
        searchCacheFetchedAt.value[cacheKey] = nowMs();
        return {
          status: 'success',
          data:   filtered,
          message: null
        };
      }

      // وإلا ابحث عبر الـ API مع fallback للفلتر المحلي
      try {
        const response = await accountsService.search(q);
        const results  = response?.data?.data || response?.data || [];
        const list     = Array.isArray(results) ? results : (results?.items || []);

        searchCache.value[cacheKey]          = list;
        searchCacheFetchedAt.value[cacheKey] = nowMs();

        return {
          status: 'success',
          data:   list,
          message: null
        };
      } catch {
        // لو الـ endpoint مش موجود — فلتر من الـ cache المتاح
        const fallback = allAccs.filter(a =>
          a.name?.toLowerCase().includes(q)        ||
          a.code?.toLowerCase().includes(q)        ||
          a.description?.toLowerCase().includes(q)
        );
        searchCache.value[cacheKey]          = fallback;
        searchCacheFetchedAt.value[cacheKey] = nowMs();

        return {
          status: 'success',
          data:   fallback,
          message: null
        };
      }
    } catch (err) {
      console.error('searchAccounts failed:', err);
      return {
        status: 'error',
        data:   [],
        message: err.response?.data?.message || err.message
      };
    }
  };

  // ─── Computed Getters ─────────────────────────────────────────────────────
  const getAccountById = (id) =>
    computed(() => {
      const all = [...accounts.value.tenant, ...accounts.value.global];
      return all.find(a => String(a.id) === String(id));
    });

  const tenantAccounts = computed(() => accounts.value.tenant);
  const globalAccounts = computed(() => accounts.value.global);
  const allAccounts    = computed(() => [...accounts.value.tenant, ...accounts.value.global]);

  // ─── Cache Clearers ───────────────────────────────────────────────────────
  const clear = () => {
    accounts.value          = { tenant: [], global: [] };
    accountsFetchedAt.value = 0;
    accountsInFlight.value  = null;
    searchCache.value          = {};
    searchCacheFetchedAt.value = {};
  };

  const clearSearchCache = () => {
    searchCache.value          = {};
    searchCacheFetchedAt.value = {};
  };

  // ─── Return ───────────────────────────────────────────────────────────────
  return {
    // State
    accounts,

    // Computed
    tenantAccounts,
    globalAccounts,
    allAccounts,

    // Read Actions
    fetchGroupedAccounts,
    fetchAccounts,
    searchAccounts,
    getAccountById,

    // Cache
    clear,
    clearSearchCache,
  };
});