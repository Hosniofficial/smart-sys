import apiClient from '@/config/axios';

export default {
  // جلب جميع طرق الدفع
  async getPaymentMethods() {
    return await apiClient.get('/payment-methods');
  },
  // تحديث طريقة الدفع (النوع + الحساب المحاسبي)
  async updatePaymentMethodKind(id, kind, accountId = null, liquiditySettings = {}) {
    const payload = { kind };
    if (accountId !== undefined) payload.account_id = accountId;
    Object.assign(payload, liquiditySettings);
    return await apiClient.put(`/payment-methods/${id}`, payload);
  },
  // إنشاء طريقة دفع جديدة
  async createPaymentMethod(payload) {
    return await apiClient.post('/payment-methods', payload);
  }
};
