<template>
  <div class="min-h-screen bg-[#fafafa] text-slate-900 font-sans antialiased selection:bg-blue-100" dir="rtl">
    <!-- Top Progress Bar -->
    <div v-if="isLoading || isLoadingSearch" class="fixed top-0 left-0 right-0 h-0.5 bg-blue-600/10 z-[110]">
      <div class="h-full bg-blue-600 animate-[loading_2s_ease-in-out_infinite] w-1/3"></div>
    </div>
    <div class="max-w-[1600px] mx-auto p-6 lg:p-10 space-y-8">

      <!-- Page Header -->
      <PageHeader
        :breadcrumb="breadcrumb"
        title="إدارة السندات المالية"
        description="إدارة الحركات النقدية، إصدار سندات القبض والصرف، وتسوية الحسابات."
        :branches="canSelectBranch ? branches : []"
        :selectedBranch="selectedBranch"
        @branch-changed="(val) => { selectedBranch.value = val; handleBranchChange(); }"
      >
        <template #controls>
          <button v-if="canCreateVoucher" @click="onAddVoucher" class="h-9 px-6 rounded-md bg-blue-600 text-white text-xs font-bold shadow-lg shadow-blue-900/20 hover:bg-blue-700 transition-all active:scale-95 flex items-center gap-2">
            <i class="fas fa-plus-circle text-[10px]"></i>
            إضافة سند جديد
          </button>
        </template>
      </PageHeader>

      <transition name="fade">
        <div v-if="pageError" class="bg-rose-50 border border-rose-200 rounded-lg p-4 flex items-center justify-between">
          <span class="text-xs font-bold text-rose-700"><i class="fas fa-exclamation-triangle ml-2"></i>{{ pageError }}</span>
          <div class="flex items-center gap-3">
            <button @click="fetchLists" :disabled="listsLoading" class="text-xs font-bold text-rose-700 disabled:opacity-50">
              {{ listsLoading ? 'جاري إعادة المحاولة...' : 'إعادة المحاولة' }}
            </button>
            <button @click="pageError = ''" class="text-rose-400 hover:text-rose-600"><i class="fas fa-times"></i></button>
          </div>
        </div>
      </transition>

      <!-- Financial KPI Summary -->
      <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">

        <div v-for="kpi in [
          { label: 'إجمالي المقبوضات (وارد)', val: totals.receipts, icon: 'fa-arrow-down-long', color: 'text-emerald-600', bg: 'bg-emerald-50' },
          { label: 'إجمالي المدفوعات (صادر)', val: totals.payments, icon: 'fa-arrow-up-long', color: 'text-rose-600', bg: 'bg-rose-50' },
          { label: 'صافي الحركة النقدية', val: totals.net, icon: 'fa-scale-balanced', color: 'text-blue-600', bg: 'bg-blue-50' }
        ]" :key="kpi.label" class="bg-white border border-slate-200 p-6 rounded-xl flex items-center justify-between group hover:border-slate-300 transition-all shadow-sm">
          <div>
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">{{ kpi.label }}</p>
            <p :class="[kpi.color, 'text-xl font-bold font-mono tracking-tighter']">{{ formatPrice(kpi.val) }}</p>
          </div>
          <div :class="[kpi.bg, kpi.color]" class="w-12 h-12 rounded-lg flex items-center justify-center text-lg opacity-80 group-hover:opacity-100 transition-opacity shadow-inner">
            <i :class="['fas', kpi.icon]"></i>
          </div>
        </div>

      </section>

      <!-- Filters & Search Toolbar -->
      <div class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm relative">
        <div class="p-6 bg-slate-50/30 border-b border-slate-100 space-y-6">
          <div class="grid grid-cols-1 lg:grid-cols-12 gap-4 items-end">
            <div class="lg:col-span-4 space-y-1.5 relative group">
              <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider px-1">بحث سريع</label>
              <div class="relative">
                <input ref="searchInputRef" v-model="search" type="text" class="filter-input-v2 h-9" style="padding-right: 2rem;" placeholder="رقم السند أو المرجع..." @focus="showSearchDropdown = true" @blur="handleSearchBlur" />
                <i class="fas fa-search absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px] pointer-events-none"></i>
                <Teleport to="body">
                  <transition name="dropdown">
                    <div v-if="showSearchDropdown && search.length > 0" ref="searchDropdownRef" class="fixed bg-white border border-slate-200 rounded-lg shadow-2xl overflow-hidden z-[99999]" :style="searchDropdownPosition">
                      <div v-if="isLoadingSearch" class="p-6 text-center">
                        <div class="flex items-center justify-center gap-1">
                          <div class="w-2 h-2 rounded-full bg-slate-400 animate-pulse"></div>
                          <div class="w-2 h-2 rounded-full bg-slate-400 animate-pulse" style="animation-delay: 0.2s"></div>
                          <div class="w-2 h-2 rounded-full bg-slate-400 animate-pulse" style="animation-delay: 0.4s"></div>
                        </div>
                      </div>
                      <template v-else>
                        <div v-for="result in searchResults.slice(0, 10)" :key="result.id" @click="selectSearchResult(result)" class="px-4 py-3 hover:bg-slate-50 cursor-pointer border-b border-slate-50 flex items-center justify-between group">
                          <div>
                            <p class="text-xs font-bold text-slate-900">{{ result.reference_number || '#' + result.id }}</p>
                            <p class="text-[10px] text-slate-400 font-mono">{{ result.date }}</p>
                          </div>
                          <p :class="[result.type === 'receipt' ? 'text-emerald-600' : 'text-rose-600', 'text-xs font-bold font-mono']">
                            {{ result.type === 'receipt' ? '+' : '-' }}{{ formatPrice(result.amount) }}
                          </p>
                        </div>
                      </template>
                    </div>
                  </transition>
                </Teleport>
              </div>
            </div>
            <div class="lg:col-span-3 space-y-1.5">
              <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider px-1">نوع السند</label>
              <select v-model="filterType" class="filter-input-v2 h-9 appearance-none font-bold">
                <option value="all">كل السندات</option>
                <option value="receipt">سندات قبض</option>
                <option value="payment">سندات صرف</option>
              </select>
            </div>
            <div class="lg:col-span-2 space-y-1.5">
              <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider px-1">من تاريخ</label>
              <div class="relative">
                <input ref="dateFromRef" type="date" v-model="dateFrom" class="filter-input-v2 h-9 font-mono" style="padding-left: 2rem;" />
                <i @click="dateFromRef?.showPicker?.()" class="fas fa-calendar absolute left-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px] cursor-pointer hover:text-slate-500 transition-colors"></i>
              </div>
            </div>
            <div class="lg:col-span-2 space-y-1.5">
              <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider px-1">إلى تاريخ</label>
              <div class="relative">
                <input ref="dateToRef" type="date" v-model="dateTo" class="filter-input-v2 h-9 font-mono" style="padding-left: 2rem;" />
                <i @click="dateToRef?.showPicker?.()" class="fas fa-calendar absolute left-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px] cursor-pointer hover:text-slate-500 transition-colors"></i>
              </div>
            </div>
            <div class="lg:col-span-1">
              <button @click="fetchVouchers" class="h-9 w-full rounded-md border border-slate-200 bg-white text-slate-400 hover:text-blue-600 transition-colors shadow-sm">
                <i class="fas fa-sync-alt text-xs"></i>
              </button>
            </div>
          </div>
        </div>
        <!-- Vouchers Table -->
        <div class="overflow-x-auto">
          <table class="w-full text-right border-collapse">

            <thead>
              <tr class="bg-slate-50 border-b border-slate-200">
                <th class="px-6 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center w-16">#</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">تاريخ السند</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">نوع العملية</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">قيمة السند</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">الحساب المتأثر</th>
                <th class="px-6 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">الوصف</th>
                <th class="px-6 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">إجراءات</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 font-medium">
              <template v-if="isLoading">
                <tr v-for="n in 5" :key="n" class="animate-pulse">
                  <td v-for="m in 7" :key="m" class="px-6 py-4"><div class="h-3 bg-slate-100 rounded w-full"></div></td>
                </tr>
              </template>
              <tr v-else-if="vouchers.length === 0">
                <td colspan="7" class="py-20 text-center text-slate-300">
                   <i class="fas fa-folder-open text-3xl mb-4 opacity-20"></i>
                   <p class="text-xs font-bold uppercase tracking-widest">لا توجد سندات مسجلة</p>
                </td>
              </tr>
              <tr v-for="(voucher, idx) in vouchers" :key="voucher.id" class="hover:bg-blue-50/10 transition-all group">
                <td class="px-6 py-4 text-center text-[10px] font-mono text-slate-400">{{ (currentPage - 1) * pageSize + idx + 1 }}</td>
                <td class="px-4 py-4 text-[10px] font-mono font-bold text-slate-400 group-hover:text-slate-900">{{ voucher.date }}</td>
                <td class="px-4 py-4 text-center">
                  <span :class="[voucher.type === 'receipt' ? 'bg-emerald-50 text-emerald-700 border-emerald-100' : 'bg-rose-50 text-rose-700 border-rose-100']" class="px-2 py-0.5 rounded text-[9px] font-bold border">
                    {{ voucher.type === 'receipt' ? 'سند قبض' : 'سند صرف' }}
                  </span>
                  <span v-if="voucher.status === 'cancelled'" class="mr-1 px-2 py-0.5 rounded text-[9px] font-bold border border-slate-200 bg-slate-100 text-slate-500">ملغى</span>
                </td>
                <td class="px-4 py-4 text-xs font-bold font-mono tracking-tighter" :class="voucher.type === 'receipt' ? 'text-emerald-600' : 'text-rose-600'">
                  {{ voucher.type === 'receipt' ? '+' : '-' }}{{ formatPrice(voucher.amount) }}
                </td>
                <td class="px-4 py-4 text-xs font-bold text-slate-700 truncate max-w-[180px]">{{ voucher.account_name }}</td>
                <td class="px-6 py-4 text-[11px] text-slate-400 italic truncate max-w-[200px]" :title="voucher.description">{{ voucher.description || '—' }}</td>
                <td class="px-6 py-4 text-center">
                  <div class="flex items-center justify-center gap-2">
                    <button v-if="canEditVoucher && voucher.status !== 'cancelled'" @click="onEditVoucher(voucher)" class="w-8 h-8 rounded-lg border border-slate-200 text-slate-400 hover:text-blue-600 hover:border-blue-200 transition-all flex items-center justify-center shadow-sm"><i class="fas fa-pen text-[10px]"></i></button>
                    <button v-if="canDeleteVoucher && voucher.status !== 'cancelled'" @click="onDeleteVoucher(voucher)" class="w-8 h-8 rounded-lg border border-slate-200 text-slate-400 hover:text-rose-600 hover:border-rose-200 transition-all flex items-center justify-center shadow-sm" title="إلغاء السند وعكسه"><i class="fas fa-ban text-[10px]"></i></button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <!-- Pagination -->
        <div class="px-6 py-4 bg-slate-50/50 border-t border-slate-200 flex items-center justify-between">
          <div class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">
            صفحة <span class="text-slate-900 font-mono">{{ currentPage }}</span> من <span class="text-slate-900 font-mono">{{ totalPages }}</span>
            <span class="mx-2 text-slate-200">|</span>
            إجمالي <span class="text-slate-900 font-mono">{{ totalCount }}</span> سند
          </div>
          <div class="flex items-center gap-3">
             <div class="flex items-center gap-2">
               <span class="text-[10px] font-bold text-slate-400 uppercase tracking-tighter">النتائج:</span>
               <select v-model.number="pageSize" @change="currentPage = 1; loadVouchers();" class="h-8 border border-slate-200 rounded px-2 text-[10px] font-bold outline-none">
                 <option :value="10">10</option>
                 <option :value="20">20</option>
                 <option :value="50">50</option>
               </select>
             </div>
             <div class="flex items-center gap-1">
               <button @click="previousPage()" :disabled="currentPage <= 1" class="pagination-btn-v2"><i class="fas fa-chevron-right"></i></button>
               <button @click="nextPage(totalPages)" :disabled="currentPage >= totalPages" class="pagination-btn-v2"><i class="fas fa-chevron-left"></i></button>
             </div>
          </div>
        </div>
      </div>
    </div>
    <!-- The Refactored Voucher Form Modal -->
    <VoucherFormModal
      v-if="showFormModal"
      :show="showFormModal"
      :edit-mode="isEditMode"
      :initial-data="form"
      :branches="branches"
      :customers="customersList"
      :suppliers="suppliersList"
      :expenses="expensesList"
      :payment-methods="validPaymentMethods"
      :accounts="allAccounts"
      :is-saving="addLoading || editLoading"
      :is-exempt="isExempt"
      :currency-symbol="currencySymbol"
      :server-error="modalError"
      :lists-loading="listsLoading"
      @close="closeFormModal"
      @save="isEditMode ? submitEditVoucher($event) : submitAddVoucher($event)"
    />

    <!-- Delete Confirmation Modal -->
    <BaseModal :show="showDeleteModal" @close="showDeleteModal = false" maxWidth="sm" align="center" :zIndex="130">
      <template #header>
        <h3 class="text-base font-bold text-slate-900 uppercase">تأكيد إلغاء السند</h3>
      </template>

      <div class="text-center space-y-4">
        <div class="w-14 h-14 bg-rose-50 text-rose-600 rounded-lg flex items-center justify-center mx-auto"><i class="fas fa-trash-alt text-xl"></i></div>
        <p class="text-xs text-slate-400 font-medium leading-relaxed">سيُحفظ السند في السجل، ويُنشأ قيد عكسي لأثره. لا يمكن إلغاء سند مرتبط بفاتورة أو بجلسة كاشير مغلقة.</p>
        <div class="p-3 bg-slate-50 rounded-lg border border-slate-100 text-right space-y-1 font-bold text-[10px]">
          <div class="flex justify-between"><span>الحساب:</span><span>{{ deleteTarget?.account_name }}</span></div>
          <div class="flex justify-between"><span>المبلغ:</span><span class="text-rose-600 font-mono">{{ formatPrice(deleteTarget?.amount) }}</span></div>
        </div>
        <p v-if="deleteError" class="text-xs font-bold text-rose-600">{{ deleteError }}</p>
      </div>

      <template #footer>
        <button @click="showDeleteModal = false" class="flex-1 h-9 text-xs font-bold text-slate-500 hover:text-slate-900">إلغاء</button>
        <button @click="submitDeleteVoucher" :disabled="deleteLoading" class="flex-1 h-9 bg-rose-600 text-white rounded-md text-xs font-bold shadow-lg shadow-rose-900/20 disabled:opacity-50">تأكيد الإلغاء</button>
      </template>
    </BaseModal>

  </div>

