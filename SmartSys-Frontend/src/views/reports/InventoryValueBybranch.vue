<template>
  <div class="min-h-screen bg-[#fafafa] text-slate-900 font-sans antialiased selection:bg-blue-100" dir="rtl">
    
    <!-- Top Progress Bar -->
    <div v-if="loading" class="fixed top-0 left-0 right-0 h-0.5 bg-blue-600/10 z-[110]">
      <div class="h-full bg-blue-600 animate-[loading_2s_ease-in-out_infinite] w-1/3"></div>
    </div>

    <div class="max-w-[1600px] mx-auto p-6 lg:p-10 space-y-8">
      
      <!-- Page Header -->
      <PageHeader
        :breadcrumb="{ parent: { label: 'التقارير', path: '/reports' }, current: { label: 'توزيع قيمة المخزون', path: '/reports/inventory-by-branch' } }"
        title="توزيع قيمة المخزون"
        description="تحليل الأصول وتوزيع السيولة عبر الفروع"
        :branches="branches"
        :selectedBranch="selectedBranch === 'all' ? null : selectedBranch"
        :hasExplicitSelection="true"
        @branch-changed="selectedBranch = $event === null ? 'all' : $event"
      >
        <template #controls>
          <!-- Valuation Switcher -->
          <div class="flex items-center gap-2 px-3 py-1 bg-slate-100 rounded-md border border-slate-200">
            <span class="text-[9px] font-bold text-slate-400 uppercase tracking-widest">أساس التقييم:</span>
            <select v-model="valuation" class="bg-transparent border-none text-[11px] font-bold text-slate-900 focus:ring-0 cursor-pointer outline-none">
              <option value="sale">سعر البيع</option>
              <option value="cost">سعر التكلفة</option>
            </select>
          </div>
        </template>
      </PageHeader>

      <main class="space-y-8">

      <!-- KPI Summary Section: Refined Metric Grid -->
      <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
        <div v-for="kpi in [
          { label: 'القيمة الإجمالية للمخزون', val: formatCurrency(totals.total_value || 0), icon: 'fa-chart-line', color: 'text-indigo-600', bg: 'bg-indigo-50' },
          { label: 'إجمالي عدد الأصناف', val: totals.total_products || 0, icon: 'fa-boxes-stacked', color: 'text-blue-600', bg: 'bg-blue-50' },
          { label: 'منتجات منخفضة (Low)', val: totals.low_stock_count || 0, icon: 'fa-exclamation-triangle', color: 'text-amber-600', bg: 'bg-amber-50' },
          { label: 'أصناف نافذة (Out)', val: totals.out_of_stock_count || 0, icon: 'fa-exclamation-circle', color: 'text-rose-600', bg: 'bg-rose-50' }
        ]" :key="kpi.label" class="bg-white border border-slate-200 p-5 rounded-xl flex items-center justify-between group hover:border-slate-300 transition-all shadow-sm">
          <div>
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">{{ kpi.label }}</p>
            <p :class="[kpi.color, 'text-xl font-bold font-mono tracking-tighter leading-none']">{{ kpi.val }}</p>
          </div>
          <div :class="[kpi.bg, kpi.color]" class="w-10 h-10 rounded-lg flex items-center justify-center text-sm opacity-80 group-hover:opacity-100 transition-opacity shadow-inner">
            <i :class="['fas', kpi.icon]"></i>
          </div>
        </div>
      </section>

      <!-- Visual Analytics Row -->
      <section class="grid grid-cols-1 lg:grid-cols-12 gap-8">
        
        <!-- Chart Visualization (8/12) -->
        <div class="lg:col-span-8 bg-white border border-slate-200 rounded-xl p-6 shadow-sm flex flex-col relative overflow-hidden group">
          <div class="absolute top-0 left-0 w-40 h-40 bg-blue-500/5 rounded-full -translate-x-12 -translate-y-12 group-hover:scale-110 transition-transform duration-1000"></div>
          
          <div class="flex items-center justify-between mb-8 relative z-10">
             <h2 class="text-sm font-bold text-slate-900 uppercase tracking-widest flex items-center gap-2">
               <i class="fas fa-chart-column text-blue-500"></i> توزيع القيمة المالية عبر الفروع
             </h2>
             <span class="text-[9px] font-bold text-slate-400 bg-slate-50 px-2 py-1 rounded border border-slate-100">تحليل جغرافي</span>
          </div>

          <div class="flex-grow relative z-10 h-80">
            <div v-if="loading" class="absolute inset-0 flex items-center justify-center bg-white/80 backdrop-blur-sm z-20">
              <div class="flex items-center gap-1">
                <div class="w-2 h-2 rounded-full bg-slate-400 animate-pulse"></div>
                <div class="w-2 h-2 rounded-full bg-slate-400 animate-pulse" style="animation-delay: 0.2s"></div>
                <div class="w-2 h-2 rounded-full bg-slate-400 animate-pulse" style="animation-delay: 0.4s"></div>
              </div>
            </div>
            <BarChart v-if="chartData && chartData.labels.length" :data="chartData" :options="chartOptions" />
            <div v-else class="h-full flex flex-col items-center justify-center text-slate-300 gap-3">
               <i class="fas fa-sync-alt fa-spin text-2xl opacity-20"></i>
               <p class="text-[10px] font-bold uppercase tracking-widest">بانتظار مزامنة البيانات...</p>
            </div>
          </div>
        </div>

        <!-- Valuation Info (4/12) -->
        <div class="lg:col-span-4 bg-slate-900 rounded-xl p-8 text-white shadow-xl flex flex-col justify-center relative overflow-hidden">
          <div class="absolute bottom-0 right-0 w-32 h-32 bg-white/5 rounded-full translate-x-12 translate-y-12"></div>
          <div class="relative z-10 space-y-6 text-center">
            <p class="text-[10px] font-bold text-blue-400 uppercase tracking-[0.3em] mb-4">أساس التقييم النشط</p>
            <div class="w-16 h-16 bg-white/10 rounded-2xl flex items-center justify-center text-2xl mx-auto shadow-inner">
               <i :class="valuation === 'cost' ? 'fas fa-tag' : 'fas fa-money-bill-trend-up'"></i>
            </div>
            <h3 class="text-xl font-bold uppercase">{{ valuation === 'cost' ? 'سعر التكلفة' : 'سعر البيع' }}</h3>
            <p class="text-xs text-slate-400 leading-relaxed italic">يتم حساب القيمة بناءً على الأرصدة الحالية مضروبة في {{ valuation === 'cost' ? 'متوسط سعر الشراء' : 'سعر البيع المحدد' }}.</p>
          </div>
        </div>
      </section>
      
      <!-- Detailed Branch Breakdown Table -->
      <section class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm relative min-h-[400px]">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
           <h3 class="text-xs font-bold text-slate-900 uppercase tracking-widest flex items-center gap-2">
             <i class="fas fa-list-ul text-slate-400"></i> تفاصيل تقييم المخزون المادي
           </h3>
           <span class="text-[10px] font-bold text-slate-400 bg-white border border-slate-200 px-3 py-1 rounded-full uppercase tracking-tighter">
             Total Branches: {{ rows.length }}
           </span>
        </div>

        <div class="overflow-x-auto">
          <table class="w-full text-right border-collapse">
            <thead>
              <tr class="bg-slate-50/50 border-b border-slate-200 text-slate-400 font-bold uppercase text-[10px] tracking-widest">
                <th class="px-6 py-4">اسم الفرع / الموقع</th>
                <th class="px-4 py-4 text-center">عدد الأصناف</th>
                <th class="px-4 py-4 text-center">نواقص (Low)</th>
                <th class="px-4 py-4 text-center">نافذ (Out)</th>
                <th class="px-4 py-4 text-center">الكمية الإجمالية</th>
                <th class="px-6 py-4 text-left">قيمة المخزون</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 font-medium text-xs">
              <!-- Loading Skeleton -->
              <template v-if="loading && !rows.length">
                <tr v-for="n in 5" :key="n" class="animate-pulse">
                  <td v-for="m in 6" :key="m" class="px-6 py-4"><div class="h-3 bg-slate-100 rounded w-full"></div></td>
                </tr>
              </template>
              
              <!-- Empty State -->
              <tr v-else-if="rows.length === 0">
                <td colspan="6" class="py-24 text-center text-slate-300">
                   <i class="fas fa-warehouse text-3xl mb-4 opacity-20"></i>
                   <p class="text-xs font-bold uppercase tracking-widest">لا توجد بيانات متاحة للفروع</p>
                </td>
              </tr>

              <!-- Data Rows -->
              <tr v-for="r in rows" :key="r.branch_name" class="hover:bg-blue-50/20 transition-all group border-r-4 border-r-transparent hover:border-r-blue-600">
                <td class="px-6 py-4 whitespace-nowrap">
                  <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-lg bg-slate-50 flex items-center justify-center text-slate-400 group-hover:text-blue-600 transition-colors border border-slate-100 group-hover:border-blue-100">
                      <i class="fas fa-store text-xs"></i>
                    </div>
                    <span class="text-xs font-bold text-slate-900 group-hover:text-blue-600 transition-colors">{{ r.branch_name }}</span>
                  </div>
                </td>
                <td class="px-4 py-4 text-center">
                  <span class="px-2 py-0.5 bg-slate-100 rounded text-[10px] font-bold text-slate-600 font-mono">{{ r.items_count }}</span>
                </td>
                <td class="px-4 py-4 text-center">
                  <span v-if="r.low_stock_count > 0" class="px-2 py-0.5 bg-amber-50 text-amber-600 border border-amber-100 rounded text-[9px] font-bold">
                    {{ r.low_stock_count }} صنف
                  </span>
                  <span v-else class="text-slate-200">—</span>
                </td>
                <td class="px-4 py-4 text-center">
                  <span v-if="r.out_of_stock_count > 0" class="px-2 py-0.5 bg-rose-50 text-rose-600 border border-rose-100 rounded text-[9px] font-bold">
                    {{ r.out_of_stock_count }} صنف
                  </span>
                  <span v-else class="text-slate-200">—</span>
                </td>
                <td class="px-4 py-4 text-center font-mono font-bold text-slate-400 group-hover:text-slate-900 transition-colors">{{ r.total_qty }}</td>
                <td class="px-6 py-4 text-left">
                  <span class="text-sm font-bold font-mono tracking-tighter text-blue-600">
                    {{ formatCurrency(r.total_value) }}
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
      </main>
    </div>
  </div>
