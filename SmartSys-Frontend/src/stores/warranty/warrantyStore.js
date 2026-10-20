import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import WarrantyService from '@/services/warranty';

const nowMs = () => Date.now();

export const useWarrantyStore = defineStore('warranty', () => {
  let latestWarrantiesRequestId = 0;

  // ─── Cache TTL ────────────────────────────────────────────────────────────
  const TTL = {
    warranties: 5 * 60 * 1000,    // 5 دقائق
    details: 10 * 60 * 1000,      // 10 دقائق
  };

  // ─── State ────────────────────────────────────────────────────────────────
  const warranties = ref([]);
  const warrantiesTotal = ref(0);
  const details = ref(null);
  const warrantiesFetchedAt = ref(null);
  const warrantiesCacheKey = ref(null);
  const detailsFetchedAt = ref({});
  const isLoading = ref(false);
  const error = ref(null);

  // ─── Helpers ──────────────────────────────────────────────────────────────
  const isFresh = (fetchedAt, ttl) =>
    !!fetchedAt && (nowMs() - fetchedAt) < ttl;

  const normalizeWarrantyDetails = (data) => {
    if (!data?.request) return data;
    return {
      ...data.request,
      customer_name: data.customer?.name || data.request.customer_name,
      items: data.items || [],
      attachments: data.attachments || [],
      notes: data.notes || [],
    };
  };

  const refreshWarrantyDetails = async (id) => {
    const data = normalizeWarrantyDetails(await WarrantyService.get(id));
    details.value = data;
    detailsFetchedAt.value[String(id)] = nowMs();
    return data;
  };

  // ─── Getters ──────────────────────────────────────────────────────────────
  const totalWarranties = computed(() => warrantiesTotal.value);
  const openWarranties = computed(() => 
    warranties.value.filter(w => ['open', 'in_progress', 'pending_customer'].includes(w.status))
  );
  const closedWarranties = computed(() =>
    warranties.value.filter(w => ['resolved', 'closed'].includes(w.status))
  );

  // ─── Actions ──────────────────────────────────────────────────────────────

  /**
   * جلب قائمة طلبات الضمان
   */
  const fetchWarranties = async (params = {}, force = false) => {
    let requestId = null;
    try {
      const requestParams = {
        ...params,
        from_date: params.from_date || params.date_from,
        to_date: params.to_date || params.date_to,
      };
      delete requestParams.date_from;
      delete requestParams.date_to;
      const cacheKey = JSON.stringify(
        Object.entries(requestParams)
          .filter(([, value]) => value !== undefined && value !== null && value !== '')
          .sort(([left], [right]) => left.localeCompare(right))
      );

      if (
        !force
        && warrantiesCacheKey.value === cacheKey
        && isFresh(warrantiesFetchedAt.value, TTL.warranties)
      ) {
        latestWarrantiesRequestId += 1;
        isLoading.value = false;
        return {
          status: 'success',
          data: warranties.value,
          total: warrantiesTotal.value,
        };
      }

      requestId = ++latestWarrantiesRequestId;
      isLoading.value = true;
      error.value = null;

      const data = await WarrantyService.list(requestParams);
      const list = Array.isArray(data) ? data : (data?.items || []);
      const total = Array.isArray(data)
        ? list.length
        : Number(data?.total ?? list.length);
      if (requestId === latestWarrantiesRequestId) {
        warranties.value = list;
        warrantiesTotal.value = total;
        warrantiesFetchedAt.value = nowMs();
        warrantiesCacheKey.value = cacheKey;
      }

      return {
        status: 'success',
        data: list,
        total,
      };
    } catch (err) {
      const message = err.response?.data?.message || err.message || 'فشل تحميل طلبات الضمان';
      if (requestId === latestWarrantiesRequestId) {
        error.value = message;
      }
      return {
        status: 'error',
        message,
      };
    } finally {
      if (requestId !== null && requestId === latestWarrantiesRequestId) {
        isLoading.value = false;
      }
    }
  };

  /**
   * جلب تفاصيل طلب ضمان معين
   */
  const fetchWarrantyDetails = async (id, force = false) => {
    try {
      const cacheKey = String(id);
      if (!force && String(details.value?.id) === cacheKey && isFresh(detailsFetchedAt.value[cacheKey], TTL.details)) {
        return {
          status: 'success',
          data: details.value,
        };
      }

      isLoading.value = true;
      error.value = null;

      details.value = await refreshWarrantyDetails(id);

      return {
        status: 'success',
        data: details.value,
      };
    } catch (err) {
      error.value = err.response?.data?.message || err.message || 'فشل تحميل التفاصيل';
      return {
        status: 'error',
        message: error.value,
      };
    } finally {
      isLoading.value = false;
    }
  };

  /**
   * إنشاء طلب ضمان جديد
   */
  const createWarranty = async (payload) => {
    try {
      isLoading.value = true;
      error.value = null;

      const newWarranty = await WarrantyService.create(payload);
      warrantiesFetchedAt.value = null;

      return {
        status: 'success',
        data: newWarranty,
      };
    } catch (err) {
      error.value = err.response?.data?.message || err.message || 'فشل إنشاء الطلب';
      return {
        status: 'error',
        message: error.value,
      };
    } finally {
      isLoading.value = false;
    }
  };

  /**
   * تغيير حالة طلب الضمان
   */
  const updateWarrantyStatus = async (id, status, note = '') => {
    try {
      isLoading.value = true;
      error.value = null;

      await WarrantyService.changeStatus(id, status, note);
      const updated = await refreshWarrantyDetails(id);
      warrantiesFetchedAt.value = null;

      return {
        status: 'success',
        data: updated,
      };
    } catch (err) {
      error.value = err.response?.data?.message || err.message || 'فشل تحديث الحالة';
      return {
        status: 'error',
        message: error.value,
      };
    } finally {
      isLoading.value = false;
    }
  };

  /**
   * إضافة ملاحظة للطلب
   */
  const addNote = async (id, content, isInternal = false) => {
    try {
      error.value = null;

      await WarrantyService.addNote(id, { content, is_internal: isInternal });
      const updated = await refreshWarrantyDetails(id);

      return {
        status: 'success',
        data: updated,
      };
    } catch (err) {
      error.value = err.response?.data?.message || err.message || 'فشل إضافة الملاحظة';
      return {
        status: 'error',
        message: error.value,
      };
    }
  };

  /**
   * رفع مرفق للطلب
   */
  const uploadAttachment = async (id, file) => {
    try {
      error.value = null;

      await WarrantyService.uploadAttachment(id, file);
      const updated = await refreshWarrantyDetails(id);

      return {
        status: 'success',
        data: updated,
      };
    } catch (err) {
      error.value = err.response?.data?.message || err.message || 'فشل رفع المرفق';
      return {
        status: 'error',
        message: error.value,
      };
    }
  };

  /**
   * حذف مرفق
   */
  const deleteAttachment = async (warrantyId, attachmentId) => {
    try {
      error.value = null;

      await WarrantyService.deleteAttachment(attachmentId);
      const updated = await refreshWarrantyDetails(warrantyId);

      return {
        status: 'success',
        data: updated,
      };
    } catch (err) {
      error.value = err.response?.data?.message || err.message || 'فشل حذف المرفق';
      return {
        status: 'error',
        message: error.value,
      };
    }
  };

  /**
   * مسح الـ Cache
   */
  const clearCache = () => {
    warranties.value = [];
    warrantiesTotal.value = 0;
    details.value = null;
    warrantiesFetchedAt.value = null;
    warrantiesCacheKey.value = null;
    detailsFetchedAt.value = {};
  };

  return {
    // State
    warranties: computed(() => warranties.value),
    warrantiesTotal: computed(() => warrantiesTotal.value),
    details: computed(() => details.value),
    isLoading: computed(() => isLoading.value),
    error: computed(() => error.value),

    // Getters
    totalWarranties,
    openWarranties,
    closedWarranties,

    // Actions
    fetchWarranties,
    fetchWarrantyDetails,
    createWarranty,
    updateWarrantyStatus,
    addNote,
    uploadAttachment,
    deleteAttachment,
    clearCache,
  };
});
