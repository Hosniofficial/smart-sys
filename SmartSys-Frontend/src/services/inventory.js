import apiClient from '@/config/axios';

const fetchStock = (params) => apiClient.get('/inventory/stock', { params });

export const inventoryService = {
  getProductStock(productId, branchId) {
    return fetchStock({
      product_id: String(productId),
      branch_id: String(branchId),
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
      params: { product_id: String(productId), branch_id: String(branchId) },
    });
  },

  getStockSummary(branchId) {
    return fetchStock({ branch_id: String(branchId) });
  },

  getInventorySummary(params) {
    return apiClient.get('/inventory/summary', { params });
  },
};

export default inventoryService;
