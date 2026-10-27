import apiClient from '@/config/axios';

export async function listSuppliers(params = {}) {
  const { data } = await apiClient.get('/suppliers', { params });
  if (Array.isArray(data)) return data;
  if (Array.isArray(data?.data)) return data.data;
  if (Array.isArray(data?.items)) return data.items;
  if (Array.isArray(data?.data?.items)) return data.data.items;
  return [];
}

export async function ensureCashSupplier() {
  const { data } = await apiClient.post('/suppliers/cash-default');
  return data;
}

export function createSupplier(payload) {
  return apiClient.post('/suppliers', payload);
}

export function updateSupplier(id, payload) {
  return apiClient.put(`/suppliers/${id}`, payload);
}

export function deleteSupplier(id) {
  return apiClient.delete(`/suppliers/${id}`);
}

export function paySupplierDebt(payload, idempotencyKey) {
  return apiClient.post('/purchases/pay-debt', payload, {
    headers: idempotencyKey ? { 'Idempotency-Key': idempotencyKey } : {},
  });
}
