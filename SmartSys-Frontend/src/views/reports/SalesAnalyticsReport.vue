<template>
  <div class="min-h-screen bg-[#fafafa] text-slate-900 font-sans antialiased selection:bg-blue-100" dir="rtl">
    
    <!-- Top Progress Bar -->
    <PageLoadingBar :active="isLoading" />

    <div class="max-w-[1600px] mx-auto p-6 lg:p-10 space-y-8">
      
      <!-- Page Header -->
      <PageHeader
        :breadcrumb="{ parent: { label: 'التقارير', path: '/reports' }, current: { label: 'تحليلات المبيعات', path: '/reports/sales-analytics' } }"
        title="مركز تحليلات المبيعات"
        description="ذكاء الأعمال • تحليل الإيرادات والربحية"
        :branches="branches"
        :selectedBranch="selectedBranch === 'all' ? null : selectedBranch"
        :hasExplicitSelection="true"
        @branch-changed="selectedBranch = $event === null ? 'all' : $event"
      >
        <template #controls>
          <LoadingButton
            text="تحديث البيانات"
            loadingText="جاري التحديث..."
            icon="fas fa-sync-alt"
            :isLoading="isLoading"
            variant="secondary"
            class="h-9 px-4 rounded-md border border-slate-200 bg-white text-slate-600 text-xs shadow-sm hover:bg-slate-50"
            @click="fetchAnalytics"
          />
        </template>
      </PageHeader>

      <main class="space-y-8">

      <!-- Analytical Filters Utility Bar -->
      <section class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-visible">
        <div class="p-6 flex flex-col lg:flex-row lg:items-end justify-between gap-6">
          <div class="flex flex-wrap gap-4 items-end flex-grow">
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
              <label class="metadata-label">نطاقات سريعة</label>
              <div class="flex items-center gap-1.5 p-1 bg-slate-50 rounded-lg border border-slate-100">
                <button @click="setDateRange('week')" class="quick-range-pill">آخر 7 أيام</button>
                <button @click="setDateRange('month')" class="quick-range-pill">آخر 30 يوماً</button>
              </div>
            </div>
          </div>

          <div class="flex items-center gap-3 bg-blue-50/50 px-4 py-2 rounded-lg border border-blue-100 shadow-inner shrink-0">
             <div class="w-1.5 h-1.5 rounded-full bg-blue-500 animate-pulse"></div>
             <span class="text-[10px] font-bold text-blue-700 uppercase tracking-[0.2em]">Live Data Analytics</span>
          </div>
        </div>
      </section>

      <section
        v-if="analyticsData.summary.returnsCostUnresolvedLineCount > 0"
        class="rounded-xl border border-amber-300 bg-amber-50 px-5 py-4 text-sm text-amber-900"
        role="alert"
      >
        <p class="font-bold">تكلفة بعض مرتجعات المبيعات غير محسومة</p>
        <p class="mt-1">
          {{ analyticsData.summary.returnsCostUnresolvedLineCount }} بند مرتجع
          <span v-if="analyticsData.summary.returnsCostUnresolvedQuantity > 0">
            بكمية {{ analyticsData.summary.returnsCostUnresolvedQuantity }}
          </span>
          بلا تكلفة تاريخية موثوقة؛ قد لا يعكس الربح الصافي القيمة النهائية. راجع تكلفة حركة المرتجع أو تكلفة بند البيع الأصلي.
        </p>
      </section>

      <!-- KPI Performance Grid -->
      <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-4">
        <div v-for="card in summaryCards" :key="card.title" class="bg-white border border-slate-200 p-5 rounded-xl flex flex-col justify-between group hover:border-slate-300 transition-all shadow-sm border-r-4" :class="card.color.replace('text', 'border-r')">
          <div class="flex justify-between items-start mb-4">
            <p class="text-[9px] font-bold text-slate-400 uppercase tracking-widest leading-none">{{ card.title }}</p>
            <div :class="[card.bg, card.color]" class="w-7 h-7 rounded-lg flex items-center justify-center text-xs opacity-70 group-hover:opacity-100 transition-opacity">
              <i :class="card.icon"></i>
            </div>
          </div>
          <p :class="[card.color, 'text-xl font-bold font-mono tracking-tighter leading-none']">
            {{ card.format === 'currency' ? formatCurrency(card.value) : formatNumber(card.value) }}
          </p>
        </div>
      </section>

      <!-- Main Trend Chart -->
      <section class="bg-white border border-slate-200 rounded-xl p-6 shadow-sm flex flex-col relative overflow-hidden group">
        <div class="absolute top-0 left-0 w-64 h-64 bg-blue-500/5 rounded-full -translate-x-12 -translate-y-12 group-hover:scale-110 transition-transform duration-1000"></div>
        
        <div class="flex items-center justify-between mb-8 relative z-10">
           <h2 class="text-sm font-bold text-slate-900 uppercase tracking-widest flex items-center gap-2">
             <span class="w-1 h-5 bg-blue-600 rounded-full"></span>
             أداء المبيعات اللحظي (Financial Trend)
           </h2>
           <span class="text-[9px] font-bold text-slate-400 bg-slate-50 px-2 py-1 rounded border border-slate-100">إجمالي إيرادات الفترة</span>
        </div>

        <div class="flex-grow relative z-10 h-96">
          <div v-if="isLoading" class="absolute inset-0 flex items-center justify-center bg-white/80 backdrop-blur-sm z-20">
            <div class="flex items-center gap-1">
              <div class="w-2 h-2 rounded-full bg-slate-400 animate-pulse"></div>
              <div class="w-2 h-2 rounded-full bg-slate-400 animate-pulse" style="animation-delay: 0.2s"></div>
              <div class="w-2 h-2 rounded-full bg-slate-400 animate-pulse" style="animation-delay: 0.4s"></div>
            </div>
          </div>
          <LineChart v-if="analyticsData.salesOverTime.length" :data="salesOverTimeChart" :options="chartOptions" />
          <div v-else class="h-full flex flex-col items-center justify-center text-slate-300 gap-3 opacity-30 italic font-bold">بانتظار مزامنة البيانات...</div>
        </div>
      </section>
      
      <!-- Distribution Grid -->
      <section class="grid grid-cols-1 lg:grid-cols-12 gap-8">
          <!-- Top Products (8/12) -->
          <div class="lg:col-span-8 bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm flex flex-col">
               <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
                  <h3 class="text-xs font-bold text-slate-900 uppercase tracking-widest flex items-center gap-2">
                    <i class="fas fa-trophy text-amber-500"></i> قائمة الأصناف الأكثر طلباً
                  </h3>
               </div>
               <div class="p-6 grid grid-cols-1 xl:grid-cols-12 gap-8 flex-grow">
                   <div class="xl:col-span-7 h-[300px]">
                      <BarChart v-if="!isLoading" :data="topProductsChart" :options="chartOptions" />
                   </div>
                   <!-- Technical Data Table -->
                   <div class="xl:col-span-5 bg-slate-50 border border-slate-100 rounded-xl overflow-hidden">
                      <table class="w-full text-right text-[11px] font-medium border-collapse">
                          <thead>
                            <tr class="bg-white/50 text-slate-400 font-bold uppercase border-b border-slate-100">
                              <th class="px-4 py-3">الصنف</th>
                              <th class="px-4 py-3 text-center">الكمية</th>
                              <th class="px-4 py-3 text-left">الإيراد</th>
                            </tr>
                          </thead>
                          <tbody class="divide-y divide-slate-100 font-bold">
                              <tr v-for="product in analyticsData.topProducts.slice(0, 8)" :key="product.id" class="hover:bg-white transition-colors">
                                  <td class="px-4 py-2.5 text-slate-700 leading-tight truncate max-w-[120px]" :title="product.name">{{ product.name }}</td>
                                  <td class="px-4 py-2.5 text-center text-slate-900 font-mono">{{ formatNumber(product.quantity) }}</td>
                                  <td class="px-4 py-2.5 text-left text-blue-600 font-mono tracking-tighter">{{ formatCurrency(product.totalSales) }}</td>
                              </tr>
                          </tbody>
                      </table>
                   </div>
               </div>
          </div>

          <!-- Category Doughnut (4/12) -->
          <div class="lg:col-span-4 bg-white border border-slate-200 rounded-xl p-6 shadow-sm flex flex-col items-center">
               <h3 class="text-xs font-bold text-slate-900 uppercase tracking-widest self-start flex items-center gap-2 mb-8">
                 <i class="fas fa-chart-pie text-indigo-500"></i> توزيع الحصص حسب الفئة
               </h3>
               <div class="flex-grow w-full relative h-72">
                 <DoughnutChart v-if="!isLoading" :data="salesByCategoryChart" :options="doughnutOptions" />
               </div>
               <div class="w-full mt-6 grid grid-cols-2 gap-y-2 pt-6 border-t border-slate-50">
                  <div v-for="(cat, idx) in analyticsData.salesByCategory.slice(0, 6)" :key="cat.id" class="flex items-center gap-2">
                     <span class="w-1.5 h-1.5 rounded-full" :style="{ backgroundColor: ['#3B82F6', '#10B981', '#6366F1', '#F59E0B', '#F43F5E', '#8B5CF6'][idx % 6] }"></span>
                     <span class="text-[9px] font-bold text-slate-400 uppercase truncate">{{ cat.name }}</span>
                  </div>
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
import { useReportsStore } from '@/stores/reports/reportsStore';
import { useAnalyticsStore } from '@/stores/analytics/analyticsStore';
import { Line as LineChart, Bar as BarChart, Doughnut as DoughnutChart } from 'vue-chartjs';
import { Chart as ChartJS, Title, Tooltip, Legend, LineElement, BarElement, CategoryScale, LinearScale, PointElement, ArcElement, Filler } from 'chart.js';
import { useCompanyCurrency } from '@/composables/useCompanyCurrency';
import { getLocalDateISO } from '@/utils';

