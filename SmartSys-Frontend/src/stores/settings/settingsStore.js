import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import { settingsService } from '@/services/settings';

const nowMs = () => Date.now();

export const useSettingsStore = defineStore('settings', () => {

  // ─── Cache TTL ────────────────────────────────────────────────────────────
  const TTL = {
    settings: 30 * 60 * 1000,   // 30 دقيقة
    taxSettings: 15 * 60 * 1000, // 15 دقيقة
  };

  // ─── State ────────────────────────────────────────────────────────────────
  const settings        = ref({});
  const settingsFetchedAt = ref(0);
  const settingsInFlight  = ref(null);
  const loading = ref(false);
  const error = ref(null);

  // ─── Helpers ──────────────────────────────────────────────────────────────
  const isFresh = (fetchedAt, ttl) =>
    !!fetchedAt && (nowMs() - fetchedAt) < ttl;

  // ─── fetchSettings ────────────────────────────────────────────────────────
  /**
   * يرجع object فيه:
   *   - settings: كائن البيانات الكامل (لفحص if (s && s.settings))
   *   - كل مفاتيح الإعدادات على مستوى أعلى  (s['company.name'] ...)
   */
  const fetchSettings = async ({ force = false } = {}) => {
    try {
      if (
        !force &&
        isFresh(settingsFetchedAt.value, TTL.settings) &&
        Object.keys(settings.value).length > 0
      ) {
        return {
          status: 'success',
          data: buildSettingsResponse(settings.value),
          message: null
        };
      }

      if (!force && settingsInFlight.value) {
        const result = await settingsInFlight.value;
        return {
          status: 'success',
          data: result,
          message: null
        };
      }

      const promise = (async () => {
        try {
          const response = await settingsService.getSettings({ suppress403: true });
          // الـ API ممكن يرجع { data: {...} } أو { settings: {...} } أو flat object
          const raw  = response?.data?.data
                    || response?.data?.settings
                    || response?.data
                    || {};

          // نحتفظ بالبيانات flat في الـ store
          settings.value         = raw;
          settingsFetchedAt.value = nowMs();

          return buildSettingsResponse(raw);
        } finally {
          settingsInFlight.value = null;
        }
      })();

      settingsInFlight.value = promise;
      const result = await promise;
      
      return {
        status: 'success',
        data: result,
        message: null
      };
    } catch (error) {
      // 403 = permission denied (expected for non-admin users) — log as warning only
      if (error?.response?.status === 403) {
        console.warn('fetchSettings: permission denied (403) — continuing with defaults');
      } else {
        console.error('fetchSettings failed:', error);
      }
      return {
        status: 'error',
        data: buildSettingsResponse({}),
        message: error.response?.data?.message || error.message
      };
    }
  };

  /**
   * يبني الـ response بالشكل المتوقع من الصفحة:
   *   s.settings  → truthy check
   *   s['company.name'] → قيمة مباشرة
   */
  const buildSettingsResponse = (raw) => ({
    settings: raw,   // للـ if (s && s.settings)
    ...raw,          // لـ s['company.name'] وغيرها مباشرةً
  });

  // ─── updateSettings ───────────────────────────────────────────────────────
  /**
   * يرجع { status: 'success', data } لتتوافق مع كل مكان في الصفحة:
   *   if (response.status === 'success') { ... }
   */
  const updateSettings = async (settingsObj) => {
    loading.value = true;
    error.value = null;
    
    try {
      const response = await settingsService.updateSettings(settingsObj);

      // حدّث الـ cache المحلي فوراً
      Object.assign(settings.value, settingsObj);
      settingsFetchedAt.value = nowMs();

      const apiStatus = response?.data?.status;
      return {
        status: apiStatus === 'success' ? 'success' : 'error',
        data: response?.data ?? {},
        message: response?.data?.message
      };
    } catch (err) {
      console.error('updateSettings failed:', err);
      error.value = err.response?.data?.message || err.message;
      return {
        status: 'error',
        data: null,
        message: err.response?.data?.message || err.message
      };
    } finally {
      loading.value = false;
    }
  };

  // ─── updateSetting (مفرد) ─────────────────────────────────────────────────
  const updateSetting = async (key, value) => {
    return await updateSettings({ [key]: value });
  };

  // ─── uploadLogo ───────────────────────────────────────────────────────────
  /**
   * يرسل FormData تحتوي على logo + settings JSON
   * يرجع { status: 'success', data }
   */
  const uploadLogo = async (formData) => {
    loading.value = true;
    error.value = null;
    
    try {
      const response = await settingsService.uploadLogo(formData);

      // حدّث الـ cache بأي settings جديدة إن وجدت في الـ response
      const returned = response?.data?.settings ?? response?.data?.data ?? {};
      if (Object.keys(returned).length) {
        Object.assign(settings.value, returned);
      }

      // لو الـ API رجّع مسار اللوجو مباشرة
      const logoPath = response?.data?.logo_path ?? response?.data?.data?.['company.logo'];
      if (logoPath) {
        settings.value['company.logo'] = logoPath;
      }

      settingsFetchedAt.value = nowMs();

      const apiStatus = response?.data?.status;
      return {
        status: apiStatus === 'success' ? 'success' : 'error',
        data: response?.data ?? {},
        message: response?.data?.message
      };
    } catch (err) {
      console.error('uploadLogo failed:', err);
      error.value = err.response?.data?.message || err.message;
      return {
        status: 'error',
        data: null,
        message: err.response?.data?.message || err.message
      };
    } finally {
      loading.value = false;
    }
  };

  // ─── fetchTaxSettings ─────────────────────────────────────────────────────
  const fetchTaxSettings = async ({ force = false } = {}) => {
    return await fetchSettings({ force });
  };

  // ─── clearCache ───────────────────────────────────────────────────────────
  const clearCache = () => {
    settings.value          = {};
    settingsFetchedAt.value = 0;
    settingsInFlight.value  = null;
  };

  // ─── Computed Selectors ───────────────────────────────────────────────────
  const company = computed(() => {
    const prefix = 'company.';
    return Object.fromEntries(
      Object.entries(settings.value)
        .filter(([k]) => k.startsWith(prefix))
        .map(([k, v]) => [k.slice(prefix.length), v])
    );
  });

  const tax = computed(() => {
    // Source of truth is tax.* (written by Setup wizard and our fixed saveInvoiceSettings).
    // Fallback to company.tax_* to stay compatible with any older rows still in that namespace.
    const enabled = settings.value['tax.tax_enabled'] ?? settings.value['company.tax_enabled'];
    const rate    = settings.value['tax.tax_rate']    ?? settings.value['company.tax_rate'];
    const name    = settings.value['tax.tax_name']    ?? settings.value['company.tax_name'];
    const number  = settings.value['tax.tax_number']  ?? settings.value['company.tax_number'];
    return {
      enabled: enabled === '1' || enabled === 1 || enabled === true,
      rate:    parseFloat(rate) || 0,
      name:    name   || 'ضريبة القيمة المضافة',
      number:  number || '',
    };
  });

  const invoice = computed(() => {
    const prefix = 'invoice.';
    return Object.fromEntries(
      Object.entries(settings.value)
        .filter(([k]) => k.startsWith(prefix))
        .map(([k, v]) => [k.slice(prefix.length), v])
    );
  });

  const print = computed(() => {
    const prefix = 'print.';
    return Object.fromEntries(
      Object.entries(settings.value)
        .filter(([k]) => k.startsWith(prefix))
        .map(([k, v]) => [k.slice(prefix.length), v])
    );
  });

  const printer = computed(() => {
    const prefix = 'printer.';
    return Object.fromEntries(
      Object.entries(settings.value)
        .filter(([k]) => k.startsWith(prefix))
        .map(([k, v]) => [k.slice(prefix.length), v])
    );
  });

  const tenant = computed(() => settings.value?.tenant ?? {});

  // ─── Tax helpers (backward compat) ───────────────────────────────────────
  const isTaxEnabled = computed(() => tax.value.enabled);
  const getTaxRate   = computed(() => tax.value.rate);

  // ─── Return ───────────────────────────────────────────────────────────────
  return {
    // State
    settings,
    loading,
    error,

    // Actions
    fetchSettings,
    fetchTaxSettings,
    updateSetting,
    updateSettings,
    uploadLogo,
    clearCache,

    // Computed selectors
    company,
    tax,
    invoice,
    print,
    printer,
    tenant,
    isTaxEnabled,
    getTaxRate,
  };
});