<template>
  <div class="min-h-screen bg-[#fafafa] text-slate-900 font-sans antialiased selection:bg-blue-100" dir="rtl">
    
    <!-- Top Progress Bar -->
    <div v-if="loading" class="fixed top-0 left-0 right-0 h-0.5 bg-blue-600/10 z-[110]">
      <div class="h-full bg-blue-600 animate-[loading_2s_ease-in-out_infinite] w-1/3"></div>
    </div>

    <div class="max-w-[1600px] mx-auto p-6 lg:p-10 space-y-8">
      
      <!-- Page Header -->
      <PageHeader
        :breadcrumb="breadcrumb"
        title="إدارة الجلسات والورديات"
        description="مراقبة حية للنشاط النقدي، تصفية الورديات، ومعالجة الفروقات."
        :branches="isExempt ? branches : []"
        :selectedBranch="selectedBranch"
        @branch-changed="(val) => { selectedBranch.value = val; applyFilters(); }"
      >
        <template #controls>
          <button 
            @click="showFilters = !showFilters" 
            :class="[showFilters ? 'bg-slate-900 text-white' : 'bg-white text-slate-700 border-slate-200']"
            class="h-9 px-4 rounded-md border text-xs font-bold transition-all flex items-center gap-2 shadow-sm"
          >
            <i class="fas fa-filter text-[10px]"></i>
            {{ showFilters ? 'إخفاء الفلاتر' : 'تصفية الجلسات' }}
          </button>
          <button @click="fetchData" class="h-9 w-9 flex items-center justify-center rounded-md border border-slate-200 bg-white text-slate-400 hover:text-blue-600 transition-colors">
            <i class="fas fa-sync-alt text-xs" :class="{'animate-spin': loading}"></i>
          </button>
          <button @click="openRenameDevice" class="h-9 px-4 rounded-md bg-white border border-slate-200 text-slate-600 text-xs font-bold hover:bg-slate-50">
            <i class="fas fa-edit ml-1.5 text-[10px]"></i> تسمية الجهاز
          </button>
        </template>
      </PageHeader>

      <!-- KPI Summary -->
      <section class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-6">
        <div v-for="kpi in [
          { label: 'إجمالي الجلسات', val: total, icon: 'fa-clipboard-list', color: 'text-blue-600', bg: 'bg-blue-50' },
          { label: 'إجمالي النقد المتوقع', val: formatCurrency(totalCash), icon: 'fa-money-bill-wave', color: 'text-emerald-600', bg: 'bg-emerald-50' },
          { label: 'جلسات بها فروقات', val: sessionsWithVariance, icon: 'fa-balance-scale', color: 'text-amber-600', bg: 'bg-amber-50' }
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

      <!-- Filters Panel -->
      <transition name="slide-down">
        <div v-if="showFilters" class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden animate-fadeIn">
          <div class="p-6 space-y-6">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
              <div class="space-y-1.5">
                <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">الجهاز / نقطة البيع</label>
                <select class="filter-input" v-model="deviceFilter" @change="applyFilters">
                  <option value="">كل الاجهزة</option>
                  <option v-for="d in deviceOptions" :key="d" :value="d">{{ d }}</option>
                </select>
              </div>

              <div class="space-y-1.5">
                <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">نوع الوردية</label>
                <select class="filter-input" v-model="sessionType" @change="applyFilters">
                  <option value="">كل الورديات</option>
                  <option value="morning">صباحية</option>
                  <option value="evening">مسائية</option>
                  <option value="daily">يومية</option>
                  <option value="manual">يدوية</option>
                </select>
              </div>

              <div class="space-y-1.5">
                <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">حالة الجلسة</label>
                <select class="filter-input" v-model="filters.statusFilter.value" @change="applyFilters">
                  <option value="">كل الحالات</option>
                  <option value="open">مفتوحة</option>
                  <option value="closed">مغلقة</option>
                </select>
              </div>

              <div class="space-y-1.5">
                <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">الفروقات النقدية</label>
                <select class="filter-input" v-model="hasVariance" @change="applyFilters">
                  <option value="">الكل</option>
                  <option value="yes">يوجد عجز/زيادة</option>
                  <option value="no">مطابق</option>
                </select>
              </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end pt-4 border-t border-slate-50">
              <div class="space-y-1.5">
                <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">من تاريخ</label>
                <div class="relative">
                  <input ref="fromDateRef" type="date" v-model="dateFrom" class="filter-input" style="padding-left: 2rem;" />
                  <i @click="fromDateRef?.showPicker?.()" class="fas fa-calendar absolute left-3 top-1/2 -translate-y-1/2 text-slate-300 cursor-pointer hover:text-slate-500 transition-colors text-[10px]"></i>
                </div>
              </div>

              <div class="space-y-1.5">
                <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">إلى تاريخ</label>
                <div class="relative">
                  <input ref="toDateRef" type="date" v-model="dateTo" class="filter-input" style="padding-left: 2rem;" />
                  <i @click="toDateRef?.showPicker?.()" class="fas fa-calendar absolute left-3 top-1/2 -translate-y-1/2 text-slate-300 cursor-pointer hover:text-slate-500 transition-colors text-[10px]"></i>
                </div>
              </div>

              <button @click="resetFilters" class="h-9 w-full rounded-md bg-slate-100 text-slate-600 text-[11px] font-bold hover:bg-slate-200 transition-all">
                إعادة تعيين الفلاتر
              </button>
            </div>
          </div>
        </div>
      </transition>

      <!-- Active Filter Chips -->
      <div v-if="hasActiveSessionFilters" class="flex flex-wrap gap-2 animate-fadeIn">
        <div v-for="chip in [
          { show: deviceFilter, label: deviceFilter, clear: () => deviceFilter = '' },
          { show: sessionType, label: sessionType, clear: () => sessionType = '' },
          { show: filters.statusFilter.value, label: statusLabel(filters.statusFilter.value), clear: () => filters.statusFilter.value = '' },
          { show: dateFrom, label: 'من: ' + dateFrom, clear: () => dateFrom = '' },
          { show: dateTo, label: 'إلى: ' + dateTo, clear: () => dateTo = '' }
        ].filter(c => c.show)" :key="chip.label" class="inline-flex items-center gap-2 px-2.5 py-1 bg-blue-50 border border-blue-100 rounded-md text-[10px] font-bold text-blue-700">
          {{ chip.label }}
          <i @click="chip.clear" class="fas fa-times cursor-pointer opacity-60 hover:opacity-100 transition-opacity"></i>
        </div>
      </div>

      <!-- Main Data Table -->
      <div class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm relative">
        <div class="overflow-x-auto">
          <table class="w-full text-right border-collapse">
            <thead>
              <tr class="bg-slate-50 border-b border-slate-200">
                <th class="px-6 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">الجلسة والكاشير</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">المحطة / الجهاز</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">التوقيت</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">ملخص الخزينة</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">الحالة</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">الفرق</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">إجراءات</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <template v-if="loading">
                <tr v-for="n in 5" :key="n" class="animate-pulse">
                  <td v-for="m in 7" :key="m" class="px-6 py-4"><div class="h-3 bg-slate-100 rounded w-full"></div></td>
                </tr>
              </template>
              <tr v-else-if="items.length === 0">
                <td colspan="7" class="py-20 text-center text-slate-300">
                   <i class="fas fa-history text-3xl mb-4 opacity-20"></i>
                   <p class="text-xs font-bold uppercase tracking-widest">لا توجد جلسات مسجلة</p>
                </td>
              </tr>
              <tr v-for="row in items" :key="row.id" class="hover:bg-blue-50/10 transition-all group">
                <td class="px-6 py-4">
                  <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded bg-slate-100 flex items-center justify-center text-slate-400 group-hover:text-blue-600 transition-colors">
                      <i class="fas fa-receipt text-xs"></i>
                    </div>
                    <div class="flex flex-col">
                      <span class="text-xs font-bold text-slate-900">#{{ row.id }}</span>
                      <span class="text-[10px] font-medium text-slate-400">{{ row.cashier_name || 'كاشير' }}</span>
                    </div>
                  </div>
                </td>
                <td class="px-4 py-4 text-[10px] font-bold text-slate-500 uppercase tracking-tight">
                   <div class="flex items-center gap-2"><i class="fas fa-desktop text-slate-300"></i> {{ deviceLabel(row) }}</div>
                </td>
                <td class="px-4 py-4">
                  <div class="flex flex-col gap-0.5">
                    <div class="text-[10px] font-bold text-slate-700 tracking-tighter">{{ formatDateTime(row.start_time) }}</div>
                    <div v-if="row.end_time" class="text-[9px] font-medium text-slate-400 italic">أغلقت: {{ formatDateTime(row.end_time) }}</div>
                    <div v-else class="text-[9px] font-bold text-blue-600 flex items-center gap-1"><span class="w-1 h-1 rounded-full bg-blue-600 animate-pulse"></span> نشطة حالياً</div>
                  </div>
                </td>
                <td class="px-4 py-4">
                  <div class="min-w-[140px] space-y-1">
                    <template v-if="summaries[row.id]">
                      <div class="flex justify-between text-[10px] font-bold"><span class="text-slate-400">المدفوعات:</span><span class="text-slate-900">{{ formatCurrency(summaries[row.id]?.totals?.payments || 0) }}</span></div>
                      <div class="flex justify-between text-[10px] font-bold border-t border-slate-50 pt-1"><span class="text-slate-400">المتوقع:</span><span class="text-slate-900 font-mono tracking-tighter">{{ formatCurrency(summaries[row.id]?.calculated?.expected_cash || 0) }}</span></div>
                    </template>
                    <div v-else class="h-8 w-24 bg-slate-50 animate-pulse rounded"></div>
                  </div>
                </td>
                <td class="px-4 py-4 text-center">
                  <span :class="[statusClass(row.status)]" class="px-2 py-0.5 rounded text-[9px] font-bold border">
                    {{ statusLabel(row.status) }}
                  </span>
                </td>
                <td class="px-4 py-4 text-center">
                   <template v-if="summaries[row.id] && !summaries[row.id]?._partial">
                    <span v-if="summaries[row.id]?.calculated?.variance_amount !== null"
                          :class="[summaries[row.id].calculated.variance_amount > 0 ? 'text-emerald-600 border-emerald-100 bg-emerald-50' : summaries[row.id].calculated.variance_amount < 0 ? 'text-rose-600 border-rose-100 bg-rose-50' : 'text-slate-400 bg-slate-50 border-slate-100']"
                          class="px-1.5 py-0.5 rounded text-[11px] font-bold border font-mono">
                      {{ formatCurrency(summaries[row.id].calculated.variance_amount) }}
                    </span>
                    <span v-else class="text-slate-300 font-mono">—</span>
                  </template>
                  <span v-else-if="row.variance_amount" :class="row.variance_amount > 0 ? 'text-emerald-600' : 'text-rose-600'" class="text-xs font-bold font-mono">...</span>
                  <span v-else class="text-slate-200">—</span>
                </td>
                <td class="px-4 py-4 text-center">
                  <div class="flex items-center justify-center gap-2">
                    <button @click="toggleDetails(row)" class="w-8 h-8 rounded-lg border border-slate-200 text-slate-400 hover:text-blue-600 hover:border-blue-200 transition-all flex items-center justify-center"><i class="fas fa-ellipsis-v text-[10px]"></i></button>
                    <button class="w-8 h-8 rounded-lg border border-slate-200 text-slate-400 hover:text-slate-900 transition-all flex items-center justify-center"><i class="fas fa-print text-[10px]"></i></button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Pagination -->
        <div class="px-6 py-4 bg-slate-50/50 border-t border-slate-200 flex items-center justify-between">
          <div class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">
            صفحة <span class="text-slate-900">{{ currentPage }}</span> من <span class="text-slate-900">{{ totalPages }}</span>
            <span class="mx-2 text-slate-200">|</span>
            إجمالي <span class="text-slate-900">{{ total }}</span> جلسة
          </div>
          <div class="flex items-center gap-3">
             <div class="flex items-center gap-2">
               <span class="text-[10px] font-bold text-slate-400 uppercase tracking-tighter">النتائج:</span>
               <select v-model.number="filters.perPage.value" @change="applyFilters" class="h-8 border border-slate-200 rounded px-2 text-[10px] font-bold outline-none">
                 <option :value="10">10</option>
                 <option :value="20">20</option>
                 <option :value="50">50</option>
               </select>
             </div>
             <div class="flex items-center gap-1">
               <button @click="goToPreviousPage()" :disabled="currentPage<=1" class="pagination-btn-v2"><i class="fas fa-chevron-right"></i></button>
               <button @click="goToNextPage()" :disabled="currentPage>=totalPages" class="pagination-btn-v2"><i class="fas fa-chevron-left"></i></button>
             </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Details Sidebar Drawer: Standardized side panel -->
    <transition name="drawer">
      <div v-if="detailsSession" class="fixed inset-0 z-[120] flex justify-end bg-slate-900/60 backdrop-blur-sm" @click="detailsSession = null">
        <div class="w-full max-w-xl h-full bg-white shadow-2xl flex flex-col overflow-hidden animate-slide-in-right" @click.stop>
          <!-- Drawer Header -->
          <div class="px-8 py-5 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
            <div class="flex items-center gap-3">
              <div class="w-8 h-8 bg-slate-900 rounded flex items-center justify-center text-white text-xs font-bold">{{ detailsSession.id }}</div>
              <div>
                <h2 class="text-sm font-bold text-slate-900 uppercase">تفاصيل الجلسة المالية</h2>
                <p class="text-[10px] text-slate-400 font-medium mt-0.5">
                  {{ getbranchName(detailsSession.branch_id) }}
                  <span class="mx-1 text-slate-200">|</span>
                  محطة: {{ deviceLabel(detailsSession) }}
                </p>
              </div>
            </div>
            <button @click="detailsSession = null" class="text-slate-400 hover:text-slate-900 transition-colors"><i class="fas fa-times"></i></button>
          </div>

          <!-- Drawer Body -->
          <div class="flex-grow overflow-y-auto custom-scroll p-8 space-y-10">

            <!-- Session Status Header -->
            <div class="bg-slate-900 rounded-xl p-5 space-y-3">
              <div class="flex items-center justify-between">
                <span :class="[detailsSession.status === 'open' ? 'bg-emerald-500/20 text-emerald-400 border-emerald-500/30' : 'bg-slate-600/40 text-slate-300 border-slate-600/50', 'px-2.5 py-1 rounded-full text-[10px] font-bold border uppercase tracking-widest']">
                  {{ statusLabel(detailsSession.status) }}
                </span>
                <span class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">{{ formatDate(detailsSession.start_time) }}</span>
              </div>
              <div class="flex items-center gap-2 text-xs font-bold text-slate-300">
                <i class="fas fa-clock text-slate-500"></i>
                <span>{{ formatTime(detailsSession.start_time) }}</span>
                <span class="text-slate-600 mx-1">→</span>
                <span v-if="detailsSession.end_time">{{ formatTime(detailsSession.end_time) }}</span>
                <span v-else class="text-emerald-400 flex items-center gap-1">
                  <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span> الآن
                </span>
              </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
              <div v-for="info in [
                { l: 'الرصيد الافتتاحي', v: summaries[detailsSession.id]?.calculated?.opening_balance, c: 'text-slate-600' },
                { l: 'إجمالي المبيعات', v: summaries[detailsSession.id]?.totals?.cash_in, c: 'text-emerald-600' },
                { l: 'إجمالي المصروفات', v: summaries[detailsSession.id]?.totals?.cash_out, c: 'text-rose-600' },
                { l: 'المتوقع حالياً', v: summaries[detailsSession.id]?.calculated?.expected_cash, c: 'text-blue-600 font-black' }
              ]" :key="info.l" class="p-4 rounded-lg bg-slate-50 border border-slate-100 space-y-1">
                <p class="text-[9px] font-bold text-slate-400 uppercase tracking-widest">{{ info.l }}</p>
                <p :class="[info.c, 'text-sm font-bold font-mono tracking-tighter']">{{ formatCurrency(info.v || 0) }}</p>
              </div>
            </div>

            <!-- Transaction Table -->
            <div class="space-y-3">
              <h3 class="text-[10px] font-bold text-slate-400 uppercase tracking-[0.2em] px-1">سجل الحركات النقدية</h3>
              <div class="border border-slate-100 rounded-lg overflow-hidden shadow-sm">
                <table class="w-full text-right text-xs">
                  <thead><tr class="bg-slate-50 border-b border-slate-100"><th class="px-4 py-3 text-[10px] font-bold text-slate-400 uppercase">الوقت</th><th class="px-4 py-3 text-[10px] font-bold text-slate-400 uppercase">النوع</th><th class="px-4 py-3 text-[10px] font-bold text-slate-400 uppercase text-left">المبلغ</th></tr></thead>
                  <tbody class="divide-y divide-slate-50">
                    <tr v-for="tx in transactions[detailsSession.id]" :key="tx.id" class="hover:bg-slate-50 transition-colors">
                      <td class="px-4 py-3 text-slate-400 font-mono">{{ formatTime(tx.created_at) }}</td>
                      <td class="px-4 py-3 font-bold text-slate-700">
                        {{ getTransactionTypeLabel(tx.type) }}
                        <span v-if="tx.status === 'cancelled'" class="mr-1 text-[9px] text-slate-400">ملغاة</span>
                      </td>
                      <td class="px-4 py-3 text-left font-bold font-mono" :class="tx.status === 'cancelled' ? 'text-slate-400 line-through' : (isExpense(tx.type) ? 'text-rose-600' : 'text-emerald-600')">
                        <span v-if="tx.status !== 'cancelled'">{{ isExpense(tx.type) ? '-' : '+' }}</span>{{ formatCurrency(tx.amount) }}
                      </td>
                    </tr>
                    <tr v-if="!transactions[detailsSession.id]?.length"><td colspan="3" class="py-8 text-center text-slate-300 italic text-[10px]">لا توجد حركات مسجلة لهذه الجلسة</td></tr>
                  </tbody>
                </table>
              </div>
            </div>

            <!-- Variance Report -->
            <div v-if="summaries[detailsSession.id]?.calculated?.variance_amount" class="p-6 rounded-xl bg-amber-50 border border-amber-100 space-y-3 shadow-inner">
               <div class="flex items-center justify-between">
                  <h4 class="text-[10px] font-bold text-amber-800 uppercase tracking-widest">تقرير الفروقات والتصفية</h4>
                  <span class="text-sm font-bold text-amber-900 font-mono tracking-tighter">{{ formatCurrency(summaries[detailsSession.id].calculated.variance_amount) }}</span>
               </div>
               <p class="text-xs text-amber-700 leading-relaxed italic border-t border-amber-200/50 pt-3">{{ summaries[detailsSession.id]?.session?.variance_reason || 'تم إغلاق الجلسة دون تبرير للفروقات المذكورة.' }}</p>
            </div>
          </div>
          
          <div class="px-8 py-5 bg-slate-900 border-t border-white/10 flex justify-end shrink-0">
             <button @click="detailsSession = null" class="h-10 px-8 rounded-md bg-white/10 text-white hover:bg-white/20 text-xs font-bold transition-all uppercase tracking-widest">إغلاق الجلسة</button>
          </div>
        </div>
      </div>
    </transition>

    <!-- Rename Device Modal -->
    <transition name="fade">
      <div v-if="showRenameDevice" class="fixed inset-0 z-[130] flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-md">
        <div class="bg-white w-full max-w-sm rounded-2xl shadow-2xl overflow-hidden border border-slate-200 animate-modalIn">
          <div class="p-8 text-center space-y-6">
             <div class="w-14 h-14 bg-blue-50 text-blue-600 rounded-xl flex items-center justify-center mx-auto"><i class="fas fa-edit text-xl"></i></div>
             <div><h3 class="text-base font-bold text-slate-900 uppercase">تسمية جهاز نقطة البيع</h3><p class="text-[10px] text-slate-400 mt-2 font-medium">سيظهر هذا الاسم في سجلات العمليات والتقارير</p></div>
             <input v-model.trim="deviceNameInput" type="text" class="w-full h-11 border border-slate-200 rounded-lg px-4 text-sm font-bold focus:ring-4 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all" placeholder="مثال: كاشير الاستقبال" />
             <div class="flex gap-3 pt-2">
                <button @click="showRenameDevice = false" class="flex-1 h-10 text-xs font-bold text-slate-500">إلغاء</button>
                <button @click="saveDeviceName" class="flex-2 h-10 bg-blue-600 text-white rounded-lg text-xs font-bold shadow-lg shadow-blue-900/20 px-6">حفظ التغييرات</button>
             </div>
          </div>
        </div>
      </div>
    </transition>

  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useSessionStore } from '@/stores/session/sessionStore';
