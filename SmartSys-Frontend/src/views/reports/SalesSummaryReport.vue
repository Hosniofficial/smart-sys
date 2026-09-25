<template>
  <div class="min-h-screen bg-[#fafafa] text-slate-900 font-sans antialiased selection:bg-blue-100" dir="rtl">
    
    <!-- Top Progress Bar -->
    <div v-if="isLoading" class="fixed top-0 left-0 right-0 h-0.5 bg-blue-600/10 z-[110]">
      <div class="h-full bg-blue-600 animate-[loading_2s_ease-in-out_infinite] w-1/3"></div>
    </div>

    <div class="max-w-[1600px] mx-auto p-6 lg:p-10 space-y-8">
      
      <!-- Page Header -->
      <PageHeader
        :breadcrumb="breadcrumb"
        title="أداء المبيعات والربحية"
        description="تحليل تدفقات النقدية، تكلفة البضاعة، وصافي أرباح نقاط البيع."
        :branches="[]"
        :selectedBranch="null"
      >
        <template #controls>
          <button @click="viewYesterday" class="h-9 px-4 rounded-md border border-slate-200 bg-white text-slate-600 text-xs font-bold hover:bg-slate-50 transition-all shadow-sm">عرض يوم أمس</button>
          <button @click="fetchSalesSummary" :disabled="isLoading" class="h-9 w-9 flex items-center justify-center rounded-md border border-slate-200 bg-white text-slate-400 hover:text-blue-600 transition-all shadow-sm">
            <i class="fas fa-sync-alt text-[10px]" :class="{'animate-spin': isLoading}"></i>
          </button>
        </template>
      </PageHeader>

      <!-- Analytical Filters Panel: High-Density Utility Bar -->
      <section class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-visible">
        <div class="p-6 space-y-6">
          <div class="flex items-center justify-between px-1">
            <h3 class="text-[10px] font-bold text-slate-400 uppercase tracking-widest flex items-center gap-2">
              <i class="fas fa-filter text-blue-500"></i> معايير التصفية والتحليل
            </h3>
            <button v-if="hasActiveFilters" @click="clearFilters" class="text-[10px] font-bold text-rose-500 hover:text-rose-700 uppercase tracking-tighter transition-colors">
              <i class="fas fa-times-circle ml-1"></i> مسح الفلاتر
            </button>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-12 gap-4 items-end">
            <!-- Date Selector -->
            <div class="lg:col-span-2 space-y-1.5 group">
              <label class="metadata-label">تاريخ التقرير</label>
              <div class="relative">
                <input ref="selectedDateRef" type="date" v-model="selectedDate" class="filter-input-v2 font-mono" style="padding-left: 2rem;" />
                <i @click="selectedDateRef?.showPicker?.()" class="fas fa-calendar absolute left-3 top-1/2 -translate-y-1/2 text-slate-300 group-hover:text-blue-500 transition-colors cursor-pointer hover:text-slate-500 text-[10px]"></i>
              </div>
            </div>

            <!-- POS Multi-selector -->
            <div class="lg:col-span-3 space-y-1.5 relative" v-click-outside="() => posDropdownOpen = false">
              <label class="metadata-label">نقاط البيع (POS)</label>
              <div 
                @click="posDropdownOpen = !posDropdownOpen"
                class="filter-input-v2 h-9 flex items-center justify-between cursor-pointer group"
                :class="{'ring-2 ring-blue-500/10 border-blue-500': posDropdownOpen}"
              >
                <span class="truncate pr-1" :class="posIds.length ? 'text-slate-900' : 'text-slate-400'">
                  {{ posIds.length === 0 ? 'كل النقاط' : (posIds.length === posOptions.length ? 'الكل' : `${posIds.length} محددة`) }}
                </span>
                <i class="fas fa-chevron-down text-[8px] text-slate-300 transition-transform" :class="{'rotate-180': posDropdownOpen}"></i>
              </div>
              
              <transition name="dropdown">
                <div v-if="posDropdownOpen" class="absolute top-full right-0 left-0 mt-1 bg-white border border-slate-200 rounded-lg shadow-2xl z-[60] overflow-hidden">
                  <div class="p-2 border-b border-slate-50 bg-slate-50/50">
                    <input v-model="posSearch" type="text" class="w-full h-8 border border-slate-200 rounded px-2 text-[10px] font-bold focus:ring-0 outline-none" placeholder="بحث..." @click.stop />
                  </div>
                  <div class="max-h-48 overflow-y-auto custom-scroll py-1">
                    <div class="px-3 py-1.5 flex items-center gap-2 hover:bg-slate-50 cursor-pointer" @click="toggleAllPos">
                      <input type="checkbox" :checked="posIds.length === posOptions.length && posOptions.length > 0" class="w-3.5 h-3.5 rounded text-blue-600 border-slate-300 focus:ring-0" @click.stop="toggleAllPos" />
                      <span class="text-[11px] font-bold text-slate-700">تحديد الكل</span>
                    </div>
                    <div v-for="opt in filteredPosOptions" :key="opt.id" class="px-3 py-1.5 flex items-center gap-2 hover:bg-blue-50 cursor-pointer transition-colors" @click="togglePos(opt.id)">
                      <input type="checkbox" :value="opt.id" v-model="posIds" class="w-3.5 h-3.5 rounded text-blue-600 border-slate-300 focus:ring-0" @click.stop />
                      <span class="text-[11px] font-medium text-slate-600 truncate">{{ opt.label }}</span>
                    </div>
                  </div>
                </div>
              </transition>
            </div>

            <!-- Payment Kind -->
            <div class="lg:col-span-2 space-y-1.5">
              <label class="metadata-label">نوع الدفع</label>
              <select v-model="paymentKind" class="filter-input-v2 appearance-none font-bold">
                <option value="">كل الطرق</option>
                <option value="cash">نقدي</option>
                <option value="card">بطاقة</option>
                <option value="wallet">محفظة</option>
                <option value="bank">تحويل</option>
                <option value="credit">آجل</option>
              </select>
            </div>

            <!-- ID Filters -->
            <div class="lg:col-span-2 space-y-1.5">
              <label class="metadata-label">رقم التصنيف</label>
              <input v-model="categoryId" type="number" class="filter-input-v2 font-mono h-9" placeholder="—" />
            </div>

            <div class="lg:col-span-2 space-y-1.5">
              <label class="metadata-label">رقم المنتج</label>
              <input v-model="productId" type="number" class="filter-input-v2 font-mono h-9" placeholder="—" />
            </div>

            <div class="lg:col-span-1">
              <LoadingButton
                text="بحث"
                :isLoading="isLoading"
                loadingText="جاري..."
                icon="fas fa-search"
                variant="primary"
                loaderType="dots"
                @click="fetchSalesSummary"
                class="h-9 w-full"
              />
            </div>
          </div>
        </div>
      </section>

      <!-- KPI Grid: Metric Blocks -->
      <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-4">
        <div v-for="card in summaryCards" :key="card.title" class="bg-white border border-slate-200 p-5 rounded-xl flex flex-col justify-between group hover:border-slate-300 transition-all shadow-sm border-r-4" :class="card.color.replace('text', 'border-r')">
          <div class="flex justify-between items-start mb-3">
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest leading-none">{{ card.title }}</p>
            <div :class="[card.bg, card.color]" class="w-7 h-7 rounded-lg flex items-center justify-center text-[10px] opacity-80 group-hover:opacity-100 transition-opacity shadow-inner">
              <i :class="card.icon"></i>
            </div>
          </div>
          <p :class="[card.color, 'text-xl font-bold font-mono tracking-tighter leading-none']">
            {{ card.format === 'currency' ? formatCurrency(card.value) : (card.format === 'number' ? formatNumber(card.value) : card.value) }}
          </p>
        </div>
      </section>

      <!-- Analytics Visualization Grid -->
      <section class="grid grid-cols-1 lg:grid-cols-12 gap-8">
        
        <!-- POS Sales (4/12) -->
        <div class="lg:col-span-4 bg-white border border-slate-200 rounded-xl p-6 shadow-sm flex flex-col relative overflow-hidden group">
          <div class="absolute top-0 left-0 w-32 h-32 bg-blue-500/5 rounded-full -translate-x-12 -translate-y-12 group-hover:scale-110 transition-transform duration-1000"></div>
          <h3 class="text-xs font-bold text-slate-900 uppercase tracking-widest flex items-center gap-2 mb-8 relative z-10">
            <i class="fas fa-store text-blue-500"></i> مبيعات نقاط البيع
          </h3>
          <div class="flex-grow h-64 relative z-10">
             <BarChart v-if="!isLoading && salesSummary.salesByPos.length" :data="salesByPosChart" :options="chartOptions" />
             <div v-else class="h-full flex items-center justify-center opacity-30 italic text-[10px]">لا توجد بيانات نقاط بيع</div>
          </div>
        </div>

        <!-- Payment Distribution (3/12) -->
        <div class="lg:col-span-3 bg-white border border-slate-200 rounded-xl p-6 shadow-sm flex flex-col items-center group">
          <h3 class="text-xs font-bold text-slate-900 uppercase tracking-widest self-start mb-8">توزيع طرق الدفع</h3>
          <div class="flex-grow w-full relative h-64">
            <DoughnutChart v-if="!isLoading && salesSummary.salesByPayment.length" :data="salesByPaymentChart" :options="doughnutOptions" />
          </div>
          <div class="w-full mt-6 grid grid-cols-2 gap-y-2 pt-6 border-t border-slate-50">
             <div v-for="(p, idx) in salesSummary.salesByPayment" :key="p.name" class="flex items-center gap-2">
                <span class="w-1.5 h-1.5 rounded-full" :style="{ backgroundColor: ['#10b981', '#f59e0b', '#ef4444', '#3b82f6'][idx % 4] }"></span>
                <span class="text-[9px] font-bold text-slate-400 uppercase truncate">{{ p.name }}</span>
             </div>
          </div>
        </div>

        <!-- Recent Ledger (5/12) -->
        <div class="lg:col-span-5 bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm flex flex-col">
          <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
            <h3 class="text-xs font-bold text-slate-900 uppercase tracking-widest">سجل آخر العمليات</h3>
            <span class="text-[9px] font-bold text-slate-400 font-mono">LIVE FEED</span>
          </div>

          <transition name="slide-down">
            <div v-if="shouldShowCostWarning" class="p-4 bg-amber-50 border-b border-amber-100 flex items-start gap-4">
              <i class="fas fa-exclamation-triangle text-amber-500 mt-0.5"></i>
              <div class="flex-1">
                <p class="text-[10px] font-bold text-amber-900 uppercase">تنبيه تكلفة المبيعات (COGS)</p>
                <p class="text-[9px] text-amber-700 leading-relaxed italic">يظهر إجمالي التكلفة بصفر؛ يرجى التأكد من تعريف تكلفة الشراء أو الرصيد الافتتاحي للمنتجات المباعة.</p>
              </div>
              <button @click="hideCostWarning = true" class="text-slate-300 hover:text-slate-600"><i class="fas fa-times"></i></button>
            </div>
          </transition>

          <div class="flex-1 overflow-y-auto custom-scroll max-h-[500px]">
            <table class="w-full text-right border-collapse">
              <thead>
                <tr class="bg-white border-b border-slate-100 text-slate-400 font-bold uppercase text-[9px] tracking-widest">
                  <th class="px-6 py-3">الوقت</th>
                  <th class="px-4 py-3">المبلغ</th>
                  <th class="px-4 py-3">الربح</th>
                  <th class="px-4 py-3">الوسيلة</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-50 font-medium text-xs">
                <template v-if="isLoading">
                  <tr v-for="n in 5" :key="n" class="animate-pulse">
                    <td v-for="m in 4" :key="m" class="px-6 py-4"><div class="h-3 bg-slate-100 rounded w-full"></div></td>
                  </tr>
                </template>
                <tr v-for="tx in salesSummary.recentTransactions" :key="tx.id" class="hover:bg-slate-50 transition-colors">
                  <td class="px-6 py-3 text-[10px] font-mono text-slate-400 font-bold">{{ formatTime(tx.time) }}</td>
                  <td class="px-4 py-3 font-bold text-slate-900 font-mono tracking-tighter">{{ formatCurrency(tx.amount) }}</td>
                  <td class="px-4 py-3">
                    <span :class="tx.profit >= 0 ? 'text-emerald-600' : 'text-rose-600'" class="font-bold font-mono tracking-tighter">
                      {{ tx.profit >= 0 ? '+' : '' }}{{ formatCurrency(tx.profit) }}
                    </span>
                  </td>
                  <td class="px-4 py-3">
                    <span :class="[getPaymentMethodStyle(tx.paymentMethod)]" class="px-2 py-0.5 rounded text-[9px] font-bold border uppercase">
                      {{ tx.paymentMethod }}
                    </span>
                  </td>
                </tr>
                <tr v-if="!salesSummary.recentTransactions.length && !isLoading"><td colspan="4" class="py-20 text-center text-slate-300 font-bold uppercase tracking-widest text-[10px]">لا توجد حركات لليوم</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </section>

    </div>
  </div>
