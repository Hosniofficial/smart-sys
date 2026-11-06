<template>

  <BaseModal 
    :show="show" 
    @close="$emit('close')" 
    maxWidth="6xl" 
    variant="standard"
    :showCloseButton="false"
  >
    <template #header>
      <div class="flex items-center gap-3">
        <div class="w-10 h-10 bg-indigo-600 rounded-lg flex items-center justify-center text-white text-sm shadow-lg shadow-indigo-100">
          <i :class="editMode ? 'fas fa-edit' : 'fas fa-plus-circle'"></i>
        </div>
        <div>
          <h3 class="text-sm font-bold text-slate-900 uppercase tracking-tight">{{ editMode ? 'تعديل فاتورة مشتريات' : 'تسجيل فاتورة شراء جديدة' }}</h3>
          <p class="text-[10px] text-slate-400 font-mono mt-1">{{ localForm.invoiceNumber || 'مسودة جديدة' }}</p>
        </div>
      </div>
      <button @click="$emit('close')" class="text-slate-400 hover:text-rose-500 transition-colors absolute top-5 left-6"><i class="fas fa-times text-lg"></i></button>
    </template>
    
    <div class="space-y-10" dir="rtl">

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 bg-slate-50/50 p-6 rounded-xl border border-slate-200">

          <div class="space-y-1.5">

            <label class="metadata-label">المورّد المستهدف <span class="text-rose-500">*</span></label>

            <select v-model="localForm.supplierId" class="form-input-v3 font-bold" required>

              <option value="">-- اختر المورد --</option>

              <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>

            </select>

          </div>

          <div class="space-y-1.5">

            <label class="metadata-label">فرع الاستلام</label>

            <select v-model="localForm.branchId" class="form-input-v3">

              <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>

            </select>

          </div>

          <div class="space-y-1.5">

            <label class="metadata-label">تاريخ الشراء</label>

            <div class="relative">

              <input ref="purchaseDateRef" type="date" v-model="localForm.purchaseDate" class="form-input-v3 font-mono" style="padding-left: 2rem;" />

              <i @click="purchaseDateRef?.showPicker?.()" class="fas fa-calendar absolute left-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px] cursor-pointer hover:text-slate-500 transition-colors"></i>

            </div>

          </div>

        </div>

        <div class="space-y-4">

          <div class="flex items-center justify-between">

            <h4 class="text-xs font-bold text-slate-400 uppercase tracking-[0.2em]">بنود الفاتورة</h4>

            <button @click="addItemRow" class="h-8 px-4 bg-slate-900 text-white rounded text-[10px] font-bold hover:bg-black transition-all">+ إضافة صنف</button>

          </div>

          <div class="border border-slate-200 rounded-xl overflow-hidden shadow-sm">

            <table class="w-full text-right text-xs">

              <thead><tr class="bg-slate-50 border-b border-slate-200 font-bold text-slate-400 uppercase tracking-tighter"><th class="px-4 py-3">المنتج</th><th class="px-4 py-3 text-center">الكمية</th><th class="px-4 py-3 text-center">السعر</th><th class="px-4 py-3">تتبع (Batch/Exp/SN)</th><th class="px-4 py-3 text-left">الإجمالي</th><th class="px-4 py-3 w-10"></th></tr></thead>

              <tbody class="divide-y divide-slate-100">

                <tr v-for="(item, index) in localForm.items" :key="index" class="hover:bg-slate-50/50 transition-colors">

                  <td class="px-4 py-3">

                    <div class="flex gap-2">

                      <select v-model="item.productId" @change="onProductChange(item, index)" class="form-input-v3 h-8 text-[11px] font-bold flex-grow">

                        <option value="">اختر منتج</option>

                        <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }}</option>

                      </select>

                      <button @click="$emit('open-product-modal', { index, branchId: localForm.branchId })" class="w-8 h-8 border border-slate-200 bg-white rounded-md text-blue-600 hover:bg-blue-50 shrink-0"><i class="fas fa-plus text-[10px]"></i></button>

                    </div>

                  </td>

                  <td class="px-4 py-3 text-center"><input type="number" min="1" v-model.number="item.quantity" @input="updateTotals" class="h-8 w-16 border-2 border-slate-100 rounded text-center text-[11px] font-bold text-indigo-600 outline-none focus:border-indigo-500" /></td>

                  <td class="px-4 py-3 text-center"><input type="number" step="0.01" v-model.number="item.purchasePrice" @input="updateTotals" class="h-8 w-20 border-2 border-slate-100 rounded text-center text-[11px] font-bold text-emerald-600 outline-none focus:border-indigo-500" /></td>

                  <td class="px-4 py-3">

                    <div class="flex flex-col gap-1 min-w-[110px]">

                      <input v-if="item.hasBatchNumber" type="text" v-model="item.batchNumber" placeholder="رقم الدفعة" class="h-7 border border-slate-100 rounded text-[9px] px-2" />

                      <input v-if="item.hasExpiryDate" type="date" v-model="item.expiryDate" class="h-7 border border-slate-100 rounded text-[9px] px-2" />

                      <input v-if="item.hasSerialNumber" type="text" v-model="item.serial" placeholder="السيريال" class="h-7 border border-slate-100 rounded text-[9px] px-2" />

                      <span v-if="!item.hasBatchNumber && !item.hasExpiryDate && !item.hasSerialNumber" class="text-slate-300 text-[9px] uppercase italic">لا يتطلب تتبع</span>

                    </div>

                  </td>

                  <td class="px-4 py-3 text-left font-bold text-slate-900 font-mono">{{ formatCurrency(item.quantity * item.purchasePrice) }}</td>

                  <td class="px-4 py-3 text-center">

                    <button @click="removeItemRow(index)" class="text-slate-300 hover:text-rose-500"><i class="fas fa-times-circle"></i></button>

                  </td>

                </tr>

                <tr v-if="!localForm.items.length">

                  <td colspan="6" class="py-10 text-center text-slate-300 font-bold uppercase tracking-widest">يرجى إضافة أصناف للفاتورة للبدء</td>

                </tr>

              </tbody>

            </table>

          </div>

        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-8">

          <div class="space-y-4">

            <label class="metadata-label">ملاحظات إضافية</label>

            <textarea v-model="localForm.notes" rows="4" class="form-input-v3 h-auto p-4 italic text-slate-500 font-medium" placeholder="سجل تفاصيل استلام الشحنة أو أي ملاحظات مرجعية..."></textarea>

            <div class="grid grid-cols-2 gap-3">

              <div class="space-y-1.5">

                <label class="metadata-label">نوع الخصم</label>

                <select v-model="localForm.discountType" class="form-input-v3 font-bold">

                  <option value="fixed">مبلغ ثابت</option>

                  <option value="percentage">نسبة %</option>

                </select>

              </div>

              <div class="space-y-1.5">

                <label class="metadata-label">قيمة الخصم</label>

                <input type="number" min="0" step="0.01" v-model.number="localForm.discount" class="form-input-v3 font-bold text-rose-600" />

              </div>

            </div>

          </div>

          <div class="bg-slate-900 rounded-2xl p-8 text-white shadow-2xl space-y-6 relative overflow-hidden">

            <div class="absolute top-0 right-0 w-24 h-24 bg-white/5 rounded-full -translate-x-6 -translate-y-6"></div>

            <div class="space-y-3 relative z-10">

              <div class="flex justify-between text-[11px] font-medium text-slate-400 uppercase tracking-widest"><span>إجمالي البنود</span><span class="text-white">{{ formatCurrency(subtotal) }}</span></div>

              <div class="flex justify-between text-[11px] font-medium text-slate-400 uppercase tracking-widest"><span>الخصم</span><span class="text-rose-400">- {{ formatCurrency(discountAmount) }}</span></div>

              <div class="flex justify-between text-[11px] font-medium text-slate-400 uppercase tracking-widest"><span>الضريبة ({{ taxSettings.value }}%)</span><span class="text-blue-400">{{ formatCurrency(taxAmount) }}</span></div>

              <div class="pt-4 border-t border-white/10 flex justify-between items-end">

                <span class="text-xs font-bold uppercase text-blue-400">صافي المستحق</span>

                <span class="text-3xl font-bold font-mono tracking-tighter">{{ formatCurrency(grandTotal) }}</span>

              </div>

            </div>

            <div class="grid grid-cols-2 gap-4 relative z-10 pt-4 border-t border-white/5">

              <div class="space-y-1.5">

                <label class="text-[9px] font-bold text-slate-500 uppercase">طريقة الدفع</label>

                <select v-model="localForm.paymentMethodId" class="h-9 w-full bg-white/5 border border-white/10 rounded-md px-2 text-[11px] font-bold text-white">

                  <option v-for="m in paymentMethods" :key="m.id" :value="m.id" class="text-slate-900">{{ m.name }}</option>

                </select>

              </div>

              <div class="space-y-1.5">

                <label class="text-[9px] font-bold text-slate-500 uppercase">المبلغ المدفوع</label>

                <input type="number" v-model.number="localForm.paidAmount" :class="localForm.paidAmount > grandTotal ? 'border-red-500' : 'border-white/10'" class="h-9 w-full bg-white/5 border rounded-md px-3 text-xs font-bold text-emerald-400 text-left" />

              </div>

            </div>

            <p v-if="isCashPayment && Number(localForm.paidAmount) > 0" class="relative z-10 rounded-md border border-amber-400/20 bg-amber-400/10 px-3 py-2 text-[10px] leading-relaxed text-amber-200">
              سيتم تسجيل المبلغ المدفوع على نقدية فرع الاستلام. عند تفعيل جلسات الكاشير، يلزم وجود جلسة مفتوحة لهذا الفرع لإتمام الدفعة النقدية. الدفعة البنكية أو الإلكترونية تُسجل على حساب وسيلة الدفع ولا تخصم من درج النقدية.
            </p>

            <p v-if="localForm.paidAmount > grandTotal" class="text-[9px] text-red-400 font-bold relative z-10">المبلغ المدفوع يتجاوز الإجمالي</p>

            <div v-if="remainingAmount > 0" class="flex justify-between items-center text-xs font-bold text-rose-400 pt-2 relative z-10">

              <span>المتبقي (ذمم):</span>

              <span class="font-mono">{{ formatCurrency(remainingAmount) }}</span>

            </div>

          </div>

        </div>

    </div>
    
    <template #footer>
      <button @click="$emit('close')" class="px-6 h-10 text-xs font-bold text-slate-500 hover:text-slate-700 transition-colors">
        إلغاء
      </button>
      <LoadingButton
        :text="editMode ? 'تحديث الفاتورة' : 'تأكيد وحفظ الفاتورة'"
        :isLoading="isSaving"
        :loadingText="editMode ? 'جاري التحديث...' : 'جاري الحفظ...'"
        variant="primary"
        loaderType="dots"
        @click="handleSave"
        class="px-10 h-10"
      />
    </template>
  </BaseModal>

