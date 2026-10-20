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

  // تحليلات المخزون (مع branch_id إجباري)
  async getInventoryAnalytics(branchId) {
    const params = {}
    if (branchId !== undefined && branchId !== null && branchId !== '') {
      params.branch_id = branchId
    }
    return await apiClient.get(`/analytics/inventory`, { params })
  },

  // سجلات التدقيق (مع branch_id اختياري للتفصيل أكثر)
  async getAuditLogs(startDate, endDate, branchId = null) {
    const params = { 
      start_date: startDate, 
      end_date: endDate 
    }
    if (branchId !== undefined && branchId !== null && branchId !== '') {
      params.branch_id = branchId
    }
    return await apiClient.get(`/audit/logs`, { params })
  },

  // تقرير الأرباح والخسائر (مع branch_id إجباري)
  async getProfitLossReport(startDate, endDate, branchId) {
    const params = { 
      start_date: startDate, 
      end_date: endDate 
    }
    if (branchId !== undefined && branchId !== null && branchId !== '') {
      params.branch_id = branchId
    }
    return await apiClient.get(`/reports/profit-loss`, { params })
  },

}

export default analyticsService
