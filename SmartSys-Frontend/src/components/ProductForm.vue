<template>
  <form @submit.prevent="handleSubmit" class="space-y-10 text-right" dir="rtl">
      
      <!-- ⚠️ تنبيه: لا يوجد فرع محدد -->
      <div v-if="!branchId" class="p-4 bg-blue-50 border border-blue-200 rounded-lg flex items-start gap-3">
        <i class="fas fa-info-circle text-blue-600 mt-0.5 text-sm flex-shrink-0"></i>
        <p class="text-sm text-blue-700 font-medium">اختر فرعًا أولاً لعرض التصنيفات وإدارتها</p>
      </div>

      <!-- Section 1: Basic Information -->
      <section class="space-y-6">
        <header class="flex items-center gap-3 px-1">
          <span class="w-1 h-4 bg-blue-600 rounded-full"></span>
          <h4 class="text-[10px] font-bold text-slate-400 uppercase tracking-[0.2em]">1. البيانات التعريفية</h4>
        </header>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 p-6 bg-slate-50/50 rounded-xl border border-slate-200">
          <div class="space-y-1.5 group">
            <label class="metadata-label">اسم المنتج الرسمي <span class="text-rose-500">*</span></label>
            <input type="text" v-model="form.name" class="form-input-v3 font-bold" :class="{'border-rose-400': errors.name}" placeholder="ادخل اسم المنتج..." />
            <p v-if="errors.name" class="text-[10px] text-rose-500 font-bold px-1">{{ errors.name }}</p>
          </div>

          <div v-if="isEditMode" class="space-y-1.5 group">
            <label class="metadata-label">كود التتبع الداخلي (SKU)</label>
            <div class="relative">
              <input type="text" v-model="form.product_code" class="form-input-v3 font-mono text-indigo-600" style="padding-right: 2rem;" placeholder="PRD-000" />
              <i class="fas fa-hashtag absolute right-3.5 top-1/2 -translate-y-1/2 text-slate-300 text-[10px] pointer-events-none"></i>
            </div>
          </div>

          <div class="space-y-1.5 group">
            <label class="metadata-label">الباركود (Barcode)</label>
            <div class="relative">
              <input type="text" v-model="form.barcode" class="form-input-v3 font-mono" style="padding-right: 2rem;" placeholder="0000000000" />
              <i class="fas fa-barcode absolute right-3.5 top-1/2 -translate-y-1/2 text-slate-300 text-[10px] pointer-events-none"></i>
            </div>
          </div>

          <div class="space-y-1.5">
            <label class="metadata-label">الوحدة الأساسية <span class="text-rose-500">*</span></label>
            <select v-model="form.unit_id" class="form-input-v3 font-bold appearance-none" :class="{'border-rose-400': errors.unit_id}">
              <option :value="null">-- اختر الوحدة --</option>
              <option v-for="unit in units" :key="unit.id" :value="unit.id">{{ unit.name }}</option>
            </select>
          </div>
        </div>

        <div class="p-6 bg-white border border-slate-200 rounded-xl space-y-4 shadow-sm">
          <label class="metadata-label">تصنيف الصنف</label>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <select v-model="form.category_id" class="form-input-v3 font-bold appearance-none bg-slate-50 border-transparent" :disabled="!branchId">
              <option :value="null">بدون تصنيف (عام)</option>
              <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
            </select>
            <div class="flex gap-2">
              <input type="text" v-model="newCategoryName" placeholder="إضافة تصنيف جديد..." class="form-input-v3 flex-grow text-[11px]" :disabled="!branchId" />
              <LoadingButton
                text="إضافة"
                :isLoading="isAddingCategory"
                loadingText="إضافة..."
                :disabled="!branchId"
                variant="primary"
                loaderType="dots"
                @click="addNewCategory"
                class="h-9 px-4"
              />
            </div>
          </div>
        </div>
      </section>

      <!-- Section 2: Pricing & Finance -->
      <section class="space-y-6">
        <header class="flex items-center gap-3 px-1">
          <span class="w-1 h-4 bg-emerald-500 rounded-full"></span>
          <h4 class="text-[10px] font-bold text-slate-400 uppercase tracking-[0.2em]">2. سياسة التسعير والضرائب ({{ currencySymbol }})</h4>
        </header>
        
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
          <div v-for="field in pricingFields" :key="field.id" class="space-y-1.5">
            <label class="metadata-label">{{ field.label }} <span v-if="['purchase_price', 'sale_price'].includes(field.id)" class="text-rose-500">*</span></label>
            <div class="relative">
              <input type="number" :min="0" :max="field.id === 'fixed_discount_percentage' ? 100 : undefined" :step="field.step" v-model.number="form[field.model]" class="h-10 w-full bg-white border border-slate-200 rounded-md px-3 font-mono font-bold text-sm focus:ring-4 focus:ring-blue-500/5 focus:border-blue-500" :class="field.color" />
              <span class="absolute left-3 top-1/2 -translate-y-1/2 text-[9px] font-black text-slate-300 uppercase">{{ field.suffix }}</span>
            </div>
            <p v-if="errors[field.err]" class="text-[9px] text-rose-500 font-bold px-1">{{ errors[field.err] }}</p>
          </div>
        </div>
      </section>

      <!-- Section 3: Inventory & Tracking Architecture -->
      <section class="space-y-6">
        <header class="flex items-center gap-3 px-1">
          <span class="w-1 h-4 bg-indigo-500 rounded-full"></span>
          <h4 class="text-[10px] font-bold text-slate-400 uppercase tracking-[0.2em]">3. إدارة المخزون والتتبع</h4>
        </header>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
          <div class="space-y-2">
            <label class="metadata-label">الكمية الإجمالية الحالية</label>
            <div class="relative group">
              <input type="number" :value="form.quantity || 0" readonly class="h-10 w-full bg-slate-100 border border-slate-200 rounded-md px-4 font-mono font-bold text-slate-500 cursor-not-allowed" />
              <i class="fas fa-lock absolute left-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px]"></i>
            </div>
            <p class="text-[10px] text-slate-400 italic px-1">كمية المخزون تُحدَّث عبر فواتير الشراء والتسويات.</p>
          </div>

          <div class="space-y-1.5 group">
            <label class="metadata-label">حد إعادة الطلب (Min Stock)</label>
            <div class="relative">
              <input type="number" v-model="form.min_quantity" class="form-input-v3 font-bold" style="padding-right: 2rem;" placeholder="مثال: 5" />
              <i class="fas fa-bell absolute right-3.5 top-1/2 -translate-y-1/2 text-slate-300 group-focus-within:text-indigo-500 transition-colors text-[10px] pointer-events-none"></i>
            </div>
          </div>
        </div>

        <!-- Product Type & Tracking Strategy -->
        <div class="space-y-4">
          <h5 class="text-[9px] font-bold text-slate-400 uppercase tracking-widest px-1">استراتيجية التتبع</h5>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <label v-for="type in [
              { id: 'stock', name: 'منتج مخزني', sub: 'يتطلب جرد ومراقبة كميات', icon: 'fa-boxes-stacked', color: 'text-emerald-500' },
              { id: 'service', name: 'خدمة / صنف رقمي', sub: 'بدون مخزون فيزيائي', icon: 'fa-concierge-bell', color: 'text-blue-500' }
            ]" :key="type.id" class="cursor-pointer group">
              <input type="radio" v-model="form.product_type" :value="type.id" class="hidden" />
              <div class="p-4 rounded-xl border border-slate-200 bg-white transition-all group-hover:border-blue-300" :class="{'border-blue-600 ring-4 ring-blue-500/5 bg-blue-50/30': form.product_type === type.id}">
                <div class="flex items-center gap-4">
                  <div :class="[type.color]" class="w-10 h-10 rounded-lg bg-white flex items-center justify-center shadow-sm border border-slate-100"><i :class="['fas', type.icon]"></i></div>
                  <div>
                    <p class="text-xs font-bold text-slate-900 leading-none">{{ type.name }}</p>
                    <p class="text-[10px] text-slate-400 mt-1 font-medium">{{ type.sub }}</p>
                  </div>
                </div>
              </div>
            </label>
          </div>
        </div>

        <!-- Batch/Serial/Expiry Toggles (Only for stock products) -->
        <div v-if="form.product_type === 'stock'" class="grid grid-cols-1 md:grid-cols-3 gap-3">
          <label v-for="opt in [
            { model: 'has_expiry_date', label: 'تتبع الصلاحية', icon: 'fa-calendar-alt' },
            { model: 'has_batch_number', label: 'رقم الدفعة', icon: 'fa-layer-group' },
            { model: 'has_serial_number', label: 'رقم تسلسلي', icon: 'fa-barcode' }
          ]" :key="opt.model" class="cursor-pointer group">
            <input type="checkbox" v-model="form[opt.model]" class="sr-only peer" />
            <div class="px-4 py-3 rounded-lg border border-slate-200 bg-slate-50/50 flex items-center gap-3 transition-all peer-checked:bg-blue-600 peer-checked:text-white peer-checked:border-blue-600 peer-checked:shadow-lg peer-checked:shadow-blue-900/10 group-hover:bg-white">
              <i :class="['fas', opt.icon, 'text-xs opacity-50 peer-checked:opacity-100']"></i>
              <span class="text-[10px] font-bold uppercase tracking-tight">{{ opt.label }}</span>
            </div>
          </label>
        </div>

        <!-- Conditional Input Grid -->
        <transition name="slide-down">
          <div v-if="form.has_expiry_date || form.has_batch_number || form.has_serial_number" class="grid grid-cols-1 md:grid-cols-3 gap-4 p-5 bg-slate-50 border border-slate-100 rounded-xl">
             <div v-if="form.has_expiry_date" class="space-y-1.5 animate-fadeIn group">
               <label class="text-[10px] font-bold text-blue-600 uppercase">صلاحية افتراضية</label>
               <div class="relative">
                 <input ref="expiryDateRef" type="date" v-model="form.expiry_date" class="form-input-v3 h-9 font-mono w-full" style="padding-left: 2rem;" />
                 <i @click="expiryDateRef?.showPicker?.()" class="fas fa-calendar absolute left-3 top-1/2 -translate-y-1/2 text-slate-300 group-focus-within:text-blue-500 cursor-pointer hover:text-blue-500 transition-colors text-[10px]"></i>
               </div>
             </div>
             <div v-if="form.has_batch_number" class="space-y-1.5 animate-fadeIn">
               <label class="text-[10px] font-bold text-blue-600 uppercase">الدفعة (Batch)</label>
               <input type="text" v-model="form.batch_number" class="form-input-v3 h-9 font-mono" placeholder="B-000" />
             </div>
             <div v-if="form.has_serial_number" class="space-y-1.5 animate-fadeIn">
               <label class="text-[10px] font-bold text-blue-600 uppercase">السيريال (SN)</label>
               <input type="text" v-model="form.serial_number" class="form-input-v3 h-9 font-mono" placeholder="SN-000" />
             </div>
          </div>
        </transition>
      </section>

      <!-- Section 4: Distribution & Branch Management -->
      <section v-if="!isEditMode && branches.length > 0 && form.product_type === 'stock'" class="space-y-6">
        <header class="flex items-center gap-3 px-1">
          <span class="w-1 h-4 bg-amber-500 rounded-full"></span>
          <h4 class="text-[10px] font-bold text-slate-400 uppercase tracking-[0.2em]">4. تحديد النطاق وإدخال الرصيد الافتتاحي</h4>
        </header>

        <p class="rounded-lg border border-blue-100 bg-blue-50 px-4 py-3 text-[11px] leading-relaxed text-blue-800">
          سيربط النظام حسابات المنتج بالفروع المختارة تلقائيًا إذا كانت حسابات المخزون والمشتريات وتكلفة البضاعة مهيأة. إذا تعذر الربط، سيظهر تنبيه باسم الفرع ويظل تسجيل حركاته متوقفًا حتى استكمال الإعداد المحاسبي.
        </p>

        <div class="grid grid-cols-1 gap-3">
          <label v-for="assign in [
            { v: 'current', l: 'هذا الفرع فقط', i: 'fa-store', c: 'text-blue-500', show: props.branchId },
            { v: 'all', l: 'تعميم على كافة الفروع', i: 'fa-globe', c: 'text-emerald-500', show: true },
            { v: 'selected', l: 'اختيار فروع محددة', i: 'fa-check-double', c: 'text-amber-500', show: true }
          ]" v-show="assign.show" :key="assign.v" class="cursor-pointer group">
            <input type="radio" v-model="branchAssignmentType" :value="assign.v" class="hidden" />
            <div class="px-5 py-3 rounded-xl border border-slate-200 bg-white transition-all group-hover:border-blue-200" :class="{'border-blue-600 bg-blue-50 shadow-sm': branchAssignmentType === assign.v}">
              <div class="flex items-center justify-between">
                <div class="flex items-center gap-4">
                  <i :class="['fas', assign.i, assign.c, 'text-xs']"></i>
                  <span class="text-xs font-bold text-slate-800">{{ assign.l }}</span>
                </div>
                <div v-if="branchAssignmentType === assign.v" class="w-4 h-4 rounded-full bg-blue-600 flex items-center justify-center text-white text-[8px]"><i class="fas fa-check"></i></div>
              </div>
            </div>
          </label>
        </div>

        <!-- Dynamic Branch Multi-Select -->
        <transition name="slide-down">
          <div v-if="branchAssignmentType === 'selected'" class="p-6 bg-slate-50 border border-slate-200 rounded-xl space-y-4 animate-fadeIn">
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest px-1">القائمة المستهدفة:</p>
            <div class="grid grid-cols-2 md:grid-cols-3 gap-3 max-h-48 overflow-y-auto custom-scroll">
              <label v-for="b in branches" :key="b.id" class="flex items-center gap-3 p-2 hover:bg-white rounded-lg transition-colors cursor-pointer border border-transparent hover:border-slate-100">
                <input type="checkbox" v-model="selectedBranches" :value="b.id" class="w-4 h-4 rounded border-slate-300 text-blue-600 focus:ring-0" />
                <span class="text-[11px] font-bold text-slate-700">{{ b.name }}</span>
              </label>
            </div>
          </div>
        </transition>

        <!-- Initial Balance: Strategic Action Card -->
        <div v-if="form.product_type === 'stock'" class="pt-4">
          <div class="p-6 rounded-xl border-2 border-dashed border-slate-200 hover:border-amber-400 transition-all bg-white group/ob">
            <label class="flex items-start gap-4 cursor-pointer">
              <input type="checkbox" v-model="enableOpeningBalance" class="w-5 h-5 mt-1 rounded border-slate-300 text-amber-500 focus:ring-0" />
              <div class="flex-grow">
                <span class="text-sm font-bold text-slate-900 block">إدخال الرصيد الافتتاحي الآن</span>
                <p class="text-[10px] text-slate-500 mt-1 leading-relaxed">فعّل هذا الخيار إذا كنت ترغب في إدخال الكمية الافتتاحية وتكلفة الوحدة. سيُنشئ النظام قيدًا محاسبيًا تلقائيًا.</p>
              </div>
            </label>

            <transition name="slide-down">
              <div v-if="enableOpeningBalance" class="mt-6 pt-6 border-t border-slate-100 animate-fadeIn space-y-6">
                <div class="grid grid-cols-2 gap-6">
                  <div class="space-y-1.5"><label class="metadata-label text-amber-600">الكمية الافتتاحية</label><input type="number" v-model.number="initialQuantity" class="form-input-v3 h-10 font-mono font-bold" placeholder="0" /></div>
                  <div class="space-y-1.5"><label class="metadata-label text-amber-600">تكلفة الوحدة (Cost)</label><input type="number" v-model.number="initialUnitCost" class="form-input-v3 h-10 font-mono font-bold" placeholder="0.00" /></div>
                </div>
                <div v-if="initialQuantity > 0 && initialUnitCost > 0" class="p-4 bg-slate-900 rounded-xl text-white flex justify-between items-center shadow-lg">
                   <span class="text-[10px] font-bold text-blue-400 uppercase tracking-widest">إجمالي قيمة الرصيد الافتتاحي</span>
                   <span class="text-xl font-bold font-mono tracking-tighter">{{ formatPriceEn(initialQuantity * initialUnitCost) }} {{ currencySymbol }}</span>
                </div>
              </div>
            </transition>
          </div>
        </div>
      </section>

      <!-- Description: Final Meta -->
      <section class="space-y-2">
        <label class="metadata-label uppercase tracking-widest">ملاحظات المنتج (اختياري)</label>
        <textarea v-model="form.description" class="w-full rounded-xl border border-slate-200 p-4 text-xs font-medium bg-slate-50 focus:bg-white transition-all outline-none focus:border-blue-500" rows="3" placeholder="تفاصيل إضافية للمرجعية..."></textarea>
      </section>

    </form>
