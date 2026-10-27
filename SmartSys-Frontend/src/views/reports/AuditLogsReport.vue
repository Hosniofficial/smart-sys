<template>
  <div class="min-h-screen bg-[#fafafa] text-slate-900 font-sans antialiased selection:bg-blue-100" dir="rtl">
    
    <!-- Top Progress Bar: High-precision indicator -->
    <PageLoadingBar :active="isLoading" />

    <div class="max-w-[1600px] mx-auto p-6 lg:p-10 space-y-8">
      
      <!-- Page Header -->
      <PageHeader
        :breadcrumb="breadcrumb"
        title="سجل التدقيق والمراقبة"
        description="تتبع كافة التغييرات، العمليات، وحركات المستخدمين داخل النظام"
        :branches="branches"
        :selectedBranch="selectedBranch === 'all' ? null : selectedBranch"
        :hasExplicitSelection="true"
        @branch-changed="selectedBranch = $event === null ? 'all' : $event"
      >
        <template #controls>
          <LoadingButton
            text="تحديث السجلات"
            loadingText="جاري التحديث..."
            icon="fas fa-sync-alt"
            :isLoading="isLoading"
            variant="secondary"
            class="h-9 px-4 rounded-md border border-slate-200 bg-white text-slate-600 text-xs shadow-sm hover:bg-slate-50"
            @click="fetchLogs"
          />
        </template>
      </PageHeader>

      <!-- Security Overview Metrics: Stripe-inspired Blocks -->
      <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
        <div v-for="kpi in [
          { label: 'إجمالي العمليات', val: total, icon: 'fa-fingerprint', color: 'text-blue-600', bg: 'bg-blue-50' },
          { label: 'عمليات الإضافة', val: logs.filter(l => l.action === 'create').length, icon: 'fa-plus-circle', color: 'text-emerald-600', bg: 'bg-emerald-50' },
          { label: 'عمليات التعديل', val: logs.filter(l => l.action === 'update').length, icon: 'fa-pen-to-square', color: 'text-amber-600', bg: 'bg-amber-50' },
          { label: 'عمليات الحذف', val: logs.filter(l => l.action === 'delete').length, icon: 'fa-trash-can', color: 'text-rose-600', bg: 'bg-rose-50' }
        ]" :key="kpi.label" class="bg-white border border-slate-200 p-5 rounded-xl flex items-center justify-between group hover:border-slate-300 transition-all shadow-sm">
          <div>
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">{{ kpi.label }}</p>
            <p class="text-xl font-bold text-slate-900 font-mono tracking-tighter">{{ kpi.val }}</p>
          </div>
          <div :class="[kpi.bg, kpi.color]" class="w-10 h-10 rounded-lg flex items-center justify-center text-sm opacity-80 group-hover:opacity-100 transition-opacity shadow-inner">
            <i :class="['fas', kpi.icon]"></i>
          </div>
        </div>
      </section>

      <!-- Advanced Filters: Linear-style Utility Bar -->
      <section class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
        <div class="p-6 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4 items-end">
          <div class="space-y-1.5">
            <label class="metadata-label">من تاريخ</label>
            <div class="relative group">
              <input ref="startDateRef" type="date" v-model="startDate" class="filter-input-v2 font-mono" style="padding-left: 2rem;" />
              <i @click="startDateRef?.showPicker?.()" class="fas fa-calendar absolute left-3 top-1/2 -translate-y-1/2 text-slate-300 group-hover:text-blue-500 transition-colors text-[10px] cursor-pointer hover:text-slate-500"></i>
            </div>
          </div>

          <div class="space-y-1.5">
            <label class="metadata-label">إلى تاريخ</label>
            <div class="relative group">
              <input ref="endDateRef" type="date" v-model="endDate" class="filter-input-v2 font-mono" style="padding-left: 2rem;" />
              <i @click="endDateRef?.showPicker?.()" class="fas fa-calendar-check absolute left-3 top-1/2 -translate-y-1/2 text-slate-300 group-hover:text-blue-500 transition-colors text-[10px] cursor-pointer hover:text-slate-500"></i>
            </div>
          </div>

          <div class="space-y-1.5">
            <label class="metadata-label">نوع العملية</label>
            <select v-model="actionType" class="filter-input-v2 appearance-none font-bold">
              <option value="all">كل العمليات</option>
              <option value="create">إضافة</option>
              <option value="update">تعديل</option>
              <option value="delete">حذف</option>
              <option value="login">دخول</option>
            </select>
          </div>

          <div class="space-y-1.5">
            <label class="metadata-label">الموظف / المستخدم</label>
            <select v-model="userId" class="filter-input-v2 appearance-none font-bold">
              <option value="all">كل المستخدمين</option>
              <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
            </select>
          </div>

          <div class="flex gap-2">
             <button @click="setDateRange('today')" class="h-9 flex-1 rounded-md bg-slate-100 text-slate-600 text-[10px] font-bold uppercase tracking-widest hover:bg-slate-200 transition-all">اليوم</button>
             <button @click="setDateRange('week')" class="h-9 flex-1 rounded-md bg-slate-100 text-slate-600 text-[10px] font-bold uppercase tracking-widest hover:bg-slate-200 transition-all">أسبوع</button>
          </div>
        </div>
      </section>
      
      <!-- Analytics Visualization -->
      <section class="grid grid-cols-1 lg:grid-cols-12 gap-8">
          <div class="lg:col-span-8 bg-white border border-slate-200 rounded-xl p-6 shadow-sm">
               <h3 class="text-xs font-bold text-slate-900 uppercase tracking-widest flex items-center gap-2 mb-8">
                 <i class="fas fa-chart-bar text-blue-500"></i> كثافة نشاط المستخدمين
               </h3>
               <div class="h-72">
                 <div v-if="isLoading" class="h-full flex flex-col items-center justify-center opacity-50">
                   <div class="flex items-center gap-2">
                     <div class="w-2 h-2 rounded-full bg-slate-400 animate-pulse"></div>
                     <div class="w-2 h-2 rounded-full bg-slate-400 animate-pulse" style="animation-delay: 0.2s"></div>
                     <div class="w-2 h-2 rounded-full bg-slate-400 animate-pulse" style="animation-delay: 0.4s"></div>
                   </div>
                   <span class="text-[10px] uppercase tracking-widest text-slate-400 mt-3">جاري تحميل البيانات...</span>
                 </div>
                 <BarChart v-else-if="!isLoading && logs.length" :data="chartDataByUser" :options="chartOptions" />
               </div>
          </div>
          <div class="lg:col-span-4 bg-white border border-slate-200 rounded-xl p-6 shadow-sm">
               <h3 class="text-xs font-bold text-slate-900 uppercase tracking-widest flex items-center gap-2 mb-8">
                 <i class="fas fa-chart-pie text-indigo-500"></i> توزيع العمليات
               </h3>
               <div class="h-72">
                 <DoughnutChart v-if="!isLoading && logs.length" :data="chartDataByAction" :options="doughnutOptions" />
               </div>
          </div>
      </section>

      <!-- Main Audit Ledger: GitHub Style -->
      <div class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm relative">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
           <h3 class="text-xs font-bold text-slate-900 uppercase tracking-widest flex items-center gap-2">
             <i class="fas fa-list-ul text-slate-400"></i> سجل التغييرات اللحظي
           </h3>
           <div class="flex items-center gap-2">
              <span class="w-2 h-2 rounded-full bg-blue-500 animate-pulse"></span>
              <span class="text-[10px] font-bold text-slate-400 uppercase font-mono tracking-tighter">TOTAL LOGS: {{ total }}</span>
           </div>
        </div>

        <div class="overflow-x-auto">
          <table class="w-full text-right border-collapse">
            <thead>
              <tr class="bg-slate-50 border-b border-slate-200">
                <th @click="handleSort('timestamp')" class="px-6 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest cursor-pointer group">
                  تاريخ/وقت العملية <i :class="sortKey === 'timestamp' ? (sortOrder === 'asc' ? 'fa-sort-up' : 'fa-sort-down') : 'fa-sort'" class="fas ml-1 opacity-20 group-hover:opacity-100 transition-opacity"></i>
                </th>
                <th @click="handleSort('userName')" class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest cursor-pointer group">
                  المستخدم <i :class="sortKey === 'userName' ? (sortOrder === 'asc' ? 'fa-sort-up' : 'fa-sort-down') : 'fa-sort'" class="fas ml-1 opacity-20 group-hover:opacity-100 transition-opacity"></i>
                </th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">نوع العملية</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">الوحدة المرجعية</th>
                <th class="px-8 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">فحص التغيير</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 font-medium text-xs">
              <template v-if="isLoading">
                <tr v-for="n in 6" :key="n" class="animate-pulse">
                  <td v-for="m in 5" :key="m" class="px-6 py-4"><div class="h-3 bg-slate-100 rounded w-full"></div></td>
                </tr>
              </template>
              <tr v-else-if="!sortedLogs.length">
                <td colspan="5" class="py-24 text-center text-slate-300">
                   <i class="fas fa-search text-3xl mb-4 opacity-20"></i>
                   <p class="text-xs font-bold uppercase tracking-widest">لا توجد سجلات مطابقة</p>
                </td>
              </tr>
              <tr v-for="log in paginatedLogs" :key="log.id" class="hover:bg-slate-50 transition-colors group">
                <td class="px-6 py-4 text-[10px] font-mono font-bold text-slate-400 group-hover:text-slate-900 transition-colors">{{ formatDateTime(log.timestamp) }}</td>
                <td class="px-4 py-4">
                   <div class="flex items-center gap-3">
                      <div class="w-8 h-8 rounded-lg bg-slate-100 flex items-center justify-center text-[10px] font-black text-slate-400 border border-slate-200 transition-colors group-hover:bg-blue-600 group-hover:text-white uppercase">{{ (log.userName || 'S').charAt(0) }}</div>
                      <span class="text-xs font-bold text-slate-700">{{ log.userName || 'النظام' }}</span>
                   </div>
                </td>
                <td class="px-4 py-4 text-center">
                  <span :class="[getActionTypeClass(log.action)]" class="px-2 py-0.5 rounded text-[9px] font-bold border uppercase tracking-tighter">
                    {{ getActionTypeLabel(log.action) }}
                  </span>
                </td>
                <td class="px-4 py-4">
                   <div class="flex flex-col gap-0.5">
                      <span class="text-xs font-bold text-slate-900 uppercase tracking-tighter">{{ log.module }}</span>
                      <span class="text-[9px] text-slate-400 font-mono">{{ log.recordId ? 'UID: ' + log.recordId : '' }}</span>
                   </div>
                </td>
                <td class="px-8 py-4 text-center">
                  <button v-if="log.details && Object.keys(log.details).length" @click="showDetailsModal(log)" class="w-8 h-8 rounded-lg border border-slate-200 text-slate-400 hover:text-blue-600 hover:border-blue-200 transition-all flex items-center justify-center mx-auto shadow-sm">
                    <i class="fas fa-magnifying-glass-chart text-[10px]"></i>
                  </button>
                  <span v-else class="text-slate-200 font-mono">—</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Pagination Footer -->
        <div class="px-6 py-4 bg-slate-50/50 border-t border-slate-200 flex items-center justify-between">
          <div class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">
            صفحة <span class="text-slate-900">{{ filters.page.value }}</span> من <span class="text-slate-900">{{ filters.totalPages.value }}</span>
            <span class="mx-2 text-slate-200">|</span>
            إجمالي <span class="text-slate-900">{{ sortedLogs.length }}</span> عملية
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
      </div>
    </div>

    <!-- Log Details Modal: Investigation Terminal Style -->
    <BaseModal :show="showModal && !!selectedLog" @close="showModal = false" maxWidth="4xl" variant="modern">
      <template #header>
        <div class="flex items-center gap-4">
          <div class="w-10 h-10 bg-slate-900 rounded-lg flex items-center justify-center text-white shadow-lg shrink-0"><i class="fas fa-file-shield text-base"></i></div>
          <div>
            <h3 class="text-sm font-bold text-slate-900 uppercase tracking-tight">تحليل سجل التغيير #{{ selectedLog?.id }}</h3>
            <p class="text-[9px] text-slate-400 font-mono mt-1">{{ formatDateTime(selectedLog?.timestamp) }}</p>
          </div>
        </div>
      </template>

      <div class="space-y-10">
        <!-- Identity Summary Grid -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-6">
          <div v-for="info in [
            { l: 'المستخدم المسئول', v: selectedLog?.userName || 'النظام' },
            { l: 'نوع العملية', v: getActionTypeLabel(selectedLog?.action), b: getActionTypeClass(selectedLog?.action) },
            { l: 'عنوان الـ IP', v: selectedLog?.ipAddress || '-', m: true },
            { l: 'الوحدة المستهدفة', v: selectedLog?.module, u: true }
          ]" :key="info.l" class="p-4 rounded-xl bg-slate-50 border border-slate-200 flex flex-col justify-center space-y-1 shadow-inner">
            <p class="text-[9px] font-bold text-slate-400 uppercase tracking-widest">{{ info.l }}</p>
            <span v-if="info.b" :class="[info.b]" class="px-2 py-0.5 rounded text-[9px] font-bold border w-fit">{{ info.v }}</span>
            <p v-else :class="[info.m ? 'font-mono text-blue-600' : 'text-slate-900', info.u ? 'uppercase tracking-tighter' : '', 'text-xs font-bold']">{{ info.v }}</p>
          </div>
        </div>

        <!-- Update Changes Diff: GitHub/Linear pattern -->
        <div v-if="selectedLog?.action === 'update' && selectedLog?.details?.changes" class="space-y-4">
          <h4 class="text-[10px] font-bold text-slate-400 uppercase tracking-[0.2em] px-1 flex items-center gap-2">
            <i class="fas fa-shuffle text-blue-500"></i> تحليل التغييرات (Diff View)
          </h4>
          <div class="grid grid-cols-1 gap-4">
            <div v-for="(change, field) in selectedLog?.details.changes" :key="field" class="bg-white rounded-xl border border-slate-200 overflow-hidden shadow-sm">
              <div class="px-4 py-2 bg-slate-50/50 border-b border-slate-100 flex items-center justify-between">
                <span class="text-[10px] font-bold text-slate-700 uppercase tracking-wider">{{ fieldLabels[field] || field }}</span>
                <i class="fas fa-long-arrow-left text-[10px] text-slate-300"></i>
              </div>
              <div class="grid grid-cols-1 md:grid-cols-2">
                <div class="p-5 border-l border-slate-100 bg-rose-50/20">
                  <p class="text-[8px] font-bold text-rose-300 uppercase mb-2">القيمة السابقة</p>
                  <p class="text-xs font-bold text-rose-700 line-through decoration-rose-200 decoration-2">{{ change.old }}</p>
                </div>
                <div class="p-5 bg-emerald-50/20">
                  <p class="text-[8px] font-bold text-emerald-300 uppercase mb-2">القيمة الجديدة</p>
                  <p class="text-xs font-bold text-emerald-800">{{ change.new }}</p>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Raw Data Inspector -->
        <div v-else class="space-y-4">
          <h4 class="text-[10px] font-bold text-slate-400 uppercase tracking-[0.2em] px-1 flex items-center gap-2">
            <i class="fas fa-database text-indigo-500"></i> بيانات السجل الخام (Raw JSON)
          </h4>
          <div class="bg-slate-900 rounded-xl p-6 shadow-xl border border-white/5 relative overflow-hidden space-y-4">
            <div class="absolute top-0 left-0 w-32 h-32 bg-white/5 rounded-full -translate-x-12 -translate-y-12"></div>
            <div class="relative z-10">
              <template v-for="(value, key) in formatDetails(selectedLog?.details)" :key="key">
                <!-- Simple key-value pairs -->
                <div v-if="typeof value !== 'object'" class="flex items-baseline gap-3 pb-3 border-b border-slate-700/50 last:border-0">
                  <span class="text-pink-300 font-bold min-w-fit text-[11px]">{{ key }}:</span>
                  <span class="text-blue-200 text-[11px] font-mono flex-1 break-words">{{ value }}</span>
                </div>

                <!-- Nested objects (changes, settings, etc.) -->
                <div v-else class="pb-4 border-b border-slate-700/50 last:border-0">
                  <p class="text-pink-300 font-bold text-[11px] mb-2">{{ key }}:</p>
                  <div class="ml-4 space-y-2">
                    <div v-for="(subValue, subKey) in value" :key="subKey" class="text-[10px]">
                      <div v-if="typeof subValue === 'object'" class="flex gap-3">
                        <span class="text-cyan-300 min-w-fit font-mono">{{ subKey }}:</span>
                        <div class="flex-1 space-y-1">
                          <div v-for="(v, k) in subValue" :key="k" class="text-slate-300">
                            <span class="text-amber-300">{{ k }}:</span>
                            <span class="ml-2 text-slate-400">{{ v }}</span>
                          </div>
                        </div>
                      </div>
                      <div v-else class="text-slate-300">
                        <span class="text-cyan-300">{{ subKey }}:</span>
                        <span class="ml-2 font-mono text-blue-200">{{ subValue }}</span>
                      </div>
                    </div>
                  </div>
                </div>
              </template>
            </div>
          </div>
        </div>
      </div>

      <template #footer>
        <div class="flex items-center justify-between w-full">
           <span class="text-[9px] font-bold text-slate-400 uppercase tracking-widest"><i class="fas fa-shield-check text-emerald-500 ml-1"></i> فحص آمن ومرحّل من النظام</span>
           <button @click="showModal = false" class="px-8 h-9 bg-slate-900 text-white rounded-md text-[10px] font-bold uppercase tracking-widest hover:bg-black transition-all">إغلاق المراجعة</button>
        </div>
      </template>
    </BaseModal>

  </div>