</template>

<script setup>
import { ref, onMounted, computed, watch } from 'vue';
import BaseModal from '@/components/BaseModal.vue';
import { useAuthStore } from '@/stores/auth/authStore';
import { useBranchStore } from '@/stores/branch/branchStore';
import { useAccountStore } from '@/stores/account/accountStore';
import { useCustomerStore } from '@/stores/customer/customerStore';
import { useSupplierStore } from '@/stores/supplier/supplierStore';
import { usePaymentStore } from '@/stores/payment/paymentStore';
import { useVoucherStore } from '@/stores/voucher/voucherStore';
import { useCompanyCurrency } from '@/composables/useCompanyCurrency';
import { useTableFilters } from '@/composables/useTableFilters';
import { useSearchDropdown } from '@/composables/useSearchDropdown';
import { useSessionExemption } from '@/composables/useCashierSessionGuard';
import { useBranchIsolation } from '@/composables/useBranchIsolation';
import { useBreadcrumb } from '@/composables/useBreadcrumb';
import { useSessionStore } from '@/stores/session/sessionStore';
import { useProductStore } from '@/stores/product/productStore';
import { useCostCenterStore } from '@/stores/costCenter/costCenterStore';
import VoucherFormModal from '@/components/VoucherForm.vue';
import LoadingButton from '@/components/ui/LoadingButton.vue';
import BaseSkeleton from '@/components/ui/BaseSkeleton.vue';
import { getLocalDateISO, isSettingEnabled } from '@/utils';
import PageHeader from '@/components/PageHeader.vue';

