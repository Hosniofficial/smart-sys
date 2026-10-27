import apiClient from '@/config/axios';

export async function listUsers(params = {}) {
  // Backend route is GET /users/list (see api/v1/index.php)
  const { data } = await apiClient.get('/users/list', { params });
  if (Array.isArray(data)) return data;
  if (Array.isArray(data?.data)) return data.data;
  if (Array.isArray(data?.items)) return data.items;
  if (Array.isArray(data?.data?.items)) return data.data.items;
  return [];
}

export async function getCurrentUserProfile() {
  return apiClient.get('/users/me');
}

export async function updateCurrentUserProfile(payload) {
  return apiClient.post('/users/update-profile', payload);
}

export async function changeCurrentUserPassword(payload) {
  return apiClient.post('/users/change-password', payload);
}
