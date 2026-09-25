<template>
  <div class="space-y-8">
    <!-- Step 1: Invoice Search -->
    <div class="relative space-y-2">
      <label class="text-[10px] font-bold text-slate-400 uppercase tracking-[0.2em] px-1">1. البحث عن المستند الأصلي</label>
      <div class="relative group">
        <input
          ref="invoiceSearchInputRef"
          type="text"
          v-model="invoiceNumber"
          @input="debouncedSearchInvoice"
          @focus="showInvoiceDropdown = true"
          @blur="handleInvoiceBlur"
          @keydown.down="selectNextResult"
          @keydown.up="selectPrevResult"
          @keydown.enter.prevent="selectHighlightedResult"
          @keydown.escape="showInvoiceDropdown = false"
          class="h-11 w-full bg-white border border-slate-200 rounded-lg text-sm font-bold focus:ring-4 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all"
          style="padding-right: 2rem; padding-left: 2rem;"
          placeholder="ابحث بـ: رقم الفاتورة أو اسم الطرف..."
          autocomplete="off"
        >
        <i class="fas fa-search absolute right-4 top-1/2 -translate-y-1/2 text-slate-300 text-xs pointer-events-none"></i>
        <div v-if="isSearchingInvoice" class="absolute left-4 top-1/2 -translate-y-1/2"><BaseSpinner :size="16" color="#3b82f6" /></div>

        <!-- Search Dropdown Teleport Style -->
        <Teleport to="body">
          <transition name="dropdown">
            <div v-if="showInvoiceDropdown && invoiceSearchResults.length && !selectedInvoice" ref="invoiceDropdownRef" class="fixed bg-white border border-slate-200 rounded-lg shadow-2xl overflow-hidden z-[99999]" :style="invoiceDropdownPosition">
              <div
                v-for="(inv, idx) in invoiceSearchResults.slice(0, 20)"
                :key="inv.id"
                @click="selectInvoice(inv)"
                :class="['px-4 py-3 cursor-pointer border-b border-slate-50 flex items-center justify-between group transition-colors', idx === highlightedIdx ? 'bg-blue-100 border-l-4 border-l-blue-500' : 'hover:bg-blue-50']"
              >
                <div class="flex-grow min-w-0">
                  <p class="text-xs font-bold text-slate-900">#{{ inv.invoice_number || inv.id }}</p>
                  <p class="text-[10px] text-slate-400 truncate">{{ inv.customer_name || inv.supplier_name }}</p>
                </div>
                <div class="text-left"><p class="text-xs font-bold text-blue-600">{{ formatPrice(inv.total_amount) }}</p></div>
              </div>
			  
              <div v-if="invoiceSearchResults.length > 20" class="px-4 py-2 text-center bg-slate-50 border-t border-slate-100">
                <p class="text-[10px] text-slate-500">و {{ invoiceSearchResults.length - 20 }} نتيجة أخرى (استخدم ↑↓ للتنقل)</p>
              </div>
            </div>
          </transition>
        </Teleport>

        <div v-if="invoiceNumber && !isSearchingInvoice && !invoiceSearchResults.length && !selectedInvoice" class="text-xs text-red-500 mt-2">
          <i class="fas fa-exclamation-circle ml-1"></i>
          لا توجد فواتير مطابقة للبحث
        </div>
      </div>
    </div>

    <div v-if="selectedInvoice" class="space-y-6 animate-fadeIn">
      <div class="flex items-center justify-between px-1">
        <label class="text-[10px] font-bold text-slate-400 uppercase tracking-[0.2em]">2. تحديد الأصناف المرتجعة</label>
        <label class="flex items-center gap-2 cursor-pointer bg-slate-100 px-3 py-1 rounded-full text-[10px] font-bold text-slate-600 hover:bg-slate-200 transition-colors">
          <input type="checkbox" v-model="isFullReturn" @change="setFullReturn" class="rounded border-slate-300 text-blue-600 focus:ring-0" />
          إرجاع الفاتورة كاملة
        </label>
      </div>

      <!-- Selected Invoice Card Header -->
      <div class="grid grid-cols-1 md:grid-cols-2 gap-6 bg-slate-900 rounded-xl p-6 text-white shadow-lg overflow-hidden relative">
        <div class="absolute right-0 top-0 w-32 h-32 bg-white/5 rounded-full translate-x-10 -translate-y-10"></div>
        <div class="space-y-3 relative z-10">
          <div class="flex items-center gap-3">
             <span class="text-[10px] font-bold text-white/40 uppercase tracking-widest">مستند المصدر</span>
             <span class="px-2 py-0.5 bg-white/10 rounded text-[9px] font-bold border border-white/10 font-mono">#{{ selectedInvoice.invoice_number || selectedInvoice.id }}</span>
          </div>
          <div class="flex items-baseline gap-4">
            <span class="text-[10px] font-medium text-white/50">تاريخ الإصدار: {{ selectedInvoice.invoice_date }}</span>
            <span class="text-[10px] font-medium text-white/50">الذمة المتبقية: {{ formatPrice(outstanding) }}</span>
          </div>
        </div>
        <div class="flex flex-col items-end justify-center relative z-10">
           <p class="text-[9px] font-bold text-blue-400 uppercase tracking-widest mb-1">المبلغ القابل للاسترداد</p>
           <p class="text-3xl font-bold font-mono tracking-tighter">{{ formatPrice(totalReturnAmount) }}</p>
        </div>
      </div>

      <div class="bg-slate-50 border border-slate-200 rounded-xl p-6">
        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-3">التفصيل المالي للفاتورة الأصلية</p>
        <table class="w-full text-xs">
          <tbody>
            <tr>
              <td class="py-1 text-slate-500">إجمالي الفاتورة</td>
              <td class="py-1 text-left font-mono font-bold text-slate-900">{{ formatPrice(selectedInvoice.total_amount || 0) }}</td>
            </tr>
            <tr v-if="selectedInvoice.has_discount || Number(selectedInvoice.discount_value) > 0">
              <td class="py-1 text-rose-500">الخصم</td>
              <td class="py-1 text-left font-mono text-rose-500">- {{ formatPrice(selectedInvoice.discount_value || 0) }}</td>
            </tr>
            <tr v-if="Number(selectedInvoice.tax_amount) > 0">
              <td class="py-1 text-slate-500">الضريبة</td>
              <td class="py-1 text-left font-mono text-slate-900">+ {{ formatPrice(selectedInvoice.tax_amount || 0) }}</td>
            </tr>
            <tr class="border-t border-slate-200 font-bold">
              <td class="pt-2 text-slate-700">صافي المدفوع</td>
              <td class="pt-2 text-left font-mono text-slate-900">{{ formatPrice(selectedInvoice.net_total_amount || 0) }}</td>
            </tr>
            <tr v-if="outstanding > 0">
              <td class="py-1 text-amber-600">المتبقي غير المسدد</td>
              <td class="py-1 text-left font-mono text-amber-600 font-bold">{{ formatPrice(outstanding) }}</td>
            </tr>
            <tr class="border-t border-blue-200 font-bold">
              <td class="pt-2 text-blue-700">قيمة المرتجع المستحق</td>
              <td class="pt-2 text-left font-mono text-blue-700">{{ formatPrice(totalReturnAmount) }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="hasOutstanding" class="p-4 rounded-lg bg-amber-50 text-amber-800 border border-amber-200 text-xs font-bold flex items-start gap-3">
        <i class="fas fa-exclamation-triangle text-amber-500 mt-0.5"></i>
        <span>
          <template v-if="isSale">لا يمكن رد نقدي لفاتورة مبيعات آجلة أو غير مسددة بالكامل. سيتم خصم قيمة المرتجع من ذمة العميل فقط.</template>
          <template v-else>لا يمكن استلام نقدي لمرتجع على فاتورة مشتريات آجلة أو غير مسددة بالكامل. سيتم خصم قيمة المرتجع من ذمة المورد فقط.</template>
        </span>
      </div>

      <!-- Items Table: High Density -->
      <div class="border border-slate-200 rounded-xl overflow-hidden shadow-sm">
        <table class="w-full text-right text-xs">
          <thead>
            <tr class="bg-slate-50 border-b border-slate-200 font-bold text-slate-400 uppercase tracking-tighter">
              <th class="px-4 py-3">الصنف</th>
              <th class="px-4 py-3 text-center">أصلي</th>
              <th class="px-4 py-3 text-center">مرتجع سابقاً</th>
              <th class="px-4 py-3 text-center">المتبقي</th>
              <th class="px-4 py-3 text-center w-24">كمية المرتجع</th>
              <th class="px-4 py-3 text-left">الإجمالي</th>
              <th class="px-4 py-3 text-center w-12"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="item in selectedInvoice.items" :key="item.item_id" class="hover:bg-slate-50 transition-colors">
              <td class="px-4 py-3">
                <p class="font-bold text-slate-800">{{ item.product_name }}</p>
                <p v-if="hasDuplicateProduct(item.product_id)" class="text-[8px] text-amber-500 font-bold uppercase mt-0.5">بند مكرر #{{ item.item_id }}</p>
              </td>
              <td class="px-4 py-3 text-center text-slate-400 font-mono">{{ item[quantityKey] }}</td>
              <td class="px-4 py-3 text-center text-slate-400 font-mono">{{ item.prevReturned ?? 0 }}</td>
              <td class="px-4 py-3 text-center text-slate-900 font-bold font-mono">{{ item.remainingQty ?? item[quantityKey] }}</td>
              <td class="px-4 py-3 text-center">
                <input type="number" v-model.number="item.returnQuantity" :disabled="isFullReturn" min="0" :max="item.remainingQty ?? item[quantityKey]" class="h-7 w-16 bg-white border border-slate-200 rounded text-center text-[11px] font-bold focus:ring-4 focus:ring-blue-500/10 outline-none" @change="() => { const max = item.remainingQty ?? item[quantityKey]; if(item.returnQuantity>max) item.returnQuantity=max; }" />
              </td>
              <td class="px-4 py-3 text-left font-bold text-slate-900 font-mono">{{ formatPrice(item[displayPriceKey] * (item.returnQuantity || 0)) }}</td>
              <td class="px-4 py-3 text-center">
                <button v-if="!isFullReturn" @click="addToReturnList(item)" class="text-blue-600 hover:text-blue-700 transition-colors"><i class="fas fa-plus-circle text-base"></i></button>
                <span v-else class="text-slate-300">-</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Step 3: Refund Config -->
    <div v-if="selectedInvoice" class="grid grid-cols-1 md:grid-cols-2 gap-8 animate-fadeIn">
      <div class="space-y-4">
        <label class="text-[10px] font-bold text-slate-400 uppercase tracking-[0.2em] px-1">3. إعدادات التسوية والسبب</label>
        <div class="space-y-4 p-6 bg-slate-50 border border-slate-200 rounded-xl">
          <div class="space-y-1.5"><label class="text-[10px] font-bold text-slate-500 uppercase tracking-widest">سياسة الاسترجاع</label><select v-model="refundMode" :disabled="hasOutstanding" class="filter-input-v2 h-10"><option value="auto">تلقائي (تسوية ثم رد)</option><option value="cash">رد نقدي كامل</option><option value="credit_note">قيد دائن فقط</option></select></div>
          <div class="space-y-1.5"><label class="text-[10px] font-bold text-slate-500 uppercase tracking-widest">سبب الإرجاع</label><textarea v-model="returnReason" rows="2" class="filter-input-v2 h-auto py-2" placeholder="اكتب التفاصيل هنا..."></textarea></div>
        </div>
      </div>

      <div class="space-y-4">
        <label class="text-[10px] font-bold text-slate-400 uppercase tracking-[0.2em] px-1">4. الدفع النهائي</label>
        <div class="space-y-4 p-6 bg-white border border-slate-200 rounded-xl shadow-inner">
           <div class="grid grid-cols-2 gap-4">
             <div class="space-y-1.5"><label class="text-[10px] font-bold text-slate-500 uppercase tracking-widest">المبلغ المدفوع</label><input type="number" v-model.number="paidAmount" :disabled="hasOutstanding" :max="totalReturnAmount" min="0" class="filter-input-v2 h-10 font-mono text-emerald-600 font-black" /></div>
             <div class="space-y-1.5"><label class="text-[10px] font-bold text-slate-500 uppercase tracking-widest">طريقة الدفع</label><select v-model="paymentMethodId" :disabled="hasOutstanding" class="filter-input-v2 h-10 font-bold"><option v-for="m in paymentMethods" :key="m.id" :value="m.id">{{ m.name }}</option></select></div>
           </div>
           <LoadingButton
             text="تأكيد وتسجيل المرتجع"
             :isLoading="isSubmittingReturn"
             loadingText="جاري التسجيل..."
             icon="fas fa-check-circle"
             variant="primary"
             loaderType="dots"
             @click="submitReturn"
             :disabled="!returnItems.length"
             class="w-full h-12"
           />
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, watch, computed, onMounted, onBeforeUnmount } from 'vue';
import apiClient from '@/config/axios';
import paymentService from '@/services/payment';
import { useToast } from '@/composables/useToast';
import LoadingButton from '@/components/ui/LoadingButton.vue'
import { getLocalDateISO } from '@/utils/date';
import { useReturnStore } from '@/stores/return/returnStore';

