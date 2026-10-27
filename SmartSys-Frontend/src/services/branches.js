import apiClient from '@/config/axios';

export const branchesService = {
  list() {
    return apiClient.get('/branches');
  },

  listBranchTransfers(branchId) {
    return apiClient.get(`/branches/${branchId}/transfers`);
  },

  listStockTransfers(branchId) {
    return apiClient.get('/branches/transfers', {
      params: { branch_id: branchId },
    });
  },

  getStockTransfer(transferId) {
    return apiClient.get(`/branches/transfers/${transferId}`);
  },

  create(payload) {
    return apiClient.post('/branches', payload);
  },

  update(id, payload) {
    return apiClient.put(`/branches/${id}`, payload);
  },

  delete(id) {
    return apiClient.delete(`/branches/${id}`);
  },
};

export default branchesService;
