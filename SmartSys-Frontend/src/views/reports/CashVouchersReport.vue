<template>
  <div class="min-h-screen bg-[#fafafa] text-slate-900 font-sans antialiased selection:bg-blue-100" dir="rtl">
    
    <!-- Top Progress Bar: High-precision indicator -->
    <div v-if="isLoading" class="fixed top-0 left-0 right-0 h-0.5 bg-blue-600/10 z-[110]">
      <div class="h-full bg-blue-600 animate-[loading_2s_ease-in-out_infinite] w-1/3"></div>
    </div>

    <div class="max-w-[1600px] mx-auto p-6 lg:p-10 space-y-8">
      
      <!-- Page Header -->
      <PageHeader
        :breadcrumb="{ parent: { label: 'التقارير', path: '/reports' }, current: { label: 'تقرير الصرف والقبض', path: '/reports/cash-vouchers' } }"
        title="تقرير الصرف والقبض"
        description="تحليل السيولة والتدفقات النقدية اللحظية"
        :branches="canSelectBranch ? branches : []"
        :selectedBranch="selectedBranch === 'all' ? null : selectedBranch"
        branch-selection-mode="optional"
        @branch-changed="selectedBranch = $event === null ? 'all' : $event"
      >
        <template #controls>
          <button @click="fetchReport" :disabled="isLoading" class="h-9 px-4 rounded-md border border-slate-200 bg-white text-slate-600 text-xs font-bold hover:bg-slate-50 transition-all flex items-center gap-2 shadow-sm">
            <i class="fas fa-sync-alt text-[10px]" :class="{'animate-spin': isLoading}"></i> تحديث البيانات
          </button>
          <button class="h-9 px-4 rounded-md bg-blue-600 text-white text-xs font-bold shadow-sm hover:bg-blue-700 transition-all flex items-center gap-2">
            <i class="fas fa-print text-[10px]"></i> طباعة التقرير
          </button>
        </template>
      </PageHeader>

      <div v-if="error" role="alert" class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-bold text-amber-800">
        {{ error }}
      </div>

      <main class="space-y-8">

      <!-- Analytical Filters Panel: Utility Grid -->
      <section class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-visible">
        <div class="p-6 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
          <div class="space-y-1.5">
            <label class="metadata-label">من تاريخ</label>
            <div class="relative group">
              <input ref="startDateRef" type="date" v-model="startDate" class="filter-input-v2 font-mono" style="padding-left: 2rem;" />
              <i @click="startDateRef?.showPicker?.()" class="fas fa-calendar absolute left-3 top-1/2 -translate-y-1/2 text-slate-300 group-hover:text-blue-500 transition-colors cursor-pointer hover:text-slate-500 text-xs"></i>
            </div>
          </div>

          <div class="space-y-1.5">
            <label class="metadata-label">إلى تاريخ</label>
            <div class="relative group">
              <input ref="endDateRef" type="date" v-model="endDate" class="filter-input-v2 font-mono" style="padding-left: 2rem;" />
              <i @click="endDateRef?.showPicker?.()" class="fas fa-calendar-check absolute left-3 top-1/2 -translate-y-1/2 text-slate-300 group-hover:text-blue-500 transition-colors cursor-pointer hover:text-slate-500 text-xs"></i>
            </div>
          </div>

          <div class="space-y-1.5">
            <label class="metadata-label">نوع السند المالي</label>
            <select v-model="voucherType" class="filter-input-v2 appearance-none font-bold">
              <option value="all">كل أنواع السندات</option>
              <option value="قبض">سندات القبض (وارد)</option>
              <option value="صرف">سندات الصرف (صادر)</option>
            </select>
          </div>

          <div class="space-y-1.5 group">
            <label class="metadata-label">البحث في السجلات</label>
            <div class="relative">
              <input v-model="search" type="text" class="filter-input-v2 font-bold" style="padding-right: 2rem;" placeholder="الوصف أو المرجع..." />
              <i class="fas fa-search absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 group-focus-within:text-blue-500 transition-colors text-[10px] pointer-events-none"></i>
            </div>
          </div>
        </div>
      </section>

      <!-- Financial Summary Grid -->
      <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
        <div v-for="stat in [
          { label: 'إجمالي المقبوضات', val: summary.totalIn, icon: 'fa-arrow-down', color: 'text-emerald-600', bg: 'bg-emerald-50' },
          { label: 'إجمالي المدفوعات', val: summary.totalOut, icon: 'fa-arrow-up', color: 'text-rose-600', bg: 'bg-rose-50' },
          { label: 'صافي التدفق (الرصيد)', val: summary.balance, icon: 'fa-scale-balanced', color: 'text-blue-600', bg: 'bg-blue-50' }
        ]" :key="stat.label" class="bg-white border border-slate-200 p-6 rounded-xl flex items-center justify-between group hover:border-slate-300 transition-all shadow-sm">
          <div>
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">{{ stat.label }}</p>
            <p :class="[stat.color, 'text-2xl font-bold font-mono tracking-tighter leading-none']">{{ formatCurrency(stat.val) }}</p>
          </div>
          <div :class="[stat.bg, stat.color]" class="w-12 h-12 rounded-lg flex items-center justify-center text-lg opacity-80 group-hover:opacity-100 transition-opacity shadow-inner border border-transparent group-hover:border-current/10">
            <i :class="['fas', stat.icon]"></i>
          </div>
        </div>
      </section>

      <!-- Movements Detailed Table: Professional Audit Grid -->
      <div class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm relative min-h-[400px]">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
           <h3 class="text-xs font-bold text-slate-900 uppercase tracking-widest flex items-center gap-2">
             <i class="fas fa-list-ul text-slate-400"></i>
             سجل الحركات المالية التفصيلي
           </h3>
           <div class="flex items-center gap-2">
              <span class="w-2 h-2 rounded-full bg-blue-500 animate-pulse"></span>
              <span class="text-[10px] font-bold text-slate-400 uppercase font-mono tracking-tighter">TOTAL RECORDS: {{ vouchers.length }}</span>
           </div>
        </div>

        <div class="overflow-x-auto">
          <table class="w-full text-right border-collapse">
            <thead>
              <tr class="bg-slate-50/50 border-b border-slate-200">
                <th class="px-6 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest w-40">تاريخ السند</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">الرقم المرجعي</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">النوع</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">الحساب المتأثر</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-left">قيمة السند</th>
                <th class="px-6 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">البيان / الوصف</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 font-medium text-xs">
              <!-- Skeleton loading state -->
              <template v-if="isLoading">
                <tr v-for="n in 6" :key="n" class="animate-pulse">
                  <td v-for="m in 6" :key="m" class="px-6 py-4"><div class="h-3 bg-slate-100 rounded w-full"></div></td>
                </tr>
              </template>
              
              <!-- Empty state -->
              <tr v-else-if="!vouchers.length" class="text-center">
                <td colspan="6" class="py-24 text-slate-300">
                  <i class="fas fa-receipt text-3xl mb-4 opacity-20"></i>
                  <p class="text-xs font-bold uppercase tracking-widest">لا توجد حركات مالية للفترة المحددة</p>
                </td>
              </tr>

              <!-- Data Rows -->
              <tr v-for="voucher in vouchers" :key="voucher.id" 
                  class="hover:bg-slate-50 transition-all group border-r-4 border-r-transparent" 
                  :class="voucher.type === 'receipt' ? 'hover:border-r-emerald-500' : 'hover:border-r-rose-500'">
                <td class="px-6 py-4 text-[10px] font-mono font-bold text-slate-400 group-hover:text-slate-900 transition-colors">
                  {{ formatDate(voucher.date) }}
                </td>
                <td class="px-4 py-4 font-bold text-slate-900 font-mono tracking-wider">
                  {{ voucher.reference || `#${voucher.id}` }}
                </td>
                <td class="px-4 py-4 text-center">
                  <span :class="[voucherTypeClass(voucher.type)]" class="px-2 py-0.5 rounded text-[9px] font-bold border flex items-center justify-center gap-1.5 w-fit mx-auto">
                    <i :class="[ (voucher.type === 'receipt' || voucher.type === 'قبض') ? 'fas fa-arrow-down' : 'fas fa-arrow-up', 'text-[8px]' ]"></i>
                    {{ voucherTypeLabel(voucher.type) }}
                  </span>
                </td>
                <td class="px-4 py-4">
                   <div class="flex items-center gap-3">
                      <div class="w-7 h-7 rounded bg-slate-50 flex items-center justify-center text-slate-300 border border-slate-100 group-hover:border-blue-200 transition-all shadow-inner"><i class="fas fa-university text-[10px]"></i></div>
                      <span class="text-xs font-bold text-slate-700 truncate max-w-[180px]">{{ voucher.account_name }}</span>
                   </div>
                </td>
                <td class="px-4 py-4 text-left font-mono">
                  <span class="text-sm font-bold tracking-tighter" :class=" (voucher.type === 'receipt' || voucher.type === 'قبض') ? 'text-emerald-600' : 'text-rose-600' ">
                    {{ (voucher.type === 'receipt' || voucher.type === 'قبض') ? '+' : '-' }}{{ formatCurrency(voucher.amount) }}
                  </span>
                </td>
                <td class="px-6 py-4 text-[11px] text-slate-400 italic font-medium truncate max-w-[250px]" :title="voucher.description">
                  {{ voucher.description || '—' }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
      </main>
    </div>
  </div>
</template>

<script setup>
// [بقاء المنطق البرمجي كما هو بنسبة 100%]
import { ref, computed, onMounted, watch } from 'vue';
import { useReportsStore } from '@/stores/reports/reportsStore';
import { Chart as ChartJS, Title, Tooltip, Legend, LineElement, CategoryScale, LinearScale, PointElement } from 'chart.js';
import { Line as LineChart } from 'vue-chartjs';

import BaseSkeleton from '@/components/ui/BaseSkeleton.vue';
import { useCompanyCurrency } from '@/composables/useCompanyCurrency';
import { getLocalDateISO } from '@/utils';
import { useVoucherStore } from '@/stores/voucher/voucherStore';
import { useBreadcrumb } from '@/composables/useBreadcrumb';
import { useBranchStore } from '@/stores/branch/branchStore';
import { useBranchIsolation } from '@/composables/useBranchIsolation';
import { useAuthStore } from '@/stores/auth/authStore';
import PageHeader from '@/components/PageHeader.vue';

// --- Logic Initialization (STRICTLY PRESERVED) ---
ChartJS.register(Title, Tooltip, Legend, LineElement, CategoryScale, LinearScale, PointElement);

const reportsStore = useReportsStore();
const { breadcrumb } = useBreadcrumb();
const branchStore = useBranchStore();
const authStore = useAuthStore();
const branchIsolation = useBranchIsolation();
const canSelectBranch = branchIsolation.isExempt;
const branches = computed(() => branchStore.branches);
const selectedBranch = computed({
  get: () => canSelectBranch.value
    ? branchStore.selectedBranchId ?? 'all'
    : String(authStore.user?.branch_id || 'all'),
  set: (val) => {
    if (canSelectBranch.value) branchStore.setSelectedBranch(val === 'all' ? null : val);
  }
});
const voucherStore = useVoucherStore();
const { formatCurrencyLocale, fetchSettings } = useCompanyCurrency();
const isLoading = ref(true);
const error = ref(null);

const startDate = ref(getLocalDateISO(new Date(new Date().setDate(1))));
const endDate = ref(getLocalDateISO());
const startDateRef = ref(null);
const endDateRef = ref(null);
const voucherType = ref('all'); 
const search = ref('');
let debounceTimer = null;
let isReportInitialized = false;

const vouchers = ref([]);

const summary = computed(() => {
  let totalIn = 0, totalOut = 0;
  for (const v of vouchers.value) {
    if (v.type === 'receipt' || v.type === 'قبض') totalIn += Number(v.amount) || 0;
    else if (v.type === 'payment' || v.type === 'صرف') totalOut += Number(v.amount) || 0;
  }
  return { totalIn, totalOut, balance: totalIn - totalOut };
});

const fetchReport = async () => {
  isLoading.value = true; error.value = null;
  const branchId = selectedBranch.value !== 'all' ? selectedBranch.value : null;

  try {
    const list = await voucherStore.fetchVouchersList({ 
      branchId,
      type: voucherType.value !== 'all' ? voucherType.value : undefined,
      dateFrom: startDate.value,
      dateTo: endDate.value,
      search: search.value || undefined
    });
    vouchers.value = Array.isArray(list) ? list : [];
  } catch (e) {
    error.value = e?.response?.data?.message || e?.message || 'حدث خطأ أثناء تحميل البيانات';
  } finally {
    isLoading.value = false;
  }
};

const formatCurrency = (v) => formatCurrencyLocale(v, 2);
const formatDate = (v) => v ? new Date(v).toLocaleDateString('en-US') : '';
const voucherTypeLabel = (t) => (t === 'receipt' || t === 'قبض') ? 'سند قبض' : 'سند صرف';
const voucherTypeClass = (t) => (t === 'receipt' || t === 'قبض') ? 'text-emerald-600 border-emerald-100 bg-emerald-50' : 'text-rose-600 border-rose-100 bg-rose-50';

watch([startDate, endDate, voucherType, search, selectedBranch], () => {
  if (!isReportInitialized) return;
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(fetchReport, 500);
});

onMounted(async () => {
  await branchStore.initialize();
  isReportInitialized = true;
  await Promise.all([fetchSettings(), fetchReport()]);
});
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
</style>