import { useBreadcrumb } from '@/composables/useBreadcrumb';
import { useBranchStore } from '@/stores/branch/branchStore';
import PageHeader from '@/components/PageHeader.vue';
import LoadingButton from '@/components/ui/LoadingButton.vue';

ChartJS.register(Title, Tooltip, Legend, LineElement, BarElement, CategoryScale, LinearScale, PointElement, ArcElement, Filler);

const reportsStore = useReportsStore();
const branchStore = useBranchStore();
const branches = computed(() => branchStore.branches);
const selectedBranch = computed({
  get: () => branchStore.selectedBranchId || 'all',
  set: (val) => branchStore.setSelectedBranch(val === 'all' ? null : val)
});
const { breadcrumb } = useBreadcrumb();
const analyticsStore = useAnalyticsStore();
const { formatCurrencyLocale, fetchSettings } = useCompanyCurrency();
const isLoading = ref(true);
const error = ref(null);

const startDate = ref(getLocalDateISO(new Date(Date.now() - 30 * 24 * 60 * 60 * 1000)));
const endDate = ref(getLocalDateISO());
const startDateRef = ref(null);
const endDateRef = ref(null);
let debounceTimer = null;

const analyticsData = ref({
  summary: {
    totalSales: 0,
    totalReturns: 0,
    totalRevenue: 0,
    totalTax: 0,
    grandTotal: 0,
    netProfit: 0,
    returnsCostUnresolvedLineCount: 0,
    returnsCostUnresolvedQuantity: 0,
  },
  salesOverTime: [],
  topProducts: [],
  salesByCategory: []
});