const { breadcrumb } = useBreadcrumb();
const authStore = useAuthStore();
const pendingVoucherStorageKey = `pending_cash_voucher_create:${authStore.user?.tenant_id || 'no-tenant'}:${authStore.user?.id || 'no-user'}`;
const readPendingVoucherRequest = () => {
  try {
    const saved = JSON.parse(sessionStorage.getItem(pendingVoucherStorageKey) || 'null');
    return saved?.key && saved?.payload ? { ...saved, outcomeUnknown: true } : null;
  } catch {
    return null;
  }
};
const pendingVoucherRequest = ref(readPendingVoucherRequest());
const persistPendingVoucherRequest = (pending) => {
  try {
    if (pending) sessionStorage.setItem(pendingVoucherStorageKey, JSON.stringify(pending));
    else sessionStorage.removeItem(pendingVoucherStorageKey);
    pendingVoucherRequest.value = pending;
    return true;
  } catch {
    return false;
  }
};
const branchStore = useBranchStore();
const accountStore = useAccountStore();
const customerStore = useCustomerStore();
const supplierStore = useSupplierStore();
const paymentStore = usePaymentStore();
const voucherStore = useVoucherStore();
const branchIsolation = useBranchIsolation();
const canSelectBranch = branchIsolation.isExempt;
const sessionStore = useSessionStore();
const productStore = useProductStore();
const costCenterStore = useCostCenterStore();
const { isExempt, ensureLoaded: ensureExemptionLoaded } = useSessionExemption();
const { formatCurrencyLocale, fetchSettings, currencySymbol, currencyCode } = useCompanyCurrency();
const {
  page: currentPage, perPage: pageSize, totalCount, dateFrom, dateTo, dateFromRef, dateToRef,
  selectedBranch, branchId, handleBranchChange, previousPage, nextPage, loadFromLocalStorage
} = useTableFilters('cash_vouchers_filters', { initialPageSize: 20, onFilterChange: () => fetchVouchers() });
const {
  search, searchResults, isLoadingSearch, showSearchDropdown, searchInputRef,
  searchDropdownPosition, handleSearchBlur, selectSearchResult
} = useSearchDropdown({
  onSearch: (query) => performSearch(query),
  onSelectResult: (result) => { vouchers.value = [result]; totalCount.value = 1; },
  onClear: () => { currentPage.value = 1; fetchVouchers(); },
});
const vouchers = ref([]);
const isLoading = ref(false);
const filterType = ref('all');
const showFormModal = ref(false);
const isEditMode = ref(false);
const form = ref({});
const addLoading = ref(false);
const editLoading = ref(false);
const branches = computed(() => branchStore.branches);
const pageError = ref('');
const modalError = ref('');
const deleteError = ref('');
const listsLoading = ref(false);
let fetchListsPromise = null;
const totals = computed(() => {
  const activeVouchers = vouchers.value.filter(v => v.status !== 'cancelled');
  const receipts = activeVouchers.filter(v => v.type === 'receipt').reduce((s, v) => s + parseFloat(v.amount), 0);
  const payments = activeVouchers.filter(v => v.type === 'payment').reduce((s, v) => s + parseFloat(v.amount), 0);
  return { receipts, payments, net: receipts - payments };
});

