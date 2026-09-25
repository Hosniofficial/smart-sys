// src/config/axios.js
import axios from 'axios';
import { useAuthStore, getRouter } from '@/stores/auth';
import { useUiStore } from '@/stores/ui';
import logger from '@/utils/logger';
import deviceFingerprint from '@/utils/deviceFingerprint';

// Safe JSON parse for localStorage values
const safeParseUser = (raw) => {
  try { return raw ? JSON.parse(raw) : null; } catch { return null; }
};

// Auto-detect Base URL based on environment
function getBaseURL() {
  // In development: use VITE_API_BASE_URL directly (should contain full path)
  if (import.meta.env.MODE === 'development') {
    // VITE_API_BASE_URL should be: http://localhost:8000/smartsys/api/v1
    const apiBaseUrl = import.meta.env.VITE_API_BASE_URL;
    if (apiBaseUrl) {
      return apiBaseUrl;
    }
    // Fallback if VITE_API_BASE_URL not set
    return 'http://localhost:8000/smartsys/api/v1';
  }
  
  // In production: use VITE_API_BASE_URL if set
  if (import.meta.env.VITE_API_BASE_URL) {
    return import.meta.env.VITE_API_BASE_URL;
  }
  
  // Default for production: relative path from same origin
  return '/smartsys/api/v1';
}

const apiClient = axios.create({
  baseURL: getBaseURL(),
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
  // withCredentials: true is required so the browser sends the HttpOnly
  // refresh_token cookie on cross-origin requests to /auth/refresh,
  // /auth/logout, and /auth/logout-all-devices.
  withCredentials: true,
  timeout: 10000,
});

// Register BASE_URL for development and testing
if (import.meta.env.MODE === 'development') {
  console.log(`[API Config] Mode: ${import.meta.env.MODE}, Base URL: ${apiClient.defaults.baseURL}`);
}
apiClient.interceptors.request.use(
  async (config) => {
    // Proactive token refresh
    // Skip on: refresh endpoint, explicit skipTokenRefresh flag
    try {
      const skipTokenRefresh = config.meta?.skipTokenRefresh || 
                              config.url?.includes('/auth/refresh') || 
                              config.url?.includes('/auth/verify-email');
      
      if (!skipTokenRefresh) {
        const authStore = useAuthStore();
        await authStore.checkAndRefreshToken();
      }
    } catch (e) {
      console.warn('Token check failed:', e);
    }

    // Global loader
    try {
      const ui = useUiStore();
      if (!config.meta?.skipLoader) ui.showLoader();
    } catch (_) { /* store not ready */ }

    // Attach access token from memory (never from localStorage)
    try {
      const authStore = useAuthStore();
      if (authStore.token) {
        config.headers.Authorization = `Bearer ${authStore.token}`;
      }
    } catch (_) { /* store not ready — header may already be set on apiClient.defaults */ }

    // Tenant ID — from store first, localStorage fallback
    let tenantId = null;
    try {
      const authStore = useAuthStore();
      if (authStore.user?.tenant_id) tenantId = authStore.user.tenant_id;
    } catch (_) { /* ignore */ }

    if (!tenantId) {
      const u = safeParseUser(localStorage.getItem('user'));
      tenantId = u?.tenant_id ?? null;
    }

    if (tenantId) {
      config.headers['X-Tenant-Id'] = tenantId;
      if (import.meta.env.MODE === 'development') {
        console.log(`[API] Tenant: ${tenantId}  ${config.method?.toUpperCase()} ${config.url}`);
      }
    }

    // Accept-Language
    let locale = 'ar';
    try {
      const authStore = useAuthStore();
      locale = authStore.user?.locale || authStore.user?.language || 'ar';
    } catch (_) { /* ignore */ }
    if (locale === 'ar') {
      locale = localStorage.getItem('locale') || localStorage.getItem('lang') || 'ar';
    }
    config.headers['Accept-Language'] = locale;

    // Device fingerprint
    try {
      const fingerprint = await deviceFingerprint.getFingerprint();
      if (fingerprint) config.headers['X-Device-Fingerprint'] = fingerprint;
    } catch (error) {
      logger.warn('Failed to get device fingerprint:', error);
    }

    // Log the full URL without duplicating baseURL when URL already contains it
    const fullUrl = config.url?.startsWith('http') ? config.url : `${config.baseURL}${config.url}`;
    logger.log(`[API] ${config.method?.toUpperCase()} ${fullUrl}`, config.params || '');
    return config;
  },
  (error) => {
    logger.error('[API] Request Error:', error);
    try {
      const ui = useUiStore();
      if (!error.config?.meta?.skipLoader) ui.hideLoader();
    } catch (_) { /* store not ready */ }
    return Promise.reject(error);
  }
);

