<template>
  <!-- Main Canvas: Using a neutral Zinc/Slate palette for a high-end SaaS feel -->
  <div class="min-h-screen bg-[#fafafa] text-slate-900 font-sans antialiased selection:bg-blue-100" dir="rtl">
    
    <!-- Top Progress/Loading Bar (Subtle) -->
    <PageLoadingBar :active="isLoading || isRefreshing" />

    <div class="max-w-[1600px] mx-auto p-6 lg:p-10 space-y-8">
      
      <!-- Error State: Stripe-inspired Alert -->
      <transition name="slide-down">
        <div v-if="error" class="bg-white border border-red-200 rounded-lg p-4 shadow-sm flex items-center justify-between group">
          <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-full bg-red-50 flex items-center justify-center text-red-600">
              <i class="fas fa-exclamation-circle text-sm"></i>
            </div>
            <div>
              <p class="text-sm font-semibold text-slate-900">{{ error.message }}</p>
              <p class="text-xs text-slate-500">{{ error.details }}</p>
            </div>
          </div>
          <div class="flex items-center gap-2">
            <button @click="retry" class="text-xs font-medium px-3 py-1.5 bg-slate-900 text-white rounded-md hover:bg-slate-800 transition-colors">
              إعادة المحاولة
            </button>
            <button @click="error = null" class="p-1.5 text-slate-400 hover:text-slate-600">
              <i class="fas fa-times text-xs"></i>
            </button>
          </div>
        </div>
      </transition>

      <!-- Page Header -->
      <PageHeader
        :breadcrumb="{ parent: { label: 'الرئيسية', path: '/' }, current: { label: 'لوحة تحكم المدير', path: '/dashboard' } }"
        :title="`أهلًا، ${username} 👋`"
        :description="`تابع أداء متجرك، وراجع المبيعات والأرباح والمخزون وحالة العمليات من مكان واحد.`"
        :branches="branches"
        :selectedBranch="selectedBranch"
        :hasExplicitSelection="hasExplicitBranchSelection"
        @branch-changed="onBranchChange"
      >
        <template #controls>
          <!-- Date Range Toggles -->
          <div class="flex bg-slate-100 p-1 rounded-lg border border-slate-200/50">
            <button v-for="range in ['today', 'week', 'month']" :key="range" 
              @click="setDateRange(range)" 
              :class="[dateRange === range ? 'bg-white text-slate-900 shadow-sm ring-1 ring-slate-200' : 'text-slate-500 hover:text-slate-700']"
              class="px-4 py-1.5 rounded-md text-xs font-semibold transition-all">
              {{ range === 'today' ? 'اليوم' : range === 'week' ? 'الأسبوع' : 'الشهر' }}
            </button>
          </div>

          <LoadingButton
            text=""
            loadingText=""
            :showTextDuringLoad="false"
            icon="fas fa-sync-alt"
            :isLoading="isRefreshing || isLoading"
            variant="secondary"
            class="h-9 w-9 rounded-md border border-slate-200 bg-white p-0 text-slate-500 shadow-sm hover:text-blue-600 hover:border-blue-200"
            aria-label="تحديث لوحة التحكم"
            @click="refresh"
          />
        </template>
      </PageHeader>

      <section
        v-if="unresolvedReturnCostLineCount > 0"
        class="rounded-xl border border-amber-300 bg-amber-50 px-5 py-4 text-sm text-amber-900"
        role="alert"
      >
        <p class="font-bold">تكلفة بعض مرتجعات المبيعات غير محسومة</p>
        <p class="mt-1">
          {{ unresolvedReturnCostLineCount }} بند مرتجع
          <span v-if="unresolvedReturnCostQuantity > 0"> بكمية {{ unresolvedReturnCostQuantity }}</span>
          بلا تكلفة تاريخية موثوقة؛ قد لا يعكس الربح الإجمالي القيمة النهائية. راجع تقرير الأرباح والخسائر لمراجعة البنود.
        </p>
      </section>

      <!-- KPI Grid: Supabase-inspired Data Cards -->
      <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-4">
        <template v-if="isLoading">
          <div v-for="i in kpis.length" :key="i" class="h-32 bg-white border border-slate-200 rounded-xl animate-pulse"></div>
        </template>
        <template v-else>
          <div v-for="kpi in kpis" :key="kpi.title" class="group bg-white border border-slate-200 p-6 rounded-xl hover:border-blue-500/30 hover:shadow-md hover:shadow-blue-500/5 transition-all duration-300">
            <div class="flex justify-between items-start mb-4">
              <span class="text-xs font-bold text-slate-500 uppercase tracking-widest">{{ kpi.title }}</span>
              <div :class="[kpi.iconClass, 'w-8 h-8 rounded-lg flex items-center justify-center text-sm bg-opacity-10 opacity-80 group-hover:opacity-100 group-hover:scale-110 transition-transform']">
                <i :class="kpi.icon"></i>
              </div>
            </div>
            <div class="flex items-baseline gap-2">
              <h3 :class="[kpi.valueClass || 'text-slate-900']" class="text-2xl font-bold tracking-tight">{{ kpi.value }}</h3>
              <div v-if="kpi.change !== 0" 
                :class="[kpi.change > 0 ? 'text-emerald-600' : 'text-red-600']"
                class="text-[10px] font-bold flex items-center">
                <i :class="kpi.change > 0 ? 'fa fa-arrow-up' : 'fa fa-arrow-down'" class="ml-0.5"></i>
                {{ Math.abs(kpi.change).toFixed(1) }}%
              </div>
            </div>
            <!-- Sparkline visualization (Decoration only) -->
            <div class="mt-4 h-1 w-full bg-slate-50 rounded-full overflow-hidden">
              <div :class="[kpi.change >= 0 ? 'bg-emerald-500' : 'bg-red-500']" class="h-full opacity-20" :style="{ width: '100%' }"></div>
            </div>
          </div>
        </template>
      </section>

      <!-- Main Analytics -->
      <section class="grid grid-cols-1 lg:grid-cols-12 gap-8">
        <!-- Sales Chart -->
        <div class="lg:col-span-8 bg-white border border-slate-200/80 rounded-2xl overflow-hidden shadow-sm hover:shadow-md hover:shadow-slate-200/50 transition-shadow">
          <div class="px-6 py-5 border-b border-slate-100 flex items-center justify-between">
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center">
                <i class="fas fa-chart-line"></i>
              </div>
              <div>
                <h2 class="text-sm font-bold text-slate-900">اتجاهات المبيعات</h2>
                <p class="text-[11px] text-slate-400 mt-1">الإيرادات اليومية خلال الفترة المحددة</p>
              </div>
            </div>
            <span class="inline-flex items-center gap-1.5 text-[10px] font-semibold text-emerald-700 bg-emerald-50 border border-emerald-100 px-2.5 py-1 rounded-full">
              <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
              مباشر
            </span>
          </div>
          <div class="px-5 py-6 h-[360px] sm:h-[390px]">
            <canvas ref="salesChart"></canvas>
          </div>
        </div>

        <!-- Top Products: Side Widget -->
        <div class="lg:col-span-4 bg-white border border-slate-200/80 rounded-2xl overflow-hidden shadow-sm hover:shadow-md hover:shadow-slate-200/50 transition-shadow">
          <div class="px-6 py-5 border-b border-slate-100 flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center">
              <i class="fas fa-trophy"></i>
            </div>
            <div>
              <h2 class="text-sm font-bold text-slate-900">الأكثر مبيعاً</h2>
              <p class="text-[11px] text-slate-400 mt-1">أفضل خمسة منتجات أداءً</p>
            </div>
          </div>
          <div class="relative px-5 py-6 h-[360px] sm:h-[390px]">
            <canvas ref="topProductsChart"></canvas>
          </div>
        </div>
      </section>

      <!-- Bottom Grid: Inventory & Staff -->
      <section class="grid grid-cols-1 lg:grid-cols-12 gap-8">
        <!-- Low Stock Table -->
        <div class="lg:col-span-5 bg-white border border-slate-200/80 rounded-2xl overflow-hidden shadow-sm flex flex-col">
          <div class="px-6 py-5 border-b border-slate-100 flex items-center justify-between bg-gradient-to-l from-rose-50/70 to-white">
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 rounded-xl bg-rose-100 text-rose-600 flex items-center justify-center">
                <i class="fas fa-boxes"></i>
              </div>
              <div>
                <h2 class="text-sm font-bold text-slate-900">تنبيهات المخزون</h2>
                <p class="text-[11px] text-slate-400 mt-1">أصناف تحتاج إلى إعادة تزويد</p>
              </div>
            </div>
            <span v-if="lowStockItems.length" class="text-[10px] bg-rose-100 text-rose-700 border border-rose-200 px-2.5 py-1 rounded-full font-bold">
              {{ lowStockItems.length }} تنبيه
            </span>
            <span v-else class="text-[10px] bg-emerald-50 text-emerald-700 border border-emerald-100 px-2.5 py-1 rounded-full font-bold">مستقر</span>
          </div>
          <div class="flex-1 overflow-y-auto custom-scroll">
            <div v-if="lowStockItems.length" class="divide-y divide-slate-100">
              <div v-for="item in lowStockItems" :key="`${item.id}_${item.branch_id || 'all'}`"
                class="px-6 py-4 flex items-center justify-between hover:bg-rose-50/40 transition-colors group">
                <div class="flex items-center gap-3 min-w-0">
                  <span :class="Number(item.quantity) <= 0 ? 'bg-red-100 text-red-600' : 'bg-amber-100 text-amber-700'" class="w-9 h-9 shrink-0 rounded-lg flex items-center justify-center">
                    <i :class="Number(item.quantity) <= 0 ? 'fas fa-ban' : 'fas fa-exclamation-triangle'" class="text-xs"></i>
                  </span>
                  <div class="min-w-0">
                  <p class="text-sm font-bold text-slate-800">{{ item.name }}</p>
                  <p v-if="item.branch_name" class="text-[10px] font-bold text-indigo-600">{{ item.branch_name }}</p>
                  <p class="text-[10px] text-slate-500">
                    {{ Number(item.quantity) <= 0 ? 'نفد المخزون' : `الحد الأدنى: ${item.min_quantity} وحدة` }}
                  </p>
                  </div>
                </div>
                <div class="flex items-center gap-4">
                  <div class="text-left">
                    <p :class="Number(item.quantity) <= 0 ? 'text-red-600' : 'text-amber-600'" class="text-sm font-black">{{ item.quantity }}</p>
                    <p class="text-[9px] font-bold text-slate-400 uppercase">متبقي</p>
                  </div>
                  <button @click="handleLowStockClick(item)" class="p-2 rounded-md border border-slate-200 text-slate-400 hover:text-blue-600 hover:bg-white transition-all shadow-sm">
                    <i class="fas fa-external-link-alt text-[10px]"></i>
                  </button>
                </div>
              </div>
            </div>
            <div v-else class="h-full flex flex-col items-center justify-center py-12 text-slate-300">
              <i class="fas fa-check-circle text-4xl mb-2 opacity-20"></i>
              <p class="text-xs font-bold uppercase tracking-widest">المخزون ممتاز</p>
            </div>
          </div>
        </div>

        <!-- POS Performance -->
        <div class="lg:col-span-7 bg-white border border-slate-200/80 rounded-2xl overflow-hidden shadow-sm hover:shadow-md hover:shadow-slate-200/50 transition-shadow">
          <div class="px-6 py-5 border-b border-slate-100 flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
              <i class="fas fa-users"></i>
            </div>
            <div>
              <h2 class="text-sm font-bold text-slate-900">تحليل أداء البائعين</h2>
              <p class="text-[11px] text-slate-400 mt-1">مقارنة إجمالي المبيعات بصافيها لكل بائع</p>
            </div>
          </div>
          <div class="px-5 py-6 h-[320px]">
            <canvas ref="posPerformanceChart"></canvas>
          </div>
        </div>
      </section>

      <!-- Quick Access: Action Bar -->
      <section class="bg-white border border-slate-200 rounded-xl p-8 shadow-sm">
        <div class="mb-8 flex items-center gap-4">
          <h2 class="text-sm font-bold text-slate-900">الوصول السريع</h2>
          <div class="h-px bg-slate-200 flex-1"></div>
        </div>
        
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
          <router-link v-for="link in quickAccessLinks" :key="link.to" :to="link.to" 
            :class="[link.highlighted ? 'bg-blue-50 border-blue-300 shadow-md shadow-blue-100' : 'bg-slate-50 border-slate-200']"
            class="group relative p-5 rounded-xl transition-all border hover:bg-blue-50 hover:border-blue-300">
            <div :class="[link.highlighted ? 'bg-blue-600 text-white' : 'bg-blue-100 text-blue-600']" 
              class="w-10 h-10 rounded-lg flex items-center justify-center mb-4 group-hover:bg-blue-600 group-hover:text-white transition-colors">
              <i :class="link.icon" class="text-sm"></i>
            </div>
            <span class="text-xs font-bold leading-snug text-slate-900">{{ link.text }}</span>
            <i class="fas fa-arrow-left absolute bottom-5 left-5 text-[10px] text-slate-300 opacity-0 group-hover:opacity-100 transition-all -translate-x-2 group-hover:translate-x-0"></i>
          </router-link>
        </div>
      </section>

    </div>
  </div>