import { useBranchStore } from '@/stores/branch/branchStore';
import { useAuthStore } from '@/stores/auth/authStore';
import { useBranchIsolation } from '@/composables/useBranchIsolation';
import { useBootstrapStore } from '@/stores/bootstrap/bootstrapStore';
import { useToast } from '@/composables/useToast';
import { useCompanyCurrency } from '@/composables/useCompanyCurrency';
import { useDateValidation } from '@/composables/useDateValidation';
import { useSessionExemption } from '@/composables/useCashierSessionGuard';
import { useHistoryFilters } from '@/composables/useHistoryFilters';
import { getLocalDateISO } from '@/utils';

import BaseSkeleton from '@/components/ui/BaseSkeleton.vue';
import { useBreadcrumb } from '@/composables/useBreadcrumb';
import PageHeader from '@/components/PageHeader.vue';

const branchStore = useBranchStore();
const authStore   = useAuthStore();
const branchIsolation = useBranchIsolation();
const bootstrapStore = useBootstrapStore();
const { isExempt, ensureLoaded: ensureExemptionLoaded } = useSessionExemption();
const { showToast } = useToast();
const { formatCurrencyLocale, fetchSettings } = useCompanyCurrency();
const { validateDateRange } = useDateValidation();
const { breadcrumb } = useBreadcrumb();
const formatCurrency = (amount) => formatCurrencyLocale(amount, 2);

