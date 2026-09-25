<template>
  <div class="min-h-screen bg-[#fafafa] text-slate-900 font-sans antialiased selection:bg-blue-100" dir="rtl">
    
    <!-- Top Progress Bar: High-precision indicator -->
    <div v-if="isLoading" class="fixed top-0 left-0 right-0 h-0.5 bg-blue-600/10 z-[110]">
      <div class="h-full bg-blue-600 animate-[loading_2s_ease-in-out_infinite] w-1/3"></div>
    </div>

    <!-- Page Header Area -->
    <header class="sticky top-0 z-40 bg-white/80 backdrop-blur-md border-b border-slate-200 px-6 py-3">
      <div class="max-w-[1800px] mx-auto flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div class="flex items-center gap-4">
          <div class="w-10 h-10 bg-slate-900 rounded-lg flex items-center justify-center text-white shadow-sm shrink-0">
            <i class="fas fa-chart-pie text-sm"></i>
          </div>
          <div>
            <h1 class="text-sm font-bold text-slate-900 leading-none">ملخص المبيعات اليومي</h1>
            <p class="text-[10px] text-slate-500 font-medium uppercase tracking-widest mt-1">متابعة الربحية وحركة السيولة اللحظية</p>
          </div>
        </div>

        <div class="flex items-center gap-2">
          <button @click="viewYesterday" class="h-9 px-4 rounded-md border border-slate-200 bg-white text-slate-600 text-xs font-bold hover:bg-slate-50 transition-all">عرض يوم أمس</button>
          <button @click="fetchSalesSummary" :disabled="isLoading" class="h-9 w-9 flex items-center justify-center rounded-md border border-slate-200 bg-white text-slate-400 hover:text-blue-600 transition-all shadow-sm">
            <i class="fas fa-sync-alt text-xs" :class="{'animate-spin': isLoading}"></i>
          </button>
        </div>
      </div>
    </header>

    <main class="max-w-[1800px] mx-auto p-4 lg:p-8 space-y-8">

      <!-- Analytical Filters Utility Bar -->
      <section class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-visible">
        <div class="p-6 space-y-6">
          <!-- Filter Top Row -->
          <div class="flex items-center justify-between border-b border-slate-50 pb-4">
            <div class="flex items-center gap-3">
              <i class="fas fa-filter text-slate-400 text-xs"></i>
              <span class="text-xs font-bold text-slate-700 uppercase tracking-wider">تخصيص فلاتر التقرير</span>
            </div>
            <button v-if="hasActiveFilters" @click="clearFilters" class="text-[10px] font-bold text-rose-600 hover:underline uppercase tracking-tighter">مسح كافة الفلاتر</button>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-12 gap-4 items-end">
            <!-- Date Selector -->
            <div class="lg:col-span-2 space-y-1.5 group">
              <label class="metadata-label">تاريخ التقرير</label>
              <div class="relative">
                <input ref="selectedDateRef" type="date" v-model="selectedDate" class="filter-input-v2 font-mono" />
                <i class="fas fa-calendar absolute left-3 top-1/2 -translate-y-1/2 text-slate-300 group-hover:text-blue-500 transition-colors pointer-events-none text-[10px]"></i>
              </div>
            </div>

            <!-- POS Multi-selector: Refined SaaS Style -->
            <div class="lg:col-span-3 space-y-1.5 relative" v-click-outside="() => posDropdownOpen = false">
              <label class="metadata-label flex justify-between">
                <span>نقاط البيع المستهدفة</span>
                <span v-if="posIds.length" class="text-blue-600 font-bold font-mono">{{ posIds.length }}</span>
              </label>
              <div 
                @click="posDropdownOpen = !posDropdownOpen"
                class="h-9 px-3 bg-white border border-slate-200 rounded-md flex items-center justify-between cursor-pointer hover:border-blue-400 transition-all shadow-sm"
                :class="{'border-blue-500 ring-4 ring-blue-500/5': posDropdownOpen}"
              >
                <div class="flex items-center gap-2 overflow-hidden">
                  <i class="fas fa-store text-slate-300 text-[10px]"></i>
                  <span class="text-[11px] font-bold text-slate-700 truncate">
                    {{ posIds.length === 0 ? 'اختر نقاط البيع...' : (posIds.length === posOptions.length ? 'جميع نقاط البيع' : `${posIds.length} نقاط محددة`) }}
                  </span>
                </div>
                <i class="fas fa-chevron-down text-slate-300 text-[8px] transition-transform" :class="{'rotate-180': posDropdownOpen}"></i>
              </div>
              
              <!-- Dropdown Panel -->
              <transition name="dropdown">
                <div v-if="posDropdownOpen" class="absolute top-full right-0 mt-1 w-full bg-white border border-slate-200 rounded-lg shadow-2xl z-[60] overflow-hidden animate-fadeIn">
                  <div class="p-2 border-b border-slate-50 bg-slate-50/50">
                    <input v-model="posSearch" @click.stop placeholder="بحث في الفروع..." class="w-full h-8 bg-white border border-slate-200 rounded px-3 text-[10px] font-bold outline-none focus:border-blue-500" />
                  </div>
                  <div class="max-h-48 overflow-y-auto custom-scroll">
                    <div class="px-4 py-2 hover:bg-slate-50 transition-colors cursor-pointer border-b border-slate-50" @click="toggleAllPos">
                      <label class="flex items-center gap-3 cursor-pointer">
                        <input type="checkbox" :checked="posIds.length === posOptions.length && posOptions.length > 0" class="w-3.5 h-3.5 rounded border-slate-300 text-blue-600 focus:ring-0" @click.stop="toggleAllPos" />
                        <span class="text-[11px] font-bold text-blue-600 uppercase tracking-widest">تحديد الكل</span>
                      </label>
                    </div>
                    <div v-for="opt in filteredPosOptions" :key="opt.id" @click="togglePos(opt.id)" class="px-4 py-2.5 hover:bg-blue-50/50 transition-colors cursor-pointer flex items-center gap-3">
                      <input type="checkbox" :value="opt.id" v-model="posIds" class="w-3.5 h-3.5 rounded border-slate-300 text-blue-600 focus:ring-0" @click.stop />
                      <span class="text-[11px] font-medium text-slate-700">{{ opt.label }}</span>
                    </div>
                  </div>
                </div>
              </transition>
            </div>

            <!-- Additional Filters -->
            <div class="lg:col-span-2 space-y-1.5"><label class="metadata-label">طريقة الدفع</label><select v-model="paymentKind" class="filter-input-v2 appearance-none h-9 font-bold"><option value="">كل الطرق</option><option value="cash">نقدي</option><option value="card">بطاقة</option><option value="wallet">محفظة</option><option value="bank">تحويل</option><option value="credit">آجل</option></select></div>
            <div class="lg:col-span-2 space-y-1.5"><label class="metadata-label">معرف التصنيف</label><input v-model="categoryId" type="number" class="filter-input-v2 h-9 font-mono" placeholder="ID" /></div>
            <div class="lg:col-span-2 space-y-1.5"><label class="metadata-label">معرف المنتج</label><input v-model="productId" type="number" class="filter-input-v2 h-9 font-mono" placeholder="ID" /></div>

            <!-- Execute Button -->
            <div class="lg:col-span-1">
              <button @click="fetchSalesSummary" :disabled="isLoading" class="h-9 w-full bg-slate-900 text-white rounded-md flex items-center justify-center hover:bg-black transition-all active:scale-95 shadow-sm">
                <i class="fas fa-search text-[10px]"></i>
              </button>
            </div>
          </div>
        </div>
      </section>

      <!-- KPI Grid -->
      <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-4">
        <div v-for="card in summaryCards" :key="card.title" class="bg-white border border-slate-200 p-5 rounded-xl flex flex-col justify-between group hover:border-slate-300 transition-all shadow-sm border-r-4" :class="card.color.replace('text', 'border-r')">
          <div class="flex justify-between items-start mb-3">
            <span class="text-[9px] font-bold text-slate-400 uppercase tracking-widest">{{ card.title }}</span>
            <div :class="[card.bg, card.color]" class="w-7 h-7 rounded-lg flex items-center justify-center text-xs opacity-70">
              <i :class="card.icon"></i>
            </div>
          </div>
          <p :class="[card.color, 'text-xl font-bold font-mono tracking-tighter leading-none']">
            {{ card.format === 'currency' ? formatCurrency(card.value) : (card.format === 'number' ? formatNumber(card.value) : card.value) }}
          </p>
        </div>
      </section>

      <!-- Visual Insights Grid -->
      <section class="grid grid-cols-1 lg:grid-cols-12 gap-8">
        
        <!-- POS Performance Chart (8/12) -->
        <div class="lg:col-span-8 bg-white border border-slate-200 rounded-xl p-6 shadow-sm flex flex-col relative overflow-hidden group">
          <div class="absolute top-0 left-0 w-64 h-64 bg-blue-500/5 rounded-full -translate-x-12 -translate-y-12 group-hover:scale-110 transition-transform duration-1000"></div>
          
          <div class="flex items-center justify-between mb-8 relative z-10">
             <h2 class="text-sm font-bold text-slate-900 uppercase tracking-widest flex items-center gap-2">
               <span class="w-1 h-5 bg-blue-600 rounded-full"></span>
               توزيع المبيعات حسب الفروع (POS)
             </h2>
             <span class="text-[9px] font-bold text-slate-400 bg-slate-50 px-2 py-1 rounded border border-slate-100 uppercase tracking-tighter">Volume Comparison</span>
          </div>

          <div class="flex-grow relative z-10 h-[300px]">
            <div v-if="isLoading" class="absolute inset-0 flex items-center justify-center bg-white/80 backdrop-blur-sm z-20">
              <div class="flex items-center gap-1">
                <div class="w-2 h-2 rounded-full bg-slate-400 animate-pulse"></div>
                <div class="w-2 h-2 rounded-full bg-slate-400 animate-pulse" style="animation-delay: 0.2s"></div>
                <div class="w-2 h-2 rounded-full bg-slate-400 animate-pulse" style="animation-delay: 0.4s"></div>
              </div>
            </div>
            <BarChart v-if="!isLoading && salesSummary.salesByPos.length" :data="salesByPosChart" :options="chartOptions" />
            <div v-else class="h-full flex flex-col items-center justify-center text-slate-300 italic text-[10px]">بانتظار البيانات...</div>
          </div>
        </div>

        <!-- Payment Doughnut (4/12) -->
        <div class="lg:col-span-4 bg-white border border-slate-200 rounded-xl p-6 shadow-sm flex flex-col items-center">
             <h3 class="text-xs font-bold text-slate-900 uppercase tracking-widest self-start flex items-center gap-2 mb-10">
               <i class="fas fa-chart-pie text-indigo-500"></i> قنوات التحصيل المالي
             </h3>
             <div class="flex-grow w-full relative h-64">
               <DoughnutChart v-if="!isLoading && salesSummary.salesByPayment.length" :data="salesByPaymentChart" :options="doughnutOptions" />
             </div>
             <div class="w-full mt-6 grid grid-cols-2 gap-y-2 pt-6 border-t border-slate-50">
                <div v-for="p in salesSummary.salesByPayment" :key="p.name" class="flex items-center gap-2">
                   <span class="w-1.5 h-1.5 rounded-full bg-slate-200"></span>
                   <span class="text-[9px] font-bold text-slate-400 uppercase truncate">{{ p.name }}</span>
                </div>
             </div>
        </div>
      </section>

      <!-- Recent Transactions Ledger -->
      <section class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm relative">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
           <h3 class="text-xs font-bold text-slate-900 uppercase tracking-widest flex items-center gap-2">
             <i class="fas fa-list-ul text-slate-400"></i> سجل العمليات المباشر
           </h3>
           <div class="flex items-center gap-4">
              <span class="flex items-center gap-1.5 text-[9px] font-bold text-blue-600"><span class="w-1.5 h-1.5 rounded-full bg-blue-500 animate-pulse"></span> LIVE FEED</span>
              <span class="text-[9px] font-bold text-slate-400 uppercase tracking-tighter">Total: {{ salesSummary.recentTransactions.length }}</span>
           </div>
        </div>

        <!-- Integrated Cost Warning -->
        <transition name="slide-down">
          <div v-if="shouldShowCostWarning" class="p-3 bg-amber-50 border-b border-amber-100 flex items-center justify-center gap-3">
             <i class="fas fa-triangle-exclamation text-amber-500 text-xs"></i>
             <p class="text-[10px] font-bold text-amber-800 uppercase tracking-tight">تحذير: لم يتم اكتشاف تكلفة (COGS) لهذا اليوم — يرجى مراجعة الأرصدة الافتتاحية للمنتجات.</p>
             <button @click="hideCostWarning = true" class="text-amber-400 hover:text-amber-600"><i class="fas fa-times text-[10px]"></i></button>
          </div>
        </transition>

        <div class="overflow-x-auto max-h-[400px] custom-scroll">
          <table class="w-full text-right border-collapse">
            <thead>
              <tr class="bg-slate-50/50 border-b border-slate-100 text-slate-400 font-bold uppercase text-[10px] tracking-widest">
                <th class="px-6 py-4">وقت الحركة</th>
                <th class="px-4 py-4 text-center">نقطة البيع</th>
                <th class="px-4 py-4">قيمة الفاتورة</th>
                <th class="px-4 py-4">هامش الربح</th>
                <th class="px-6 py-4 text-center">الوسيلة</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 font-medium text-xs">
              <template v-if="isLoading">
                <tr v-for="n in 5" :key="n" class="animate-pulse">
                  <td v-for="m in 5" :key="m" class="px-6 py-4"><div class="h-3 bg-slate-100 rounded w-full"></div></td>
                </tr>
              </template>
              <tr v-else-if="!salesSummary.recentTransactions.length">
                <td colspan="5" class="py-20 text-center text-slate-300">
                   <i class="fas fa-receipt text-3xl mb-4 opacity-20"></i>
                   <p class="text-xs font-bold uppercase tracking-widest">لا توجد عمليات مسجلة لليوم</p>
                </td>
              </tr>
              <tr v-for="tx in salesSummary.recentTransactions" :key="tx.id" class="hover:bg-slate-50 transition-colors group">
                <td class="px-6 py-4 text-[10px] font-mono font-bold text-slate-400 group-hover:text-slate-900">{{ formatTime(tx.time) }}</td>
                <td class="px-4 py-4 text-center text-slate-700 font-bold">{{ tx.posName }}</td>
                <td class="px-4 py-4 font-mono font-bold text-slate-900">{{ formatCurrency(tx.amount) }}</td>
                <td class="px-4 py-4">
                  <span :class="tx.profit >= 0 ? 'text-emerald-600' : 'text-rose-600'" class="font-mono font-bold">
                    {{ tx.profit >= 0 ? '+' : '' }}{{ formatCurrency(tx.profit) }}
                  </span>
                </td>
                <td class="px-6 py-4 text-center">
                   <span :class="[getPaymentMethodStyle(tx.paymentMethod)]" class="px-2 py-0.5 rounded text-[9px] font-bold border">
                     {{ tx.paymentMethod }}
                   </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </main>

  </div>
