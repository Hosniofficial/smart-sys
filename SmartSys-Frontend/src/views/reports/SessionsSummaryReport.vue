<template>
  <div class="min-h-screen bg-[#fafafa] text-slate-900 font-sans antialiased selection:bg-blue-100" dir="rtl">
    
    <!-- Top Progress Bar: High-precision indicator -->
    <div v-if="isLoading" class="fixed top-0 left-0 right-0 h-0.5 bg-blue-600/10 z-[110]">
      <div class="h-full bg-blue-600 animate-[loading_2s_ease-in-out_infinite] w-1/3"></div>
    </div>

    <div class="max-w-[1600px] mx-auto p-6 lg:p-10 space-y-8">
      
      <!-- Page Header -->
      <PageHeader
        :breadcrumb="breadcrumb"
        title="تقرير ملخص الجلسات"
        description="تحليل أداء الورديات، التدفقات النقدية، وفروقات الخزينة اليومية"
        :branches="[]"
        :selectedBranch="null"
      >
        <template #controls>
          <button @click="fetchSessions" :disabled="isLoading" class="h-9 px-4 rounded-md border border-slate-200 bg-white text-slate-600 text-xs font-bold hover:bg-slate-50 transition-all flex items-center gap-2 shadow-sm">
            <i class="fas fa-sync-alt text-[10px]" :class="{'animate-spin': isLoading}"></i> تحديث البيانات
          </button>
        </template>
      </PageHeader>

      <!-- Quick Analytics KPIs: Metric Grid -->
      <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
        <div v-for="kpi in [
          { label: 'إجمالي الجلسات', val: totalItems, icon: 'fa-layer-group', color: 'text-blue-600', bg: 'bg-blue-50' },
          { label: 'المبيعات المتوقعة', val: formatCurrency(sessions.reduce((s, v) => s + (v.expected_cash || 0), 0)), icon: 'fa-cash-register', color: 'text-emerald-600', bg: 'bg-emerald-50' },
          { label: 'صافي الفروقات', val: formatCurrency(sessions.reduce((s, v) => s + (v.variance_amount || 0), 0)), icon: 'fa-scale-unbalanced', color: 'text-rose-600', bg: 'bg-rose-50' },
          { label: 'جلسات نشطة', val: sessions.filter(s => s.status === 'open').length, icon: 'fa-clock', color: 'text-amber-600', bg: 'bg-amber-50' }
        ]" :key="kpi.label" class="bg-white border border-slate-200 p-5 rounded-xl flex items-center justify-between group hover:border-slate-300 transition-all shadow-sm">
          <div>
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">{{ kpi.label }}</p>
            <p class="text-xl font-bold text-slate-900 font-mono tracking-tighter">{{ kpi.val }}</p>
          </div>
          <div :class="[kpi.bg, kpi.color]" class="w-10 h-10 rounded-lg flex items-center justify-center text-sm opacity-80 group-hover:opacity-100 transition-opacity">
            <i :class="['fas', kpi.icon]"></i>
          </div>
        </div>
      </section>

      <!-- Advanced Filters: Linear-style Utility Bar -->
      <section class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
        <div class="p-6 space-y-6">
          <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div class="space-y-1.5 group">
              <label class="metadata-label">من تاريخ</label>
              <div class="relative">
                <input ref="fromDateRef" type="date" v-model="fromDate" class="filter-input-v3 font-mono" style="padding-left: 2rem;" />
                <i @click="fromDateRef?.showPicker?.()" class="fas fa-calendar absolute left-3 top-1/2 -translate-y-1/2 text-slate-300 group-hover:text-blue-500 transition-colors text-[10px] cursor-pointer hover:text-blue-600"></i>
              </div>
            </div>
            <div class="space-y-1.5 group">
              <label class="metadata-label">إلى تاريخ</label>
              <div class="relative">
                <input ref="toDateRef" type="date" v-model="toDate" class="filter-input-v3 font-mono" style="padding-left: 2rem;" />
                <i @click="toDateRef?.showPicker?.()" class="fas fa-calendar-check absolute left-3 top-1/2 -translate-y-1/2 text-slate-300 group-hover:text-blue-500 transition-colors text-[10px] cursor-pointer hover:text-blue-600"></i>
              </div>
            </div>
            <div class="space-y-1.5">
              <label class="metadata-label">الفرع / المستودع</label>
              <select v-model="branchId" class="filter-input-v3 appearance-none font-bold">
                <option value="">كل الفروع</option>
                <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
              </select>
            </div>
            <div class="space-y-1.5">
              <label class="metadata-label">الموظف (الكاشير)</label>
              <select v-model="cashierId" class="filter-input-v3 appearance-none font-bold">
                <option value="">كل الموظفين</option>
                <option v-for="c in cashiers" :key="c.id" :value="c.id">{{ c.name }}</option>
              </select>
            </div>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end pt-4 border-t border-slate-50">
            <div class="space-y-1.5">
              <label class="metadata-label">جهاز نقطة البيع</label>
              <select v-model="terminalId" class="filter-input-v3 appearance-none font-bold">
                <option value="">كل الأجهزة</option>
                <option v-for="t in terminals" :key="t.id" :value="t.id">{{ t.name }}</option>
              </select>
            </div>
            <div class="space-y-1.5">
              <label class="metadata-label">حالة المطابقة</label>
              <select v-model="hasVariance" class="filter-input-v3 appearance-none font-bold">
                <option value="">الكل</option>
                <option value="true">يوجد عجز أو زيادة</option>
                <option value="false">مطابق تماماً</option>
              </select>
            </div>
            <div class="flex items-center gap-2">
              <button @click="resetFilters" class="h-9 px-4 rounded-md bg-slate-100 text-slate-600 text-[11px] font-bold hover:bg-slate-200 transition-all uppercase tracking-widest">مسح</button>
              <button @click="fetchSessions" class="h-9 flex-1 bg-slate-900 text-white rounded-md text-[11px] font-bold uppercase tracking-widest hover:bg-black transition-all shadow-sm">تطبيق الفلترة</button>
            </div>
          </div>
        </div>
      </section>

      <!-- Main Data Table: Professional Audit Grid -->
      <div class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm relative">
        <div class="overflow-x-auto">
          <table class="w-full text-right border-collapse">
            <thead>
              <tr class="bg-slate-50/50 border-b border-slate-200">
                <th class="px-6 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">التوقيت والمدة</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">الموقع والمحطة</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">الموظف</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">الافتتاحي</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">المتوقع</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">الفعلي</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">الفرق</th>
                <th class="px-6 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">الحالة</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 font-medium text-xs">
              <!-- Loading Skeleton -->
              <template v-if="isLoading && !isExporting">
                <tr v-for="n in 6" :key="n" class="animate-pulse">
                  <td v-for="m in 8" :key="m" class="px-6 py-4"><div class="h-3 bg-slate-100 rounded w-full"></div></td>
                </tr>
              </template>
              
              <!-- Data Rows -->
              <tr v-for="session in paginatedSessions" :key="session.id" class="hover:bg-blue-50/20 transition-all group">
                <td class="px-6 py-4">
                  <div class="flex flex-col gap-1">
                    <span class="font-bold text-slate-900">{{ formatDate(session.start_time) }}</span>
                    <span class="text-[9px] font-bold text-slate-400 uppercase font-mono tracking-tighter">
                      DURATION: {{ formatDuration(session.start_time, session.end_time) }}
                    </span>
                  </div>
                </td>
                <td class="px-4 py-4">
                  <div class="flex flex-col gap-1">
                    <span class="text-slate-700 font-bold">{{ session.branch_name || 'الفرع الرئيسي' }}</span>
                    <span class="text-[10px] text-blue-500 font-bold uppercase tracking-widest flex items-center gap-1.5">
                      <i class="fas fa-desktop opacity-30"></i> {{ session.terminal_name || '-' }}
                    </span>
                  </div>
                </td>
                <td class="px-4 py-4">
                  <div class="font-bold text-slate-800">{{ session.cashier_name || '-' }}</div>
                  <div v-if="session.closed_by_name" class="text-[9px] text-slate-400 font-medium mt-1">بإشراف: {{ session.closed_by_name }}</div>
                </td>
                <td class="px-4 py-4 text-center font-mono font-bold text-slate-400">{{ formatCurrency(session.opening_cash_amount) }}</td>
                <td class="px-4 py-4 text-center font-mono font-bold text-slate-600">{{ formatCurrency(session.expected_cash) }}</td>
                <td class="px-4 py-4 text-center font-mono font-bold text-slate-900 bg-slate-50/50 group-hover:bg-white transition-colors">{{ formatCurrency(session.actual_cash) }}</td>
                <td class="px-4 py-4 text-center">
                  <div :class="[
                    session.variance_amount === 0 ? 'text-slate-300' :
                    session.variance_amount < 0 ? 'text-rose-600' : 'text-emerald-600'
                  ]" class="font-bold font-mono tracking-tighter text-sm">
                    {{ formatCurrency(session.variance_amount) }}
                  </div>
                </td>
                <td class="px-6 py-4">
                  <div class="flex items-center justify-center gap-3">
                    <span :class="[session.status === 'closed' ? 'bg-slate-100 text-slate-600 border-slate-200' : 'bg-amber-50 text-amber-600 border-amber-200']"
                          class="px-2 py-0.5 rounded text-[9px] font-bold border uppercase tracking-tighter">
                      {{ session.status === 'closed' ? 'مؤرشفة' : 'نشطة' }}
                    </span>
                    <div class="flex items-center gap-1.5">
                      <button @click="viewSessionDetails(session)" class="w-8 h-8 rounded-lg border border-slate-200 text-slate-400 hover:text-blue-600 hover:border-blue-200 transition-all flex items-center justify-center"><i class="fas fa-eye text-[10px]"></i></button>
                      <button @click="exportSession(session)" :disabled="isExporting" class="w-8 h-8 rounded-lg border border-slate-200 text-slate-400 hover:text-emerald-600 hover:border-emerald-200 transition-all flex items-center justify-center"><i class="fas fa-file-export text-[10px]"></i></button>
                    </div>
                  </div>
                </td>
              </tr>

              <!-- Empty State: لا توجد جلسات -->
              <tr v-if="!paginatedSessions.length && !isLoading" class="text-center">
                <td colspan="8" class="py-24 text-slate-300">
                  <i class="fas fa-receipt text-3xl mb-4 opacity-20"></i>
                  <p class="text-xs font-bold uppercase tracking-widest">لا توجد جلسات مسجلة لهذه الفترة</p>
                </td>
              </tr>
            </tbody>
            </table>
          </div>

        <!-- Pagination Footer (Unified Standard) -->
        <div class="px-6 py-4 bg-slate-50/50 border-t border-slate-200 flex items-center justify-between">
          <div class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">
            صفحة <span class="text-slate-900">{{ filters.page.value }}</span> من <span class="text-slate-900">{{ filters.totalPages.value }}</span>
            <span class="mx-2 text-slate-200">|</span>
            إجمالي <span class="text-slate-900">{{ totalItems }}</span> جلسة
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
             <div class="flex items-center gap-1">
               <button @click="filters.previousPage()" :disabled="filters.page.value <= 1" class="pagination-btn-v2"><i class="fas fa-chevron-right"></i></button>
               <button @click="filters.nextPage(filters.totalPages.value)" :disabled="filters.page.value >= filters.totalPages.value" class="pagination-btn-v2"><i class="fas fa-chevron-left"></i></button>
             </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Session Details Modal: Audit Terminal Style -->
    <BaseModal :show="showTransactionDetails && !!selectedSession" @close="closeSessionDetails" maxWidth="4xl" variant="modern">
      <template #header>
        <div class="flex items-center gap-4">
          <div class="w-10 h-10 bg-slate-900 rounded-lg flex items-center justify-center text-white shadow-lg shrink-0"><i class="fas fa-receipt text-base"></i></div>
          <div>
            <h3 class="text-sm font-bold text-slate-900 uppercase">تحليل الجلسة المالية #{{ selectedSession?.session.id }}</h3>
            <p class="text-[9px] text-slate-400 font-mono mt-1 uppercase tracking-tighter">{{ formatDateTime(selectedSession?.session.start_time) }}</p>
          </div>
        </div>
      </template>

      <div class="space-y-10">
        <!-- Identity Summary Grid -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-6">
          <div v-for="info in [
            { l: 'الفرع', v: selectedSession?.session.branch_name || 'الفرع الرئيسي' },
            { l: 'المحطة', v: selectedSession?.session.device_name || 'غير محدد' },
            { l: 'الموظف المسئول', v: selectedSession?.session.closed_by_name || 'غير محدد' },
            { l: 'الحالة', v: selectedSession?.session.status === 'closed' ? 'مغلقة ومرحلة' : 'نشطة حالياً', b: selectedSession?.session.status === 'closed' ? 'bg-slate-100 text-slate-600' : 'bg-amber-50 text-amber-600' }
          ]" :key="info.l" class="p-4 rounded-xl bg-slate-50 border border-slate-200 flex flex-col justify-center space-y-1">
            <p class="text-[9px] font-bold text-slate-400 uppercase tracking-widest">{{ info.l }}</p>
            <span v-if="info.b" :class="[info.b]" class="px-2 py-0.5 rounded text-[9px] font-bold border w-fit uppercase">{{ info.v }}</span>
            <p v-else class="text-xs font-bold text-slate-900">{{ info.v }}</p>
          </div>
        </div>

        <!-- Financial Reconciliation: Professional Logic Box -->
        <div class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm">
           <div class="px-6 py-3 bg-slate-50/50 border-b border-slate-100 flex items-center justify-between">
              <h4 class="text-[10px] font-bold text-slate-900 uppercase tracking-[0.2em] flex items-center gap-2">
                <i class="fas fa-calculator text-blue-500"></i> موازنة الدرج (Cash Audit)
              </h4>
           </div>
           <div class="p-8 grid grid-cols-1 md:grid-cols-3 gap-10">
              <div class="space-y-4">
                <div class="flex justify-between items-center text-[11px] font-medium"><span class="text-slate-400">الرصيد الافتتاحي</span><span class="text-slate-900 font-mono">{{ formatCurrency(selectedSession?.calculated.opening_balance) }}</span></div>
                <div class="flex justify-between items-center text-[11px] font-medium text-emerald-600"><span>المتحصلات النقدية (+)</span><span class="font-mono">+{{ formatCurrency(selectedSession?.totals.cash_in) }}</span></div>
                <div class="flex justify-between items-center text-[11px] font-medium text-rose-500 pb-3 border-b border-slate-50"><span>إجمالي المصروفات (-)</span><span class="font-mono">-{{ formatCurrency(selectedSession?.totals.cash_out) }}</span></div>
              </div>
              
              <div class="flex flex-col items-center justify-center text-center p-6 bg-slate-50 rounded-xl border border-slate-100 shadow-inner">
                 <p class="text-[9px] font-bold text-slate-400 uppercase tracking-widest mb-2">النقد المتوقع محاسبياً</p>
                 <p class="text-2xl font-bold font-mono tracking-tighter text-slate-900">{{ formatCurrency(selectedSession?.calculated.expected_cash) }}</p>
              </div>

              <div class="flex flex-col items-center justify-center text-center p-6 bg-slate-900 rounded-xl text-white shadow-xl relative overflow-hidden">
                 <div class="absolute top-0 right-0 w-20 h-20 bg-white/5 rounded-full -translate-x-4 -translate-y-4"></div>
                 <p class="text-[9px] font-bold text-blue-400 uppercase tracking-widest mb-2">النقد الفعلي المستلم</p>
                 <p class="text-2xl font-bold font-mono tracking-tighter leading-none">{{ formatCurrency(selectedSession?.session.closing_cash_amount) }}</p>
                 <div class="mt-4 pt-3 border-t border-white/10 w-full flex items-center justify-center gap-2">
                    <span :class="[selectedSession?.calculated.variance_amount === 0 ? 'text-white/40' : 'text-rose-400']" class="text-[10px] font-bold uppercase tracking-tight">الفرق: {{ formatCurrency(selectedSession?.calculated.variance_amount) }}</span>
                    <i :class="selectedSession?.calculated.variance_amount === 0 ? 'fas fa-check-circle text-emerald-400' : 'fas fa-triangle-exclamation text-rose-400'" class="text-[9px]"></i>
                 </div>
              </div>
           </div>
           <div v-if="selectedSession?.session.variance_reason" class="px-8 pb-6">
              <div class="p-4 bg-amber-50 border border-amber-100 rounded-lg text-xs font-bold text-amber-800 italic leading-relaxed">
                <i class="fas fa-quote-right ml-2 opacity-30"></i> تبرير الفرق: {{ selectedSession?.session.variance_reason }}
              </div>
           </div>
        </div>

        <!-- Ledger Feed Table -->
        <div class="space-y-4">
          <h4 class="text-[10px] font-bold text-slate-400 uppercase tracking-[0.2em] px-1">سجل حركات دفتر اليومية للجلسة</h4>
          <div class="border border-slate-200 rounded-xl overflow-hidden shadow-sm">
            <table class="w-full text-right text-[11px] font-medium border-collapse">
              <thead><tr class="bg-slate-50/80 border-b border-slate-200 text-slate-400 font-bold uppercase tracking-widest"><th class="px-6 py-4">الوقت</th><th class="px-4 py-4">نوع الحركة</th><th class="px-4 py-4 text-left">المبلغ</th><th class="px-6 py-4">المرجع</th><th class="px-6 py-4">الملاحظات</th></tr></thead>
              <tbody class="divide-y divide-slate-100">
                <template v-if="!selectedSession?.transactions?.length">
                  <tr><td colspan="5" class="py-12 text-center text-slate-300"><i class="fas fa-inbox text-2xl mb-2 opacity-20"></i><p class="text-[10px] font-bold uppercase tracking-widest">لا توجد حركات مالية مسجلة لهذه الجلسة</p></td></tr>
                </template>
                <tr v-for="tx in selectedSession?.transactions" :key="tx.id" class="hover:bg-slate-50 transition-colors">
                  <td class="px-6 py-3 font-mono font-bold text-slate-400">{{ formatDateTime(tx.created_at) }}</td>
                  <td class="px-4 py-3"><span class="px-2 py-0.5 rounded bg-slate-100 text-[10px] font-bold text-slate-600 uppercase">{{ getTransactionTypeLabel(tx.type) }}</span></td>
                  <td class="px-4 py-3 text-left font-mono font-bold" :class="isExpense(tx.type) ? 'text-rose-600' : 'text-emerald-600'">{{ isExpense(tx.type) ? '-' : '+' }}{{ formatCurrency(tx.amount) }}</td>
                  <td class="px-6 py-3 font-bold text-blue-600 uppercase"><span v-if="tx.reference_type && tx.reference_id" class="font-mono">{{ tx.reference_type }}#{{ tx.reference_id }}</span><span v-else class="text-slate-400">--</span></td>
                  <td class="px-6 py-3 text-slate-500 italic max-w-xs truncate">{{ tx.notes || '--' }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <template #footer>
        <div class="flex items-center justify-between w-full">
           <span class="text-[9px] font-bold text-slate-400 uppercase tracking-widest"><i class="fas fa-shield-check text-emerald-500 ml-1"></i> تقرير معتمد من نظام تصفية الحسابات</span>
           <div class="flex gap-2">
             <button @click="closeSessionDetails" class="px-6 h-9 text-xs font-bold text-slate-500">إغلاق</button>
             <button v-if="selectedSession?.session.status === 'closed'" @click="exportSession(selectedSession?.session)" :disabled="isExporting" class="px-8 h-9 bg-slate-900 text-white rounded-md text-[10px] font-bold uppercase tracking-widest shadow-lg active:scale-95 transition-all flex items-center gap-2">
               <i v-if="!isExporting" class="fas fa-file-csv"></i>
               <i v-else class="fas fa-spinner fa-spin"></i>
               تصدير التقرير
             </button>
           </div>
        </div>
      </template>
    </BaseModal>

  </div>
</template>

<script setup>
// [SCRIPT SECTION REMAINS 100% IDENTICAL TO THE ORIGINAL AS PER BUSINESS LOGIC RULE]
import { ref, computed, onMounted } from 'vue';
import BaseModal from '@/components/BaseModal.vue';
import { formatCurrency as utilFormatCurrency } from '@/utils/formatters';
import { getLocalDateISO, formatDateTime as utilFormatDateTime } from '@/utils/date';

import BaseSkeleton from '@/components/ui/BaseSkeleton.vue';
import { useSessionStore } from '@/stores/session/sessionStore';
import { downloadCSV } from '@/utils/export';
import { useToast } from 'vue-toastification';
import { useBranchStore } from '@/stores/branch';
import { useBreadcrumb } from '@/composables/useBreadcrumb';
import { useTableFilters } from '@/composables/useTableFilters';
import { useCompanyCurrency } from '@/composables/useCompanyCurrency';
import PageHeader from '@/components/PageHeader.vue';

const toast = useToast();
const branchStore = useBranchStore();
const sessionStore = useSessionStore();
const { breadcrumb } = useBreadcrumb();
const { fetchSettings } = useCompanyCurrency();

const isLoading = ref(true);
const error = ref(null);
const sessions = ref([]);
const totalItems = ref(0);

// ─── Pagination (using useTableFilters composable)
const filters = useTableFilters('sessions_summary_filters');

const selectedSession = ref(null);
const showTransactionDetails = ref(false);
const isExporting = ref(false);

const fromDate = ref('');
const toDate = ref('');
const fromDateRef = ref(null);
const toDateRef = ref(null);
const branchId = ref('');
const cashierId = ref('');
const terminalId = ref('');
const hasVariance = ref('');

const branches = computed(() => branchStore.branches);
const cashiers = ref([]);
const terminals = ref([]);

const paginatedSessions = computed(() => {
  filters.totalCount.value = sessions.value.length;
  const start = (filters.page.value - 1) * filters.perPage.value;
  const end = start + filters.perPage.value;
  return sessions.value.slice(start, end);
});

const formatCurrency = (value) => { return utilFormatCurrency(value); };
const formatDate = (dateString) => {
  if (!dateString) return '';
  return new Date(dateString).toLocaleDateString('en-US', { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' });
};
const formatDateTime = (dateString) => {
  if (!dateString) return '—';
  return new Date(dateString).toLocaleString('en-US');
};
const formatDuration = (start, end) => {
  if (!start || !end) return '-';
  const startDate = new Date(start);
  const endDate = new Date(end);
  const diffMs = endDate - startDate;
  const hours = Math.floor(diffMs / (1000 * 60 * 60));
  const minutes = Math.floor((diffMs % (1000 * 60 * 60)) / (1000 * 60));
  return `${hours} س ${minutes} د`;
};

const fetchSessions = async () => {
  try {
    isLoading.value = true; error.value = null;
    const params = { page: currentPage.value, per_page: itemsPerPage.value, from_date: fromDate.value || undefined, to_date: toDate.value || undefined, branch_id: branchId.value || undefined, cashier_id: cashierId.value || undefined, terminal_id: terminalId.value || undefined, has_variance: hasVariance.value || undefined };
    const result = await sessionStore.fetchSessions(params);
    if (result.status === 'success') { sessions.value = result.data || []; totalItems.value = result.total || sessions.value.length; }
    else { error.value = result.message || 'فشل تحميل الجلسات'; toast.error(error.value); }
  } catch (err) { error.value = 'حدث خطأ أثناء جلب بيانات الجلسات'; toast.error(error.value); }
  finally { isLoading.value = false; }
};

const viewSessionDetails = async (session) => {
  try {
    isLoading.value = true;
    const result = await sessionStore.getSessionSummary(session.id);
    if (result.status === 'success') { selectedSession.value = { session: session, ...result.data }; showTransactionDetails.value = true; }
    else { toast.error(result.message || 'فشل في تحميل تفاصيل الجلسة'); }
  } catch (err) { toast.error('فشل في تحميل تفاصيل الجلسة'); }
  finally { isLoading.value = false; }
};

const closeSessionDetails = () => { showTransactionDetails.value = false; selectedSession.value = null; };

const exportSession = async (session) => {
  try {
    isExporting.value = true;
    const result = await sessionStore.fetchSessions();
    if (result.status === 'success' && Array.isArray(result.data)) {
      const sessionData = result.data.find(s => s.id === session.id);
      if (sessionData) {
        const fileName = `session_${session.id}_${getLocalDateISO(new Date())}.csv`;
        const csvContent = `Session ID,Date,Status,Amount\n${session.id},${session.date},${session.status},${session.amount}`;
        downloadCSV(csvContent, fileName);
        toast.success('تم تصدير الجلسة بنجاح');
      }
    }
  } catch (err) { toast.error('فشل في تصدير الجلسة'); }
  finally { isExporting.value = false; }
};

const getTransactionTypeLabel = (type) => {
  const types = { 'sale': 'بيع', 'expense': 'مصروف', 'income': 'إيراد', 'return_payment': 'مرتجع مشتريات', 'return_receipt': 'مرتجع مبيعات', 'withdrawal': 'سحب', 'deposit': 'إيداع' };
  return types[type] || type;
};

const isExpense = (type) => { return ['expense', 'return_payment', 'withdrawal'].includes(type); };

const fetchDropdownData = async () => {
  try { cashiers.value = []; terminals.value = []; } catch (err) { console.error(err); }
};

const onPageChange = (page) => { if (page >= 1 && page <= filters.totalPages.value) { filters.page.value = page; fetchSessions(); } };

const resetFilters = () => { fromDate.value = ''; toDate.value = ''; branchId.value = ''; cashierId.value = ''; terminalId.value = ''; hasVariance.value = ''; filters.page.value = 1; fetchSessions(); };

onMounted(async () => {
  const endDate = new Date(); const startDate = new Date(); startDate.setDate(startDate.getDate() - 30);
  fromDate.value = getLocalDateISO(startDate); toDate.value = getLocalDateISO(endDate);
  await Promise.all([fetchSessions(), fetchDropdownData()]);
});
</script>

<style scoped>
@keyframes loading { 0% { transform: translateX(100%); } 100% { transform: translateX(-100%); } }

.filter-input-v3 {
  @apply h-9 w-full bg-white border border-slate-200 rounded-md px-3 text-[11px] font-bold text-slate-700 focus:ring-4 focus:ring-blue-500/5 focus:border-blue-500 outline-none transition-all;
}

.metadata-label { @apply block text-[10px] font-bold text-slate-400 uppercase tracking-widest px-1 mb-1; }

.status-badge { @apply px-2 py-0.5 rounded text-[9px] font-bold border inline-flex items-center justify-center; }

.pagination-btn-v2 {
  @apply w-8 h-8 flex items-center justify-center rounded border border-slate-200 bg-white text-slate-500 hover:text-blue-600 hover:border-blue-200 disabled:opacity-30 transition-all;
}

.custom-scroll::-webkit-scrollbar { width: 5px; height: 5px; }
.custom-scroll::-webkit-scrollbar-thumb { @apply bg-slate-200 rounded-full; }

.animate-fadeIn { animation: fadeIn 0.4s ease-out; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

@keyframes modalIn { from { opacity: 0; transform: scale(0.98) translateY(10px); } to { opacity: 1; transform: scale(1) translateY(0); } }
.animate-modalIn { animation: modalIn 0.2s cubic-bezier(0.16, 1, 0.3, 1); }
</style>