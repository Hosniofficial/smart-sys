import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import approvalsService from '@/services/approvals';

const nowMs = () => Date.now();

export const useApprovalsStore = defineStore('approvals', () => {
  // ─── State ────────────────────────────────────────────────────────
  const pendingApprovals = ref([]);
  const approvalCache = ref(null);
  const approvalCacheTTL = 2 * 60 * 1000; // 2 minutes
  const inFlightRequests = ref({});
  const currentPage = ref(1);
  const totalPages = ref(1);
  const loading = ref(false);
  const error = ref(null);

  // ─── Computed ─────────────────────────────────────────────────────
  const totalApprovals = computed(() => pendingApprovals.value.length);
  const approvalsByStatus = computed(() => {
    return {
      pending: pendingApprovals.value.filter(a => a.status === 'pending'),
      approved: pendingApprovals.value.filter(a => a.status === 'approved'),
      rejected: pendingApprovals.value.filter(a => a.status === 'rejected')
    };
  });

  // ─── List Pending Approvals ───────────────────────────────────────
  const listPending = async (params = {}, options = {}) => {
    const cacheKey = `pending_${params.page || 1}_${params.limit || 20}_${params.branch_id || 'all'}`;
    
    if (inFlightRequests.value[cacheKey]) {
      return inFlightRequests.value[cacheKey];
    }

    if (approvalCache.value && nowMs() - approvalCache.value.timestamp < approvalCacheTTL) {
      return {
        status: 'success',
        data: approvalCache.value.data,
        message: null,
        pagination: approvalCache.value.pagination
      };
    }

    const promise = (async () => {
      loading.value = true;
      error.value = null;
      try {
        const { data } = await approvalsService.listPending(params, options);

        const inner = data?.data;
        const items = Array.isArray(inner)
          ? inner
          : Array.isArray(inner?.items) ? inner.items : [];
        const pagination = inner?.pagination || data?.pagination || {};

        const result = {
          status: 'success',
          data: items,
          message: null,
          pagination
        };

        pendingApprovals.value = result.data;
        totalPages.value = pagination?.last_page || pagination?.total_pages || 1;
        currentPage.value = pagination?.current_page || 1;

        approvalCache.value = {
          data: result.data,
          pagination: result.pagination,
          timestamp: nowMs()
        };

        return result;
      } catch (err) {
        error.value = err?.response?.data?.message || 'Failed to fetch approvals';
        return {
          status: 'error',
          data: null,
          message: error.value,
          pagination: {}
        };
      } finally {
        loading.value = false;
        delete inFlightRequests.value[cacheKey];
      }
    })();

    inFlightRequests.value[cacheKey] = promise;
    return promise;
  };

  // ─── Approve ──────────────────────────────────────────────────────
  const approve = async (approvalId, note = '', paymentOverride = {}) => {
    try {
      const payload = { note };
      if (paymentOverride.payment_method_id) payload.payment_method_id = paymentOverride.payment_method_id;
      if (paymentOverride.paid_amount != null) payload.paid_amount = paymentOverride.paid_amount;

      const { data } = await approvalsService.approve(approvalId, payload);

      pendingApprovals.value = pendingApprovals.value.filter(a => a.id !== approvalId);
      approvalCache.value = null;

      return {
        status: 'success',
        data: data?.data || { id: approvalId },
        message: data?.message || 'Approval successful'
      };
    } catch (error) {
      return {
        status: 'error',
        data: null,
        message: error?.response?.data?.message || 'Failed to approve'
      };
    }
  };

  // ─── Reject ───────────────────────────────────────────────────────
  const reject = async (approvalId, note = '') => {
    try {
      const { data } = await approvalsService.reject(approvalId, note);

      pendingApprovals.value = pendingApprovals.value.filter(a => a.id !== approvalId);
      approvalCache.value = null;

      return {
        status: 'success',
        data: data?.data || { id: approvalId },
        message: data?.message || 'Rejection successful'
      };
    } catch (error) {
      return {
        status: 'error',
        data: null,
        message: error?.response?.data?.message || 'Failed to reject'
      };
    }
  };

  // ─── Clear Cache ──────────────────────────────────────────────────
  const clearCache = () => {
    approvalCache.value = null;
    inFlightRequests.value = {};
  };

  return {
    pendingApprovals,
    currentPage,
    totalPages,
    loading,
    error,
    totalApprovals,
    approvalsByStatus,
    listPending,
    approve,
    reject,
    clearCache
  };
});
