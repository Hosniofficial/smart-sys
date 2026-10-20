<template>
  <div class="min-h-screen bg-[#fafafa] text-slate-900 font-sans antialiased selection:bg-blue-100" dir="rtl">
    
    <!-- Top Progress Bar -->
    <div v-if="isLoading" class="fixed top-0 left-0 right-0 h-0.5 bg-blue-600/10 z-[110]">
      <div class="h-full bg-blue-600 animate-[loading_2s_ease-in-out_infinite] w-1/3"></div>
    </div>

    <div class="max-w-[1600px] mx-auto p-6 lg:p-10 space-y-8">
      
      <!-- Page Header Area -->
      <PageHeader
        :breadcrumb="breadcrumb"
        title="تقرير الأرباح والخسائر"
        description="تحليل القوائم المالية وهوامش الربحية التشغيلية"
        :branches="branches"
        :selectedBranch="selectedBranch === 'all' ? null : selectedBranch"
        :hasExplicitSelection="true"
        @branch-changed="selectedBranch = $event === null ? 'all' : $event"
      >
        <template #controls>
          <button @click="fetchReport" :disabled="isLoading" class="h-9 px-4 rounded-md border border-slate-200 bg-white text-slate-600 text-xs font-bold hover:bg-slate-50 transition-all flex items-center gap-2 shadow-sm">
            <i class="fas fa-sync-alt text-[10px]" :class="{'animate-spin': isLoading}"></i> تحديث التقرير
          </button>
        </template>
      </PageHeader>

      <main class="space-y-8">

      <!-- Smart Filters Panel: Utility Grid -->
      <section class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-visible">
        <div class="p-6 flex flex-col lg:flex-row lg:items-end justify-between gap-6">
          
          <div class="flex flex-wrap gap-4 items-end flex-grow">
            <div class="space-y-1.5 group">
              <label class="metadata-label">من تاريخ</label>
              <div class="relative">
                <input ref="startDateRef" type="date" v-model="startDate" class="filter-input-v2 font-mono" style="padding-left: 2rem;" />
                <i @click="startDateRef?.showPicker?.()" class="fas fa-calendar absolute left-3 top-1/2 -translate-y-1/2 text-slate-300 group-hover:text-blue-500 transition-colors text-[10px] cursor-pointer hover:text-slate-500"></i>
              </div>
            </div>

            <div class="space-y-1.5 group">
              <label class="metadata-label">إلى تاريخ</label>
              <div class="relative">
                <input ref="endDateRef" type="date" v-model="endDate" class="filter-input-v2 font-mono" style="padding-left: 2rem;" />
                <i @click="endDateRef?.showPicker?.()" class="fas fa-calendar-check absolute left-3 top-1/2 -translate-y-1/2 text-slate-300 group-hover:text-blue-500 transition-colors text-[10px] cursor-pointer hover:text-slate-500"></i>
              </div>
            </div>

            <div class="space-y-1.5">
              <label class="metadata-label">نطاقات سريعة</label>
              <div class="flex items-center gap-1.5 p-1 bg-slate-50 rounded-lg border border-slate-100">
                <button @click="setDateRange('month')" class="quick-range-pill">الشهر الحالي</button>
                <button @click="setDateRange('quarter')" class="quick-range-pill">آخر 3 أشهر</button>
                <button @click="setDateRange('year')" class="quick-range-pill">السنة المالية</button>
              </div>
            </div>
          </div>

          <!-- Comparison Toggle: High Contrast SaaS Pattern -->
          <div class="shrink-0 pb-1">
            <label class="flex items-center gap-3 px-5 h-11 bg-slate-900 text-white rounded-xl cursor-pointer hover:bg-black transition-all shadow-lg shadow-slate-200 border border-white/5 group">
              <div class="flex flex-col text-right">
                <span class="text-[10px] font-bold uppercase tracking-widest text-blue-400">وضع المقارنة</span>
                <span class="text-[8px] font-medium text-white/40">بالفترة السابقة المقابلة</span>
              </div>
              <div class="relative inline-flex items-center">
                <input type="checkbox" v-model="compareEnabled" class="sr-only peer" />
                <div 
                  @click="compareEnabled = !compareEnabled"
                  :class="compareEnabled ? 'bg-blue-500' : 'bg-white/10'"
                  class="w-9 h-5 rounded-full cursor-pointer transition-colors duration-200 relative overflow-hidden"
                >
                  <span
                    :class="compareEnabled ? 'left-0.5' : 'right-0.5'"
                    class="absolute top-0.5 w-3.5 h-3.5 bg-white rounded-full shadow transition-all duration-200"
                  ></span>
                </div>
              </div>
            </label>
          </div>
        </div>
      </section>

      <section
        v-if="unresolvedReturnCostLineCount > 0 || (compareEnabled && previousUnresolvedReturnCostLineCount > 0)"
        class="rounded-xl border border-amber-300 bg-amber-50 px-5 py-4 text-sm text-amber-900"
        role="alert"
      >
        <p class="font-bold">تكلفة بعض مرتجعات المبيعات غير محسومة</p>
        <p v-if="unresolvedReturnCostLineCount > 0" class="mt-1">
          الفترة الحالية: {{ unresolvedReturnCostLineCount }} بند مرتجع غير محسوم
          <span v-if="unresolvedReturnCostQuantity > 0"> (الكمية {{ unresolvedReturnCostQuantity }})</span>.
        </p>
        <p v-if="compareEnabled && previousUnresolvedReturnCostLineCount > 0" class="mt-1">
          الفترة السابقة: {{ previousUnresolvedReturnCostLineCount }} بند مرتجع غير محسوم
          <span v-if="previousUnresolvedReturnCostQuantity > 0"> (الكمية {{ previousUnresolvedReturnCostQuantity }})</span>.
        </p>
        <p class="mt-1">
          استُبعدت هذه التكلفة من تكلفة المرتجعات، لذلك قد لا تعكس الأرباح المقارنة القيمة النهائية.
          راجع تكلفة حركة المرتجع أو تكلفة بند البيع الأصلي.
        </p>
      </section>

      <!-- Summary Performance KPIs: Metric Blocks -->
      <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
        <div v-for="card in summaryCards" :key="card.title" class="bg-white border border-slate-200 p-6 rounded-xl flex items-center justify-between group hover:border-slate-300 transition-all shadow-sm border-r-4" :class="[card.value >= 0 || card.title.includes('المصروفات') ? 'border-r-slate-200' : 'border-r-rose-500']">
          <div class="space-y-3">
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest leading-none">{{ card.title }}</p>
            <p class="text-xl font-bold font-mono tracking-tighter leading-none" :class="[card.title === 'إجمالي المصروفات' ? 'text-slate-900' : (card.value >= 0 ? 'text-slate-900' : 'text-rose-600')]">
              {{ card.format === 'percentage' ? formatPercentage(card.value) : formatCurrency(card.value) }}
            </p>
          </div>
          <div class="flex flex-col items-end gap-3">
            <div :class="[card.iconClass, 'w-10 h-10 rounded-lg flex items-center justify-center text-sm opacity-80 group-hover:opacity-100 transition-all shadow-inner border border-transparent group-hover:border-current/10']">
              <i :class="card.icon"></i>
            </div>
            <div v-if="compareEnabled" :class="[card.change >= 0 ? 'text-emerald-600 bg-emerald-50 border-emerald-100' : 'text-rose-600 bg-rose-50 border-rose-100']" class="px-2 py-0.5 rounded text-[9px] font-bold border flex items-center gap-1 font-mono">
              <i class="fas" :class="card.change >= 0 ? 'fa-caret-up' : 'fa-caret-down'"></i>
              {{ formatPercentage(Math.abs(card.change)) }}
            </div>
          </div>
        </div>
      </section>

      <!-- Analytics Visualizer -->
      <section class="grid grid-cols-1 lg:grid-cols-12 gap-8">
        
        <!-- Trend Chart (8/12) -->
        <div class="lg:col-span-8 bg-white border border-slate-200 rounded-xl p-6 shadow-sm flex flex-col relative overflow-hidden group">
          <div class="absolute top-0 right-0 w-32 h-32 bg-blue-500/5 rounded-full -translate-x-8 -translate-y-8 group-hover:scale-110 transition-transform duration-1000"></div>
          
          <div class="flex items-center justify-between mb-8 relative z-10">
             <h2 class="text-sm font-bold text-slate-900 uppercase tracking-widest flex items-center gap-2">
               <i class="fas fa-chart-line text-blue-500"></i> تحليل الأداء المالي المقارن
             </h2>
             <span class="text-[9px] font-bold text-slate-400 bg-slate-50 px-2 py-1 rounded border border-slate-100 uppercase tracking-tighter">Comparative Analysis</span>
          </div>

          <div class="flex-grow relative z-10 h-80">
            <div v-if="isLoading" class="absolute inset-0 flex items-center justify-center bg-white/80 backdrop-blur-sm z-20">
              <div class="flex items-center gap-1">
                <div class="w-2 h-2 rounded-full bg-slate-400 animate-pulse"></div>
                <div class="w-2 h-2 rounded-full bg-slate-400 animate-pulse" style="animation-delay: 0.2s"></div>
                <div class="w-2 h-2 rounded-full bg-slate-400 animate-pulse" style="animation-delay: 0.4s"></div>
              </div>
            </div>
            <LineChart v-if="!isLoading" :data="lineChartData" :options="lineChartOptions" />
          </div>
        </div>

        <!-- Expense Composition (4/12) -->
        <div class="lg:col-span-4 bg-white border border-slate-200 rounded-xl p-6 shadow-sm flex flex-col relative overflow-hidden group">
          <div class="flex flex-col items-center justify-center mb-8 px-1">
              <h2 class="text-xs font-bold text-slate-900 uppercase tracking-widest self-start flex items-center gap-2">
                <i class="fas fa-chart-pie text-indigo-500"></i> هيكل المصروفات التشغيلية
              </h2>
          </div>
          <div class="flex-grow relative h-72">
            <DoughnutChart v-if="!isLoading" :data="doughnutChartData" :options="doughnutChartOptions" />
          </div>
        </div>
      </section>

      <!-- Detailed P&L Statement: Professional Financial View -->
      <div class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm relative min-h-[500px]">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
           <h3 class="text-xs font-bold text-slate-900 uppercase tracking-widest flex items-center gap-2">
             <i class="fas fa-file-invoice-dollar text-slate-400"></i>
             قائمة الدخل الشامل (Comprehensive Income)
           </h3>
           <div class="flex items-center gap-2">
              <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
              <span class="text-[9px] font-bold text-slate-400 uppercase tracking-tighter">IFRS COMPLIANT REPORT</span>
           </div>
        </div>

        <div class="overflow-x-auto">
          <table class="w-full text-right border-collapse">
            <!-- 1. Revenue Section -->
            <thead class="bg-emerald-50/20 border-y border-emerald-100">
              <tr>
                <th colspan="3" class="px-8 py-3 text-[11px] font-bold text-emerald-800 uppercase tracking-widest">
                  <i class="fas fa-arrow-down-long ml-2 text-emerald-500"></i> الإيرادات التشغيلية (Operating Revenue)
                </th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 font-medium text-xs">
              <tr v-for="item in currentPeriodData.revenue" :key="item.name" class="hover:bg-slate-50 transition-colors group">
                <td class="px-8 py-4 text-slate-700 font-bold">{{ item.name }}</td>
                <td class="px-6 py-4 text-left font-mono font-bold text-slate-900 text-sm tracking-tighter">{{ formatCurrency(item.amount) }}</td>
                <td class="px-8 py-4 text-left w-56">
                  <div v-if="compareEnabled" class="flex items-center justify-end gap-3 animate-fadeIn">
                    <span class="text-[9px] text-slate-400 uppercase font-bold tracking-tighter">تغير:</span>
                    <span :class="calculateChange(item.amount, (previousPeriodData.revenue.find(p => p.name === item.name) || {amount:0}).amount) >= 0 ? 'text-emerald-600 bg-emerald-50 border-emerald-100' : 'text-rose-600 bg-rose-50 border-rose-100'" class="px-2 py-0.5 rounded text-[10px] font-bold border font-mono">
                      {{ formatPercentage(calculateChange(item.amount, (previousPeriodData.revenue.find(p => p.name === item.name) || {amount:0}).amount)) }}
                    </span>
                  </div>
                </td>
              </tr>
            </tbody>
            <tfoot class="bg-slate-50 border-y border-slate-100 font-bold text-xs text-slate-900">
              <tr>
                <td class="px-8 py-4">إجمالي الإيرادات (Total Revenue)</td>
                <td class="px-6 py-4 text-left font-mono text-lg text-emerald-600 tracking-tighter">{{ formatCurrency(currentSummary.totalRevenue) }}</td>
                <td class="px-8 py-4 text-left">
                  <span v-if="compareEnabled" :class="calculateChange(currentSummary.totalRevenue, previousSummary.totalRevenue) >= 0 ? 'text-emerald-600' : 'text-rose-600'" class="font-mono text-[11px] font-bold">
                    {{ formatPercentage(calculateChange(currentSummary.totalRevenue, previousSummary.totalRevenue)) }}
                  </span>
                </td>
              </tr>
            </tfoot>

            <!-- 2. COGS Section -->
            <thead class="bg-amber-50/20 border-y border-amber-100">
              <tr>
                <th colspan="3" class="px-8 py-3 text-[11px] font-bold text-amber-800 uppercase tracking-widest">
                  <i class="fas fa-boxes-stacked ml-2 text-amber-500"></i> تكلفة البضاعة المباعة (COGS)
                </th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 font-medium text-xs">
              <tr class="hover:bg-slate-50 transition-colors">
                <td class="px-8 py-4 text-slate-700">تكلفة المبيعات المباشرة</td>
                <td class="px-6 py-4 text-left font-mono font-bold text-amber-700 text-sm tracking-tighter">{{ formatCurrency(currentSummary.totalRevenue - currentSummary.grossProfit) }}</td>
                <td class="px-8 py-4 text-left">
                  <span v-if="compareEnabled" class="text-amber-500 font-mono text-[11px] font-bold">
                    {{ formatPercentage(calculateChange(currentSummary.totalRevenue - currentSummary.grossProfit, previousSummary.totalRevenue - previousSummary.grossProfit)) }}
                  </span>
                </td>
              </tr>
            </tbody>
            <tfoot class="bg-slate-50 border-y border-slate-100 font-bold text-xs text-slate-900 shadow-inner">
              <tr>
                <td class="px-8 py-4 uppercase tracking-tighter">إجمالي الربح التشغيلي (Gross Profit)</td>
                <td class="px-6 py-4 text-left font-mono text-lg tracking-tighter text-slate-800">{{ formatCurrency(currentSummary.grossProfit) }}</td>
                <td class="px-8 py-4 text-left">
                  <span v-if="compareEnabled" :class="calculateChange(currentSummary.grossProfit, previousSummary.grossProfit) >= 0 ? 'text-emerald-600' : 'text-rose-600'" class="font-mono text-[11px] font-bold">
                    {{ formatPercentage(calculateChange(currentSummary.grossProfit, previousSummary.grossProfit)) }}
                  </span>
                </td>
              </tr>
            </tfoot>

            <!-- 3. Expenses Section -->
            <thead class="bg-rose-50/20 border-y border-rose-100">
              <tr>
                <th colspan="3" class="px-8 py-3 text-[11px] font-bold text-rose-800 uppercase tracking-widest">
                  <i class="fas fa-arrow-up-long ml-2 text-rose-400"></i> المصروفات التشغيلية (Operating Expenses)
                </th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 font-medium text-xs">
              <tr v-for="item in currentPeriodData.expenses" :key="item.name" class="hover:bg-slate-50 transition-colors group">
                <td class="px-8 py-4 text-slate-600">{{ item.name }}</td>
                <td class="px-6 py-4 text-left font-mono font-bold text-slate-900 text-sm tracking-tighter">{{ formatCurrency(item.amount) }}</td>
                <td class="px-8 py-4 text-left">
                  <span v-if="compareEnabled" class="text-rose-400 font-mono text-[10px] font-bold">
                    {{ formatPercentage(calculateChange(item.amount, (previousPeriodData.expenses.find(p => p.name === item.name) || {amount:0}).amount)) }}
                  </span>
                </td>
              </tr>
            </tbody>
            <tfoot class="bg-slate-50 border-y border-slate-100 font-bold text-xs text-slate-900">
              <tr>
                <td class="px-8 py-4">إجمالي المصروفات (Total Expenses)</td>
                <td class="px-6 py-4 text-left font-mono text-lg text-rose-600 tracking-tighter">{{ formatCurrency(currentSummary.totalExpenses) }}</td>
                <td class="px-8 py-4 text-left">
                  <span v-if="compareEnabled" class="text-rose-500 font-mono text-[11px] font-bold">
                    {{ formatPercentage(calculateChange(currentSummary.totalExpenses, previousSummary.totalExpenses)) }}
                  </span>
                </td>
              </tr>
            </tfoot>

            <!-- 4. Final Bottom Line -->
            <tfoot class="bg-slate-900 text-white font-black">
              <tr>
                <td class="px-8 py-8 text-base uppercase tracking-widest flex items-center gap-3">
                  <i class="fas fa-award text-blue-400"></i> صافي الربح النهائي (Net Profit)
                </td>
                <td class="px-6 py-8 text-left font-mono text-3xl tracking-tighter" :class="currentSummary.netProfit >= 0 ? 'text-blue-400' : 'text-rose-400'">{{ formatCurrency(currentSummary.netProfit) }}</td>
                <td class="px-8 py-8 text-left">
                   <div v-if="compareEnabled" class="flex flex-col items-end gap-1 animate-fadeIn">
                      <span :class="calculateChange(currentSummary.netProfit, previousSummary.netProfit) >= 0 ? 'text-emerald-400' : 'text-rose-400'" class="text-sm font-mono font-black tracking-tighter">
                        {{ formatPercentage(calculateChange(currentSummary.netProfit, previousSummary.netProfit)) }}
                      </span>
                      <span class="text-[8px] text-white/30 uppercase tracking-[0.2em] leading-none">Net Change</span>
                   </div>
                </td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>
      </main>
    </div>
  </div>
