<template>
  <div v-if="modelValue" class="fixed inset-0 z-[120] flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm" @click.self="closeModal">
    <div class="bg-white rounded-[2.5rem] shadow-2xl w-full max-w-lg overflow-hidden animate-modalIn border border-white flex flex-col" @click.stop>
      
      <!-- Modal Header -->
      <div class="px-8 py-6 border-b border-slate-50 bg-slate-50/50 flex items-center justify-between shrink-0">
        <div class="flex items-center gap-4">
          <div class="w-12 h-12 bg-blue-600 rounded-2xl flex items-center justify-center shadow-lg shadow-blue-200 text-white">
            <i :class="[isEditing ? 'fas fa-pen-to-square' : 'fas fa-plus-circle', 'text-xl']"></i>
          </div>
          <div>
            <h3 class="text-xl font-black text-slate-800 leading-none">{{ isEditing ? 'تعديل بيانات المخزون' : 'إضافة صنف للمستودع' }}</h3>
            <p class="text-[10px] text-slate-400 mt-2 font-bold uppercase tracking-widest">تحديث أرصدة المنتجات يدوياً</p>
          </div>
        </div>
        <button @click="closeModal" class="w-10 h-10 rounded-xl flex items-center justify-center text-slate-400 hover:bg-rose-50 hover:text-rose-500 transition-all">
          <i class="fas fa-times text-xl"></i>
        </button>
      </div>

      <!-- Modal Body -->
      <form @submit.prevent="handleSubmit" class="p-8 space-y-6 text-right" dir="rtl">
        
        <!-- Product Selection (For Add Mode) -->
        <div v-if="!isEditing" class="space-y-2 relative group">
          <label class="form-label-modern">البحث عن منتج <span class="text-rose-500">*</span></label>
          <div class="relative">
            <input 
              type="text" 
              v-model="productSearch" 
              @input="searchProducts"
              @focus="showProductDropdown = true"
              class="form-input-modern pr-11 h-12 font-bold"
              placeholder="ابحث بالاسم، الكود أو الباركود..."
              autocomplete="off"
              required
            >
            <i class="fas fa-search absolute right-4 top-1/2 -translate-y-1/2 text-slate-300 group-focus-within:text-blue-500 transition-colors"></i>
          </div>

          <!-- Search Dropdown Results -->
          <transition name="fade">
            <div v-if="showProductDropdown && productSearch" class="absolute z-50 mt-2 w-full bg-white border border-slate-100 rounded-2xl shadow-2xl max-h-60 overflow-auto py-2 custom-scroll">
              <div 
                v-for="product in filteredProducts" 
                :key="product.id"
                @click="selectProduct(product)"
                class="px-6 py-3 cursor-pointer hover:bg-blue-50 transition-colors flex items-center justify-between border-b border-slate-50 last:border-0"
              >
                <div class="flex flex-col">
                  <span class="font-black text-slate-800 text-sm leading-none">{{ product.name }}</span>
                  <span class="text-[10px] text-slate-400 mt-1.5 uppercase font-mono tracking-tighter">كود: {{ product.code }}</span>
                </div>
                <i class="fas fa-chevron-left text-[10px] text-slate-300"></i>
              </div>
              <div v-if="filteredProducts.length === 0" class="px-6 py-4 text-center">
                 <p class="text-xs font-bold text-slate-400 uppercase italic">لا توجد نتائج مطابقة</p>
              </div>
            </div>
          </transition>
        </div>

        <!-- Product Info Display (For Edit Mode) -->
        <div v-else class="bg-slate-900 p-6 rounded-[2rem] text-white shadow-xl relative overflow-hidden group">
          <div class="absolute top-0 right-0 w-24 h-24 bg-white/5 rounded-full translate-x-8 -translate-y-8 group-hover:scale-110 transition-transform"></div>
          <div class="relative z-10 flex items-center gap-4">
            <div class="w-10 h-10 bg-white/10 rounded-xl flex items-center justify-center text-blue-400 shadow-inner"><i class="fas fa-box"></i></div>
            <div>
              <p class="text-[9px] font-black text-blue-400 uppercase tracking-widest mb-1">المنتج المختار</p>
              <h4 class="text-base font-black leading-tight">{{ form.name || form.product_name }}</h4>
              <div class="flex gap-3 mt-2 text-[10px] font-bold text-white/40 font-mono uppercase tracking-tighter">
                <span v-if="form.barcode">BAR: {{ form.barcode }}</span>
                <span>CODE: {{ form.product_code }}</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Quantity Field -->
        <div class="space-y-2">
          <label for="quantity" class="form-label-modern">الكمية الحالية بالمخزون <span class="text-rose-500">*</span></label>
          <div class="relative group">
            <input 
              type="number" 
              id="quantity" 
              v-model.number="form.quantity" 
              class="w-full h-14 rounded-2xl border-slate-100 bg-slate-50 text-3xl font-black text-center text-slate-800 focus:bg-white focus:ring-4 focus:ring-blue-50 transition-all outline-none" 
              min="0"
              step="0.01"
              required
            >
            <i class="fas fa-arrow-up-9-1 absolute right-4 top-1/2 -translate-y-1/2 text-slate-300 group-focus-within:text-blue-500"></i>
          </div>
          <p v-if="errors.quantity" class="text-[10px] text-rose-500 font-bold px-1 animate-fadeIn">{{ errors.quantity }}</p>
        </div>

        <!-- Notes Field -->
        <div class="space-y-2 pt-2">
          <label for="notes" class="form-label-modern">ملاحظات إضافية (اختياري)</label>
          <textarea 
            id="notes" 
            v-model="form.notes" 
            rows="2" 
            class="w-full rounded-2xl border border-slate-200 p-4 text-xs font-bold bg-slate-50 focus:bg-white transition-all outline-none"
            placeholder="اكتب أي ملاحظات تتعلق بهذا المخزون..."
          ></textarea>
        </div>
      </form>

      <!-- Modal Footer -->
      <div class="px-8 py-6 bg-slate-50 border-t border-slate-100 flex justify-end gap-3 shrink-0">
        <button type="button" @click="closeModal" class="px-6 py-3 rounded-xl border-2 border-slate-100 font-black text-slate-400 hover:bg-white transition-all text-xs">
          إلغاء
        </button>
        <LoadingButton
          :text="isEditing ? 'حفظ التغييرات' : 'إضافة للمستودع'"
          :isLoading="isSubmitting"
          loadingText="جاري الحفظ..."
          variant="primary"
          class="px-10 py-3 rounded-xl text-xs"
          @click="handleSubmit"
        />
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch, onUnmounted } from 'vue';
import { useToast } from '@/composables/useToast';
import { searchProducts as apiSearchProducts } from '@/services/branchInventoryService';
import LoadingButton from '@/components/ui/LoadingButton.vue'

