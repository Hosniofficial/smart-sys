import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import { getCurrentShift as getCurrentShiftRequest } from '@/services/shifts';

const nowMs = () => Date.now();

export const useShiftStore = defineStore('shift', () => {
  // ─── Cache TTL ────────────────────────────────────────────────────────────
  const TTL = {
    currentShift: 1 * 60 * 1000,  // دقيقة واحدة
  };

  // ─── State ────────────────────────────────────────────────────────────────
  const currentShift = ref(null);
  const currentShiftFetchedAt = ref(null);
  const isLoading = ref(false);
  const error = ref(null);

  // ─── Helpers ──────────────────────────────────────────────────────────────
  const isFresh = (fetchedAt, ttl) =>
    !!fetchedAt && (nowMs() - fetchedAt) < ttl;

  // ─── Getters ──────────────────────────────────────────────────────────────
  const isShiftOpen = computed(() => !!currentShift.value);

  // ─── Actions ──────────────────────────────────────────────────────────────

  /**
   * جلب الوردية الحالية
   */
  const getCurrentShift = async (branchId, terminalId, force = false) => {
    try {
      if (!force && currentShift.value && isFresh(currentShiftFetchedAt.value, TTL.currentShift)) {
        return {
          status: 'success',
          data: currentShift.value,
        };
      }

      isLoading.value = true;
      error.value = null;

      const response = await getCurrentShiftRequest(branchId, terminalId);

      currentShift.value = response?.data?.data || null;
      currentShiftFetchedAt.value = nowMs();

      return {
        status: 'success',
        data: currentShift.value,
      };
    } catch (err) {
      // 404 يعني لا توجد وردية مفتوحة
      if (err.response?.status === 404) {
        currentShift.value = null;
        return {
          status: 'success',
          data: null,
        };
      }

      error.value = err.response?.data?.message || err.message || 'فشل جلب الوردية الحالية';
      return {
        status: 'error',
        message: error.value,
      };
    } finally {
      isLoading.value = false;
    }
  };

  return {
    // State
    currentShift: computed(() => currentShift.value),
    isLoading: computed(() => isLoading.value),
    error: computed(() => error.value),

    // Getters
    isShiftOpen,

    // Actions
    getCurrentShift,
  };
});
