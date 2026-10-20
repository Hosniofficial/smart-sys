import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import apiClient from '@/config/axios';
import { useSubscriptionStore } from '@/stores/subscriptions/subscriptionStore';

// ⚠️ Import router directly — NEVER use useRouter() inside a Pinia store.
// useRouter() requires an active Vue component context. Stores can be called
// from navigation guards, axios interceptors, or other non-component contexts
// where no Vue instance is active, causing the "injection not found" error.
//
// We use a lazy getter to avoid circular imports:
// main.js → router → auth.js → router (circular)
// Instead: auth.js accesses router lazily after it's been created.
let _router = null;
export const setStoreRouter = (r) => { _router = r; };
export const getRouter = () => _router;

// ─── Helpers ──────────────────────────────────────────────────────────────────

const base64UrlDecode = (value) => {
  if (!value) return null;

  const normalized = value.replace(/-/g, '+').replace(/_/g, '/');
  const padded = normalized + '='.repeat((4 - (normalized.length % 4)) % 4);

  try {
    return atob(padded);
  } catch {
    return null;
  }
};

const safeJsonParse = (jsonString, defaultValue = null) => {
  if (jsonString === null || jsonString === undefined || jsonString === 'undefined') {
    return defaultValue;
  }
  try {
    return JSON.parse(jsonString);
  } catch {
    return defaultValue;
  }
};

// ─── Store ────────────────────────────────────────────────────────────────────