// Helper function to extract JSON from response safely.
// The API should return one JSON value. If a PHP warning or a nested
// response causes multiple JSON values to be concatenated, keep the last
// complete value because it is the endpoint's final response.
function extractFirstValidJson(response) {
  if (typeof response !== 'string') {
    return response;
  }

  const trimmed = response.trim();
  if (!trimmed) return null;

  if (!trimmed.startsWith('{') && !trimmed.startsWith('[')) {
    return response;
  }

  try {
    // Try parsing the whole response first
    return JSON.parse(trimmed);
  } catch (e) {
    // If full parse fails, try to find the first valid JSON object/array
    let depth = 0;
    let inString = false;
    let escapeNext = false;
    let bracketChar = trimmed[0]; // '{' or '['
    let closeBracket = bracketChar === '{' ? '}' : ']';
    let valueStart = -1;
    let lastValidJson = null;
    
    for (let i = 0; i < trimmed.length; i++) {
      const char = trimmed[i];
      
      if (escapeNext) {
        escapeNext = false;
        continue;
      }
      
      if (char === '\\') {
        escapeNext = true;
        continue;
      }
      
      if (char === '"') {
        inString = !inString;
        continue;
      }
      
      if (inString) continue;

      if (char === bracketChar) {
        if (depth === 0) valueStart = i;
        depth++;
      } else if (char === closeBracket) {
        depth--;
        if (depth === 0 && valueStart >= 0) {
          const jsonStr = trimmed.substring(valueStart, i + 1);
          try {
            lastValidJson = JSON.parse(jsonStr);
          } catch {
            // Continue searching for the next complete JSON value.
          }
        }
      }
    }
    
    // If multiple JSON values were concatenated, the last one is the actual
    // endpoint response. Otherwise leave the original value for the caller.
    if (lastValidJson !== null) return lastValidJson;
    logger.warn('Response is not clean JSON; returning raw string to caller.');
    return response;
  }
}

