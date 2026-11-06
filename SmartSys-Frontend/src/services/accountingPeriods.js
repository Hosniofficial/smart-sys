import apiClient from '@/config/axios';

export const accountingPeriodsService = {
  list() {
    return apiClient.get('/accounting-periods');
  },

  create(payload) {
    return apiClient.post('/accounting-periods', payload);
  },

  close(id) {
    return apiClient.put(`/accounting-periods/${id}/close`);
  },

  reopen(id) {
    return apiClient.put(`/accounting-periods/${id}/reopen`);
  },

  delete(id) {
    return apiClient.delete(`/accounting-periods/${id}`);
  },
};

export default accountingPeriodsService;