</template>

<script setup>
// [SCRIPT SECTION] منطق الحسابات والـ API محفوظ كاملاً
// تحديثات: إزالة console.log، تعديل نص اسم الإيراد، استبدال Cairo بـ sans-serif، إصلاح Legend
import { ref, computed, onMounted, watch } from 'vue';
import { useReportsStore } from '@/stores/reports/reportsStore';
import { Line as LineChart, Doughnut as DoughnutChart } from 'vue-chartjs';
import { Chart as ChartJS, Title, Tooltip, Legend, LineElement, CategoryScale, LinearScale, PointElement, ArcElement, Filler } from 'chart.js';
import { useCompanyCurrency } from '@/composables/useCompanyCurrency';
import { getLocalDateISO } from '@/utils';
import { useBreadcrumb } from '@/composables/useBreadcrumb';
import { useBranchStore } from '@/stores/branch/branchStore';
import PageHeader from '@/components/PageHeader.vue';

ChartJS.register(Title, Tooltip, Legend, LineElement, CategoryScale, LinearScale, PointElement, ArcElement, Filler);

const reportsStore = useReportsStore();
const branchStore = useBranchStore();
const branches = computed(() => branchStore.branches);
const selectedBranch = computed({
  get: () => branchStore.selectedBranchId || 'all',
  set: (val) => branchStore.setSelectedBranch(val === 'all' ? null : val)
});
const { breadcrumb } = useBreadcrumb();
const { formatCurrencyLocale, fetchSettings } = useCompanyCurrency();
const isLoading = ref(true);
const error = ref(null);

