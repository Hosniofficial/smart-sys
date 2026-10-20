<template>
  <Teleport to="body">
    <div
      v-if="modelValue"
      class="fixed inset-0 z-[120] flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm font-sans antialiased text-slate-900"
      @click.self="closeModal"
    >
      <div
        class="bg-white rounded-xl shadow-xl w-full max-w-lg max-h-[calc(100dvh-2rem)] overflow-hidden border border-slate-200 animate-modalIn flex flex-col text-right"
        dir="rtl"
        @click.stop
      >
      
      <!-- Modal Header -->
      <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between shrink-0 bg-slate-50/50">
        <div class="flex items-center gap-3">
          <div class="w-8 h-8 bg-amber-500 rounded-lg flex items-center justify-center text-white text-xs shadow-sm">
            <i class="fas fa-sliders"></i>
          </div>
          <div>
            <h3 class="text-sm font-bold text-slate-900 uppercase">تسوية المخزون</h3>
            <p class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">تعديل يدوي للكميات في المخزون</p>
          </div>
        </div>
        <button
          @click="closeModal"
          class="w-7 h-7 rounded-md border border-slate-200 text-slate-400 hover:text-rose-600 hover:border-rose-200 hover:bg-rose-50 transition-all flex items-center justify-center"
          title="إغلاق"
        >
          <i class="fas fa-times text-xs"></i>
        </button>
      </div>

      <!-- Modal Body -->
      <form @submit.prevent="handleSubmit" class="flex-1 min-h-0 p-6 space-y-5 overflow-y-auto custom-scroll">
        
        <!-- Product Summary Profile -->
        <div v-if="product" class="bg-slate-900 text-white p-4 rounded-xl shadow-sm space-y-3 relative overflow-hidden">
          <div class="flex items-center gap-3">
            <div class="w-9 h-9 bg-white/10 rounded-lg flex items-center justify-center text-blue-400 text-xs shrink-0">
              <i class="fas fa-box"></i>
            </div>
            <div class="space-y-0.5 overflow-hidden">
              <p class="text-[9px] font-bold text-blue-400 uppercase tracking-widest">المنتج المستهدف</p>
              <h4 class="text-xs font-bold truncate">{{ product.name || product.product_name }}</h4>
              <div class="flex items-center gap-2 text-[10px] font-mono text-slate-400">
                <span v-if="product.barcode">BAR: {{ product.barcode }}</span>
                <span v-if="product.barcode" class="text-slate-700">|</span>
                <span>CODE: {{ product.product_code || product.code }}</span>
              </div>
            </div>
          </div>

          <div class="pt-2.5 border-t border-slate-800 flex justify-between items-center text-xs">
            <span class="text-slate-400 text-[11px] font-medium">الرصيد الحالي بالمستودع:</span>
            <span class="px-2 py-0.5 bg-white/10 rounded font-mono font-bold text-blue-400 text-xs">
              {{ product.quantity || 0 }} {{ product.unit || 'قطعة' }}
            </span>
          </div>
        </div>

        <div v-else class="p-3 bg-rose-50 border border-rose-200 rounded-lg text-rose-700 text-xs font-bold flex items-center gap-2">
          <i class="fas fa-triangle-exclamation text-rose-500 shrink-0"></i>
          <span>تعذر تحميل بيانات المنتج المطلوبة.</span>
        </div>

        <!-- Adjustment Type Selector (Pills) -->
        <div class="space-y-1.5">
          <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider px-1">نوع التسوية (الإجراء)</label>
          <div class="grid grid-cols-2 gap-2">
            <label
              class="flex items-center justify-center gap-2 h-9 rounded-md border text-xs font-bold transition-all cursor-pointer select-none"
              :class="adjustmentType === 'add' ? 'bg-emerald-50 border-emerald-300 text-emerald-700 shadow-sm' : 'bg-white border-slate-200 text-slate-600 hover:bg-slate-50'"
            >
              <input type="radio" v-model="adjustmentType" value="add" class="sr-only">
              <i class="fas fa-plus text-[10px]"></i>
              <span>إضافة كمية للمخزون (+)</span>
            </label>

            <label
              class="flex items-center justify-center gap-2 h-9 rounded-md border text-xs font-bold transition-all cursor-pointer select-none"
              :class="adjustmentType === 'remove' ? 'bg-rose-50 border-rose-300 text-rose-700 shadow-sm' : 'bg-white border-slate-200 text-slate-600 hover:bg-slate-50'"
            >
              <input type="radio" v-model="adjustmentType" value="remove" class="sr-only">
              <i class="fas fa-minus text-[10px]"></i>
              <span>خصم / عجز (- سحب)</span>
            </label>
          </div>
        </div>

        <!-- Quantity Field -->
        <div class="space-y-1.5">
          <label for="quantity" class="text-[10px] font-bold text-slate-400 uppercase tracking-wider px-1">الكمية المراد تعديلها</label>
          <div class="relative">
            <input 
              type="number" 
              id="quantity" 
              v-model.number="form.quantity" 
              class="filter-input font-mono text-center text-sm font-bold pl-14"
              min="0"
              step="0.01"
              required
              @input="calculateNewQuantity"
            >
            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-[10px] font-bold font-mono text-slate-400 uppercase">
              {{ (product?.unit) || 'قطعة' }}
            </span>
          </div>
          <p v-if="errors.quantity" class="text-[10px] text-rose-600 font-bold px-1">{{ errors.quantity }}</p>
        </div>

        <!-- New Quantity Preview (Result Card) -->
        <div class="p-3.5 rounded-xl border border-slate-200 bg-slate-50/70 flex items-center justify-between">
          <div>
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">الرصيد النهائي المتوقع</p>
            <p class="text-xs text-slate-500 font-medium mt-0.5">بعد تنفيذ حركة التسوية</p>
          </div>
          <div class="text-left font-mono font-bold text-2xl tracking-tight" :class="adjustmentType === 'add' ? 'text-emerald-600' : 'text-rose-600'">
            {{ newQuantity }}
          </div>
        </div>

        <!-- Reason Selection -->
        <div class="space-y-1.5">
          <label for="reason" class="text-[10px] font-bold text-slate-400 uppercase tracking-wider px-1">
            سبب التسوية <span class="text-rose-500">*</span>
          </label>
          <select id="reason" v-model="form.reason" class="filter-input appearance-none cursor-pointer" required>
            <option value="">-- اختر السبب --</option>
            <option value="stock_take">جرد مخزون (مطابقة دورية)</option>
            <option value="damaged">صنف تالف / غير صالح</option>
            <option value="expired">منتهي الصلاحية</option>
            <option value="theft">عجز / فقدان غير مبرر</option>
            <option value="other">أخرى (مذكور بالملاحظات)</option>
          </select>
        </div>

        <!-- Notes Field -->
        <div class="space-y-1.5">
          <label for="notes" class="text-[10px] font-bold text-slate-400 uppercase tracking-wider px-1">ملاحظات إضافية</label>
          <textarea 
            id="notes" 
            v-model="form.notes" 
            rows="2" 
            class="w-full rounded-md border border-slate-200 p-2.5 text-xs font-medium text-slate-700 placeholder:text-slate-300 focus:ring-2 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all custom-scroll"
            placeholder="اكتب تفاصيل إضافية للمرجعية الإدارية والمحاسبية..."
          ></textarea>
        </div>
      </form>

      <!-- Modal Footer -->
      <div class="px-6 py-3.5 bg-slate-50/70 border-t border-slate-100 flex items-center justify-end gap-2.5 shrink-0">
        <button
          type="button"
          @click="$emit('close')"
          class="h-9 px-4 rounded-md border border-slate-200 bg-white text-slate-700 text-xs font-bold hover:bg-slate-50 active:scale-95 transition-all shadow-sm"
        >
          إلغاء
        </button>
        <button
          type="button"
          @click="handleSubmit"
          class="h-9 px-5 bg-slate-900 text-white rounded-md text-xs font-bold shadow-sm hover:bg-black active:scale-95 transition-all flex items-center gap-2 disabled:opacity-50"
          :disabled="isSubmitting"
        >
          <BaseSpinner v-if="isSubmitting" :size="14" color="#ffffff" />
          <i v-else class="fas fa-check text-[10px]"></i>
          <span>تأكيد وتسوية المخزون</span>
        </button>
      </div>

      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useToast } from '@/composables/useToast';
