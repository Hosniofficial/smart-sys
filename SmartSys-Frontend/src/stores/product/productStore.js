import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import productsService from '@/services/products';
import inventoryService from '@/services/inventory';

const nowMs = () => Date.now();

export const useProductStore = defineStore('product', () => {

  // ─── Cache TTL ────────────────────────────────────────────────────────────
  const TTL = {
    products:      5  * 60 * 1000, // 5 دقائق
    productSearch: 2  * 60 * 1000, // 2 دقيقة
    inventory:     1  * 60 * 1000, // دقيقة واحدة
    batches:       1  * 60 * 1000, // دقيقة واحدة
    glStatus:      10 * 60 * 1000, // 10 دقائق
  };

  // ─── State ────────────────────────────────────────────────────────────────
  const products          = ref({});
  const productsFetchedAt = ref({});
  const productsInFlight  = ref({});

  const searchCache          = ref({});
  const searchCacheFetchedAt = ref({});

  const inventoryCache          = ref({});
  const inventoryCacheFetchedAt = ref({});

  const batchesCache          = ref({});
  const batchesCacheFetchedAt = ref({});

  const glStatusCache          = ref({});
  const glStatusCacheFetchedAt = ref({});

  // ─── Helpers ──────────────────────────────────────────────────────────────
  const isFresh = (fetchedAt, ttl) =>
    !!fetchedAt && (nowMs() - fetchedAt) < ttl;

  // ─── fetchProducts ────────────────────────────────────────────────────────
  const fetchProducts = async ({ branchId = null, force = false } = {}) => {
    try {
      const cacheKey = branchId ? String(branchId) : 'all';
      const cached   = products.value[cacheKey];
      const cachedAt = productsFetchedAt.value[cacheKey];

      if (!force && cached && isFresh(cachedAt, TTL.products) && Array.isArray(cached) && cached.length) {
        return {
          status: 'success',
          data: cached,
          message: null
        };
      }

      if (!force && productsInFlight.value?.[cacheKey]) {
        const result = await productsInFlight.value[cacheKey];
        return {
          status: 'success',
          data: result,
          message: null
        };
      }

      const promise = (async () => {
        try {
          const params = {};
          if (branchId) params.branch_id = String(branchId);
          
          const response    = await productsService.list(params);
          const list        = response?.data?.data || response?.data || [];
          const newProducts = Array.isArray(list) ? list : (list?.items || []);

          products.value[cacheKey]          = newProducts;
          productsFetchedAt.value[cacheKey] = nowMs();
          return newProducts;
        } finally {
          if (productsInFlight.value?.[cacheKey])
            delete productsInFlight.value[cacheKey];
        }
      })();

      if (!productsInFlight.value) productsInFlight.value = {};
      productsInFlight.value[cacheKey] = promise;
      const result = await promise;
      
      return {
        status: 'success',
        data: result,
        message: null
      };
    } catch (error) {
      console.error('fetchProducts failed:', error);
      return {
        status: 'error',
        data: null,
        message: error.response?.data?.message || error.message
      };
    }
  };

  // ─── searchProducts ───────────────────────────────────────────────────────
  const searchProducts = async ({ query, branchId, categoryId, limit = 50, force = false } = {}) => {
    try {
      if (!query || query.trim().length < 2) {
        return {
          status: 'success',
          data: [],
          message: null
        };
      }

      const q        = query.trim();
      const cacheKey = `${q}_${branchId || 'all'}_${categoryId || 'all'}_${limit}`;
      const cached   = searchCache.value[cacheKey];
      const cachedAt = searchCacheFetchedAt.value[cacheKey];

      if (!force && cached && isFresh(cachedAt, TTL.productSearch)) {
        return {
          status: 'success',
          data: cached,
          message: null
        };
      }

      const params = { q, limit: String(limit) };
      if (branchId)   params.branch_id    = String(branchId);
      if (categoryId) params.category_id  = String(categoryId);

      const response = await productsService.search(params);
      const results  = response?.data?.data || response?.data || [];
      const list     = Array.isArray(results) ? results : (results?.items || []);

      searchCache.value[cacheKey]          = list;
      searchCacheFetchedAt.value[cacheKey] = nowMs();
      
      return {
        status: response?.data?.status === 'success' ? 'success' : 'error',
        data: list,
        message: response?.data?.message
      };
    } catch (error) {
      console.error('searchProducts failed:', error);
      return {
        status: 'error',
        data: null,
        message: error.response?.data?.message || error.message
      };
    }
  };

  // ─── fetchInventoryStock ──────────────────────────────────────────────────
  const fetchInventoryStock = async ({ productId, branchId, force = false } = {}) => {
    if (!productId || !branchId) throw new Error('Product ID and Branch ID are required');

    const cacheKey = `${productId}_${branchId}`;
    const cached   = inventoryCache.value[cacheKey];
    const cachedAt = inventoryCacheFetchedAt.value[cacheKey];

    if (!force && cached && isFresh(cachedAt, TTL.inventory)) return cached;

    const response     = await inventoryService.getProductStock(productId, branchId);
    const data         = response?.data?.data || response?.data || [];
    const inventoryItem = Array.isArray(data)
      ? data.find(item => String(item.id) === String(productId))
      : data;

    inventoryCache.value[cacheKey]          = inventoryItem || null;
    inventoryCacheFetchedAt.value[cacheKey] = nowMs();
    return inventoryItem;
  };

  // ─── fetchProductBatches ──────────────────────────────────────────────────
  const fetchProductBatches = async ({ productId, branchId, force = false } = {}) => {
    if (!productId || !branchId) throw new Error('Product ID and Branch ID are required');

    const cacheKey = `${productId}_${branchId}`;
    const cached   = batchesCache.value[cacheKey];
    const cachedAt = batchesCacheFetchedAt.value[cacheKey];

    if (!force && cached && isFresh(cachedAt, TTL.batches)) return cached;

    const response = await inventoryService.getProductBatches(productId, branchId);
    const data     = response?.data?.data || response?.data || {};
    const batches  = { batches: data.batches || [], serials: data.serials || [] };

    batchesCache.value[cacheKey]          = batches;
    batchesCacheFetchedAt.value[cacheKey] = nowMs();
    return batches;
  };

  // ─── fetchGLStatus ────────────────────────────────────────────────────────
  const fetchGLStatus = async ({ branchId, force = false } = {}) => {
    if (!branchId) throw new Error('Branch ID is required');

    const cacheKey = String(branchId);
    const cached   = glStatusCache.value[cacheKey];
    const cachedAt = glStatusCacheFetchedAt.value[cacheKey];

    if (!force && cached && isFresh(cachedAt, TTL.glStatus)) return cached;

    const response   = await productsService.getBranchStatus(branchId);
    const data       = response?.data?.data || response?.data || {};
    const glProducts = data.products || [];

    glStatusCache.value[cacheKey]          = glProducts;
    glStatusCacheFetchedAt.value[cacheKey] = nowMs();
    return glProducts;
  };

  // ─── activateProductInBranch ──────────────────────────────────────────────
  /**
   * يفعّل منتج في فرع معين
   * الـ component يستخدمها هكذا:
   *   const response = await productStore.activateProductInBranch(productId, branchId);
   *   if (response.status === 'success' || response.data?.status === 'ACTIVE_IN_BRANCH' || !response.status)
   *
   * يعني نرجع response.data مباشرةً { status, data, message, ... }
   */
  const activateProductInBranch = async (productId, branchId) => {
    try {
      if (!productId || !branchId) throw new Error('Product ID and Branch ID are required');

      const response = await productsService.activateInBranch(productId, branchId);

    // امسح GL status cache للفرع + products cache (+ all branches cache)
      const bid = String(branchId);
      delete glStatusCache.value[bid];
      delete glStatusCacheFetchedAt.value[bid];
      delete products.value[bid];
      delete productsFetchedAt.value[bid];
      // امسح 'all' cache حتى تُحدَّث الكميات عند تبديل الفرع
      delete products.value['all'];
      delete productsFetchedAt.value['all'];

      const apiStatus = response?.data?.status;

      return {
        status: (apiStatus === 'success' || apiStatus === 'ACTIVE_IN_BRANCH') ? 'success' : 'error',
        data: response?.data ?? {},
        message: response?.data?.message
      };
    } catch (error) {
      console.error('activateProductInBranch failed:', error);
      return {
        status: 'error',
        data: null,
        message: error.response?.data?.message || error.message
      };
    }
  };

  // ─── postOpeningBalance ───────────────────────────────────────────────────
  /**
   * يرسل رصيد افتتاحي لمنتج في فرع
   * الـ component يستخدمها هكذا:
   *   const response = await productStore.postOpeningBalance({ ... });
   *   if (response.status === 'success') { ... }
   */
  const postOpeningBalance = async (payload) => {
    try {
      const response = await productsService.postOpeningBalance(payload);

      // ✅ استخدام invalidateCacheForBranch للتوحيد
      if (payload?.branch_id) {
        invalidateCacheForBranch(payload.branch_id);
      }

      return {
        status: response?.data?.status === 'success' ? 'success' : 'error',
        data: response?.data ?? {},
        message: response?.data?.message
      };
    } catch (error) {
      console.error('postOpeningBalance failed:', error);
      return {
        status: 'error',
        data: null,
        message: error.response?.data?.message || error.message
      };
    }
  };

  const fetchActiveOpeningBalances = async (branchId) => {
    try {
      if (!branchId) throw new Error('Branch ID is required');
      const response = await productsService.listOpeningBalances(branchId);
      const data = response?.data?.data || response?.data || {};
      return {
        status: response?.data?.status === 'success' ? 'success' : 'error',
        data: data.opening_balances || [],
        message: response?.data?.message,
      };
    } catch (error) {
      console.error('fetchActiveOpeningBalances failed:', error);
      return {
        status: 'error',
        data: [],
        message: error.response?.data?.message || error.message,
      };
    }
  };

  const reverseOpeningBalance = async ({ productId, branchId } = {}) => {
    try {
      if (!productId || !branchId) throw new Error('Product ID and branch ID are required');
      const response = await productsService.reverseOpeningBalance(productId, branchId);
      invalidateCacheForBranch(branchId);
      return {
        status: response?.data?.status === 'success' ? 'success' : 'error',
        data: response?.data?.data || response?.data || {},
        message: response?.data?.message,
      };
    } catch (error) {
      console.error('reverseOpeningBalance failed:', error);
      return {
        status: 'error',
        data: null,
        message: error.response?.data?.message || error.message,
      };
    }
  };

  // ─── deleteProduct ────────────────────────────────────────────────────────
  /**
   * يحذف منتج ويمسح كل الـ cache المرتبط بيه
   * الـ component بيستدعيها بدون انتظار response:
   *   await productStore.deleteProduct(id);
   */
  const deleteProduct = async (id) => {
    try {
      if (!id) throw new Error('Product ID is required');

      const response = await productsService.delete(id);

    // امسح المنتج من كل branch cache
      Object.keys(products.value).forEach(branchKey => {
        const list = products.value[branchKey];
        if (Array.isArray(list)) {
          products.value[branchKey] = list.filter(p => String(p.id) !== String(id));
        }
      });

    // امسح inventory + batches cache للمنتج ده في كل الفروع
      const productStr = String(id);
      [inventoryCache, batchesCache].forEach(cache => {
        Object.keys(cache.value)
          .filter(k => k.startsWith(`${productStr}_`))
          .forEach(k => { delete cache.value[k]; });
      });
      [inventoryCacheFetchedAt, batchesCacheFetchedAt].forEach(cache => {
        Object.keys(cache.value)
          .filter(k => k.startsWith(`${productStr}_`))
          .forEach(k => { delete cache.value[k]; });
      });

      clearSearchCache();

      return {
        status: response?.data?.status === 'success' ? 'success' : 'error',
        data: response?.data ?? {},
        message: response?.data?.message
      };
    } catch (error) {
      console.error('deleteProduct failed:', error);
      return {
        status: 'error',
        data: null,
        message: error.response?.data?.message || error.message
      };
    }
  };

  // ─── createProduct ────────────────────────────────────────────────────────
  const createProduct = async (payload) => {
    try {
      const response = await productsService.create(payload);
      
      const newProduct = response?.data?.data || response?.data;
      if (newProduct) {
        // Clear all branch caches to force refresh
        clear();
      }
      
      return {
        status: response?.data?.status === 'success' ? 'success' : 'error',
        data: newProduct,
        message: response?.data?.message
      };
    } catch (error) {
      console.error('createProduct failed:', error);
      return {
        status: 'error',
        data: null,
        message: error.response?.data?.message || error.message
      };
    }
  };

  // ─── getProductDetail ─────────────────────────────────────────────────────
  /**
   * جلب تفاصيل منتج واحد مع جميع البيانات المتداخلة
   * الـ API ترجع ProductDetailResource بنية متداخلة
   * نرجعها كما هي للـ component الذي سيقوم بتسطيحها
   */
  const getProductDetail = async (productId, branchId = null) => {
    try {
      if (!productId) throw new Error('Product ID is required');

      const response = await productsService.get(productId, branchId);
      const data = response?.data?.data || response?.data;

      return {
        status: response?.data?.status === 'success' ? 'success' : 'error',
        data: data,
        message: response?.data?.message
      };
    } catch (error) {
      console.error('getProductDetail failed:', error);
      return {
        status: 'error',
        data: null,
        message: error.response?.data?.message || error.message
      };
    }
  };

  // ─── Computed Getters ─────────────────────────────────────────────────────
  const getProductById = (id) =>
    computed(() => {
      for (const list of Object.values(products.value)) {
        if (!Array.isArray(list)) continue;
        const found = list.find(p => String(p.id) === String(id));
        if (found) return found;
      }
      return null;
    });

  const getProductsByBranch = (branchId) =>
    computed(() => products.value[String(branchId)] || []);

  const getProductGLStatus = (productId, branchId) =>
    computed(() => {
      const branchStatus = glStatusCache.value[String(branchId)] || [];
      return branchStatus.find(p => String(p.product_id) === String(productId));
    });

  // ─── Cache Clearers ───────────────────────────────────────────────────────
  const clear = () => {
    products.value          = {};
    productsFetchedAt.value = {};
    productsInFlight.value  = {};
    searchCache.value          = {};
    searchCacheFetchedAt.value = {};
    inventoryCache.value          = {};
    inventoryCacheFetchedAt.value = {};
    batchesCache.value          = {};
    batchesCacheFetchedAt.value = {};
    glStatusCache.value          = {};
    glStatusCacheFetchedAt.value = {};
  };

  const clearSearchCache = () => {
    searchCache.value          = {};
    searchCacheFetchedAt.value = {};
  };

  const clearInventoryCache = () => {
    inventoryCache.value          = {};
    inventoryCacheFetchedAt.value = {};
  };

  const clearBatchesCache = () => {
    batchesCache.value          = {};
    batchesCacheFetchedAt.value = {};
  };

  // ─── invalidateCacheForBranch ─────────────────────────────────────────────
  /**
   * يمسح جميع الـ cache المرتبطة بفرع معين
   * يُستخدم بعد أي عملية تؤثر على الكميات (بيع، شراء، مرتجع، تحويل)
   * حتى تُحدّث الكميات تلقائياً في الجدول عند إعادة البحث
   */
  const invalidateCacheForBranch = (branchId) => {
    if (!branchId) return;

    const bid = String(branchId);

    // امسح products list للفرع الخاص
    delete products.value[bid];
    delete productsFetchedAt.value[bid];

    // امسح 'all' branches cache
    delete products.value['all'];
    delete productsFetchedAt.value['all'];

    // امسح search cache (جميع searches متعلقة بالفرع ده)
    Object.keys(searchCache.value).forEach(key => {
      if (key.includes(`_${bid}`) || key.includes('_all')) {
        delete searchCache.value[key];
        delete searchCacheFetchedAt.value[key];
      }
    });

    // امسح inventory cache للفرع ده
    Object.keys(inventoryCache.value).forEach(key => {
      if (key.endsWith(`_${bid}`)) {
        delete inventoryCache.value[key];
        delete inventoryCacheFetchedAt.value[key];
      }
    });

    // امسح batches cache للفرع ده
    Object.keys(batchesCache.value).forEach(key => {
      if (key.endsWith(`_${bid}`)) {
        delete batchesCache.value[key];
        delete batchesCacheFetchedAt.value[key];
      }
    });

    // امسح GL status cache للفرع ده
    delete glStatusCache.value[bid];
    delete glStatusCacheFetchedAt.value[bid];
  };

  // ─── Return ───────────────────────────────────────────────────────────────
  return {
    // State
    products,

    // Read Actions
    fetchProducts,
    searchProducts,
    getProductDetail,
    getProductById,
    getProductsByBranch,
    fetchInventoryStock,
    fetchProductBatches,
    fetchGLStatus,
    getProductGLStatus,

    // Write Actions
    activateProductInBranch,
    postOpeningBalance,
    fetchActiveOpeningBalances,
    reverseOpeningBalance,
    deleteProduct,
    createProduct,

    // Cache
    clear,
    clearSearchCache,
    clearInventoryCache,
    clearBatchesCache,
    invalidateCacheForBranch,  // ← مسح cache لفرع عند تغيير الكميات
    invalidateCache: clear,    // ← alias لـ clear للتوافق مع الاستخدامات الموجودة في الكود
  };
});