</template>

<script setup>

import { ref, computed, watch } from 'vue';

import { useToast } from '@/composables/useToast';

import { useCompanyCurrency } from '@/composables/useCompanyCurrency';

import BaseModal from '@/components/BaseModal.vue';

import LoadingButton from '@/components/ui/LoadingButton.vue';

const props = defineProps({

  show: Boolean,

  editMode: Boolean,

  initialData: Object,

  suppliers: Array,

  products: Array,

  branches: Array,

  paymentMethods: Array,

  taxSettings: Object,

  isSaving: Boolean,

  pendingProductAdd: { type: Object, default: null } // { index, product }

});

const emit = defineEmits(['close', 'save', 'open-product-modal']);

const { showToast } = useToast();

const { formatCurrencyLocale, fetchSettings } = useCompanyCurrency();

const formatCurrency = formatCurrencyLocale;

// refs لمنتقيات التاريخ
const purchaseDateRef = ref(null);

const safeInitial = () => {
  const base = props.initialData ? JSON.parse(JSON.stringify(props.initialData)) : {};
  if (base.discount === undefined) base.discount = 0;
  if (base.discountType === undefined) base.discountType = 'fixed';
  if (!Array.isArray(base.items)) base.items = [];
  if (base.paidAmount === undefined) base.paidAmount = 0;
  return base;
};