const userPermissions = computed(() => authStore.user?.permissions || []);
const canCreateVoucher = computed(() => authStore.isSuperAdmin || userPermissions.value.includes('voucher.create'));
const canEditVoucher = computed(() => authStore.isSuperAdmin || userPermissions.value.includes('voucher.edit'));
const canDeleteVoucher = computed(() => authStore.isSuperAdmin || userPermissions.value.includes('voucher.delete'));
const totalPages = computed(() => Math.ceil(totalCount.value / pageSize.value));
const fetchVouchers = async () => {
  isLoading.value = true;
  pageError.value = '';
  try {
    const res = await voucherStore.fetchVouchersList({
      branchId: branchId.value || undefined,
      type: filterType.value !== 'all' ? filterType.value : undefined,
      dateFrom: dateFrom.value || undefined,
      dateTo: dateTo.value || undefined,
      search: search.value || undefined,
      page: currentPage.value,
      perPage: pageSize.value
    });
    if (Array.isArray(res)) { vouchers.value = res; totalCount.value = res.length; }
    else if (res?.data?.items) { vouchers.value = res.data.items; totalCount.value = res.data.total || 0; }
    else { vouchers.value = res?.items || res || []; totalCount.value = res?.total || vouchers.value.length; }
  } catch (err) {
    vouchers.value = [];
    pageError.value = err?.message || 'فشل تحميل السندات';
  } finally { isLoading.value = false; }
};
const performSearch = async (query) => {
  if (!query.trim()) { searchResults.value = []; return; }
  isLoadingSearch.value = true;
  try {
    const res = await voucherStore.fetchVouchersList({ search: query.trim(), branchId: branchId.value || undefined, perPage: 50 });
    searchResults.value = Array.isArray(res) ? res : (res?.data?.items || []);
  } catch { searchResults.value = []; } finally { isLoadingSearch.value = false; }
};

