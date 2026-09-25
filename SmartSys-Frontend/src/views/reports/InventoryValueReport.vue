<template>
  <div class="min-h-screen bg-[#fafafa] text-slate-900 font-sans antialiased selection:bg-blue-100" dir="rtl">
    
    <!-- Top Progress Bar: High-precision indicator -->
    <div v-if="isLoading" class="fixed top-0 left-0 right-0 h-0.5 bg-blue-600/10 z-[110]">
      <div class="h-full bg-blue-600 animate-[loading_2s_ease-in-out_infinite] w-1/3"></div>
    </div>

    <div class="max-w-[1600px] mx-auto p-6 lg:p-10 space-y-8">
      
      <!-- Page Header -->
      <PageHeader
        :breadcrumb="{ parent: { label: 'التقارير', path: '/reports' }, current: { label: 'تحليل قيمة المخزون', path: '/reports/inventory-value' } }"
        title="تحليل قيمة المخزون"
        description="تقييم القيمة الرأسمالية للأصول المخزنية"
        :branches="[]"
        :selectedBranch="null"
      >
        <template #controls>
          <div class="flex items-center gap-2">
            <!-- Valuation Switcher -->
            <div class="flex items-center gap-2 px-3 py-1 bg-slate-100 rounded-md border border-slate-200">
              <span class="text-[9px] font-bold text-slate-400 uppercase tracking-widest">أساس التقييم:</span>
              <select v-model="valuation" class="bg-transparent border-none text-[11px] font-bold text-slate-900 focus:ring-0 cursor-pointer outline-none">
                <option value="sale">سعر البيع</option>
                <option value="cost">سعر التكلفة</option>
              </select>
            </div>
          </div>
        </template>
      </PageHeader>

      <main class="space-y-8">

      <!-- KPI Summary Section -->
      <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
        <div v-for="card in summaryCards" :key="card.title" class="bg-white border border-slate-200 p-5 rounded-xl flex items-center justify-between group hover:border-slate-300 transition-all shadow-sm border-r-4" :class="card.color.replace('text', 'border-r')">
          <div>
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">{{ card.title }}</p>
            <p :class="[card.color, 'text-xl font-bold font-mono tracking-tighter leading-none']">
              {{ card.format === 'currency' ? formatCurrency(card.value) : formatNumber(card.value) }}
            </p>
          </div>
          <div :class="[card.bg, card.color]" class="w-10 h-10 rounded-lg flex items-center justify-center text-sm opacity-80 group-hover:opacity-100 transition-opacity shadow-inner">
            <i :class="card.icon"></i>
          </div>
        </div>
      </section>

      <!-- Visual Analytics Row -->
      <section class="grid grid-cols-1 lg:grid-cols-12 gap-8">
        
        <!-- Category Distribution Chart (8/12) -->
        <div class="lg:col-span-8 bg-white border border-slate-200 rounded-xl p-6 shadow-sm flex flex-col relative overflow-hidden group">
          <div class="absolute top-0 left-0 w-40 h-40 bg-blue-500/5 rounded-full -translate-x-12 -translate-y-12 group-hover:scale-110 transition-transform duration-1000"></div>
          
          <div class="flex items-center justify-between mb-8 relative z-10">
             <h2 class="text-sm font-bold text-slate-900 uppercase tracking-widest flex items-center gap-2">
               <i class="fas fa-chart-column text-blue-500"></i> القيمة المالية حسب التصنيف (أعلى 5)
             </h2>
             <span class="text-[9px] font-bold text-slate-400 bg-slate-50 px-2 py-1 rounded border border-slate-100">تحليل الفئات</span>
          </div>

          <div class="flex-grow relative z-10 h-80">
            <div v-if="isLoading" class="absolute inset-0 flex items-center justify-center bg-white/80 backdrop-blur-sm z-20">
              <div class="flex items-center gap-1">
                <div class="w-2 h-2 rounded-full bg-slate-400 animate-pulse"></div>
                <div class="w-2 h-2 rounded-full bg-slate-400 animate-pulse" style="animation-delay: 0.2s"></div>
                <div class="w-2 h-2 rounded-full bg-slate-400 animate-pulse" style="animation-delay: 0.4s"></div>
              </div>
            </div>
            <BarChart v-if="inventoryItems.length" :data="valueByCategoryChart" :options="chartOptions" />
            <div v-else class="h-full flex flex-col items-center justify-center text-slate-300 gap-3">
               <i class="fas fa-chart-bar text-2xl opacity-20"></i>
               <p class="text-[10px] font-bold uppercase tracking-widest">بانتظار البيانات...</p>
            </div>
          </div>
        </div>

        <!-- System Intelligence (4/12) -->
        <div class="lg:col-span-4 bg-slate-900 rounded-xl p-8 text-white shadow-xl flex flex-col justify-center relative overflow-hidden border border-white/5">
          <div class="absolute bottom-0 right-0 w-32 h-32 bg-white/5 rounded-full translate-x-12 translate-y-12"></div>
          <div class="relative z-10 space-y-6">
            <p class="text-[10px] font-bold text-blue-400 uppercase tracking-[0.3em] mb-4 text-center">معايرة التقرير</p>
            <div class="space-y-4">
              <div class="flex justify-between items-center py-2 border-b border-white/5">
                <span class="text-[10px] font-medium text-white/50 uppercase tracking-widest">أساس الحساب</span>
                <span class="text-xs font-bold">{{ valuation === 'cost' ? 'سعر التكلفة' : 'سعر البيع' }}</span>
              </div>
              <div class="flex justify-between items-center py-2 border-b border-white/5">
                <span class="text-[10px] font-medium text-white/50 uppercase tracking-widest">العملة النشطة</span>
                <span class="text-xs font-bold">{{ currencySymbol }}</span>
              </div>
              <div class="flex justify-between items-center py-2 border-b border-white/5">
                <span class="text-[10px] font-medium text-white/50 uppercase tracking-widest">الدقة الحسابية</span>
                <span class="text-xs font-bold">2 Decimal Places</span>
              </div>
            </div>
            <p class="text-[10px] text-slate-500 leading-relaxed italic text-center pt-4">يعتمد هذا التقرير على الأرصدة الحالية المستخرجة من مستودعات النظام.</p>
          </div>
        </div>
      </section>
      
      <!-- Detailed Data Table Card -->
      <section class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm relative min-h-[500px]">
        <!-- Table Filter Bar -->
        <div class="p-4 bg-slate-50/50 border-b border-slate-100 flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div class="relative group w-full md:w-96">
              <input v-model="searchQuery" type="text" class="h-9 w-full bg-white border border-slate-200 rounded-md pl-4 text-[11px] font-bold text-slate-700 focus:ring-4 focus:ring-blue-500/5 focus:border-blue-500 outline-none transition-all" style="padding-right: 2rem;" placeholder="بحث بالاسم أو الباركود..." />
              <i class="fas fa-search absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px] pointer-events-none"></i>
            </div>

            <div class="flex items-center gap-3">
              <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">التصنيف:</label>
              <select v-model="selectedCategory" class="h-9 w-48 bg-white border border-slate-200 rounded-md px-3 text-[11px] font-bold text-slate-700 outline-none focus:border-blue-500">
                  <option value="all">كل التصنيفات</option>
                  <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
              </select>
            </div>
        </div>

        <div class="overflow-x-auto">
          <table class="w-full text-right border-collapse">
            <thead>
              <tr class="bg-slate-50 border-b border-slate-200 text-slate-400 font-bold uppercase text-[10px] tracking-widest">
                <th @click="handleSort('name')" class="px-6 py-4 cursor-pointer hover:text-blue-600 transition-colors group">
                  الصنف والترميز <i :class="sortKey === 'name' ? (sortOrder === 'asc' ? 'fa-sort-up' : 'fa-sort-down') : 'fa-sort'" class="fas ml-1 opacity-20 group-hover:opacity-100"></i>
                </th>
                <th @click="handleSort('quantity')" class="px-4 py-4 text-center cursor-pointer hover:text-blue-600 transition-colors group">
                  الكمية <i :class="sortKey === 'quantity' ? (sortOrder === 'asc' ? 'fa-sort-up' : 'fa-sort-down') : 'fa-sort'" class="fas ml-1 opacity-20 group-hover:opacity-100"></i>
                </th>
                <th class="px-4 py-4 text-center cursor-pointer hover:text-blue-600 transition-colors group" @click="handleSort('costPrice')">
                  التكلفة <i :class="sortKey === 'costPrice' ? (sortOrder === 'asc' ? 'fa-sort-up' : 'fa-sort-down') : 'fa-sort'" class="fas ml-1 opacity-20 group-hover:opacity-100"></i>
                </th>
                <th class="px-4 py-4 text-center cursor-pointer hover:text-blue-600 transition-colors group" @click="handleSort('salePrice')">
                  سعر البيع <i :class="sortKey === 'salePrice' ? (sortOrder === 'asc' ? 'fa-sort-up' : 'fa-sort-down') : 'fa-sort'" class="fas ml-1 opacity-20 group-hover:opacity-100"></i>
                </th>
                <th @click="handleSort('totalValue')" class="px-6 py-4 text-left cursor-pointer hover:text-blue-600 transition-colors group">
                  إجمالي القيمة <i :class="sortKey === 'totalValue' ? (sortOrder === 'asc' ? 'fa-sort-up' : 'fa-sort-down') : 'fa-sort'" class="fas ml-1 opacity-20 group-hover:opacity-100"></i>
                </th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 font-medium text-xs">
              <template v-if="isLoading && !inventoryItems.length">
                <tr v-for="n in 6" :key="n" class="animate-pulse">
                  <td v-for="m in 5" :key="m" class="px-6 py-4"><div class="h-3 bg-slate-100 rounded w-full"></div></td>
                </tr>
              </template>
              <tr v-else-if="!sortedItems.length">
                <td colspan="5" class="py-24 text-center text-slate-300">
                   <i class="fas fa-box-open text-3xl mb-4 opacity-20"></i>
                   <p class="text-xs font-bold uppercase tracking-widest">لا توجد سجلات مطابقة</p>
                </td>
              </tr>
              <tr v-for="item in sortedItems" :key="item.id" class="hover:bg-blue-50/20 transition-all group border-r-4 border-r-transparent" :class="getRowClass(item)">
                <td class="px-6 py-4">
                  <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-lg bg-slate-50 flex items-center justify-center text-slate-400 group-hover:text-blue-600 transition-colors border border-slate-100 group-hover:border-blue-100">
                      <i class="fas fa-barcode text-xs"></i>
                    </div>
                    <div class="min-w-0">
                      <p class="text-xs font-bold text-slate-900 truncate">{{ item.name }}</p>
                      <p class="text-[9px] font-mono text-slate-400 uppercase tracking-tighter mt-1">{{ item.barcode || 'NO BARCODE' }}</p>
                    </div>
                  </div>
                </td>
                <td class="px-4 py-4 text-center">
                  <span :class="item.quantity === 0 ? 'text-rose-600' : (item.quantity <= item.lowStockThreshold ? 'text-amber-600' : 'text-slate-900')" class="text-sm font-bold font-mono tracking-tighter">
                    {{ formatNumber(item.quantity) }}
                  </span>
                </td>
                <td class="px-4 py-4 text-center font-mono text-slate-400">{{ formatCurrency(item.costPrice) }}</td>
                <td class="px-4 py-4 text-center font-mono text-slate-400">{{ formatCurrency(item.salePrice) }}</td>
                <td class="px-6 py-4 text-left">
                  <span class="text-sm font-bold font-mono tracking-tighter text-blue-600">{{ formatCurrency(item.totalValue) }}</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Pagination Simulation / Footer Info -->
        <div class="px-6 py-4 bg-slate-50/50 border-t border-slate-200 flex items-center justify-between">
          <div class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">
            إجمالي الأصناف: <span class="text-slate-900 font-mono">{{ sortedItems.length }}</span>
          </div>
          <p class="text-[10px] font-bold text-slate-300 uppercase tracking-tighter italic">تقرير تدقيق مالي معتمد من محرك الجرد</p>
        </div>
      </section>
      </main>
    </div>
  </div>