// Response Interceptor
apiClient.interceptors.response.use(
  (response) => {
    // Global loader end (unless skipped)
    try {
      const ui = useUiStore();
      if (!response.config?.meta || !response.config.meta.skipLoader) ui.hideLoader();
    } catch (_) { /* ignore */ }
    
    logger.log(`[API] ${response.status} ${response.config.method.toUpperCase()} ${response.config.url}`);
    
    // Handle the case where the response data is a string that might contain concatenated JSON
    if (typeof response.data === 'string') {
      try {
        response.data = extractFirstValidJson(response.data);
      } catch (e) {
        logger.error('Failed to process response data:', e);
        throw e;
      }
    }
    
    // Surface server warnings (e.g., journal_entry_skipped) to the user
    try {
      const warnings = response?.data?.warnings;
      if (warnings && warnings.journal_entry_skipped && typeof window.showToast === 'function') {
        const msg = warnings.skipped_count
          ? `تمت العملية مع تحذير: تم تخطي إنشاء ${warnings.skipped_count} قيد/قيود يومية بسبب إعدادات ناقصة أو قيمة صفرية.`
          : 'تمت العملية مع تحذير: تم تخطي إنشاء قيد اليومية بسبب إعدادات ناقصة أو قيمة صفرية.';
        window.showToast(msg, 'warning');
      }
    } catch (_) { /* ignore toast errors */ }

    return response;
  },
  async (error) => {
    const originalRequest = error.config;

    // Ensure loader is hidden on error as well
    try {
      const ui = useUiStore();
      if (!originalRequest?.meta || !originalRequest.meta.skipLoader) ui.hideLoader();
    } catch (_) { /* ignore */ }

    if (error.response) {
      const { status, data } = error.response;

      // 400 Bad Request on refresh = missing refresh token (new user case) — not a redirect-worthy error
      if (status === 400 && (error.config._isRefreshRequest || error.config.url?.includes('/auth/refresh'))) {
        console.log('[API] 400 on refresh — missing token (expected for new users)');
        return Promise.reject(error);
      }

      if (status === 401 && !originalRequest._retry) {
        // Refresh request handling — handle both success and failure
        if (originalRequest._isRefreshRequest || originalRequest.url?.includes('/auth/refresh')) {
          // 401 على /auth/refresh = جلسة منتهية حقاً → redirect
          try {
            const authStore = useAuthStore();
            authStore.clearAuthData();
          } catch (_) { /* ignore */ }
          window.location.replace('/');
          return Promise.reject(error);
        }

        // Login endpoint returning 401 = wrong credentials — don't redirect, let the component handle it
        if (originalRequest.url?.includes('/auth/login')) {
          return Promise.reject(error);
        }

        originalRequest._retry = true;

        try {
          const authStore = useAuthStore();
          const refreshed = await authStore.refreshToken();

          if (refreshed && authStore.token) {
            originalRequest.headers.Authorization = `Bearer ${authStore.token}`;
            return apiClient(originalRequest);
          }
        } catch (refreshError) {
          console.error('Token refresh failed:', refreshError);
        }

        // Refresh failed — clear state and redirect to login
        try {
          const authStore = useAuthStore();
          authStore.clearAuthData();
        } catch (_) { /* ignore */ }
        window.location.replace('/');
        return Promise.reject(error);
      }

      // 402 Subscription Required -> Set flag, let router guard handle redirect
      if (status === 402) {
        const msg = data?.message || 'يرجى الترقية لمتابعة استخدام النظام';
        
        // Update auth store to mark subscription as expired
        try {
          const authStore = useAuthStore();
          authStore.setSubscriptionExpired(msg);
        } catch (_) { /* ignore */ }
        
        // Show toast notification (silent on /upgrade page)
        try {
          const router = getRouter();
          const currentPath = router?.currentRoute?.value?.path || window.location.pathname;
          if (!currentPath.includes('/upgrade')) {
            if (typeof window.showToast === 'function') window.showToast(msg, 'warning');
          }
        } catch (_) { /* ignore */ }
        
        // Return error — don't redirect here (router guard will handle it)
        error.message = msg;
        return Promise.reject(error);
      }

      // 403 / 404 / 405 / 5xx
      let errorMessage = data?.message || '';

switch (status) {
  case 403:
    errorMessage ||= 'ليس لديك صلاحية الوصول';
    break;
  case 404:
    errorMessage ||= 'العنصر غير موجود';
    break;
  case 405:
    errorMessage ||= 'طريقة الطلب غير مسموح بها';
    break;
  default:
    if (status >= 500) {
      errorMessage ||= 'خطأ في الخادم';
    }
}

if (!errorMessage) {
  errorMessage = 'حدث خطأ غير متوقع';
}

      // suppress403: skip toast + log for expected permission-denied scenarios
      const suppress403 = status === 403 && originalRequest?.meta?.suppress403;

      if (suppress403) {
        // Silent — caller handles this gracefully (e.g. Setup.vue loading settings)
      } else {
        logger.error(`[API] ${status} ${originalRequest.method.toUpperCase()} ${originalRequest.url}`, data);

        if (typeof window.showToast === 'function') {
          window.showToast(errorMessage, 'error');
        }
      }

      error.message = errorMessage;
    } else if (error.request) {
      logger.error('[API] No response from server:', error.request);
      const isTimeout = error.code === 'ECONNABORTED';
      error.message = isTimeout ? 'انتهت مهلة الطلب، حاول مرة أخرى' : 'لا توجد استجابة من الخادم';
      if (typeof window.showToast === 'function') {
        window.showToast(error.message, 'error');
      }
    } else {
      error.message = 'خطأ في إعداد الطلب';
    }

    return Promise.reject(error);
  }
);

export { apiClient };
export default apiClient;
