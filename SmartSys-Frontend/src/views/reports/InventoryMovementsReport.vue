<template>
  <div class="min-h-screen bg-[#fafafa] text-slate-900 font-sans antialiased selection:bg-blue-100" dir="rtl">
    
    <div class="max-w-[1600px] mx-auto p-6 lg:p-10 space-y-8">
      
      <!-- Page Header -->
      <PageHeader
        :breadcrumb="{ parent: { label: 'التقارير', path: '/reports' }, current: { label: 'تقرير حركة المخزون', path: '/reports/inventory-movements' } }"
        title="تقرير حركة المخزون"
        description="تتبع الوارد والمنصرف واللوجستيات"
        :branches="branches"
        :selectedBranch="selectedBranch === 'all' ? null : selectedBranch"
        :hasExplicitSelection="true"
        @branch-changed="selectedBranch = $event === null ? 'all' : $event"
      >
        <template #controls>
          <button @click="exportCsv" class="h-9 px-4 rounded-md border border-slate-200 bg-white text-emerald-600 text-xs font-bold hover:bg-emerald-50 transition-all flex items-center gap-2">
            <i class="fas fa-file-csv text-[10px]"></i> تصدير CSV
          </button>
        </template>
      </PageHeader>

      <main class="space-y-8">

      <!-- Analytical Filters Panel: Professional Utility Bar -->
      <section class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-visible">
        <div class="p-6 space-y-6">
          <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 items-end">
            <div class="space-y-1.5 group">
              <label class="metadata-label">من تاريخ</label>
              <div class="relative">
                <input ref="startDateRef" type="date" v-model="startDate" class="filter-input-v2 font-mono" style="padding-left: 2rem;" />
                <i @click="startDateRef?.showPicker?.()" class="fas fa-calendar absolute left-3 top-1/2 -translate-y-1/2 text-slate-300 group-hover:text-blue-500 transition-colors cursor-pointer hover:text-slate-500 text-[10px]"></i>
              </div>
            </div>

            <div class="space-y-1.5 group">
              <label class="metadata-label">إلى تاريخ</label>
              <div class="relative">
                <input ref="endDateRef" type="date" v-model="endDate" class="filter-input-v2 font-mono" style="padding-left: 2rem;" />
                <i @click="endDateRef?.showPicker?.()" class="fas fa-calendar-check absolute left-3 top-1/2 -translate-y-1/2 text-slate-300 group-hover:text-blue-500 transition-colors cursor-pointer hover:text-slate-500 text-[10px]"></i>
              </div>
            </div>

            <div class="space-y-1.5">
              <label class="metadata-label">نوع الحركة</label>
              <select v-model="movementType" class="filter-input-v2 appearance-none font-bold">
                <option value="all">كل الحركات</option>
                <option value="purchase">مشتريات (وارد)</option>
                <option value="sale">مبيعات (صادر)</option>
                <option value="sale_cancel">إلغاء بيع (وارد)</option>
                <option value="return">مرتجعات</option>
                <option value="adjustment">تسويات</option>
              </select>
            </div>

            <div class="flex gap-2 h-9">
               <button @click="setDateRange('week')" class="flex-1 rounded-md bg-slate-100 text-slate-600 text-[10px] font-bold uppercase hover:bg-slate-200 transition-all">أسبوع</button>
               <button @click="setDateRange('month')" class="flex-1 rounded-md bg-slate-100 text-slate-600 text-[10px] font-bold uppercase hover:bg-slate-200 transition-all">شهر</button>
            </div>
          </div>

          <!-- Product Autocomplete: Command Palette Style -->
          <div class="pt-6 border-t border-slate-100 space-y-3">
            <label class="metadata-label">تصفية حسب صنف محدد</label>
            <div class="relative max-w-2xl group">
              <input 
                type="text" 
                v-model="productQuery" 
                class="w-full h-11 pr-11 pl-4 bg-slate-50 border border-slate-200 rounded-lg text-sm font-bold focus:ring-4 focus:ring-blue-500/5 focus:bg-white focus:border-blue-500 outline-none transition-all shadow-inner" 
                placeholder="ابحث بالاسم أو الباركود..." 
                @input="onProductInput" 
                @focus="onProductFocus"
              />
              <i class="fas fa-box absolute right-4 top-1/2 -translate-y-1/2 text-slate-300 group-focus-within:text-blue-500 transition-colors"></i>
              
              <button v-if="selectedProduct" @click="clearSelectedProduct" class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-300 hover:text-rose-500 transition-colors">
                <i class="fas fa-times-circle"></i>
              </button>

              <!-- Dropdown Panel -->
              <transition name="dropdown">
                <div v-if="showProductDropdown && productResults.length" class="absolute z-50 mt-1 w-full bg-white border border-slate-200 rounded-lg shadow-2xl max-h-64 overflow-auto py-1">
                  <div v-for="p in productResults" :key="p.id" @click="selectProduct(p)" class="px-4 py-2.5 hover:bg-blue-50 cursor-pointer flex items-center justify-between border-b border-slate-50 last:border-0 group transition-colors">
                    <div class="flex flex-col">
                      <span class="font-bold text-slate-800 text-xs">{{ p.name }}</span>
                      <span class="text-[9px] text-slate-400 mt-1 font-mono tracking-tighter">{{ p.barcode || '-' }} • {{ p.code || '-' }}</span>
                    </div>
                    <i class="fas fa-plus text-[8px] text-slate-200 group-hover:text-blue-500"></i>
                  </div>
                </div>
              </transition>
            </div>
            <div v-if="selectedProduct" class="inline-flex items-center gap-2 px-3 py-1 bg-blue-50 border border-blue-100 rounded-full animate-fadeIn">
               <span class="w-1.5 h-1.5 rounded-full bg-blue-600 animate-pulse"></span>
               <span class="text-[10px] font-bold text-blue-700 uppercase tracking-tight">مقيد بالمنتج: {{ selectedProduct.name }}</span>
            </div>
          </div>
        </div>
      </section>

      <!-- Analytics Dashboard Grid -->
      <section class="grid grid-cols-1 lg:grid-cols-12 gap-8">
        
        <!-- Summary Stats (4/12) -->
        <div class="lg:col-span-4 space-y-4">
          <div v-for="summary in summaryData" :key="summary.title" class="bg-white border border-slate-200 p-6 rounded-xl flex items-center gap-5 hover:border-slate-300 transition-all shadow-sm group">
            <div :class="[summary.iconClass, 'w-12 h-12 rounded-lg flex items-center justify-center text-lg opacity-80 group-hover:opacity-100 transition-all shadow-inner']">
              <i :class="summary.icon"></i>
            </div>
            <div class="space-y-1">
              <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest leading-none">{{ summary.title }}</p>
              <p :class="[summary.valueClass, 'text-xl font-bold font-mono leading-none tracking-tighter']">
                {{ summary.format === 'currency' ? formatCurrency(summary.value) : formatNumber(summary.value) }}
              </p>
            </div>
          </div>
        </div>

        <!-- Trend Visualization (8/12) -->
        <div class="lg:col-span-8 bg-white border border-slate-200 rounded-xl p-6 shadow-sm flex flex-col relative overflow-hidden group">
          <div class="absolute top-0 right-0 w-32 h-32 bg-blue-500/5 rounded-full -translate-x-8 -translate-y-8 group-hover:scale-110 transition-transform duration-1000"></div>
          
          <div class="flex items-center justify-between mb-8 relative z-10">
             <h2 class="text-sm font-bold text-slate-900 uppercase tracking-widest flex items-center gap-2">
               <i class="fas fa-chart-line text-blue-500"></i> منحنى تغير رصيد المخزون
             </h2>
             <span class="text-[9px] font-bold text-slate-400 bg-slate-50 px-2 py-1 rounded border border-slate-100">تحليل تراكمي</span>
          </div>

          <div class="flex-grow relative z-10 h-64">
            <div v-if="isLoading" class="absolute inset-0 flex items-center justify-center bg-white/80 backdrop-blur-sm z-20">
              <div class="flex items-center gap-1">
                <div class="w-2 h-2 rounded-full bg-slate-400 animate-pulse"></div>
                <div class="w-2 h-2 rounded-full bg-slate-400 animate-pulse" style="animation-delay: 0.2s"></div>
                <div class="w-2 h-2 rounded-full bg-slate-400 animate-pulse" style="animation-delay: 0.4s"></div>
              </div>
            </div>
            <LineChart v-if="movements.length" :data="chartData" :options="chartOptions" />
            <div v-else class="h-full flex flex-col items-center justify-center text-slate-300 gap-3">
               <i class="fas fa-bezier-curve text-2xl opacity-20"></i>
               <p class="text-[10px] font-bold uppercase tracking-widest">بانتظار البيانات للتحليل</p>
            </div>
          </div>
        </div>
      </section>

      <!-- Detailed Audit Ledger Table -->
      <section class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm relative">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
           <h3 class="text-xs font-bold text-slate-900 uppercase tracking-widest flex items-center gap-2">
             <i class="fas fa-list-ul text-slate-400"></i> سجل دفتر المخزن التفصيلي
           </h3>
           <span class="text-[10px] font-bold text-slate-400 bg-white border border-slate-200 px-3 py-1 rounded-full uppercase tracking-tighter">
             الفرز: {{ sortOrder === 'asc' ? 'تصاعدي' : 'تنازلي' }} • {{ movements.length }} عملية
           </span>
        </div>

        <div class="overflow-x-auto">
          <table class="w-full text-right border-collapse">
            <thead>
              <tr class="bg-slate-50/50 border-b border-slate-200 text-slate-400 font-bold uppercase text-[10px] tracking-widest">
                <th @click="handleSort('date')" class="px-6 py-4 cursor-pointer hover:text-blue-600 transition-colors flex items-center gap-2">
                  تاريخ الحركة
                  <i v-if="sortKey === 'date'" class="fas" :class="sortOrder === 'asc' ? 'fa-sort-up' : 'fa-sort-down'" style="font-size: 8px;"></i>
                </th>
                <th class="px-4 py-4">المستند المرجعي</th>
                <th @click="handleSort('product')" class="px-4 py-4 cursor-pointer hover:text-blue-600 transition-colors flex items-center gap-2">
                  الصنف
                  <i v-if="sortKey === 'product'" class="fas" :class="sortOrder === 'asc' ? 'fa-sort-up' : 'fa-sort-down'" style="font-size: 8px;"></i>
                </th>
                <th class="px-4 py-4 text-center">النوع</th>
                <th class="px-4 py-4 text-center">وارد (+)</th>
                <th class="px-4 py-4 text-center">منصرف (-)</th>
                <th class="px-4 py-4 text-center">الرصيد التراكمي للصنف</th>
                <th class="px-4 py-4 text-left">القيمة</th>
                <th class="px-6 py-4">البيان</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 font-medium text-xs">
              <template v-if="isLoading">
                <tr v-for="n in 6" :key="n" class="animate-pulse">
                  <td v-for="m in 9" :key="m" class="px-6 py-4"><div class="h-3 bg-slate-100 rounded w-full"></div></td>
                </tr>
              </template>
              <tr v-else-if="!movements.length">
                <td colspan="9" class="py-24 text-center text-slate-300">
                   <i class="fas fa-boxes-stacked text-3xl mb-4 opacity-20"></i>
                   <p class="text-xs font-bold uppercase tracking-widest">لا توجد حركات مسجلة للفترة</p>
                </td>
              </tr>
              <tr v-for="m in paginatedMovements" :key="m.id" class="hover:bg-blue-50/20 transition-all group border-r-2 border-r-transparent hover:border-r-blue-500">
                <td class="px-6 py-4 text-[10px] font-mono font-bold text-slate-400 group-hover:text-slate-900 transition-colors">{{ formatDate(m.date) }}</td>
                <td class="px-4 py-4">
                  <router-link v-if="getReferenceRoute(m)" :to="getReferenceRoute(m)" class="text-blue-600 font-bold hover:underline decoration-blue-200">
                    {{ m.reference }}
                  </router-link>
                  <span v-else class="text-slate-400 font-mono text-[10px] uppercase">{{ m.reference }}</span>
                </td>
                <td class="px-4 py-4 text-slate-700 font-bold truncate max-w-[150px]">{{ m.product }}</td>
                <td class="px-4 py-4 text-center">
                  <span :class="[getMovementTypeClass(m.type)]" class="px-2 py-0.5 rounded text-[9px] font-bold border">
                    {{ getMovementTypeLabel(m.type) }}
                  </span>
                </td>
                <td class="px-4 py-4 text-center font-mono font-bold text-emerald-600">{{ m.in > 0 ? formatNumber(m.in) : '—' }}</td>
                <td class="px-4 py-4 text-center font-mono font-bold text-rose-600">{{ m.out > 0 ? formatNumber(m.out) : '—' }}</td>
                <td class="px-4 py-4 text-center font-mono font-bold text-slate-900 bg-slate-50/50 group-hover:bg-white transition-all text-sm border-x border-slate-100">{{ formatNumber(m.balance) }}</td>
                <td class="px-4 py-4 text-left font-mono font-bold text-indigo-500">{{ formatCurrency(movementValue(m)) }}</td>
                <td class="px-6 py-4 text-[11px] text-slate-400 italic truncate max-w-[180px]" :title="m.notes">{{ m.notes || '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Pagination Footer (Unified Standard) -->
        <div class="px-6 py-4 bg-slate-50/50 border-t border-slate-200 flex items-center justify-between">
          <div class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">
            صفحة <span class="text-slate-900">{{ filters.page.value }}</span> من <span class="text-slate-900">{{ filters.totalPages.value }}</span>
            <span class="mx-2 text-slate-200">|</span>
            إجمالي <span class="text-slate-900">{{ sortedMovements.length }}</span> عملية
          </div>
          <div class="flex items-center gap-3">
             <div class="flex items-center gap-2">
               <span class="text-[10px] font-bold text-slate-400 uppercase tracking-tighter">النتائج:</span>
               <select v-model.number="filters.perPage.value" class="h-8 border border-slate-200 rounded px-2 text-[10px] font-bold outline-none">
                 <option :value="10">10</option>
                 <option :value="20">20</option>
                 <option :value="50">50</option>
               </select>
             </div>
             <PaginationControls
               :page="filters.page.value"
               :total-pages="filters.totalPages.value"
               @previous="filters.previousPage()"
               @next="filters.nextPage(filters.totalPages.value)"
             />
          </div>
        </div>
      </section>
      </main>
    </div>
  </div>
</template>

<script setup>
// [بقاء المنطق البرمجي كما هو بنسبة 100%]
import { ref, computed, onMounted, watch } from 'vue';
import { Line as LineChart } from 'vue-chartjs';
import { Chart as ChartJS, Title, Tooltip, Legend, LineElement, CategoryScale, LinearScale, PointElement, Filler } from 'chart.js';

import BaseSkeleton from '@/components/ui/BaseSkeleton.vue';
import { getLocalDateISO, getLocalTimestamp } from '@/utils';
import { useTableFilters } from '@/composables/useTableFilters';
import { useReportsStore } from '@/stores/reports/reportsStore';
import { useBranchStore } from '@/stores/branch/branchStore';
import { useProductStore } from '@/stores/product/productStore';
import { useCompanyCurrency } from '@/composables/useCompanyCurrency';
import PageHeader from '@/components/PageHeader.vue';
import PaginationControls from '@/components/ui/PaginationControls.vue';
import { useBreadcrumb } from '@/composables/useBreadcrumb';

ChartJS.register(Title, Tooltip, Legend, LineElement, CategoryScale, LinearScale, PointElement, Filler);

const reportsStore = useReportsStore();
const branchStore = useBranchStore();
const { breadcrumb } = useBreadcrumb();
const productStore = useProductStore();
const { formatCurrencyLocale, fetchSettings } = useCompanyCurrency();
const isLoading = ref(true);
const error = ref(null);

const startDate = ref(getLocalDateISO(new Date(Date.now() - 7 * 24 * 60 * 60 * 1000)));
const endDate = ref(getLocalDateISO());
const startDateRef = ref(null);
const endDateRef = ref(null);
const movementType = ref('all');
let debounceTimer = null;

const movements = ref([]);

// ─── Pagination (using useTableFilters composable)
const filters = useTableFilters('inventory_movements_filters');

const openingBalance = ref(0);
const finalBalance = ref(0);
const currentValuation = ref(0);
const branches = computed(() => branchStore.branches);
const selectedBranch = computed({
  get: () => branchStore.selectedBranchId || 'all',
  set: (val) => branchStore.setSelectedBranch(val === 'all' ? null : val)
});
const productQuery = ref('');
const productResults = ref([]);
const selectedProduct = ref(null);
const showProductDropdown = ref(false);

const sortKey = ref('date');
const sortOrder = ref('desc');
const isReportInitialized = ref(false);

const summaryData = computed(() => {
  const totalIn = movements.value.reduce((sum, item) => sum + (item.in || 0), 0);
  const totalOut = movements.value.reduce((sum, item) => sum + (item.out || 0), 0);
  return [
    { title: selectedProduct.value ? 'الرصيد الافتتاحي' : 'الرصيد الافتتاحي الإجمالي', value: openingBalance.value, icon: 'fas fa-box-open', iconClass: 'bg-indigo-50 text-indigo-600', valueClass: 'text-indigo-600', format: 'number' },
    { title: 'إجمالي الوارد (+)', value: totalIn, icon: 'fas fa-arrow-down', iconClass: 'bg-emerald-50 text-emerald-600', valueClass: 'text-emerald-600', format: 'number' },
    { title: 'إجمالي المنصرف (-)', value: totalOut, icon: 'fas fa-arrow-up', iconClass: 'bg-rose-50 text-rose-600', valueClass: 'text-rose-600', format: 'number' },
    { title: 'القيمة التقديرية الحالية', value: currentValuation.value, icon: 'fas fa-coins', iconClass: 'bg-blue-50 text-blue-600', valueClass: 'text-blue-600', format: 'currency' }
  ];
});

const sortedMovements = computed(() => {
  return [...movements.value].sort((a, b) => {
    let vA = a[sortKey.value], vB = b[sortKey.value];
    if (sortKey.value === 'date') { vA = new Date(vA); vB = new Date(vB); }
    if (vA < vB) return sortOrder.value === 'asc' ? -1 : 1;
    if (vA > vB) return sortOrder.value === 'asc' ? 1 : -1;
    return 0;
  });
});

const paginatedMovements = computed(() => {
  filters.totalCount.value = sortedMovements.value.length;
  const start = (filters.page.value - 1) * filters.perPage.value;
  const end = start + filters.perPage.value;
  return sortedMovements.value.slice(start, end);
});

const chartData = computed(() => {
  const reversed = [...sortedMovements.value].reverse();
  return {
    labels: reversed.map(m => formatDate(m.date, { day: 'numeric', month: 'short' })),
    datasets: [{ label: 'الرصيد', backgroundColor: 'rgba(59, 130, 246, 0.05)', borderColor: '#3b82f6', data: reversed.map(m => m.balance), fill: true, tension: 0.4, pointRadius: 0, pointHoverRadius: 4, borderWidth: 2 }]
  };
});

const chartOptions = { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { backgroundColor: '#1e293b', padding: 12, titleFont: { family: 'Cairo', size: 12 }, bodyFont: { family: 'Cairo', size: 11 }, cornerRadius: 8 } }, scales: { y: { grid: { color: '#f1f5f9', drawBorder: false }, ticks: { font: { family: 'Cairo', size: 10, weight: 'bold' }, color: '#94a3b8' } }, x: { grid: { display: false }, ticks: { font: { family: 'Cairo', size: 10, weight: 'bold' }, color: '#94a3b8' } } } };

const fetchReport = async () => {
  isLoading.value = true;
  try {
    const pId = selectedProduct.value?.id || null;
    const bId = selectedBranch.value !== 'all' ? String(selectedBranch.value) : undefined;
    let apiData = await reportsStore.fetchInventoryMovements(startDate.value, endDate.value, pId, movementType.value, bId);
    if (apiData?.data) apiData = apiData.data;
    if (apiData?.data) apiData = apiData.data;
    if (Array.isArray(apiData)) { movements.value = apiData; openingBalance.value = 0; finalBalance.value = movements.value.at(-1)?.balance || 0; currentValuation.value = 0; }
    else {
      movements.value = (apiData?.items || apiData?.movements || []).map(item => ({
        ...item,
        quantity: Number(item.quantity) || 0,
        in: Number(item.in) || 0,
        out: Number(item.out) || 0,
        balance: Number(item.balance) || 0,
        cost: Number(item.cost) || 0
      }));
      openingBalance.value = Number(apiData?.opening_balance) || 0;
      finalBalance.value = Number(apiData?.final_balance) || movements.value.at(-1)?.balance || openingBalance.value;
      currentValuation.value = Number(apiData?.valuation) || 0;
    }
  } catch (err) {
    console.error('[InventoryMovementsReport] load failed:', err);
    movements.value = [];
    openingBalance.value = 0;
    finalBalance.value = 0;
    currentValuation.value = 0;
  } finally { isLoading.value = false; }
};

const exportCsv = () => {
  const columns = [
    ['date', 'التاريخ'],
    ['reference', 'المرجع'],
    ['product', 'المنتج'],
    ['type', 'النوع'],
    ['in', 'وارد'],
    ['out', 'صادر'],
    ['balance', 'الرصيد'],
    ['notes', 'ملاحظات'],
    ['cost', 'التكلفة']
  ];
  const escapeCsv = (value) => `"${String(value ?? '').replaceAll('"', '""')}"`;
  const rows = [
    columns.map(([, label]) => escapeCsv(label)).join(','),
    ...sortedMovements.value.map((movement) => columns
      .map(([key]) => escapeCsv(movement[key]))
      .join(','))
  ];
  const blob = new Blob(['\uFEFF', rows.join('\r\n')], { type: 'text/csv;charset=utf-8' });
  const url = window.URL.createObjectURL(blob);
  const anchor = document.createElement('a');
  anchor.href = url;
  anchor.download = `inventory_${getLocalTimestamp()}.csv`;
  anchor.click();
  setTimeout(() => window.URL.revokeObjectURL(url), 0);
};

const formatCurrency = (v) => formatCurrencyLocale(v, 2);
const formatNumber = (v) => (Number(v) || 0).toLocaleString('en-US');
const formatDate = (v, o = {}) => v ? new Date(v).toLocaleDateString('en-US', o) : '';
const movementValue = (mv) => (Number(mv.in || 0) - Number(mv.out || 0)) * Number(mv.cost || 0);
const getMovementTypeClass = (t) => ({ purchase: 'text-emerald-600 border-emerald-100 bg-emerald-50', sale: 'text-blue-600 border-blue-100 bg-blue-50', sale_cancel: 'text-rose-600 border-rose-100 bg-rose-50', return: 'text-amber-600 border-amber-100 bg-amber-50', adjustment: 'text-purple-600 border-purple-100 bg-purple-50' }[t] || 'text-slate-400 bg-slate-50 border-slate-100');
const getMovementTypeLabel = (t) => ({
  purchase: 'مشتريات',
  sale: 'مبيعات',
  sale_cancel: 'إلغاء بيع',
  return: 'مرتجع',
  adjustment: 'تسوية',
  initial_stock: 'إدخال مخزون أولي',
  opening_balance: 'رصيد افتتاحي',
  opening_balance_manual: 'رصيد افتتاحي يدوي',
  opening_balance_bulk: 'رصيد افتتاحي دفعي'
}[t] || t);
const handleSort = (key) => { if (sortKey.value === key) sortOrder.value = sortOrder.value === 'asc' ? 'desc' : 'asc'; else { sortKey.value = key; sortOrder.value = 'asc'; } };
const setDateRange = (r) => { const end = new Date(); let start = new Date(); if (r === 'week') start.setDate(end.getDate() - 7); else start.setMonth(end.getMonth() - 1); endDate.value = getLocalDateISO(end); startDate.value = getLocalDateISO(start); };

let productSearchTimer = null;
const onProductInput = async () => { 
  showProductDropdown.value = true; clearTimeout(productSearchTimer); 
  productSearchTimer = setTimeout(async () => { 
    try { 
      const result = await productStore.searchProducts({
        query: productQuery.value,
        branchId: selectedBranch.value !== 'all' ? selectedBranch.value : undefined,
        limit: 50
      });
      if (result.status === 'success' && Array.isArray(result.data)) productResults.value = result.data;
      else productResults.value = [];
    } catch { productResults.value = []; } 
  }, 300); 
};
const onProductFocus = () => { if (!productResults.value.length && productQuery.value) onProductInput(); showProductDropdown.value = true; };
const selectProduct = (p) => { selectedProduct.value = p; productQuery.value = p.name; showProductDropdown.value = false; fetchReport(); };
const clearSelectedProduct = () => { selectedProduct.value = null; productQuery.value = ''; showProductDropdown.value = false; fetchReport(); };

const getReferenceRoute = (m) => {
  const ref = m.reference || ''; if (!ref.includes('#')) return null;
  const [kind, id] = ref.split('#');
  const map = { sale: 'SalesHistory', return: 'ReturnsHistory', purchase: 'PurchaseHistory' };
  return map[kind] ? { name: map[kind], query: { id: Number(id) } } : null;
};

onMounted(async () => {
  await Promise.all([fetchSettings(), branchStore.fetchBranches().catch(() => {})]);
  isReportInitialized.value = true;
  fetchReport();
});

watch([startDate, endDate, movementType, selectedBranch], () => {
  if (!isReportInitialized.value) return;
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(fetchReport, 700);
});
</script>

<style scoped>
@keyframes loading { 0% { transform: translateX(100%); } 100% { transform: translateX(-100%); } }

.filter-input-v2 {
  @apply focus:ring-4 focus:ring-blue-500/5;
}

.metadata-label { @apply mb-1; }

.status-badge { @apply px-2 py-0.5 rounded text-[9px] font-bold border inline-flex items-center justify-center; }

.custom-scroll::-webkit-scrollbar { width: 5px; height: 5px; }
.custom-scroll::-webkit-scrollbar-thumb { @apply bg-slate-200 rounded-full; }

.animate-fadeIn { animation: fadeIn 0.4s ease-out; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

/* Dropdown Animation */
.dropdown-enter-active { animation: dropdownIn 0.2s ease-out; }
@keyframes dropdownIn { from { opacity: 0; transform: translateY(-8px); } to { opacity: 1; transform: translateY(0); } }
</style>