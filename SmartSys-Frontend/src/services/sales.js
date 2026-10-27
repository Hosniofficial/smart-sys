import apiClient from '@/config/axios';

export const salesService = {
  listQuotations(params) {
    return apiClient.get('/sales/quotations', { params });
  },

  getQuotation(id) {
    return apiClient.get(`/sales/quotations/${id}`);
  },

  createQuotation(payload) {
    return apiClient.post('/sales/quotations', payload);
  },

  listPriceCampaigns(params) {
    return apiClient.get('/sales/price-campaigns', { params });
  },

  createPriceCampaign(payload) {
    return apiClient.post('/sales/price-campaigns', payload);
  },

  updatePriceCampaignStatus(campaignId, status) {
    return apiClient.patch(`/sales/price-campaigns/${campaignId}/status`, { status });
  },

  list(params) {
    return apiClient.get('/sales', { params });
  },

  get(id) {
    return apiClient.get(`/sales/${id}`);
  },

  create(payload) {
    return apiClient.post('/sales', payload);
  },

  payDebt(payload) {
    return apiClient.post('/sales/pay-debt', payload);
  },

  addPayment(payload, idempotencyKey) {
    return apiClient.post('/sales/add-payment', payload, {
      headers: idempotencyKey ? { 'Idempotency-Key': idempotencyKey } : {},
    });
  },

  void(id, note) {
    return apiClient.delete(`/sales/${id}`, { data: { note } });
  },

  update(id, payload) {
    return apiClient.put(`/sales/${id}`, payload);
  },
};

export default salesService;
