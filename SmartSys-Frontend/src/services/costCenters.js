import apiClient from '@/config/axios';

export function listCostCenters() {
  return apiClient.get('/cost-centers');
}