const localForm = ref(safeInitial());

// تحديث localForm عند كل فتح للـ modal
watch(() => props.show, async (val) => {
  if (val) {
    localForm.value = safeInitial();
    await fetchSettings();
  }
});

const subtotal = computed(() => localForm.value.items.reduce((sum, it) => sum + (it.quantity * it.purchasePrice), 0));

const isCashPayment = computed(() => {
  const method = (props.paymentMethods || []).find(
    (item) => Number(item.id) === Number(localForm.value.paymentMethodId)
  );
  return String(method?.kind || '').toLowerCase() === 'cash';
});

const discountAmount = computed(() => {

  const disc = Number(localForm.value.discount || 0);

  if (localForm.value.discountType === 'percentage') {

    return Math.min(Math.max(disc, 0), 100) / 100 * subtotal.value;

  }

  return Math.min(disc, subtotal.value);

});

const baseAfterDiscount = computed(() => Math.max(0, subtotal.value - discountAmount.value));

const taxAmount = computed(() => (parseFloat(props.taxSettings.value || 0) / 100 * baseAfterDiscount.value));

const grandTotal = computed(() => baseAfterDiscount.value + taxAmount.value);

const remainingAmount = computed(() => Math.max(0, grandTotal.value - (localForm.value.paidAmount || 0)));

const addItemRow = () => localForm.value.items.push({ productId: '', quantity: 1, purchasePrice: 0.01, unitId: 1, batchNumber: '', expiryDate: '', serial: '' });

const removeItemRow = (idx) => localForm.value.items.splice(idx, 1);

const updateTotals = () => { /* computed تعيد الحساب تلقائياً */ };

const onProductChange = (item, idx) => {

  const p = props.products.find(x => x.id === item.productId);

  if (p) {

    item.purchasePrice = p.purchasePrice || 0.01; item.unitId = p.unitId || 1;

    item.hasBatchNumber = p.hasBatchNumber; item.hasExpiryDate = p.hasExpiryDate; item.hasSerialNumber = p.hasSerialNumber;

    item.batchNumber = ''; item.expiryDate = ''; item.serial = '';

  }

};

