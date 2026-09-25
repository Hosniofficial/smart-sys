<template>
  <div class="min-h-screen bg-[#fafafa] text-slate-900 font-sans antialiased selection:bg-amber-100" dir="rtl">
    
    <!-- Top Progress Bar -->
    <div v-if="isLoading || isSubmitting" class="fixed top-0 left-0 right-0 h-0.5 bg-amber-500/10 z-[110]">
      <div class="h-full bg-amber-500 animate-[loading_2s_ease-in-out_infinite] w-1/3"></div>
    </div>

    <div class="max-w-[1600px] mx-auto p-6 lg:p-10 space-y-8">
      
      <!-- Page Header Area -->
      <PageHeader
        :breadcrumb="breadcrumb"
        title="اعتماد الفواتير المعلقة"
        description="استعرض ووافق على الفواتير المعلقة في الانتظار"
        :branches="branches"
        :selectedBranch="selectedBranch"
        :hasExplicitSelection="hasExplicitBranchSelection"
        @branch-changed="onBranchChange"
      >
        <template #controls>
          <!-- Polling Indicator -->
          <div class="flex items-center gap-2 px-3 py-1.5 rounded-lg bg-white border border-slate-200 text-[9px] font-bold text-slate-400 uppercase tracking-widest">
            <div v-if="isPolling" class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></div>
            <div v-else class="w-2 h-2 rounded-full bg-slate-300"></div>
            <span>{{ isPolling ? 'مزامنة مستمرة' : 'متوقفة' }}</span>
            <span v-if="lastUpdatedLabel" class="text-slate-300 ml-1">• {{ lastUpdatedLabel }}</span>
          </div>

          <!-- New Invoice Alert -->
          <transition name="fade-scale">
            <div v-if="newInvoicesAlert" class="flex items-center gap-2 px-4 py-2 rounded-lg bg-amber-500 text-white text-xs font-bold shadow-lg shadow-amber-900/20 animate-bounce">
              <i class="fas fa-bell"></i>
              طلب جديد بانتظاركم
            </div>
          </transition>

          <div class="flex items-center gap-3 bg-white p-1 rounded-lg border border-slate-200 shadow-sm">
            <div class="px-4 py-1.5 border-l border-slate-100">
              <p class="text-[9px] font-bold text-slate-400 uppercase tracking-widest leading-none mb-1">المعلق</p>
              <p class="text-lg font-bold text-slate-900 leading-none font-mono tracking-tighter">{{ total }}</p>
            </div>
            <button @click="manualRefresh" :disabled="isLoading" class="h-8 w-8 flex items-center justify-center text-slate-400 hover:text-blue-600 transition-colors">
              <i class="fas fa-sync-alt text-xs" :class="{'animate-spin': isLoading}"></i>
            </button>
          </div>
        </template>
      </PageHeader>

      <!-- Main Content Card -->
      <div class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm relative">
        
        <!-- Summary Cards (Total/Paid/Balance) -->
        <div v-if="rows.length > 0" class="grid grid-cols-3 gap-4 p-4 bg-white border-b border-slate-100">
          <div class="p-3 rounded-lg bg-slate-50 border border-slate-200 text-center">
            <p class="text-[9px] font-bold text-slate-400 uppercase tracking-widest mb-1">إجمالي المعلق</p>
            <p class="text-base font-bold font-mono text-slate-900">{{ formatPrice(rows.reduce((sum, r) => sum + Number(r.net_total_amount ?? r.total_amount ?? 0), 0)) }}</p>
          </div>
          <div class="p-3 rounded-lg bg-blue-50 border border-blue-200 text-center">
            <p class="text-[9px] font-bold text-blue-600 uppercase tracking-widest mb-1">عدد الطلبات</p>
            <p class="text-base font-bold font-mono text-blue-900">{{ rows.length }}</p>
          </div>
          <div class="p-3 rounded-lg bg-emerald-50 border border-emerald-200 text-center">
            <p class="text-[9px] font-bold text-emerald-600 uppercase tracking-widest mb-1">متوسط الفاتورة</p>
            <p class="text-base font-bold font-mono text-emerald-900">{{ formatPrice(rows.length > 0 ? rows.reduce((sum, r) => sum + Number(r.net_total_amount ?? r.total_amount ?? 0), 0) / rows.length : 0) }}</p>
          </div>
        </div>

        <!-- High-Density Approvals Table Wrapper -->
        <div>
          <!-- High-Density Approvals Table -->
          <div class="overflow-x-auto">
            <table class="w-full text-right border-collapse">
              <thead>
                <tr class="bg-slate-50/50 border-b border-slate-200">
                  <th class="px-6 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest w-20">الرقم</th>
                  <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">التوقيت</th>
                  <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">العميل</th>
                  <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">الأصناف</th>
                  <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">المبلغ</th>
                  <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">الدفع</th>
                  <th class="px-8 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">الإجراء</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100 font-medium">
                <!-- Loading Skeleton -->
                <template v-if="isLoading">
                  <tr v-for="n in 5" :key="n" class="animate-pulse">
                    <td v-for="m in 7" :key="m" class="px-6 py-4"><div class="h-3 bg-slate-100 rounded w-full"></div></td>
                  </tr>
                </template>
                
                <!-- Empty State -->
                <tr v-else-if="!rows.length">
                  <td colspan="7" class="py-24 text-center text-slate-300">
                     <i class="fas fa-check-double text-3xl mb-4 opacity-20"></i>
                     <p class="text-xs font-bold uppercase tracking-widest">لا توجد فواتير معلقة</p>
                  </td>
                </tr>

                <!-- Data Rows -->
                <tr v-for="s in rows" :key="s.id" class="hover:bg-blue-50/20 transition-all group">
                  <td class="px-6 py-4 text-xs font-bold text-slate-900 font-mono">#{{ s.id }}</td>
                  <td class="px-4 py-4 text-[10px] font-mono text-slate-400">{{ formatDateTime(s.created_at || s.sale?.created_at) }}</td>
                  <td class="px-4 py-4 text-xs font-bold text-slate-700">{{ s.customer_name || s.sale?.customer_name || 'عميل نقدي' }}</td>
                  <td class="px-4 py-4 text-center">
                    <span class="px-2 py-0.5 bg-slate-100 rounded text-[10px] font-bold text-slate-500">
                      {{ s.total_items ?? s.items_count ?? s.sale?.items?.length ?? '-' }}
                    </span>
                  </td>
                  <td class="px-4 py-4 text-xs font-bold text-blue-600 font-mono tracking-tighter">
                    {{ formatPrice(s.net_total_amount ?? s.total_amount ?? s.sale?.net_total_amount) }}
                  </td>
                  <td class="px-4 py-4 text-center">
                    <span :class="[kindClass(s.payment_method_kind ?? s.sale?.payment_method_kind ?? s.payment_method?.kind)]" class="px-2 py-0.5 rounded text-[9px] font-bold border">
                      {{ kindLabel(s.payment_method_kind ?? s.sale?.payment_method_kind ?? s.payment_method?.kind) }}
                    </span>
                  </td>
                  <td class="px-8 py-4 text-center">
                    <div class="flex items-center justify-center gap-1.5">
                      <button @click="viewDetails(s.id)" class="w-8 h-8 rounded-lg border border-slate-200 text-slate-400 hover:text-blue-600 transition-all flex items-center justify-center"><i class="fas fa-eye text-[10px]"></i></button>
                      <button @click="openAction('approve', s.id)" class="h-8 px-3 rounded-lg bg-emerald-600 text-white text-[9px] font-bold hover:bg-emerald-700 transition-all shadow-sm">اعتماد</button>
                      <button @click="openAction('reject', s.id)" class="h-8 px-3 rounded-lg border border-rose-200 text-rose-600 text-[9px] font-bold hover:bg-rose-50 transition-all">رفض</button>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Pagination -->
          <div class="px-6 py-4 bg-slate-50/50 border-t border-slate-200 flex items-center justify-between">
            <div class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">
              صفحة <span class="text-slate-900">{{ page }}</span> من <span class="text-slate-900">{{ totalPages }}</span>
              <span class="mx-2 text-slate-200">|</span>
              إجمالي <span class="text-slate-900">{{ total }}</span> طلب معلق
            </div>
            <div class="flex items-center gap-3">
               <div class="flex items-center gap-2">
                 <span class="text-[10px] font-bold text-slate-400 uppercase">النتائج:</span>
                 <select v-model.number="limit" @change="page = 1; fetchPending({ silent: false })" class="h-8 border border-slate-200 rounded px-2 text-[10px] font-bold outline-none">
                   <option :value="10">10</option>
                   <option :value="20">20</option>
                   <option :value="50">50</option>
                 </select>
               </div>
               <div class="flex items-center gap-1">
                 <button @click="page = Math.max(1, page - 1); fetchPending({ silent: false })" :disabled="page <= 1" class="pagination-btn-v2"><i class="fas fa-chevron-right"></i></button>
                 <button @click="page = Math.min(totalPages, page + 1); fetchPending({ silent: false })" :disabled="page >= totalPages" class="pagination-btn-v2"><i class="fas fa-chevron-left"></i></button>
               </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Action Modal: Restructured for SaaS Standards -->
    <transition name="fade">
      <div v-if="actionModal.open" class="fixed inset-0 z-[120] flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-md">
        <div class="bg-white w-full max-w-lg rounded-xl shadow-2xl overflow-hidden border border-slate-200 animate-modalIn flex flex-col">
          <div class="px-8 py-5 border-b border-slate-100 bg-slate-50/50 flex items-center justify-between">
            <div class="flex items-center gap-3">
              <div :class="[actionModal.mode === 'approve' ? 'bg-emerald-600' : 'bg-rose-600']" class="w-8 h-8 rounded-lg flex items-center justify-center text-white"><i :class="actionModal.mode === 'approve' ? 'fas fa-check-double' : 'fas fa-ban'" class="text-xs"></i></div>
              <h3 class="text-sm font-bold text-slate-900 uppercase">{{ actionModal.mode === 'approve' ? 'مراجعة واعتماد الفاتورة' : 'رفض الطلب' }}</h3>
            </div>
            <button @click="closeAction" class="text-slate-400 hover:text-slate-900 transition-colors"><i class="fas fa-times text-lg"></i></button>
          </div>

          <div class="p-8 space-y-6">
            <!-- Source Context -->
            <div class="p-4 bg-slate-900 rounded-xl text-white flex justify-between items-center shadow-inner">
               <div><p class="text-[9px] font-bold text-slate-400 uppercase mb-1">صافي الفاتورة</p><p class="text-xl font-bold font-mono tracking-tighter text-blue-400">{{ formatPrice(actionModal.invoiceTotal) }}</p></div>
               <div class="text-left"><p class="text-[9px] font-bold text-slate-400 uppercase mb-1">رقم الطلب</p><p class="text-xs font-bold font-mono text-white">#{{ actionModal.id }}</p></div>
            </div>
            <div v-if="actionModal.mode === 'approve'" class="space-y-6 animate-fadeIn">
               <!-- Alert Box: Payment Type Info -->
               <div v-if="actionModal.isCredit" class="p-4 rounded-xl border border-amber-200 bg-amber-50 text-right space-y-2">
                  <p class="text-[10px] font-bold text-amber-600 uppercase tracking-widest">⚠️ فاتورة آجلة</p>
                  <p class="text-xs text-amber-700 leading-relaxed">هذه فاتورة بنظام الآجل (الاقتراض)، لا تتطلب تحصيل نقدي الآن. يمكن تحديث التاريخ المستحق لاحقاً.</p>
               </div>
               <div v-else class="p-4 rounded-xl border border-emerald-200 bg-emerald-50 text-right space-y-2">
                  <p class="text-[10px] font-bold text-emerald-600 uppercase tracking-widest">✓ فاتورة نقدية</p>
                  <p class="text-xs text-emerald-700 leading-relaxed">هذه فاتورة بدفع فوري (نقدي). يجب تحديد طريقة الدفع والمبلغ المستلم فعلياً.</p>
               </div>

               <div class="grid grid-cols-2 gap-4">
                 <div class="space-y-1.5">
                   <label class="metadata-label">
                     طريقة الدفع
                     <span v-if="actionModal.paidAmount > 0" class="text-red-400">*</span>
                   </label>
                   <select v-model="actionModal.paymentMethodId" class="filter-input-v2 h-10 font-bold"><option value="">-- اختر --</option><option v-for="pm in paymentMethods" :key="pm.id" :value="pm.id">{{ pm.name }}</option></select>
                 </div>
                 <div class="space-y-1.5"><label class="metadata-label">المبلغ المستلم</label><input v-model.number="actionModal.paidAmount" type="number" class="h-10 w-full bg-white border border-slate-200 rounded-md px-3 text-sm font-black text-left text-emerald-600 outline-none focus:border-emerald-500 transition-all" /></div>
               </div>

               <div v-if="actionModal.paidAmount > 0" class="grid grid-cols-3 gap-3">
                  <div class="p-3 rounded-lg bg-white border border-slate-200 text-center">
                    <p class="text-[9px] font-bold text-slate-400 uppercase tracking-widest mb-1">صافي الفاتورة</p>
                    <p class="text-sm font-bold font-mono text-slate-900">{{ formatPrice(actionModal.invoiceTotal) }}</p>
                  </div>
                  <div class="p-3 rounded-lg bg-white border border-slate-200 text-center">
                    <p class="text-[9px] font-bold text-slate-400 uppercase tracking-widest mb-1">المستلم</p>
                    <p class="text-sm font-bold font-mono text-emerald-600">{{ formatPrice(actionModal.paidAmount) }}</p>
                  </div>
                  <div :class="[changeAmount >= 0 ? 'bg-emerald-50 border-emerald-200' : 'bg-rose-50 border-rose-200']" class="p-3 rounded-lg border text-center">
                    <p class="text-[9px] font-bold uppercase tracking-widest mb-1" :class="[changeAmount >= 0 ? 'text-emerald-600' : 'text-rose-600']">{{ changeAmount >= 0 ? 'الباقي' : 'الناقص' }}</p>
                    <p class="text-sm font-bold font-mono" :class="[changeAmount >= 0 ? 'text-emerald-600' : 'text-rose-600']">{{ formatPrice(Math.abs(changeAmount)) }}</p>
                  </div>
               </div>
            </div>

            <div class="space-y-1.5"><label class="metadata-label">ملاحظات القرار</label><textarea v-model="actionModal.note" rows="2" class="filter-input-v2 h-auto py-2 italic" placeholder="أدخل تفاصيل القرار هنا..."></textarea></div>
          </div>

          <div class="px-8 py-5 bg-slate-50 border-t border-slate-200 flex justify-end gap-3 shrink-0">
             <button @click="closeAction" class="px-6 h-10 text-xs font-bold text-slate-500">إلغاء</button>
             <LoadingButton
               :text="actionModal.mode === 'approve' ? 'تأكيد الاعتماد' : 'تأكيد الرفض'"
               :isLoading="isSubmitting"
               loadingText="جاري..."
               :disabled="actionModal.mode === 'approve' && actionModal.paidAmount > 0 && !actionModal.paymentMethodId"
               variant="primary"
               loaderType="dots"
               @click="submitAction"
               class="px-10 h-10"
             />
          </div>
        </div>
      </div>
    </transition>

    <!-- Post-Approval Print: Restructured -->
    <transition name="fade">
      <div v-if="printModal.open" class="fixed inset-0 z-[130] flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-md">
        <div class="bg-white w-full max-w-sm rounded-xl shadow-2xl overflow-hidden border border-slate-200 animate-modalIn p-8 text-center space-y-6">
           <div class="w-16 h-16 rounded-full bg-emerald-50 text-emerald-600 flex items-center justify-center mx-auto shadow-inner"><i class="fas fa-check-circle text-3xl"></i></div>
           <div><h3 class="text-base font-bold text-slate-900 uppercase">تم الاعتماد والتحصيل</h3><p class="text-[10px] text-slate-400 mt-1 font-medium">فاتورة رقم #{{ printModal.saleId }} بانتظار الطباعة</p></div>
           
           <div v-if="printModal.change > 0" class="p-3 rounded-lg bg-emerald-900 text-white flex justify-between items-center shadow-lg">
              <span class="text-[9px] font-bold uppercase tracking-widest text-emerald-400">الباقي للعميل:</span>
              <span class="text-sm font-bold font-mono tracking-tighter">{{ formatPrice(printModal.change) }}</span>
           </div>

           <div class="flex gap-2">
              <button @click="printModal.open = false" class="flex-1 h-10 text-xs font-bold text-slate-500">تجاهل</button>
              <button @click="printApprovedSale" class="flex-2 px-8 h-10 bg-blue-600 text-white rounded-md text-xs font-bold shadow-lg shadow-blue-900/20 active:scale-95 transition-all flex items-center justify-center gap-2">
                 <i class="fas fa-print"></i> طباعة الفاتورة
              </button>
           </div>
        </div>
      </div>
    </transition>

    <!-- Details View: Using established report style -->
    <transition name="fade">
      <div v-if="showDetails && details" class="fixed inset-0 z-[120] flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-md" @click.self="showDetails = false">
        <div class="bg-white w-full max-w-4xl rounded-2xl shadow-2xl overflow-hidden border border-slate-200 flex flex-col max-h-[90vh] animate-modalIn">
          <div class="px-8 py-5 border-b border-slate-100 bg-slate-50/50 flex items-center justify-between">
            <h3 class="text-sm font-bold text-slate-900 uppercase">مراجعة أصناف الطلب</h3>
            <button @click="showDetails = false" class="text-slate-400 hover:text-slate-900 transition-colors"><i class="fas fa-times text-xl"></i></button>
          </div>

          <div class="p-8 overflow-y-auto custom-scroll space-y-8">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-6">
              <div v-for="info in [
                { l: 'العميل', v: details?.sale?.customer_name ?? details?.customer_name ?? 'عميل نقدي' },
                { l: 'التاريخ', v: formatDateTime(details?.sale?.created_at ?? details?.created_at) },
                { l: 'طريقة الدفع', v: details?.sale?.payment_method_name ?? details?.payment_method_name ?? '-' },
                { l: 'إجمالي الفاتورة', v: formatPrice(details?.sale?.net_total_amount ?? details?.net_total_amount), c: 'text-blue-600 font-bold' }
              ]" :key="info.l" class="space-y-1">
                <p class="text-[9px] font-bold text-slate-400 uppercase tracking-widest">{{ info.l }}</p>
                <p :class="[info.c || 'text-slate-800', 'text-xs font-bold']">{{ info.v }}</p>
              </div>
            </div>

            <div class="border border-slate-100 rounded-xl overflow-hidden shadow-sm">
              <table class="w-full text-right text-xs">
                <thead><tr class="bg-slate-50 border-b border-slate-100 font-bold text-slate-400 uppercase tracking-tighter"><th class="px-4 py-3">الصنف</th><th class="px-4 py-3 text-center">الكمية</th><th class="px-4 py-3 text-center">سعر الوحدة</th><th class="px-4 py-3 text-left">الإجمالي</th></tr></thead>
                <tbody class="divide-y divide-slate-100 font-medium">
                  <tr v-for="it in (details?.items || details?.sale?.items || [])" :key="it.id">
                    <td class="px-4 py-3 text-slate-800 font-bold">{{ it.name || it.product_name }}</td>
                    <td class="px-4 py-3 text-center font-bold text-slate-500">{{ it.quantity ?? it.qty }}</td>
                    <td class="px-4 py-3 text-center font-mono text-slate-700">{{ formatPrice(itemPrice(it)) }}</td>
                    <td class="px-4 py-3 text-left font-bold font-mono text-slate-900">{{ formatPrice(itemTotal(it)) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>

            <div class="flex justify-end">
              <div class="w-full md:w-72 bg-slate-900 p-6 rounded-xl text-white flex flex-col justify-center space-y-4">
                <div class="flex justify-between text-[11px] font-medium text-white/50 uppercase"><span>الإجمالي الصافي</span><span class="text-white font-mono">{{ formatPrice(details?.sale?.net_total_amount ?? details?.net_total_amount) }}</span></div>
              </div>
            </div>
          </div>

          <div class="px-8 py-4 bg-slate-50 border-t border-slate-200 flex justify-between items-center shrink-0">
             <span class="text-[10px] font-bold text-amber-600 bg-amber-50 border border-amber-100 px-2 py-0.5 rounded uppercase tracking-tighter">بانتظار القرار</span>
             <button @click="showDetails = false" class="px-8 h-9 bg-slate-900 text-white rounded-md text-xs font-bold transition-all hover:bg-black">إغلاق المعاينة</button>
          </div>
        </div>
      </div>
    </transition>

  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { useApprovalsStore } from '@/stores/approvals/approvalsStore'
import { useToast } from '@/composables/useToast'
import { useLoader } from '@/composables/useLoader'
import { useCompanyCurrency } from '@/composables/useCompanyCurrency'
import { useBreadcrumb } from '@/composables/useBreadcrumb'
import { useBranchStore } from '@/stores/branch'
import { useSalesStore } from '@/stores/sales/salesStore'
import { usePaymentStore } from '@/stores/payment/paymentStore'
import { useProductStore } from '@/stores/product/productStore'
import LoadingButton from '@/components/ui/LoadingButton.vue';
import BaseSkeleton from '@/components/ui/BaseSkeleton.vue';
import PageHeader from '@/components/PageHeader.vue';

// --- Logic Initialization (STRICTLY PRESERVED) ---
const { showToast } = useToast()
const { showLoader, hideLoader } = useLoader()
const { formatCurrencyLocale, fetchSettings } = useCompanyCurrency()
const { breadcrumb } = useBreadcrumb()
const branchStore = useBranchStore()
const salesStore = useSalesStore()
const paymentStore = usePaymentStore()
const productStore = useProductStore()
const paymentMethods = computed(() => paymentStore.paymentMethods || [])
const branches = computed(() => branchStore.branches);
const selectedBranch = computed(() => branchStore.selectedBranchId);

// ✅ يتتبع الاختيار اليدوي للفرع (النمط A — متطابق مع SalesHistory/PurchaseHistory/ReturnsHistory)
const userChoseBranch = ref(
  localStorage.getItem('selectedBranchId') !== null
  && localStorage.getItem('selectedBranchId') !== 'all'
);
const hasExplicitBranchSelection = computed(() => userChoseBranch.value && branchStore.selectedBranchId !== null);

const onBranchChange = (newBranchId) => {
  branchStore.setSelectedBranch(newBranchId);
  userChoseBranch.value = (newBranchId !== null && newBranchId !== '' && newBranchId !== 'all');
  page.value = 1;
  manualRefresh(true);  // ✅ force=true
};

const rows = ref([])
const page = ref(1)
const limit = ref(20)
const total = ref(0)
const totalPages = computed(() => Math.max(1, Math.ceil(total.value / limit.value)))
const isLoading = ref(false)
let listAbortCtrl = null

// ─── Real-time polling ─────────────────────────────────────────────
const POLL_INTERVAL = 15000
let pollTimer = null
let pollingBusy = false
const isPolling = ref(false)
const lastUpdatedAt = ref(null)
const newInvoicesAlert = ref(false)
let newInvoicesAlertTimer = null
let knownIds = new Set()
let lastSoundAt = 0

const lastUpdatedLabel = computed(() => {
  if (!lastUpdatedAt.value) return ''
  const now = new Date()
  const diff = Math.floor((now - lastUpdatedAt.value) / 1000)
  if (diff < 5) return 'الآن'
  if (diff < 60) return `منذ ${diff} ث`
  return lastUpdatedAt.value.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' })
})

const showDetails = ref(false)
const details = ref(null)
const isLoadingDetails = ref(false)

const actionModal = ref({ open: false, mode: 'approve', id: null, note: '', paymentMethodId: '', paidAmount: 0, invoiceTotal: 0 })
const isSubmitting = ref(false)
const printModal = ref({ open: false, saleId: null, invoiceTotal: 0, change: 0 })

const changeAmount = computed(() => (actionModal.value.paidAmount || 0) - (actionModal.value.invoiceTotal || 0))

const formatPrice = (amount) => formatCurrencyLocale(amount, 2)
const formatDateTime = (val) => {
  if (!val) return ''
  try {
    return new Date(val).toLocaleString('en-US', { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' })
  } catch { return String(val) }
}

const itemPrice = (it) => { const v = it?.price ?? it?.sale_price ?? it?.unit_price; return Number(v ?? 0); }
const itemQty = (it) => Number((it?.quantity ?? it?.qty ?? 1))
const itemTotal = (it) => {
  const v = it?.total ?? it?.net_total
  if (v != null) return Number(v)
  return itemPrice(it) * itemQty(it)
}

const kindLabel = (k) => {
  const v = String(k || '').toLowerCase()
  switch (v) {
    case 'cash': return 'نقدي';
    case 'bank': return 'بنكي';
    case 'card': return 'بطاقة';
    case 'credit': return 'آجل';
    default: return 'أخرى';
  }
}
const kindClass = (k) => {
  const v = String(k || '').toLowerCase()
  if (v === 'cash') return 'bg-emerald-100 text-emerald-700';
  if (v === 'credit') return 'bg-amber-100 text-amber-700';
  if (v === 'bank' || v === 'card') return 'bg-sky-100 text-sky-700';
  return 'bg-slate-100 text-slate-500';
}

const fetchPending = async ({ silent = false } = {}) => {
  if (listAbortCtrl) listAbortCtrl.abort()
  listAbortCtrl = new AbortController()
  if (!silent) { isLoading.value = true; showLoader(); }
  try {
    const approvalsStore = useApprovalsStore();
    approvalsStore.clearCache()
    const params = { page: page.value, limit: limit.value };
    if (selectedBranch.value) params.branch_id = selectedBranch.value;
    const res = await approvalsStore.listPending(params)
    const newRows = res.data || []
    const newTotal = Number(res.pagination?.total || newRows.length)

    // Detect new invoices by ID comparison (more accurate than count)
    const newIds = newRows.map(r => r.id)
    const hasNew = silent && knownIds.size > 0 && newIds.some(id => !knownIds.has(id))
    knownIds = new Set(newIds)

    if (hasNew) {
      newInvoicesAlert.value = true
      playAlertSound()
      clearTimeout(newInvoicesAlertTimer)
      newInvoicesAlertTimer = setTimeout(() => { newInvoicesAlert.value = false }, 5000)
    }
    rows.value = newRows
    total.value = newTotal
    lastUpdatedAt.value = new Date()
  } catch (e) {
    if (e.name !== 'CanceledError' && !silent)
      showToast(e?.response?.data?.message || 'فشل تحميل القائمة', 'error')
  } finally {
    if (!silent) { isLoading.value = false; hideLoader(); }
  }
}

const manualRefresh = (forceRefresh = false) => {
  newInvoicesAlert.value = false
  fetchPending({ silent: false, force: forceRefresh })
}

const playAlertSound = () => {
  const now = Date.now()
  if (now - lastSoundAt < 10000) return
  lastSoundAt = now
  try {
    const ctx = new (window.AudioContext || window.webkitAudioContext)()
    const osc = ctx.createOscillator()
    const gain = ctx.createGain()
    osc.connect(gain); gain.connect(ctx.destination)
    osc.frequency.value = 880
    osc.type = 'sine'
    gain.gain.setValueAtTime(0.3, ctx.currentTime)
    gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.4)
    osc.start(ctx.currentTime); osc.stop(ctx.currentTime + 0.4)
  } catch {}
}

const pollOnce = async () => {
  if (pollingBusy) return
  pollingBusy = true
  try {
    await fetchPending({ silent: true })
  } finally {
    pollingBusy = false
  }
}

const startPolling = () => {
  if (pollTimer) return
  isPolling.value = true
  pollTimer = setInterval(() => {
    if (document.hidden || actionModal.value.open || printModal.value.open) return
    pollOnce()
  }, POLL_INTERVAL)
}

const stopPolling = () => {
  if (pollTimer) { clearInterval(pollTimer); pollTimer = null }
  isPolling.value = false
}

const handleVisibilityChange = () => {
  if (document.hidden) {
    stopPolling()
  } else {
    fetchPending({ silent: true })
    startPolling()
  }
}

const openAction = (mode, id) => {
  const row = rows.value.find(r => r.id === id)
  const invoiceTotal = Number(row?.net_total_amount ?? row?.total_amount ?? 0)
  const kind = String(row?.payment_method_kind ?? row?.sale?.payment_method_kind ?? row?.payment_method?.kind ?? '').toLowerCase()
  const isCredit = kind === 'credit'
  const defaultMethodId = isCredit ? '' : (row?.payment_method_id || (paymentMethods.value[0]?.id ?? ''))
  const defaultPaid    = isCredit ? 0 : invoiceTotal
  actionModal.value = { open: true, mode, id, note: '', paymentMethodId: defaultMethodId, paidAmount: defaultPaid, invoiceTotal, isCredit }
}
const closeAction = () => { actionModal.value.open = false; }

const submitAction = async () => {
  if (!actionModal.value.open || !actionModal.value.id || isSubmitting.value) return
  isSubmitting.value = true; showLoader();
  try {
    const approvalsStore = useApprovalsStore();
    if (actionModal.value.mode === 'approve') {
      const override = {
        payment_method_id: actionModal.value.paymentMethodId || undefined,
        paid_amount: actionModal.value.paidAmount ?? undefined
      }
      const res = await approvalsStore.approve(actionModal.value.id, actionModal.value.note, override)
      if (res.status === 'error') { showToast(res.message || 'فشل التنفيذ', 'error'); return }
      const saleId = actionModal.value.id
      const invoiceTotal = actionModal.value.invoiceTotal
      const change = (actionModal.value.paidAmount || 0) - invoiceTotal
      closeAction()
      await fetchPending()
      // ✅ تحديث cache المنتجات في POS لأن الاعتماد يخصم من المخزون
      productStore.invalidateCache()
      printModal.value = { open: true, saleId, invoiceTotal, change: Math.max(0, change) }
    } else {
      const res = await approvalsStore.reject(actionModal.value.id, actionModal.value.note)
      if (res.status === 'error') { showToast(res.message || 'فشل التنفيذ', 'error'); return }
      showToast('تم رفض الفاتورة', 'success')
      closeAction(); await fetchPending();
    }
  } catch (e) { showToast(e?.response?.data?.message || 'فشل التنفيذ', 'error') } 
  finally { hideLoader(); isSubmitting.value = false; }
}

const printApprovedSale = async () => {
  printModal.value.open = false
  try {
    const res = await salesStore.fetchSaleDetails(printModal.value.saleId)
    const saleData = res?.data ?? res
    if (saleData) { details.value = saleData; showDetails.value = true; }
  } catch { showToast('فشل تحميل تفاصيل الفاتورة للطباعة', 'error') }
}

const viewDetails = async (id) => {
  isLoadingDetails.value = true; showLoader();
  try {
    const res = await salesStore.fetchSaleDetails(id, { force: true });
    const saleData = res?.data ?? res
    if (saleData) { details.value = saleData; showDetails.value = true; }
    else showToast('لم يتم استرجاع بيانات الفاتورة', 'error')
  } catch { showToast('فشل تحميل التفاصيل', 'error') } 
  finally { isLoadingDetails.value = false; hideLoader(); }
}

// isMounting flag: يمنع watch(selectedBranch) من إطلاق fetch أثناء onMounted
let isMounting = true;

onMounted(async () => {
  // ✅ FIX: تهيئة branch context قبل أول API call (النمط A)
  const hadPriorBranchChoice = localStorage.getItem('selectedBranchId') !== null
                                && localStorage.getItem('selectedBranchId') !== 'all';
  branchStore.loadFromStorage();
  if (!branchStore.branches || branchStore.branches.length === 0) {
    await branchStore.fetchBranches().catch(() => {})
  }
  // بعد fetchBranches: أعد تعيين الـ flag بما كان موجوداً قبل الكتابة
  userChoseBranch.value = hadPriorBranchChoice;

  fetchSettings()
  fetchPending()
  paymentStore.fetchPaymentMethods()
  startPolling()
  document.addEventListener('visibilitychange', handleVisibilityChange)
  isMounting = false;
})
onUnmounted(() => {
  stopPolling()
  clearTimeout(newInvoicesAlertTimer)
  document.removeEventListener('visibilitychange', handleVisibilityChange)
  if (listAbortCtrl) listAbortCtrl.abort()
})
watch(page, () => fetchPending())
watch(limit, () => { page.value = 1; fetchPending() })
// selectedBranch مُزال من watch — تغيير الفرع يُعالج عبر onBranchChange() مباشرة
// إبقاؤه يُسبب race condition: fetchBranches() تُغيّر selectedBranchId أثناء onMounted فيُطلق watch مبكراً
</script>

<style scoped>
@keyframes loading { 0% { transform: translateX(100%); } 100% { transform: translateX(-100%); } }

.filter-input-v2 {
  @apply h-9 w-full bg-white border border-slate-200 rounded-md px-3 text-[11px] font-bold text-slate-700 focus:ring-2 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all;
}

.metadata-label { @apply block text-[10px] font-bold text-slate-400 uppercase tracking-widest px-1; }

.pagination-btn-v2 {
  @apply w-8 h-8 flex items-center justify-center rounded border border-slate-200 bg-white text-slate-500 hover:text-blue-600 hover:border-blue-200 disabled:opacity-40 transition-all;
}

.custom-scroll::-webkit-scrollbar { width: 5px; }
.custom-scroll::-webkit-scrollbar-thumb { @apply bg-slate-200 rounded-full; }

.animate-fadeIn { animation: fadeIn 0.4s ease-out; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

@keyframes modalIn { from { opacity: 0; transform: scale(0.98) translateY(10px); } to { opacity: 1; transform: scale(1) translateY(0); } }
.animate-modalIn { animation: modalIn 0.2s cubic-bezier(0.16, 1, 0.3, 1); }

.fade-scale-enter-active, .fade-scale-leave-active { transition: all 0.3s ease; }
.fade-scale-enter-from, .fade-scale-leave-to { opacity: 0; transform: scale(0.8); }
</style>