const summaryCards = computed(() => [
  { title: 'إجمالي المبيعات', value: analyticsData.value.summary.totalSales, format: 'currency', icon: 'fas fa-shopping-cart', color: 'text-emerald-600', bg: 'bg-emerald-50' },
  { title: 'إجمالي المرتجعات', value: analyticsData.value.summary.totalReturns, format: 'currency', icon: 'fas fa-undo', color: 'text-rose-600', bg: 'bg-rose-50' },
  { title: 'صافي المبيعات (قبل الضريبة)', value: analyticsData.value.summary.totalRevenue, format: 'currency', icon: 'fas fa-chart-line', color: 'text-blue-600', bg: 'bg-blue-50' },
  { title: 'ضريبة القيمة المضافة', value: analyticsData.value.summary.totalTax, format: 'currency', icon: 'fas fa-receipt', color: 'text-indigo-600', bg: 'bg-indigo-50' },
  { title: 'الإجمالي النهائي (شامل الضريبة)', value: analyticsData.value.summary.grandTotal, format: 'currency', icon: 'fas fa-dollar-sign', color: 'text-slate-900', bg: 'bg-slate-50' },
  { title: 'صافي الأرباح', value: analyticsData.value.summary.netProfit, format: 'currency', icon: 'fas fa-wallet', color: 'text-amber-600', bg: 'bg-amber-50' }
]);