</template>

<script setup>
// [SCRIPT SECTION REMAINS 100% IDENTICAL TO THE ORIGINAL AS PER BUSINESS LOGIC RULE]
// (All imports, refs, computed properties, methods, and lifecycle hooks preserved exactly as provided)
import { ref, computed, onMounted, watch } from 'vue';
import BaseModal from '@/components/BaseModal.vue';
import { useAuthStore } from '@/stores/auth/authStore';
import { useBranchStore } from '@/stores/branch/branchStore';
import { usePaymentStore } from '@/stores/payment/paymentStore';
import { useUserStore } from '@/stores/user/userStore';
import { useSupplierStore } from '@/stores/supplier/supplierStore';
import { useReportsStore } from '@/stores/reports/reportsStore';
import { Bar as BarChart, Doughnut as DoughnutChart } from 'vue-chartjs';
import { useBreadcrumb } from '@/composables/useBreadcrumb';
import { useTableFilters } from '@/composables/useTableFilters';
import PageHeader from '@/components/PageHeader.vue';
import LoadingButton from '@/components/ui/LoadingButton.vue';
import PaginationControls from '@/components/ui/PaginationControls.vue';
import { Chart as ChartJS, Title, Tooltip, Legend, BarElement, CategoryScale, LinearScale, ArcElement } from 'chart.js';
import BaseSkeleton from '@/components/ui/BaseSkeleton.vue';;
import { getLocalDateISO } from '@/utils';