const props = defineProps({
  type: { type: String, required: true }, // 'sales' or 'purchases'
});

const emit = defineEmits(['returnSuccess']);
const { showToast } = useToast();
const returnStore = useReturnStore();

// حالة
const invoiceNumber = ref('');
const selectedInvoice = ref(null);
const returnItems = ref([]);
const returnReason = ref('');
const isSubmittingReturn = ref(false);
const isFullReturn = ref(false);
const paidAmount = ref(0);
const paymentMethodId = ref(null);
const paymentMethods = ref([]);
const refundMode = ref('auto'); // auto | cash | credit_note
const discountType = ref('fixed'); // أو 'percent'
const discountValue = ref(0);
const partyId = ref(null);
const invoiceId = ref(null);
const invoiceSearchResults = ref([]);
const isSearchingInvoice = ref(false);
const showInvoiceDropdown = ref(false);
const highlightedIdx = ref(-1);
const invoiceSearchInputRef = ref(null);
const invoiceDropdownRef = ref(null);
const dropdownPositionUpdate = ref(0); // Trigger for position recalculation

// Event handler references for scroll/resize
let scrollThrottleTimer = null;
let resizeDebounceTimer = null;
let handleScroll = null;
let handleResize = null;

let debounceTimer = null;
let invoiceSearchAbortController = null;
const invoiceSearchCache = new Map();