const filters = useHistoryFilters('sessions_hist_filters');
filters.loadFromLocalStorage();

const currentPage  = filters.page;
const perPage      = filters.perPage;
const dateFrom     = filters.dateFrom;
const dateTo       = filters.dateTo;
const showFilters  = filters.showFilters;

const selectedBranch = computed({
  get: () => branchStore.selectedBranchId,
  set: (val) => branchStore.setSelectedBranch(val)
});

const branches = computed(() => branchStore.branches);
const sessionType  = ref('');
const deviceFilter = ref('');
const hasVariance  = ref('');
const loading    = ref(false);
const items      = ref([]);
const summaries  = ref({});
const transactions = ref({});
const detailsSession = ref(null);
const totalCount = ref(0);
const kpiTotalExpectedCash    = ref(0);
const kpiSessionsWithVariance = ref(0);
const fromDateRef = ref(null);
const toDateRef   = ref(null);

const deviceOptions = computed(() => {
  const set = new Set();
  for (const it of items.value) {
    if (it.device_name) set.add(String(it.device_name));
    else if (it.device_id) set.add(String(it.device_id));
    else if (it.device) set.add(String(it.device));
  }
  try {
    const custom = (localStorage.getItem('pos_device_name') || '').trim();
    if (custom) set.add(custom);
  } catch {}
  return Array.from(set).filter(Boolean);
});