</template>

<script setup>
// [بقاء المنطق البرمجي كما هو بنسبة 100%]
import { ref, onMounted, watch, computed } from 'vue'
import { useReportsStore } from '@/stores/reports/reportsStore'
import { Bar as BarChart } from 'vue-chartjs'
import { Chart as ChartJS, Title, Tooltip, Legend, BarElement, CategoryScale, LinearScale } from 'chart.js'

import { useCompanyCurrency } from '@/composables/useCompanyCurrency';
import { useBranchStore } from '@/stores/branch/branchStore';
import PageHeader from '@/components/PageHeader.vue';

ChartJS.register(Title, Tooltip, Legend, BarElement, CategoryScale, LinearScale)

const reportsStore = useReportsStore()
const branchStoreInstance = useBranchStore()
const { formatCurrencyLocale, fetchSettings } = useCompanyCurrency()
const valuation = ref('sale')
const rows = ref([])
const totals = ref({})
const loading = ref(false)

const branches = computed(() => branchStoreInstance.branches)
const selectedBranch = computed({
  get: () => branchStoreInstance.selectedBranchId || 'all',
  set: (value) => branchStoreInstance.setSelectedBranch(value === 'all' ? null : value)
})

const formatCurrency = (n) => formatCurrencyLocale(n, 2)

