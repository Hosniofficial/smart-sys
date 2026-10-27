import apiClient from '@/config/axios';

export const ownerContributionsService = {
  listAvailableTenants() {
    return apiClient.get('/admin/owner-contribution-tenants');
  },

  list(params) {
    return apiClient.get('/setup/owner-contributions', { params });
  },

  get(id) {
    return apiClient.get(`/setup/owner-contribution/${id}`);
  },

  create(payload) {
    return apiClient.post('/setup/owner-contribution/create', payload);
  },

  void(id, payload) {
    return apiClient.post(`/setup/owner-contribution/${id}/void`, payload);
  },
};

export default ownerContributionsService;
