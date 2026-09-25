<template>
  <div class="fixed inset-0 z-[120] flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm" @click.self="closeModal">
    <div class="bg-white rounded-[2.5rem] shadow-2xl w-full max-w-lg overflow-hidden animate-modalIn border border-white flex flex-col text-right" dir="rtl" @click.stop>
      
      <!-- Modal Header -->
      <div class="px-8 py-6 border-b border-slate-50 bg-slate-50/50 flex items-center justify-between shrink-0">
        <div class="flex items-center gap-4">
          <div class="w-12 h-12 bg-blue-600 rounded-2xl flex items-center justify-center shadow-lg shadow-blue-200 text-white">
            <i class="fas fa-exchange-alt text-xl"></i>
          </div>
          <div>
            <h3 class="text-xl font-black text-slate-800 leading-none">نقل مخزون بين الفروع</h3>
            <p class="text-[10px] text-slate-400 mt-2 font-bold uppercase tracking-widest">تحويل الكميات بين الفروع والمخازن</p>
          </div>
        </div>
        <button @click="closeModal" class="w-10 h-10 rounded-xl flex items-center justify-center text-slate-400 hover:bg-rose-50 hover:text-rose-500 transition-all">
          <i class="fas fa-times text-xl"></i>
        </button>
      </div>

      <!-- Modal Body -->
      <form @submit.prevent="handleSubmit" class="p-8 space-y-6">
        
        <!-- Active Product Profile -->
        <div class="bg-slate-900 p-6 rounded-[2rem] text-white shadow-xl relative overflow-hidden group border border-slate-800">
          <div class="absolute top-0 right-0 w-24 h-24 bg-white/5 rounded-full translate-x-8 -translate-y-8 group-hover:scale-110 transition-transform"></div>
          <div class="relative z-10 flex items-center gap-4">
            <div class="w-10 h-10 bg-white/10 rounded-xl flex items-center justify-center text-blue-400 shadow-inner"><i class="fas fa-box"></i></div>
            <div>
              <p class="text-[9px] font-black text-blue-400 uppercase tracking-widest mb-1">الصنف المراد نقله</p>
              <h4 class="text-base font-black leading-tight">{{ product.product_name || product.name }}</h4>
            </div>
          </div>
          <div class="mt-6 pt-4 border-t border-white/5 flex justify-between items-center">
             <span class="text-[10px] font-black text-white/30 uppercase tracking-widest">الرصيد المتاح حالياً</span>
             <span class="px-3 py-1 bg-white/10 rounded-lg text-xs font-black text-blue-400 font-mono">{{ product.quantity }} قطعة</span>
          </div>
        </div>

        <!-- Destination branch -->
        <div class="space-y-2 group">
          <label for="destination-branch" class="modal-label">الفرع الهدف (الوجهة) <span class="text-rose-500">*</span></label>
          <div class="relative">
            <select 
              id="destination-branch" 
              v-model="destinationbranchId" 
              class="form-select-modern font-bold" 
              style="padding-right: 2rem;"
              required
            >
              <option :value="null" disabled>-- اختر مستودع الوجهة --</option>
              <option v-for="wh in destinationbranchs" :key="wh.id" :value="wh.id">
                {{ wh.name }}
              </option>
            </select>
            <i class="fas fa-map-location-dot absolute right-4 top-1/2 -translate-y-1/2 text-slate-300 group-focus-within:text-blue-500 transition-colors"></i>
          </div>
          <p v-if="serverErrors.destination_branch_id" class="text-[10px] text-rose-500 font-bold px-1">{{ serverErrors.destination_branch_id[0] }}</p>
        </div>

        <!-- Quantity Field -->
        <div class="space-y-2 group">
          <label for="quantity" class="modal-label">الكمية المراد نقلها <span class="text-rose-500">*</span></label>
          <div class="relative">
            <input 
              type="number" 
              id="quantity" 
              v-model.number="quantity" 
              class="w-full h-14 rounded-2xl border-slate-100 bg-slate-50 text-3xl font-black text-center text-slate-800 focus:bg-white focus:ring-4 focus:ring-blue-50 transition-all outline-none border" 
              min="1" 
              :max="product.quantity" 
              required
            >
            <i class="fas fa-truck-moving absolute right-5 top-1/2 -translate-y-1/2 text-slate-200 group-focus-within:text-blue-500 text-lg transition-all"></i>
            <span class="absolute left-5 top-1/2 -translate-y-1/2 text-[10px] font-black text-slate-300 uppercase tracking-widest">قطعة</span>
          </div>
          <p v-if="serverErrors.quantity" class="text-[10px] text-rose-500 font-bold px-1">{{ serverErrors.quantity[0] }}</p>
        </div>

        <!-- Transfer Notice -->
        <div class="p-4 bg-blue-50 rounded-2xl border border-blue-100 flex items-start gap-3">
          <i class="fas fa-info-circle text-blue-500 mt-0.5"></i>
          <p class="text-[11px] text-blue-700 font-bold leading-relaxed">عند تأكيد النقل، سيتم خصم الكمية من الفرع الحالي وإضافتها فوراً للمستودع المختار في السجل المحاسبي.</p>
        </div>

      </form>

      <!-- Modal Footer -->
      <div class="px-8 py-6 bg-slate-50 border-t border-slate-100 flex justify-end gap-3 shrink-0">
        <button type="button" @click="closeModal" class="px-6 py-3 rounded-xl border-2 border-slate-100 font-black text-slate-400 hover:bg-white transition-all text-xs uppercase tracking-widest">
          إلغاء
        </button>
        <button 
        <LoadingButton
          text="تأكيد عملية النقل"
          :isLoading="isLoading"
          loadingText="جاري التأكيد..."
          variant="primary"
          loaderType="dots"
          @click="handleSubmit"
          class="px-10 py-3"
        />
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue';
import apiClient from '../config/axios';
import { useToast } from '@/composables/useToast';
import LoadingButton from '@/components/ui/LoadingButton.vue'
import { useBranchStore } from '@/stores/branch';

