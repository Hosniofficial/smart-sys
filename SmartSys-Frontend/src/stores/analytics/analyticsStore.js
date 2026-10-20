import { defineStore } from 'pinia';
import { ref } from 'vue';
import { analyticsService } from '../../services/analytics';
import { localDateRangeToUTC } from '@/utils';

const nowMs = () => Date.now();

export const useAnalyticsStore = defineStore('analytics', () => {

  // ─── Cache TTL ────────────────────────────────────────────────────────────
  const TTL = {
    sales:     2  * 60 * 1000, // دقيقتان
    daily:     1  * 60 * 1000, // دقيقة واحدة
    dashboard: 1  * 60 * 1000, // دقيقة واحدة
  };

  // ─── State ────────────────────────────────────────────────────────────────
  const salesData           = ref(null);
  const inventoryData       = ref(null);
  const auditLogs           = ref([]);
  const profitLossData      = ref(null);
  const dailyCashData       = ref({});
  const dashboardSummary    = ref({});
  const salesDataCache      = ref({});
  const loading             = ref(false);
  const error               = ref(null);

  // cache timestamps
  const salesCacheAt     = ref({});
  const dailyCacheAt     = ref({});
  const dashboardCacheAt = ref({});

  const isFresh = (ts, ttl) => !!ts && (nowMs() - ts) < ttl;

  // ─── fetchSalesAnalytics ──────────────────────────────────────────────────
  /**
   * يقبل object أو positional args:
   *   fetchSalesAnalytics({ startDate, endDate, sessionId, branchId })
   *   fetchSalesAnalytics(startDate, endDate, sessionId)   ← متوافق مع القديم
   */
  const fetchSalesAnalytics = async (startDateOrObj, endDate, sessionId = null) => {
    // normalize params — يقبل object أو positional
    let startDate, branchId, force, signal;
    if (startDateOrObj && typeof startDateOrObj === 'object' && !Array.isArray(startDateOrObj)) {
      ({ startDate, endDate, sessionId = null, branchId = null, force = false, signal = undefined } = startDateOrObj);
    } else {
      startDate = startDateOrObj;
      branchId  = null;
      force     = false;
    }

    const cacheKey = `${startDate}_${endDate}_${sessionId || 'none'}_${branchId || 'all'}`;
    const cachedAt = salesCacheAt.value[cacheKey] || 0;

    if (!force && salesDataCache.value[cacheKey] && isFresh(cachedAt, TTL.sales)) {
      return { status: 'success', data: salesDataCache.value[cacheKey], message: '' };
    }

    loading.value = true;
    try {
      const useLocalDates = !!sessionId;
      const startUtcIso   = useLocalDates ? startDate : (localDateRangeToUTC(startDate, endDate).startUtcIso || startDate);
      const endUtcIso     = useLocalDates ? endDate   : (localDateRangeToUTC(startDate, endDate).endUtcIso   || endDate);

      const response = await analyticsService.getSalesAnalytics(
        startUtcIso,
        endUtcIso,
        branchId || undefined,
        sessionId || undefined,
        signal
      );
      const data     = response?.data?.data ?? response?.data ?? response;

      salesData.value          = data;
      salesDataCache.value[cacheKey] = data;
      salesCacheAt.value[cacheKey] = nowMs();

      return { status: 'success', data, message: '' };
    } catch (err) {
      error.value = err.message;
      return { status: 'error', data: null, message: err.message || 'فشل تحميل تحليلات المبيعات' };
    } finally {
      loading.value = false;
    }
  };

  // ─── fetchDailyCash ───────────────────────────────────────────────────────
  /**
   * يجيب ملخص النقدية اليومية للفرع
   * الـ component يستخدمها هكذا:
   *   const dailyCash = await analyticsStore.fetchDailyCash(branchId, { force: true });
   */
  const fetchDailyCash = async (branchId = null, { force = false } = {}) => {
    const cacheKey = `daily_${branchId || 'all'}`;
    const cachedAt = dailyCacheAt.value[cacheKey] || 0;

    if (!force && dailyCashData.value[cacheKey] && isFresh(cachedAt, TTL.daily)) {
      return { status: 'success', data: dailyCashData.value[cacheKey], message: '' };
    }

    loading.value = true;
    try {
      // حاول endpoint مخصص للـ daily cash، لو مش موجود fallback
      let data = null;
      try {
        const params = {};
        if (branchId) params.branch_id = String(branchId);
        const response = await analyticsService.getDailyCash
          ? await analyticsService.getDailyCash(params)
          : await analyticsService.getSalesAnalytics(
              new Date().toISOString().split('T')[0] + ' 00:00:00',
              new Date().toISOString().split('T')[0] + ' 23:59:59',
              undefined
            );
        data = response?.data?.data ?? response?.data ?? response;
      } catch {
        data = {};
      }

      dailyCashData.value[cacheKey]  = data;
      dailyCacheAt.value[cacheKey]   = nowMs();

      return { status: 'success', data, message: '' };
    } catch (err) {
      error.value = err.message;
      return { status: 'error', data: null, message: err.message || 'فشل تحميل البيانات اليومية' };
    } finally {
      loading.value = false;
    }
  };

  // ─── fetchDashboardSummary ────────────────────────────────────────────────
  /**
   * يجيب ملخص لوحة التحكم للكاشير
   * الـ component يستخدمها هكذا:
   *   const data = await analyticsStore.fetchDashboardSummary({
   *     branchId, sessionId, startDate, endDate
   *   });
   */
  const fetchDashboardSummary = async ({
    branchId  = null,
    sessionId = null,
    startDate = null,
    endDate   = null,
    force     = false,
    signal    = undefined,
  } = {}) => {
    const cacheKey = `dashboard_${branchId || 'all'}_${sessionId || 'none'}`;
    const cachedAt = dashboardCacheAt.value[cacheKey] || 0;

    if (!force && dashboardSummary.value[cacheKey] && isFresh(cachedAt, TTL.dashboard)) {
      return { status: 'success', data: dashboardSummary.value[cacheKey], message: '' };
    }

    loading.value = true;
    try {
      const params = {};
      if (branchId)  params.branch_id  = String(branchId);
      if (sessionId) params.session_id = String(sessionId);
      if (startDate) params.start_date = startDate;
      if (endDate)   params.end_date   = endDate;

      let data = null;
      try {
        // جرب endpoint مخصص للـ dashboard summary
        const response = analyticsService.getDashboardSummary
          ? await analyticsService.getDashboardSummary(params)
          : await analyticsService.getSalesAnalytics(
              startDate,
              endDate,
              branchId || undefined,
              sessionId || undefined,
              signal
            );
        data = response?.data?.data ?? response?.data ?? response;
      } catch {
        data = {};
      }

      try {
        const activityResponse = await analyticsService.getCashierDashboardSummary(params, signal);
        const activityData = activityResponse?.data?.data ?? activityResponse?.data ?? activityResponse;
        data = {
          ...(data || {}),
          ...(activityData?.totalSales !== undefined ? { totalSales: activityData.totalSales } : {}),
          ...(activityData?.totalReturns !== undefined ? { totalReturns: activityData.totalReturns } : {}),
          ...(activityData?.expenses !== undefined ? { expenses: activityData.expenses } : {}),
          ...(Array.isArray(activityData?.hourly_sales) ? { hourly_sales: activityData.hourly_sales } : {}),
          recentActivities: Array.isArray(activityData?.recentActivities)
            ? activityData.recentActivities
            : [],
          paymentBreakdown: activityData?.paymentBreakdown && typeof activityData.paymentBreakdown === 'object'
            ? activityData.paymentBreakdown
            : (data?.paymentBreakdown || {}),
        };
      } catch {
        data = data || {};
      }

      dashboardSummary.value[cacheKey]  = data;
      dashboardCacheAt.value[cacheKey]  = nowMs();

      return { status: 'success', data, message: '' };
    } catch (err) {
      error.value = err.message;
      return { status: 'error', data: null, message: err.message || 'فشل تحميل ملخص لوحة التحكم' };
    } finally {
      loading.value = false;
    }
  };

  // ─── fetchInventoryAnalytics ──────────────────────────────────────────────
  const fetchInventoryAnalytics = async () => {
    loading.value = true;
    try {
      const response     = await analyticsService.getInventoryAnalytics();
      inventoryData.value = response?.data?.data ?? response?.data ?? response;
      return { status: 'success', data: inventoryData.value, message: '' };
    } catch (err) {
      error.value = err.message;
      return { status: 'error', data: null, message: err.message || 'فشل تحميل تحليلات المخزون' };
    } finally { loading.value = false; }
  };

  // ─── fetchAuditLogs ───────────────────────────────────────────────────────
  const fetchAuditLogs = async (startDate, endDate) => {
    loading.value = true;
    try {
      const response  = await analyticsService.getAuditLogs(startDate, endDate);
      auditLogs.value = response?.data?.data ?? response?.data ?? response;
      return { status: 'success', data: auditLogs.value, message: '' };
    } catch (err) {
      error.value = err.message;
      return { status: 'error', data: null, message: err.message || 'فشل تحميل سجلات التدقيق' };
    } finally { loading.value = false; }
  };

  // ─── fetchProfitLossReport ────────────────────────────────────────────────
  const fetchProfitLossReport = async (startDate, endDate) => {
    loading.value = true;
    try {
      const response      = await analyticsService.getProfitLossReport(startDate, endDate);
      profitLossData.value = response?.data?.data ?? response?.data ?? response;
      return { status: 'success', data: profitLossData.value, message: '' };
    } catch (err) {
      error.value = err.message;
      return { status: 'error', data: null, message: err.message || 'فشل تحميل تقرير الأرباح والخسائر' };
    } finally { loading.value = false; }
  };

  // ─── clearState ───────────────────────────────────────────────────────────
  const clearState = () => {
    salesData.value          = null;
    salesDataCache.value     = {};
    inventoryData.value      = null;
    auditLogs.value          = [];
    profitLossData.value     = null;
    dailyCashData.value      = {};
    dashboardSummary.value   = {};
    salesCacheAt.value       = {};
    dailyCacheAt.value       = {};
    dashboardCacheAt.value   = {};
    loading.value            = false;
    error.value              = null;
  };

  return {
    // State
    salesData,
    inventoryData,
    auditLogs,
    profitLossData,
    loading,
    error,

    // Actions
    fetchSalesAnalytics,
    fetchDailyCash,
    fetchDashboardSummary,
    fetchInventoryAnalytics,
    fetchAuditLogs,
    fetchProfitLossReport,
    clearState,
  };
});