const today = new Date();
const firstDayOfMonth = new Date(today.getFullYear(), today.getMonth(), 1);
const startDate = ref(getLocalDateISO(firstDayOfMonth));
const endDate = ref(getLocalDateISO(today));
const startDateRef = ref(null);
const endDateRef = ref(null);
const compareEnabled = ref(false);
let debounceTimer = null;

const currentPeriodData = ref({ revenue: [], expenses: [] });
const previousPeriodData = ref({ revenue: [], expenses: [] });

const createSummary = (data) => {
  const totalRevenue = Number(data.meta?.totalRevenue ?? data.revenue.reduce((sum, item) => sum + (item.amount || 0), 0));
  const grossProfit = Number(data.meta?.grossProfit ?? 0);
  const cogs = Number(data.meta?.cogs ?? 0);
  const totalExpenses = Number(data.meta?.totalExpenses ?? data.expenses.reduce((sum, item) => sum + (item.amount || 0), 0));
  const netProfit = Number(data.meta?.netProfit ?? (grossProfit - totalExpenses));
  const grossProfitMargin = totalRevenue > 0 ? (grossProfit / totalRevenue) * 100 : 0;
  const netProfitMargin = totalRevenue > 0 ? (netProfit / totalRevenue) * 100 : 0;
  return { totalRevenue, totalExpenses, grossProfit, cogs, netProfit, grossProfitMargin, netProfitMargin };
};