// جلب طرق الدفع عند التحميل
onMounted(async () => {
  try {
    const res = await paymentService.getPaymentMethods();
    if (res.data?.status === 'success') {
      paymentMethods.value = res.data.data;
      // اختر أول طريقة دفع بشكل افتراضي إذا متاح
      if (!paymentMethodId.value && paymentMethods.value.length) {
        paymentMethodId.value = paymentMethods.value[0].id;
      }
    }
  } catch (e) {
    paymentMethods.value = [];
  }

  // إضافة مستمعي أحداث التمرير وتغيير الحجم لتحديث موضع القائمة المنسدلة
  handleScroll = () => {
    if (scrollThrottleTimer) return;
    scrollThrottleTimer = setTimeout(() => {
      dropdownPositionUpdate.value++;
      scrollThrottleTimer = null;
    }, 16); // ~60fps
  };

  handleResize = () => {
    clearTimeout(resizeDebounceTimer);
    resizeDebounceTimer = setTimeout(() => {
      dropdownPositionUpdate.value++;
    }, 100);
  };

  window.addEventListener('scroll', handleScroll, true);
  window.addEventListener('resize', handleResize);
});

// تنظيف المستمعين عند فصل المكون
onBeforeUnmount(() => {
  if (handleScroll) window.removeEventListener('scroll', handleScroll, true);
  if (handleResize) window.removeEventListener('resize', handleResize);
  clearTimeout(scrollThrottleTimer);
  clearTimeout(resizeDebounceTimer);
});