</template>

<script setup>
import { ref, shallowRef, onMounted, onBeforeUnmount, computed, watch } from 'vue'
import Chart from 'chart.js/auto'
import { Filler } from 'chart.js'
import { useAuthStore } from '@/stores/auth/authStore'
import { useReportsStore } from '@/stores/reports/reportsStore'
import { useAnalyticsStore } from '@/stores/analytics/analyticsStore'
import { useRouter } from 'vue-router'
import { useCompanyCurrency } from '@/composables/useCompanyCurrency'
import { useBranchStore } from '@/stores/branch/branchStore'
import { useSessionExemption } from '@/composables/useCashierSessionGuard'
import PageHeader from '@/components/PageHeader.vue'
import LoadingButton from '@/components/ui/LoadingButton.vue'

Chart.register(Filler)

const authStore = useAuthStore()
const reportsStore = useReportsStore()
const analyticsStore = useAnalyticsStore()
const router = useRouter()
const { formatCurrencyLocale, fetchSettings } = useCompanyCurrency()
const branchStore = useBranchStore()
const { isExempt, ensureLoaded: ensureExemptionLoaded } = useSessionExemption()

const branches = computed(() => branchStore.branches)
const selectedBranch = computed({
  get: () => branchStore.selectedBranchId ?? null,
  set: (val) => {
    const normalized = val === 'all' || val === null || val === undefined ? null : Number(val)
    const current = branchStore.selectedBranchId
    if (String(current ?? 'all') !== String(normalized ?? 'all')) {
      branchStore.setSelectedBranch(normalized)
    }
  }
})