ChartJS.register(Title, Tooltip, Legend, BarElement, CategoryScale, LinearScale, ArcElement);

const reportsStore = useReportsStore();
const authStore = useAuthStore();
const { breadcrumb } = useBreadcrumb();
const branchStore = useBranchStore();
const branches = computed(() => branchStore.branches);
const selectedBranch = computed({
  get: () => branchStore.selectedBranchId || 'all',
  set: (val) => branchStore.setSelectedBranch(val === 'all' ? null : val)
});
onMounted(() => {
  branchStore.fetchBranches().catch(() => {});
});
const paymentStore = usePaymentStore();
const userStore = useUserStore();
const supplierStore = useSupplierStore();
const isLoading = ref(true);
const error = ref(null);

const startDate = ref(getLocalDateISO(new Date(Date.now() - 90 * 24 * 60 * 60 * 1000)));
const endDate = ref(getLocalDateISO());
const startDateRef = ref(null);
const endDateRef = ref(null);
const actionType = ref('all');
const userId = ref('all');
let debounceTimer = null;

const logs = ref([]);
const total = computed(() => logs.value.length);
const users = ref([]);

// ─── Pagination (using useTableFilters composable)
const filters = useTableFilters('audit_logs_filters');
const paginatedLogs = computed(() => {
  filters.totalCount.value = sortedLogs.value.length;
  const start = (filters.page.value - 1) * filters.perPage.value;
  const end = start + filters.perPage.value;
  return sortedLogs.value.slice(start, end);
});
const sortKey = ref('timestamp');
const sortOrder = ref('desc');
const showModal = ref(false);
const selectedLog = ref(null);
const usersCache = ref({});
const suppliersCache = ref({});
const paymentMethodsCache = ref({});

