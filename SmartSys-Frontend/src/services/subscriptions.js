import apiClient from '@/config/axios';

export const subscriptionsService = {
  getPublicPlans() {
    return apiClient.get('/plans', {
      meta: { skipLoader: true, suppress402: true },
    });
  },

  getCurrentSubscription() {
    return apiClient.get('/subscription/me', {
      meta: { skipTokenRefresh: true },
    });
  },
};

export default subscriptionsService;