</template>

<script setup>
// [بقاء المنطق البرمجي كما هو بنسبة 100%]
import { ref, computed, onMounted, watch } from 'vue';
import { Bar as BarChart, Doughnut as DoughnutChart } from 'vue-chartjs';
import { Chart as ChartJS, Title, Tooltip, Legend, BarElement, CategoryScale, LinearScale, ArcElement } from 'chart.js';

import BaseSkeleton from '@/components/ui/BaseSkeleton.vue';
import { useReportsStore } from '@/stores/reports';
import { getLocalDateISO } from '@/utils/date';
import { useCompanyCurrency } from '@/composables/useCompanyCurrency';
import { useBreadcrumb } from '@/composables/useBreadcrumb';
import PageHeader from '@/components/PageHeader.vue';

ChartJS.register(Title, Tooltip, Legend, BarElement, CategoryScale, LinearScale, ArcElement);

// Click outside directive (Preserved logic)
const vClickOutside = {
  mounted(el, binding) {
    el._clickOutside = (event) => {
      if (!(el === event.target || el.contains(event.target))) { binding.value(event); }
    };
    document.addEventListener('click', el._clickOutside);
  },
  unmounted(el) { document.removeEventListener('click', el._clickOutside); }
};

const reportsStore = useReportsStore();
const { breadcrumb } = useBreadcrumb();
const { formatCurrencyLocale, fetchSettings } = useCompanyCurrency();
const isLoading = ref(true);
const error = ref(null);
const selectedDate = ref(getLocalDateISO());
const selectedDateRef = ref(null);