const total = computed(() => totalCount.value);
const totalPages = computed(() => Math.max(1, Math.ceil((totalCount.value || 0) / perPage.value)));
const totalCash = computed(() => kpiTotalExpectedCash.value);
const sessionsWithVariance = computed(() => kpiSessionsWithVariance.value);

const hasActiveSessionFilters = computed(() => !!(
  deviceFilter.value || sessionType.value || filters.statusFilter.value ||
  dateFrom.value || dateTo.value || selectedBranch.value || hasVariance.value
));

const showRenameDevice = ref(false);
const deviceNameInput  = ref('');

function openRenameDevice() {
  try { deviceNameInput.value = localStorage.getItem('pos_device_name') || ''; } catch { deviceNameInput.value = ''; }
  showRenameDevice.value = true;
}

function saveDeviceName() {
  const val = String(deviceNameInput.value || '').trim();
  try {
    if (val) localStorage.setItem('pos_device_name', val.slice(0, 64));
    else localStorage.removeItem('pos_device_name');
    showRenameDevice.value = false;
    items.value = items.value.slice();
    showToast(val ? 'تم حفظ اسم الجهاز' : 'تمت إعادة الاسم الافتراضي', 'success');
  } catch (_) { showToast('تعذر الحفظ', 'error'); }
}

