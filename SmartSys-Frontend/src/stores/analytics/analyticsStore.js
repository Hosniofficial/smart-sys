import { defineStore } from 'pinia';
import { ref } from 'vue';
import { analyticsService } from '../../services/analytics';
import { localDateRangeToUTC } from '@/utils';

const nowMs = () => Date.now();

export const useAnalyticsStore = defineStore('analytics', () => {

  // ─── Cache TTL ────────────────────────────────────────────────────────────
  const TTL = {
    sales:     2  * 60 * 1000, // دقيقتان
    dashboard: 1  * 60 * 1000, // دقيقة واحدة
  };

  // ─── State ────────────────────────────────────────────────────────────────
  const salesData           = ref(null);
  const dashboardSummary    = ref({});
  const salesDataCache      = ref({});
  const loading             = ref(false);
  const error               = ref(null);

  // cache timestamps
  const salesCacheAt     = ref({});
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

      const response = await analyticsService.getCashierDashboardSummary(params, signal);
      const data = response?.data?.data ?? response?.data ?? response;

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

  // ─── clearState ───────────────────────────────────────────────────────────
  const clearState = () => {
    salesData.value          = null;
    salesDataCache.value     = {};
    dashboardSummary.value   = {};
    salesCacheAt.value       = {};
    dashboardCacheAt.value   = {};
    loading.value            = false;
    error.value              = null;
  };

  return {
    // State
    salesData,
    loading,
    error,

    // Actions
    fetchSalesAnalytics,
    fetchDashboardSummary,
    clearState,
  };
});