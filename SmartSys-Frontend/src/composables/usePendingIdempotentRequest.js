import { ref } from 'vue';
import { useAuthStore } from '@/stores/auth/authStore';

export function usePendingIdempotentRequest(namespace) {
  const authStore = useAuthStore();
  const pending = ref(null);

  const getStorageKey = (scopeId) =>
    `pending_${namespace}:${authStore.user?.tenant_id || 'platform'}:${authStore.user?.id || 'current'}:${scopeId}`;

  const fingerprint = (payload) => JSON.stringify(payload);

  const restore = (scopeId) => {
    if (!scopeId) throw new Error('معرف العملية غير صالح لاستعادة الطلب.');

    const raw = sessionStorage.getItem(getStorageKey(scopeId));
    if (!raw) {
      pending.value = null;
      return null;
    }

    let saved;
    try {
      saved = JSON.parse(raw);
    } catch (error) {
      console.error('Unable to parse pending idempotent request:', error);
      throw new Error('تعذر قراءة عملية سابقة غير مؤكدة. لا تبدأ عملية جديدة قبل مراجعة الحالة.');
    }

    if (!saved?.key || !saved?.payload || saved.fingerprint !== fingerprint(saved.payload)) {
      throw new Error('بيانات عملية سابقة غير مؤكدة تالفة. لا تبدأ عملية جديدة قبل مراجعة الحالة.');
    }

    pending.value = saved;
    return saved;
  };

  const prepare = (scopeId, payload) => {
    const saved = restore(scopeId);
    const payloadFingerprint = fingerprint(payload);

    if (saved) {
      if (saved.fingerprint !== payloadFingerprint) {
        throw new Error('توجد عملية غير مؤكدة ببيانات مختلفة. أعد المحاولة بالبيانات الأصلية أولاً.');
      }
      return saved;
    }

    const cryptoApi = globalThis.crypto;
    let key = cryptoApi?.randomUUID?.();
    if (!key && cryptoApi?.getRandomValues) {
      const bytes = cryptoApi.getRandomValues(new Uint8Array(16));
      bytes[6] = (bytes[6] & 0x0f) | 0x40;
      bytes[8] = (bytes[8] & 0x3f) | 0x80;
      const hex = Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');
      key = `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
    }
    if (!key) {
      throw new Error('تعذر إنشاء مفتاح آمن لمنع تكرار العملية في هذا المتصفح.');
    }

    const next = { key, payload, fingerprint: payloadFingerprint };
    sessionStorage.setItem(getStorageKey(scopeId), JSON.stringify(next));
    pending.value = next;
    return next;
  };

  const clear = (scopeId) => {
    if (!scopeId) throw new Error('معرف العملية غير صالح لإزالة طلب التحصيل.');
    sessionStorage.removeItem(getStorageKey(scopeId));
    pending.value = null;
  };

  return { pending, restore, prepare, clear };
}