const userChoseBranch = ref(
  localStorage.getItem('selectedBranchId') !== null
  && localStorage.getItem('selectedBranchId') !== 'all'
)
const hasExplicitBranchSelection = computed(() => userChoseBranch.value && branchStore.selectedBranchId !== null)

const salesChart = ref(null)
const topProductsChart = ref(null)
const posPerformanceChart = ref(null)
const salesChartInstance = shallowRef(null)
const topProductsChartInstance = shallowRef(null)
const posPerformanceChartInstance = shallowRef(null)
const topProductsTotal = ref(0)
const compactNumberFormat = new Intl.NumberFormat('ar-EG', { notation: 'compact', maximumFractionDigits: 1 })

const username = computed(() => authStore.user?.name || 'مدير المحل')
const isAdmin = computed(() => authStore.isAdmin)

const isLoading = ref(false)
const isRefreshing = ref(false)
const error = ref(null)
const lastUpdated = ref('')
const dateRange = ref('week')
const lowStockItems = ref([])
const unresolvedReturnCostLineCount = ref(0)
const unresolvedReturnCostQuantity = ref(0)

const kpis = ref([
  { title: 'إجمالي المبيعات', value: '---', change: 0, icon: 'fa fa-dollar-sign', iconClass: 'bg-blue-500 text-blue-600', valueClass: 'text-slate-900' },
  { title: 'صافي المبيعات',   value: '---', change: 0, icon: 'fa fa-chart-line',  iconClass: 'bg-cyan-500 text-cyan-600', valueClass: 'text-cyan-700' },
  { title: 'المرتجعات',       value: '---', change: 0, icon: 'fa fa-undo',        iconClass: 'bg-rose-500 text-rose-600', valueClass: 'text-rose-600' },
  { title: 'الربح الإجمالي',  value: '---', change: 0, icon: 'fa fa-wallet',       iconClass: 'bg-emerald-500 text-emerald-600',    valueClass: 'text-emerald-600'  },
  { title: 'عدد الفواتير',    value: '0',   change: 0, icon: 'fa fa-receipt',      iconClass: 'bg-amber-500 text-amber-600',  valueClass: 'text-slate-900' },
  { title: 'متوسط الفاتورة',  value: '---', change: 0, icon: 'fa fa-calculator',   iconClass: 'bg-indigo-500 text-indigo-600',valueClass: 'text-slate-900' }
])

