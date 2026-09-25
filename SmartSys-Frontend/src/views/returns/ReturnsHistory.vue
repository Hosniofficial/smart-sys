<template>
  <div class="min-h-screen bg-[#fafafa] text-slate-900 font-sans antialiased selection:bg-rose-100" dir="rtl">
    
    <!-- Top Progress Bar -->
    <div v-if="isLoadingReturns || isLoadingDetails" class="fixed top-0 left-0 right-0 h-0.5 bg-rose-600/10 z-[110]">
      <div class="h-full bg-rose-600 animate-[loading_2s_ease-in-out_infinite] w-1/3"></div>
    </div>

    <div class="max-w-[1600px] mx-auto p-6 lg:p-10 space-y-8">
      
      <!-- Page Header -->
      <PageHeader
        :breadcrumb="breadcrumb"
        title="إدارة المرتجعات"
        description="مراجعة وتدقيق عمليات الإرجاع للمبيعات والمشتريات."
        :branches="branches"
        :selectedBranch="selectedBranch"
        :hasExplicitSelection="hasExplicitBranchSelection"
        @branch-changed="onBranchChange"
      >
        <template #controls>
          <button 
            @click="filters.showFilters.value = !filters.showFilters.value" 
            :class="[filters.showFilters.value ? 'bg-slate-900 text-white' : 'bg-white text-slate-700 border-slate-200']"
            class="h-9 px-4 rounded-md border text-xs font-bold transition-all flex items-center gap-2 shadow-sm"
          >
            <i class="fas fa-filter text-[10px]"></i>
            {{ filters.showFilters.value ? 'إخفاء الفلاتر' : 'تصفية المرتجعات' }}
          </button>
        </template>
      </PageHeader>

      <!-- KPI Summary -->
      <section class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-6">
        <div v-for="kpi in [
          { label: 'عدد المرتجعات', val: kpiCount, icon: 'fa-clipboard-list', color: 'text-blue-600', bg: 'bg-blue-50' },
          { label: 'إجمالي القيمة', val: formatPrice(kpiSum), icon: 'fa-file-invoice-dollar', color: 'text-rose-600', bg: 'bg-rose-50' },
          { label: 'إجمالي الضريبة', val: formatPrice(kpiTax || 0), icon: 'fa-percent', color: 'text-indigo-600', bg: 'bg-indigo-50', show: kpiTax != null },
          { label: 'إجمالي الخصومات', val: formatPrice(kpiDiscount || 0), icon: 'fa-tags', color: 'text-amber-600', bg: 'bg-amber-50', show: kpiDiscount != null }
        ].filter(k => k.show !== false)" :key="kpi.label" class="bg-white border border-slate-200 p-5 rounded-xl flex items-center justify-between group hover:border-slate-300 transition-all shadow-sm">
          <div>
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">{{ kpi.label }}</p>
            <p class="text-xl font-bold text-slate-900">{{ kpi.val }}</p>
          </div>
          <div :class="[kpi.bg, kpi.color]" class="w-10 h-10 rounded-lg flex items-center justify-center text-sm opacity-80 group-hover:opacity-100 transition-opacity">
            <i :class="['fas', kpi.icon]"></i>
          </div>
        </div>
      </section>

      <!-- Filters Panel -->
      <transition name="slide-down">
        <div v-if="filters.showFilters.value" class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden animate-fadeIn">
          <div class="p-6 space-y-6">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
              <div class="space-y-1.5">
                <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">بحث سريع</label>
                <div class="relative">
                  <input v-model="filters.searchQuery.value" type="text" class="filter-input" style="padding-right: 2rem;" placeholder="رقم المرتجع أو الفاتورة..." />
                  <i class="fas fa-search absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px] pointer-events-none"></i>
                </div>
              </div>

              <div class="space-y-1.5">
                <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">من تاريخ</label>
                <div class="relative">
                  <input ref="dateFromRef" type="date" v-model="filters.dateFrom.value" class="filter-input" style="padding-left: 2rem;" />
                  <i @click="dateFromRef?.showPicker?.()" class="fas fa-calendar absolute left-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px] cursor-pointer hover:text-slate-500 transition-colors"></i>
                </div>
              </div>
              <div class="space-y-1.5">
                <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">إلى تاريخ</label>
                <div class="relative">
                  <input ref="dateToRef" type="date" v-model="filters.dateTo.value" class="filter-input" style="padding-left: 2rem;" />
                  <i @click="dateToRef?.showPicker?.()" class="fas fa-calendar absolute left-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px] cursor-pointer hover:text-slate-500 transition-colors"></i>
                </div>
              </div>

              <div class="space-y-1.5">
                <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">نوع المرتجع</label>
                <select v-model="type" class="filter-input appearance-none">
                  <option value="sales">مرتجعات المبيعات</option>
                  <option value="purchases">مرتجعات المشتريات</option>
                </select>
              </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end pt-4 border-t border-slate-50">
              <div class="md:col-span-2 space-y-1.5 relative">
                <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">{{ type === 'sales' ? 'تصفية حسب العميل' : 'تصفية حسب المورد' }}</label>
                <div class="relative">
                  <input v-model="filters.customerSearch.value" type="text" class="filter-input" style="padding-right: 2rem;" :placeholder="type === 'sales' ? 'ابحث عن عميل...' : 'ابحث عن مورد...'" @focus="filters.showCustomerDropdown.value = true" @blur="hidePartyDropdown" />
                  <i class="fas fa-user-circle absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px] pointer-events-none"></i>
                  <div v-if="filters.showCustomerDropdown.value" class="absolute z-50 w-full mt-1 bg-white border border-slate-200 rounded-lg shadow-xl max-h-48 overflow-auto">
                    <button @mousedown.prevent="filters.clearCustomerFilter()" class="w-full text-right px-4 py-2 text-[11px] hover:bg-slate-50 border-b border-slate-50 text-rose-600 font-bold">الكل</button>
                    <button v-for="p in filteredParties" :key="p.id" @mousedown.prevent="selectParty(p)" class="w-full text-right px-4 py-2 text-[11px] hover:bg-slate-50 border-b border-slate-50 last:border-0 font-medium">{{ p.name || p.customer_name || p.supplier_name }}</button>
                  </div>
                </div>
              </div>

              <button @click="resetFilters" class="h-9 w-full rounded-md bg-slate-100 text-slate-600 text-[11px] font-bold hover:bg-slate-200 transition-all">إعادة تعيين الفلاتر</button>
            </div>
          </div>
        </div>
      </transition>

      <!-- Active Filter Chips -->
      <div v-if="filters.hasActiveFilters.value || filters.customerFilter.value || (isExempt.value && hasExplicitBranchSelection)" class="flex flex-wrap gap-2">
        <div v-for="chip in [
          { show: filters.searchQuery.value, label: filters.searchQuery.value, clear: () => filters.searchQuery.value = '' },
          { show: filters.dateFrom.value, label: 'من: ' + filters.dateFrom.value, clear: () => filters.dateFrom.value = '' },
          { show: filters.dateTo.value, label: 'إلى: ' + filters.dateTo.value, clear: () => filters.dateTo.value = '' },
          { show: filters.customerFilter.value, label: filters.customerSearch.value, clear: () => filters.clearCustomerFilter() },
          { show: isExempt.value && hasExplicitBranchSelection, label: 'الفرع: ' + (branches?.find(b => b.id == selectedBranch)?.name || selectedBranch), clear: () => onBranchChange(null) }
        ].filter(c => c.show)" :key="chip.label" class="inline-flex items-center gap-2 px-2.5 py-1 bg-rose-50 border border-rose-100 rounded-md text-[10px] font-bold text-rose-700">
          {{ chip.label }} <i @click="chip.clear" class="fas fa-times cursor-pointer hover:text-rose-900 opacity-60"></i>
        </div>
      </div>

      <!-- Main Data Table -->
      <div class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm relative">
        <div class="overflow-x-auto">
          <table class="w-full text-right border-collapse">
            <thead>
              <tr class="bg-slate-50 border-b border-slate-200">
                <th class="px-6 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">رقم المرتجع</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">رقم الفاتورة</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">التاريخ</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">القيمة</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">النوع</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">إجراءات</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <template v-if="isLoadingReturns">
                <tr v-for="n in 5" :key="n" class="animate-pulse"><td v-for="m in 6" :key="m" class="px-6 py-4"><div class="h-3 bg-slate-100 rounded w-full"></div></td></tr>
              </template>
              <tr v-else-if="!rows.length"><td colspan="6" class="py-20 text-center text-slate-300"><i class="fas fa-box-open text-3xl mb-4 opacity-20"></i><p class="text-xs font-bold uppercase tracking-widest">لا توجد فواتير مرتجعات حتى الآن</p></td></tr>
              <tr v-for="r in rows" :key="r.id" class="hover:bg-rose-50/10 transition-all group">
                <td class="px-6 py-4 text-xs font-bold text-slate-900 font-mono tracking-wider">{{ r.return_number || ('RET-' + String(r.id).padStart(4, '0')) }}</td>
                <td class="px-4 py-4 text-xs font-bold text-slate-400 font-mono">{{ r.invoice_number || '-' }}</td>
                <td class="px-4 py-4 text-[10px] font-mono text-slate-400">{{ formatDate(r.return_date || r.created_at) }}</td>
                <td class="px-4 py-4 text-xs font-bold text-rose-600">{{ formatPrice(r.grand_total || r.total_amount || r.total || 0) }}</td>
                <td class="px-4 py-4 text-center">
                  <span :class="[type === 'sales' ? 'bg-indigo-50 text-indigo-600 border-indigo-100' : 'bg-amber-50 text-amber-600 border-amber-100']" class="px-2 py-0.5 rounded text-[9px] font-bold border">
                    {{ type === 'sales' ? 'مرتجع بيع' : 'مرتجع شراء' }}
                  </span>
                </td>
                <td class="px-4 py-4 text-center">
                  <button @click="viewReturnDetails(r)" :disabled="isLoadingDetails" class="w-8 h-8 rounded-lg border border-slate-200 text-slate-400 hover:text-rose-600 hover:border-rose-200 disabled:opacity-50 disabled:cursor-not-allowed transition-all flex items-center justify-center mx-auto"><i class="fas fa-eye text-[10px]"></i></button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Pagination -->
        <div class="px-6 py-4 bg-slate-50/50 border-t border-slate-200 flex items-center justify-between">
          <div class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">
            صفحة <span class="text-slate-900">{{ filters.page.value }}</span> من <span class="text-slate-900">{{ totalPages }}</span>
            <span class="mx-2 text-slate-200">|</span> إجمالي <span class="text-slate-900">{{ filters.total.value }}</span> سجل
          </div>
          <div class="flex items-center gap-3">
             <div class="flex items-center gap-2"><span class="text-[10px] font-bold text-slate-400 uppercase tracking-tighter">النتائج:</span><select v-model.number="filters.perPage.value" class="h-8 border border-slate-200 rounded px-2 text-[10px] font-bold outline-none"><option :value="10">10</option><option :value="20">20</option><option :value="50">50</option></select></div>
             <div class="flex items-center gap-1"><button @click="filters.previousPage()" :disabled="filters.page.value<=1" class="pagination-btn-v2"><i class="fas fa-chevron-right"></i></button><button @click="filters.nextPage(totalPages)" :disabled="filters.page.value>=totalPages" class="pagination-btn-v2"><i class="fas fa-chevron-left"></i></button></div>
          </div>
        </div>
      </div>
    </div>

    <!-- Details Modal -->
    <BaseModal :show="showDetailsModal && !!selectedReturn" @close="showDetailsModal = false" maxWidth="3xl">
      <template #header>
        <div class="flex items-center gap-3">
          <div class="w-8 h-8 bg-rose-600 rounded-lg flex items-center justify-center text-white text-xs"><i class="fas fa-undo-alt"></i></div>
          <h3 class="text-sm font-bold text-slate-900 uppercase">تفاصيل المرتجع {{ selectedReturn?.return_number || ('RET-' + String(selectedReturn?.id).padStart(4, '0')) }}</h3>
        </div>
      </template>

      <div class="space-y-8">
        <div class="grid grid-cols-2 md:grid-cols-4 gap-6">
          <div v-for="info in [
            { l: 'فاتورة مرتبطة', v: selectedReturn?.invoice_number || '-' },
            { l: 'الطرف المعني', v: type === 'sales' ? (selectedReturn?.customer_name || selectedReturn?.customer || '-') : (selectedReturn?.supplier_name || selectedReturn?.supplier || '-') },
            { l: 'الحالة', v: selectedReturn?.status || selectedReturn?.state || '-', c: 'text-blue-600 font-bold' },
            { l: 'التاريخ', v: formatDate(selectedReturn?.return_date || selectedReturn?.created_at) },
            { l: 'إجمالي القيمة', v: formatPrice(selectedReturn?.grand_total || selectedReturn?.total_amount || selectedReturn?.total || 0), c: 'text-rose-600 font-bold' }
          ]" :key="info.l" class="space-y-1">
            <p class="text-[9px] font-bold text-slate-400 uppercase tracking-widest">{{ info.l }}</p>
            <p :class="[info.c || 'text-slate-800', 'text-xs font-bold']">{{ info.v }}</p>
          </div>
        </div>

        <div v-if="selectedReturn?.notes || selectedReturn?.remarks" class="p-4 rounded-lg bg-slate-50 border border-slate-100 text-xs text-slate-500 italic">{{ selectedReturn?.notes || selectedReturn?.remarks }}</div>

        <div class="border border-slate-100 rounded-xl overflow-hidden shadow-sm">
          <table class="w-full text-right text-xs">
            <thead><tr class="bg-slate-50 border-b border-slate-100"><th class="px-4 py-3 text-[10px] font-bold text-slate-400 uppercase">الصنف</th><th class="px-4 py-3 text-[10px] font-bold text-slate-400 uppercase text-center">الكمية</th><th class="px-4 py-3 text-[10px] font-bold text-slate-400 uppercase">السعر</th><th class="px-4 py-3 text-[10px] font-bold text-slate-400 uppercase">الإجمالي</th></tr></thead>
            <tbody class="divide-y divide-slate-50">
              <tr v-for="item in selectedReturn?.items" :key="item.id || item.product_id">
                <td class="px-4 py-3 font-bold text-slate-800">{{ item.product_name || item.name || 'منتج غير معروف' }}</td>
                <td class="px-4 py-3 text-center font-bold text-slate-500">{{ item.quantity || item.qty || '-' }}</td>
                <td class="px-4 py-3 font-bold text-slate-700">{{ formatPrice(item.sale_price || item.unit_price || item.price || 0) }}</td>
                <td class="px-4 py-3 font-bold text-slate-900">{{ formatPrice(item.total || item.net_total || item.amount || 0) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <template #footer>
        <button @click="showDetailsModal = false" class="px-6 h-9 bg-slate-900 text-white rounded-md text-xs font-bold transition-all hover:bg-slate-800">إغلاق</button>
      </template>
    </BaseModal>

  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import BaseModal from '@/components/BaseModal.vue';
import { useRoute } from 'vue-router';
import { useHistoryFilters } from '@/composables/useHistoryFilters';
import { useLoader } from '@/composables/useLoader';
import { useToast } from '@/composables/useToast';
import { useSessionExemption } from '@/composables/useCashierSessionGuard';
import { useBranchIsolation } from '@/composables/useBranchIsolation';
import { useBreadcrumb } from '@/composables/useBreadcrumb';
import { useAuthStore } from '@/stores/auth';
import { useBranchStore } from '@/stores/branch';
import { useCustomerStore } from '@/stores/customer/customerStore';
import { useSupplierStore } from '@/stores/supplier/supplierStore';
import { useReturnStore } from '@/stores/return/returnStore';
import { useBootstrapStore } from '@/stores/bootstrap';
import { useCompanyCurrency } from '@/composables/useCompanyCurrency';

import BaseSkeleton from '@/components/ui/BaseSkeleton.vue';
import PageHeader from '@/components/PageHeader.vue';

const { showLoader, hideLoader } = useLoader();
const { showToast } = useToast();
const { formatCurrencyLocale, fetchSettings } = useCompanyCurrency();
const { isExempt, ensureLoaded: ensureExemptionLoaded } = useSessionExemption();
const { breadcrumb } = useBreadcrumb();
const authStore = useAuthStore();
const branchStore = useBranchStore();
const branchIsolation = useBranchIsolation();
const customerStore = useCustomerStore();
const returnStore = useReturnStore();
const supplierStore = useSupplierStore();
const bootstrapStore = useBootstrapStore();

const route = useRoute();
const detailsAbortCtrl = ref(null);
const showDetailsModal = ref(false);
const selectedReturn = ref(null);

const filters = useHistoryFilters('returns_hist_filters');
filters.loadFromLocalStorage();

const type = ref('sales');
const rows = ref([]);
const isLoadingReturns = ref(false);
const isLoadingDetails = ref(false);

// ✅ Date picker refs
const dateFromRef = ref(null);
const dateToRef = ref(null);

const branches = computed(() => branchStore.branches);
const selectedBranch = computed({
  get: () => branchStore.selectedBranchId,
  set: (val) => branchStore.setSelectedBranch(val)
});

// ✅ يتحقق من ما إذا كان المستخدم اختار فرع بشكل صريح من الـ dropdown
// لا يكفي فحص localStorage لأن fetchBranches() يكتب default branch فيه تلقائياً
// لذلك نتتبع الاختيار اليدوي بـ flag منفصل
const userChoseBranch = ref(localStorage.getItem('selectedBranchId') !== null && localStorage.getItem('selectedBranchId') !== 'all');

const hasExplicitBranchSelection = computed(() => userChoseBranch.value && branchStore.selectedBranchId !== null);

const onBranchChange = (newBranchId) => {
  branchStore.setSelectedBranch(newBranchId);
  // إذا اختار المستخدم فرعاً محدداً (وليس 'all'/null) — سجّل الاختيار
  userChoseBranch.value = (newBranchId !== null && newBranchId !== '' && newBranchId !== 'all');
  filters.page.value = 1;
  fetchReturns(true);  // ✅ force=true
};
const customers = computed(() => customerStore.customers);
const suppliers = computed(() => supplierStore.suppliers);

const filteredParties = computed(() => {
  const list = type.value === 'sales' ? (customers.value || []) : (suppliers.value || []);
  const q = (filters.customerSearch.value || '').toLowerCase();
  const getName = type.value === 'sales' ? (c => c.name || c.customer_name || '') : (s => s.name || s.supplier_name || '');
  if (!q) return list.slice(0, 50);
  return list.filter(item => String(getName(item)).toLowerCase().includes(q)).slice(0, 50);
});

const selectParty = (p) => {
  filters.setCustomerFilter(p.id, p.name || p.customer_name || p.supplier_name || '');
};

const hidePartyDropdown = () => filters.hideCustomerDropdown();

const kpiCount = computed(() => filters.total.value || rows.value.length);
const kpiSum = computed(() => rows.value.reduce((s, r) => s + parseFloat(r.grand_total || r.total_amount || r.total || 0), 0));
const kpiTax = computed(() => {
  const any = rows.value.some(r => r.tax_amount != null);
  return any ? rows.value.reduce((s, r) => s + parseFloat(r.tax_amount || 0), 0) : null;
});
const kpiDiscount = computed(() => {
  const any = rows.value.some(r => r.discount_amount != null || r.discount_value != null);
  return any ? rows.value.reduce((s, r) => s + parseFloat((r.discount_amount ?? r.discount_value) || 0), 0) : null;
});
const totalPages = computed(() => Math.max(1, Math.ceil((filters.total.value || 0) / filters.perPage.value)));

const formatPrice = (amount) => formatCurrencyLocale(amount, 2);
const formatDate = (date) => date ? new Date(date).toLocaleDateString('en-US', { day: '2-digit', month: '2-digit', year: 'numeric' }) : '';

let returnsAbortCtrl = null;

const fetchReturns = async (forceRefresh = false) => {
  if (returnsAbortCtrl) returnsAbortCtrl.abort();
  returnsAbortCtrl = new AbortController();
  const currentCtrl = returnsAbortCtrl;
  isLoadingReturns.value = true;
  try {
    let branchId = selectedBranch.value || null;
    if (!isExempt.value) {
      try { branchId = branchIsolation.getRequiredBranchId(); }
      catch (e) { rows.value = []; filters.total.value = 0; showToast(e.message || 'لم يتم تعيين الفرع.', 'error'); return; }
    }
    const params = { ...filters.getApiParams({ type: type.value, partyId: filters.customerFilter.value || undefined, force: forceRefresh }), branchId: branchId };
    
    // ✅ Log: تحقق من القيم قبل الإرسال
    console.log('[fetchReturns] API Call params:', {
      branchId,
      selectedBranchId: branchStore.selectedBranchId,
      isExempt: isExempt.value,
      paramsPayload: params
    });
    
    const response = await returnStore.fetchReturnsList(params);
    if (currentCtrl !== returnsAbortCtrl) return;
    if (response?.status === 'success') {
      const resData = response.data;
      const list = Array.isArray(resData) ? resData : (resData?.items || []);
      rows.value = list; filters.total.value = resData?.total || list.length;
    } else { rows.value = []; filters.total.value = 0; }
  } catch (e) {
    if (e?.name !== 'AbortError' && e?.name !== 'CanceledError' && e?.code !== 'ERR_CANCELED') {
      showToast('فشل في تحميل سجل المرتجعات', 'error');
      rows.value = [];
      filters.total.value = 0;
    }
  } finally { if (currentCtrl === returnsAbortCtrl) isLoadingReturns.value = false; hideLoader(); }
};

const resetFilters = () => { filters.resetFilters(); type.value = 'sales'; fetchReturns(); };

const viewReturnDetails = async (returnRow) => {
  if (!returnRow || !returnRow.id) return;
  if (detailsAbortCtrl.value) detailsAbortCtrl.value.abort();
  detailsAbortCtrl.value = new AbortController();
  const currentCtrl = detailsAbortCtrl.value;
  isLoadingDetails.value = true; showLoader();
  try {
    const response = await returnStore.fetchReturnDetails(returnRow.id, type.value, { signal: currentCtrl.signal });
    if (currentCtrl !== detailsAbortCtrl.value) return;
    if (response?.status === 'success') { selectedReturn.value = response.data || response; showDetailsModal.value = true; }
    else showToast('فشل في تحميل تفاصيل المرتجع', 'error');
  } catch (e) {
    if (e?.name !== 'AbortError' && e?.name !== 'CanceledError' && e?.code !== 'ERR_CANCELED') {
      showToast('فشل في تحميل تفاصيل المرتجع', 'error');
    }
  } finally { if (currentCtrl === detailsAbortCtrl.value) isLoadingDetails.value = false; hideLoader(); }
};

let searchDebounceTimer = null;
// isMounting flag: يمنع الـ watches من إطلاق fetchReturns() أثناء onMounted
// لأن تحميل البيانات (customers, branches, etc.) يُغيّر reactive values فيُطلق الـ watch مبكراً
let isMounting = true;

watch(filters.searchQuery, () => { 
  if (isMounting) return;
  clearTimeout(searchDebounceTimer); filters.page.value = 1; searchDebounceTimer = setTimeout(fetchReturns, 400); 
});
// selectedBranch مُزال من الـ watch — تغيير الفرع يُعالج عبر onBranchChange() مباشرة
// إبقاؤه هنا يُسبب race condition: fetchBranches() تُغيّر selectedBranchId أثناء onMounted فيُطلق watch مبكراً
watch([filters.customerFilter, type, filters.dateFrom, filters.dateTo], () => { 
  if (isMounting) return;
  filters.page.value = 1; fetchReturns(); 
});
watch(filters.page, () => { if (isMounting) return; fetchReturns(); });
watch([filters.customerFilter, type, customers, suppliers], () => {
  const list = type.value === 'sales' ? (customers.value || []) : (suppliers.value || []);
  const found = list.find(x => String(x.id) === String(filters.customerFilter.value));
  filters.customerSearch.value = found ? (found.name || found.customer_name || found.supplier_name || '') : '';
});

onMounted(async () => {
  try {
    const data = await bootstrapStore.fetchPaymentsData();
    if (data.customers) customerStore.customers = data.customers;
    if (data.suppliers) supplierStore.suppliers = data.suppliers;
    if (data.branches && isExempt.value) branchStore.setBranches(data.branches);
    await Promise.all([fetchSettings(), ensureExemptionLoaded()]);
  } catch {
    await Promise.all([fetchSettings(), ensureExemptionLoaded()]);
    if (isExempt.value) await branchStore.fetchBranches().catch(() => {});
    await customerStore.fetchCustomers().catch(() => {});
    await supplierStore.fetchSuppliers().catch(() => {});
  }
  
  // ✅ FIX: تهيئة/استعادة branch context قبل أول API call
  // سجّل ما إذا كان المستخدم اختار فرعاً في جلسة سابقة (قبل fetchBranches يكتب default)
  const hadPriorBranchChoice = localStorage.getItem('selectedBranchId') !== null 
                                && localStorage.getItem('selectedBranchId') !== 'all';
  try {
    branchStore.loadFromStorage();
    if (!branchStore.branches || branchStore.branches.length === 0) {
      await branchStore.fetchBranches();
    }
  } catch (err) {
    console.error('[ReturnsHistory] Failed to initialize branches:', err);
    showToast('فشل في تحميل قائمة الفروع', 'error');
    return;
  }
  // بعد fetchBranches: أعد تعيين الـ flag بما كان موجوداً قبل الكتابة
  // لأن fetchBranches() قد تكتب selectedBranchId في localStorage تلقائياً
  userChoseBranch.value = hadPriorBranchChoice;
  
  // الخطوة 2: التحقق من أن selectedBranchId جاهز فعلياً
  let resolvedBranchId = branchStore.selectedBranchId;
  if (!isExempt.value) {
    // للمستخدم غير exempt: استخدم getRequiredBranchId()
    try {
      resolvedBranchId = branchIsolation.getRequiredBranchId();
    } catch (err) {
      console.error('[ReturnsHistory] Failed to resolve branch ID:', err);
      showToast(err.message || 'لم يتم تعيين الفرع', 'error');
      return;
    }
  }
  
  // الخطوة 3: Log واضح قبل أول API call
  const selectedBranchName = branchStore.selectedBranch?.name || null;
  console.log('[ReturnsHistory] Before first API call:', {
    selectedBranchId: branchStore.selectedBranchId,
    selectedBranch: selectedBranchName,
    isExempt: isExempt.value,
    resolvedBranchId,
    branchesCount: branchStore.branches.length
  });
  
  // الآن آمن للاستدعاء — رفع الـ flag أولاً
  isMounting = false;
  await new Promise(resolve => setTimeout(resolve, 100));
  fetchReturns();
  const qid = Number(route.query.id || 0);
  if (qid > 0) await viewReturnDetails({ id: qid });
});

onUnmounted(() => { if (returnsAbortCtrl) returnsAbortCtrl.abort(); if (searchDebounceTimer) clearTimeout(searchDebounceTimer); });
</script>

<style scoped>
@keyframes loading { 0% { transform: translateX(100%); } 100% { transform: translateX(-100%); } }

.filter-input {
  @apply h-9 w-full bg-white border border-slate-200 rounded-md px-3 text-[11px] font-bold text-slate-700 focus:ring-2 focus:ring-rose-500/10 focus:border-rose-500 outline-none transition-all;
}

.pagination-btn-v2 {
  @apply w-8 h-8 flex items-center justify-center rounded border border-slate-200 bg-white text-slate-500 hover:text-blue-600 hover:border-blue-200 disabled:opacity-40 transition-all;
}

.custom-scroll::-webkit-scrollbar { width: 5px; }
.custom-scroll::-webkit-scrollbar-thumb { @apply bg-slate-200 rounded-full; }

.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }

.slide-down-enter-active, .slide-down-leave-active { transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
.slide-down-enter-from, .slide-down-leave-to { opacity: 0; transform: translateY(-10px); }

@keyframes modalIn { from { opacity: 0; transform: scale(0.98) translateY(10px); } to { opacity: 1; transform: scale(1) translateY(0); } }
.animate-modalIn { animation: modalIn 0.2s cubic-bezier(0.16, 1, 0.3, 1); }
</style>