const fetchLists = () => {
  if (fetchListsPromise) return fetchListsPromise;
  listsLoading.value = true;
  pageError.value = '';

  fetchListsPromise = (async () => {
    try {
      const [, , paymentMethodsResult] = await Promise.all([
        customerStore.fetchCustomers(),
        supplierStore.fetchSuppliers(),
        paymentStore.fetchPaymentMethods(),
        accountStore.fetchGroupedAccounts(),
        branchStore.fetchBranches()
      ]);
      if (paymentMethodsResult?.status !== 'success') {
        throw new Error(paymentMethodsResult?.message || 'تعذر تحميل طرق الدفع.');
      }
      return true;
    } catch (e) {
      pageError.value = e?.message || 'تعذر تحميل القوائم المرتبطة.';
      return false;
    } finally {
      listsLoading.value = false;
      fetchListsPromise = null;
    }
  })();
  return fetchListsPromise;
};

const getDeviceIdentity = () => {
  let id = localStorage.getItem('pos_device_id');
  if (!id) { id = 'dev-' + Math.random().toString(36).slice(2, 8) + '-' + Date.now().toString().slice(-6); try { localStorage.setItem('pos_device_id', id); } catch {} }
  return { device_id: id };
};
const isCashPayment = (pmId) => {
  if (!pmId) return false;
  const pm = validPaymentMethods.value.find(p => String(p.id) === String(pmId));
  if (!pm) return false;
  return String(pm.kind || '').toLowerCase() === 'cash';
};
const ensureCashierSessionForCash = async (payload) => {
  if (!(Number(payload.amount) > 0 && isCashPayment(payload.payment_method_id))) return true;
  const settings = await fetchSettings(true);
  const sessionsEnabled = isSettingEnabled(settings?.['pos.sessions.enabled']);
  if (!sessionsEnabled || isExempt.value) return true;
  try {
    const { device_id } = getDeviceIdentity();
    const result = await sessionStore.getCurrentSession(payload.branch_id || undefined, undefined, device_id);
    if (!result.data?.id) {
      modalError.value = 'لا توجد جلسة كاشير مفتوحة. لا يمكن إتمام المدفوعات النقدية بدون جلسة.';
      return false;
    }
    return true;
  } catch {
    modalError.value = 'فشل في التحقق من جلسة الكاشير';
    return false;
  }
};