const salesSummary = ref({
  kpis: { totalSales: 0, totalReturns: 0, netSales: 0, orderCount: 0, cogsTotal: 0, grossProfit: 0 },
  salesByPos: [],
  salesByPayment: [],
  recentTransactions: []
});

const summaryCards = computed(() => {
  const k = salesSummary.value.kpis || {}
  return [
    { title: 'إجمالي المبيعات', value: k.totalSales, format: 'currency', icon: 'fas fa-shopping-cart', color: 'text-emerald-600', bg: 'bg-emerald-50' },
    { title: 'المرتجعات', value: k.totalReturns, format: 'currency', icon: 'fas fa-undo', color: 'text-rose-600', bg: 'bg-rose-50' },
    { title: 'صافي المبيعات', value: k.netSales, format: 'currency', icon: 'fas fa-sack-dollar', color: 'text-blue-600', bg: 'bg-blue-50' },
    { title: 'عدد الفواتير', value: k.orderCount || 0, format: 'number', icon: 'fas fa-receipt', color: 'text-indigo-600', bg: 'bg-indigo-50' },
    { title: 'إجمالي التكلفة', value: k.cogsTotal, format: 'currency', icon: 'fas fa-box', color: 'text-amber-600', bg: 'bg-amber-50' },
    { title: 'الربح الإجمالي', value: k.grossProfit, format: 'currency', icon: 'fas fa-chart-line', color: 'text-emerald-700', bg: 'bg-emerald-100' },
  ]
})