</template>

<script setup>
// [بقاء المنطق البرمجي كما هو بنسبة 100%]
import { ref, computed, onMounted, watch } from 'vue';
import { useReportsStore } from '../../stores/reports';
import { Bar as BarChart } from 'vue-chartjs';
import { Chart as ChartJS, Title, Tooltip, Legend, BarElement, CategoryScale, LinearScale } from 'chart.js';

import BaseSkeleton from '@/components/ui/BaseSkeleton.vue';
import { useCompanyCurrency } from '@/composables/useCompanyCurrency';
import PageHeader from '@/components/PageHeader.vue';
import { useBreadcrumb } from '@/composables/useBreadcrumb';

ChartJS.register(Title, Tooltip, Legend, BarElement, CategoryScale, LinearScale);

const reportsStore = useReportsStore();
const { formatCurrencyLocale, fetchSettings, currencySymbol } = useCompanyCurrency();
const isLoading = ref(true);
const error = ref(null);

const inventoryItems = ref([]);
const categories = ref([]);
const totals = ref({ total_value: 0, total_products: 0, low_stock_count: 0, out_of_stock_count: 0 });
const valuation = ref('sale');

const searchQuery = ref('');
const selectedCategory = ref('all');
const sortKey = ref('totalValue');
const sortOrder = ref('desc');