const enrichCostCenter = (payload) => {
  if (authStore.isAdmin) {
    if (costCenterStore.selectedCostCenterId) {
      payload.cost_center_id = costCenterStore.selectedCostCenterId;

    } else if (branchStore.selectedBranchId) {

      const selectedBranchData = branchStore.branches.find(b => b.id === branchStore.selectedBranchId);

      if (selectedBranchData?.cost_center_id) payload.cost_center_id = selectedBranchData.cost_center_id;

    }

  }

  return payload;

};

const onAddVoucher = async () => {
  isEditMode.value = false;
  modalError.value = '';
  await ensureExemptionLoaded();
  if (!await fetchLists()) return;
  let bId = branchStore.selectedBranchId || branches.value[0]?.id || '';
  if (!isExempt.value) {
    try { bId = branchIsolation.getRequiredBranchId(); } catch { /* keep fallback */ }
  }
  if (pendingVoucherRequest.value?.payload) {
    form.value = { ...pendingVoucherRequest.value.payload };
    modalError.value = 'يوجد طلب سند سابق نتيجته غير مؤكدة. أعد المحاولة بنفس البيانات للتحقق قبل إنشاء سند آخر.';
    showFormModal.value = true;
    return;
  }
  form.value = { type: 'receipt', date: getLocalDateISO(), amount: '', branch_id: bId, payment_method_id: '', account_id: '', description: '', payment_to_type: 'supplier', customer_id: null, supplier_id: null, expense_account_id: null, purchase_id: null, sale_id: null };
  showFormModal.value = true;
};
const onEditVoucher = async (v) => {
  isEditMode.value = true;
  modalError.value = '';
  await ensureExemptionLoaded();
  if (!await fetchLists()) return;
  let editData = { ...v };
  if (!('payment_method_id' in editData)) {
    editData.payment_method_id = '';
  }
  if (!editData.branch_id) {
    let userBranchId = '';
    if (!isExempt.value) {
      try {
        userBranchId = branchIsolation.getRequiredBranchId();
      } catch {
        userBranchId = '';
      }
    } else {
      userBranchId = branchStore.selectedBranchId || '';
    }
    if (userBranchId && branches.value.length > 0) {
      const validBranch = branches.value.find(b => String(b.id) === String(userBranchId));
      editData.branch_id = validBranch ? userBranchId : branches.value[0]?.id || '';
    }
  }
  editData.payment_to_type = v.supplier_id ? 'supplier' : (v.expense_account_id ? 'expense' : 'supplier');
  form.value = editData;
  if (form.value.payment_method_id && !form.value.account_id) {
    const pm = validPaymentMethods.value.find(p => p.id === form.value.payment_method_id);
    if (pm?.account_id) {
      form.value.account_id = pm.account_id;
    }
  }
  showFormModal.value = true;
};
const closeFormModal = () => { showFormModal.value = false; modalError.value = ''; };