const quickAccessLinks = ref([
  { text: 'الأرباح والخسائر',  icon: 'fa fa-chart-line',     to: '/reports/profit-loss' },
  { text: 'حركة المخزون',      icon: 'fa fa-boxes',           to: '/reports/inventory-movements' },
  { text: 'تحليلات المبيعات',  icon: 'fa fa-chart-bar',       to: '/reports/sales-analytics' },
  { text: 'نقطة البيع',        icon: 'fa fa-cash-register',   to: '/sales/pos' },
  { text: 'إدارة المخزون',     icon: 'fa fa-warehouse',       to: '/inventory' },
  { text: 'كل التقارير',       icon: 'fa fa-chart-pie',       to: '/reports/sales-analytics', highlighted: true }
])

function formatCurrency(value) {
  return formatCurrencyLocale(value, 2)
}

function setDefaultKpiValues() {
  unresolvedReturnCostLineCount.value = 0
  unresolvedReturnCostQuantity.value = 0
  kpis.value.forEach(kpi => {
    kpi.value = formatCurrency(0)
    kpi.change = 0
  })
  kpis.value[4].value = '0'
}

function getLocalDateISO(d = new Date()) {
  const offset = d.getTimezoneOffset()
  const localDate = new Date(d.getTime() - offset * 60 * 1000)
  const yyyy = localDate.getFullYear()
  const mm = String(localDate.getMonth() + 1).padStart(2, '0')
  const dd = String(localDate.getDate()).padStart(2, '0')
  return `${yyyy}-${mm}-${dd}`
}

