import { defineStore } from 'pinia';
import { ref } from 'vue';
import statementsService from '@/services/statements';

const nowMs = () => Date.now();

export const useStatementStore = defineStore('statement', () => {

  // Cache TTL
  const TTL = {
    statements: 5 * 60 * 1000, // 5 دقائق
  };

  const customerStatements          = ref({});
  const customerStatementsMeta      = ref({});
  const customerStatementsFetchedAt = ref({});

  const supplierStatements          = ref({});
  const supplierStatementsMeta      = ref({});
  const supplierStatementsFetchedAt = ref({});

  const isFresh = (fetchedAt, ttl) =>
    !!fetchedAt && (nowMs() - fetchedAt) < ttl;

  // fetchCustomerStatement
  /**
   * يجيب كشف حساب عميل معين مع caching
   * الـ component يستخدمها هكذا:
   *   const res = await statementStore.fetchCustomerStatement(customerId, params);
   *   if (res.status === 'success') { statement.value = res.data; }
   */
  const fetchCustomerStatement = async (customerId, params = {}, { force = false } = {}) => {
    try {
      if (!customerId) {
        return {
          status: 'error',
          data:   null,
          message: 'Customer ID is required'
        };
      }

      const cacheKey = `${customerId}_${JSON.stringify(params)}`;
      const cached   = customerStatements.value[cacheKey];
      const cachedAt = customerStatementsFetchedAt.value[cacheKey];

      if (!force && cached && isFresh(cachedAt, TTL.statements)) {
        return {
          status: 'success',
          data:   cached,
          meta:   customerStatementsMeta.value[cacheKey] || {},
          message: null
        };
      }

      const response  = await statementsService.getCustomerStatement(customerId, params);
      const statement = response?.data?.data || null;
      const meta = response?.data?.meta || {};

      customerStatements.value[cacheKey]          = statement;
      customerStatementsMeta.value[cacheKey]      = meta;
      customerStatementsFetchedAt.value[cacheKey] = nowMs();

      return {
        status: response?.data?.status === 'success' ? 'success' : 'error',
        data:   statement,
        meta,
        message: response?.data?.message || null
      };
    } catch (err) {
      console.error('fetchCustomerStatement failed:', err);
      return {
        status: 'error',
        data:   null,
        meta:   {},
        message: err.response?.data?.message || err.message
      };
    }
  };

  // fetchSupplierStatement
  /**
   * يجيب كشف حساب مورد معين مع caching
   * الـ component يستخدمها هكذا:
   *   const res = await statementStore.fetchSupplierStatement(supplierId, params);
   *   if (res.status === 'success') { statement.value = res.data; }
   */
  const fetchSupplierStatement = async (supplierId, params = {}, { force = false } = {}) => {
    try {
      if (!supplierId) {
        return {
          status: 'error',
          data:   null,
          message: 'Supplier ID is required'
        };
      }

      const cacheKey = `${supplierId}_${JSON.stringify(params)}`;
      const cached   = supplierStatements.value[cacheKey];
      const cachedAt = supplierStatementsFetchedAt.value[cacheKey];

      if (!force && cached && isFresh(cachedAt, TTL.statements)) {
        return {
          status: 'success',
          data:   cached,
          meta:   supplierStatementsMeta.value[cacheKey] || {},
          message: null
        };
      }

      const response  = await statementsService.getSupplierStatement(supplierId, params);
      const statement = response?.data?.data || null;
      const meta = response?.data?.meta || {};

      supplierStatements.value[cacheKey]          = statement;
      supplierStatementsMeta.value[cacheKey]      = meta;
      supplierStatementsFetchedAt.value[cacheKey] = nowMs();

      return {
        status: response?.data?.status === 'success' ? 'success' : 'error',
        data:   statement,
        meta,
        message: response?.data?.message || null
      };
    } catch (err) {
      console.error('fetchSupplierStatement failed:', err);
      return {
        status: 'error',
        data:   null,
        meta:   {},
        message: err.response?.data?.message || err.message
      };
    }
  };

  // Cache Clearers
  const clear = () => {
    customerStatements.value          = {};
    customerStatementsMeta.value      = {};
    customerStatementsFetchedAt.value = {};
    supplierStatements.value          = {};
    supplierStatementsMeta.value      = {};
    supplierStatementsFetchedAt.value = {};
  };

  const clearCustomerCache = () => {
    customerStatements.value          = {};
    customerStatementsMeta.value      = {};
    customerStatementsFetchedAt.value = {};
  };

  const clearSupplierCache = () => {
    supplierStatements.value          = {};
    supplierStatementsMeta.value      = {};
    supplierStatementsFetchedAt.value = {};
  };

  // Return
  return {
    // State
    customerStatements,
    supplierStatements,

    // Read Actions
    fetchCustomerStatement,
    fetchSupplierStatement,

    // Cache
    clear,
    clearCustomerCache,
    clearSupplierCache,
  };
});