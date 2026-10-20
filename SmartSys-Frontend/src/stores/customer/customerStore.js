import { defineStore } from 'pinia';
import { ref } from 'vue';
import { listCustomers } from '@/services/customers';
import apiClient from '@/config/axios';
import { useStatementStore } from '@/stores/statement/statementStore';
import { useSalesStore } from '@/stores/sales/salesStore';

const nowMs = () => Date.now();

export const useCustomerStore = defineStore('customer', () => {

  // ─── Cache TTL ────────────────────────────────────────────────────────────
  const TTL = {
    customers: 5 * 60 * 1000, // 5 دقائق
  };

  // ─── State ────────────────────────────────────────────────────────────────
  const customers          = ref([]);
  const customersFetchedAt = ref(0);
  const customersInFlight  = ref(null);

  const searchCache          = ref({});
  const searchCacheFetchedAt = ref({});

  const detailsCache          = ref({});
  const detailsCacheFetchedAt = ref({});

  // ─── Helpers ──────────────────────────────────────────────────────────────
  const isFresh = (fetchedAt, ttl) =>
    !!fetchedAt && (nowMs() - fetchedAt) < ttl;

  // ─── fetchCustomers ───────────────────────────────────────────────────────
  /**
   * يجيب كل العملاء مع دعم جلب عميل معين بالـ id
   * الـ component يستخدمها هكذا:
   *   const res = await customerStore.fetchCustomers();
   *   customers.value = res.data || [];
   *
   * أو لعميل معين:
   *   const res = await customerStore.fetchCustomers({ id: customerId });
   *   customer.value = res.data;
   */
  const fetchCustomers = async ({ force = false, id = null, params = {} } = {}) => {
    try {
      // لو بنجيب عميل معين — ابحث في الـ cache أولاً
      if (id && !force) {
        const cached = customers.value.find(c => String(c.id) === String(id));
        if (cached) {
          return {
            status: 'success',
            data:   cached,
            message: null
          };
        }
      }

      if (!force && isFresh(customersFetchedAt.value, TTL.customers) && Array.isArray(customers.value) && customers.value.length) {
        const data = id
          ? customers.value.find(c => String(c.id) === String(id)) || null
          : customers.value;
        return {
          status: 'success',
          data,
          message: null
        };
      }

      if (!force && customersInFlight.value) {
        const result = await customersInFlight.value;
        const data   = id
          ? result.find(c => String(c.id) === String(id)) || null
          : result;
        return {
          status: 'success',
          data,
          message: null
        };
      }

      const promise = (async () => {
        try {
          let list;

          if (id) {
            // جرب endpoint العميل المفرد أولاً
            try {
              const response = await apiClient.get(`/customers/${id}`);
              const customer = response?.data?.data || response?.data;
              if (customer) {
                const existingIndex = customers.value.findIndex(c => String(c.id) === String(id));
                if (existingIndex >= 0) {
                  customers.value[existingIndex] = customer;
                } else {
                  customers.value.push(customer);
                }
                customersFetchedAt.value = nowMs();
                return customer;
              }
            } catch (specificError) {
              console.warn('Specific customer endpoint failed, falling back to list:', specificError);
            }
          }

          // Fallback: جيب القائمة كاملة
          list             = await listCustomers(params);
          customers.value  = Array.isArray(list) ? list : [];
          customersFetchedAt.value = nowMs();

          if (id) {
            const found = customers.value.find(c => String(c.id) === String(id));
            return found || null;
          }

          return customers.value;
        } catch (err) {
          console.error('Failed to fetch customers:', err);
          customers.value          = [];
          customersFetchedAt.value = 0;
          return [];
        } finally {
          customersInFlight.value = null;
        }
      })();

      customersInFlight.value = promise;
      const result = await promise;

      return {
        status: 'success',
        data:   result,
        message: null
      };
    } catch (err) {
      console.error('fetchCustomers failed:', err);
      return {
        status: 'error',
        data:   null,
        message: err.response?.data?.message || err.message
      };
    }
  };

  // ─── searchCustomers ──────────────────────────────────────────────────────
  /**
   * يبحث في العملاء عبر الـ API مع caching
   * الـ component يستخدمها هكذا:
   *   const res = await customerStore.searchCustomers(query);
   *   results.value = res.data || [];
   */
  const searchCustomers = async (query, { force = false, params = {} } = {}) => {
    try {
      if (!query || query.length < 2) {
        return {
          status: 'success',
          data:   [],
          message: null
        };
      }

      const cacheParams = Object.keys(params)
        .sort()
        .map(key => `${key}:${params[key]}`)
        .join('|');
      const cacheKey = `${cacheParams}_${query.toLowerCase()}`;
      const cachedAt = searchCacheFetchedAt.value[cacheKey] || 0;

      if (!force && isFresh(cachedAt, TTL.customers) && Array.isArray(searchCache.value[cacheKey])) {
        return {
          status: 'success',
          data:   searchCache.value[cacheKey],
          message: null
        };
      }

      const list    = await listCustomers({ ...params, search: query });
      const results = Array.isArray(list) ? list : [];

      searchCache.value[cacheKey]          = results;
      searchCacheFetchedAt.value[cacheKey] = nowMs();

      return {
        status: 'success',
        data:   results,
        message: null
      };
    } catch (err) {
      console.error('searchCustomers failed:', err);
      const cacheKey = query?.toLowerCase?.() || '';
      searchCache.value[cacheKey]          = [];
      searchCacheFetchedAt.value[cacheKey] = 0;
      return {
        status: 'error',
        data:   null,
        message: err.response?.data?.message || err.message
      };
    }
  };

  // ─── getCustomerById ──────────────────────────────────────────────────────
  /**
   * يرجع العميل من الـ cache المحلي مباشرة (sync)
   * لو محتاج تجيبه من الـ API استخدم fetchCustomers({ id })
   */
  const getCustomerById = (id) =>
    customers.value.find(c => String(c.id) === String(id));

  // ─── createCustomer ───────────────────────────────────────────────────────
  /**
   * ينشئ عميل جديد ويضيفه للـ cache المحلي
   * الـ component يستخدمها هكذا:
   *   const res = await customerStore.createCustomer(payload);
   *   if (res.status === 'success') { ... }
   */
  const createCustomer = async (payload) => {
    try {
      const response = await apiClient.post('/customers', payload);
      const created  = response?.data?.data || response?.data;

      if (created) {
        customers.value.push(created);
        customersFetchedAt.value = nowMs();
      }

      return {
        status: response?.data?.status === 'success' ? 'success' : 'error',
        data:   created,
        message: response?.data?.message
      };
    } catch (err) {
      console.error('createCustomer failed:', err);
      return {
        status: 'error',
        data:   null,
        message: err.response?.data?.message || err.message
      };
    }
  };

  // ─── updateCustomer ───────────────────────────────────────────────────────
  /**
   * يعدّل عميل ويحدّث الـ cache المحلي
   * الـ component يستخدمها هكذا:
   *   const res = await customerStore.updateCustomer(id, payload);
   *   if (res.status === 'success') { ... }
   */
  const updateCustomer = async (id, payload) => {
    try {
      const response = await apiClient.put(`/customers/${id}`, payload);
      const updated  = response?.data?.data || response?.data || {};

      const idx = customers.value.findIndex(c => String(c.id) === String(id));
      if (idx !== -1) customers.value[idx] = { ...customers.value[idx], ...updated };

      delete detailsCache.value[String(id)];
      delete detailsCacheFetchedAt.value[String(id)];

      return {
        status: 'success',
        data:   updated,
        message: null
      };
    } catch (err) {
      console.error('updateCustomer failed:', err);
      return {
        status: 'error',
        data:   null,
        message: err.response?.data?.message || err.message
      };
    }
  };

  // ─── deleteCustomer ───────────────────────────────────────────────────────
  /**
   * يحذف عميل ويمسحه من الـ cache المحلي
   * الـ component يستخدمها هكذا:
   *   const res = await customerStore.deleteCustomer(id);
   *   if (res.status === 'success') { ... }
   */
  const deleteCustomer = async (id) => {
    try {
      await apiClient.delete(`/customers/${id}`);

      customers.value = customers.value.filter(c => String(c.id) !== String(id));
      delete detailsCache.value[String(id)];
      delete detailsCacheFetchedAt.value[String(id)];

      return {
        status: 'success',
        data:   null,
        message: null
      };
    } catch (err) {
      console.error('deleteCustomer failed:', err);
      return {
        status: 'error',
        data:   null,
        message: err.response?.data?.message || err.message
      };
    }
  };

  const recordCustomerPayment = async (customerId, payload, idempotencyKey) => {
    try {
      if (!customerId) {
        return {
          status: 'error',
          data:   null,
          message: 'Customer ID is required'
        };
      }
 
      const response = await apiClient.post(`/customers/${customerId}/payments`, payload, {
        headers: idempotencyKey ? { 'Idempotency-Key': idempotencyKey } : {},
      });
 
      if (response?.data?.status === 'success') {
        customersFetchedAt.value = 0;
        useStatementStore().clearCustomerCache();
        useSalesStore().clearSalesListCache();
        useSalesStore().clearDetailsCache();
        useSalesStore().clearSearchCache();
      }
 
      return {
        status: response?.data?.status === 'success' ? 'success' : 'error',
        data:   response?.data?.data || response?.data || {},
        message: response?.data?.message || null,
        outcomeUnknown: false,
        httpStatus: Number(response?.status || 0),
      };
    } catch (err) {
      console.error('recordCustomerPayment failed:', err);
      return {
        status: 'error',
        data:   null,
        message: err.response?.data?.message || err.message,
        outcomeUnknown: !err.response || err.response.status >= 500,
        httpStatus: Number(err.response?.status || 0),
      };
    }
  };

  // ─── Cache Clearers ───────────────────────────────────────────────────────
  const clear = () => {
    customers.value          = [];
    customersFetchedAt.value = 0;
    customersInFlight.value  = null;
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
    customers,

    // Read Actions
    fetchCustomers,
    searchCustomers,
    getCustomerById,

    // Write Actions
    createCustomer,
    updateCustomer,
    deleteCustomer,
    recordCustomerPayment,

    // Cache
    clear,
    clearSearchCache,
  };
});