function goToNextPage() { if (currentPage.value < totalPages.value) { currentPage.value++; fetchData(); } }
function goToPreviousPage() { if (currentPage.value > 1) { currentPage.value--; fetchData(); } }
function applyFilters() { currentPage.value = 1; fetchData(); }
function resetFilters() { filters.resetFilters(); sessionType.value = ''; deviceFilter.value = ''; hasVariance.value = ''; fetchData(); }

async function fetchData() {
  if (dateFrom.value && dateTo.value) { if (!validateDateRange(dateFrom.value, dateTo.value)) { loading.value = false; return; } }
  loading.value = true;
  try {
    const baseParams = filters.getApiParams();
    const params = {
      page: baseParams.page, per_page: baseParams.perPage, status: baseParams.status || undefined,
      branch_id: !isExempt.value ? (branchIsolation.currentBranchId.value || undefined) : (selectedBranch.value || undefined),
      from_date: baseParams.dateFrom || undefined, to_date: baseParams.dateTo || undefined,
      session_type: sessionType.value || undefined, device: deviceFilter.value || undefined, has_variance: hasVariance.value || undefined,
    };
    const sessionStore = useSessionStore();
    const result = await sessionStore.fetchSessions(params);
    if (result.status === 'success') {
      items.value = result.data || [];
      totalCount.value = result.total ?? result.data?.length ?? 0;
      kpiTotalExpectedCash.value = result.kpi?.total_expected_cash ?? 0;
      kpiSessionsWithVariance.value = result.kpi?.sessions_with_variance ?? 0;
      for (const item of items.value) {
        if (!summaries.value[item.id] || summaries.value[item.id]?._partial) {
          summaries.value[item.id] = { variance_amount: item.variance_amount ?? null, calculated: { opening_balance: parseFloat(item.opening_cash_amount || 0), expected_cash: null }, totals: { payments: 0, cash_in: 0, cash_out: 0 }, _partial: true };
        }
      }
      const sessionIds = items.value.map(item => item.id);
      if (sessionIds.length > 0) {
        sessionStore.getSessionSummaries(sessionIds).then(result => {
          if (result.status === 'success' && result.data) {
            Object.assign(summaries.value, result.data);
            items.value = [...items.value];
          }
        }).catch(() => {});
      }
    }
  } catch (e) { console.error(e); } finally { loading.value = false; }
}

