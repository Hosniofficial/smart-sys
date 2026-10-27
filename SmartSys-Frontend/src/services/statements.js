import apiClient from '@/config/axios';

export const statementsService = {
  getCustomerStatement(customerId, params) {
    return apiClient.get(`/customers/${customerId}/statement`, { params });
  },

  getSupplierStatement(supplierId, params) {
    return apiClient.get(`/suppliers/${supplierId}/statement`, { params });
  },
};

export default statementsService;
