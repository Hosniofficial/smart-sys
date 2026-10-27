import apiClient from '@/config/axios';

export const adminService = {
  listSubscriptions(params) {
    return apiClient.get('/admin/subscriptions', { params });
  },

  activateSubscription(id, body) {
    return apiClient.post(`/admin/subscriptions/${id}/activate`, body);
  },

  expireSubscription(id) {
    return apiClient.post(`/admin/subscriptions/${id}/expire`);
  },

  extendSubscription(id, days) {
    return apiClient.post(`/admin/subscriptions/${id}/extend`, { days });
  },

  changeSubscriptionPlan(id, body) {
    return apiClient.post(`/admin/subscriptions/${id}/change-plan`, body);
  },

  refreshSubscriptionSecurity(id) {
    return apiClient.post(`/admin/subscriptions/${id}/security-check`);
  },

  blockSubscription(id) {
    return apiClient.post(`/admin/subscriptions/${id}/block`);
  },

  listPlans() {
    return apiClient.get('/admin/plans');
  },

  createPlan(payload) {
    return apiClient.post('/admin/plans', payload);
  },

  updatePlan(code, payload) {
    return apiClient.put(`/admin/plans/${code}`, payload);
  },

  deletePlan(code) {
    return apiClient.delete(`/admin/plans/${code}`);
  },

  listCustomersMissingAccounts() {
    return apiClient.get('/customers/missing-accounts');
  },

  listSuppliersMissingAccounts() {
    return apiClient.get('/suppliers/missing-accounts');
  },

  ensureCustomerAccount(customerId) {
    return apiClient.post(`/customers/${customerId}/ensure-account`);
  },

  ensureSupplierAccount(supplierId) {
    return apiClient.post(`/suppliers/${supplierId}/ensure-account`);
  },
};

export default adminService;