// تطبيع النوع ليطابق ما يتوقعه الـ backend
const normalizedType = computed(() => props.type === 'sales' ? 'sale' : (props.type === 'purchases' ? 'purchase' : props.type));
const isSale = computed(() => normalizedType.value === 'sale');
const outstanding = computed(() => {
  if (!selectedInvoice.value) return 0;
  const net = Number(selectedInvoice.value.net_total_amount || 0);
  const paid = Number(selectedInvoice.value.paid_amount || 0);
  return Math.max(0, net - paid);
});
const hasOutstanding = computed(() => outstanding.value > 0);

// عند اختيار طريقة دفع آجلة → أجبر refundMode على credit_note (paidAmount سيتصفر تلقائياً)
watch(paymentMethodId, (newId) => {
  const m = paymentMethods.value.find(pm => Number(pm.id) === Number(newId));
  if (m?.kind === 'credit') refundMode.value = 'credit_note';
});

// اجعل paidAmount يتبع إجمالي المرتجع تلقائياً عند تغيير العناصر
watch([returnItems, isFullReturn, refundMode, selectedInvoice], () => {
  // اقتراح مبلغ مدفوع تلقائي حسب سياسة الاسترجاع (للمبيعات فقط)
  if (!selectedInvoice.value) { paidAmount.value = 0; return; }
  // منع أي حركة نقدية إن وُجد متبقي (مبيعات أو مشتريات)
  if (hasOutstanding.value) {
    refundMode.value = 'credit_note';
    paidAmount.value = 0;
    return;
  }
  const total = Number(totalReturnAmount.value || 0);
  const out = Number(outstanding.value || 0);
  if (refundMode.value === 'auto') {
    paidAmount.value = Math.max(0, total - out);
  } else if (refundMode.value === 'cash') {
    paidAmount.value = total;
  } else if (refundMode.value === 'credit_note') {
    paidAmount.value = 0;
  }
});

