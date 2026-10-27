import { defineStore } from 'pinia';
import { ref } from 'vue';
import setupService from '@/services/setup';

export const useSetupStore = defineStore('setup', () => {

  // ─── State ────────────────────────────────────────────────────────────────
  const loading = ref(false);
  const error   = ref(null);

  // ─── saveSetup ────────────────────────────────────────────────────────────
  /**
   * يرجع {status, data, message} موحد
   */
  const saveSetup = async (setupData) => {
    loading.value = true;
    error.value   = null;
    try {
      const response = await setupService.save(setupData);
      return {
        status: 'success',
        data: response.data,
        message: 'تم حفظ الإعدادات بنجاح'
      };
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to save setup';
      return {
        status: 'error',
        data: null,
        message: err.response?.data?.message || err.message || 'فشل حفظ الإعدادات'
      };
    } finally {
      loading.value = false;
    }
  };

  // ─── skipSetup ────────────────────────────────────────────────────────────
  const skipSetup = async () => {
    loading.value = true;
    error.value   = null;
    try {
      const response = await setupService.skip();
      return {
        status: 'success',
        data: response.data,
        message: 'تم تخطي الإعدادات بنجاح'
      };
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to skip setup';
      return {
        status: 'error',
        data: null,
        message: err.response?.data?.message || err.message || 'فشل تخطي الإعدادات'
      };
    } finally {
      loading.value = false;
    }
  };

  // ─── loadSetupStatus ──────────────────────────────────────────────────────
  const loadSetupStatus = async () => {
    loading.value = true;
    error.value   = null;
    try {
      const response = await setupService.getStatus();
      return {
        status: 'success',
        data: response.data,
        message: ''
      };
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to load setup status';
      return {
        status: 'error',
        data: null,
        message: err.response?.data?.message || err.message || 'فشل تحميل حالة الإعدادات'
      };
    } finally {
      loading.value = false;
    }
  };

  // ─── preview (Opening Balance) ────────────────────────────────────────────
  const preview = async (payload) => {
    loading.value = true;
    error.value   = null;
    try {
      const response = await setupService.previewOpeningBalance(payload);
      return {
        status: response.data?.status || 'success',
        data: response.data?.data ?? response.data,
        message: response.data?.message || ''
      };
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to preview';
      return {
        status: 'error',
        data: null,
        message: err.response?.data?.message || err.message || 'فشل معاينة الرصيد الافتتاحي'
      };
    } finally {
      loading.value = false;
    }
  };

  // ─── commit (Opening Balance) ──────────────────────────────────────────────
  const commit = async (payload) => {
    loading.value = true;
    error.value   = null;
    try {
      const response = await setupService.commitOpeningBalance(payload);
      return {
        status: response.data?.status || 'success',
        data: response.data?.data ?? response.data,
        message: response.data?.message || 'تم حفظ الرصيد الافتتاحي'
      };
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to commit';
      return {
        status: 'error',
        data: null,
        message: err.response?.data?.message || err.message || 'فشل حفظ الرصيد الافتتاحي'
      };
    } finally {
      loading.value = false;
    }
  };

  // ─── Return ───────────────────────────────────────────────────────────────
  return {
    loading,
    error,
    saveSetup,
    skipSetup,
    loadSetupStatus,
    preview,
    commit,
  };
});