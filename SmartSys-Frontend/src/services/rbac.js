import apiClient from '@/config/axios';

export const rbacService = {
  listRoles() {
    return apiClient.get('/rbac/roles');
  },

  listUsers(params = {}) {
    return apiClient.get('/rbac/users', { params });
  },

  createUser(payload) {
    return apiClient.post('/rbac/users', payload);
  },

  updateUser(userId, payload) {
    return apiClient.put(`/rbac/users/${userId}`, payload);
  },

  deleteUser(userId) {
    return apiClient.delete(`/rbac/users/${userId}`);
  },

  getUserPermissions(userId) {
    return apiClient.get(`/rbac/users/${userId}/permissions`);
  },

  updateUserPermissions(userId, payload) {
    return apiClient.put(`/rbac/users/${userId}/permissions`, payload);
  },

  listPermissions() {
    return apiClient.get('/rbac/permissions');
  },

  getRolePermissions(roleId) {
    return apiClient.get(`/rbac/roles/${roleId}/permissions`);
  },

  updateRolePermissions(roleId, payload) {
    return apiClient.put(`/rbac/roles/${roleId}/permissions`, payload);
  },
};

export default rbacService;