import { adjustStock } from '@/services/branchInventoryService';
import BaseSpinner from '@/components/ui/BaseSpinner.vue';

// --- Logic Initialization (STRICTLY PRESERVED) ---
const props = defineProps({
  modelValue: { type: Boolean, default: false },
  branchId: { type: [Number, String], required: true },
  product: { type: Object, required: true }
});

const emit = defineEmits(['update:modelValue', 'close', 'adjusted']);

const closeModal = () => {
  emit('update:modelValue', false);
  emit('close');
};

const { showToast } = useToast();

const form = ref({
  product_id: null, quantity: 1, adjustment: 'add', reason: 'other', notes: '', reference: '', reference_type: 'adjustment', cost_price: null, sale_price: null
});

const adjustmentType = ref('add');
const isSubmitting = ref(false);
const errors = ref({});

// Logic: New Quantity Preview (Preserved)
const newQuantity = computed(() => {
  if (!props.product) return 0;
  const currentQty = parseFloat(props.product.quantity) || 0;
  const adjustmentQty = parseFloat(form.value.quantity) || 0;
  return adjustmentType.value === 'add' ? currentQty + adjustmentQty : Math.max(0, currentQty - adjustmentQty);
});

// Logic: Methods (Preserved)
const calculateNewQuantity = () => {
  const qty = parseFloat(form.value.quantity);
  form.value.quantity = isNaN(qty) ? 0 : Math.abs(qty);
};