async function toggleDetails(row) {
  const sessionStore = useSessionStore();
  detailsSession.value = row;
  if (!summaries.value[row.id] || summaries.value[row.id]?._partial) {
    try {
      const result = await sessionStore.getSessionSummary(row.id);
      if (result.status === 'success') summaries.value[row.id] = result.data;
    } catch {}
  }
  transactions.value[row.id] = summaries.value[row.id]?.transactions || [];
}

watch([dateFrom, dateTo, perPage, selectedBranch, () => filters.statusFilter.value], () => { currentPage.value = 1; fetchData(); });
watch([sessionType, deviceFilter, hasVariance], () => { currentPage.value = 1; fetchData(); });
watch(currentPage, fetchData);

const formatDateTime = (v) => v ? new Date(v).toLocaleString('en-US', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—';
const formatDate = (v) => v ? new Date(v).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }) : '';
const formatTime = (v) => v ? new Date(v).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' }) : '';

const deviceLabel = (row) => row?.device_name || (localStorage.getItem('pos_device_name') || '').trim() || (row?.device_id ? `Device ${row.device_id}` : '—');
const statusLabel = (s) => ({ open: 'نشطة', closed: 'مغلقة', cancelled: 'ملغاة' }[s] || s);
const statusClass = (s) => ({ open: 'text-blue-600 border-blue-100 bg-blue-50', closed: 'text-slate-400 border-slate-100 bg-slate-50', cancelled: 'text-rose-600 border-rose-100 bg-rose-50' }[s] || 'bg-slate-50');
const getbranchName = (id) => branches.value.find(b => b.id === id)?.name || 'الفرع الرئيسي';