// مفاتيح حسب النوع
const quantityKey = computed(() => 'quantity');
const priceKey = computed(() => props.type === 'sales' ? 'net_price' : 'unit_price');
const displayPriceKey = priceKey;
const placeholderText = computed(() => props.type === 'sales' ? 'أدخل رقم فاتورة المبيعات أو ID...' : 'أدخل رقم فاتورة المشتريات أو ID...');

function formatPrice(amount) {
  if (!amount) return '0';
  return Number(amount).toLocaleString('en-US', { minimumFractionDigits: 2 });
}

// دالة للكشف عن المنتجات المكررة
function hasDuplicateProduct(productId) {
  if (!selectedInvoice.value?.items) return false;
  return selectedInvoice.value.items.filter(
    item => item.product_id === productId
  ).length > 1;
}

function formatDateShort(date) {
  if (!date) return '';
  const d = new Date(date);
  return d.toLocaleDateString('en-US', { day: '2-digit', month: '2-digit', year: 'numeric' });
}
function paymentStatusText(status) {
  if (status === 'cash') return 'نقدي';
  if (status === 'credit') return 'آجل';
  if (status === 'partial') return 'مدفوع جزئيًا';
  return status || 'غير معروف';
}
function statusBadgeClass(status) {
  if (status === 'cash') return 'bg-green-600';
  if (status === 'credit') return 'bg-gray-600';
  if (status === 'partial') return 'bg-yellow-600';
  return 'bg-slate-500';
}

const totalReturnAmount = computed(() =>
  returnItems.value.reduce((total, item) => total + (item[displayPriceKey.value] * item.returnQuantity), 0)
);