const currentSummary = computed(() => createSummary(currentPeriodData.value));
const previousSummary = computed(() => createSummary(previousPeriodData.value));
const unresolvedReturnCostLineCount = computed(
  () => Number(currentPeriodData.value.meta?.returnsCostUnresolvedLineCount ?? 0)
);
const unresolvedReturnCostQuantity = computed(
  () => Number(currentPeriodData.value.meta?.returnsCostUnresolvedQuantity ?? 0)
);
const previousUnresolvedReturnCostLineCount = computed(
  () => Number(previousPeriodData.value.meta?.returnsCostUnresolvedLineCount ?? 0)
);
const previousUnresolvedReturnCostQuantity = computed(
  () => Number(previousPeriodData.value.meta?.returnsCostUnresolvedQuantity ?? 0)
);
const calculateChange = (current, previous) => { if (previous === 0) return current > 0 ? 100 : 0; return ((current - previous) / previous) * 100; };

const summaryCards = computed(() => {
    const { totalRevenue, totalExpenses, netProfit, netProfitMargin } = currentSummary.value;
    const prev = previousSummary.value;
    return [
        { title: 'إجمالي الإيرادات', value: totalRevenue, change: calculateChange(totalRevenue, prev.totalRevenue), format: 'currency', icon: 'fas fa-chart-line', iconClass: 'bg-emerald-50 text-emerald-600' },
        { title: 'إجمالي المصروفات', value: totalExpenses, change: calculateChange(totalExpenses, prev.totalExpenses), format: 'currency', icon: 'fas fa-arrow-trend-down', iconClass: 'bg-rose-50 text-rose-600' },
        { title: 'صافي الربح', value: netProfit, change: calculateChange(netProfit, prev.netProfit), format: 'currency', icon: 'fas fa-wallet', iconClass: 'bg-blue-50 text-blue-600' },
        { title: 'هامش صافي الربح', value: netProfitMargin, change: netProfitMargin - prev.netProfitMargin, format: 'percentage', icon: 'fas fa-percent', iconClass: 'bg-indigo-50 text-indigo-600' }
    ];
});

