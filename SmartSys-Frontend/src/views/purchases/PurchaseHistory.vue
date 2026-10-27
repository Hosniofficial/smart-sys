<template>
  <div class="min-h-screen bg-[#fafafa] text-slate-900 font-sans antialiased selection:bg-indigo-100" dir="rtl">
    
    <!-- Top Progress Bar -->
    <PageLoadingBar :active="isLoadingPurchases || isLoadingDetails" />

    <div class="max-w-[1600px] mx-auto p-6 lg:p-10 space-y-8">
      
      <!-- Page Header -->
      <PageHeader
        :breadcrumb="breadcrumb"
        title="سجل المشتريات"
        description="إدارة عمليات التوريد، تتبع الفواتير، وتسجيل الدفعات للموردين."
        :branches="branches"
        :selectedBranch="selectedBranch"
        :hasExplicitSelection="hasExplicitBranchSelection"
        @branch-changed="onBranchChange"
      >
        <template #controls>
          <button 
            @click="filters.showFilters.value = !filters.showFilters.value" 
            :class="[filters.showFilters.value ? 'bg-slate-900 text-white' : 'bg-white text-slate-700 border-slate-200']"
            class="h-9 px-4 rounded-md border text-xs font-bold transition-all flex items-center gap-2 shadow-sm"
          >
            <i class="fas fa-filter text-[10px]"></i>
            {{ filters.showFilters.value ? 'إخفاء الفلاتر' : 'تصفية النتائج' }}
          </button>
        </template>
      </PageHeader>

      <!-- KPI Summary -->
      <section class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-6">
        <div v-for="kpi in [
          { label: 'عدد الفواتير', val: kpiCount, icon: 'fa-file-invoice', color: 'text-indigo-600', bg: 'bg-indigo-50' },
          { label: 'إجمالي المشتريات', val: formatPrice(kpiSum), icon: 'fa-truck-loading', color: 'text-emerald-600', bg: 'bg-emerald-50' },
          { label: 'إجمالي الضريبة', val: formatPrice(kpiTax || 0), icon: 'fa-receipt', color: 'text-blue-600', bg: 'bg-blue-50', show: kpiTax != null },
          { label: 'إجمالي الخصومات', val: formatPrice(kpiDiscount || 0), icon: 'fa-tag', color: 'text-rose-600', bg: 'bg-rose-50', show: kpiDiscount != null }
        ].filter(k => k.show !== false)" :key="kpi.label" class="bg-white border border-slate-200 p-5 rounded-xl flex items-center justify-between group hover:border-slate-300 transition-all shadow-sm">
          <div>
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">{{ kpi.label }}</p>
            <p class="text-xl font-bold text-slate-900">{{ kpi.val }}</p>
          </div>
          <div :class="[kpi.bg, kpi.color]" class="w-10 h-10 rounded-lg flex items-center justify-center text-sm opacity-80 group-hover:opacity-100 transition-opacity">
            <i :class="['fas', kpi.icon]"></i>
          </div>
        </div>
      </section>

      <!-- Filters Panel -->
      <transition name="slide-down">
        <div v-if="filters.showFilters.value" class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden animate-fadeIn">
          <div class="p-6 space-y-6">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
              <div class="space-y-1.5">
                <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">بحث سريع</label>
                <div class="relative">
                  <input v-model="filters.searchQuery.value" type="text" class="filter-input" style="padding-right: 2rem;" placeholder="رقم الفاتورة أو المورد..." />
                  <i class="fas fa-search absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px] pointer-events-none"></i>
                </div>
              </div>

              <div class="space-y-1.5">
                <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">من تاريخ</label>
                <div class="relative">
                  <input ref="dateFromRef" type="date" v-model="filters.dateFrom.value" class="filter-input" style="padding-left: 2rem;" />
                  <i @click="dateFromRef?.showPicker?.()" class="fas fa-calendar absolute left-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px] cursor-pointer hover:text-slate-500 transition-colors"></i>
                </div>
              </div>
              <div class="space-y-1.5">
                <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">إلى تاريخ</label>
                <div class="relative">
                  <input ref="dateToRef" type="date" v-model="filters.dateTo.value" class="filter-input" style="padding-left: 2rem;" />
                  <i @click="dateToRef?.showPicker?.()" class="fas fa-calendar absolute left-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px] cursor-pointer hover:text-slate-500 transition-colors"></i>
                </div>
              </div>

              <div class="space-y-1.5">
                <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">حالة الفاتورة</label>
                <select v-model="filters.statusFilter.value" class="filter-input appearance-none">
                  <option value="">كل الحالات</option>
                  <option value="draft">مسودة</option>
                  <option value="pending">قيد الانتظار</option>
                  <option value="approved">معتمدة</option>
                  <option value="received">مستلمة</option>
                  <option value="due">مستحقة</option>
                  <option value="partial">مدفوعة جزئيًا</option>
                  <option value="paid">مدفوعة</option>
                  <option value="settled">مسددة</option>
                  <option value="returned">مرتجعة</option>
                  <option value="canceled">ملغاة</option>
                  <option value="rejected">مرفوضة</option>
                </select>
              </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end pt-4 border-t border-slate-50">
              <div class="md:col-span-2 space-y-1.5 relative">
                <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">تصفية حسب المورد</label>
                <div class="relative">
                  <input v-model="filters.customerSearch.value" type="text" class="filter-input" style="padding-right: 2rem;" placeholder="ابحث عن مورد..." @focus="filters.showCustomerDropdown.value = true" @blur="hideSupplierDropdown" />
                  <i class="fas fa-truck absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px] pointer-events-none"></i>
                  <div v-if="filters.showCustomerDropdown.value" class="absolute z-50 w-full mt-1 bg-white border border-slate-200 rounded-lg shadow-xl max-h-48 overflow-auto">
                    <button @mousedown.prevent="filters.clearCustomerFilter()" class="w-full text-right px-4 py-2 text-[11px] hover:bg-slate-50 border-b border-slate-50 text-indigo-600 font-bold">جميع الموردين</button>
                    <button v-for="s in filteredSuppliers" :key="s.id" @mousedown.prevent="selectSupplier(s)" class="w-full text-right px-4 py-2 text-[11px] hover:bg-slate-50 border-b border-slate-50 last:border-0 font-medium">{{ s.name || s.supplier_name }}</button>
                  </div>
                </div>
              </div>

              <button @click="resetFilters" class="h-9 w-full rounded-md bg-slate-100 text-slate-600 text-[11px] font-bold hover:bg-slate-200 transition-all">إعادة تعيين الفلاتر</button>
            </div>
          </div>
        </div>
      </transition>

      <!-- Active Filter Chips -->
      <div v-if="filters.hasActiveFilters.value || filters.customerFilter.value || (isExempt.value && hasExplicitBranchSelection)" class="flex flex-wrap gap-2">
        <div v-for="chip in [
          { show: filters.searchQuery.value, label: filters.searchQuery.value, clear: () => filters.searchQuery.value = '' },
          { show: filters.dateFrom.value, label: 'من: ' + filters.dateFrom.value, clear: () => filters.dateFrom.value = '' },
          { show: filters.dateTo.value, label: 'إلى: ' + filters.dateTo.value, clear: () => filters.dateTo.value = '' },
          { show: filters.statusFilter.value, label: getStatusLabel(filters.statusFilter.value), clear: () => filters.statusFilter.value = '' },
          { show: filters.customerFilter.value, label: filters.customerSearch.value, clear: () => filters.clearCustomerFilter() },
          { show: isExempt.value && hasExplicitBranchSelection, label: 'الفرع: ' + (branches?.find(b => b.id == selectedBranch)?.name || selectedBranch), clear: () => onBranchChange(null) }
        ].filter(c => c.show)" :key="chip.label" class="inline-flex items-center gap-2 px-2.5 py-1 bg-indigo-50 border border-indigo-100 rounded-md text-[10px] font-bold text-indigo-700">
          {{ chip.label }} <i @click="chip.clear" class="fas fa-times cursor-pointer hover:text-indigo-900 opacity-60"></i>
        </div>
      </div>

      <!-- Main Data Table -->
      <div class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm relative">
        <div class="overflow-x-auto">
          <table class="w-full text-right border-collapse">
            <thead>
              <tr class="bg-slate-50 border-b border-slate-200">
                <th class="px-6 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">رقم الفاتورة</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">التاريخ</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">المورد</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">الأصناف</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">الإجمالي</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">الحالة</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">إجراءات</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <template v-if="isLoadingPurchases">
                <tr v-for="n in 5" :key="n" class="animate-pulse"><td v-for="m in 7" :key="m" class="px-6 py-4"><div class="h-3 bg-slate-100 rounded w-full"></div></td></tr>
              </template>
              <tr v-else-if="rows.length === 0"><td colspan="7" class="py-20 text-center text-slate-300"><i class="fas fa-box-open text-3xl mb-4 opacity-20"></i><p class="text-xs font-bold uppercase tracking-widest">لا توجد فواتير مشتريات حتى الآن</p></td></tr>
              <tr v-else v-for="purchase in rows" :key="purchase?.id" class="hover:bg-indigo-50/10 transition-all group">
                <td class="px-6 py-4 text-xs font-bold text-slate-900 font-mono tracking-wider">{{ purchase.invoice_number || ('#' + purchase.id) }}</td>
                <td class="px-4 py-4 text-[10px] font-mono text-slate-400">{{ formatDateTime(purchase.invoice_date) }}</td>
                <td class="px-4 py-4 text-xs font-bold text-slate-700 truncate max-w-[180px]">{{ purchase.supplier_name }}</td>
                <td class="px-4 py-4 text-center">
                  <span class="px-2 py-0.5 bg-slate-100 rounded text-[10px] font-bold text-slate-500">
                    {{ purchase.total_items ?? purchase.items_count ?? (Array.isArray(purchase.items) ? purchase.items.length : '-') }}
                  </span>
                </td>
                <td class="px-4 py-4 text-xs font-bold text-emerald-600">{{ formatPrice(purchase.total_amount) }}</td>
                <td class="px-4 py-4 text-center">
                  <span :class="[getDynamicStatusClass(purchase.dynamic_status || purchase.status)]" class="px-2 py-0.5 rounded text-[9px] font-bold border">
                    {{ getStatusLabel(purchase.dynamic_status || purchase.status) }}
                  </span>
                </td>
                <td class="px-4 py-4 text-center">
                  <div class="flex items-center justify-center gap-2">
                    <button @click="openPurchaseDetails(purchase.id)" class="w-8 h-8 rounded-lg border border-slate-200 text-slate-400 hover:text-indigo-600 hover:border-indigo-200 transition-all flex items-center justify-center" :aria-label="`فتح تفاصيل فاتورة الشراء ${purchase.invoice_number || purchase.id} في تبويب`"><i class="fas fa-eye text-[10px]"></i></button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Pagination -->
        <div class="px-6 py-4 bg-slate-50/50 border-t border-slate-200 flex items-center justify-between">
          <div class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">
            صفحة <span class="text-slate-900">{{ filters.page.value }}</span> من <span class="text-slate-900">{{ totalPages }}</span>
            <span class="mx-2 text-slate-200">|</span> إجمالي <span class="text-slate-900">{{ filters.total.value }}</span> فاتورة
          </div>
          <div class="flex items-center gap-3">
             <div class="flex items-center gap-2"><span class="text-[10px] font-bold text-slate-400 uppercase tracking-tighter">النتائج:</span><select v-model.number="filters.perPage.value" class="h-8 border border-slate-200 rounded px-2 text-[10px] font-bold outline-none"><option :value="10">10</option><option :value="20">20</option><option :value="50">50</option></select></div>
             <PaginationControls
               :page="filters.page.value"
               :total-pages="totalPages"
               @previous="filters.previousPage()"
               @next="filters.nextPage(totalPages)"
             />
          </div>
        </div>
      </div>
    </div>

    <!-- Purchase Details Modal -->
    <HistoryDetailsModal
      :show="showDetailsModal && !!selectedPurchase"
      @close="closePurchaseDetails"
      :title="`تفاصيل فاتورة الشراء #${selectedPurchase?.invoice_number || selectedPurchase?.id}`"
      icon="fas fa-file-invoice"
    >

      <div class="space-y-6">
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
          <div v-for="info in [
            { l: 'المورد', v: selectedPurchase?.supplier_name || selectedPurchase?.supplier?.name || '-' },
            { l: 'الفرع', v: selectedPurchase?.branch_name || branches.find(branch => String(branch.id) === String(selectedPurchase?.branch_id))?.name || '-' },
            { l: 'التاريخ', v: formatDateTime(selectedPurchase?.invoice_date || selectedPurchase?.created_at) },
            { l: 'الكاشير', v: selectedPurchase?.created_by_name || 'النظام' },
            { l: 'الحالة', v: getStatusLabel(selectedPurchase?.dynamic_status || selectedPurchase?.status) }
          ]" :key="info.l" class="min-w-0 space-y-1 rounded-lg border border-slate-100 bg-slate-50/60 px-2.5 py-2">
            <p class="text-[9px] font-bold text-slate-400 uppercase tracking-wide">{{ info.l }}</p>
            <p class="break-words text-[11px] font-bold leading-4 text-slate-800">{{ info.v }}</p>
          </div>
        </div>

        <div class="overflow-x-auto rounded-xl border border-slate-100 shadow-sm">
          <table class="w-full text-right text-xs">
            <thead><tr class="bg-slate-50 border-b border-slate-100"><th class="px-4 py-3 text-[10px] font-bold text-slate-400 uppercase">المنتج</th><th class="px-4 py-3 text-[10px] font-bold text-slate-400 uppercase text-center">الكمية</th><th class="px-4 py-3 text-[10px] font-bold text-slate-400 uppercase text-center">سعر الوحدة</th><th class="px-4 py-3 text-[10px] font-bold text-slate-400 uppercase text-center">الإجمالي</th></tr></thead>
            <tbody class="divide-y divide-slate-50">
              <tr v-for="(it, idx) in (selectedPurchase?.items || selectedPurchase?.purchase?.items || [])" :key="idx">
                <td class="px-4 py-3 font-bold text-slate-800">{{ it.product_name || it.name || '-' }}</td>
                <td class="px-4 py-3 text-center font-bold text-slate-500">{{ it.quantity || it.qty || 0 }}</td>
                <td class="px-4 py-3 text-center font-bold text-slate-700">{{ formatPrice(itemUnitPrice(it)) }}</td>
                <td class="px-4 py-3 text-center font-bold text-slate-900">{{ formatPrice(itemTotal(it)) }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="flex flex-col md:flex-row gap-8">
          <div class="flex-1 p-5 rounded-xl bg-slate-50/50 border border-slate-100">
            <p class="text-[10px] font-bold text-slate-400 uppercase mb-2">ملاحظات الفاتورة</p>
            <p class="text-xs text-slate-600 italic">{{ selectedPurchase?.notes || 'لا توجد ملاحظات.' }}</p>
          </div>
          <div class="w-full md:w-72 space-y-2.5 bg-slate-50 border border-slate-200 p-5 rounded-xl">
            <div class="flex justify-between text-[11px] font-medium text-slate-500"><span>المجموع</span><span class="font-mono font-bold text-slate-700">{{ formatPrice(selectedPurchase?.total_amount || 0) }}</span></div>
            <div class="flex justify-between text-[11px] font-medium text-slate-500 pb-2.5 border-b border-slate-200"><span>المدفوع</span><span class="font-mono font-bold text-emerald-600">{{ formatPrice(selectedPurchase?.actual_paid_amount ?? selectedPurchase?.paid_amount ?? 0) }}</span></div>
            <!-- صافي الفاتورة مميز -->
            <div class="flex justify-between items-center bg-indigo-600 text-white px-4 py-3 rounded-lg">
              <span class="text-[11px] font-bold uppercase tracking-widest opacity-80">صافي الفاتورة</span>
              <span class="text-xl font-bold font-mono tracking-tighter">{{ formatPrice(selectedPurchase?.total_amount || 0) }}</span>
            </div>
            <div class="pt-1 space-y-2">
              <div v-if="Number(selectedPurchase?.return_amount || 0) > 0" class="flex justify-between text-[11px] font-medium text-slate-500">
                <span>قيمة المرتجعات</span>
                <span class="font-mono font-bold text-amber-500">{{ formatPrice(selectedPurchase.return_amount) }}</span>
              </div>
              <div v-if="(selectedPurchase?.remaining_balance ?? ((selectedPurchase?.total_amount || 0) - (selectedPurchase?.actual_paid_amount ?? selectedPurchase?.paid_amount ?? 0))) > 0" class="flex justify-between text-[11px] font-bold pt-1 border-t border-slate-200">
                <span class="text-slate-500">المتبقي</span>
                <span class="font-mono text-rose-600">{{ formatPrice(selectedPurchase?.remaining_balance ?? ((selectedPurchase?.total_amount || 0) - (selectedPurchase?.actual_paid_amount ?? selectedPurchase?.paid_amount ?? 0))) }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <template #footer>
        <div class="mr-auto flex flex-row flex-wrap items-center gap-3">
          <!-- 1. تسجيل دفعة -->
          <button
            v-if="canPaySelectedPurchase"
            @click="openPaymentModal(selectedPurchase)"
            class="inline-flex h-10 shrink-0 items-center justify-center gap-2 whitespace-nowrap rounded-md bg-emerald-600 px-4 text-xs font-bold text-white shadow-sm shadow-emerald-900/20 transition-all hover:bg-emerald-700"
          >
            <i class="fas fa-money-bill-wave text-[10px]"></i> تسجيل دفعة
          </button>
          <!-- 2. طباعة الفاتورة -->
          <button
            @click="printPurchaseDetails"
            class="inline-flex h-10 shrink-0 items-center justify-center gap-2 whitespace-nowrap rounded-md border border-indigo-200 px-4 text-xs font-bold text-indigo-700 hover:bg-indigo-50"
          >
            <i class="fas fa-print"></i> طباعة الفاتورة
          </button>
          <!-- 3. إغلاق -->
          <button
            @click="closePurchaseDetails"
            class="inline-flex h-10 shrink-0 items-center justify-center whitespace-nowrap px-4 text-xs font-bold text-slate-500"
          >
            إغلاق
          </button>
        </div>
      </template>
    </HistoryDetailsModal>

    <!-- Payment Modal -->
    <BaseModal :show="showPaymentModal" @close="showPaymentModal = false" maxWidth="md" align="center" :z-index="130">
      <template #header>
        <div class="text-center w-full">
          <h3 class="text-sm font-bold text-slate-900 uppercase">تسجيل دفعة مورد</h3>
          <p class="text-[10px] text-slate-400 mt-1 font-medium italic">
            فاتورة {{ currentPayingPurchase?.invoice_number || `#${currentPayingPurchase?.id}` }}
            <span v-if="currentPayingPurchase?.remaining_balance > 0" class="text-rose-500 mr-2">
              · متبقي {{ formatPrice(currentPayingPurchase.remaining_balance) }}
            </span>
          </p>
        </div>
      </template>

      <div class="space-y-6">
        <div class="text-center">
          <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-3 block">المبلغ المراد دفعه</label>
          <input type="number" min="0.01" step="0.01" :max="currentPayingPurchase?.remaining_balance" v-model.number="paymentData.amount" class="w-full h-14 text-3xl font-bold text-center text-emerald-600 border-b-2 border-slate-100 focus:border-emerald-500 outline-none transition-all" />
        </div>
        <div class="grid grid-cols-2 gap-4">
          <div class="space-y-1.5"><label class="text-[10px] font-bold text-slate-400 uppercase">التاريخ</label><div class="relative"><input ref="paymentDateRef" type="date" v-model="paymentData.payment_date" class="filter-input" style="padding-left: 2rem;" /><i @click="paymentDateRef?.showPicker?.()" class="fas fa-calendar-days absolute left-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px] cursor-pointer hover:text-slate-500"></i></div></div>
          <div class="space-y-1.5"><label class="text-[10px] font-bold text-slate-400 uppercase">طريقة الدفع</label><select v-model.number="paymentData.payment_method_id" class="filter-input"><option v-for="m in paymentMethods" :key="m.id" :value="m.id">{{ m.name }}</option></select></div>
        </div>
        <div class="space-y-1.5"><label class="text-[10px] font-bold text-slate-400 uppercase">رقم المرجع</label><input type="text" v-model="paymentData.reference_number" class="filter-input" placeholder="شيك، رقم تحويل..." /></div>
      </div>

      <template #footer>
        <button @click="showPaymentModal = false" class="flex-1 h-10 text-xs font-bold text-slate-500">إلغاء</button>
        <LoadingButton
          v-if="canPayPurchases"
          text="تأكيد العملية"
          :isLoading="isSubmittingPayment"
          loadingText="جاري التسجيل..."
          variant="success"
          class="flex-[2] h-10"
          @click="submitPayment"
        />
      </template>
    </BaseModal>

  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import HistoryDetailsModal from '@/components/HistoryDetailsModal.vue';
import { useRoute, useRouter } from 'vue-router';

import BaseSkeleton from '@/components/ui/BaseSkeleton.vue';
import BaseModal from '@/components/BaseModal.vue';
import { useHistoryFilters } from '@/composables/useHistoryFilters';
import { useToast } from '@/composables/useToast';
import { useCompanyCurrency } from '@/composables/useCompanyCurrency';
import { useSessionExemption } from '@/composables/useCashierSessionGuard';
import { useBranchIsolation } from '@/composables/useBranchIsolation';
import { useBreadcrumb } from '@/composables/useBreadcrumb';
import { useSessionStore } from '@/stores/session/sessionStore';
import { useAuthStore } from '@/stores/auth/authStore';
import { useBranchStore } from '@/stores/branch/branchStore';
import { usePaymentStore } from '@/stores/payment/paymentStore';
import { useSupplierStore } from '@/stores/supplier/supplierStore';
import { usePurchaseStore } from '@/stores/purchase/purchaseStore';
import { useBootstrapStore } from '@/stores/bootstrap/bootstrapStore';
import { getLocalDateISO, isSettingEnabled, printDocument } from '@/utils';
import { buildPurchaseHtml } from '@/utils/printTemplates';
import PageHeader from '@/components/PageHeader.vue';
import PaginationControls from '@/components/ui/PaginationControls.vue';

const { showToast } = useToast();
const { formatCurrencyLocale, fetchSettings } = useCompanyCurrency();
const { isExempt, ensureLoaded: ensureExemptionLoaded } = useSessionExemption();
const { breadcrumb } = useBreadcrumb();
const authStore = useAuthStore();
const canPayPurchases = computed(() =>
  authStore.isSuperAdmin || authStore.user?.permissions?.includes('purchase.payment.create') === true
);
const canPaySelectedPurchase = computed(() => {
  const purchase = selectedPurchase.value;
  if (!canPayPurchases.value || !purchase) return false;
  const status = String(purchase.dynamic_status || purchase.status || '').toLowerCase();
  if (['paid', 'completed', 'cancelled', 'canceled', 'rejected', 'returned'].includes(status)) return false;
  return Number(purchase.remaining_balance ?? 0) > 0.01;
});
const branchStore = useBranchStore();
const branchIsolation = useBranchIsolation();
const purchaseStore = usePurchaseStore();
const paymentStore = usePaymentStore();
const supplierStore = useSupplierStore();
const bootstrapStore = useBootstrapStore();

const route = useRoute();
const router = useRouter();
const detailsAbortCtrl = ref(null);
const showDetailsModal = ref(false);
const selectedPurchase = ref(null);

const filters = useHistoryFilters('purchases_hist_filters');
filters.loadFromLocalStorage();

const rows = ref([]);
const isLoadingPurchases = ref(false);
const isLoadingDetails = ref(false);

const dateFromRef = ref(null);
const dateToRef = ref(null);

const branches = computed(() => branchStore.branches);
const selectedBranch = computed({
  get: () => branchStore.selectedBranchId,
  set: (val) => branchStore.setSelectedBranch(val)
});

const userChoseBranch = ref(
  localStorage.getItem('selectedBranchId') !== null
  && localStorage.getItem('selectedBranchId') !== 'all'
);
const hasExplicitBranchSelection = computed(() => userChoseBranch.value && branchStore.selectedBranchId !== null);

const onBranchChange = (newBranchId) => {
  branchStore.setSelectedBranch(newBranchId);
  userChoseBranch.value = (newBranchId !== null && newBranchId !== '' && newBranchId !== 'all');
  if (filters.page.value === 1) {
    fetchPurchases(true);
  } else {
    filters.page.value = 1;
  }
};

const suppliers = computed(() => supplierStore.suppliers);
const filteredSuppliers = computed(() => {
  const q = (filters.customerSearch.value || '').toLowerCase();
  const list = suppliers.value || [];
  if (!q) return list.slice(0, 50);
  return list.filter(s => String(s.name || s.supplier_name || '').toLowerCase().includes(q)).slice(0, 50);
});

const selectSupplier = (s) => { filters.setCustomerFilter(s.id, s.name || s.supplier_name || ''); };
const hideSupplierDropdown = () => filters.hideCustomerDropdown();

const kpiCount = computed(() => filters.total.value || rows.value.length);
const kpiSum = computed(() => rows.value.reduce((s, p) =>
  s + (['cancelled', 'canceled', 'rejected'].includes(p.dynamic_status || p.status) ? 0 : parseFloat(p.total_amount || p.total || 0)), 0));
const kpiTax = computed(() => {
  const activeRows = rows.value.filter(p => !['cancelled', 'canceled', 'rejected'].includes(p.dynamic_status || p.status));
  const any = activeRows.some(p => p.tax_amount != null);
  return any ? activeRows.reduce((s, p) => s + parseFloat(p.tax_amount || 0), 0) : null;
});
const kpiDiscount = computed(() => {
  const activeRows = rows.value.filter(p => !['cancelled', 'canceled', 'rejected'].includes(p.dynamic_status || p.status));
  const any = activeRows.some(p => p.discount_amount != null || p.discount_value != null);
  return any ? activeRows.reduce((s, p) => s + parseFloat((p.discount_amount ?? p.discount_value) || 0), 0) : null;
});
const totalPages = computed(() => Math.max(1, Math.ceil((filters.total.value || 0) / filters.perPage.value)));

const formatPrice = (amount) => formatCurrencyLocale(amount, 2);
const formatDateTime = (value) => {
  if (!value) return '';
  const parsed = new Date(String(value).replace(' ', 'T'));
  return Number.isNaN(parsed.getTime())
    ? String(value)
    : parsed.toLocaleString('ar-EG', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: true });
};

const itemUnitPrice = (item) => Number(item?.price ?? item?.unit_price ?? item?.purchase_price ?? 0) || 0;
const itemTotal = (item) => Number(item?.total ?? item?.subtotal ?? (itemUnitPrice(item) * Number(item?.quantity ?? item?.qty ?? 0))) || 0;

const paymentMethods = computed(() => paymentStore.paymentMethods);
const cashMethodId = computed(() => paymentMethods.value.find(pm => pm.kind === 'cash')?.id || null);
const isCashById = (id) => paymentMethods.value.find(pm => Number(pm.id) === Number(id))?.kind === 'cash';

const getDynamicStatusClass = (status) => {
  const statusMap = {
    'draft': 'bg-slate-50 text-slate-400 border-slate-100',
    'pending': 'bg-amber-50 text-amber-600 border-amber-100',
    'approved': 'bg-blue-50 text-blue-600 border-blue-100',
    'received': 'bg-indigo-50 text-indigo-600 border-indigo-100',
    'due': 'bg-amber-50 text-amber-600 border-amber-100',
    'completed': 'bg-emerald-50 text-emerald-600 border-emerald-100',
    'partial': 'bg-amber-50 text-amber-600 border-amber-100',
    'paid': 'bg-emerald-50 text-emerald-600 border-emerald-100',
    'settled': 'bg-emerald-50 text-emerald-600 border-emerald-100',
    'returned': 'bg-orange-50 text-orange-600 border-orange-100',
    'cancelled': 'bg-rose-50 text-rose-600 border-rose-100',
    'canceled': 'bg-rose-50 text-rose-600 border-rose-100',
    'rejected': 'bg-rose-50 text-rose-600 border-rose-100'
  };
  return statusMap[status] || 'bg-slate-50 text-slate-400 border-slate-100';
};

const getStatusLabel = (status) => {
  const labels = {
    draft: 'مسودة',
    pending: 'قيد الانتظار',
    approved: 'معتمدة',
    received: 'مستلمة',
    due: 'مستحقة',
    partial: 'مدفوعة جزئياً',
    paid: 'مدفوعة',
    settled: 'مسددة',
    returned: 'مرتجعة',
    completed: 'مكتملة',
    cancelled: 'ملغاة',
    canceled: 'ملغاة',
    rejected: 'مرفوضة'
  };
  const normalized = String(status || '').toLowerCase();
  return labels[normalized] || 'غير محددة';
};

let purchasesAbortCtrl = null;
const fetchPurchases = async (forceRefresh = false) => {
  if (purchasesAbortCtrl) purchasesAbortCtrl.abort();
  purchasesAbortCtrl = new AbortController();
  const currentCtrl = purchasesAbortCtrl;
  isLoadingPurchases.value = true;
  try {
    let branchId = selectedBranch.value || null;
    if (!isExempt.value) {
      try { branchId = branchIsolation.getRequiredBranchId(); }
      catch (e) { rows.value = []; filters.total.value = 0; showToast(e.message || 'لم يتم تعيين مخزن.', 'error'); return; }
    }
    const params = { ...filters.getApiParams({ supplierId: filters.customerFilter.value || undefined, force: forceRefresh }), branchId: branchId };
    const response = await purchaseStore.fetchPurchasesList(params);
    if (currentCtrl !== purchasesAbortCtrl) return;
    if (response?.status === 'success') {
      const resData = response.data;
      const list = Array.isArray(resData) ? resData : (resData?.items || []);
      rows.value = list; filters.total.value = resData?.total || list.length;
    } else { rows.value = []; filters.total.value = 0; }
  } catch (e) {
    if (e?.name !== 'AbortError' && e?.name !== 'CanceledError' && e?.code !== 'ERR_CANCELED') {
      showToast('فشل في تحميل سجل المشتريات', 'error'); rows.value = []; filters.total.value = 0;
    }
  } finally { if (currentCtrl === purchasesAbortCtrl) isLoadingPurchases.value = false; }
};

const resetFilters = () => { filters.resetFilters(); fetchPurchases(); };

watch(() => route.query.id, async (id) => {
  if (Number(id) > 0) {
    await viewPurchaseDetails(Number(id));
    return;
  }
  showDetailsModal.value = false;
  selectedPurchase.value = null;
});

const viewPurchaseDetails = async (purchaseId) => {
  if (detailsAbortCtrl.value) detailsAbortCtrl.value.abort();
  detailsAbortCtrl.value = new AbortController();
  const currentCtrl = detailsAbortCtrl.value;
  isLoadingDetails.value = true;
  try {
    const response = await purchaseStore.getPurchaseById(purchaseId, { signal: currentCtrl.signal });
    if (currentCtrl !== detailsAbortCtrl.value) return;
    if (response?.status === 'success' && response.data) {
      selectedPurchase.value = response.data;
      showDetailsModal.value = true;
    }
    else showToast('فشل في تحميل تفاصيل المشترية', 'error');
  } catch (e) {
    if (e?.name !== 'AbortError' && e?.name !== 'CanceledError' && e?.code !== 'ERR_CANCELED') {
      showToast('فشل في تحميل تفاصيل المشترية', 'error');
    }
  } finally { if (currentCtrl === detailsAbortCtrl.value) isLoadingDetails.value = false; }
};

const openPurchaseDetails = async (purchaseId) => {
  if (Number(route.query.id) === Number(purchaseId)) {
    await viewPurchaseDetails(purchaseId);
    return;
  }

  await router.push({
    name: 'PurchaseHistory',
    query: { ...route.query, id: String(purchaseId) }
  });
};

const closePurchaseDetails = async () => {
  showDetailsModal.value = false;
  if (!route.query.id) return;

  const query = { ...route.query };
  delete query.id;
  await router.replace({ name: 'PurchaseHistory', query });
};

const showPaymentModal = ref(false);
const isSubmittingPayment = ref(false);
const paymentData = ref({ purchase_id: null, supplier_id: null, amount: 0, payment_method_id: null, payment_date: getLocalDateISO(), reference_number: '' });
const currentPayingPurchase = ref(null);
const paymentIdempotencyKey = ref('');
const paymentDateRef = ref(null);

const openPaymentModal = (p) => {
  if (!canPayPurchases.value || !p) return;
  const remaining = Number(p.remaining_balance ?? (
    Number(p.total_amount || 0)
    - Number(p.actual_paid_amount ?? p.paid_amount ?? 0)
    - Number(p.return_amount || 0)
  ));
  if (remaining <= 0.01) return;
  paymentData.value = { purchase_id: p.id, supplier_id: p.supplier_id, amount: remaining, payment_method_id: cashMethodId.value ?? paymentMethods.value[0]?.id ?? null, payment_date: getLocalDateISO(), reference_number: '' };
  paymentIdempotencyKey.value = globalThis.crypto?.randomUUID?.()
    || `purchase-payment-${Date.now()}-${Math.random().toString(36).slice(2)}`;
  showPaymentModal.value = true; currentPayingPurchase.value = p;
};

const submitPayment = async () => {
  if (!canPayPurchases.value || isSubmittingPayment.value) return;
  const amount = Number(paymentData.value.amount || 0);
  if (amount <= 0) return showToast('الرجاء إدخال مبلغ صحيح', 'error');
  const remaining = Number(currentPayingPurchase.value?.remaining_balance ?? 0);
  if (amount > remaining + 0.01) return showToast('مبلغ الدفعة يتجاوز الرصيد المتبقي على الفاتورة.', 'error');
  isSubmittingPayment.value = true;
  try {
    try { await ensureExemptionLoaded(); } catch {}
    const settings = await fetchSettings(true);
    const sessionsEnabled = isSettingEnabled(settings?.['pos.sessions.enabled']);
    if (sessionsEnabled && !isExempt.value && isCashById(paymentData.value.payment_method_id) && amount > 0) {
      let wid; try { wid = branchIsolation.getRequiredBranchId(); } catch { wid = currentPayingPurchase.value?.branch_id; }
      if (!wid) return showToast('يجب تحديد الفرع', 'error');
      try { const sessionStore = useSessionStore(); const result = await sessionStore.getCurrentSession(wid, authStore?.user?.id || null); if (!result.data?.id) return showToast('لا توجد جلسة كاشير مفتوحة', 'error'); }
      catch { return showToast('خطأ في التحقق من الجلسة', 'error'); }
    }
    let branchId; try { branchId = branchIsolation.getRequiredBranchId(); } catch { branchId = currentPayingPurchase.value?.branch_id; }
    const res = await purchaseStore.addPayment(paymentData.value.purchase_id, { amount, payment_date: paymentData.value.payment_date, payment_method_id: paymentData.value.payment_method_id, reference_number: paymentData.value.reference_number || undefined, branch_id: String(branchId) }, paymentIdempotencyKey.value);
    if (res?.status === 'success') {
      showToast('تمت العملية', 'success');
      showPaymentModal.value = false;
      paymentIdempotencyKey.value = '';
      await fetchPurchases();
      const refreshedPurchase = await purchaseStore.getPurchaseById(paymentData.value.purchase_id, { force: true });
      if (refreshedPurchase?.status === 'success' && refreshedPurchase.data) {
        selectedPurchase.value = refreshedPurchase.data;
      } else {
        showToast('تم تسجيل الدفعة، لكن تعذر تحديث تفاصيل الفاتورة. أعد فتحها لمراجعة الرصيد.', 'warning');
      }
    } else {
      showToast(
        res?.outcomeUnknown
          ? 'تعذر تأكيد نتيجة الدفعة. أعد المحاولة دون تغيير البيانات لتجنب تكرارها.'
          : (res?.message || 'فشل تسجيل الدفعة'),
        'error'
      );
    }
  } catch (e) {
    showToast(e.response?.data?.message || 'فشل التسجيل', 'error');
  } finally {
    isSubmittingPayment.value = false;
  }
};

const printPurchaseDetails = async () => {
  if (!selectedPurchase.value) return;
  const p = { ...selectedPurchase.value, items: selectedPurchase.value.items || selectedPurchase.value.purchase?.items || [] };
  await printDocument(buildPurchaseHtml(p));
};

let searchDebounceTimer = null;

let isMounting = true;

watch(filters.searchQuery, () => { 
  if (isMounting) return;
  clearTimeout(searchDebounceTimer); filters.page.value = 1; searchDebounceTimer = setTimeout(fetchPurchases, 400); 
});

watch([filters.customerFilter, filters.dateFrom, filters.dateTo, filters.statusFilter], () => {
  if (isMounting) return;
  filters.page.value = 1; fetchPurchases(); 
});
watch(filters.page, () => { if (isMounting) return; fetchPurchases(); });
watch([filters.customerFilter, suppliers], () => {
  const found = suppliers.value.find(x => String(x.id) === String(filters.customerFilter.value));
  filters.customerSearch.value = found ? (found.name || found.supplier_name || '') : '';
});

onMounted(async () => {
  try {
    const data = await bootstrapStore.fetchManagementData('purchase');
    if (data.branches && isExempt.value) branchStore.setBranches(data.branches);
    if (data.paymentMethods) paymentStore.paymentMethods = data.paymentMethods;
    if (data.suppliers) supplierStore.suppliers = data.suppliers;
    await Promise.all([fetchSettings(), ensureExemptionLoaded()]);
  } catch {
    await Promise.all([fetchSettings(), ensureExemptionLoaded()]);
    if (isExempt.value) await branchStore.fetchBranches().catch(() => {});
    await paymentStore.fetchPaymentMethods().catch(() => {});
    await supplierStore.fetchSuppliers().catch(() => {});
  }
  
  const hadPriorBranchChoice = localStorage.getItem('selectedBranchId') !== null
                                && localStorage.getItem('selectedBranchId') !== 'all';
  try {
    branchStore.loadFromStorage();
    if (!branchStore.branches || branchStore.branches.length === 0) {
      await branchStore.fetchBranches();
    }
  } catch (err) {
    console.error('[PurchaseHistory] Failed to initialize branches:', err);
    showToast('فشل في تحميل قائمة الفروع', 'error');
    return;
  }
  userChoseBranch.value = hadPriorBranchChoice;
  
  let resolvedBranchId = branchStore.selectedBranchId;
  if (!isExempt.value) {
    try {
      resolvedBranchId = branchIsolation.getRequiredBranchId();
    } catch (err) {
      console.error('[PurchaseHistory] Failed to resolve branch ID:', err);
      showToast(err.message || 'لم يتم تعيين الفرع', 'error');
      return;
    }
  }
  
  console.log('[PurchaseHistory] Before first API call:', {
    selectedBranchId: branchStore.selectedBranchId,
    selectedBranch: branchStore.selectedBranch?.name || null,
    isExempt: isExempt.value,
    resolvedBranchId,
    branchesCount: branchStore.branches.length
  });

  isMounting = false;
  await new Promise(resolve => setTimeout(resolve, 100));
  fetchPurchases();
  const qid = Number(route.query.id || 0);
  if (qid > 0) await viewPurchaseDetails(qid);
});

onUnmounted(() => { if (purchasesAbortCtrl) purchasesAbortCtrl.abort(); if (searchDebounceTimer) clearTimeout(searchDebounceTimer); });
</script>

<style scoped>
/* Animations */
@keyframes loading {
  0% { transform: translateX(100%); }
  100% { transform: translateX(-100%); }
}

@keyframes modalIn {
  from { opacity: 0; transform: scale(0.98) translateY(10px); }
  to { opacity: 1; transform: scale(1) translateY(0); }
}

/* Form Inputs */
.filter-input {
  @apply h-9 w-full bg-white border border-slate-200 rounded-md px-3 text-[11px] font-bold text-slate-700 focus:ring-2 focus:ring-indigo-500/10 focus:border-indigo-500 outline-none transition-all;
}

/* Pagination */
/* Scrollbar */
.custom-scroll::-webkit-scrollbar {
  width: 5px;
}

.custom-scroll::-webkit-scrollbar-thumb {
  @apply bg-slate-200 rounded-full;
}

/* Transition Classes */
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}

.slide-down-enter-active,
.slide-down-leave-active {
  transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
}

.slide-down-enter-from,
.slide-down-leave-to {
  opacity: 0;
  transform: translateY(-10px);
}

/* Animation Classes */
.animate-modalIn {
  animation: modalIn 0.2s cubic-bezier(0.16, 1, 0.3, 1);
}
</style>