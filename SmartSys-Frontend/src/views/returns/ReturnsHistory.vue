<template>
  <div class="min-h-screen bg-[#fafafa] text-slate-900 font-sans antialiased selection:bg-rose-100" dir="rtl">
    
    <!-- Top Progress Bar -->
    <PageLoadingBar :active="isLoadingReturns || isLoadingDetails" />

    <div class="max-w-[1600px] mx-auto p-6 lg:p-10 space-y-8">
      
      <!-- Page Header -->
      <PageHeader
        :breadcrumb="breadcrumb"
        title="سجل المرتجعات"
        description="راجع مرتجعات المبيعات والمشتريات، وتابع تفاصيل عمليات الإرجاع وحالتها."
        :branches="branches"
        :selectedBranch="selectedBranch"
        branch-selection-mode="optional"
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
          { label: 'إجمالي قيمة المرتجعات', val: formatPrice(kpiSum), icon: 'fa-file-invoice-dollar', color: 'text-rose-600', bg: 'bg-rose-50' },
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
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">الفاتورة الأصلية</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">تاريخ المرتجع</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">عدد الأصناف</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">إجمالي المرتجع</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">النوع</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">إجراءات</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <template v-if="isLoadingReturns">
                <tr v-for="n in 5" :key="n" class="animate-pulse"><td v-for="m in 7" :key="m" class="px-6 py-4"><div class="h-3 bg-slate-100 rounded w-full"></div></td></tr>
              </template>
              <tr v-else-if="!rows.length"><td colspan="7" class="py-20 text-center text-slate-300"><i class="fas fa-box-open text-3xl mb-4 opacity-20"></i><p class="text-xs font-bold uppercase tracking-widest">لا توجد فواتير مرتجعات حتى الآن</p></td></tr>
              <tr v-for="r in rows" :key="r.id" class="hover:bg-blue-50/20 transition-all group">
                <td class="px-6 py-4 text-xs font-bold text-slate-900 font-mono tracking-wider">{{ r.return_number || ('RET-' + String(r.id).padStart(4, '0')) }}</td>
                <td class="px-4 py-4 text-xs font-bold text-slate-400 font-mono">{{ r.invoice_number || '-' }}</td>
                <td class="px-4 py-4 text-[10px] font-mono text-slate-400 whitespace-nowrap">{{ formatReturnDateTime(r.return_date, r.created_at) }}</td>
                <td class="px-4 py-4 text-center">
                  <span class="rounded bg-slate-100 px-2 py-0.5 text-[10px] font-bold text-slate-600">{{ Number(r.items_count || 0) }}</span>
                </td>
                <td class="px-4 py-4 text-xs font-bold text-rose-600">{{ formatPrice(r.grand_total || r.total_amount || r.total || 0) }}</td>
                <td class="px-4 py-4 text-center">
                  <span :class="[type === 'sales' ? 'bg-indigo-50 text-indigo-600 border-indigo-100' : 'bg-amber-50 text-amber-600 border-amber-100']" class="px-2 py-0.5 rounded text-[9px] font-bold border">
                    {{ type === 'sales' ? 'مرتجع بيع' : 'مرتجع شراء' }}
                  </span>
                </td>
                <td class="px-4 py-4 text-center">
                  <button @click="openReturnDetails(r)" :disabled="isLoadingDetails" class="w-8 h-8 rounded-lg border border-slate-200 text-slate-400 hover:text-rose-600 hover:border-rose-200 disabled:opacity-50 disabled:cursor-not-allowed transition-all flex items-center justify-center mx-auto" :aria-label="`فتح تفاصيل المرتجع ${r.return_number || r.id} في تبويب`"><i class="fas fa-eye text-[10px]"></i></button>
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
             <PaginationControls
               :page="filters.page.value"
               :total-pages="totalPages"
               @previous="filters.previousPage()"
               @next="filters.nextPage(totalPages)"
             />
          </div>
        </div>
      </div>
    </div>

    <!-- Details Modal -->
    <HistoryDetailsModal
      :show="showDetailsModal && !!selectedReturn"
      @close="closeReturnDetails"
      :title="`تفاصيل المرتجع ${selectedReturn?.return_number || ('RET-' + String(selectedReturn?.id).padStart(4, '0'))}`"
      icon="fas fa-undo"
    >

      <div class="space-y-6">
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
          <div v-for="info in returnDetailFacts" :key="info.l" class="min-w-0 space-y-1 rounded-lg border border-slate-100 bg-slate-50/60 px-2.5 py-2">
            <p class="text-[9px] font-bold text-slate-400 uppercase tracking-wide">{{ info.l }}</p>
            <p class="break-words text-[11px] font-bold leading-4 text-slate-800">{{ info.v }}</p>
          </div>
        </div>

        <div class="overflow-x-auto rounded-xl border border-slate-100 shadow-sm">
          <table class="w-full text-right text-xs">
            <thead>
              <tr class="bg-slate-50 border-b border-slate-100">
                <th class="px-4 py-3 text-[10px] font-bold text-slate-400 uppercase">الصنف</th>
                <th class="px-4 py-3 text-[10px] font-bold text-slate-400 uppercase text-center">الكمية</th>
                <th class="px-4 py-3 text-[10px] font-bold text-slate-400 uppercase text-center">سعر الوحدة</th>
                <th class="px-4 py-3 text-[10px] font-bold text-slate-400 uppercase">الإجمالي</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
              <tr v-for="item in returnItems" :key="item.id || item.product_id" class="hover:bg-blue-50/20 transition-all">
                <td class="px-4 py-3">
                  <p class="font-bold text-slate-800">{{ item.product_name || item.name || 'منتج غير معروف' }}</p>
                  <p v-if="item.product_code" class="text-[9px] text-slate-400 font-mono">{{ item.product_code }}</p>
                </td>
                <td class="px-4 py-3 text-center font-bold text-slate-500">{{ item.quantity ?? item.qty ?? '-' }}</td>
                <td class="px-4 py-3 text-center text-slate-700 font-mono font-bold">{{ formatPrice(returnItemUnitPrice(item)) }}</td>
                <td class="px-4 py-3 font-bold font-mono text-slate-900">{{ formatPrice(returnItemTotal(item)) }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="flex flex-col md:flex-row gap-6">
          <div v-if="selectedReturn?.notes || selectedReturn?.remarks" class="flex-1 p-4 bg-amber-50 border border-amber-100 rounded-xl">
            <p class="text-[10px] font-bold text-amber-700 uppercase tracking-widest mb-2">سبب الإرجاع</p>
            <p class="text-xs text-amber-800 whitespace-pre-wrap">{{ selectedReturn?.notes || selectedReturn?.remarks }}</p>
          </div>
          <div class="w-full md:w-72 space-y-2.5 bg-slate-50 border border-slate-200 p-5 rounded-xl">
            <div class="flex justify-between text-[11px] font-medium text-slate-500">
              <span>إجمالي المرتجع</span>
              <span class="font-mono font-bold text-slate-700">{{ formatPrice(selectedReturn?.grand_total ?? selectedReturn?.total_amount ?? selectedReturn?.total ?? 0) }}</span>
            </div>
            <div v-if="Number(selectedReturn?.tax_amount) > 0" class="flex justify-between text-[11px] font-medium text-slate-500">
              <span>الضريبة</span>
              <span class="font-mono font-bold text-indigo-600">{{ formatPrice(selectedReturn?.tax_amount) }}</span>
            </div>
            <div v-if="Number(selectedReturn?.discount_value ?? selectedReturn?.discount_amount) > 0" class="flex justify-between text-[11px] font-medium text-slate-500 pb-2.5 border-b border-slate-200">
              <span>الخصم</span>
              <span class="font-mono font-bold text-rose-500">- {{ formatPrice(selectedReturn?.discount_value ?? selectedReturn?.discount_amount) }}</span>
            </div>
            <!-- صافي التسوية pill مميز -->
            <div class="flex justify-between items-center bg-indigo-600 text-white px-4 py-3 rounded-lg">
              <span class="text-[11px] font-bold uppercase tracking-widest opacity-80">صافي التسوية</span>
              <span class="text-xl font-bold font-mono tracking-tighter">{{ formatPrice(returnNetSettlementAmount) }}</span>
            </div>
            <div class="pt-1 space-y-2">
              <div v-if="returnOffsetAmount > 0" class="flex justify-between text-[11px] font-medium text-slate-500">
                <span>المبلغ المُقاصّ</span>
                <span class="font-mono font-bold text-slate-700">{{ formatPrice(returnOffsetAmount) }}</span>
              </div>
              <div v-if="returnCashRefundAmount > 0" class="flex justify-between text-[11px] font-medium text-slate-500">
                <span>{{ Number(selectedReturn?.is_cash) === 1 ? 'المبلغ المسترد نقدًا' : 'المبلغ المسترد' }}</span>
                <span class="font-mono font-bold text-emerald-600">{{ formatPrice(returnCashRefundAmount) }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <template #footer>
        <div class="mr-auto flex flex-row flex-wrap items-center gap-3">
          <button @click="printReturnDetails" class="inline-flex h-10 shrink-0 items-center justify-center gap-2 whitespace-nowrap rounded-md border border-indigo-200 px-4 text-xs font-bold text-indigo-700 hover:bg-indigo-50"><i class="fas fa-print"></i> طباعة المرتجع</button>
          <button @click="closeReturnDetails" class="inline-flex h-10 shrink-0 items-center justify-center whitespace-nowrap px-4 text-xs font-bold text-slate-500 transition-colors hover:text-slate-800">إغلاق</button>
        </div>
      </template>
    </HistoryDetailsModal>

  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import HistoryDetailsModal from '@/components/HistoryDetailsModal.vue';
import { useRoute, useRouter } from 'vue-router';
import { useHistoryFilters } from '@/composables/useHistoryFilters';
import { useToast } from '@/composables/useToast';
import { useBranchIsolation } from '@/composables/useBranchIsolation';
import { useBreadcrumb } from '@/composables/useBreadcrumb';
import { useBranchStore } from '@/stores/branch/branchStore';
import { useCustomerStore } from '@/stores/customer/customerStore';
import { useSupplierStore } from '@/stores/supplier/supplierStore';
import { useReturnStore } from '@/stores/return/returnStore';
import { useBootstrapStore } from '@/stores/bootstrap/bootstrapStore';
import { useCompanyCurrency } from '@/composables/useCompanyCurrency';
import { printDocument } from '@/utils';
import { buildReturnHtml } from '@/utils/printTemplates';

import BaseSkeleton from '@/components/ui/BaseSkeleton.vue';
import PageHeader from '@/components/PageHeader.vue';
import PaginationControls from '@/components/ui/PaginationControls.vue';

const { showToast } = useToast();
const { formatCurrencyLocale, fetchSettings } = useCompanyCurrency();
const { breadcrumb } = useBreadcrumb();
const branchStore = useBranchStore();
const branchIsolation = useBranchIsolation();
const customerStore = useCustomerStore();
const returnStore = useReturnStore();
const supplierStore = useSupplierStore();
const bootstrapStore = useBootstrapStore();

const route = useRoute();
const router = useRouter();
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
const isExempt = branchIsolation.isExempt;
const selectedBranch = computed({
  get: () => branchStore.selectedBranchId,
  set: (val) => branchStore.setSelectedBranch(val)
});
const hasExplicitBranchSelection = computed(() => selectedBranch.value !== null);

const onBranchChange = (newBranchId) => {
  branchStore.setSelectedBranch(newBranchId);
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
const formatDateTime = (date) => date ? new Date(date).toLocaleString('en-US', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '';
const formatReturnDateTime = (returnDate, createdAt) => {
  const dateValue = String(returnDate || createdAt || '');
  if (!dateValue) return '';
  const datePart = dateValue.slice(0, 10);
  const directTime = dateValue.match(/[T ](\d{2}:\d{2}(?::\d{2})?)/)?.[1];
  const createdTime = String(createdAt || '').match(/[T ](\d{2}:\d{2}(?::\d{2})?)/)?.[1];
  const time = directTime || createdTime;
  if (!time) return formatDate(datePart);
  const normalizedTime = time.length === 5 ? `${time}:00` : time;
  const date = new Date(`${datePart}T${normalizedTime}`);
  return Number.isNaN(date.getTime())
    ? formatDate(datePart)
    : date.toLocaleString('ar-EG', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
};
const isPurchaseReturn = computed(() => {
  const returnType = String(selectedReturn.value?.return_type || selectedReturn.value?.type || '').toLowerCase();
  return type.value === 'purchases'
    || returnType === 'purchase'
    || returnType === 'purchase_return'
    || selectedReturn.value?.return_type_label?.includes('مشتريات');
});
const returnPartyName = computed(() => {
  const item = selectedReturn.value;
  if (!item) return '-';
  const partyName = [
    item.party_name,
    isPurchaseReturn.value ? item.supplier_name : item.customer_name,
    isPurchaseReturn.value ? item.supplier?.name : item.customer?.name
  ].find((name) => {
    const normalizedName = String(name ?? '').trim();
    return normalizedName && !['-', '—', 'null', 'undefined'].includes(normalizedName.toLowerCase());
  });
  return partyName || (isPurchaseReturn.value ? '-' : 'عميل نقدي');
});
const returnItems = computed(() => {
  const items = selectedReturn.value?.items || selectedReturn.value?.return_items || [];
  return Array.isArray(items) ? items : [];
});
const returnCashRefundAmount = computed(() => Math.max(
  0,
  Number(selectedReturn.value?.cash_refund_amount ?? selectedReturn.value?.paid_amount ?? 0)
));
const returnOffsetAmount = computed(() => Math.max(0, Number(selectedReturn.value?.offset_amount ?? 0)));
const returnNetSettlementAmount = computed(() => Number(
  selectedReturn.value?.net_settlement_amount ?? (returnCashRefundAmount.value + returnOffsetAmount.value)
));
const returnRefundChannel = computed(() => {
  if (Number(selectedReturn.value?.is_cash) === 1) return 'نقدًا';
  const method = String(selectedReturn.value?.refund_method || '').toLowerCase();
  const labels = {
    cash: 'نقدًا',
    bank: 'تحويل بنكي',
    bank_transfer: 'تحويل بنكي',
    card: 'بطاقة ائتمان',
    wallet: 'محفظة إلكترونية',
    credit: 'رصيد آجل',
    other: 'طريقة دفع أخرى'
  };
  const label = labels[method];
  return label ? `عبر ${label}` : 'عبر طريقة الدفع';
});
const returnSettlementLabel = computed(() => {
  const hasCashRefund = returnCashRefundAmount.value > 0.005;
  const hasOffset = returnOffsetAmount.value > 0.005;
  const total = Number(selectedReturn.value?.grand_total ?? selectedReturn.value?.total_amount ?? selectedReturn.value?.total ?? 0);
  const fullySettled = total > 0 && Math.abs(returnCashRefundAmount.value + returnOffsetAmount.value - total) <= 0.01;
  const channel = returnRefundChannel.value;

  if (hasOffset && hasCashRefund) {
    return isPurchaseReturn.value
      ? `مقاصة مع حساب المورد ثم استرداد المتبقي ${channel}`
      : `مقاصة مع رصيد العميل ثم رد المتبقي ${channel}`;
  }
  if (hasCashRefund) {
    const extent = fullySettled ? 'كامل' : 'جزئي';
    if (returnRefundChannel.value === 'نقدًا') {
      return isPurchaseReturn.value ? `استرداد نقدي ${extent}` : `رد نقدي ${extent}`;
    }
    return isPurchaseReturn.value ? `استرداد ${extent} ${channel}` : `رد ${extent} ${channel}`;
  }
  if (hasOffset) {
    if (isPurchaseReturn.value) return fullySettled ? 'تسوية كاملة على حساب المورد' : 'تسوية جزئية على حساب المورد';
    return fullySettled ? 'إضافة كامل المبلغ إلى رصيد العميل' : 'إضافة جزئية إلى رصيد العميل';
  }
  return 'لا توجد تسوية مالية مسجلة';
});
const returnDetailFacts = computed(() => [
  { l: isPurchaseReturn.value ? 'المورد' : 'العميل', v: returnPartyName.value },
  { l: 'الفاتورة الأصلية', v: selectedReturn.value?.original_invoice_number || selectedReturn.value?.invoice_number || 'غير محدد' },
  { l: 'تاريخ المرتجع', v: formatReturnDateTime(selectedReturn.value?.return_date, selectedReturn.value?.created_at) },
  { l: 'الفرع', v: selectedReturn.value?.branch_name || branches.value.find(branch => String(branch.id) === String(selectedReturn.value?.branch_id))?.name || '-' },
  { l: 'الكاشير', v: selectedReturn.value?.created_by_name || 'النظام' },
  { l: 'نوع العملية', v: selectedReturn.value?.return_type_label || (isPurchaseReturn.value ? 'مرتجع مشتريات' : 'مرتجع مبيعات') },
  { l: 'طريقة التسوية', v: returnSettlementLabel.value }
]);
const returnItemUnitPrice = (item) => Number(item.unit_price ?? item.sale_price ?? item.price ?? 0);
const returnItemTotal = (item) => {
  const explicitTotal = item.subtotal ?? item.total ?? item.net_total ?? item.amount;
  if (explicitTotal !== null && explicitTotal !== undefined && explicitTotal !== '') {
    return Number(explicitTotal) || 0;
  }
  const quantity = Number(item.quantity ?? item.qty ?? 0);
  return quantity * returnItemUnitPrice(item);
};

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
  } finally { if (currentCtrl === returnsAbortCtrl) isLoadingReturns.value = false; }
};

const resetFilters = () => { filters.resetFilters(); type.value = 'sales'; fetchReturns(); };

const viewReturnDetails = async (returnRow) => {
  if (!returnRow || !returnRow.id) return;
  if (detailsAbortCtrl.value) detailsAbortCtrl.value.abort();
  detailsAbortCtrl.value = new AbortController();
  const currentCtrl = detailsAbortCtrl.value;
  isLoadingDetails.value = true;
  try {
    const response = await returnStore.fetchReturnDetails(returnRow.id, type.value, { signal: currentCtrl.signal });
    if (currentCtrl !== detailsAbortCtrl.value) return;
    if (response?.status === 'success') {
      selectedReturn.value = { ...returnRow, ...(response.data || response) };
      showDetailsModal.value = true;
    }
    else showToast('فشل في تحميل تفاصيل المرتجع', 'error');
  } catch (e) {
    if (e?.name !== 'AbortError' && e?.name !== 'CanceledError' && e?.code !== 'ERR_CANCELED') {
      showToast('فشل في تحميل تفاصيل المرتجع', 'error');
    }
  } finally { if (currentCtrl === detailsAbortCtrl.value) isLoadingDetails.value = false; }
};

const openReturnDetails = async (returnRow) => {
  if (!returnRow?.id) return;
  if (Number(route.query.id) === Number(returnRow.id) && route.query.type === type.value) {
    await viewReturnDetails(returnRow);
    return;
  }

  await router.push({
    name: 'ReturnsHistory',
    query: { ...route.query, id: String(returnRow.id), type: type.value }
  });
};

const closeReturnDetails = async () => {
  showDetailsModal.value = false;
  if (!route.query.id) return;

  const query = { ...route.query };
  delete query.id;
  await router.replace({ name: 'ReturnsHistory', query });
};

const printReturnDetails = async () => {
  if (!selectedReturn.value) return;
  await printDocument(buildReturnHtml(selectedReturn.value));
};

let searchDebounceTimer = null;
let isMounting = true;

watch(filters.searchQuery, () => { 
  if (isMounting) return;
  clearTimeout(searchDebounceTimer); filters.page.value = 1; searchDebounceTimer = setTimeout(fetchReturns, 400); 
});

watch([filters.customerFilter, type, filters.dateFrom, filters.dateTo], () => { 
  if (isMounting) return;
  filters.page.value = 1; fetchReturns(); 
});
watch(filters.page, () => { if (isMounting) return; fetchReturns(); });
watch([() => route.query.id, () => route.query.type], async ([id, routeType]) => {
  if (routeType === 'sales' || routeType === 'purchases') type.value = routeType;
  if (Number(id) > 0) {
    await viewReturnDetails({ id: Number(id) });
    return;
  }
  showDetailsModal.value = false;
  selectedReturn.value = null;
});
watch([filters.customerFilter, type, customers, suppliers], () => {
  const list = type.value === 'sales' ? (customers.value || []) : (suppliers.value || []);
  const found = list.find(x => String(x.id) === String(filters.customerFilter.value));
  filters.customerSearch.value = found ? (found.name || found.customer_name || found.supplier_name || '') : '';
});

onMounted(async () => {
  if (route.query.type === 'sales' || route.query.type === 'purchases') type.value = route.query.type;
  try {
    const data = await bootstrapStore.fetchPaymentsData();
    if (data.customers) customerStore.customers = data.customers;
    if (data.suppliers) supplierStore.suppliers = data.suppliers;
    if (data.branches && isExempt.value) branchStore.setBranches(data.branches);
    await fetchSettings();
  } catch {
    await fetchSettings();
    if (isExempt.value) await branchStore.fetchBranches().catch(() => {});
    await customerStore.fetchCustomers().catch(() => {});
    await supplierStore.fetchSuppliers().catch(() => {});
  }
  
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
  isMounting = false;
  await new Promise(resolve => setTimeout(resolve, 100));
  fetchReturns();
  const qid = Number(route.query.id || 0);
  if (qid > 0) await viewReturnDetails({ id: qid });
});

onUnmounted(() => { if (returnsAbortCtrl) returnsAbortCtrl.abort(); if (searchDebounceTimer) clearTimeout(searchDebounceTimer); });
</script>

<style scoped>
/* Animations */
@keyframes loading {
  0% { transform: translateX(100%); }
  100% { transform: translateX(-100%); }
}

@keyframes modalIn {
  from { opacity: 0; transform: scale(0.98) translateY(10px); }
  to { opacity: 1; transform: scale(1) translateY(0); }
}

/* Form Inputs */
.filter-input {
  @apply h-9 w-full bg-white border border-slate-200 rounded-md px-3 text-[11px] font-bold text-slate-700 focus:ring-2 focus:ring-rose-500/10 focus:border-rose-500 outline-none transition-all;
}

/* Pagination */
/* Scrollbar */
.custom-scroll::-webkit-scrollbar {
  width: 5px;
}

.custom-scroll::-webkit-scrollbar-thumb {
  @apply bg-slate-200 rounded-full;
}

/* Transition Classes */
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}

.slide-down-enter-active,
.slide-down-leave-active {
  transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
}

.slide-down-enter-from,
.slide-down-leave-to {
  opacity: 0;
  transform: translateY(-10px);
}

/* Animation Classes */
.animate-modalIn {
  animation: modalIn 0.2s cubic-bezier(0.16, 1, 0.3, 1);
}
</style>