</template>

<script setup>
import { ref, watch, computed, onMounted } from 'vue';
import { useAuthStore } from '@/stores/auth/authStore';
import productsService from '@/services/products';
import catalogService from '@/services/catalog';
import { useToast } from '@/composables/useToast';
import { useCompanyCurrency } from '@/composables/useCompanyCurrency';
import LoadingButton from '@/components/ui/LoadingButton.vue';

const props = defineProps({
  productData: { type: Object, default: null },
  categories: { type: Array, required: true },
  branchId: { type: Number, default: null },
  branches: { type: Array, default: () => [] }
});

const emit = defineEmits(['close', 'success', 'category-added']);
const { showToast } = useToast();
const { currencySymbol, fetchSettings } = useCompanyCurrency();
const authStore = useAuthStore();
const canViewPurchasePrice = computed(() =>
  authStore.isSuperAdmin || authStore.user?.permissions?.includes('product.purchase_price.view') === true
);
const pricingFields = computed(() => [
  { id: 'purchase_price', label: 'سعر الشراء', model: 'purchase_price', color: 'text-slate-700', err: 'purchase_price', step: '0.01', suffix: currencySymbol.value },
  { id: 'sale_price', label: 'سعر البيع الافتراضي', model: 'sale_price', color: 'text-blue-600', err: 'sale_price', step: '0.01', suffix: currencySymbol.value },
  { id: 'min_sale_price', label: 'أقل سعر مسموح', model: 'min_sale_price', color: 'text-amber-600', err: 'min_sale_price', step: '0.01', suffix: currencySymbol.value },
  { id: 'fixed_discount_percentage', label: 'أقصى خصم مسموح (%)', model: 'fixed_discount_percentage', color: 'text-rose-600', err: 'fixed_discount_percentage', step: '0.01', suffix: '%' },
].filter((field) => field.id !== 'purchase_price' || canViewPurchasePrice.value));

