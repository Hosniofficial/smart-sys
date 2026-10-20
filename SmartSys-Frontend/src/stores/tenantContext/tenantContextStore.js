import { defineStore } from 'pinia';
import { ref } from 'vue';

export const useTenantContextStore = defineStore('tenantContext', () => {
  const activeTenantId = ref(null);

  const setActiveTenantId = (tenantId) => {
    const normalizedTenantId = Number(tenantId);
    if (!Number.isSafeInteger(normalizedTenantId) || normalizedTenantId <= 0) {
      throw new TypeError('A positive tenant ID is required for tenant context.');
    }
    activeTenantId.value = normalizedTenantId;
  };

  const clearActiveTenantId = () => {
    activeTenantId.value = null;
  };

  return {
    activeTenantId,
    setActiveTenantId,
    clearActiveTenantId,
  };
});
