import { defineStore } from 'pinia'
import { ref } from 'vue'
import { localDateRangeToUTC } from '@/utils/date'
import { reportsService } from '../services/reports'

export const useReportsStore = defineStore('reports', () => {
  // State
  const loading = ref(false)
  const error = ref(null)
  
  // تقرير المبيعات
  const salesData = ref(null)
  const inventoryData = ref(null)
  const auditLogs = ref([])
  const profitLossData = ref(null)
  const inventoryMovements = ref([])
  const inventoryValue = ref(null)

  // Actions
  async function fetchInventoryAnalytics() {
    loading.value = true
    error.value = null
    try {
      const response = await reportsService.getInventoryAnalytics()
      inventoryData.value = response.data
      return {
        status: 'success',
        data: response.data,
        message: ''
      }
    } catch (err) {
      error.value = 'حدث خطأ في تحميل تحليلات المخزون'
      return {
        status: 'error',
        data: null,
        message: err?.message || 'فشل تحميل تحليلات المخزون'
      }
    } finally {
      loading.value = false
    }
  }

  async function fetchAuditLogs(filters = {}) {
    loading.value = true
    error.value = null
    try {
      const { startDate, endDate, actionType, userId, page = 1 } = filters
      // Send local dates directly without UTC conversion (database stores local times)
      const response = await reportsService.getAuditLogs({
        start_date: startDate,
        end_date: endDate,
        action_type: actionType !== 'all' ? actionType : undefined,
        user_id: userId !== 'all' ? userId : undefined,
        page,
        per_page: 50  // Add per_page
      })
      
      // Return the full response including pagination data
      return {
        status: 'success',
        data: response.data,
        message: ''
      }
    } catch (err) {
      error.value = 'حدث خطأ في تحميل سجلات النظام'
      console.error('Error fetching audit logs:', err)
      return {
        status: 'error',
        data: null,
        message: err?.message || 'فشل تحميل سجلات النظام'
      }
    } finally {
      loading.value = false
    }
  }

  async function fetchProfitLossReport(startDate, endDate, mode = 'cogs', expenseSource = 'vouchers') {
    loading.value = true
    error.value = null
    try {
  const { startUtcIso: plStart, endUtcIso: plEnd } = localDateRangeToUTC(startDate, endDate)
  // Pass null for branchId (4th parameter) to use correct mode and expenseSource
  const response = await reportsService.getProfitLossReport(plStart || startDate, plEnd || endDate, null, mode, expenseSource)
      profitLossData.value = response.data
      return {
        status: 'success',
        data: response.data,
        message: ''
      }
    } catch (err) {
      error.value = 'حدث خطأ في تحميل تقرير الأرباح والخسائر'
      return {
        status: 'error',
        data: null,
        message: err?.message || 'فشل تحميل تقرير الأرباح والخسائر'
      }
    } finally {
      loading.value = false
    }
  }

  async function fetchInventoryMovements(startDate, endDate, productId, type, branchId) {
    loading.value = true
    error.value = null
    try {
      // The inventory movements endpoint compares against local database dates.
      const response = await reportsService.getInventoryMovements(startDate, endDate, productId, branchId, type)
      inventoryMovements.value = response.data
      return {
        status: 'success',
        data: response.data,
        message: ''
      }
    } catch (err) {
      error.value = 'حدث خطأ في تحميل حركات المخزون'
      return {
        status: 'error',
        data: null,
        message: err?.message || 'فشل تحميل حركات المخزون'
      }
    } finally {
      loading.value = false
    }
  }

  async function fetchInventoryValue(params = {}) {
    loading.value = true
    error.value = null
    try {
      const response = await reportsService.getInventoryValue(null, params)
      const data = response?.data?.data ?? response?.data ?? response
      inventoryValue.value = data
      return {
        status: 'success',
        data,
        message: ''
      }
    } catch (err) {
      error.value = 'حدث خطأ في تحميل قيمة المخزون'
      console.error('Error fetching inventory value:', err)
      return {
        status: 'error',
        data: null,
        message: err?.message || 'فشل تحميل قيمة المخزون'
      }
    } finally {
      loading.value = false
    }
  }

  async function fetchInventoryValueByBranch(params = {}) {
    loading.value = true
    error.value = null
    try {
      const response = await reportsService.getInventoryValueByBranch(params)
      const data = response?.data?.data ?? response?.data ?? response
      inventoryValue.value = data
      return {
        status: 'success',
        data,
        message: ''
      }
    } catch (err) {
      error.value = 'حدث خطأ في تحميل قيمة المخزون حسب الفرع'
      console.error('Error fetching inventory value by branch:', err)
      return {
        status: 'error',
        data: null,
        message: err?.message || 'فشل تحميل قيمة المخزون حسب الفرع'
      }
    } finally {
      loading.value = false
    }
  }

  // fetchSalesSummary moved to analyticsStore.js for better caching and deduplication

  async function fetchSalesSummary(date, { branchId = null, ...extraParams } = {}) {
    loading.value = true
    error.value = null
    try {
      const response = reportsService.getDailySalesSummary
        ? await reportsService.getDailySalesSummary(date, branchId, extraParams)
        : await reportsService.getSalesSummary(date, branchId)
      const data = response?.data?.data ?? response?.data ?? response
      return {
        status: 'success',
        data,
        message: ''
      }
    } catch (err) {
      error.value = 'حدث خطأ في تحميل ملخص المبيعات'
      return {
        status: 'error',
        data: null,
        message: err?.message || 'فشل تحميل ملخص المبيعات'
      }
    } finally {
      loading.value = false
    }
  }

  async function fetchPosAnalytics(_startDate, _endDate, { branchId = null } = {}) {
    loading.value = true
    error.value = null
    try {
      const response = await reportsService.getPosList(branchId)
      const data = response?.data?.data ?? response?.data ?? response
      return {
        status: 'success',
        data,
        message: ''
      }
    } catch (err) {
      error.value = 'حدث خطأ في تحميل قائمة نقاط البيع'
      return {
        status: 'error',
        data: null,
        message: err?.message || 'فشل تحميل قائمة نقاط البيع'
      }
    } finally {
      loading.value = false
    }
  }

  async function fetchPosPerformance(startDate, endDate, sessionId = null) {
    loading.value = true
    error.value = null
    try {
      // For cashier dashboard, use local date without UTC conversion when session_id is provided
      const useLocalDates = sessionId !== null
      let posStart, posEnd
      
      if (useLocalDates) {
        // Use local dates for session-based queries
        posStart = startDate
        posEnd = endDate
      } else {
        // Use UTC conversion for non-session queries
        const { startUtcIso, endUtcIso } = localDateRangeToUTC(startDate, endDate)
        posStart = startUtcIso || startDate
        posEnd = endUtcIso || endDate
      }
      
      const response = await reportsService.getPosPerformance(posStart, posEnd, sessionId)
      return {
        status: 'success',
        data: response.data,
        message: ''
      }
    } catch (err) {
      error.value = 'حدث خطأ في تحميل أداء نقاط البيع'
      return {
        status: 'error',
        data: null,
        message: err?.message || 'فشل تحميل أداء نقاط البيع'
      }
    } finally {
      loading.value = false
    }
  }

  // fetchTrialBalance wrapper for AccountingReports.vue compatibility
  async function fetchTrialBalance(startDate, endDate, branchId = null) {
    loading.value = true
    error.value = null
    try {
      const response = await reportsService.getTrialBalance(startDate, endDate, branchId)
      const data = response?.data?.data ?? response?.data ?? response
      return {
        status: 'success',
        data,
        message: ''
      }
    } catch (err) {
      error.value = 'حدث خطأ في تحميل ميزان المراجعة'
      return {
        status: 'error',
        data: null,
        message: err?.message || 'فشل تحميل ميزان المراجعة'
      }
    } finally {
      loading.value = false
    }
  }

  // fetchIncomeStatement wrapper for AccountingReports.vue compatibility
  async function fetchIncomeStatement(startDate, endDate, branchId = null) {
    loading.value = true
    error.value = null
    try {
      const response = await reportsService.getIncomeStatement(startDate, endDate, branchId)
      const data = response?.data?.data ?? response?.data ?? response
      return {
        status: 'success',
        data,
        message: ''
      }
    } catch (err) {
      error.value = 'حدث خطأ في تحميل قائمة الدخل'
      return {
        status: 'error',
        data: null,
        message: err?.message || 'فشل تحميل قائمة الدخل'
      }
    } finally {
      loading.value = false
    }
  }

  // fetchBalanceSheet wrapper for AccountingReports.vue compatibility
  async function fetchBalanceSheet(asOfDate, branchId = null) {
    loading.value = true
    error.value = null
    try {
      const response = await reportsService.getBalanceSheet(asOfDate, branchId)
      const data = response?.data?.data ?? response?.data ?? response
      return {
        status: 'success',
        data,
        message: ''
      }
    } catch (err) {
      error.value = 'حدث خطأ في تحميل الميزانية العمومية'
      return {
        status: 'error',
        data: null,
        message: err?.message || 'فشل تحميل الميزانية العمومية'
      }
    } finally {
      loading.value = false
    }
  }

  // fetchBranchAccountCoverage wrapper for AccountingReports.vue compatibility
  async function fetchBranchAccountCoverage(branchId = null) {
    loading.value = true
    error.value = null
    try {
      const response = await reportsService.getBranchAccountCoverage(branchId)
      const data = response?.data?.data ?? response?.data ?? response
      return {
        status: 'success',
        data,
        message: ''
      }
    } catch (err) {
      error.value = 'حدث خطأ في تحميل تغطية الحسابات'
      return {
        status: 'error',
        data: null,
        message: err?.message || 'فشل تحميل تغطية الحسابات'
      }
    } finally {
      loading.value = false
    }
  }

  // fetchArAging — IFRS 9
  async function fetchArAging(asOf = null) {
    loading.value = true
    error.value = null
    try {
      const response = await reportsService.getArAging(asOf)
      const data = response?.data?.data ?? response?.data ?? response
      return { status: 'success', data, message: '' }
    } catch (err) {
      error.value = 'حدث خطأ في تحميل تقرير تقادم الذمم'
      return { status: 'error', data: null, message: err?.message || 'فشل التحميل' }
    } finally { loading.value = false }
  }

  async function postBadDebtProvision(amount, asOf = null) {
    loading.value = true
    error.value = null
    try {
      const response = await reportsService.postBadDebtProvision(amount, asOf)
      const data = response?.data?.data ?? response?.data ?? response
      return { status: 'success', data, message: data?.message || '' }
    } catch (err) {
      const msg = err?.response?.data?.message || err?.message || 'فشل تسجيل المخصص'
      error.value = msg
      return { status: 'error', data: null, message: msg }
    } finally { loading.value = false }
  }

  // fetchCashFlow — IAS 7
  async function fetchCashFlow(startDate, endDate) {
    loading.value = true
    error.value = null
    try {
      const response = await reportsService.getCashFlow(startDate, endDate)
      const data = response?.data?.data ?? response?.data ?? response
      return { status: 'success', data, message: '' }
    } catch (err) {
      error.value = 'حدث خطأ في تحميل قائمة التدفقات'
      return { status: 'error', data: null, message: err?.message || 'فشل التحميل' }
    } finally {
      loading.value = false
    }
  }

  // postNrvWriteDown — IAS 2 auto journal entry
  async function postNrvWriteDown(branchId = null) {
    loading.value = true
    error.value = null
    try {
      const response = await reportsService.postNrvWriteDown(branchId)
      const data = response?.data?.data ?? response?.data ?? response
      return { status: 'success', data, message: data?.message || '' }
    } catch (err) {
      const msg = err?.response?.data?.message || err?.message || 'فشل تسجيل قيد NRV'
      error.value = msg
      return { status: 'error', data: null, message: msg }
    } finally {
      loading.value = false
    }
  }

  // fetchNrvReport — NRV per IAS 2
  async function fetchNrvReport(branchId = null) {
    loading.value = true
    error.value = null
    try {
      const response = await reportsService.getNrvReport(branchId)
      const data = response?.data?.data ?? response?.data ?? response
      return { status: 'success', data, message: '' }
    } catch (err) {
      error.value = 'حدث خطأ في تحميل تقرير NRV'
      return { status: 'error', data: null, message: err?.message || 'فشل تحميل تقرير NRV' }
    } finally {
      loading.value = false
    }
  }

  // Getters
  const isLoading = () => loading.value
  const getError = () => error.value
  const hasError = () => error.value !== null

  return {
    // State
    loading,
    error,
    salesData,
    inventoryData,
    auditLogs,
    profitLossData,
    inventoryMovements,
    inventoryValue,
    

    // Actions
    fetchInventoryAnalytics,
    fetchAuditLogs,
    fetchProfitLossReport,
    fetchInventoryMovements,
    fetchInventoryValue,
    fetchInventoryValueByBranch,
    fetchPosPerformance,
    fetchSalesSummary,
    fetchPosAnalytics,
    fetchTrialBalance,
    fetchIncomeStatement,
    fetchBalanceSheet,
    fetchBranchAccountCoverage,
    fetchArAging,
    postBadDebtProvision,
    fetchCashFlow,
    fetchNrvReport,
    postNrvWriteDown,

    // Getters
    isLoading,
    getError,
    hasError
  }
})