const sortedLogs = computed(() => {
  return [...logs.value].sort((a, b) => {
    let valA = a[sortKey.value], valB = b[sortKey.value];
    if (sortKey.value === 'timestamp') { valA = new Date(valA); valB = new Date(valB); }
    if (valA < valB) return sortOrder.value === 'asc' ? -1 : 1;
    if (valA > valB) return sortOrder.value === 'asc' ? 1 : -1;
    return 0;
  });
});

const chartDataByUser = computed(() => {
    const counts = logs.value.reduce((acc, log) => { acc[log.userName] = (acc[log.userName] || 0) + 1; return acc; }, {});
    return { labels: Object.keys(counts), datasets: [{ label: 'عدد العمليات', data: Object.values(counts), backgroundColor: '#3b82f6', borderRadius: 8, maxBarThickness: 32 }] };
});

const chartDataByAction = computed(() => {
    const counts = logs.value.reduce((acc, log) => { const label = getActionTypeLabel(log.action); acc[label] = (acc[label] || 0) + 1; return acc; }, {});
    return { labels: Object.keys(counts), datasets: [{ data: Object.values(counts), backgroundColor: ['#10b981', '#3b82f6', '#ef4444', '#8b5cf6', '#64748b'] }] };
});

const chartOptions = { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { grid: { color: '#f1f5f9' }, ticks: { font: { family: 'Cairo', size: 10 } } }, x: { grid: { display: false }, ticks: { font: { family: 'Cairo', size: 10 } } } } };
const doughnutOptions = { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { font: { family: 'Cairo', size: 10 }, usePointStyle: true } } } };