const form = ref({});
const isSaving = ref(false);
const expiryDateRef = ref(null);
const errors = ref({});
const units = ref([]);
const newCategoryName = ref('');
const isAddingCategory = ref(false);
const enableOpeningBalance = ref(false);
const branchAssignmentType = ref(props.branchId ? 'current' : 'selected');
const selectedBranches = ref([]);
const initialQuantity = ref(0);
const initialUnitCost = ref(0);

const formatPriceEn = (v) => new Intl.NumberFormat('en-US', { minimumFractionDigits: 2 }).format(v);

watch(() => props.productData, (newData) => {
  form.value = newData
    ? { 
        id: newData.id || null,
        name: newData.name || '',
        product_code: newData.product_code || '',
        barcode: newData.barcode || '',
        description: newData.description || '',
        category_id: newData.category_id || null,
        category_name: newData.category_name || '',
        purchase_price: newData.purchase_price || 0,
        sale_price: newData.sale_price || 0,
        min_sale_price: newData.min_sale_price || 0,
        fixed_discount_percentage: newData.fixed_discount_percentage || 0,
        quantity: newData.current_quantity || newData.quantity || 0,
        current_quantity: newData.current_quantity || 0,
        unit_id: newData.unit_id || null,
        unit_name: newData.unit_name || '',
        min_quantity: newData.min_quantity || 0,
        max_quantity: newData.max_quantity || 0,
        product_type: newData.product_type || 'stock',
        active: newData.active ?? 1,
        has_expiry_date: !!(newData.has_expiry_date ?? newData.configuration?.has_expiry_date),
        has_batch_number: !!(newData.has_batch_number ?? newData.configuration?.has_batch_number),
        has_serial_number: !!(newData.has_serial_number ?? newData.configuration?.has_serial_number),
        expiry_date: newData.expiry_date || '',
        batch_number: newData.batch_number || '',
        serial_number: newData.serial_number || '',
      }
    : { 
        id: null, name: '', product_code: '', barcode: '', description: '', category_id: null, category_name: '',
        quantity: '', current_quantity: 0, purchase_price: '', sale_price: '', min_sale_price: '', min_quantity: '',
        has_expiry_date: false, expiry_date: '', has_batch_number: false, batch_number: '',
        has_serial_number: false, serial_number: '', unit_id: null, unit_name: '', product_type: 'stock',
        active: 1, fixed_discount_percentage: 0, max_quantity: 0,
      };
  errors.value = {};
}, { immediate: true });

