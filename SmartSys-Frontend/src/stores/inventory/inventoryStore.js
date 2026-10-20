import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import apiClient from '@/config/axios';
import { useProductStore } from '@/stores/product/productStore';

const nowMs = () => Date.now();

export const useInventoryStore = defineStore('inventory', () => {

  // ─── Cache TTL ────────────────────────────────────────────────────────────
  const TTL = {
    stock:        1 * 60 * 1000, // دقيقة واحدة
    batches:      1 * 60 * 1000, // دقيقة واحدة
    stockSummary: 2 * 60 * 1000, // دقيقتان
    history:      2 * 60 * 1000, // دقيقتان
  };

  // ─── State ────────────────────────────────────────────────────────────────
  const stockCache          = ref({});
  const stockCacheFetchedAt = ref({});
  const stockInFlight       = ref(null);

  const batchesCache          = ref({});
  const batchesCacheFetchedAt = ref({});

  const summaryCache          = ref({});
  const summaryCacheFetchedAt = ref({});

  const historyCache          = ref({});
  const historyCacheFetchedAt = ref({});

  // ─── Helpers ──────────────────────────────────────────────────────────────
  const isFresh = (fetchedAt, ttl) =>
    !!fetchedAt && (nowMs() - fetchedAt) < ttl;

  /**
   * يقبل camelCase أو snake_case في نفس الوقت
   * fetchStock({ productId, branchId })
   * fetchStock({ product_id, branch_id })   ← من الـ component القديم
   */
  const resolveStockParams = (params) => ({
    productId: params.productId ?? params.product_id,
    branchId:  params.branchId  ?? params.branch_id,
    force:     params.force     ?? false,
  });

  // ─── fetchStock ───────────────────────────────────────────────────────────
  /**
   * الـ component يستخدمها هكذا:
   *   const data = await inventoryStore.fetchStock(params);
   *   // params = { product_id, branch_id } أو { productId, branchId }
   */
  const fetchStock = async (params = {}) => {
    try {
      const { productId, branchId, force } = resolveStockParams(params);
      if (!productId || !branchId) throw new Error('Product ID and Branch ID are required');

      const cacheKey = `${productId}_${branchId}`;
      const cached   = stockCache.value[cacheKey];
      const cachedAt = stockCacheFetchedAt.value[cacheKey];

      if (!force && cached && isFresh(cachedAt, TTL.stock)) {
        return {
          status: 'success',
          data: cached,
          message: null
        };
      }
      if (!force && stockInFlight.value?.[cacheKey]) {
        const result = await stockInFlight.value[cacheKey];
        return {
          status: 'success',
          data: result,
          message: null
        };
      }

      const promise = (async () => {
        try {
          const response  = await apiClient.get('/inventory/stock', {
            params: { product_id: String(productId), branch_id: String(branchId) },
          });
          const data      = response?.data?.data || response?.data || [];
          const stockItem = Array.isArray(data)
            ? data.find(item => String(item.id) === String(productId))
            : data;

          stockCache.value[cacheKey]          = stockItem || null;
          stockCacheFetchedAt.value[cacheKey] = nowMs();
          return stockItem;
        } finally {
          if (stockInFlight.value?.[cacheKey])
            delete stockInFlight.value[cacheKey];
        }
      })();

      if (!stockInFlight.value) stockInFlight.value = {};
      stockInFlight.value[cacheKey] = promise;
      const result = await promise;
      
      return {
        status: 'success',
        data: result,
        message: null
      };
    } catch (err) {
      console.error('fetchStock failed:', err);
      return {
        status: 'error',
        data: null,
        message: err.response?.data?.message || err.message
      };
    }
  };

  // ─── fetchAllStock - for general inventory listing by branch using fetchStock for each product
  const fetchAllStock = async ({ branchId, page = 1, perPage = 50 } = {}) => {
    try {
      if (!branchId) {
        throw new Error('Branch ID is required for fetchAllStock');
      }

      // Import productStore to get products for this branch
      const { useProductStore } = await import('@/stores/product/productStore');
      const productStore = useProductStore();
      
      // Get all products for this branch
      const productsResponse = await productStore.fetchProducts({ branchId });
      if (productsResponse.status !== 'success') {
        throw new Error('Failed to fetch products for inventory');
      }

      const products = productsResponse.data || [];
      const inventoryData = [];

      // Fetch stock for each product individually
      for (const product of products) {
        try {
          const stockResponse = await fetchStock({ 
            productId: product.id, 
            branchId,
            force: false
          });
          
          if (stockResponse.status === 'success') {
            inventoryData.push({
              ...product,
              ...stockResponse.data,
              // Ensure we have product info merged with stock info
              product_id: product.id,
              product_name: product.name,
              branch_id: branchId
            });
          }
        } catch (stockError) {
          console.warn(`Failed to fetch stock for product ${product.id}:`, stockError);
          // Still include product with default stock values
          inventoryData.push({
            ...product,
            product_id: product.id,
            product_name: product.name,
            branch_id: branchId,
            quantity: 0,
            stock_quantity: 0,
            available_quantity: 0
          });
        }
      }

      // Apply pagination if needed
      const startIndex = (page - 1) * perPage;
      const endIndex = startIndex + perPage;
      const paginatedData = inventoryData.slice(startIndex, endIndex);

      return {
        status: 'success',
        data: paginatedData,
        message: null,
        pagination: {
          page,
          perPage,
          total: inventoryData.length,
          totalPages: Math.ceil(inventoryData.length / perPage)
        }
      };
    } catch (err) {
      console.error('fetchAllStock failed:', err);
      return {
        status: 'error',
        data: null,
        message: err.response?.data?.message || err.message
      };
    }
  };

  // ─── adjustStock ──────────────────────────────────────────────────────────
  /**
   * adjusts stock
   * returns standardized response like settingsStore
   */
  const adjustStock = async (payload) => {
    try {
      const branchId = payload?.branch_id ?? payload?.branchId;
      if (!branchId) throw new Error('Branch ID is required for stock adjustment');

      const response = await apiClient.post(`/branches/${branchId}/adjustments`, payload);

      // clear cache for affected product and branch so it fetches from API
      const productId = payload?.product_id ?? payload?.productId;

      // ✅ تحديث تلقائي للكميات — امسح cache المنتجات عند التسويات
      const productStore = useProductStore();
      if (branchId) {
        productStore.invalidateCacheForBranch(branchId);
      }

      if (productId && branchId) {
        clearProductCache(productId, branchId);
      } else if (branchId) {
        clearBranchCache(branchId);
      } else {
        clearStockCache();
      }

      return {
        status: response?.data?.status === 'success' ? 'success' : 'error',
        data: response?.data ?? {},
        message: response?.data?.message
      };
    } catch (err) {
      console.error('adjustStock failed:', err);
      return {
        status: 'error',
        data: null,
        message: err.response?.data?.message || err.message
      };
    }
  };

  // ─── transferStock ──────────────────────────────────────────────────────────
  const transferStock = async ({ product_id, from_branch_id, to_branch_id, quantity, notes = '' }) => {
    try {
      const response = await apiClient.post(`/branches/${from_branch_id}/transfer`, {
        from_branch: from_branch_id,
        to_branch: to_branch_id,
        product_id,
        quantity,
        notes: notes || null
      });
      clearBranchCache(String(from_branch_id));
      clearBranchCache(String(to_branch_id));
      return {
        status: response?.data?.status === 'success' ? 'success' : 'error',
        data: response?.data?.data ?? {},
        message: response?.data?.message
      };
    } catch (err) {
      console.error('transferStock failed:', err);
      return {
        status: 'error',
        data: null,
        message: err.response?.data?.message || err.message
      };
    }
  };

  // ─── getProductHistory ────────────────────────────────────────────────────
  /**
   * gets inventory movement history for a product
   * returns standardized response like settingsStore
   */
  const getProductHistory = async (productId, limit = 10, { branchId = null, force = false } = {}) => {
    try {
      if (!productId) throw new Error('Product ID is required');

      const cacheKey = `${productId}_${branchId || 'all'}_${limit}`;
      const cached   = historyCache.value[cacheKey];
      const cachedAt = historyCacheFetchedAt.value[cacheKey];

      if (!force && cached && isFresh(cachedAt, TTL.history)) {
        return {
          status: 'success',
          data: cached,
          message: null
        };
      }

      const response = await apiClient.get(
        `/inventory/movements`,
        { params: {
          product_id: String(productId),
          limit: String(limit),
          ...(branchId ? { branch_id: String(branchId) } : {})
        } }
      );
      const list = response?.data?.data || response?.data || [];

      historyCache.value[cacheKey]          = list;
      historyCacheFetchedAt.value[cacheKey] = nowMs();

      return {
        status: response?.data?.status === 'success' ? 'success' : 'error',
        data: list,
        message: response?.data?.message
      };
    } catch (err) {
      console.error('getProductHistory failed:', err);
      return {
        status: 'error',
        data: null,
        message: err.response?.data?.message || err.message
      };
    }
  };

  // ─── fetchBatches ─────────────────────────────────────────────────────────
  const fetchBatches = async ({ productId, branchId, force = false } = {}) => {
    try {
      if (!productId || !branchId) throw new Error('Product ID and Branch ID are required');

      const cacheKey = `${productId}_${branchId}`;
      const cached   = batchesCache.value[cacheKey];
      const cachedAt = batchesCacheFetchedAt.value[cacheKey];

      if (!force && cached && isFresh(cachedAt, TTL.batches)) {
        return {
          status: 'success',
          data: cached,
          message: null
        };
      }

      const response = await apiClient.get('/inventory/batches', {
        params: { product_id: String(productId), branch_id: String(branchId) },
      });
      const data    = response?.data?.data || response?.data || {};
      const batches = { batches: data.batches || [], serials: data.serials || [] };

      batchesCache.value[cacheKey]          = batches;
      batchesCacheFetchedAt.value[cacheKey] = nowMs();
      
      return {
        status: response?.data?.status === 'success' ? 'success' : 'error',
        data: batches,
        message: response?.data?.message
      };
    } catch (err) {
      console.error('fetchBatches failed:', err);
      return {
        status: 'error',
        data: null,
        message: err.response?.data?.message || err.message
      };
    }
  };

  // ─── fetchStockSummary ────────────────────────────────────────────────────
  const fetchStockSummary = async ({ branchId, force = false } = {}) => {
    try {
      if (!branchId) throw new Error('Branch ID is required');

      const cacheKey = String(branchId);
      const cached   = summaryCache.value[cacheKey];
      const cachedAt = summaryCacheFetchedAt.value[cacheKey];

      if (!force && cached && isFresh(cachedAt, TTL.stockSummary)) {
        return {
          status: 'success',
          data: cached,
          message: null
        };
      }

      const response = await apiClient.get('/inventory/stock', {
        params: { branch_id: String(branchId) },
      });
      const data    = response?.data?.data || response?.data || [];
      const summary = Array.isArray(data) ? data : (data?.items || []);

      summaryCache.value[cacheKey]          = summary;
      summaryCacheFetchedAt.value[cacheKey] = nowMs();
      
      return {
        status: response?.data?.status === 'success' ? 'success' : 'error',
        data: summary,
        message: response?.data?.message
      };
    } catch (err) {
      console.error('fetchStockSummary failed:', err);
      return {
        status: 'error',
        data: null,
        message: err.response?.data?.message || err.message
      };
    }
  };

  // ─── Computed Getters ─────────────────────────────────────────────────────
  const getStock = (productId, branchId) =>
    computed(() => stockCache.value[`${productId}_${branchId}`]);

  const getBatches = (productId, branchId) =>
    computed(() => batchesCache.value[`${productId}_${branchId}`]);

  const getStockSummary = (branchId) =>
    computed(() => summaryCache.value[String(branchId)] || []);

  const hasStock = (productId, branchId, quantity = 1) =>
    computed(() => {
      const stock = getStock(productId, branchId).value;
      return !!stock && (stock.quantity || 0) >= quantity;
    });

  const getAvailableQuantity = (productId, branchId) =>
    computed(() => getStock(productId, branchId).value?.quantity || 0);

  // ─── Cache Clearers ───────────────────────────────────────────────────────
  const clear = () => {
    stockCache.value          = {};
    stockCacheFetchedAt.value = {};
    stockInFlight.value       = null;
    batchesCache.value          = {};
    batchesCacheFetchedAt.value = {};
    summaryCache.value          = {};
    summaryCacheFetchedAt.value = {};
    historyCache.value          = {};
    historyCacheFetchedAt.value = {};
  };

  const clearStockCache = () => {
    stockCache.value          = {};
    stockCacheFetchedAt.value = {};
    stockInFlight.value       = null;
  };

  const clearBatchesCache = () => {
    batchesCache.value          = {};
    batchesCacheFetchedAt.value = {};
  };

  const clearSummaryCache = () => {
    summaryCache.value          = {};
    summaryCacheFetchedAt.value = {};
  };

  const clearProductCache = (productId, branchId) => {
    const cacheKey = `${productId}_${branchId}`;
    delete stockCache.value[cacheKey];
    delete stockCacheFetchedAt.value[cacheKey];
    delete batchesCache.value[cacheKey];
    delete batchesCacheFetchedAt.value[cacheKey];
    // امسح history cache للمنتج ده بكل الـ limits
    Object.keys(historyCache.value)
      .filter(k => k.startsWith(`${productId}_`))
      .forEach(k => {
        delete historyCache.value[k];
        delete historyCacheFetchedAt.value[k];
      });
  };

  // ─── fetchInventorySummary ─────────────────────────────────────────────────────────
  const summaryKpiCache          = ref({});
  const summaryKpiCacheFetchedAt = ref({});
  const TTL_SUMMARY_KPI = 30 * 1000; // 30 seconds

  const fetchInventorySummary = async ({ branchId = null, force = false } = {}) => {
    try {
      const cacheKey = branchId ? String(branchId) : 'all';
      const cached   = summaryKpiCache.value[cacheKey];
      const cachedAt = summaryKpiCacheFetchedAt.value[cacheKey];

      if (!force && cached && isFresh(cachedAt, TTL_SUMMARY_KPI)) {
        return { status: 'success', data: cached };
      }

      const params = {};
      if (branchId) params.branch_id = String(branchId);

      const response = await apiClient.get('/inventory/summary', { params });
      const data = response?.data?.data || response?.data || {};

      summaryKpiCache.value[cacheKey]          = data;
      summaryKpiCacheFetchedAt.value[cacheKey] = nowMs();

      return { status: 'success', data };
    } catch (err) {
      console.error('fetchInventorySummary failed:', err);
      return { status: 'error', data: null, message: err.response?.data?.message || err.message };
    }
  };

  const clearBranchCache = (branchId) => {
    const bid = String(branchId);
    [stockCache, batchesCache].forEach(cache => {
      Object.keys(cache.value)
        .filter(k => k.endsWith(`_${bid}`))
        .forEach(k => { delete cache.value[k]; });
    });
    [stockCacheFetchedAt, batchesCacheFetchedAt].forEach(cache => {
      Object.keys(cache.value)
        .filter(k => k.endsWith(`_${bid}`))
        .forEach(k => { delete cache.value[k]; });
    });
    delete summaryCache.value[bid];
    delete summaryCacheFetchedAt.value[bid];
  };

  // ─── Return ───────────────────────────────────────────────────────────────
  return {
    // State
    stockCache,
    batchesCache,
    summaryCache,

    // Read Actions
    fetchStock,
    fetchAllStock,
    fetchBatches,
    fetchStockSummary,
    getStock,
    getBatches,
    getStockSummary,
    hasStock,
    getAvailableQuantity,
    getProductHistory,
    fetchInventorySummary,

    // Write Actions
    adjustStock,
    transferStock,

    // Cache
    clear,
    clearStockCache,
    clearBatchesCache,
    clearSummaryCache,
    clearProductCache,
    clearBranchCache,
  };
});