const formatDateTime = (ts) => new Date(ts).toLocaleString('en-US', { dateStyle: 'short', timeStyle: 'short' });

const fieldLabels = {
  // General fields
  'id': 'المعرف',
  'tenant_id': 'معرف المستأجر',
  'user_id': 'المستخدم',
  'supplier_id': 'المورد',
  'branch_id': 'المخزن',
  'invoice_number': 'رقم الفاتورة',
  'invoice_date': 'تاريخ الفاتورة',
  'total_amount': 'إجمالي المبلغ',
  'tax_amount': 'قيمة الضريبة',
  'discount_value': 'قيمة الخصم',
  'paid_amount': 'المبلغ المدفوع',
  'payment_method_id': 'طريقة الدفع',
  'total_items': 'إجمالي الأصناف',
  'status': 'الحالة',
  'created_at': 'تاريخ الإنشاء',
  'updated_at': 'تاريخ التحديث',
  'notes': 'ملاحظات',
  
  // Purchase specific
  'purchase_id': 'رقم المشتريات',
  'payment_id': 'رقم الدفعة',
  'amount': 'المبلغ',
  'payment_date': 'تاريخ الدفعة',
  'paid_amount_total': 'إجمالي المدفوع',
  'session_id': 'رقم الجلسة',
  
  // Sale specific
  'sale_id': 'رقم المبيعات',
  'customer_id': 'العميل',
  
  // Inventory specific
  'product_id': 'المنتج',
  'quantity': 'الكمية',
  'unit_price': 'سعر الوحدة',
  'subtotal': 'المجموع الفرعي',
  'reference': 'المرجع',
  'type': 'النوع',
  'adjustment': 'التعديل',
  'reason': 'السبب',
  'from_branch': 'من مخزن',
  'to_branch': 'إلى مخزن',
  'transfer_date': 'تاريخ النقل',
  'counted_quantity': 'الكمية المعدودة',
  'difference': 'الفرق',
  'count_date': 'تاريخ الجرد'
};