const salesByPosChart = computed(() => ({
  labels: salesSummary.value.salesByPos.map(p => p.name),
  datasets: [{ label: 'المبيعات', data: salesSummary.value.salesByPos.map(p => p.totalSales), backgroundColor: '#3b82f6', borderRadius: 4, maxBarThickness: 32 }]
}));

const salesByPaymentChart = computed(() => ({
  labels: salesSummary.value.salesByPayment.map(p => p.name),
  datasets: [{ data: salesSummary.value.salesByPayment.map(p => p.totalSales), backgroundColor: ['#10b981', '#3b82f6', '#f59e0b', '#ef4444', '#6366f1', '#8b5cf6'], borderWidth: 0 }]
}));

const chartOptions = { 
  responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, 
  scales: { 
    y: { grid: { color: '#f1f5f9', drawBorder: false }, ticks: { font: { family: 'Cairo', size: 10, weight: 'bold' }, color: '#94a3b8' } }, 
    x: { grid: { display: false }, ticks: { font: { family: 'Cairo', size: 10, weight: 'bold' }, color: '#94a3b8' } } 
  } 
};
const doughnutOptions = { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, cutout: '75%' };

const formatCurrency = (v) => formatCurrencyLocale(v, 0);
const formatNumber = (v) => (Number(v) || 0).toLocaleString('en-US');
const formatTime = (ts) => ts ? new Date(ts).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' }) : '';

