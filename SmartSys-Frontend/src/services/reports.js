import apiClient from '../config/axios'

const REPORT_TIMEOUT = 30000

export const reportsService = {
  // تحليلات المخزون (مع branch_id إجباري)
  async getInventoryAnalytics(branchId, signal) {
    const params = {}
    if (branchId !== undefined && branchId !== null && branchId !== '') {
      params.branch_id = branchId
    }
    return await apiClient.get('/analytics/inventory', { params, signal })
  },

  // سجلات النظام (مع branch_id اختياري)
  async getAuditLogs(params = {}) {
    // Remove undefined parameters
    const cleanParams = Object.entries(params).reduce((acc, [key, value]) => {
      if (value !== undefined && value !== '') {
        acc[key] = value
      }
      return acc
    }, {})
    
    return await apiClient.get('/audit/logs', { params: cleanParams })
  },

  // تقرير الأرباح والخسائر (مع branch_id اختياري)
  async getProfitLossReport(startDate, endDate, branchId, mode = 'cogs', expenseSource = 'vouchers') {
    const params = {
      start_date: startDate,
      end_date: endDate,
      mode,
      expense_source: expenseSource
    }
    if (branchId !== undefined && branchId !== null && branchId !== '') {
      params.branch_id = branchId
    }
    return await apiClient.get('/reports/profit-loss', { params, timeout: REPORT_TIMEOUT })
  },

  // حركات المخزون (مع branch_id اختياري)
  async getInventoryMovements(startDate, endDate, productId, branchId, type) {
    const params = {
      start_date: startDate,
      end_date: endDate,
      product_id: productId
    }
    if (branchId !== undefined && branchId !== null && branchId !== '' && branchId !== 'all') {
      params.branch_id = branchId
    }
    if (type && type !== 'all') {
      params.type = type
    }
    return await apiClient.get('/branches/reports/inventory/movements', { params, timeout: REPORT_TIMEOUT })
  },

  // قيمة المخزون (مع branch_id اختياري)
  async getInventoryValue(branchId, params = {}) {
    const queryParams = { ...params }
    if (branchId !== undefined && branchId !== null && branchId !== '') {
      queryParams.branch_id = branchId
    }
    return await apiClient.get('/branches/reports/inventory-value', { params: queryParams, timeout: REPORT_TIMEOUT })
  },

  // قيمة المخزون حسب الفرع
  async getInventoryValueByBranch(params = {}) {
    return await apiClient.get('/branches/reports/inventory-value/by-branch', { params, timeout: REPORT_TIMEOUT })
  },

  // ملخص المبيعات اليومي (يتضمن COGS و Gross Profit عبر WAC)
  async getDailySalesSummary(date, branchId, extraParams = {}) {
    const params = { date, ...extraParams }
    if (branchId !== undefined && branchId !== null && branchId !== '') {
      params.branch_id = branchId
    }
    return await apiClient.get('/reports/sales/daily-summary', { params })
  },

  // قائمة نقاط البيع لاستخدامها كفلاتر
  async getPosList(branchId, params = {}) {
    const queryParams = { ...params }
    if (branchId !== undefined && branchId !== null && branchId !== '') {
      queryParams.branch_id = branchId
    }
    return await apiClient.get('/reports/pos', { params: queryParams })
  },
 
  // ================= Accounting Reports =================
  async getTrialBalance(startDate, endDate, branchId) {
    const params = {}
    if (startDate) params.start_date = startDate
    if (endDate) params.end_date = endDate
    if (branchId !== undefined && branchId !== null && branchId !== '') {
      params.branch_id = branchId
    }
    return await apiClient.get('/reports/accounting/trial-balance', { params, timeout: REPORT_TIMEOUT })
  },

  async getLedger(accountId, startDate, endDate, branchId) {
    const params = {}
    if (startDate) params.start_date = startDate
    if (endDate) params.end_date = endDate
    if (branchId !== undefined && branchId !== null && branchId !== '') {
      params.branch_id = branchId
    }
    return await apiClient.get(`/reports/accounting/ledger/${accountId}`, { params, timeout: REPORT_TIMEOUT })
  },

  async getIncomeStatement(startDate, endDate, branchId) {
    const params = {}
    if (startDate) params.start_date = startDate
    if (endDate) params.end_date = endDate
    if (branchId !== undefined && branchId !== null && branchId !== '') {
      params.branch_id = branchId
    }
    return await apiClient.get('/reports/accounting/income-statement', { params, timeout: REPORT_TIMEOUT })
  },

  async getBalanceSheet(asOfDate, branchId) {
    const params = {}
    if (asOfDate) params.as_of_date = asOfDate
    if (branchId !== undefined && branchId !== null && branchId !== '') {
      params.branch_id = branchId
    }
    return await apiClient.get('/reports/accounting/balance-sheet', { params, timeout: REPORT_TIMEOUT })
  },

  async getBranchAccountCoverage(branchId) {
    const params = {}
    if (branchId !== undefined && branchId !== null && branchId !== '') {
      params.branch_id = branchId
    }
    return await apiClient.get('/branches/reports/account-coverage', { params, timeout: REPORT_TIMEOUT })
  },

  // أداء نقاط البيع (POS Performance)
  async getPosPerformance(startDate, endDate, branchId, sessionId = null, signal = null) {
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
    return await apiClient.get('/analytics/pos-performance', { params, signal })
  },

  async getArAging(asOf = null, branchId = null) {
    const params = {}
    if (asOf) params.as_of = asOf
    if (branchId !== undefined && branchId !== null && branchId !== '') {
      params.branch_id = branchId
    }
    return await apiClient.get('/reports/accounting/ar-aging', { params })
  },

  async postBadDebtProvision(amount, asOf = null) {
    const body = { amount }
    if (asOf) body.as_of = asOf
    return await apiClient.post('/reports/accounting/ar-aging/post-provision', body)
  },

  async getCashFlow(startDate, endDate, branchId) {
    const params = {}
    if (startDate) params.start_date = startDate
    if (endDate)   params.end_date   = endDate
    if (branchId !== undefined && branchId !== null && branchId !== '') {
      params.branch_id = branchId
    }
    return await apiClient.get('/reports/accounting/cash-flow', { params })
  },

  async getNrvReport(branchId = null) {
    const params = {}
    if (branchId) params.branch_id = branchId
    return await apiClient.get('/reports/accounting/nrv', { params })
  },

  async postNrvWriteDown(branchId = null) {
    const body = branchId ? { branch_id: branchId } : {}
    return await apiClient.post('/reports/accounting/nrv/post-writedown', body)
  }
}