const lineChartData = computed(() => ({
    labels: ['الفترة السابقة', 'الفترة الحالية'],
    datasets: [
        { label: 'الإيرادات', data: [previousSummary.value.totalRevenue, currentSummary.value.totalRevenue], borderColor: '#10B981', backgroundColor: 'rgba(16, 185, 129, 0.1)', fill: true, tension: 0.4 },
        { label: 'المصروفات', data: [previousSummary.value.totalExpenses, currentSummary.value.totalExpenses], borderColor: '#EF4444', backgroundColor: 'rgba(239, 68, 68, 0.1)', fill: true, tension: 0.4 },
        { label: 'صافي الربح', data: [previousSummary.value.netProfit, currentSummary.value.netProfit], borderColor: '#3B82F6', borderWidth: 3, tension: 0.4, pointRadius: 5 }
    ]
}));

const doughnutChartData = computed(() => {
    const labels = currentPeriodData.value.expenses.map(e => e.name);
    const data = currentPeriodData.value.expenses.map(e => e.amount);
    return { labels, datasets: [{ data, backgroundColor: ['#3b82f6','#10b981','#6366f1','#f59e0b','#f43f5e','#8b5cf6'], borderWidth: 0 }] };
});

const lineChartOptions = { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: true, position: 'bottom' } }, scales: { y: { grid: { color: '#f1f5f9', drawBorder: false }, ticks: { font: { family: 'sans-serif', size: 10, weight: 'bold' }, color: '#94a3b8' } }, x: { grid: { display: false }, ticks: { font: { family: 'sans-serif', size: 10, weight: 'bold' }, color: '#94a3b8' } } } };
const doughnutChartOptions = { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { font: { family: 'sans-serif', size: 10, weight: 'bold' }, usePointStyle: true, padding: 20 } } }, cutout: '70%' };