const salesOverTimeChart = computed(() => ({
  labels: analyticsData.value.salesOverTime.map(d => formatDate(d.date, { month: 'short', day: 'numeric' })),
  datasets: [{ 
    label: 'المبيعات', 
    data: analyticsData.value.salesOverTime.map(d => d.total), 
    borderColor: '#3b82f6', 
    backgroundColor: 'rgba(59, 130, 246, 0.05)', 
    fill: true, 
    tension: 0.4, 
    pointRadius: 0, 
    pointHoverRadius: 6,
    borderWidth: 2
  }]
}));

const topProductsChart = computed(() => ({
    labels: analyticsData.value.topProducts.slice(0, 5).map(p => p.name),
    datasets: [{ 
      label: 'الإيرادات', 
      data: analyticsData.value.topProducts.slice(0, 5).map(p => p.totalSales), 
      backgroundColor: ['#3b82f6', '#10b981', '#6366f1', '#f59e0b', '#f43f5e'], 
      borderRadius: 4, 
      maxBarThickness: 32 
    }]
}));

const salesByCategoryChart = computed(() => ({
    labels: analyticsData.value.salesByCategory.map(c => c.name),
    datasets: [{ 
      data: analyticsData.value.salesByCategory.map(c => c.totalSales), 
      backgroundColor: ['#3b82f6', '#10b981', '#6366f1', '#f59e0b', '#f43f5e', '#8b5cf6'], 
      borderWidth: 0 
    }]
}));

const chartOptions = { 
  responsive: true, 
  maintainAspectRatio: false, 
  plugins: { 
    legend: { display: false },
    tooltip: {
      backgroundColor: '#0f172a',
      padding: 12,
      titleFont: { family: 'Cairo', size: 12, weight: 'bold' },
      bodyFont: { family: 'Cairo', size: 11 },
      cornerRadius: 8,
      rtl: true
    }
  }, 
  scales: { 
    y: { 
      grid: { color: '#f1f5f9', drawBorder: false }, 
      ticks: { font: { family: 'Cairo', size: 10, weight: 'bold' }, color: '#94a3b8' } 
    }, 
    x: { grid: { display: false }, ticks: { font: { family: 'Cairo', size: 10, weight: 'bold' }, color: '#94a3b8' } } 
  } 
};

const doughnutOptions = { 
  responsive: true, 
  maintainAspectRatio: false, 
  plugins: { legend: { display: false } }, 
  cutout: '75%' 
};

const formatCurrency = (v) => formatCurrencyLocale(v, 2);
const formatNumber = (v) => (Number(v) || 0).toLocaleString('en-US');
const formatDate = (v, o = {}) => v ? new Date(v).toLocaleDateString('en-US', o) : '';

const toNumber = (value) => Number(value) || 0;

