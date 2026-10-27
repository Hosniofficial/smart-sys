import { defineStore } from 'pinia';
import { ref } from 'vue';
import {
  createSupplier as createSupplierRequest,
  deleteSupplier as deleteSupplierRequest,
  listSuppliers,
  paySupplierDebt as paySupplierDebtRequest,
  updateSupplier as updateSupplierRequest,
} from '@/services/suppliers';

const nowMs = () => Date.now();

export const useSupplierStore = defineStore('supplier', () => {

  // ─── Cache TTL ────────────────────────────────────────────────────────────
  const TTL = {
    suppliers: 10 * 60 * 1000, // 10 دقائق (نادراً ما يتغير)
  };

  // ─── State ────────────────────────────────────────────────────────────────
  const suppliers          = ref([]);
  const suppliersFetchedAt = ref(0);
  const suppliersInFlight  = ref(null);

  const searchCache          = ref({});
  const searchCacheFetchedAt = ref({});

  const detailsCache          = ref({});
  const detailsCacheFetchedAt = ref({});

  // ─── Helpers ──────────────────────────────────────────────────────────────
  const isFresh = (fetchedAt, ttl) =>
    !!fetchedAt && (nowMs() - fetchedAt) < ttl;

  // ─── fetchSuppliers ───────────────────────────────────────────────────────
  /**
   * يجيب كل الموردين مع دعم جلب مورد معين بالـ id
   * الـ component يستخدمها هكذا:
   *   const res = await supplierStore.fetchSuppliers();
   *   suppliers.value = res.data || [];
   *
   * أو لمورد معين:
   *   const res = await supplierStore.fetchSuppliers({ id: supplierId });
   *   supplier.value = res.data;
   */
  const fetchSuppliers = async ({ force = false, id = null, params = {} } = {}) => {
    try {
      // لو بنجيب مورد معين — ابحث في الـ cache أولاً
      if (id && !force) {
        const cached = suppliers.value.find(s => String(s.id) === String(id));
        if (cached) {
          return {
            status: 'success',
            data:   cached,
            message: null
          };
        }
      }

      if (!force && isFresh(suppliersFetchedAt.value, TTL.suppliers) && Array.isArray(suppliers.value) && suppliers.value.length) {
        const data = id
          ? suppliers.value.find(s => String(s.id) === String(id)) || null
          : suppliers.value;
        return {
          status: 'success',
          data,
          message: null
        };
      }

      if (!force && suppliersInFlight.value) {
        const result = await suppliersInFlight.value;
        const data   = id
          ? result.find(s => String(s.id) === String(id)) || null
          : result;
        return {
          status: 'success',
          data,
          message: null
        };
      }

      const promise = (async () => {
        try {
          const list = await listSuppliers({ per_page: 1000, ...params });
          suppliers.value  = Array.isArray(list) ? list : (list?.items || []);
          suppliersFetchedAt.value = nowMs();

          if (id) {
            const found = suppliers.value.find(s => String(s.id) === String(id));
            return found || null;
          }

          return suppliers.value;
        } catch (err) {
          console.error('Failed to fetch suppliers:', err);
          suppliers.value          = [];
          suppliersFetchedAt.value = 0;
          throw err;
        } finally {
          suppliersInFlight.value = null;
        }
      })();

      suppliersInFlight.value = promise;
      const result = await promise;

      return {
        status: 'success',
        data:   result,
        message: null
      };
    } catch (err) {
      console.error('fetchSuppliers failed:', err);
      return {
        status: 'error',
        data:   null,
        message: err.response?.data?.message || err.message
      };
    }
  };

  // ─── searchSuppliers ──────────────────────────────────────────────────────
  /**
   * يبحث في الموردين محلياً لو البيانات موجودة، وإلا يكلم الـ API
   * الـ component يستخدمها هكذا:
   *   const res = await supplierStore.searchSuppliers(query);
   *   results.value = res.data || [];
   */
  const searchSuppliers = async (query, { force = false } = {}) => {
    try {
      if (!query || query.trim().length < 2) {
        return {
          status: 'success',
          data:   [],
          message: null
        };
      }

      const q        = query.trim().toLowerCase();
      const cacheKey = q;
      const cached   = searchCache.value[cacheKey];
      const cachedAt = searchCacheFetchedAt.value[cacheKey];

      if (!force && cached && isFresh(cachedAt, TTL.suppliers)) {
        return {
          status: 'success',
          data:   cached,
          message: null
        };
      }

      // فلتر محلي لو البيانات موجودة
      if (suppliers.value.length > 0 && !force) {
        const filtered = suppliers.value.filter(s =>
          s.name?.toLowerCase().includes(q)  ||
          s.phone?.toLowerCase().includes(q) ||
          s.email?.toLowerCase().includes(q) ||
          s.code?.toLowerCase().includes(q)
        );
        searchCache.value[cacheKey]          = filtered;
        searchCacheFetchedAt.value[cacheKey] = nowMs();
        return {
          status: 'success',
          data:   filtered,
          message: null
        };
      }

      // وإلا ابحث عبر الـ API
      try {
        const list    = await listSuppliers({ q, per_page: 50 });
        const results = Array.isArray(list) ? list : (list?.items || []);
        searchCache.value[cacheKey]          = results;
        searchCacheFetchedAt.value[cacheKey] = nowMs();
        return {
          status: 'success',
          data:   results,
          message: null
        };
      } catch (err) {
        console.error('Failed to search suppliers via API:', err);
        searchCache.value[cacheKey]          = [];
        searchCacheFetchedAt.value[cacheKey] = 0;
        return {
          status: 'error',
          data:   [],
          message: err.response?.data?.message || err.message
        };
      }
    } catch (err) {
      console.error('searchSuppliers failed:', err);
      return {
        status: 'error',
        data:   null,
        message: err.response?.data?.message || err.message
      };
    }
  };

  // ─── getSupplierById ──────────────────────────────────────────────────────
  /**
   * يرجع المورد من الـ cache المحلي مباشرة (sync)
   * لو محتاج تجيبه من الـ API استخدم fetchSuppliers({ id })
   */
  const getSupplierById = (id) =>
    suppliers.value.find(s => String(s.id) === String(id));

  // ─── createSupplier ───────────────────────────────────────────────────────
  /**
   * ينشئ مورد جديد ويضيفه للـ cache المحلي
   * الـ component يستخدمها هكذا:
   *   const res = await supplierStore.createSupplier(payload);
   *   if (res.status === 'success') { ... }
   */
  const createSupplier = async (payload) => {
    try {
      const response    = await createSupplierRequest(payload);
      const newSupplier = response?.data?.data || response?.data;

      if (newSupplier) {
        suppliers.value.push(newSupplier);
        suppliersFetchedAt.value = nowMs();
      }

      return {
        status: response?.data?.status === 'success' ? 'success' : 'error',
        data:   newSupplier,
        message: response?.data?.message
      };
    } catch (err) {
      console.error('createSupplier failed:', err);
      return {
        status: 'error',
        data:   null,
        message: err.response?.data?.message || err.message
      };
    }
  };

  // ─── updateSupplier ───────────────────────────────────────────────────────
  /**
   * يعدّل مورد ويحدّث الـ cache المحلي
   * الـ component يستخدمها هكذا:
   *   const res = await supplierStore.updateSupplier(id, payload);
   *   if (res.status === 'success') { ... }
   */
  const updateSupplier = async (id, payload) => {
    try {
      const response = await updateSupplierRequest(id, payload);
      const updated  = response?.data?.data || response?.data || {};

      const idx = suppliers.value.findIndex(s => String(s.id) === String(id));
      if (idx !== -1) suppliers.value[idx] = { ...suppliers.value[idx], ...updated };

      delete detailsCache.value[String(id)];
      delete detailsCacheFetchedAt.value[String(id)];

      return {
        status: 'success',
        data:   updated,
        message: null
      };
    } catch (err) {
      console.error('updateSupplier failed:', err);
      return {
        status: 'error',
        data:   null,
        message: err.response?.data?.message || err.message
      };
    }
  };

  // ─── deleteSupplier ───────────────────────────────────────────────────────
  /**
   * يحذف مورد ويمسحه من الـ cache المحلي
   * الـ component يستخدمها هكذا:
   *   const res = await supplierStore.deleteSupplier(id);
   *   if (res.status === 'success') { ... }
   */
  const deleteSupplier = async (id) => {
    try {
      await deleteSupplierRequest(id);

      suppliers.value = suppliers.value.filter(s => String(s.id) !== String(id));
      delete detailsCache.value[String(id)];
      delete detailsCacheFetchedAt.value[String(id)];

      return {
        status: 'success',
        data:   null,
        message: null
      };
    } catch (err) {
      console.error('deleteSupplier failed:', err);
      return {
        status: 'error',
        data:   null,
        message: err.response?.data?.message || err.message
      };
    }
  };

  const paySupplierDebt = async (payload, idempotencyKey) => {
    try {
      const response = await paySupplierDebtRequest(payload, idempotencyKey);
      suppliersFetchedAt.value = 0;

      return {
        status: response?.data?.status === 'success' ? 'success' : 'error',
        data: response?.data?.data || response?.data || {},
        message: response?.data?.message || null,
        outcomeUnknown: false,
      };
    } catch (err) {
      console.error('paySupplierDebt failed:', err);
      return {
        status: 'error',
        data: null,
        message: err.response?.data?.message || err.message,
        outcomeUnknown: !err.response || err.response.status >= 500,
      };
    }
  };

  // ─── Cache Clearers ───────────────────────────────────────────────────────
  const clear = () => {
    suppliers.value          = [];
    suppliersFetchedAt.value = 0;
    suppliersInFlight.value  = null;
    searchCache.value          = {};
    searchCacheFetchedAt.value = {};
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
    suppliers,

    // Read Actions
    fetchSuppliers,
    searchSuppliers,
    getSupplierById,

    // Write Actions
    createSupplier,
    updateSupplier,
    deleteSupplier,
    paySupplierDebt,


    // Cache
    clear,
    clearSearchCache,
  };
});