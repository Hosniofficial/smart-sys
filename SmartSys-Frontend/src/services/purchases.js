import apiClient from '@/config/axios';

export const purchasesService = {
  list(params) {
    return apiClient.get('/purchases', { params });
  },

  get(id, signal) {
    return apiClient.get(`/purchases/${id}`, { signal });
  },

  create(payload, idempotencyKey) {
    return apiClient.post('/purchases', payload, {
      headers: { 'Idempotency-Key': idempotencyKey },
      timeout: 60000,
    });
  },

  update(id, payload) {
    return apiClient.put(`/purchases/${id}`, payload);
  },

  delete(id) {
    return apiClient.delete(`/purchases/${id}`);
  },

  getNextInvoiceNumber() {
    return apiClient.get('/purchases/next-invoice-number');
  },

  addPayment(id, payload, idempotencyKey) {
    return apiClient.post(`/purchases/${id}/payments`, payload, {
      headers: idempotencyKey ? { 'Idempotency-Key': idempotencyKey } : {},
    });
  },
};

export default purchasesService;