</template>

<script setup>
// [SCRIPT SECTION REMAINS 100% IDENTICAL AS PER BUSINESS LOGIC RULE]
import { ref, computed, onMounted, watch } from 'vue';
import { Bar as BarChart, Doughnut as DoughnutChart } from 'vue-chartjs';
import { Chart as ChartJS, Title, Tooltip, Legend, BarElement, CategoryScale, LinearScale, ArcElement } from 'chart.js';
import LoadingButton from '@/components/ui/LoadingButton.vue';
import { useReportsStore } from '@/stores/reports';
import { getLocalDateISO } from '@/utils/date';
import { useCompanyCurrency } from '@/composables/useCompanyCurrency';
import { useBreadcrumb } from '@/composables/useBreadcrumb';
import PageHeader from '@/components/PageHeader.vue';

ChartJS.register(Title, Tooltip, Legend, BarElement, CategoryScale, LinearScale, ArcElement);

const vClickOutside = {
  mounted(el, binding) {
    el._clickOutside = (event) => {
      if (!(el === event.target || el.contains(event.target))) binding.value(event);
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
const paymentKind = ref(''); 
const categoryId = ref(''); 
const productId = ref(''); 
const posIds = ref([]); 
const posOptions = ref([]); 
const posSearch = ref('');
const posDropdownOpen = ref(false);
const hideCostWarning = ref(false);

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
    { title: 'إجمالي المرتجعات', value: k.totalReturns, format: 'currency', icon: 'fas fa-undo', color: 'text-rose-600', bg: 'bg-rose-50' },
    { title: 'صافي المبيعات', value: k.netSales, format: 'currency', icon: 'fas fa-sack-dollar', color: 'text-blue-600', bg: 'bg-blue-50' },
    { title: 'عدد الفواتير', value: k.orderCount || 0, format: 'number', icon: 'fas fa-receipt', color: 'text-indigo-600', bg: 'bg-indigo-50' },
    { title: 'تكلفة المبيعات', value: k.cogsTotal, format: 'currency', icon: 'fas fa-box', color: 'text-amber-600', bg: 'bg-amber-50' },
    { title: 'الربح الإجمالي', value: k.grossProfit, format: 'currency', icon: 'fas fa-chart-line', color: 'text-emerald-600', bg: 'bg-emerald-50' },
  ]
})