const submitAddVoucher = async (payload) => {
  if (addLoading.value) return;
  addLoading.value = true;
  modalError.value = '';
  try {
    if (!pendingVoucherRequest.value?.outcomeUnknown) {
      const ok = await ensureCashierSessionForCash(payload);
      if (!ok) return;
    }
    const finalPayload = enrichCostCenter({ ...payload, currency: currencyCode.value });
    const payloadFingerprint = JSON.stringify(finalPayload);
    const pending = pendingVoucherRequest.value;
    if (pending?.outcomeUnknown && pending.fingerprint !== payloadFingerprint) {
      modalError.value = 'بيانات السند تغيّرت بعد طلب غير مؤكد. أعد البيانات الأصلية وحاول بنفس المفتاح أو تحقق من السندات أولًا.';
      return;
    }
    const idempotencyKey = pending?.outcomeUnknown
      ? pending.key
      : (globalThis.crypto?.randomUUID?.()
        || `cash-voucher-${Date.now()}-${Math.random().toString(36).slice(2)}`);
    if (!pending?.outcomeUnknown && !persistPendingVoucherRequest({
      key: idempotencyKey,
      fingerprint: payloadFingerprint,
      payload: finalPayload,
      outcomeUnknown: false,
    })) {
      modalError.value = 'تعذر حفظ مفتاح أمان الطلب في جلسة المتصفح؛ لم يتم إرسال السند. فعّل تخزين الجلسة ثم أعد المحاولة.';
      return;
    }
    await voucherStore.createVoucher(finalPayload, idempotencyKey);
    persistPendingVoucherRequest(null);
    window.dispatchEvent(new CustomEvent('pos:voucher-recorded', { detail: { voucher_type: payload.type, amount: Number(payload.amount), payment_method_id: payload.payment_method_id } }));
    productStore.invalidateCache();
    closeFormModal(); await fetchVouchers();
  } catch (e) {
    const status = Number(e?.response?.status || 0);
    if (status >= 500 || status === 0) {
      const pending = pendingVoucherRequest.value;
      if (pending) persistPendingVoucherRequest({ ...pending, outcomeUnknown: true });
      modalError.value = 'تعذر تأكيد نتيجة حفظ السند. أعد المحاولة بنفس البيانات قبل بدء سند آخر.';
    } else {
      persistPendingVoucherRequest(null);
      modalError.value = e?.response?.data?.message || 'خطأ في الإضافة';
    }
  } finally { addLoading.value = false; }
};
const submitEditVoucher = async (payload) => {
  if (editLoading.value) return;
  editLoading.value = true;
  modalError.value = '';
  try {
    const ok = await ensureCashierSessionForCash(payload);
    if (!ok) return;
    const finalPayload = enrichCostCenter({ ...payload, currency: currencyCode.value });
    await voucherStore.updateVoucher(form.value.id, finalPayload);
    window.dispatchEvent(new Event('pos:session-refresh-request'));
    productStore.invalidateCache();
    closeFormModal(); await fetchVouchers();
  } catch (e) { modalError.value = e?.response?.data?.message || 'خطأ في التعديل'; } finally { editLoading.value = false; }
};
const showDeleteModal = ref(false);
const deleteTarget = ref(null);
const deleteLoading = ref(false);
const onDeleteVoucher = (v) => { deleteTarget.value = v; deleteError.value = ''; showDeleteModal.value = true; };
const submitDeleteVoucher = async () => {
  deleteLoading.value = true;
  deleteError.value = '';
  try {
    await voucherStore.cancelVoucher(deleteTarget.value.id);
    window.dispatchEvent(new Event('pos:session-refresh-request'));
    productStore.invalidateCache();
    showDeleteModal.value = false; await fetchVouchers();
  }
  catch (e) { deleteError.value = e?.response?.data?.message || 'حدث خطأ أثناء حذف السند'; } finally { deleteLoading.value = false; }
};
const customersList = computed(() => customerStore.customers);
const suppliersList = computed(() => supplierStore.suppliers);
const expensesList = computed(() => accountStore.allAccounts.filter(a => a.code?.startsWith('51')));
const allAccounts = computed(() => accountStore.allAccounts);

