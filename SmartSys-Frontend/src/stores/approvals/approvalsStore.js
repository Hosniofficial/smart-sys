import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import approvalsService from '@/services/approvals';

const nowMs = () => Date.now();

export const useApprovalsStore = defineStore('approvals', () => {
  const pendingApprovals = ref([]);
  const approvalCache = ref(null);
  const approvalCacheTTL = 2 * 60 * 1000; // 2 minutes
  const inFlightRequests = ref({});
  const currentPage = ref(1);
  const totalPages = ref(1);
  const loading = ref(false);
  const error = ref(null);

  const totalApprovals = computed(() => pendingApprovals.value.length);
  const approvalsByStatus = computed(() => {
    return {
      pending: pendingApprovals.value.filter(a => a.status === 'pending'),
      approved: pendingApprovals.value.filter(a => a.status === 'approved'),
      rejected: pendingApprovals.value.filter(a => a.status === 'rejected')
    };
  });

  // List Pending Approvals
  const listPending = async (params = {}, options = {}) => {
    const cacheKey = `pending_${params.page || 1}_${params.limit || 20}_${params.branchId || 'all'}`;
    
    if (inFlightRequests.value[cacheKey]) {
      return inFlightRequests.value[cacheKey];
    }

    if (approvalCache.value && nowMs() - approvalCache.value.timestamp < approvalCacheTTL) {
      return {
        status: 'success',
        data: approvalCache.value.data,
        message: null,
        meta: approvalCache.value.meta
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
        const pagination = data?.meta || {};

        const result = {
          status: 'success',
          data: items,
          message: null,
          meta: pagination
        };

        pendingApprovals.value = result.data;
        totalPages.value = pagination?.lastPage || pagination?.totalPages || 1;
        currentPage.value = pagination?.currentPage || 1;

        approvalCache.value = {
          data: result.data,
          meta: result.meta,
          timestamp: nowMs()
        };

        return result;
      } catch (err) {
        error.value = err?.response?.data?.message || 'Failed to fetch approvals';
        return {
          status: 'error',
          data: null,
          message: error.value,
          meta: {}
        };
      } finally {
        loading.value = false;
        delete inFlightRequests.value[cacheKey];
      }
    })();

    inFlightRequests.value[cacheKey] = promise;
    return promise;
  };

  // Approve
  const approve = async (approvalId, note = '', paymentOverride = {}) => {
    try {
      const payload = { note };
      if (paymentOverride.paymentMethodId) payload.paymentMethodId = paymentOverride.paymentMethodId;
      if (paymentOverride.paidAmount != null) payload.paidAmount = paymentOverride.paidAmount;

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

  // Reject
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

  // Clear Cache
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