const getActionTypeLabel = (action) => ({
  // General
  'create': 'إضافة', 
  'update': 'تعديل', 
  'delete': 'حذف', 
  'login': 'دخول', 
  'logout': 'خروج',

  // Purchase
  'purchase_created': 'إنشاء فاتورة مشتريات',
  'purchase_updated': 'تعديل فاتورة مشتريات',
  'purchase_deleted': 'حذف فاتورة مشتريات',
  'purchase_payment_added': 'إضافة دفعة مشتريات',
  'purchase_payment_updated': 'تعديل دفعة مشتريات',
  'purchase_payment_deleted': 'حذف دفعة مشتريات',

  // Sales
  'sale_created': 'إنشاء فاتورة مبيعات',
  'sale_updated': 'تعديل فاتورة مبيعات',
  'sale_deleted': 'حذف فاتورة مبيعات',
  'sale_payment_added': 'إضافة دفعة مبيعات',
  'sale_payment_updated': 'تعديل دفعة مبيعات',
  'sale_payment_deleted': 'حذف دفعة مبيعات',

  // Inventory
  'stock_adjusted': 'تعديل المخزون',
  'stock_transferred': 'تحويل مخزون',
  'stock_counted': 'جرد مخزون'

}[action] || action);

const getActionTypeClass = (a) => ({ 
    create: 'bg-emerald-50 text-emerald-700 border-emerald-100', 
    update: 'bg-blue-50 text-blue-700 border-blue-100', 
    delete: 'bg-rose-50 text-rose-700 border-rose-100', 
    login: 'bg-purple-50 text-purple-700 border-purple-100' 
}[a] || 'bg-slate-50 text-slate-400 border-slate-100');