// --- Props & Emits ---
const props = defineProps({
  modelValue: { type: Boolean, default: false },
  branchId: { type: [Number, String], required: true },
  item: { type: Object, default: null },
  mode: { type: String, default: 'add', validator: (v) => ['add', 'edit'].includes(v) }
});

const emit = defineEmits(['update:modelValue', 'close', 'saved', 'search-products']);

// --- Logic ---
const closeModal = () => {
  emit('update:modelValue', false);
  emit('close');
};

const isEditing = computed(() => props.mode === 'edit' && props.item);
const { showToast } = useToast();

// State (Preserved)
const form = ref({ id: null, product_id: null, name: '', sku: '', quantity: 0, notes: '' });
const isSubmitting = ref(false);
const errors = ref({});
const productSearch = ref('');
const showProductDropdown = ref(false);
const filteredProducts = ref([]);

// Logic: Search Products (Preserved)
const searchProducts = async () => {
  if (productSearch.value.length < 2) { filteredProducts.value = []; return; }
  try {
    const products = await apiSearchProducts(productSearch.value);
    filteredProducts.value = products;
  } catch (error) {
    showToast('حدث خطأ أثناء البحث', 'error');
    filteredProducts.value = [];
  }
};

const selectProduct = (product) => {
  form.value.product_id = product.id;
  form.value.product_name = product.name;
  form.value.product_code = product.code;
  form.value.barcode = product.barcode || '';
  productSearch.value = product.name;
  showProductDropdown.value = false;
};

// Logic: Validation (Preserved)
const validate = () => {
  errors.value = {};
  let isValid = true;
  if (!form.value.product_id) { errors.value.product = 'يجب اختيار منتج'; isValid = false; }
  if (form.value.quantity === null || form.value.quantity === '') { errors.value.quantity = 'الكمية مطلوبة'; isValid = false; }
  else if (form.value.quantity < 0) { errors.value.quantity = 'يجب أن تكون 0 أو أكثر'; isValid = false; }
  return isValid;
};

const handleSubmit = async () => {
  if (!validate()) return;
  isSubmitting.value = true;
  try {
    const stockData = {
      product_id: form.value.product_id,
      quantity: Number(form.value.quantity),
      notes: form.value.notes || null
    };
    emit('saved', stockData);
  } catch (error) {
    showToast('حدث خطأ أثناء الحفظ', 'error');
  } finally {
    isSubmitting.value = false;
  }
};

// Logic: Watchers & Initializers (Preserved)
watch(() => props.item, (newItem) => {
  if (newItem) {
    form.value = { ...newItem, name: newItem.name || newItem.product_name, product_name: newItem.product_name || newItem.name, notes: newItem.notes || '' };
  } else {
    form.value = { id: null, product_id: null, name: '', sku: '', quantity: 0, notes: '' };
    productSearch.value = '';
  }
}, { immediate: true });

// UI: Click Outside Logic (Preserved)
const handleClickOutside = (event) => {
  const dropdown = document.querySelector('.relative');
  if (dropdown && !dropdown.contains(event.target)) showProductDropdown.value = false;
};

onMounted(() => document.addEventListener('click', handleClickOutside));
onUnmounted(() => document.removeEventListener('click', handleClickOutside));
</script>

<style scoped>

/* Modern UI Components */
.form-label-modern { @apply block text-[11px] font-black text-slate-400 uppercase tracking-widest mb-1.5 px-1; }
.form-input-modern { @apply w-full bg-white border border-slate-200 rounded-2xl px-4 outline-none transition-all duration-300 focus:border-blue-500 focus:ring-4 focus:ring-blue-50 shadow-sm text-sm; }

/* Modal removed - z-index now inline */

/* Scrollbar */
.custom-scroll::-webkit-scrollbar { width: 5px; }
.custom-scroll::-webkit-scrollbar-thumb { @apply bg-slate-200 rounded-full; }

.animate-fadeIn { animation: fadeIn 0.4s ease-out; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
</style>