const formatCurrency = (v) => formatCurrencyLocale(v, 0);
const formatPercentage = (v) => `${(Number(v) || 0).toFixed(1)}%`;

const mapApiToPeriodData = (apiData) => {
    let data = apiData;
    if (data && data.data) data = data.data;
    if (data && data.data) data = data.data;
    const expensesRaw = data?.account_transactions || data?.expenses || [];
    const expenses = expensesRaw.map(e => ({ name: e.expense_category || e.name || 'مصروفات أخرى', amount: Number(e.total ?? e.amount ?? 0) }));
    const totalRevenue = Number(data?.revenue?.total_revenue ?? data?.summary?.total_revenue ?? 0);
    const grossProfit = Number(data?.revenue?.gross_profit ?? data?.summary?.gross_profit ?? 0);
    const cogs = Number(data?.revenue?.cogs ?? data?.summary?.cogs ?? 0);
    const totalExpenses = Number(data?.summary?.total_expenses ?? 0);
    const netProfit = Number(data?.summary?.net_profit ?? 0);
    const returnsCostUnresolvedLineCount = Number(
        data?.revenue?.returns_cost_unresolved_line_count
        ?? data?.summary?.returns_cost_unresolved_line_count
        ?? 0
    );
    const returnsCostUnresolvedQuantity = Number(
        data?.revenue?.returns_cost_unresolved_quantity
        ?? data?.summary?.returns_cost_unresolved_quantity
        ?? 0
    );
    const revenue = [{ name: 'إجمالي الإيرادات التشغيلية', amount: totalRevenue }];
    return {
        revenue,
        expenses,
        meta: {
            totalRevenue,
            grossProfit,
            cogs,
            totalExpenses,
            netProfit,
            returnsCostUnresolvedLineCount,
            returnsCostUnresolvedQuantity,
        },
    };
};

