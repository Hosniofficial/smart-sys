import apiClient from '@/config/axios';

const fetchStock = (params) => apiClient.get('/inventory/stock', { params });

export const inventoryService = {
  getProductStock(productId, branchId) {
    return fetchStock({
      productId: String(productId),
      branchId: String(branchId),
    });
  },

  adjustStock(branchId, payload) {
    return apiClient.post(`/branches/${branchId}/adjustments`, payload);
  },

  transferStock(fromBranchId, payload) {
    return apiClient.post(`/branches/${fromBranchId}/transfer`, payload);
  },

  getProductMovements(params) {
    return apiClient.get('/inventory/movements', { params });
  },

  getProductBatches(productId, branchId) {
    return apiClient.get('/inventory/batches', {
      params: { productId: String(productId), branchId: String(branchId) },
    });
  },

  getStockSummary(branchId) {
    return fetchStock({ branchId: String(branchId) });
  },

  getInventorySummary(params) {
    return apiClient.get('/inventory/summary', { params });
  },
};

export default inventoryService;