const loadData = async () => {
  loading.value = true
  try {
    const result = await reportsStore.fetchInventoryValueByBranch({ valuation: valuation.value })
    if (result.status === 'success') {
      const apiResponse = result.data || {}
      const payload = apiResponse?.data ?? apiResponse
      const list = Array.isArray(payload)
        ? payload
        : (Array.isArray(payload?.rows)
            ? payload.rows
            : (Array.isArray(payload?.data)
                ? payload.data
                : (Array.isArray(payload?.items)
                    ? payload.items
                    : [])))
      rows.value = list.map(row => ({
        ...row,
        branch_id: row.branch_id ?? row.branchId,
        branch_name: row.branch_name ?? row.branchName ?? '—',
        items_count: Number(row.items_count ?? row.itemsCount ?? 0),
        low_stock_count: Number(row.low_stock_count ?? row.lowStockCount ?? 0),
        out_of_stock_count: Number(row.out_of_stock_count ?? row.outOfStockCount ?? 0),
        total_qty: Number(row.total_qty ?? row.totalQty ?? 0),
        total_value: Number(row.total_value ?? row.totalValue ?? 0)
      }))
      totals.value = apiResponse?.totals || payload?.totals || {}
    } else {
      rows.value = []
      totals.value = {}
    }
  } catch (e) {
    console.error('inventory-value load failed:', e)
    rows.value = []
    totals.value = {}
  } finally {
    loading.value = false
  }
}

const chartData = computed(() => {
  const labels = rows.value.map(r => r.branch_name)
  const values = rows.value.map(r => Number(r.total_value) || 0)
  return {
    labels,
    datasets: [
      {
        label: 'قيمة المخزون',
        data: values,
        backgroundColor: '#2563eb',
        borderRadius: 4,
        maxBarThickness: 32
      }
    ]
  }
})

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
    x: {
      grid: { display: false },
      ticks: { font: { family: 'Cairo', size: 10, weight: 'bold' }, color: '#94a3b8' }
    }
  } 
}

onMounted(() => { Promise.all([fetchSettings(), loadData()]); })
watch(valuation, () => loadData())
</script>

<style scoped>
@keyframes loading { 0% { transform: translateX(100%); } 100% { transform: translateX(-100%); } }

.metadata-label { @apply block text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1; }

.custom-scroll::-webkit-scrollbar { width: 5px; height: 5px; }
.custom-scroll::-webkit-scrollbar-thumb { @apply bg-slate-200 rounded-full; }

.animate-fadeIn { animation: fadeIn 0.4s ease-out; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
</style>