// --- Props & Emits (STRICTLY PRESERVED) ---
const props = defineProps({
  product: { type: Object, required: true },
  sourcebranchId: { type: [String, Number], required: true }
});

const emit = defineEmits(['close', 'success']);
const { showToast } = useToast();
const branchStore = useBranchStore();

// --- State (STRICTLY PRESERVED) ---
const allbranchs = computed(() => branchStore.branches);
const destinationbranchId = ref(null);
const quantity = ref(1);
const isLoading = ref(false);
const serverErrors = ref({});

// --- Computed Logic (STRICTLY PRESERVED) ---
const destinationbranchs = computed(() => {
  // Filter out the source branch from the list of possible destinations
  return allbranchs.value.filter(w => w.id != props.sourcebranchId);
});

// --- API Logic (STRICTLY PRESERVED) ---
const fetchbranchs = async () => {
  try {
    await branchStore.fetchBranches();
  } catch (error) {
    showToast('فشل في تحميل قائمة الفروع.', 'error');
  }
};

const handleSubmit = async () => {
  // Basic validation (Preserved)
  if (!destinationbranchId.value) {
    showToast('يرجى اختيار الفرع الهدف.', 'warning');
    return;
  }
  if (quantity.value <= 0) {
    showToast('يجب أن تكون الكمية أكبر من صفر.', 'warning');
    return;
  }
  if (quantity.value > props.product.quantity) {
    showToast('الكمية المطلوبة أكبر من الكمية المتاحة في المخزون.', 'warning');
    return;
  }

  isLoading.value = true;
  serverErrors.value = {};

  const payload = {
    product_id: props.product.id,
    from_branch: props.sourcebranchId,
    to_branch: destinationbranchId.value,
    quantity: quantity.value,
    notes: ''
  };

  try {
    const response = await apiClient.post(`/branches/${props.sourcebranchId}/transfer`, payload);
    
    if (response.data.status === 'success') {
      showToast('تم إنشاء طلب النقل بنجاح!', 'success');
      emit('success');
    } else {
      throw new Error(response.data.message || 'An unknown error occurred.');
    }
  } catch (error) {
    const errorMessage = error.response?.data?.message || 'فشل في عملية النقل.';
    showToast(errorMessage, 'error');
    if (error.response?.data?.errors) {
        serverErrors.value = error.response.data.errors;
    }
  } finally {
    isLoading.value = false;
  }
};

const closeModal = () => {
  emit('close');
};

// --- Lifecycle ---
onMounted(() => {
  fetchbranchs();
});
</script>

<style scoped>

/* Modern UI Components */
.modal-label { @apply block text-[11px] font-black text-slate-400 uppercase tracking-widest mb-1.5 px-1; }
.form-input-modern, .form-select-modern { @apply w-full h-12 bg-white border border-slate-200 rounded-2xl px-4 outline-none transition-all duration-300 focus:border-blue-500 focus:ring-4 focus:ring-blue-50 shadow-sm appearance-none; }

/* Modal & Transitions */
.modal-overlay { @apply fixed inset-0 z-[100] bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4; }
.animate-modalIn { animation: modalIn 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
@keyframes modalIn { from { opacity: 0; transform: scale(0.95) translateY(20px); } to { opacity: 1; transform: scale(1) translateY(0); } }

/* Scrollbar */
.custom-scroll::-webkit-scrollbar { width: 5px; }
.custom-scroll::-webkit-scrollbar-thumb { @apply bg-slate-200 rounded-full; }

.animate-fadeIn { animation: fadeIn 0.4s ease-out; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
</style>