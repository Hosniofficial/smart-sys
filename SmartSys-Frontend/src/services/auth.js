import apiClient from '@/config/axios';

export const authService = {
  refresh() {
    return apiClient.post(
      '/auth/refresh',
      {},
      {
        meta: { skipLoader: true },
        _isRefreshRequest: true,
      },
    );
  },

  login(formData) {
    return apiClient.post('/auth/login', formData, {
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
      },
    });
  },

  register(userData) {
    return apiClient.post('/auth/register', userData);
  },

  requestPasswordReset(email) {
    return apiClient.post('/auth/forgot-password', { email }, { timeout: 30000 });
  },

  resetPassword(payload) {
    return apiClient.post('/auth/reset-password', payload);
  },

  verifyEmail(token) {
    return apiClient.post('/auth/verify-email', { token });
  },

  resendVerificationEmail(email, purpose = 'registration') {
    return apiClient.post(
      '/auth/verify-email/resend',
      { email, purpose },
      { timeout: 30000 },
    );
  },

  logout() {
    return apiClient.post('/auth/logout', {});
  },

  logoutAllDevices() {
    return apiClient.post('/auth/logout-all-devices', {});
  },
};

export default authService;
