import apiClient from '@/config/axios';

export const setupService = {
  save(setupData) {
    return apiClient.post('/setup/save', setupData);
  },

  skip() {
    return apiClient.post('/setup/skip');
  },

  getStatus() {
    return apiClient.get('/setup/status');
  },

  previewOpeningBalance(items) {
    return apiClient.post('/setup/opening-balance/preview', { items });
  },

  commitOpeningBalance(items) {
    return apiClient.post('/setup/opening-balance/commit', { items });
  },
};

export default setupService;
