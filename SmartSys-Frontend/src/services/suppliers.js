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