function calcRange(range) {
  const today = new Date()
  const end = new Date(today)
  end.setHours(23, 59, 59, 999)
  const start = new Date(today)
  start.setHours(0, 0, 0, 0)
  if (range === 'week') start.setDate(start.getDate() - 6)
  else if (range === 'month') start.setDate(start.getDate() - 29)
  return { startDate: getLocalDateISO(start), endDate: getLocalDateISO(end) }
}

function handleLowStockClick(item) {
  try { router.push({ path: '/inventory', query: { product_id: item?.id } }) } catch {}
}

function initChart(chartRef, type, data, instanceRef) {
  if (!chartRef.value) return
  const canvas = chartRef.value
  const chart = instanceRef.value

  const hasDatasets = Array.isArray(data?.datasets) && data.datasets.length > 0
  const hasChartData = hasDatasets
    ? data.datasets.some(dataset => Array.isArray(dataset.data) && dataset.data.length > 0)
    : Array.isArray(data?.data) && data.data.length > 0
  if (!data?.labels?.length || !hasChartData) {
    if (chart) {
      chart.stop()
      chart.destroy()
      instanceRef.value = null
    }
    const ctx = canvas.getContext('2d')
    if (!ctx) return
    ctx.clearRect(0, 0, canvas.width, canvas.height)
    ctx.save(); ctx.fillStyle = '#94a3b8'; ctx.font = '12px Cairo'; ctx.textAlign = 'center';
    ctx.fillText('لا توجد بيانات', canvas.width / 2, canvas.height / 2); ctx.restore()
    return
  }

  const labelMap = {
    line: 'إجمالي الإيرادات',
    doughnut: 'المبيعات',
    bar: 'أداء البائعين'
  }

  const numberFormat = new Intl.NumberFormat('ar-EG', { notation: 'compact', maximumFractionDigits: 1 })
  const chartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    animation: { duration: 650, easing: 'easeOutQuart' },
    interaction: { mode: 'index', intersect: false },
    indexAxis: type === 'bar' ? 'y' : 'x',
    cutout: type === 'doughnut' ? '68%' : undefined,
    layout: { padding: type === 'doughnut' ? 8 : { top: 8, right: 4, bottom: 0, left: 4 } },
    plugins: {
      legend: {
        display: type === 'doughnut' || (hasDatasets && data.datasets.length > 1),
        position: 'bottom',
        rtl: true,
        labels: { usePointStyle: true, pointStyle: 'circle', boxWidth: 8, boxHeight: 8, padding: 18, color: '#64748b', font: { family: 'Cairo', size: 11 } }
      },
      tooltip: {
        rtl: true,
        textDirection: 'rtl',
        backgroundColor: '#0f172a',
        titleColor: '#cbd5e1',
        bodyColor: '#fff',
        padding: 12,
        cornerRadius: 10,
        displayColors: true,
        boxPadding: 5,
        bodyFont: { family: 'Cairo', size: 11 },
        titleFont: { family: 'Cairo', size: 11, weight: '600' },
        callbacks: { label: context => ` ${context.dataset.label || ''}: ${numberFormat.format(Number(context.raw) || 0)}` }
      }
    },
    scales: type === 'doughnut' ? {} : {
      x: {
        beginAtZero: true,
        grid: { color: type === 'bar' ? '#f1f5f9' : 'transparent', drawTicks: false },
        border: { display: false },
        ticks: { font: { size: 10, family: 'Cairo' }, color: '#94a3b8', padding: 8, callback: value => type === 'bar' ? numberFormat.format(value) : value }
      },
      y: {
        beginAtZero: true,
        grid: { color: type === 'line' ? '#f1f5f9' : 'transparent', drawTicks: false },
        border: { display: false },
        ticks: { font: { size: 10, family: 'Cairo' }, color: '#64748b', padding: 8, callback: value => type === 'line' ? numberFormat.format(value) : value }
      }
    }
  }

  const palette = ['#3b82f6', '#10b981', '#8b5cf6', '#f59e0b', '#f43f5e']
  const styledDatasets = (hasDatasets ? data.datasets : [{
    label: labelMap[type] || 'القيمة',
    data: data.data
  }]).map((dataset, index) => {
    if (type === 'line') {
      return {
        ...dataset,
        borderColor: '#2563eb',
        backgroundColor: context => {
          const { chart } = context
          const area = chart.chartArea
          if (!area) return 'rgba(37, 99, 235, 0.08)'
          const gradient = chart.ctx.createLinearGradient(0, area.top, 0, area.bottom)
          gradient.addColorStop(0, 'rgba(37, 99, 235, 0.22)')
          gradient.addColorStop(1, 'rgba(37, 99, 235, 0.01)')
          return gradient
        },
        borderWidth: 2.5,
        tension: 0.38,
        fill: true,
        pointRadius: 0,
        pointHoverRadius: 5,
        pointHoverBackgroundColor: '#fff',
        pointHoverBorderColor: '#2563eb',
        pointHoverBorderWidth: 2
      }
    }
    if (type === 'doughnut') {
      return {
        ...dataset,
        backgroundColor: palette,
        borderColor: '#fff',
        borderWidth: 4,
        hoverOffset: 7,
        borderRadius: 5,
        spacing: 2
      }
    }
    return {
      ...dataset,
      backgroundColor: dataset.backgroundColor || palette[index % palette.length],
      borderColor: 'transparent',
      borderRadius: 7,
      borderSkipped: false,
      maxBarThickness: 26,
      categoryPercentage: 0.68,
      barPercentage: 0.82
    }
  })

  const chartData = {
    labels: data.labels,
    datasets: styledDatasets
  }

  if (chart) {
    chart.data = chartData
    chart.options = chartOptions
    chart.update('none')
    return
  }

  const ctx = canvas.getContext('2d')
  if (!ctx) return
  const centerTextPlugin = type === 'doughnut' ? {
    id: 'top-products-center-text',
    afterDraw(chart) {
      const arc = chart.getDatasetMeta(0).data[0]
      if (!arc || topProductsTotal.value <= 0) return

      const context = chart.ctx
      const centerX = arc.x
      const centerY = arc.y
      const valueFontSize = Math.min(16, Math.max(12, chart.width / 22))

      context.save()
      context.textAlign = 'center'
      context.textBaseline = 'middle'
      context.direction = 'rtl'
      context.fillStyle = '#94a3b8'
      context.font = '500 10px Cairo, sans-serif'
      context.fillText('إجمالي القيمة', centerX, centerY - 9)
      context.fillStyle = '#0f172a'
      context.font = `700 ${valueFontSize}px Cairo, sans-serif`
      context.fillText(compactNumberFormat.format(topProductsTotal.value), centerX, centerY + 9)
      context.restore()
    }
  } : null
  instanceRef.value = new Chart(ctx, {
    type,
    data: chartData,
    options: { ...chartOptions, animation: false },
    plugins: centerTextPlugin ? [centerTextPlugin] : []
  })
}