watch(() => props.pendingProductAdd, (val) => {

  if (val && localForm.value.items[val.index]) {

    const item = localForm.value.items[val.index];

    item.productId = val.product.id;

    item.purchasePrice = val.product.purchasePrice || 0.01;

    item.unitId = val.product.unitId || 1;

    item.hasBatchNumber = val.product.hasBatchNumber;

    item.hasExpiryDate = val.product.hasExpiryDate;

    item.hasSerialNumber = val.product.hasSerialNumber;

  }

});

const validatePurchaseForm = () => {

  if (!localForm.value.items.length) { showToast('يجب إضافة منتج واحد على الأقل', 'error'); return false; }

  if (!localForm.value.supplierId) { showToast('يجب اختيار المورد', 'error'); return false; }

  for (let i = 0; i < localForm.value.items.length; i++) {

    const item = localForm.value.items[i];

    if (!item.productId) { showToast(`المنتج رقم ${i + 1} غير محدد`, 'error'); return false; }

    if (!item.quantity || item.quantity <= 0) { showToast(`كمية المنتج رقم ${i + 1} غير صالحة`, 'error'); return false; }

    if (!item.purchasePrice || item.purchasePrice <= 0) { showToast(`سعر المنتج رقم ${i + 1} غير صالح`, 'error'); return false; }

    if (item.hasBatchNumber && !item.batchNumber) { showToast(`المنتج رقم ${i + 1} يتطلب رقم الدفعة`, 'error'); return false; }

    if (item.hasExpiryDate && !item.expiryDate) { showToast(`المنتج رقم ${i + 1} يتطلب تاريخ الصلاحية`, 'error'); return false; }

    if (item.hasSerialNumber && !item.serial) { showToast(`المنتج رقم ${i + 1} يتطلب الرقم التسلسلي`, 'error'); return false; }

  }

  const paidAmt = parseFloat(localForm.value.paidAmount || 0);

  if (paidAmt > grandTotal.value) {

    showToast(`المبلغ المدفوع (${paidAmt}) لا يمكن أن يتجاوز إجمالي الفاتورة (${grandTotal.value.toFixed(2)})`, 'error');

    return false;

  }

  const selectedMethod = props.paymentMethods.find(m => Number(m.id) === Number(localForm.value.paymentMethodId));

  if (selectedMethod?.kind === 'credit' && paidAmt > 0) {

    showToast('طريقة الدفع الآجلة لا تقبل مبلغاً مدفوعاً — اجعل المبلغ المدفوع = 0 أو اختر طريقة دفع أخرى', 'error');

    return false;

  }

  return true;

};

const handleSave = () => {

  if (!validatePurchaseForm()) return;

  const paidAmt = parseFloat(localForm.value.paidAmount || 0);

  const payload = {

    supplierId: Number(localForm.value.supplierId),

    branchId: String(localForm.value.branchId),

    invoiceNumber: localForm.value.invoiceNumber,

    purchaseDate: localForm.value.purchaseDate,

    taxRate: parseFloat(props.taxSettings.value || 0),

    discountValue: Number(localForm.value.discount || 0),

    discountType: localForm.value.discountType || 'fixed',

    paidAmount: paidAmt,

    paymentMethodId: Number(localForm.value.paymentMethodId),

    remainingAmount: Math.max(0, grandTotal.value - paidAmt),

    status: paidAmt >= grandTotal.value ? 'completed' : (paidAmt > 0 ? 'partial' : 'pending'),

    notes: localForm.value.notes || '',

    totalAmount: grandTotal.value,

    totalItems: localForm.value.items.length,

    items: localForm.value.items.map(it => ({

      id: it.id || undefined,

      productId: Number(it.productId),

      quantity: parseFloat(Number(it.quantity).toFixed(2)),

      price: it.purchasePrice,

      cost: it.purchasePrice,

      total: it.purchasePrice * it.quantity,

      unitId: it.unitId || 1,

      batchNumber: it.batchNumber || null,

      expiryDate: it.expiryDate || null,

      serial: it.serial || null,

      warehouseId: localForm.value.branchId

    }))

  };

  emit('save', payload);

};

watch(() => props.initialData, (newVal) => {

  localForm.value = JSON.parse(JSON.stringify(newVal));

  if (localForm.value.discount === undefined) localForm.value.discount = 0;

  if (localForm.value.discountType === undefined) localForm.value.discountType = 'fixed';

}, { deep: true });

</script>

<style scoped>

.form-input-v3 { @apply h-9 w-full bg-white border border-slate-200 rounded-md px-3 text-xs font-medium focus:ring-4 focus:ring-indigo-500/10 focus:border-indigo-500 outline-none transition-all; }

</style>