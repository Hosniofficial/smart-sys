import { defineStore } from 'pinia';
import { ref } from 'vue';
import { bulkAdjustFromCsv, bulkAdjustProduct } from '@/services/BulkAdjustmentsService';
import apiClient from '@/config/axios';

const nowMs = () => Date.now();

export const useBulkAdjustmentStore = defineStore('bulkAdjustment', () => {
  // ─── State ────────────────────────────────────────────────────────
  const products = ref([]);
  const productsCache = ref({});
  const productsCacheTTL = 5 * 60 * 1000; // 5 minutes
  const inFlightRequests = ref({});

  // ─── Search Products ──────────────────────────────────────────────
  const searchProducts = async (query = '') => {
    const normalizedQuery = query.trim();
    const cacheKey = `search_${normalizedQuery || 'all'}`;

    if (inFlightRequests.value[cacheKey]) {
      return inFlightRequests.value[cacheKey];
    }

    const cached = productsCache.value[cacheKey];
    if (cached && nowMs() - cached.timestamp < productsCacheTTL) {
      return { status: 'success', data: cached.data, message: null };
    }

    const promise = (async () => {
      try {
        const { data } = await apiClient.get('/products/search', {
          params: normalizedQuery ? { q: normalizedQuery, limit: 10 } : {},
        });
        const results = data?.data ?? data;
        const items = (Array.isArray(results) ? results : (results?.items || []))
          .map(product => ({
            ...product,
            stock: Number(product.stock ?? product.current_quantity ?? product.quantity ?? 0),
          }));
        const result = {
          status: 'success',
          data: items,
          message: null
        };

        productsCache.value[cacheKey] = {
          data: result.data,
          timestamp: nowMs()
        };

        return result;
      } catch (error) {
        console.error('Bulk product search failed:', error);
        return {
          status: 'error',
          data: [],
          message: error?.response?.data?.message || error?.message || 'Failed to search products'
        };
      } finally {
        delete inFlightRequests.value[cacheKey];
      }
    })();

    inFlightRequests.value[cacheKey] = promise;
    return promise;
  };

  // ─── Bulk Adjust Product ──────────────────────────────────────────
  const adjustProduct = async (productId, adjustments) => {
    try {
      const data = await bulkAdjustProduct(productId, adjustments);
      if (data?.status !== 'success') {
        throw new Error(data?.message || 'Failed to adjust product');
      }
      clearCache();

      return {
        status: 'success',
        data: data?.data || data,
        message: data?.message || null
      };
    } catch (error) {
      console.error('Bulk product adjustment failed:', error);
      return {
        status: 'error',
        data: null,
        message: error?.response?.data?.message || error?.message || 'Failed to adjust product'
      };
    }
  };

  // ─── Bulk Adjust from CSV ─────────────────────────────────────────
  const adjustFromCsv = async (csvFile, defaultProductId) => {
    try {
      const data = await bulkAdjustFromCsv(csvFile, defaultProductId);
      if (data?.status !== 'success') {
        throw new Error(data?.message || 'Failed to import CSV');
      }
      clearCache();

      return {
        status: 'success',
        data: data?.data || data,
        message: data?.message || null,
        summary: data?.summary || data?.data?.summary || {}
      };
    } catch (error) {
      console.error('Bulk CSV adjustment failed:', error);
      return {
        status: 'error',
        data: null,
        message: error?.response?.data?.message || error?.message || 'Failed to import CSV',
        summary: {}
      };
    }
  };

  // ─── Clear Cache ──────────────────────────────────────────────────
  const clearCache = () => {
    productsCache.value = {};
    inFlightRequests.value = {};
  };

  return {
    products,
    searchProducts,
    adjustProduct,
    adjustFromCsv,
    clearCache
  };
});