function updateChart(data, chartRef, instanceRef, chartType) {
  if (chartRef.value) initChart(chartRef, chartType, data || { labels: [], data: [] }, instanceRef)
}

const updateSalesChart       = (data) => updateChart(data, salesChart,        salesChartInstance,       'line')
const updateTopProductsChart = (data) => {
  topProductsTotal.value = (Array.isArray(data?.data) ? data.data : [])
    .reduce((sum, value) => sum + (Number(value) || 0), 0)
  updateChart(data, topProductsChart, topProductsChartInstance, 'doughnut')
}
const updatePosPerformanceChart = (data) => updateChart(data, posPerformanceChart, posPerformanceChartInstance, 'bar')

let dashboardAbortCtrl = null
let dashboardRequestId = 0
let dashboardRefreshTimer = null
let dashboardLastFetchAt = 0

async function fetchDashboardData(refresh = false) {
    const requestId = ++dashboardRequestId
    if (dashboardAbortCtrl) dashboardAbortCtrl.abort()
    dashboardAbortCtrl = new AbortController()
    const signal = dashboardAbortCtrl.signal

    if (refresh) { isRefreshing.value = true; await fetchSettings(true) }
    else { isLoading.value = true; await fetchSettings() }
    if (requestId !== dashboardRequestId || signal.aborted) return

    error.value = null

    try {
      const { startDate, endDate } = calcRange(dateRange.value)
      const branchId = selectedBranch.value ? String(selectedBranch.value) : null

      try {
        const [salesResponse, inventoryResponse, posResponse] = await Promise.all([
          analyticsStore.fetchSalesAnalytics({ startDate, endDate, branchId, force: refresh, signal }),
          reportsStore.fetchInventoryAnalytics({ signal, branchId }),
          reportsStore.fetchPosPerformance(startDate, endDate, { signal, branchId })
        ])
        if (requestId !== dashboardRequestId || signal.aborted) return
        const failedResponse = [salesResponse, inventoryResponse, posResponse]
          .find(response => response?.status === 'error')
        if (failedResponse) {
          throw new Error(failedResponse.message || 'تعذر تحديث بعض بيانات لوحة التحكم')
        }

        const salesData = salesResponse?.data || salesResponse || {}

      try {
        const dailySales = Array.isArray(salesData.daily_sales) ? salesData.daily_sales : []
        const invoiceCount = Number(salesData.invoices_count)
          || dailySales.reduce((sum, day) => sum + (Number(day?.total_orders) || 0), 0)
        const grossProfit = parseFloat(salesData.gross_profit) || 0
        unresolvedReturnCostLineCount.value = Number(salesData.returns_cost_unresolved_line_count) || 0
        unresolvedReturnCostQuantity.value = Number(salesData.returns_cost_unresolved_quantity) || 0
        const grossGrandTotal = Number(salesData.grand_total ?? salesData.total_sales_amount ?? 0) || 0
        const netSales = Number(salesData.net_sales ?? 0) || 0
        const totalReturns = Number(salesData.total_returns_amount ?? 0) || 0
        const avgOrderValue = Number(salesData.avg_invoice)
          || (invoiceCount > 0 ? grossGrandTotal / invoiceCount : 0)

        kpis.value[0].value = formatCurrency(grossGrandTotal)
        kpis.value[1].value = formatCurrency(netSales)
        kpis.value[2].value = formatCurrency(totalReturns)
        kpis.value[3].value = formatCurrency(grossProfit)
        kpis.value[4].value = invoiceCount.toLocaleString('en-US')
        kpis.value[5].value = formatCurrency(avgOrderValue)

        kpis.value[0].change = typeof salesData.sales_change === 'number' ? salesData.sales_change : 0
        kpis.value[1].change = typeof salesData.net_sales_change === 'number' ? salesData.net_sales_change : 0
        kpis.value[3].change = typeof salesData.gross_profit_change === 'number' ? salesData.gross_profit_change : 0
        kpis.value[4].change = typeof salesData.invoice_count_change === 'number' ? salesData.invoice_count_change : 0
        kpis.value[5].change = typeof salesData.avg_order_value_change === 'number' ? salesData.avg_order_value_change : 0
      } catch { setDefaultKpiValues() }

      const inventoryData = inventoryResponse?.data || inventoryResponse || {}
      const branchProducts = inventoryData.branch_products || inventoryData.data?.branch_products || []
      if (Array.isArray(branchProducts) && branchProducts.length > 0) {
        lowStockItems.value = branchProducts
          .filter(it => {
            const status = (it?.stock_status || '').toLowerCase()
            const qty = Number(it?.quantity || 0)
            const minQty = Number(it?.min_quantity || 0)
              return status === 'out_of_stock' || status === 'low' || qty <= 0 || (minQty > 0 && qty <= minQty)
          })
            .map(it => ({
              id: it.product_id || it.id,
              branch_id: it.branch_id,
              branch_name: it.branch_name,
              name: it.product_name || it.name,
              quantity: it.quantity,
              min_quantity: it.min_quantity,
              stock_status: it.stock_status
            }))
          .slice(0, 10)
      } else {
        lowStockItems.value = []
      }

      if (Array.isArray(salesData.daily_sales) && salesData.daily_sales.length) {
        updateSalesChart({
          labels: salesData.daily_sales.map(item => item.date || ''),
          data:   salesData.daily_sales.map(item => Number(item.total_revenue ?? item.total) || 0)
        })
      } else {
        updateSalesChart({ labels: [], data: [] })
      }

      const topProducts = Array.isArray(salesData?.top_products) ? salesData.top_products : []
      const tpLabels = [], tpData = []
      topProducts.slice(0, 5).forEach(p => {
        if (!p) return
        const name = p.name || p.product_name || p.title || 'منتج غير معروف'
        const value = p.total_revenue ? parseFloat(p.total_revenue) : Number(p.quantity ?? p.qty ?? p.count ?? p.amount ?? 0)
        if (name && !isNaN(value) && value > 0) { tpLabels.push(name); tpData.push(value) }
      })
      updateTopProductsChart(tpLabels.length ? { labels: tpLabels, data: tpData } : { labels: [], data: [] })

      try {
        const posPayload = posResponse?.data?.data ?? posResponse?.data ?? posResponse
        const posData = Array.isArray(posPayload?.data)
          ? posPayload.data
          : (Array.isArray(posPayload) ? posPayload : [])
        if (posData.length) {
          updatePosPerformanceChart({
            labels: posData.map(p => p.cashier_name || p.user_name || p.device_name || 'غير معروف'),
            datasets: [
              {
                label: 'إجمالي المبيعات',
                data: posData.map(p => Number(p.total_sales ?? p.total_amount ?? 0)),
                backgroundColor: 'rgba(99,102,241,0.7)',
                borderRadius: 4
              },
              {
                label: 'صافي المبيعات',
                data: posData.map(p => Number(p.net_sales ?? p.total_sales ?? p.total_amount ?? 0)),
                backgroundColor: 'rgba(16,185,129,0.7)',
                borderRadius: 4
              }
            ]
          })
        } else {
          updatePosPerformanceChart({ labels: [], data: [] })
        }
      } catch {
        updatePosPerformanceChart({ labels: [], data: [] })
      }

    } catch (err) {
      const isAborted = err?.name === 'AbortError' || err?.name === 'CanceledError'
      if (requestId === dashboardRequestId && !signal.aborted && !isAborted) {
        error.value = { message: 'فشل تحميل بيانات لوحة التحكم', details: err.message || 'حدث خطأ في الاتصال' }
        setDefaultKpiValues(); updateSalesChart({ labels: [], data: [] }); updateTopProductsChart({ labels: [], data: [] }); updatePosPerformanceChart({ labels: [], data: [] })
      }
    }

  } catch (err) {
    if (requestId === dashboardRequestId && !signal.aborted) {
      error.value = { message: 'فشل تحميل بيانات لوحة التحكم', details: err.message || 'حدث خطأ في الاتصال' }
    }
  } finally {
    if (requestId === dashboardRequestId) {
      dashboardLastFetchAt = Date.now()
      isLoading.value = false; isRefreshing.value = false
      lastUpdated.value = new Date().toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' })
    }
  }
}

