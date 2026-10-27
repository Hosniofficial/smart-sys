import apiClient from '@/config/axios';

export const accountsService = {
  listGrouped() {
    return apiClient.get('/accounts/grouped');
  },

  search(query) {
    return apiClient.get('/accounts', { params: { q: query } });
  },
};

export default accountsService;