function debouncedSearchInvoice() {
  clearTimeout(debounceTimer);
  highlightedIdx.value = -1;

  const query = invoiceNumber.value.trim();
  if (!query) {
    invoiceSearchResults.value = [];
    return;
  }

  // تحقق من Cache
  const cacheKey = `${normalizedType.value}:${query}`;
  if (invoiceSearchCache.has(cacheKey)) {
    invoiceSearchResults.value = invoiceSearchCache.get(cacheKey);
    return;
  }

  isSearchingInvoice.value = true;
  debounceTimer = setTimeout(async () => {
    try {
      if (invoiceSearchAbortController) invoiceSearchAbortController.abort();
      invoiceSearchAbortController = new AbortController();

      const res = await apiClient.get('/returns/invoices', {
        params: { type: normalizedType.value, q: query },
        signal: invoiceSearchAbortController.signal
      });

      const data = res.data?.data || [];
      invoiceSearchResults.value = data;
      invoiceSearchCache.set(cacheKey, data);
    } catch (err) {
      if (err.name !== 'CanceledError') {
        invoiceSearchResults.value = [];
      }
    } finally {
      isSearchingInvoice.value = false;
    }
  }, 400);
}

function handleInvoiceBlur() {
  setTimeout(() => {
    showInvoiceDropdown.value = false;
  }, 200);
}

function selectNextResult() {
  if (invoiceSearchResults.value.length === 0) return;
  highlightedIdx.value = (highlightedIdx.value + 1) % invoiceSearchResults.value.length;
}

function selectPrevResult() {
  if (invoiceSearchResults.value.length === 0) return;
  highlightedIdx.value = highlightedIdx.value <= 0 ? invoiceSearchResults.value.length - 1 : highlightedIdx.value - 1;
}

function selectHighlightedResult() {
  if (highlightedIdx.value >= 0 && highlightedIdx.value < invoiceSearchResults.value.length) {
    selectInvoice(invoiceSearchResults.value[highlightedIdx.value]);
  }
}

function getStatusBadgeClass(status) {
  if (status === 'cash') return 'bg-green-100 text-green-800';
  if (status === 'credit') return 'bg-gray-100 text-gray-800';
  if (status === 'partial') return 'bg-yellow-100 text-yellow-800';
  return 'bg-slate-100 text-slate-800';
}

const invoiceDropdownPosition = computed(() => {
  // Access dropdownPositionUpdate to create dependency for reactivity
  dropdownPositionUpdate.value;

  if (!invoiceSearchInputRef.value) {
    return { top: '0', left: '0', width: '0' };
  }

  const rect = invoiceSearchInputRef.value.getBoundingClientRect();
  const inputWidth = rect.width;

  return {
    top: `${rect.bottom + 4}px`,
    left: `${rect.left}px`,
    width: `${inputWidth}px`
  };
});