const getPaymentMethodStyle = (m) => {
    const s = String(m || '').toLowerCase();
    if (s.includes('cash') || s.includes('نقد')) return 'text-emerald-600 border-emerald-100 bg-emerald-50';
    if (s.includes('card') || s.includes('بطاقة')) return 'text-blue-600 border-blue-100 bg-blue-50';
    return 'text-slate-400 border-slate-100 bg-slate-50';
};

const paymentKind = ref(''); 
const categoryId = ref(''); 
const productId = ref(''); 
const posIds = ref([]); 
const posOptions = ref([]); 
const posSearch = ref('');
const posDropdownOpen = ref(false);

const filteredPosOptions = computed(() => { 
  const q = (posSearch.value || '').toLowerCase(); 
  return posOptions.value.filter(o => o.label.toLowerCase().includes(q)); 
});

const hasActiveFilters = computed(() => paymentKind.value !== '' || categoryId.value !== '' || productId.value !== '' || posIds.value.length > 0);

const clearFilters = () => { paymentKind.value = ''; categoryId.value = ''; productId.value = ''; posIds.value = []; posSearch.value = ''; fetchSalesSummary(); };
const toggleAllPos = () => { if (posIds.value.length === posOptions.value.length) { posIds.value = []; } else { posIds.value = posOptions.value.map(p => p.id); } };
const togglePos = (id) => { const index = posIds.value.indexOf(id); if (index > -1) { posIds.value.splice(index, 1); } else { posIds.value.push(id); } };

