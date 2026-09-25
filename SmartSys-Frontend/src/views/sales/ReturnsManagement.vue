<template>
  <div class="min-h-screen bg-[#fafafa] text-slate-900 font-sans antialiased selection:bg-rose-100" dir="rtl">
    
    <!-- Global Loading Progress -->
    <div v-if="isLoading || isLoadingDetails" class="fixed top-0 left-0 right-0 h-0.5 bg-rose-600/10 z-[110]">
      <div class="h-full bg-rose-600 animate-[loading_2s_ease-in-out_infinite] w-1/3"></div>
    </div>

    <div class="max-w-[1600px] mx-auto p-6 lg:p-10 space-y-8">
      
      <!-- Page Header Area -->
      <PageHeader
        :breadcrumb="breadcrumb"
        title="إدارة المرتجعات"
        description="تسجيل، مراجعة، وتدقيق عمليات الإرجاع وتحديث الذمم المالية."
        :branches="branches"
        :selectedBranch="branchStore.selectedBranchId"
        @branch-changed="onBranchChange"
      >
        <template #controls>
          <button @click="showCreateForm = !showCreateForm" 
            :class="[showCreateForm ? 'bg-slate-900' : 'bg-rose-600 shadow-rose-900/20 hover:bg-rose-700']" 
            class="h-9 px-6 rounded-md text-white text-xs font-bold shadow-lg transition-all active:scale-95 flex items-center gap-2">
            <i :class="showCreateForm ? 'fas fa-times text-[10px]' : 'fas fa-plus text-[10px]'"></i>
            {{ showCreateForm ? 'إلغاء العملية' : 'تسجيل مرتجع جديد' }}
          </button>
        </template>
      </PageHeader>

      <!-- Segmented Type Selector -->
      <div class="flex items-center justify-center">
        <div class="flex bg-slate-100 p-1 rounded-lg border border-slate-200/50">
          <button v-for="type in returnTypes" :key="type.id" @click="activeType = type.id" 
                  :class="[activeType === type.id ? 'bg-white text-slate-900 shadow-sm ring-1 ring-slate-200' : 'text-slate-500 hover:text-slate-700']"
                  class="px-8 py-2 rounded-md text-xs font-bold transition-all flex items-center gap-2">
            <i :class="[type.icon, 'text-[10px]']"></i>
            {{ type.name }}
          </button>
        </div>
      </div>

      <!-- KPI Summary Overview -->
      <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
        <div v-for="kpi in [
          { label: 'إجمالي العمليات', val: stats.count, icon: 'fa-list-ol', color: 'text-blue-600', bg: 'bg-blue-50' },
          { label: 'إجمالي القيمة', val: formatPrice(stats.total), icon: 'fa-money-bill-wave', color: 'text-rose-600', bg: 'bg-rose-50' },
          { label: 'نشاط اليوم (عدد)', val: stats.today, icon: 'fa-calendar-check', color: 'text-amber-600', bg: 'bg-amber-50' },
          { label: 'قيمة مرتجع اليوم', val: formatPrice(stats.todayTotal), icon: 'fa-chart-line', color: 'text-emerald-600', bg: 'bg-emerald-50' }
        ]" :key="kpi.label" class="bg-white border border-slate-200 p-5 rounded-xl flex items-center justify-between group hover:border-slate-300 transition-all shadow-sm">
          <div>
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">{{ kpi.label }}</p>
            <p class="text-xl font-bold text-slate-900">{{ kpi.val }}</p>
          </div>
          <div :class="[kpi.bg, kpi.color]" class="w-10 h-10 rounded-lg flex items-center justify-center text-sm opacity-80 group-hover:opacity-100 transition-opacity">
            <i :class="['fas', kpi.icon]"></i>
          </div>
        </div>
      </section>

      <!-- Create Form Section (Collapsible) -->
      <transition name="slide-down">
        <div v-if="showCreateForm" class="bg-white rounded-xl shadow-xl border border-slate-200 overflow-hidden">
          <div class="px-6 py-4 bg-slate-50/50 border-b border-slate-100 flex items-center justify-between">
            <h3 class="text-sm font-bold text-slate-900 flex items-center gap-2">
              <i class="fas fa-edit text-rose-500"></i>
              تسجيل مستند مرتجع لعملية {{ activeType === 'sales' ? 'مبيعات' : 'مشتريات' }}
            </h3>
            <span class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">إدخال البيانات المالية</span>
          </div>
          <div class="p-8">
            <ReturnForm :type="activeType" @returnSuccess="handleReturnSuccess" />
          </div>
        </div>
      </transition>

      <!-- Main Content Area: History & Filters -->
      <div class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm relative">
        
        <!-- Filters Toolbar -->
        <div class="p-6 bg-slate-50/30 border-b border-slate-100 space-y-6">
          <div class="grid grid-cols-1 lg:grid-cols-12 gap-4 items-end">
            
            <div class="lg:col-span-4 space-y-1.5 relative group">
              <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider px-1">بحث سريع</label>
              <div class="relative">
                <input ref="searchInputRef" v-model="search" type="text" class="filter-input-v2" style="padding-right: 2rem;" placeholder="رقم المرتجع أو الفاتورة..." @focus="showSearchDropdown = true" @blur="handleSearchBlur" />
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
                        <!-- رأس عداد النتائج -->
                        <div v-if="searchResults.length" class="px-4 py-2 bg-slate-50 border-b border-slate-100">
                          <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">{{ searchResults.length }} نتيجة</p>
                        </div>
                        <!-- حالة لا توجد نتائج -->
                        <div v-if="!searchResults.length" class="px-4 py-8 text-center text-slate-400">
                          <i class="fas fa-search text-xl mb-2 opacity-30"></i>
                          <p class="text-[11px] font-bold">لا توجد نتائج مطابقة</p>
                        </div>
                        <div v-for="result in searchResults.slice(0, 20)" :key="result.id" @click="selectSearchResult(result)" class="px-4 py-3 hover:bg-slate-50 cursor-pointer border-b border-slate-50 flex items-center justify-between group">
                          <div>
                            <p class="text-xs font-bold text-slate-900">{{ result.return_number || 'RET-' + result.id }}</p>
                            <p class="text-[10px] text-slate-400">{{ formatDate(result.return_date || result.created_at) }}</p>
                          </div>
                          <p class="text-xs font-bold text-rose-600">{{ formatPrice(result.grand_total || result.total_amount || 0) }}</p>
                        </div>
                        <!-- رسالة "المزيد" إذا تجاوزت 20 -->
                        <div v-if="searchResults.length > 20" class="px-4 py-2 text-center bg-slate-50 border-t border-slate-100">
                          <p class="text-[10px] text-slate-500">و {{ searchResults.length - 20 }} نتيجة أخرى — حدّد البحث للتضييق</p>
                        </div>
                      </template>
                    </div>
                  </transition>
                </Teleport>
              </div>
            </div>

            <!-- من تاريخ -->
            <div class="lg:col-span-2 space-y-1.5">
              <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider px-1">من تاريخ</label>
              <div class="relative">
                <input ref="dateFromRef" type="date" v-model="dateFrom" class="filter-input-v2" style="padding-left: 2rem;" />
                <i @click="dateFromRef?.showPicker?.()" class="fas fa-calendar absolute left-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px] cursor-pointer hover:text-slate-500 transition-colors"></i>
              </div>
            </div>

            <!-- إلى تاريخ -->
            <div class="lg:col-span-2 space-y-1.5">
              <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider px-1">إلى تاريخ</label>
              <div class="relative">
                <input ref="dateToRef" type="date" v-model="dateTo" class="filter-input-v2" style="padding-left: 2rem;" />
                <i @click="dateToRef?.showPicker?.()" class="fas fa-calendar absolute left-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px] cursor-pointer hover:text-slate-500 transition-colors"></i>
              </div>
            </div>



            <div class="lg:col-span-2">
              <button @click="loadReturns" class="h-9 w-full rounded-md border border-slate-200 bg-white text-slate-400 hover:text-blue-600 transition-colors shadow-sm">
                <i class="fas fa-sync-alt text-xs"></i>
              </button>
            </div>
          </div>
        </div>

        <!-- Returns Table -->
        <div class="overflow-x-auto">
          <table class="w-full text-right border-collapse">
            <thead>
              <tr class="bg-slate-50/50 border-b border-slate-200">
                <th class="px-6 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">رقم المرتجع</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">العملية الأصلية</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">تاريخ المرتجع</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">القيمة الإجمالية</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">الحالة</th>
                <th class="px-6 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">إجراءات</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <template v-if="isLoading">
                <tr v-for="n in 5" :key="n" class="animate-pulse">
                  <td v-for="m in 6" :key="m" class="px-6 py-4"><div class="h-3 bg-slate-100 rounded w-full"></div></td>
                </tr>
              </template>
              <tr v-else-if="!returns.length">
                <td colspan="6" class="py-20 text-center text-slate-300">
                   <i class="fas fa-box-open text-3xl mb-4 opacity-20"></i>
                   <p class="text-xs font-bold uppercase tracking-widest">لا توجد فواتير مرتجعات</p>
                </td>
              </tr>
              <tr v-for="r in returns" :key="r.id" class="hover:bg-rose-50/10 transition-all group">
                <td class="px-6 py-4 text-xs font-bold text-slate-900 font-mono tracking-wider">{{ r.return_number || 'RET-' + r.id.toString().padStart(4, '0') }}</td>
                <td class="px-4 py-4 text-[10px] font-mono font-bold text-slate-400">#{{ r.invoice_number || r.id }}</td>
                <td class="px-4 py-4 text-[10px] font-mono text-slate-400">{{ formatDate(r.return_date || r.created_at) }}</td>
                <td class="px-4 py-4 text-xs font-bold text-rose-600">{{ formatPrice(r.grand_total || r.total_amount || 0) }}</td>
                <td class="px-4 py-4 text-center">
                  <span class="px-2 py-0.5 rounded text-[9px] font-bold border bg-emerald-50 text-emerald-700 border-emerald-100">مكتمل ومرحّل</span>
                </td>
                <td class="px-6 py-4 text-center">
                  <button @click="viewReturnDetails(r)" class="w-8 h-8 rounded-lg border border-slate-200 text-slate-400 hover:text-rose-600 hover:border-rose-200 transition-all flex items-center justify-center mx-auto shadow-sm">
                    <i class="fas fa-eye text-[10px]"></i>
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Pagination Footer -->
        <div class="px-6 py-4 bg-slate-50/50 border-t border-slate-200 flex items-center justify-between">
          <div class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">
            صفحة <span class="text-slate-900">{{ currentPage }}</span> من <span class="text-slate-900">{{ totalPages }}</span>
            <span class="mx-2 text-slate-200">|</span>
            إجمالي <span class="text-slate-900">{{ totalCount }}</span> سجل
          </div>
          <div class="flex items-center gap-3">
             <div class="flex items-center gap-2">
               <span class="text-[10px] font-bold text-slate-400 uppercase tracking-tighter">النتائج:</span>
               <select v-model.number="pageSize" @change="currentPage = 1; loadReturns();" class="h-8 border border-slate-200 rounded px-2 text-[10px] font-bold outline-none">
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

    <!-- Details Modal -->
    <BaseModal :show="showDetailsModal && !!selectedReturn" @close="showDetailsModal = false" maxWidth="4xl">
      <template #header>
        <div class="flex items-center gap-3">
          <div class="w-8 h-8 bg-rose-600 rounded-lg flex items-center justify-center text-white text-xs"><i class="fas fa-undo"></i></div>
          <div>
            <h3 class="text-sm font-bold text-slate-900 uppercase">تفاصيل المرتجع {{ selectedReturn?.return_number || 'RET-' + selectedReturn?.id }}</h3>
            <!-- #7: تاريخ الإنشاء في رأس الـ Modal -->
            <p class="text-[10px] text-slate-400 font-medium mt-0.5">{{ formatDateTime(selectedReturn?.created_at) }}</p>
          </div>
        </div>
      </template>

      <div class="space-y-8">
        <!-- #7: أضيف نوع العملية كـ حقل خامس -->
        <div class="grid grid-cols-2 md:grid-cols-5 gap-6">
          <div v-for="info in [
            { l: 'نوع العملية',    v: selectedReturn?.type === 'purchase' ? 'مرتجع مشتريات' : 'مرتجع مبيعات' },
            { l: 'الفاتورة الأصلية', v: selectedReturn?.invoice_number || 'غير محدد' },
            { l: 'تاريخ المرتجع', v: formatDate(selectedReturn?.return_date || selectedReturn?.created_at) },
            { l: 'الطرف المعني',  v: selectedReturn?.party_name || 'عميل نقدي' },
            { l: 'طريقة التسوية', v: selectedReturn?.is_cash ? 'نقدي' : 'خصم ذمم' }
          ]" :key="info.l" class="space-y-1">
            <p class="text-[9px] font-bold text-slate-400 uppercase tracking-widest">{{ info.l }}</p>
            <p class="text-xs font-bold text-slate-800">{{ info.v }}</p>
          </div>
        </div>

        <div class="border border-slate-100 rounded-xl overflow-hidden">
          <table class="w-full text-right text-xs">
            <thead><tr class="bg-slate-50 border-b border-slate-100 font-bold text-slate-400 uppercase"><th class="px-4 py-3">الصنف</th><th class="px-4 py-3 text-center">الكمية</th><th class="px-4 py-3 text-center">سعر الوحدة</th><th class="px-4 py-3 text-left">الإجمالي</th></tr></thead>
            <tbody class="divide-y divide-slate-50 font-medium">
              <tr v-for="item in selectedReturn?.items" :key="item.id">
                <td class="px-4 py-3">
                  <p class="font-bold text-slate-800">{{ item.product_name }}</p>
                  <p v-if="item.product_code" class="text-[9px] text-slate-400 font-mono">{{ item.product_code }}</p>
                </td>
                <td class="px-4 py-3 text-center font-bold text-slate-500">{{ item.quantity }}</td>
                <td class="px-4 py-3 text-center text-slate-700 font-mono">{{ formatPrice(item.unit_price) }}</td>
                <td class="px-4 py-3 text-left font-bold font-mono">{{ formatPrice(item.quantity * item.unit_price) }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- #5: التفصيل المالي الكامل (ضريبة، خصم، مدفوع) -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
          <div class="p-4 bg-slate-50 rounded-xl border border-slate-100 space-y-2">
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-3">التفصيل المالي</p>
            <div class="flex justify-between text-xs font-bold text-slate-500">
              <span>إجمالي المرتجع</span>
              <span class="font-mono text-slate-900">{{ formatPrice(selectedReturn?.grand_total || selectedReturn?.total_amount || 0) }}</span>
            </div>
            <div v-if="Number(selectedReturn?.tax_amount) > 0" class="flex justify-between text-xs font-bold text-slate-500">
              <span>الضريبة</span>
              <span class="font-mono text-indigo-600">{{ formatPrice(selectedReturn?.tax_amount || 0) }}</span>
            </div>
            <div v-if="Number(selectedReturn?.discount_value) > 0 || Number(selectedReturn?.discount_amount) > 0" class="flex justify-between text-xs font-bold text-slate-500">
              <span>الخصم</span>
              <span class="font-mono text-rose-500">- {{ formatPrice(selectedReturn?.discount_value || selectedReturn?.discount_amount || 0) }}</span>
            </div>
            <div class="flex justify-between text-xs font-bold border-t border-slate-200 pt-2">
              <span class="text-slate-700">المبلغ المدفوع</span>
              <span class="font-mono text-emerald-600">{{ formatPrice(selectedReturn?.paid_amount || 0) }}</span>
            </div>
          </div>
          <div v-if="selectedReturn?.notes" class="p-4 bg-amber-50 border border-amber-100 rounded-xl text-xs text-amber-800 italic flex items-start gap-2">
            <i class="fas fa-sticky-note text-amber-400 mt-0.5"></i>
            <span>{{ selectedReturn?.notes }}</span>
          </div>
        </div>
      </div>

      <template #footer>
        <div class="flex items-center gap-6 mr-auto">
          <div class="space-y-1">
            <p class="text-[9px] font-bold text-slate-400 uppercase">بواسطة</p>
            <p class="text-[11px] font-bold text-slate-800">{{ selectedReturn?.created_by_name || 'النظام' }}</p>
            <!-- #8: آخر تحديث -->
            <p v-if="selectedReturn?.updated_at && selectedReturn?.updated_at !== selectedReturn?.created_at" class="text-[9px] text-slate-400">آخر تحديث: {{ formatDateTime(selectedReturn?.updated_at) }}</p>
          </div>
          <div class="h-6 w-px bg-slate-200"></div>
          <div class="space-y-1">
            <p class="text-[9px] font-bold text-rose-500 uppercase">قيمة المرتجع النهائية</p>
            <p class="text-xl font-bold text-slate-900 font-mono tracking-tighter">{{ formatPrice(selectedReturn?.grand_total || selectedReturn?.total_amount || 0) }}</p>
          </div>
        </div>
        <button @click="showDetailsModal = false" class="px-4 h-9 text-xs font-bold text-slate-500 hover:text-slate-800 transition-colors">إغلاق</button>
        <button @click="printReturnDetails" class="px-6 h-9 bg-blue-600 text-white rounded-md text-xs font-bold shadow-lg shadow-blue-900/20"><i class="fas fa-print ml-1.5"></i> طباعة المستند</button>
      </template>
    </BaseModal>

  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import BaseModal from '@/components/BaseModal.vue';
import { useRoute, useRouter } from 'vue-router';
import ReturnForm from '@/components/ReturnForm.vue';
import { useToast } from '@/composables/useToast';
import { useLoader } from '@/composables/useLoader';
import { useSessionExemption } from '@/composables/useCashierSessionGuard';
import { useBreadcrumb } from '@/composables/useBreadcrumb';
import { useAuthStore } from '@/stores/auth';
import { useBranchStore } from '@/stores/branch';
import { useCustomerStore } from '@/stores/customer/customerStore';
import { useSupplierStore } from '@/stores/supplier/supplierStore';
import { useReturnStore } from '@/stores/return/returnStore';
import { useCompanyCurrency } from '@/composables/useCompanyCurrency';

import BaseSkeleton from '@/components/ui/BaseSkeleton.vue';
import PageHeader from '@/components/PageHeader.vue';
import { printDocument } from '@/utils/PrintService';
import { buildReturnHtml } from '@/utils/printTemplates';
import { useTableFilters } from '@/composables/useTableFilters';
import { useSearchDropdown } from '@/composables/useSearchDropdown';

// --- Services & Router ---
const router = useRouter();
const route = useRoute();
const { showLoader, hideLoader } = useLoader();
const { showToast } = useToast();
const { breadcrumb } = useBreadcrumb();
const authStore = useAuthStore();
const branchStore = useBranchStore();
const customerStore = useCustomerStore();
const supplierStore = useSupplierStore();
const returnStore = useReturnStore();
const { isExempt, ensureLoaded: ensureExemptionLoaded } = useSessionExemption();
const { formatCurrencyLocale, fetchSettings } = useCompanyCurrency();

// Branches computed from store
const branches = computed(() => branchStore.branches);

const onBranchChange = (newBranchId) => {
  branchStore.setSelectedBranch(newBranchId);
  currentPage.value = 1;
  loadReturns();
};

// --- Initialize Composables
const {
  dateFrom,
  dateTo,
  perPage: pageSize,
  page: currentPage,
  branchId,
  isExempt: exemptFromBranch,
  dateFromRef,
  dateToRef,
  handleBranchChange,
  nextPage,
  previousPage,
  goToPage,
  loadFromLocalStorage: loadFiltersFromLocalStorage,
  saveToLocalStorage: saveFiltersToLocalStorage,
  totalCount,
  totalPages,
} = useTableFilters('returns_mgmt_filters', {
  initialPageSize: 20,
  onFilterChange: () => loadReturns(),
});

const {
  search,
  searchInputRef,
  showSearchDropdown,
  searchResults,
  isLoadingSearch,
  searchDropdownPosition,
  handleSearchBlur,
  selectSearchResult,
} = useSearchDropdown({
  onSearch: (query) => performSearch(query),
  onSelectResult: (result) => handleSearchResultSelect(result),
  onClear: () => { currentPage.value = 1; loadReturns(); },
});

// --- Reactive State: General ---
const activeType = ref('sales');
const returns = ref([]);
const totalAmountFromAPI = ref(0);
const showCreateForm = ref(false);
const showDetailsModal = ref(false);
const selectedReturn = ref(null);
const isLoading = ref(false);
const isLoadingDetails = ref(false);

// --- Reactive State ---
const returnTypes = [
  { id: 'sales', name: 'مرتجعات المبيعات', icon: 'fas fa-shopping-cart' },
  { id: 'purchases', name: 'مرتجعات المشتريات', icon: 'fas fa-truck' }
];

// --- Computed Logic ---
const stats = computed(() => {
  const isToday = (someDate) => {
    const today = new Date();
    const d = new Date(someDate);
    return d.getDate() === today.getDate() &&
           d.getMonth() === today.getMonth() &&
           d.getFullYear() === today.getFullYear();
  };
  const todayReturns = returns.value.filter(r => isToday(r.created_at));
  const todayTotal = todayReturns.reduce((sum, r) => sum + parseFloat(r.grand_total || r.total_amount || r.total || r.amount || 0), 0);
  const totalVal = totalAmountFromAPI.value || returns.value.reduce((sum, r) => sum + parseFloat(r.grand_total || r.total_amount || r.total || r.amount || 0), 0);
  return {
    count: totalCount.value || returns.value.length,
    total: totalVal,
    today: todayReturns.length,
    todayTotal: todayTotal
  };
});

// --- Formatting Helpers ---
const formatPrice = (amount) => formatCurrencyLocale(amount, 2);
const formatDate = (date) => date ? new Date(date).toLocaleDateString('en-US', { day: '2-digit', month: '2-digit', year: 'numeric' }) : '';
const formatDateTime = (date) => date ? new Date(date).toLocaleString('en-US', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '';

// --- API: Load Returns List ---
const loadReturns = async () => {
  isLoading.value = true;
  showLoader();
  try {
    const response = await returnStore.fetchReturnsList({
      type: activeType.value,
      branchId: branchId.value || undefined,
      page: currentPage.value,
      perPage: pageSize.value,
      search: search.value || undefined,
      dateFrom: dateFrom.value || undefined,
      dateTo: dateTo.value || undefined
    });
    if (response?.status === 'success') {
      const resData = response.data;
      const list = Array.isArray(resData) ? resData : (resData?.items || []);
      returns.value = list;
      totalCount.value = resData?.total || list.length;
      totalAmountFromAPI.value = list.reduce((sum, r) => sum + (Number(r.total_amount) || 0), 0);
    } else {
      throw new Error(response?.message || 'Failed to load returns');
    }
  } catch (error) {
    console.error('Error loading returns:', error);
    showToast(error.message || 'فشل في تحميل المرتجعات', 'error');
    returns.value = [];
    totalCount.value = 0;
  } finally {
    isLoading.value = false;
    hideLoader();
  }
};

// --- Handle Return Success ---
const handleReturnSuccess = () => {
  showCreateForm.value = false;
  loadReturns();
  
  if (typeof productStore !== 'undefined' && productStore.invalidateCache) {
    productStore.invalidateCache();
  }

  window.dispatchEvent(new CustomEvent('pos:return-recorded', {
    detail: {
      returnId: Date.now(),
      saleId: route.query.saleId,
    }
  }));

  // Only navigate away if explicitly coming from another page
  if (!route.query.returnFrom) return;

  const destinations = {
    cashier: { name: 'SalesPoint' },
    'cashier-dashboard': { name: 'CashierDashboard' },
    'admin-sales': { name: 'SalesHistory' },
    'sale-details': { name: 'SalesHistory', query: { id: route.query.saleId } },
  };

  const dest = destinations[route.query.returnFrom] ?? { name: 'CashierDashboard' };
  router.push(dest).catch(() => {});
};

// --- Search with Dropdown Results ---
const performSearch = async (query) => {
  if (!query.trim()) {
    searchResults.value = [];
    return;
  }
  
  isLoadingSearch.value = true;
  try {
    const response = await returnStore.fetchReturnsList({
      type: activeType.value,
      branchId: branchId.value || undefined,
      search: query.trim(),
      perPage: 50 // Get more results for dropdown
    });
    if (response?.status === 'success') {
      const resData = response.data;
      searchResults.value = Array.isArray(resData) ? resData : (resData?.items || []);
    } else {
      searchResults.value = [];
    }
  } catch (error) {
    console.error('Search error:', error);
    searchResults.value = [];
  } finally {
    isLoadingSearch.value = false;
  }
};

const handleSearchResultSelect = (result) => {
  returns.value = [result]; // Show only the selected result
  totalCount.value = 1;
};

// --- Watchers & Lifecycle ---

watch(activeType, () => { currentPage.value = 1; search.value = ''; searchResults.value = []; loadReturns(); showCreateForm.value = false; });

onMounted(async () => {
  await Promise.all([ensureExemptionLoaded(), branchStore.initialize?.()]);
  await fetchSettings();
  loadFiltersFromLocalStorage(); // Load filters from localStorage

  if (!isExempt.value) {
    // LEAVE: Reason: - This is initialization logic to set the default branch for the store
    // - API calls use isExempt check which is now guaranteed to be loaded (ensureExemptionLoaded was called)
    // - Does not directly affect data isolation (API calls will use getRequiredBranchId if needed)
    const userBranchId = authStore?.user?.branch_id;
    if (userBranchId) {
      branchStore.setSelectedBranch(userBranchId);
    }
  }

  loadReturns();
});

// --- View Return Details ---
const viewReturnDetails = async (r) => {
  selectedReturn.value = r;
  showDetailsModal.value = true;
  isLoadingDetails.value = true;
  try {
    const type = activeType.value === 'sales' ? 'sales' : 'purchase';
    const res = await returnStore.fetchReturnDetails(r.id, type);
    if (res?.status === 'success' && res.data) {
      selectedReturn.value = { ...r, ...res.data };
    }
  } catch (_) { /* keep list data */ }
  finally { isLoadingDetails.value = false; }
};

// --- Print Return Details ---
const printReturnDetails = async () => {
  if (!selectedReturn.value) return;
  await printDocument(buildReturnHtml(selectedReturn.value));
};
</script>

<style scoped>
@keyframes loading { 0% { transform: translateX(100%); } 100% { transform: translateX(-100%); } }

.filter-input-v2 {
  @apply h-9 w-full bg-white border border-slate-200 rounded-md px-3 text-[11px] font-bold text-slate-700 focus:ring-2 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all;
}

.pagination-btn-v2 {
  @apply w-8 h-8 flex items-center justify-center rounded border border-slate-200 bg-white text-slate-500 hover:text-blue-600 hover:border-blue-200 disabled:opacity-40 transition-all;
}

.custom-scroll::-webkit-scrollbar { width: 5px; }
.custom-scroll::-webkit-scrollbar-thumb { @apply bg-slate-200 rounded-full; }

.animate-fadeIn { animation: fadeIn 0.4s ease-out; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

@keyframes modalIn { from { opacity: 0; transform: scale(0.98) translateY(10px); } to { opacity: 1; transform: scale(1) translateY(0); } }
.animate-modalIn { animation: modalIn 0.2s cubic-bezier(0.16, 1, 0.3, 1); }

.slide-down-enter-active, .slide-down-leave-active { transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
.slide-down-enter-from, .slide-down-leave-to { opacity: 0; transform: translateY(-10px); }
</style>