async function selectInvoice(invoice) {
  // تعيين طريقة الدفع الافتراضية من الفاتورة أو أول خيار
  if (invoice.payment_method_id) {
    paymentMethodId.value = invoice.payment_method_id;
  } else {
    paymentMethodId.value = paymentMethods.value.length ? paymentMethods.value[0].id : null;
  }
  isSearchingInvoice.value = true;
  try {
    let items = invoice.items;
    if (!items) {
      if (props.type === 'sales') {
        const itemsRes = await apiClient.get(`/sales/${invoice.id}`);
        items = itemsRes.data?.data?.items || [];
      } else {
        const itemsRes = await apiClient.get('/returns/invoice-items', { params: { type: 'purchase', invoice_id: invoice.id } });
        items = itemsRes.data?.data?.items || [];
      }
    }
    // استخدام API الجديد الذي يعيد item_id
    let availableItemsMap = {};
    try {
      const sumRes = await apiClient.get('/returns/returned-qty', {
        params: {
          type: normalizedType.value,
          invoice_id: invoice.id
        }
      });
      const rows = sumRes.data?.data || [];

      // تحويل إلى Map بناءً على item_id
      availableItemsMap = rows.reduce((acc, r) => {
        acc[r.item_id] = {
          item_id: Number(r.item_id),
          product_id: Number(r.product_id),
          original_qty: Number(r.original_qty || 0),
          returned_qty: Number(r.returned_qty || 0),
          remaining_qty: Number(r.remaining_qty || 0)
        };
        return acc;
      }, {});
    } catch (e) {
      console.error('Error fetching available quantities:', e);
      availableItemsMap = {};
    }

    selectedInvoice.value = {
      ...invoice,
      items: items.map(item => {
        // استخدام item.id (sale_item_id أو purchase_item_id)
        const itemId = item.id;
        const s = availableItemsMap[itemId] || null;
        const remaining = s ? s.remaining_qty : Number(item[quantityKey.value] || 0);
        const prev = s ? s.returned_qty : 0;

        return {
          ...item,
          item_id: itemId,           // حفظ item_id
          prevReturned: prev,
          remainingQty: remaining,
          returnQuantity: 0
        };
      })
    };
    returnItems.value = [];
    invoiceNumber.value = invoice.invoice_number;
    invoiceSearchResults.value = [];
    isFullReturn.value = false;
    paidAmount.value = 0;
    refundMode.value = 'auto';
    // تعيين رقم الفاتورة للطباعة
    invoiceId.value = invoice.id;
    // تعيين الطرف (عميل أو مورد) بناءً على نوع الفاتورة
    if (invoice.customer_id) partyId.value = invoice.customer_id;
    else if (invoice.supplier_id) partyId.value = invoice.supplier_id;
    else partyId.value = null;
  } catch (error) {
    selectedInvoice.value = null;
    showToast('تعذر جلب تفاصيل الفاتورة', 'error');
  } finally {
    isSearchingInvoice.value = false;
  }
}

function setFullReturn() {
  if (selectedInvoice.value && selectedInvoice.value.items) {
    selectedInvoice.value.items.forEach(item => {
      const maxQty = Number(item.remainingQty ?? item[quantityKey.value] ?? 0);
      item.returnQuantity = isFullReturn.value ? maxQty : 0;
    });
    returnItems.value = isFullReturn.value
      ? selectedInvoice.value.items
          .filter(item => !isNaN(Number(item[priceKey.value])) && Number(item[priceKey.value]) > 0)
          .map(item => ({ ...item, [priceKey.value]: Number(item[priceKey.value]) }))
      : [];
    if (isFullReturn.value && returnItems.value.length === 0) {
      showToast('لا يوجد منتجات لها سعر صالح في هذه الفاتورة!', 'error');
    }
  }
}

function addToReturnList(item) {
  if (!item.returnQuantity || item.returnQuantity < 1) return showToast('يرجى تحديد كمية صالحة', 'warning');
  const maxQty = Number(item.remainingQty ?? item[quantityKey.value] ?? 0);
  if (item.returnQuantity > maxQty) return showToast(`الكمية المتاحة هي ${maxQty} فقط`, 'warning');
  if (maxQty <= 0) return showToast('لا يوجد رصيد متبقٍ للإرجاع لهذا المنتج', 'warning');

  // استخدام item_id بدلاً من id للبحث
  const existingItem = returnItems.value.find(i => i.item_id === item.item_id);
  if (existingItem) {
    existingItem.returnQuantity = item.returnQuantity;
    existingItem[priceKey.value] = Number(item[priceKey.value]);
  } else {
    returnItems.value.push({
      ...item,
      [priceKey.value]: Number(item[priceKey.value])
    });
  }
  showToast('تمت الإضافة إلى قائمة الإرجاع', 'success');
}

