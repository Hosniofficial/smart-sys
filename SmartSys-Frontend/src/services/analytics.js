import apiClient from '../config/axios'

const ANALYTICS_REQUEST_TIMEOUT = 30000

export const analyticsService = {
  // تحليلات المبيعات (مع branch_id إجباري)
  async getSalesAnalytics(startDate, endDate, branchId, sessionId = null, signal = undefined) {
    const params = {
      startDate: startDate,
      endDate: endDate
    }
    if (branchId !== undefined && branchId !== null && branchId !== '') {
      params.branchId = branchId
    }
    if (sessionId) {
      params.sessionId = sessionId
    }
    return await apiClient.get(`/analytics/sales`, {
      params,
      signal,
      timeout: ANALYTICS_REQUEST_TIMEOUT
    })
  },

  async getCashierDashboardSummary(params = {}, signal = undefined) {
    return await apiClient.get('/analytics/cashier/dashboard-summary', {
      params,
      signal,
      timeout: ANALYTICS_REQUEST_TIMEOUT
    })
  },

}

export default analyticsService
