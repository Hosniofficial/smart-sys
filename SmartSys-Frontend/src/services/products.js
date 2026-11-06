import apiClient from '@/config/axios';

export const productsService = {
  list(params) {
    return apiClient.get('/products', { params });
  },

  search(params) {
    return apiClient.get('/products/search', { params });
  },

  getBranchStatus(branchId) {
    return apiClient.get('/product-branch/status', {
      params: { branchId: String(branchId) },
    });
  },

  activateInBranch(productId, branchId) {
    return apiClient.post('/product-branch/activate', {
      productId: productId,
      branchId: Number(branchId),
    });
  },

  postOpeningBalance(payload) {
    return apiClient.post('/product-branch/opening-balance/post', payload);
  },

  listOpeningBalances(branchId) {
    return apiClient.get('/product-branch/opening-balances', {
      params: { branchId: String(branchId) },
    });
  },

  reverseOpeningBalance(productId, branchId) {
    return apiClient.post('/product-branch/opening-balance/reverse', {
      productId: Number(productId),
      branchId: Number(branchId),
    });
  },

  delete(id) {
    return apiClient.delete(`/products/${id}`);
  },

  create(payload) {
    return apiClient.post('/products', payload);
  },

  update(id, payload) {
    return apiClient.put(`/products/${id}`, payload);
  },

  get(id, branchId) {
    return apiClient.get(`/products/${id}`, {
      params: branchId ? { branchId: String(branchId) } : undefined,
    });
  },
};

export default productsService;