async function submitReturn() {
  if (returnItems.value.length === 0) return showToast('قائمة الإرجاع فارغة!', 'warning');
  if (!returnReason.value.trim()) return showToast('يرجى كتابة سبب الإرجاع', 'warning');

  if (!paymentMethodId.value) return showToast('يرجى اختيار طريقة الدفع', 'warning');
  const selectedPM = paymentMethods.value.find(pm => Number(pm.id) === Number(paymentMethodId.value));
  if (selectedPM?.kind === 'credit' && Number(paidAmount.value) > 0) {
    return showToast('طريقة الدفع الآجلة لا تقبل مبلغاً مسترداً — استخدم credit_note أو اختر طريقة دفع أخرى', 'error');
  }
  if (!invoiceId.value) return showToast('رقم الفاتورة غير محدد', 'warning');

  // تحقق من الحقول داخل كل عنصر
  for (const [index, item] of returnItems.value.entries()) {
    const requiredFields = ['product_id', 'unit_id', 'returnQuantity', priceKey.value];
    for (const field of requiredFields) {
      if (!item[field] && item[field] !== 0) {
        return showToast(`العنصر رقم ${index + 1} ناقص فيه الحقل: ${field}`, 'error');
      }
    }
  }

  isSubmittingReturn.value = true;
  try {
    const user = JSON.parse(localStorage.getItem('user')) || {};
    const today = new Date();
    const formattedDate = getLocalDateISO(today); // YYYY-MM-DD (local)
    const returnData = {
      return_type: normalizedType.value,
      return_date: formattedDate,
      invoice_id: invoiceId.value,
      party_id: partyId.value,
      items: returnItems.value.map(item => ({
        // إضافة sale_item_id أو purchase_item_id
        sale_item_id: normalizedType.value === 'sale' ? item.item_id : undefined,
        purchase_item_id: normalizedType.value === 'purchase' ? item.item_id : undefined,
        product_id: item.product_id,
        unit_id: item.unit_id,
        quantity: item.returnQuantity,
        unit_price: item[priceKey.value],
        subtotal: item[priceKey.value] * item.returnQuantity
      })),
      paid_amount: paidAmount.value,
      payment_method_id: paymentMethodId.value,
      refund_mode: isSale.value ? refundMode.value : undefined,

      notes: returnReason.value,
      cashier_id: user.id,
      branch_id: user.branch_id
    };
    const res = await returnStore.createReturn(returnData);
    if (res.status !== 'success') throw new Error(res.message || 'فشل تسجيل المرتجع');
    const result = res.data || {};

    // أبلغ الواجهة عن تسجيل مرتجع بنجاح لتحديث درج النقدية (إن كان نقديًا)
    try {
      const detail = {
        returnId: result.id || Date.now(),
        return_type: returnData.return_type,
        saleId: invoiceId.value,
        customerId: partyId.value,
        totalRefunded: Number(returnData.paid_amount || 0),
        payment_method_id: Number(returnData.payment_method_id),
        paid_amount: Number(returnData.paid_amount || 0)
      };
      window.dispatchEvent(new CustomEvent('pos:return-recorded', { detail }));
    } catch (_) { /* ignore */ }
    showToast('تم تسجيل المرتجع بنجاح', 'success');

    // إرسال البيانات الحقيقية إلى الـ parent
    emit('returnSuccess', {
      returnId: result.id || Date.now(),
      saleId: invoiceId.value,
      customerId: partyId.value,
      totalRefunded: Number(returnData.paid_amount || 0),
      paymentMethodId: paymentMethodId.value
    });
    // إعادة تعيين النموذج
    selectedInvoice.value = null;
    returnItems.value = [];
    returnReason.value = '';
    paidAmount.value = 0;
    paymentMethodId.value = paymentMethods.value[0]?.id || null;
    discountType.value = 'fixed';
    discountValue.value = 0;
    partyId.value = null;
    invoiceId.value = null;
  } catch (error) {
    showToast(error?.response?.data?.message || 'حدث خطأ أثناء تسجيل المرتجع', 'error');
  } finally {
    isSubmittingReturn.value = false;
  }
}
</script>

<style scoped>
.filter-input-v2 {
  @apply h-9 w-full bg-white border border-slate-200 rounded-md px-3 text-[11px] font-bold text-slate-700 focus:ring-2 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all;
}
/* Transitions */
.dropdown-enter-active { animation: dropdownIn 0.2s ease-out; }
@keyframes dropdownIn { from { opacity: 0; transform: translateY(-8px); } to { opacity: 1; transform: translateY(0); } }
.animate-fadeIn { animation: fadeIn 0.3s ease-out; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }
</style>