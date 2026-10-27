import apiClient from '@/config/axios';

export const notificationsService = {
  list(params, { skipLoader = false } = {}) {
    // Increase timeout for notifications to 30 seconds
    return apiClient.get('/notifications', {
      params,
      timeout: 30000,
      ...(skipLoader ? { meta: { skipLoader: true } } : {})
    });
  },

  markAsRead(notificationId) {
    return apiClient.put(`/notifications/${notificationId}/read`);
  },
};

export default notificationsService;
