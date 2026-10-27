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
      params: { branch_id: String(branchId) },
    });
  },

  activateInBranch(productId, branchId) {
    return apiClient.post('/product-branch/activate', {
      product_id: productId,
      branch_id: Number(branchId),
    });
  },

  postOpeningBalance(payload) {
    return apiClient.post('/product-branch/opening-balance/post', payload);
  },

  listOpeningBalances(branchId) {
    return apiClient.get('/product-branch/opening-balances', {
      params: { branch_id: String(branchId) },
    });
  },

  reverseOpeningBalance(productId, branchId) {
    return apiClient.post('/product-branch/opening-balance/reverse', {
      product_id: Number(productId),
      branch_id: Number(branchId),
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
      params: branchId ? { branch_id: String(branchId) } : undefined,
    });
  },
};

export default productsService;