const salesByPosChart = computed(() => ({
  labels: salesSummary.value.salesByPos.map(p => p.name),
  datasets: [{ label: 'المبيعات', data: salesSummary.value.salesByPos.map(p => p.totalSales), backgroundColor: ['#3b82f6','#6366f1','#8b5cf6','#d946ef'], borderRadius: 4, maxBarThickness: 32 }]
}));

const salesByPaymentChart = computed(() => ({
  labels: salesSummary.value.salesByPayment.map(p => p.name),
  datasets: [{ data: salesSummary.value.salesByPayment.map(p => p.totalSales), backgroundColor: ['#10b981', '#f59e0b', '#ef4444', '#3b82f6'], borderWidth: 0 }]
}));

const chartOptions = { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, indexAxis: 'y', scales: { x: { grid: { color: '#f1f5f9', drawBorder: false }, ticks: { font: { family: 'Cairo', size: 10, weight: 'bold' }, color: '#94a3b8' } }, y: { grid: { display: false }, ticks: { font: { family: 'Cairo', size: 10, weight: 'bold' }, color: '#94a3b8' } } } };
const doughnutOptions = { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, cutout: '75%' };

const formatCurrency = (v) => formatCurrencyLocale(v, 2);
const formatNumber = (v) => (Number(v) || 0).toLocaleString('en-US');
const formatTime = (ts) => ts ? new Date(ts).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' }) : '';