const isEditMode = computed(() => !!props.productData);

const fetchUnits = async () => {
  try {
    const response = await catalogService.getUnits();
    units.value = response.data.data;
  } catch (error) {
    showToast('فشل في جلب الوحدات', 'error');
  }
};

onMounted(() => {
  fetchUnits();
  fetchSettings();
});

const addNewCategory = async () => {
  if (!newCategoryName.value.trim()) { showToast('يرجى إدخال اسم التصنيف.', 'error'); return; }
  const targetBranchId = props.branchId ?? props.branches?.[0]?.id ?? null;
  if (!targetBranchId) { showToast('الرجاء اختيار فرع أولاً قبل إضافة تصنيف.', 'warning'); return; }
  isAddingCategory.value = true;
  try {
    await catalogService.createCategory({ name: newCategoryName.value, branch_id: targetBranchId });
    showToast(`تم إضافة التصنيف بنجاح!`, 'success');
    newCategoryName.value = '';
    emit('category-added');
  } catch (error) {
    const errMsg = error.response?.data?.message || 'فشل في إضافة التصنيف';
    showToast(errMsg, 'error');
  } finally { isAddingCategory.value = false; }
};

const validateForm = () => {
  errors.value = {};
  if (!form.value.name) errors.value.name = 'اسم المنتج مطلوب.';
  if (!form.value.sale_price || isNaN(form.value.sale_price)) errors.value.sale_price = 'سعر البيع مطلوب.';
  if (canViewPurchasePrice.value && (!form.value.purchase_price || isNaN(form.value.purchase_price))) errors.value.purchase_price = 'سعر الشراء مطلوب.';
  if (!form.value.unit_id) errors.value.unit_id = 'الوحدة مطلوبة.';
  return Object.keys(errors.value).length === 0;
};