const filteredItems = computed(() => {
    let items = [...inventoryItems.value];
    if (selectedCategory.value !== 'all') items = items.filter(item => item.categoryId == selectedCategory.value);
    if (searchQuery.value) {
        const q = searchQuery.value.toLowerCase();
        items = items.filter(item => item.name.toLowerCase().includes(q) || (item.barcode && item.barcode.toLowerCase().includes(q)));
    }
    return items;
});

const sortedItems = computed(() => {
    return [...filteredItems.value].sort((a, b) => {
        let valA = a[sortKey.value], valB = b[sortKey.value];
        if (valA < valB) return sortOrder.value === 'asc' ? -1 : 1;
        if (valA > valB) return sortOrder.value === 'asc' ? 1 : -1;
        return 0;
    });
});

const summaryCards = computed(() => {
    const tv = Number(totals.value.total_value ?? 0);
    const tp = Number(totals.value.total_products ?? inventoryItems.value.length);
    const fallbackTotal = inventoryItems.value.reduce((sum, item) => sum + (item.totalValue || 0), 0);
    const fallbackLow = inventoryItems.value.filter(item => item.quantity <= item.lowStockThreshold && item.quantity > 0).length;
    const fallbackOut = inventoryItems.value.filter(item => item.quantity === 0).length;
    const ls = Number(totals.value.low_stock_count ?? fallbackLow);
    const os = Number(totals.value.out_of_stock_count ?? fallbackOut);
    
    return [
        { title: 'قيمة المخزون الإجمالية', value: tv || fallbackTotal, format: 'currency', icon: 'fas fa-money-bill-trend-up', color: 'text-blue-600', bg: 'bg-blue-50' },
        { title: 'إجمالي عدد المنتجات', value: tp, format: 'number', icon: 'fas fa-boxes-stacked', color: 'text-emerald-600', bg: 'bg-emerald-50' },
        { title: 'منتجات منخفضة المخزون', value: ls, format: 'number', icon: 'fas fa-triangle-exclamation', color: 'text-amber-600', bg: 'bg-amber-50' },
        { title: 'أصناف نفدت كميتها', value: os, format: 'number', icon: 'fas fa-circle-exclamation', color: 'text-rose-600', bg: 'bg-rose-50' }
    ];
});

