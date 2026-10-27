import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import terminalsService from '@/services/terminals';

const nowMs = () => Date.now();

export const useTerminalStore = defineStore('terminal', () => {

  // ─── Cache TTL ────────────────────────────────────────────────────────────
  const TTL = {
    terminals:      2  * 60 * 1000, // دقيقتان
    terminalStatus: 30 * 1000,      // 30 ثانية (real-time)
  };

  // ─── State ────────────────────────────────────────────────────────────────
  const terminalsByBranch          = ref({});
  const terminalsFetchedAtByBranch = ref({});
  const terminalsInFlightByBranch  = ref({});

  const terminalStatusByBranch          = ref({});
  const terminalStatusFetchedAtByBranch = ref({});
  const terminalStatusInFlightByBranch  = ref({});

  // ─── Helpers ──────────────────────────────────────────────────────────────
  const isFresh = (fetchedAt, ttl) =>
    !!fetchedAt && (nowMs() - fetchedAt) < ttl;

  // ─── invalidateBranchStatus ───────────────────────────────────────────────
  /**
   * يمسح status cache لفرع معين عند أي تغيير على الأجهزة
   */
  const invalidateBranchStatus = (branchId) => {
    const bid = String(branchId);
    delete terminalStatusByBranch.value[bid];
    delete terminalStatusFetchedAtByBranch.value[bid];
  };

  // ─── fetchTerminals ───────────────────────────────────────────────────────
  /**
   * يجيب أجهزة الكاشير لفرع معين
   * الـ component يستخدمها هكذا:
   *   const terminals = await terminalStore.fetchTerminals(branchId);
   */
  const fetchTerminals = async (branchId, { force = false, status = 'active' } = {}) => {
    const bid = branchId == null ? '' : String(branchId);
    if (!bid) return [];

    if (status === 'all') {
      return await terminalsService.list({ branch_id: bid, status });
    }

    const fetchedAt = terminalsFetchedAtByBranch.value[bid] || 0;

    if (!force && isFresh(fetchedAt, TTL.terminals) && Array.isArray(terminalsByBranch.value[bid])) {
      return terminalsByBranch.value[bid];
    }

    if (!force && terminalsInFlightByBranch.value[bid])
      return await terminalsInFlightByBranch.value[bid];

    const promise = (async () => {
      try {
        const list = await terminalsService.list({ branch_id: bid, status });

        terminalsByBranch.value          = { ...terminalsByBranch.value,          [bid]: Array.isArray(list) ? list : [] };
        terminalsFetchedAtByBranch.value = { ...terminalsFetchedAtByBranch.value, [bid]: nowMs() };

        return terminalsByBranch.value[bid];
      } finally {
        const next = { ...terminalsInFlightByBranch.value };
        delete next[bid];
        terminalsInFlightByBranch.value = next;
      }
    })();

    terminalsInFlightByBranch.value = { ...terminalsInFlightByBranch.value, [bid]: promise };
    return await promise;
  };

  // ─── fetchTerminalStatus ──────────────────────────────────────────────────
  /**
   * يجيب status map لكل أجهزة الفرع (real-time — TTL 30 ثانية)
   * الـ component يستخدمها هكذا:
   *   const statusMap = await terminalStore.fetchTerminalStatus(branchId);
   *   statusMap[terminalId] // 'active' | 'inactive'
   */
  const fetchTerminalStatus = async (branchId, { force = false } = {}) => {
    const bid = branchId == null ? '' : String(branchId);
    if (!bid) return {};

    const fetchedAt = terminalStatusFetchedAtByBranch.value[bid] || 0;

    if (!force && isFresh(fetchedAt, TTL.terminalStatus) && terminalStatusByBranch.value[bid])
      return terminalStatusByBranch.value[bid];

    if (!force && terminalStatusInFlightByBranch.value[bid])
      return await terminalStatusInFlightByBranch.value[bid];

    const promise = (async () => {
      try {
        const list      = await terminalsService.list({ branch_id: bid, status: 'all' });
        const statusMap = {};
        if (Array.isArray(list)) {
          list.forEach(terminal => { statusMap[terminal.id] = terminal.status; });
        }

        terminalStatusByBranch.value          = { ...terminalStatusByBranch.value,          [bid]: statusMap };
        terminalStatusFetchedAtByBranch.value = { ...terminalStatusFetchedAtByBranch.value, [bid]: nowMs() };

        return statusMap;
      } finally {
        const next = { ...terminalStatusInFlightByBranch.value };
        delete next[bid];
        terminalStatusInFlightByBranch.value = next;
      }
    })();

    terminalStatusInFlightByBranch.value = { ...terminalStatusInFlightByBranch.value, [bid]: promise };
    return await promise;
  };

  // ─── createTerminal ───────────────────────────────────────────────────────
  /**
   * ينشئ جهاز كاشير جديد ويضيفه للـ cache المحلي
   * الـ component يستخدمها هكذا:
   *   const res = await terminalStore.createTerminal(payload);
   *   if (res.status === 'success') { ... }
   */
  const createTerminal = async (payload) => {
    try {
      const res      = await terminalsService.create(payload);
      const terminal = res?.data?.data || res?.data;

      // أضف الجهاز للـ cache المحلي — branch_id من الـ response هو المرجع
      const bid = terminal?.branch_id ? String(terminal.branch_id) : null;
      if (bid && terminal?.id) {
        if (!terminalsByBranch.value[bid]) terminalsByBranch.value[bid] = [];
        terminalsByBranch.value[bid].push(terminal);
        invalidateBranchStatus(bid);
      }

      return {
        status: 'success',
        data:   terminal,
        message: null
      };
    } catch (err) {
      console.error('createTerminal failed:', err);
      return {
        status: 'error',
        data:   null,
        message: err.response?.data?.message || err.message
      };
    }
  };

  // ─── updateTerminal ───────────────────────────────────────────────────────
  /**
   * يعدّل جهاز كاشير ويحدّث الـ cache المحلي في كل الفروع
   * الـ component يستخدمها هكذا:
   *   const res = await terminalStore.updateTerminal(id, payload);
   *   if (res.status === 'success') { ... }
   */
  const updateTerminal = async (id, payload) => {
    try {
      const res     = await terminalsService.update(id, payload);
      const updated = res?.data?.data || res?.data;

      // حدّث الجهاز في كل الفروع (في حالة تغيير الفرع)
      for (const bid of Object.keys(terminalsByBranch.value)) {
        const index = terminalsByBranch.value[bid].findIndex(t => String(t.id) === String(id));
        if (index >= 0) {
          terminalsByBranch.value[bid][index] = {
            ...terminalsByBranch.value[bid][index],
            ...updated,
          };
          invalidateBranchStatus(bid);
        }
      }

      return {
        status: 'success',
        data:   updated,
        message: null
      };
    } catch (err) {
      console.error('updateTerminal failed:', err);
      return {
        status: 'error',
        data:   null,
        message: err.response?.data?.message || err.message
      };
    }
  };

  // ─── Computed Getters ─────────────────────────────────────────────────────
  const getTerminalsForBranch = (branchId) => {
    const bid = branchId == null ? '' : String(branchId);
    return computed(() => terminalsByBranch.value[bid] || []);
  };

  const getActiveTerminalsForBranch = (branchId) => {
    const bid = branchId == null ? '' : String(branchId);
    return computed(() =>
      (terminalsByBranch.value[bid] || []).filter(t => t.status === 'active')
    );
  };

  const getTerminalById = (id) =>
    computed(() => {
      for (const list of Object.values(terminalsByBranch.value)) {
        const found = list.find(t => String(t.id) === String(id));
        if (found) return found;
      }
      return null;
    });

  const isTerminalActive = (terminalId) =>
    computed(() => getTerminalById(terminalId).value?.status === 'active');

  // ─── Cache Clearers ───────────────────────────────────────────────────────
  const clear = () => {
    terminalsByBranch.value          = {};
    terminalsFetchedAtByBranch.value = {};
    terminalsInFlightByBranch.value  = {};
    terminalStatusByBranch.value          = {};
    terminalStatusFetchedAtByBranch.value = {};
    terminalStatusInFlightByBranch.value  = {};
  };

  // ─── Return ───────────────────────────────────────────────────────────────
  return {
    // State
    terminalsByBranch,
    terminalStatusByBranch,

    // Read Actions
    fetchTerminals,
    fetchTerminalStatus,
    getTerminalsForBranch,
    getActiveTerminalsForBranch,
    getTerminalById,
    isTerminalActive,

    // Write Actions
    createTerminal,
    updateTerminal,
    invalidateBranchStatus,

    // Cache
    clear,
  };
});