const fetchAnalytics = async () => {
    isLoading.value = true; error.value = null;
    try {
        const result = await analyticsStore.fetchSalesAnalytics({ startDate: startDate.value, endDate: endDate.value, branchId: selectedBranch.value === 'all' ? null : selectedBranch.value });
        if (result?.status === 'error') throw new Error(result.message || 'فشل تحميل تحليلات المبيعات');

        // The store normally returns { status, data }, while a direct Axios
        // response is shaped as { data: { status, data } }. Support both so
        // the view always reads the actual analytics payload.
        const apiData = result?.data?.data ?? result?.data ?? result ?? {};
        const summary = apiData.summary || {};
        const revenue = apiData.revenue || {};
        const totalSales = toNumber(apiData.total_sales_amount ?? summary.total_sales_amount);
        const totalReturns = toNumber(apiData.total_returns_amount ?? summary.total_returns_amount);
        const totalRevenue = toNumber(apiData.net_sales ?? (totalSales - totalReturns));
        const totalTax = toNumber(apiData.total_tax_amount ?? summary.total_tax_amount);
        const netTax = totalTax - toNumber(apiData.total_returns_tax);
        const grandTotal = toNumber(apiData.net_grand_total ?? (totalRevenue + netTax));
        const grossProfit = toNumber(summary.gross_profit ?? revenue.gross_profit ?? apiData.gross_profit);
        const netProfit = toNumber(summary.net_profit ?? apiData.net_profit ?? grossProfit);

        analyticsData.value = {
            summary: {
                totalSales,
                totalReturns,
                totalRevenue,
                totalTax: netTax,
                grandTotal,
                netProfit,
                grossProfit,
                returnsCostUnresolvedLineCount: toNumber(apiData.returns_cost_unresolved_line_count),
                returnsCostUnresolvedQuantity: toNumber(apiData.returns_cost_unresolved_quantity),
            },
            salesOverTime: (apiData.daily_sales || apiData.salesOverTime || []).map(day => ({ date: day.date, total: toNumber(day.total_revenue ?? day.total) })),
            topProducts: (apiData.top_products || apiData.topProducts || []).map(p => ({ id: p.id, name: p.name, totalSales: toNumber(p.total_revenue ?? p.totalSales), quantity: toNumber(p.total_quantity ?? p.quantity) })),
            salesByCategory: (apiData.top_categories || apiData.salesByCategory || []).map(c => ({ id: c.id, name: c.name, totalSales: toNumber(c.total_revenue ?? c.totalSales) }))
        };
    } catch (e) { error.value = e.message || "خطأ في التحميل"; } finally { isLoading.value = false; }
};

const setDateRange = (range) => {
    const end = new Date(); let start = new Date();
    if (range === 'week') start.setDate(end.getDate() - 7);
    else if (range === 'month') start.setMonth(end.getMonth() - 1);
    endDate.value = getLocalDateISO(end); startDate.value = getLocalDateISO(start);
};

watch([startDate, endDate, selectedBranch], () => { clearTimeout(debounceTimer); debounceTimer = setTimeout(fetchAnalytics, 700); });
onMounted(async () => { await Promise.all([fetchSettings(), branchStore.fetchBranches().catch(() => {}), fetchAnalytics()]); });
</script>

<style scoped>
@keyframes loading { 0% { transform: translateX(100%); } 100% { transform: translateX(-100%); } }

.filter-input-v2 {
  @apply focus:ring-4 focus:ring-blue-500/5;
}

.metadata-label { @apply block text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1; }

.quick-range-pill {
  @apply px-3 py-1.5 rounded-md bg-white border border-slate-200 text-[10px] font-bold text-slate-500 hover:border-blue-400 hover:text-blue-600 transition-all;
}

.custom-scroll::-webkit-scrollbar { width: 5px; height: 5px; }
.custom-scroll::-webkit-scrollbar-thumb { @apply bg-slate-200 rounded-full; }

.animate-fadeIn { animation: fadeIn 0.4s ease-out; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
</style>