function setDateRange(range) { dateRange.value = range; fetchDashboardData(true) }
async function refresh() { await fetchDashboardData(true) }
async function retry() { await fetchDashboardData(true) }
function refreshDashboardIfStale() {
  if (document.visibilityState !== 'visible' || isLoading.value || isRefreshing.value) return
  if (Date.now() - dashboardLastFetchAt < 30000) return
  fetchDashboardData(true)
}
const onBranchChange = (id) => {
  const normalized = id === 'all' || id === null || id === undefined ? null : id
  branchStore.setSelectedBranch(normalized)
  userChoseBranch.value = (normalized !== null && normalized !== '' && normalized !== 'all')
  fetchDashboardData(true)
}

onMounted(async () => {
  await authStore.initialize?.();
  if (!authStore.isAdmin) { router.push('/cashier'); return; }
  await ensureExemptionLoaded().catch(() => {})
  const hadPriorBranchChoice = localStorage.getItem('selectedBranchId') !== null
                                && localStorage.getItem('selectedBranchId') !== 'all';
  branchStore.loadFromStorage?.()
  await branchStore.fetchBranches().catch(() => {})
  // بعد fetchBranches: أعد تعيين الـ flag بما كان موجوداً قبل الكتابة
  userChoseBranch.value = hadPriorBranchChoice;
  await fetchDashboardData(true)
  dashboardRefreshTimer = window.setInterval(refreshDashboardIfStale, 60000)
  document.addEventListener('visibilitychange', refreshDashboardIfStale)
  window.addEventListener('focus', refreshDashboardIfStale)
})

onBeforeUnmount(() => {
  dashboardRequestId += 1
  if (dashboardRefreshTimer !== null) window.clearInterval(dashboardRefreshTimer)
  document.removeEventListener('visibilitychange', refreshDashboardIfStale)
  window.removeEventListener('focus', refreshDashboardIfStale)
  ;[salesChartInstance, topProductsChartInstance, posPerformanceChartInstance].forEach(i => i.value?.destroy())
  if (dashboardAbortCtrl) dashboardAbortCtrl.abort()
})
</script>

<style scoped>
@keyframes loading { 0% { transform: translateX(100%); } 100% { transform: translateX(-100%); } }

.custom-scroll::-webkit-scrollbar { width: 4px; }
.custom-scroll::-webkit-scrollbar-thumb { @apply bg-slate-200 rounded-full; }

.slide-down-enter-active, .slide-down-leave-active { transition: all 0.3s ease-out; }
.slide-down-enter-from, .slide-down-leave-to { opacity: 0; transform: translateY(-10px); }
</style>