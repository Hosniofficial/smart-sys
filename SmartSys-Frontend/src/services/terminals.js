import apiClient from '@/config/axios';

// Terminals Service
// Multi-tenant aware: tenant_id comes from JWT / backend context.
export const terminalsService = {
  async list({ branchId: branch_id = undefined, status = 'active' } = {}) {
    const params = {};
    if (branch_id !== undefined && branch_id !== null && `${branch_id}` !== '') {
      params.branchId = branch_id;
    }
    if (status) params.status = status;
    const res = await apiClient.get('/terminals', { params });
    const root = res?.data || {};
    const data = root.data || root;
    return Array.isArray(data) ? data : (data?.items || []);
  },

  async create(payload) {
    const res = await apiClient.post('/terminals', payload);
    return res?.data || {};
  },

  async update(id, payload) {
    const res = await apiClient.put(`/terminals/${id}`, payload);
    return res?.data || {};
  },
};

export default terminalsService;