const fetchReport = async () => {
    isLoading.value = true; error.value = null;
    try {
        const branchId = selectedBranch.value === 'all' ? null : selectedBranch.value;
        const current = await reportsStore.fetchProfitLossReport(startDate.value, endDate.value, 'cogs', 'vouchers', branchId);
        currentPeriodData.value = mapApiToPeriodData(current);
        if (compareEnabled.value) {
            const start = new Date(startDate.value); const end = new Date(endDate.value);
            const diffDays = Math.ceil((end - start) / (1000 * 60 * 60 * 24)) + 1;
            const prevEnd = new Date(start); prevEnd.setDate(start.getDate() - 1);
            const prevStart = new Date(start); prevStart.setDate(start.getDate() - diffDays);
            const previous = await reportsStore.fetchProfitLossReport(getLocalDateISO(prevStart), getLocalDateISO(prevEnd), 'cogs', 'vouchers', branchId);
            previousPeriodData.value = mapApiToPeriodData(previous);
        } else { previousPeriodData.value = { revenue: [], expenses: [], meta: {} }; }
    } catch (err) { error.value = 'فشل في تحميل التقرير.'; } finally { isLoading.value = false; }
};

const setDateRange = (range) => {
    const end = new Date(); let start = new Date();
    if (range === 'month') start = new Date(end.getFullYear(), end.getMonth(), 1);
    else if (range === 'quarter') start.setMonth(end.getMonth() - 3);
    else if (range === 'year') start = new Date(end.getFullYear(), 0, 1);
    endDate.value = getLocalDateISO(end); startDate.value = getLocalDateISO(start);
};

watch([startDate, endDate, compareEnabled, selectedBranch], () => { clearTimeout(debounceTimer); debounceTimer = setTimeout(fetchReport, 700); });
onMounted(async () => {
  await Promise.all([fetchSettings(), branchStore.fetchBranches().catch(() => {}), fetchReport()]);
});
</script>

<style scoped>
@keyframes loading { 0% { transform: translateX(100%); } 100% { transform: translateX(-100%); } }

.filter-input-v2 {
  @apply h-9 w-full bg-white border border-slate-200 rounded-md px-3 text-[11px] font-bold text-slate-700 focus:ring-4 focus:ring-blue-500/5 focus:border-blue-500 outline-none transition-all;
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