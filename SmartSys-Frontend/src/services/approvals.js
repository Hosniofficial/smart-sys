import apiClient from '@/config/axios';

export const approvalsService = {
  listPending(params, { skipLoader = false } = {}) {
    return apiClient.get('/sales/pending-approvals', {
      params,
      ...(skipLoader ? { meta: { skipLoader: true } } : {})
    });
  },

  approve(approvalId, payload) {
    return apiClient.post(`/sales/${approvalId}/approve`, payload);
  },

  reject(approvalId, note) {
    return apiClient.post(`/sales/${approvalId}/reject`, { note });
  },
};

export default approvalsService;