const valueByCategoryChart = computed(() => {
    const categoryValues = categories.value.map(cat => {
        const value = inventoryItems.value.filter(item => item.categoryId === cat.id).reduce((sum, item) => sum + item.totalValue, 0);
        return { name: cat.name, value };
    }).sort((a, b) => b.value - a.value).slice(0, 5);

    return { labels: categoryValues.map(c => c.name), datasets: [{ label: 'قيمة المخزون', data: categoryValues.map(c => c.value), backgroundColor: '#3b82f6', borderRadius: 4, maxBarThickness: 32 }] };
});

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
      ticks: { 
        font: { family: 'Cairo', size: 10, weight: 'bold' }, 
        color: '#94a3b8',
        callback: (v) => new Intl.NumberFormat('en-US').format(v)
      } 
    }, 
    x: { grid: { display: false }, ticks: { font: { family: 'Cairo', size: 10, weight: 'bold' }, color: '#94a3b8' } } 
  } 
};

const formatCurrency = (v) => formatCurrencyLocale(v, 2);
const formatNumber = (v) => (Number(v) || 0).toLocaleString('en-US');
const getRowClass = (item) => {
    if (item.quantity === 0) return 'bg-rose-50/20 border-r-rose-500';
    if (item.quantity <= item.lowStockThreshold) return 'bg-amber-50/20 border-r-amber-500';
    return '';
};

const fetchInventoryValue = async () => {
    isLoading.value = true; error.value = null;
    try {
        const resp = await reportsStore.fetchInventoryValue({ search: searchQuery.value || undefined, category_id: selectedCategory.value !== 'all' ? selectedCategory.value : undefined, valuation: valuation.value });
        const apiData = resp?.data?.data ?? resp?.data ?? resp;
        totals.value = apiData?.totals || totals.value;
        if (Array.isArray(apiData)) inventoryItems.value = apiData;
        else { inventoryItems.value = apiData.items || apiData.inventory || []; categories.value = apiData.categories || categories.value || []; }
    } catch { error.value = "فشل التحميل"; } finally { isLoading.value = false; }
};

const handleSort = (k) => { if (sortKey.value === k) sortOrder.value = sortOrder.value === 'asc' ? 'desc' : 'asc'; else { sortKey.value = k; sortOrder.value = 'asc'; } };

const { breadcrumb } = useBreadcrumb();

onMounted(async () => { await Promise.all([fetchSettings(), fetchInventoryValue()]); });
watch([searchQuery, selectedCategory, valuation], () => fetchInventoryValue());
</script>

<style scoped>
@keyframes loading { 0% { transform: translateX(100%); } 100% { transform: translateX(-100%); } }

.filter-input-v2 {
  @apply h-9 w-full bg-white border border-slate-200 rounded-md px-3 text-[11px] font-bold text-slate-700 focus:ring-4 focus:ring-blue-500/5 focus:border-blue-500 outline-none transition-all;
}

.custom-scroll::-webkit-scrollbar { width: 5px; height: 5px; }
.custom-scroll::-webkit-scrollbar-thumb { @apply bg-slate-200 rounded-full; }

.animate-fadeIn { animation: fadeIn 0.4s ease-out; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
</style>