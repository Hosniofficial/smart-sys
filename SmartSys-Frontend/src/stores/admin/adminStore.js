import { defineStore } from 'pinia';
import { ref } from 'vue';
import adminService from '@/services/admin';
 
export const useAdminStore = defineStore('admin', () => {
  const loading = ref(false);
  const actionLoading = ref(false);
  const securityLoading = ref(false);
 
  // Subscriptions
  async function fetchSubscriptions(params = {}) {
    loading.value = true;
    try {
      const response = await adminService.listSubscriptions(params);
      // API returns { data: { items: [...] } }
      const subscriptions = response.data?.data?.items || response.data?.data || [];
      return {
        status: 'success',
        data: Array.isArray(subscriptions) ? subscriptions : [],
        message: ''
      };
    } catch (error) {
      return {
        status: 'error',
        data: [],
        message: error?.response?.data?.message || error?.message || 'فشل تحميل الاشتراكات'
      };
    } finally {
      loading.value = false;
    }
  }
 
  async function activateSubscription(id, plan = null) {
    actionLoading.value = true;
    try {
      const body = plan ? { plan } : {};
      const response = await adminService.activateSubscription(id, body);
      return {
        status: 'success',
        data: response.data?.data || null,
        message: 'تم التفعيل بنجاح'
      };
    } catch (error) {
      return {
        status: 'error',
        data: null,
        message: error?.response?.data?.message || error?.message || 'فشل التفعيل'
      };
    } finally {
      actionLoading.value = false;
    }
  }
 
  async function expireSubscription(id) {
    actionLoading.value = true;
    try {
      const response = await adminService.expireSubscription(id);
      return {
        status: 'success',
        data: response.data?.data || null,
        message: 'تم الإيقاف'
      };
    } catch (error) {
      return {
        status: 'error',
        data: null,
        message: error?.response?.data?.message || error?.message || 'فشل الإيقاف'
      };
    } finally {
      actionLoading.value = false;
    }
  }
 
  async function extendSubscription(id, days) {
    actionLoading.value = true;
    try {
      const response = await adminService.extendSubscription(id, days);
      return {
        status: 'success',
        data: response.data?.data || null,
        message: 'تم التمديد'
      };
    } catch (error) {
      return {
        status: 'error',
        data: null,
        message: error?.response?.data?.message || error?.message || 'فشل التمديد'
      };
    } finally {
      actionLoading.value = false;
    }
  }
 
  async function changeSubscriptionPlan(id, newPlan, prorate = false, extendPeriod = false) {
    actionLoading.value = true;
    try {
      const body = {
        new_plan: newPlan,
        prorate,
        extend_period: extendPeriod
      };
      const response = await adminService.changeSubscriptionPlan(id, body);
      return {
        status: 'success',
        data: response.data?.data || null,
        message: 'تم تغيير الخطة بنجاح'
      };
    } catch (error) {
      return {
        status: 'error',
        data: null,
        message: error?.response?.data?.message || error?.message || 'فشل تغيير الخطة'
      };
    } finally {
      actionLoading.value = false;
    }
  }
 
  async function refreshSubscriptionSecurity(id) {
    securityLoading.value = true;
    try {
      const response = await adminService.refreshSubscriptionSecurity(id);
      return {
        status: 'success',
        data: response.data?.data || null,
        message: 'تم تحديث البيانات الأمنية'
      };
    } catch (error) {
      return {
        status: 'error',
        data: null,
        message: error?.response?.data?.message || error?.message || 'فشل التحديث'
      };
    } finally {
      securityLoading.value = false;
    }
  }
 
  async function blockSubscription(id) {
    securityLoading.value = true;
    try {
      const response = await adminService.blockSubscription(id);
      return {
        status: 'success',
        data: response.data?.data || null,
        message: 'تم حظر الاشتراك'
      };
    } catch (error) {
      return {
        status: 'error',
        data: null,
        message: error?.response?.data?.message || error?.message || 'فشل الحظر'
      };
    } finally {
      securityLoading.value = false;
    }
  }
 
  // Plans
  async function fetchPlans() {
    loading.value = true;
    try {
      const response = await adminService.listPlans();
      // API returns { data: { items: [...] } }
      const plansList = response.data?.data?.items || response.data?.data || [];
      const plans = Array.isArray(plansList) ? plansList.map(p => ({ ...p, is_active: Number(p.is_active) })) : [];
      return {
        status: 'success',
        data: plans,
        message: ''
      };
    } catch (error) {
      return {
        status: 'error',
        data: [],
        message: error?.response?.data?.message || error?.message || 'فشل تحميل الخطط'
      };
    } finally {
      loading.value = false;
    }
  }
 
  async function createPlan(planData) {
    actionLoading.value = true;
    try {
      const response = await adminService.createPlan(planData);
      return {
        status: 'success',
        data: response.data?.data || null,
        message: 'تمت إضافة الخطة بنجاح'
      };
    } catch (error) {
      return {
        status: 'error',
        data: null,
        message: error?.response?.data?.message || error?.message || 'فشل الإضافة'
      };
    } finally {
      actionLoading.value = false;
    }
  }
 
  async function updatePlan(code, planData) {
    actionLoading.value = true;
    try {
      const response = await adminService.updatePlan(code, planData);
      return {
        status: 'success',
        data: response.data?.data || null,
        message: 'تم حفظ التعديلات'
      };
    } catch (error) {
      return {
        status: 'error',
        data: null,
        message: error?.response?.data?.message || error?.message || 'فشل حفظ الخطة'
      };
    } finally {
      actionLoading.value = false;
    }
  }
 
  async function deletePlan(code) {
    actionLoading.value = true;
    try {
      const response = await adminService.deletePlan(code);
      return {
        status: 'success',
        data: response.data?.data || null,
        message: 'تم حذف الخطة'
      };
    } catch (error) {
      return {
        status: 'error',
        data: null,
        message: error?.response?.data?.message || error?.message || 'فشل الحذف'
      };
    } finally {
      actionLoading.value = false;
    }
  }
 
  // Data Integrity
  async function fetchCustomersMissingAccounts() {
    loading.value = true;
    try {
      const response = await adminService.listCustomersMissingAccounts();
      const payload = response.data?.data?.items ?? response.data?.data ?? [];
      const customers = Array.isArray(payload) ? payload : [];
      return {
        status: 'success',
        data: customers,
        message: ''
      };
    } catch (error) {
      return {
        status: 'error',
        data: [],
        message: error?.message || 'فشل في جلب البيانات'
      };
    } finally {
      loading.value = false;
    }
  }
 
  async function fetchSuppliersMissingAccounts() {
    loading.value = true;
    try {
      const response = await adminService.listSuppliersMissingAccounts();
      const payload = response.data?.data?.items ?? response.data?.data ?? [];
      const suppliers = Array.isArray(payload) ? payload : [];
      return {
        status: 'success',
        data: suppliers,
        message: ''
      };
    } catch (error) {
      return {
        status: 'error',
        data: [],
        message: error?.message || 'فشل في جلب البيانات'
      };
    } finally {
      loading.value = false;
    }
  }
 
  async function ensureCustomerAccount(customerId) {
    try {
      const response = await adminService.ensureCustomerAccount(customerId);
      return {
        status: 'success',
        data: response.data?.data || null,
        message: 'تم إصلاح ربط العميل'
      };
    } catch (error) {
      return {
        status: 'error',
        data: null,
        message: error?.response?.data?.message || error?.message || 'فشل إصلاح العميل'
      };
    }
  }
 
  async function ensureSupplierAccount(supplierId) {
    try {
      const response = await adminService.ensureSupplierAccount(supplierId);
      return {
        status: 'success',
        data: response.data?.data || null,
        message: 'تم إصلاح ربط المورد'
      };
    } catch (error) {
      return {
        status: 'error',
        data: null,
        message: error?.response?.data?.message || error?.message || 'فشل إصلاح المورد'
      };
    }
  }
 
  return {
    loading,
    actionLoading,
    securityLoading,
    // Subscriptions
    fetchSubscriptions,
    activateSubscription,
    expireSubscription,
    extendSubscription,
    changeSubscriptionPlan,
    refreshSubscriptionSecurity,
    blockSubscription,
    // Plans
    fetchPlans,
    createPlan,
    updatePlan,
    deletePlan,
    // Data Integrity
    fetchCustomersMissingAccounts,
    fetchSuppliersMissingAccounts,
    ensureCustomerAccount,
    ensureSupplierAccount
  };
});