const loadPosOptions = async () => {
  try {
    const result = await reportsStore.fetchPosAnalytics(selectedDate.value, selectedDate.value);
    const data = result.status === 'success' ? result.data : [];
    posOptions.value = (Array.isArray(data) ? data : (data?.data || [])).map(r => ({ id: r.pos_id, label: r.pos_name || `POS #${r.pos_id}` }));
  } catch { posOptions.value = []; }
}

const fetchSalesSummary = async () => {
    isLoading.value = true; error.value = null;
    try {
        const result = await reportsStore.fetchSalesSummary(selectedDate.value);
        if (result.status === 'success') {
            const apiData = result.data || {};
            const totalSales = Number(apiData.total_sales_amount ?? 0);
            const totalReturns = Number(apiData.total_returns_amount ?? 0);
            const cogsTotal = Number(apiData.cogs_total ?? 0);
            const orderCount = Number(apiData.order_count ?? 0);
            const netSales = totalSales - totalReturns;
            const grossProfit = netSales - cogsTotal;
            salesSummary.value = {
                kpis: { totalSales, totalReturns, netSales, orderCount, cogsTotal, grossProfit },
                salesByPos: (apiData.salesByPos || []).map(p => ({ name: p.name || `POS #${p.pos_id}`, totalSales: Number(p.totalSales || 0) })),
                salesByPayment: (apiData.salesByPayment || []).map(p => ({ name: p.name || '-', totalSales: Number(p.totalSales || 0) })),
                recentTransactions: (apiData.recentTransactions || []).map(t => ({ id: t.id, time: t.time || t.created_at, posName: t.posName || '-', amount: Number(t.amount || 0), cogs: Number(t.cogs || 0), profit: Number(t.profit || 0), paymentMethod: t.paymentMethod || '-' }))
            };
        } else { error.value = result.message || "فشل التحميل"; }
    } catch (e) { error.value = "فشل التحميل"; } finally { isLoading.value = false; }
};

const viewYesterday = () => { const y = new Date(selectedDate.value); y.setDate(y.getDate() - 1); selectedDate.value = getLocalDateISO(y); };
const hideCostWarning = ref(false);
const shouldShowCostWarning = computed(() => !hideCostWarning.value && Number(salesSummary.value.kpis.totalSales) > 0 && Number(salesSummary.value.kpis.cogsTotal) === 0);

watch(selectedDate, () => { loadPosOptions(); fetchSalesSummary(); });
onMounted(async () => { await Promise.all([fetchSettings(), loadPosOptions(), fetchSalesSummary()]); });
</script>

<style scoped>
@keyframes loading { 0% { transform: translateX(100%); } 100% { transform: translateX(-100%); } }

.filter-input-v2 {
  @apply h-9 w-full bg-white border border-slate-200 rounded-md px-3 text-[11px] font-bold text-slate-700 focus:ring-4 focus:ring-blue-500/5 focus:border-blue-500 outline-none transition-all;
}

.metadata-label { @apply block text-[10px] font-bold text-slate-400 uppercase tracking-widest px-1 mb-1; }

.custom-scroll::-webkit-scrollbar { width: 5px; height: 5px; }
.custom-scroll::-webkit-scrollbar-thumb { @apply bg-slate-200 rounded-full; }

.animate-fadeIn { animation: fadeIn 0.4s ease-out; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

/* Dropdown Animation */
.dropdown-enter-active { animation: dropdownIn 0.2s ease-out; }
@keyframes dropdownIn { from { opacity: 0; transform: translateY(-8px); } to { opacity: 1; transform: translateY(0); } }
</style>