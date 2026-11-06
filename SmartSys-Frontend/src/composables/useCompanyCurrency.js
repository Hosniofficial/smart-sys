import { ref, computed } from 'vue';
import { settingsService } from '@/services/settings.js';
import { getCurrencySymbol, getCurrencyName } from '@/config/currencies.js';

/**
 * Module-level cache variables shared across all component instances
 * This ensures all components see the same cached settings
 */
let globalCachedSettings = null;
let globalCacheTime = 0;
const CACHE_DURATION = 5 * 60 * 1000; // 5 minutes

/**
 * Composable for accessing company currency settings dynamically
 * Uses module-level cache to share settings across all components
 */
export function useCompanyCurrency() {
  const settings = ref(null);
  const loading = ref(false);
  const error = ref(null);

  /**
   * Fetch company settings from API
   * Uses module-level cache to share across all component instances
   */
  async function fetchSettings(forceRefresh = false) {
    // Return cached settings if still valid and not forcing refresh
    if (globalCachedSettings && !forceRefresh && Date.now() - globalCacheTime < CACHE_DURATION) {
      settings.value = globalCachedSettings;
      return globalCachedSettings;
    }

    loading.value = true;
    error.value = null;

    try {
      const response = await settingsService.getAll();
      
      // Parse settings into an object
      let parsedSettings = {};
      
      if (Array.isArray(response.data)) {
        // If data is an array of {key, value} objects
        response.data.data.forEach(item => {
          parsedSettings[item.key] = item.value;
        });
      } else if (typeof response.data === 'object') {
        // If data is already an object
        parsedSettings = response.data;
      }

      globalCachedSettings = parsedSettings;
      globalCacheTime = Date.now();
      settings.value = parsedSettings;
      return parsedSettings;
    } catch (err) {
      console.error('Failed to fetch company settings:', err);
      error.value = err.message || 'Failed to fetch settings';
      
      // Return default fallback settings
      const defaults = {
        'company.currency': 'EGP',
        'company.currency_code': 'EGP',
        'company.currency_symbol': 'ج.م'
      };
      
      return defaults;
    } finally {
      loading.value = false;
    }
  }

  /**
   * Get the company currency code (e.g., 'EGP', 'USD', 'SAR')
   */
  const currencyCode = computed(() => {
    return settings.value?.['company.currency_code'] ||
           settings.value?.currencyCode ||
           'EGP';
  });

  /**
   * Get the company currency symbol (e.g., 'ج.م', '$', 'ر.س')
   * Falls back to getCurrencySymbol if not in settings
   */
  const currencySymbol = computed(() => {
    const settingSymbol = settings.value?.['company.currency_symbol'] || 
                         settings.value?.currencySymbol;
    
    if (settingSymbol) {
      return settingSymbol;
    }
    
    // Fallback to centralized config
    return getCurrencySymbol(currencyCode.value);
  });

  /**
   * Get the company currency display name in Arabic
   */
  const currencyName = computed(() => {
    return getCurrencyName(currencyCode.value, 'ar') || currencyCode.value;
  });

  /**
   * Format a value as currency using company settings
   */
  function formatCurrency(value, decimals = 2) {
    if (value === null || value === undefined) {
      return `0.00 ${currencySymbol.value}`;
    }

    const numericValue = typeof value === 'string' ? parseFloat(value) : value;
    
    if (isNaN(numericValue)) {
      return `0.00 ${currencySymbol.value}`;
    }

    // Format number with fixed decimals and add thousands separators
    const formattedNumber = numericValue.toFixed(decimals)
      .replace(/\B(?=(\d{3})+(?!\d))/g, ',');

    return `${formattedNumber} ${currencySymbol.value}`;
  }

  /**
   * Format using JavaScript's toLocaleString (browser localization)
   */
  function formatCurrencyLocale(value, decimals = 2) {
    if (value === null || value === undefined) {
      value = 0;
    }

    const numericValue = typeof value === 'string' ? parseFloat(value) : value;
    
    if (isNaN(numericValue)) {
      return '0.00';
    }

    try {
      return numericValue.toLocaleString('en-US', {
        style: 'currency',
        currency: currencyCode.value,
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals
      });
    } catch (e) {
      // Fallback if locale not supported
      return formatCurrency(numericValue, decimals);
    }
  }

  /**
   * Clear the cache to force a refresh on next fetch
   * Clears module-level cache shared across all components
   */
  function clearCache() {
    globalCachedSettings = null;
    globalCacheTime = 0;
  }

  /**
   * Refresh settings immediately from API and update all components
   * Use this when settings are updated in another page
   * Forces a new API call and updates the global cache
   */
  async function refreshSettings() {
    clearCache();
    const result = await fetchSettings(true); // Force refresh from API
    return result;
  }

  return {
    settings,
    loading,
    error,
    currencyCode,
    currencySymbol,
    currencyName,
    fetchSettings,
    formatCurrency,
    formatCurrencyLocale,
    clearCache,
    refreshSettings
  };
}
