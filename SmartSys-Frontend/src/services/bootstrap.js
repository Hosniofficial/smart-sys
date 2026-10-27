import apiClient from '@/config/axios';

export const bootstrapService = {
  getPosData() {
    return apiClient.get('/bootstrap/pos');
  },

  getPaymentsData() {
    return apiClient.get('/bootstrap/payments');
  },

  getSessionsData() {
    return apiClient.get('/bootstrap/sessions');
  },

  getManagementData(type) {
    return apiClient.get(`/bootstrap/management/${type}`);
  },
};

export default bootstrapService;
