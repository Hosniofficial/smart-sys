import apiClient from '../config/axios'

const ANALYTICS_REQUEST_TIMEOUT = 30000

export const analyticsService = {
  // تحليلات المبيعات (مع branch_id إجباري)
  async getSalesAnalytics(startDate, endDate, branchId, sessionId = null, signal = undefined) {
    const params = { 
      start_date: startDate, 
      end_date: endDate 
    }
    if (branchId !== undefined && branchId !== null && branchId !== '') {
      params.branch_id = branchId
    }
    if (sessionId) {
      params.session_id = sessionId
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