const getTransactionTypeLabel = (t) => ({
  sale: 'عملية بيع', expense: 'مصروف', income: 'إيراد إضافي',
  return: 'مرتجع مبيعات', withdrawal: 'سحب نقدي', deposit: 'إيداع نقدي',
  payment: 'دفعة', refund: 'استرداد', discount: 'خصم', tax: 'ضريبة'
}[t] || t);

const isExpense = (t) => ['expense', 'withdrawal', 'refund'].includes(t);

onMounted(async () => {
  try {
    const data = await bootstrapStore.fetchSessionsData();
    if (data.branches) branchStore.branches = data.branches;
  } catch (e) {
    await Promise.all([fetchSettings(), branchStore.fetchBranches().catch(() => {})]);
  }
  await ensureExemptionLoaded();
  if (!isExempt.value && authStore.user?.branch_id) { selectedBranch.value = String(authStore.user.branch_id); }
  fetchData();
});
</script>

<style scoped>
@keyframes loading { 0% { transform: translateX(100%); } 100% { transform: translateX(-100%); } }

.filter-input {
  @apply h-9 w-full bg-white border border-slate-200 rounded-md px-3 text-[11px] font-bold text-slate-700 focus:ring-2 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all;
}

.pagination-btn-v2 {
  @apply w-8 h-8 flex items-center justify-center rounded border border-slate-200 bg-white text-slate-500 hover:text-blue-600 hover:border-blue-200 disabled:opacity-40 transition-all;
}

.custom-scroll::-webkit-scrollbar { width: 4px; }
.custom-scroll::-webkit-scrollbar-thumb { @apply bg-slate-200 rounded-full; }

.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }

.slide-down-enter-active, .slide-down-leave-active { transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
.slide-down-enter-from, .slide-down-leave-to { opacity: 0; transform: translateY(-10px); }

@keyframes modalIn { from { opacity: 0; transform: scale(0.98) translateY(10px); } to { opacity: 1; transform: scale(1) translateY(0); } }
.animate-modalIn { animation: modalIn 0.2s cubic-bezier(0.16, 1, 0.3, 1); }

/* Drawer Slide */
.drawer-enter-active, .drawer-leave-active { transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1); }
.drawer-enter-from, .drawer-leave-to { transform: translateX(-100%); }
</style>