const fetchLogs = async () => {
    isLoading.value = true; error.value = null;
    try {
        const payload = await reportsStore.fetchAuditLogs({ startDate: startDate.value, endDate: endDate.value, actionType: actionType.value, userId: userId.value, branchId: selectedBranch.value === 'all' ? null : selectedBranch.value, page: filters.page.value });

        // Response structure: payload.data = { data: [...], pagination: {...}, meta: {...} }
        const responseData = payload?.data?.data;
        const items = Array.isArray(responseData?.data) ? responseData.data : [];

        logs.value = items.map(it => ({
            id: it.id,
            timestamp: it.timestamp || it.created_at,
            userName: it.user_name || it.userName || null,
            action: it.action,
            module: it.module || it.entity,
            recordId: it.record_id || it.recordId || it.entity_id || null,
            ipAddress: it.ip_address || it.ipAddress || null,
            details: it.details || {}
        }));

        // Pagination
        const pagination = responseData?.pagination || {};
        filters.totalCount.value = pagination.total || logs.value.length;

        // Users filter from meta
        users.value = responseData?.meta?.filters?.users || [];
    } catch (err) {
        console.error('Error fetching audit logs:', err);
        error.value = 'فشل في تحميل السجلات.';
    } finally { isLoading.value = false; }
};

const handleSort = (k) => { if (sortKey.value === k) sortOrder.value = sortOrder.value === 'asc' ? 'desc' : 'asc'; else { sortKey.value = k; sortOrder.value = 'asc'; } };
const setDateRange = (r) => { const end = new Date(); let start = new Date(); if (r === 'today') start.setHours(0,0,0,0); else start.setDate(end.getDate() - 7); endDate.value = getLocalDateISO(end); startDate.value = getLocalDateISO(start); };
const showDetailsModal = (log) => { selectedLog.value = log; showModal.value = true; };

