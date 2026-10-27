import apiClient from '@/config/axios';

export async function listCustomers(params = {}) {
  const { data } = await apiClient.get('/customers', { params });
  // Some APIs return {status, data: {items}}; others return array directly
  if (Array.isArray(data)) return data;
  if (Array.isArray(data?.data)) return data.data;
  if (Array.isArray(data?.items)) return data.items;
  if (Array.isArray(data?.data?.items)) return data.data.items;
  return [];
}

export function getCustomer(id) {
  return apiClient.get(`/customers/${id}`);
}

export function createCustomer(payload) {
  return apiClient.post('/customers', payload);
}

export function updateCustomer(id, payload) {
  return apiClient.put(`/customers/${id}`, payload);
}

export function deleteCustomer(id) {
  return apiClient.delete(`/customers/${id}`);
}

export function recordCustomerPayment(customerId, payload, idempotencyKey) {
  return apiClient.post(`/customers/${customerId}/payments`, payload, {
    headers: idempotencyKey ? { 'Idempotency-Key': idempotencyKey } : {},
  });
}