const validate = () => {
  errors.value = {};
  let isValid = true;
  if (!form.value.quantity || form.value.quantity <= 0) { errors.value.quantity = 'يجب إدخال كمية صحيحة'; isValid = false; }
  else if (adjustmentType.value === 'remove' && form.value.quantity > props.product.quantity) { errors.value.quantity = 'الكمية المطلوب خصمها أكبر من الكمية المتاحة.'; isValid = false; }
  if (!form.value.reason) { errors.value.reason = 'يجب تحديد السبب'; isValid = false; }
  return isValid;
};

const handleSubmit = async () => {
  if (!validate()) return;
  isSubmitting.value = true;
  try {
    if (!form.value.product_id && props.product) form.value.product_id = props.product.product_id || props.product.id || null;
    
    if (!form.value.product_id) { showToast('تعذر تحديد المنتج', 'error'); return; }

    const adjustmentData = {
      product_id: form.value.product_id,
      quantity: adjustmentType.value === 'remove' ? -Math.abs(Number(form.value.quantity)) : Math.abs(Number(form.value.quantity)),
      type: adjustmentType.value === 'add' ? 'in' : 'out',
      reason: form.value.reason,
      notes: form.value.notes || null,
      reference_type: form.value.reference_type,
      cost_price: form.value.cost_price ? Number(form.value.cost_price) : null,
      sale_price: form.value.sale_price ? Number(form.value.sale_price) : null
    };

    const success = await adjustStock(props.branchId, adjustmentData);
    if (success) {
      showToast('تمت التسوية بنجاح', 'success');
      emit('adjusted', adjustmentData);
      emit('close');
    }
  } catch (error) {
    showToast(error.response?.data?.message || 'فشل في تسوية المخزون', 'error');
  } finally {
    isSubmitting.value = false;
  }
};

onMounted(() => {
  if (props.product) {
    form.value = { ...form.value, product_id: props.product.product_id || props.product.id, cost_price: props.product.cost_price || null, sale_price: props.product.sale_price || null };
  }
  if (!form.value.reason) form.value.reason = 'other';
});

watch(() => props.product, (p) => { if (p) form.value.product_id = p.product_id || p.id || null; });
</script>

<style scoped>
.filter-input {
  @apply h-9 w-full bg-white border border-slate-200 rounded-md px-3 text-[11px] font-bold text-slate-700 focus:ring-2 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all;
}

.custom-scroll::-webkit-scrollbar { width: 4px; }
.custom-scroll::-webkit-scrollbar-thumb { @apply bg-slate-200 rounded-full; }

@keyframes modalIn {
  from {
    opacity: 0;
    transform: scale(0.98) translateY(6px);
  }
  to {
    opacity: 1;
    transform: scale(1) translateY(0);
  }
}

.animate-modalIn {
  animation: modalIn 0.2s cubic-bezier(0.16, 1, 0.3, 1);
}
</style>