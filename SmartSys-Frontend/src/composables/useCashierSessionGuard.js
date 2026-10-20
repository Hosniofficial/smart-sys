import { ref, computed } from 'vue';
import { SessionsService } from '@/services/sessions';
import { useAuthStore } from '@/stores/auth/authStore';
import apiClient from '@/config/axios';
import { getDeviceIdentity, isSettingEnabled } from '@/utils';

/**
 * Cashier Session Guard
 * Ensures there is an open cashier session for a given branch before proceeding with cash actions.
 * Usage:
 *   const { ensureOpenSession, currentSession, loading, error } = useCashierSessionGuard();
 *   const ok = await ensureOpenSession(branchId, { autoOpen: true, opening_cash_amount: 0 });
 *   if (!ok) return; // block action
 */
export function useCashierSessionGuard() {
  const currentSession = ref(null);
  const loading = ref(false);
  const error = ref(null);
  const authStore = useAuthStore();
  const sessionsEnabled = ref(false);
  const enforceForRoles = ref([]); // array of role_ids that must work within sessions
  const enforceSessionForMe = computed(() => {
    const rid = authStore?.user?.role_id;
    return Array.isArray(enforceForRoles.value) && enforceForRoles.value.map(n=>Number(n)).includes(Number(rid));
  });

  const loadSettingsOnce = (() => {
    let done = false;
    return async () => {
      if (done) return;
      try {
        const { data } = await apiClient.get('/settings', { meta: { skipLoader: true } });
        if (data && data.status === 'success') {
          const s = data.data || {};
          sessionsEnabled.value = isSettingEnabled(s['pos.sessions.enabled']);
          const raw = s['pos.sessions.enforce_for_roles'];
          let parsed = [];
          if (Array.isArray(raw)) parsed = raw;
          else if (typeof raw === 'string') {
            const t = raw.trim();
            try {
              if (t.startsWith('[')) parsed = JSON.parse(t);
              else if (t.length) parsed = t.split(',').map(x => Number(String(x).trim())).filter(n => !isNaN(n));
            } catch (_) { /* ignore */ }
          }
          enforceForRoles.value = (parsed || []).map(n => Number(n)).filter(n => !isNaN(n));
        }
      } catch (_) {
        sessionsEnabled.value = false;
        enforceForRoles.value = [];
      } finally {
        done = true;
      }
    };
  })();

  const ensureOpenSession = async (
    branchId,
    {
      autoOpen = true,
      opening_cash_amount = 0,
      prompt = true,
      session_type = 'manual',
    } = {}
  ) => {
    error.value = null;
    await loadSettingsOnce();
    if (!sessionsEnabled.value) return true;
    const cashierId = authStore?.user?.id || null;
    if (!branchId && enforceSessionForMe.value) {
      error.value = 'الرجاء اختيار المخزن أولاً';
      return false;
    }

    loading.value = true;
    try {
      // Check current session
      // - Enforced roles: scoped by branch
      // - Non-enforced roles: always check global session for this cashier (no branch filter)
      const isEnforced = enforceSessionForMe.value;
      const { device_id } = getDeviceIdentity();
      currentSession.value = await SessionsService.getCurrent(
        isEnforced ? (branchId ?? undefined) : undefined,
        isEnforced ? undefined : cashierId,
        device_id
      );
      if (currentSession.value) return true;

      // Ask and open if allowed
      if (!autoOpen) return false;
      // For non-enforced roles, do not prompt at all (even if a branch is selected)
      const shouldPrompt = enforceSessionForMe.value ? prompt : false;
      if (shouldPrompt) {
        const msg = !branchId && !enforceSessionForMe.value
          ? 'لا توجد جلسة كاشير مفتوحة. هل تريد فتح جلسة الآن (بدون تحديد مخزن)؟'
          : 'لا توجد جلسة كاشير مفتوحة لهذا المخزن. هل تريد فتح جلسة الآن؟';
        const ok = window.confirm(msg);
        if (!ok) return false;
      }

      const { device_id: devId, device_name } = getDeviceIdentity();
      const opened = await SessionsService.openSession({
        // For non-enforced roles, open a global session (omit branch completely)
        branch_id: (enforceSessionForMe.value ? branchId : undefined),
        opening_cash_amount,
        session_type,
        device_id: devId,
        device_name,
      });
      currentSession.value = opened;
      return !!opened;
    } catch (e) {
      error.value = e?.response?.data?.message || e?.message || 'حدث خطأ أثناء التحقق من جلسة الكاشير';
      return false;
    } finally {
      loading.value = false;
    }
  };

  return { ensureOpenSession, currentSession, loading, error };
}

export default useCashierSessionGuard;

/**
 * useSessionExemption
 * Frontend mirror of backend exemption logic to skip cashier-session enforcement for privileged users.
 * Exempt if:
 *  - the user is a platform super-admin, or a privileged user within a tenant
 *  - settings contain cashier_session_exempt_users including my user id
 *  - settings contain cashier_session_exempt_roles including my role name/slug
 */
export function useSessionExemption() {
  const authStore = useAuthStore();
  const isExempt = ref(false);
  const loading = ref(false);
  const error = ref(null);

  const hasPrivilegedRole = () => {
    const role = String(authStore?.user?.role || '').toLowerCase();
    if (authStore.isSuperAdmin || authStore.isAdmin) return true;
    return Number(authStore?.user?.tenant_id) > 0 &&
      role === 'manager';
  };

  const loadOnce = (() => {
    let done = false;
    return async () => {
      if (done) return;
      loading.value = true;
      error.value = null;
      try {
        // Fast-path: privileged role
        if (hasPrivilegedRole()) {
          isExempt.value = true;
          done = true;
          return;
        }
        const { data } = await apiClient.get('/settings', { meta: { skipLoader: true } });
        const s = (data && data.status === 'success') ? (data.data || {}) : {};
        const usersCsv = String(s['cashier_session_exempt_users'] || '').trim();
        const rolesCsv = String(s['cashier_session_exempt_roles'] || '').trim();
        const uid = Number(authStore?.user?.id || 0);
        const role = String(authStore?.user?.role || '').toLowerCase();
        let ok = false;
        if (usersCsv) {
          const ids = usersCsv.split(',').map(x => Number(String(x).trim())).filter(n => !isNaN(n));
          if (ids.includes(uid)) ok = true;
        }
        if (!ok && rolesCsv) {
          const allowed = rolesCsv.split(',').map(x => String(x || '').toLowerCase().trim()).filter(Boolean);
          if (allowed.includes(role)) ok = true;
        }
        isExempt.value = !!ok;
      } catch (e) {
        error.value = e?.message || 'فشل تحميل إعدادات الإعفاء';
        isExempt.value = false;
      } finally {
        loading.value = false;
        done = true;
      }
    };
  })();

  // public API
  return { isExempt, loading, error, ensureLoaded: loadOnce };
}