export const useAuthStore = defineStore('auth', () => {
  // access_token is kept in memory only (not persisted to browser storage)
  // to reduce token theft risk from browser storage access.
  // user metadata (non-sensitive) stays in localStorage for instant UX on reload.
  const storedUser = localStorage.getItem('user');

  const user                   = ref(storedUser ? safeJsonParse(storedUser) : null);
  const token                  = ref(null);   // ← in-memory only, never persisted
  const returnUrl              = ref(null);
  const subscriptionExpired    = ref(false);
  const subscriptionMessage    = ref('');
  let   refreshPromise = null;

  // ── Computed ────────────────────────────────────────────────────────────────

  const isOwner = computed(() => {
    const isOwnerFlag = user.value?.is_owner;
    return isOwnerFlag === 1 || isOwnerFlag === true;
  });

  const hasTenant = computed(() => Number(user.value?.tenant_id) > 0);

  const isSuperAdmin = computed(() => {
    if (!user.value || !Object.prototype.hasOwnProperty.call(user.value, 'tenant_id')) {
      return false;
    }

    const role   = user.value.role;
    const roleId = Number(user.value.role_id);
    return !hasTenant.value && (role === 'super_admin' || roleId === 1);
  });

  const isAdmin = computed(() => {
    const role   = user.value?.role;
    return isSuperAdmin.value || (
      hasTenant.value && (role === 'admin' || isOwner.value)
    );
  });

  const isAuthenticated        = computed(() => !!token.value && !!user.value);
  const isSubscriptionExpired  = computed(() => subscriptionExpired.value);
  const isCheckingAuth         = ref(false);

  // ── JWT helpers ─────────────────────────────────────────────────────────────

  const getTokenPayload = () => {
    try {
      if (!token.value) return null;
      const parts = token.value.split('.');
      if (parts.length < 2) return null;

      const payload = base64UrlDecode(parts[1]);
      if (!payload) return null;

      return JSON.parse(payload);
    } catch {
      return null;
    }
  };

  // ── Silent refresh ───────────────────────────────────────────────────────────

  const _doSilentRefresh = async () => {
    try {
      const response = await apiClient.post(
        '/auth/refresh',
        {},
        {
          meta: { skipLoader: true },
          _isRefreshRequest: true,  // ← prevents interceptor retry loop
        }
      );

      if (response.data?.status === 'success' && response.data?.data?.access_token) {
        const newAccessToken = response.data.data.access_token;
        const userData = response.data.data.user; // Get updated user data
        
        token.value = newAccessToken;
        apiClient.defaults.headers.common['Authorization'] = `Bearer ${newAccessToken}`;
        
        // Update user data if provided (includes permissions)
        if (userData) {
          user.value = userData;
          localStorage.setItem('user', JSON.stringify(userData));
        }
        
        return newAccessToken;
      }
      return null;
    } catch (err) {
      // 400 = refresh token missing (new email-verified user without refresh token)
      // This is OK — user has a valid access token, just no refresh token cookie yet
      if (err?.response?.status === 400) {
        if (import.meta.env.DEV) console.log('[Auth] Refresh cookie missing (expected for new email-verified users)');
        return null;  // Not an error, just no refresh token yet
      }

      // 401 from refresh = session expired / user deleted — force logout
      if (err?.response?.status === 401) {
        clearAuthData();
        if (typeof window !== 'undefined') {
          window.location.replace('/');
        }
      }
      return null;
    }
  };

  const refreshToken = async () => {
    if (refreshPromise) return refreshPromise;

    refreshPromise = (async () => {
      try {
        const newToken = await _doSilentRefresh();
        return newToken !== null;
      } finally {
        refreshPromise = null;
      }
    })();

    return refreshPromise;
  };

  // ── checkAndRefreshToken ────────────────────────────────────────────────────

  const checkAndRefreshToken = async () => {
    // If no token exists, try to refresh using stored user + refresh cookie.
    if (!token.value) {
      if (!user.value) return false;
      if (import.meta.env.DEV) console.log('[Auth] No token — attempting refresh...');
      const refreshed = await refreshToken();
      return refreshed;
    }

    try {
      const payload = getTokenPayload();
      if (!payload || typeof payload.exp !== 'number') {
        if (import.meta.env.DEV) console.log('[Auth] Invalid token payload');
        return false;
      }

      const timeUntilExpiry = payload.exp - Math.floor(Date.now() / 1000);

      if (timeUntilExpiry <= 0) {
        const refreshed = await refreshToken();
        if (!refreshed) {
          clearAuthData();
          return false;
        }
        return true;
      }

      if (timeUntilExpiry < 300) {
        if (import.meta.env.DEV) console.log(`[Auth] Token expiring in ${timeUntilExpiry}s — attempting refresh...`);

        const refreshed = await refreshToken();
        if (refreshed) {
          return true;
        }

        // Refresh failed due to network / missing cookie / non-critical failure,
        // but the current access token is still valid. Keep using it.
        return true;
      }

      if (import.meta.env.DEV) {
        console.log(`[Auth] Token valid for ${Math.round(timeUntilExpiry / 60)}m more`);
      }

      return true;
    } catch (e) {
      if (import.meta.env.DEV) console.log('[Auth] Error parsing token:', e);
      return false;
    }
  };

  // ── checkAuthStatus ─────────────────────────────────────────────────────────

  const checkAuthStatus = async () => {
    if (!token.value) {
      if (!user.value) return false;
      const refreshed = await refreshToken();
      if (!refreshed) {
        clearAuthData();
        return false;
      }
      return true;
    }

    isCheckingAuth.value = true;
    try {
      const payload = getTokenPayload();
      if (!payload || typeof payload.exp !== 'number') {
        clearAuthData();
        return false;
      }

      const isValid = payload.exp > Math.floor(Date.now() / 1000);
      if (!isValid) {
        const refreshed = await refreshToken();
        if (!refreshed) {
          clearAuthData();
          getRouter()?.push('/');
        }
        return refreshed;
      }

      return true;
    } catch {
      clearAuthData();
      return false;
    } finally {
      isCheckingAuth.value = false;
    }
  };

  // ── setAuthData ─────────────────────────────────────────────────────────────

  function setAuthData(userData, accessToken) {
    if (!userData || !accessToken) return;

    user.value  = userData;
    token.value = accessToken;

    localStorage.setItem('user', JSON.stringify(userData));

    apiClient.defaults.headers.common['Authorization'] = `Bearer ${accessToken}`;
  }

  // ── clearAuthData ───────────────────────────────────────────────────────────

  function clearAuthData() {
    user.value  = null;
    token.value = null;
    clearSubscriptionExpired();

    localStorage.removeItem('user');
    localStorage.removeItem('token');

    // Refresh token is expected to be in an HttpOnly cookie, not browser storage.

    delete apiClient.defaults.headers.common['Authorization'];

    try {
      const subscriptionStore = useSubscriptionStore();
      subscriptionStore.reset();
    } catch { /* store may not be ready */ }
  }

  // ── login ───────────────────────────────────────────────────────────────────

  async function login(username, password) {
    try {
      const formData = new URLSearchParams();
      formData.append('username', username);
      formData.append('password', password);

      const response = await apiClient.post('/auth/login', formData.toString(), {
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        },
      });

      if (response.data?.status === 'success' && response.data?.data) {
        const { access_token, user: userData } = response.data.data;

        const enhancedUserData = {
          ...userData,
          isAdmin:   userData.role === 'admin' || userData.isAdmin || false,
          tenant_id: userData.tenant_id,
        };

        try {
          const subscriptionStore = useSubscriptionStore();
          subscriptionStore.reset();
        } catch { /* ignore */ }

        setAuthData(enhancedUserData, access_token);

        const redirectTo = returnUrl.value || '/cashier';
        returnUrl.value  = null;
        getRouter()?.push(redirectTo);

        return { success: true, user: enhancedUserData };
      }

      return {
        success: false,
        message: response.data?.message || 'فشل تسجيل الدخول',
      };
    } catch (error) {
      const message = error.response?.data?.message || 'حدث خطأ أثناء تسجيل الدخول';
      return { success: false, message };
    }
  }

  // ── register ─────────────────────────────────────────────────────────────────

  async function register(userData) {
    try {
      const response = await apiClient.post('/auth/register', userData);
      if (response.data?.status === 'success') {
        return { status: 'success', data: response.data?.data || null, message: 'تم إنشاء الحساب بنجاح' };
      }
      return { status: 'error', data: null, message: response.data?.message || 'تعذر إكمال التسجيل' };
    } catch (error) {
      return {
        status: 'error',
        data: null,
        message: error?.response?.data?.message || error?.message || 'حدث خطأ أثناء التسجيل',
      };
    }
  }

  // ── logout ───────────────────────────────────────────────────────────────────

  async function logout() {
    try {
      await apiClient.post('/auth/logout', {}).catch((err) => {
        console.warn('Server logout failed, proceeding with local logout:', err?.message);
      });
    } catch (error) {
      console.error('Logout error:', error);
    } finally {
      clearAuthData();
      getRouter()?.push('/');
    }
  }

  // ── logoutAllDevices ─────────────────────────────────────────────────────────

  async function logoutAllDevices() {
    try {
      await apiClient.post('/auth/logout-all-devices', {}).catch((err) => {
        console.warn('logoutAllDevices API call failed:', err?.message);
      });
    } catch (error) {
      console.error('logoutAllDevices error:', error);
    } finally {
      clearAuthData();
      getRouter()?.push('/');
    }
  }

  // ── setSubscriptionExpired ───────────────────────────────────────────────────

  function setSubscriptionExpired(message = '') {
    subscriptionExpired.value = true;
    subscriptionMessage.value = message || 'يرجى الترقية لمتابعة استخدام النظام';
    if (import.meta.env.DEV) {
      console.log('[Auth] Subscription expired:', subscriptionMessage.value);
    }
  }

  // ── clearSubscriptionExpired ─────────────────────────────────────────────────

  function clearSubscriptionExpired() {
    subscriptionExpired.value = false;
    subscriptionMessage.value = '';
  }

  // ── Public API ───────────────────────────────────────────────────────────────

  return {
    user,
    token,
    returnUrl,
    subscriptionExpired,
    subscriptionMessage,
    isSuperAdmin,
    isAdmin,
    isOwner,
    isAuthenticated,
    isSubscriptionExpired,
    isCheckingAuth,
    checkAuthStatus,
    checkAndRefreshToken,
    getTokenPayload,
    login,
    register,
    logout,
    logoutAllDevices,
    clearAuthData,
    setAuthData,
    refreshToken,
    setSubscriptionExpired,
    clearSubscriptionExpired,
  };
});
