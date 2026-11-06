import apiClient from '@/config/axios';

export const catalogService = {
  getCategories(branchId) {
    return apiClient.get('/categories', { params: { branchId: branchId } });
  },

  createCategory(payload) {
    return apiClient.post('/categories', payload);
  },

  updateCategory(categoryId, payload) {
    return apiClient.put(`/categories/${categoryId}`, payload);
  },

  deleteCategory(categoryId, branchId) {
    return apiClient.delete(`/categories/${categoryId}`, {
      params: branchId ? { branchId: branchId } : {},
    });
  },

  getUnits() {
    return apiClient.get('/units');
  },
};

export default catalogService;