const getPaymentMethodStyle = (m) => {
    const s = String(m || '').toLowerCase();
    if (s.includes('cash') || s.includes('نقد')) return 'text-emerald-600 border-emerald-100 bg-emerald-50';
    if (s.includes('card') || s.includes('بطاقة')) return 'text-blue-600 border-blue-100 bg-blue-50';
    return 'text-slate-500 border-slate-100 bg-slate-50';
};

const filteredPosOptions = computed(() => { 
  const q = (posSearch.value || '').toLowerCase(); 
  return posOptions.value.filter(o => o.label.toLowerCase().includes(q)); 
});

const hasActiveFilters = computed(() => paymentKind.value !== '' || categoryId.value !== '' || productId.value !== '' || posIds.value.length > 0);
const clearFilters = () => { paymentKind.value = ''; categoryId.value = ''; productId.value = ''; posIds.value = []; posSearch.value = ''; fetchSalesSummary(); };
const toggleAllPos = () => { if (posIds.value.length === posOptions.value.length) posIds.value = []; else posIds.value = posOptions.value.map(p => p.id); };
const togglePos = (id) => { const index = posIds.value.indexOf(id); if (index > -1) posIds.value.splice(index, 1); else posIds.value.push(id); };

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

/* Slide Down Animation */
.slide-down-enter-active { animation: slideDown 0.3s ease-out; }
.slide-down-leave-active { animation: slideUp 0.3s ease-in; }
@keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
@keyframes slideUp { from { opacity: 1; transform: translateY(0); } to { opacity: 0; transform: translateY(-10px); } }
</style>