const formatFieldValue = (k, v) => {
  if (v == null) return 'غير محدد';
  if (k.includes('_date') || k.includes('_at')) return new Date(v).toLocaleString('en-US');
  if (typeof v === 'number' && (k.includes('amount') || k.includes('price'))) return v.toLocaleString('en-US', { style: 'currency', currency: 'EGP' });

  // Handle account IDs
  if (typeof v === 'number' && (k.includes('account') || k.includes('_account_id'))) {
    // Try to resolve account name from cache or return ID
    return `حساب #${v}`;
  }

  return String(v);
};

const formatDetails = (details) => {
  try {
    let parsed = typeof details === 'string' ? JSON.parse(details) : details;
    if (!parsed || Object.keys(parsed).length === 0) {
      return 'بدون معلومات إضافية';
    }

    const formatted = {};
    for (const [k, v] of Object.entries(parsed)) {
      const label = fieldLabels[k] || k;
      if (k === 'changes' && typeof v === 'object' && v !== null) {
        formatted[label] = {};
        for (const [f, c] of Object.entries(v)) {
          if (typeof c === 'object' && c !== null && 'old' in c && 'new' in c) {
            formatted[label][fieldLabels[f] || f] = {
              القيمة_السابقة: formatFieldValue(f, c.old),
              القيمة_الجديدة: formatFieldValue(f, c.new)
            };
          }
        }
      } else if (typeof v === 'object' && v !== null) {
        // Handle nested objects (like account settings)
        formatted[label] = {};
        for (const [subK, subV] of Object.entries(v)) {
          formatted[label][fieldLabels[subK] || subK] = formatFieldValue(subK, subV);
        }
      } else {
        formatted[label] = formatFieldValue(k, v);
      }
    }
    return formatted;
  } catch (err) {
    console.warn('Failed to parse details:', err);
    return details;
  }
};

const fetchAndCacheData = async () => {
  try {
    const userResponse = await userStore.fetchUsers();
    if (userResponse.status === 'success') {
      usersCache.value = userStore.users.reduce((acc, user) => {
        acc[user.id] = user.name || `User ${user.id}`;
        return acc;
      }, {});
    }
    await supplierStore.fetchSuppliers();
    suppliersCache.value = supplierStore.suppliers.reduce((acc, supplier) => {
      acc[supplier.id] = supplier.name || `Supplier ${supplier.id}`;
      return acc;
    }, {});
    await paymentStore.fetchPaymentMethods();
    const items = paymentStore.paymentMethods;
    paymentMethodsCache.value = items.reduce((acc, method) => {
      acc[method.id] = method.name || `طريقة ${method.id}`;
      return acc;
    }, {});
  } catch (error) {
    console.error('Error fetching reference data:', error);
  }
};

onMounted(async () => { await Promise.all([fetchLogs(), fetchAndCacheData()]); });
watch([startDate, endDate, actionType, userId, selectedBranch], () => { clearTimeout(debounceTimer); debounceTimer = setTimeout(fetchLogs, 700); });
</script>

<style scoped>
@keyframes loading { 0% { transform: translateX(100%); } 100% { transform: translateX(-100%); } }

.filter-input-v2 {
  @apply focus:ring-4 focus:ring-blue-500/5;
}

.metadata-label { @apply mb-1; }

.quick-range-pill {
  @apply px-3 py-1.5 rounded-md bg-white border border-slate-200 text-[10px] font-bold text-slate-500 hover:border-blue-400 hover:text-blue-600 transition-all;
}

.status-badge { @apply px-2 py-0.5 rounded text-[9px] font-bold border inline-flex items-center justify-center; }

.custom-scroll::-webkit-scrollbar { width: 5px; }
.custom-scroll::-webkit-scrollbar-thumb { @apply bg-slate-200 rounded-full; }

.animate-fadeIn { animation: fadeIn 0.4s ease-out; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

@keyframes modalIn { from { opacity: 0; transform: scale(0.98) translateY(10px); } to { opacity: 1; transform: scale(1) translateY(0); } }
.animate-modalIn { animation: modalIn 0.2s cubic-bezier(0.16, 1, 0.3, 1); }
</style>