const validPaymentMethods = computed(() => paymentStore.paymentMethods.filter(pm => pm.account_id));
const formatPrice = (v) => formatCurrencyLocale(v, 2);
watch(filterType, () => { currentPage.value = 1; fetchVouchers(); });
onMounted(async () => {
  await Promise.all([branchStore.initialize(), ensureExemptionLoaded(), fetchSettings()]);
  loadFromLocalStorage();
  if (!canSelectBranch.value && authStore.user?.branch_id) branchStore.setSelectedBranch(authStore.user.branch_id);
  await fetchLists();
  fetchVouchers();
});
</script>

<style scoped>
/* Animations */
@keyframes loading { 0% { transform: translateX(100%); } 100% { transform: translateX(-100%); } }
@keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
@keyframes modalIn { from { opacity: 0; transform: scale(0.98) translateY(10px); } to { opacity: 1; transform: scale(1) translateY(0); } }

/* Form Inputs */
.filter-input-v2 { @apply h-9 w-full bg-white border border-slate-200 rounded-md px-3 text-[11px] font-bold text-slate-700 focus:ring-2 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all; }
.pagination-btn-v2 { @apply w-8 h-8 flex items-center justify-center rounded border border-slate-200 bg-white text-slate-500 hover:text-blue-600 hover:border-blue-200 disabled:opacity-40 transition-all; }

/* Scrollbar */
.custom-scroll::-webkit-scrollbar { width: 5px; }
.custom-scroll::-webkit-scrollbar-thumb { @apply bg-slate-200 rounded-full; }

/* Animation Classes */
.animate-fadeIn { animation: fadeIn 0.4s ease-out; }
.animate-modalIn { animation: modalIn 0.2s cubic-bezier(0.16, 1, 0.3, 1); }

/* Transition Classes */
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>