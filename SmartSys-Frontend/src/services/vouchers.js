import apiClient from '@/config/axios';

export const vouchersService = {
  list(params) {
    return apiClient.get('/cash-vouchers', { params });
  },

  create(payload, idempotencyKey) {
    return apiClient.post('/cash-vouchers', payload, {
      headers: idempotencyKey ? { 'Idempotency-Key': idempotencyKey } : {},
    });
  },

  update(voucherId, payload) {
    return apiClient.put(`/cash-vouchers/${voucherId}`, payload);
  },

  delete(voucherId) {
    return apiClient.delete(`/cash-vouchers/${voucherId}`);
  },
};

export default vouchersService;