const handleSubmit = async () => {
  if (!validateForm()) return;
  const isStockProduct = form.value.product_type === 'stock';
  if (!isEditMode.value && branchAssignmentType.value === 'selected' && selectedBranches.value.length === 0) {
    showToast('يرجى اختيار فرع واحد على الأقل', 'error'); return;
  }
  if (!isEditMode.value && isStockProduct && enableOpeningBalance.value) {
    if (!initialQuantity.value || initialQuantity.value <= 0) { showToast('يرجى إدخال الكمية الافتتاحية', 'error'); return; }
    if (!initialUnitCost.value || initialUnitCost.value <= 0) { showToast('يرجى إدخال سعر التكلفة', 'error'); return; }
  }
  isSaving.value = true;
  try {
    const payload = { ...form.value };
    if (!canViewPurchasePrice.value) delete payload.purchase_price;
    if (payload.expiry_date) { payload.default_expiry_date = payload.expiry_date; delete payload.expiry_date; }
    if (payload.batch_number) { payload.default_batch_number = payload.batch_number; delete payload.batch_number; }
    if (payload.serial_number) { payload.default_serial_number = payload.serial_number; delete payload.serial_number; }

    if (!isEditMode.value) {
      let targetBranchIds = [];
      if (isStockProduct) {
        if (enableOpeningBalance.value) {
          if (branchAssignmentType.value === 'current') targetBranchIds = props.branchId ? [props.branchId] : props.branches.map(b => b.id);
          else if (branchAssignmentType.value === 'all') targetBranchIds = props.branches.map(b => b.id);
          else if (branchAssignmentType.value === 'selected') targetBranchIds = selectedBranches.value;
        } else {
          if (branchAssignmentType.value === 'all' || !props.branchId) targetBranchIds = props.branches.map(b => b.id);
          else if (branchAssignmentType.value === 'selected') targetBranchIds = selectedBranches.value.length > 0 ? selectedBranches.value : [props.branchId];
          else targetBranchIds = props.branchId ? [props.branchId] : props.branches.map(b => b.id);
        }
      }

      payload.branch_assignments = {
        branch_ids: targetBranchIds,
        initial_quantity: isStockProduct && enableOpeningBalance.value ? initialQuantity.value : 0,
        initial_unit_cost: isStockProduct && enableOpeningBalance.value ? initialUnitCost.value : 0
      };
    }

    if (isEditMode.value) {
      await productsService.update(props.productData.id, payload);
      showToast('تم التحديث بنجاح!', 'success');
    } else {
      const response = await productsService.create(payload);
      const activation = response.data?.data?.branch_activation || [];
      const setupRequired = activation.filter((branch) => branch.status === 'setup_required');
      if (setupRequired.length) {
        const branchNames = setupRequired.map((branch) => branch.branch_name).join('، ');
        showToast(`تم إنشاء المنتج، لكن تعذر ربط حساباته في: ${branchNames}. أكمل التفعيل المحاسبي قبل تسجيل الحركات.`, 'warning');
      } else if (activation.some((branch) => branch.status === 'active')) {
        showToast('تمت إضافة المنتج وربط حساباته بالفروع المختارة بنجاح.', 'success');
      } else {
        showToast('تمت إضافة المنتج بنجاح.', 'success');
      }
    }
    emit('success');
  } catch (error) {
    console.error('[ProductForm] Error saving product:', error);
    if (error.response?.status === 409) {
      errors.value.product_code = 'كود SKU مكرر.';
      showToast('كود مكرر', 'error');
    } else {
      const errorMsg = error.response?.data?.message || 'فشل في الحفظ';
      showToast(errorMsg, 'error');
    }
  } finally { isSaving.value = false; }
};

defineExpose({ handleSubmit, isSaving });
</script>

<style scoped>
.metadata-label { @apply block text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1.5 px-1; }
.form-input-v3 { @apply h-10 w-full bg-white border border-slate-200 rounded-md px-3 text-xs font-medium focus:ring-4 focus:ring-blue-500/5 focus:border-blue-500 outline-none transition-all; }
.custom-scroll::-webkit-scrollbar { width: 5px; }
.custom-scroll::-webkit-scrollbar-thumb { @apply bg-slate-200 rounded-full; }
@keyframes modalIn { from { opacity: 0; transform: scale(0.98) translateY(10px); } to { opacity: 1; transform: scale(1) translateY(0); } }
.animate-modalIn { animation: modalIn 0.2s cubic-bezier(0.16, 1, 0.3, 1); }
.slide-down-enter-active { transition: all 0.2s ease-out; }
.slide-down-enter-from { opacity: 0; transform: translateY(-10px); }
</style>