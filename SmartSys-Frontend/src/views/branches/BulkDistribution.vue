<template>
  <div class="min-h-screen bg-[#fafafa] text-slate-900 font-sans antialiased selection:bg-blue-100" dir="rtl">
    
    <!-- Top Progress Bar -->

    <div class="max-w-[1600px] mx-auto p-6 lg:p-10 space-y-8">
      
      <!-- Page Header -->
      <PageHeader
        :breadcrumb="breadcrumb"
        title="التوزيع الجماعي"
        description="وزّع كميات الأصناف على الفروع دفعة واحدة، أو استورد بيانات التوزيع من ملفات خارجية."
        :branches="[]"
        :selectedBranch="null"
      >
        <template #controls>
          <LoadingButton
            text="تحديث البيانات"
            loadingText="جاري التحديث..."
            icon="fas fa-sync-alt"
            :isLoading="isRefreshing"
            variant="secondary"
            class="h-9 px-4 rounded-md border border-slate-200 bg-white text-slate-600 text-xs shadow-sm hover:bg-slate-50"
            @click="refreshData"
          />
          <router-link to="/branches" class="h-9 px-4 rounded-md bg-white border border-slate-200 text-slate-600 text-xs font-bold hover:bg-slate-50 transition-all flex items-center gap-2 shadow-sm">
            <i class="fas fa-arrow-right text-[10px]"></i> العودة للمستودعات
          </router-link>
        </template>
      </PageHeader>

      <!-- Segmented Tab Navigation: High-Density SaaS Style -->
      <div class="flex items-center justify-center">
        <div class="flex bg-slate-100 p-1 rounded-lg border border-slate-200/50">
          <button v-for="tab in [
              { id: 'form', name: 'نموذج التوزيع', icon: 'sliders-h' },
              { id: 'csv', name: 'استيراد CSV', icon: 'file-csv' },
              { id: 'history', name: 'سجل العمليات', icon: 'history' }
            ]" 
            :key="tab.id"
            @click="activeTab = tab.id"
            :class="[activeTab === tab.id ? 'bg-white text-slate-900 shadow-sm ring-1 ring-slate-200' : 'text-slate-500 hover:text-slate-700']"
            class="px-8 py-2 rounded-md text-xs font-bold transition-all flex items-center gap-2"
          >
            <i :class="['fas fa-' + tab.icon, 'text-[10px]']"></i>
            {{ tab.name }}
          </button>
        </div>
      </div>

      <!-- Tab Content: Form Distribution -->
      <div v-if="activeTab === 'form'" class="space-y-8 animate-fadeIn">
        
        <!-- Product Selection Section -->
        <section class="bg-white border border-slate-200 rounded-xl p-8 shadow-sm relative overflow-hidden">
          <div class="flex items-center justify-between mb-8">
            <div class="space-y-1">
              <h3 class="text-sm font-bold text-slate-900 uppercase tracking-wider">1. اختيار المنتج</h3>
              <p class="text-xs text-slate-400">ابحث عن الصنف المراد توزيع مخزونه حالياً</p>
            </div>
            <div v-if="selectedProduct" class="flex items-center gap-4 bg-blue-50 border border-blue-100 px-4 py-2 rounded-lg">
               <span class="text-[10px] font-bold text-blue-400 uppercase tracking-widest">إجمالي التوزيع</span>
               <span class="text-xl font-bold text-blue-600 font-mono tracking-tighter">{{ totalQuantity }}</span>
            </div>
          </div>

          <div class="relative group">
            <div class="flex gap-3">
              <div class="relative flex-grow">
                <input 
                  type="text" 
                  role="combobox"
                  aria-autocomplete="list"
                  aria-controls="product-dropdown"
                  :aria-expanded="showDropdown.toString()"
                  aria-label="البحث عن منتج"
                  class="w-full h-12 bg-white border border-slate-200 rounded-lg pr-12 pl-4 text-sm font-bold focus:ring-4 focus:ring-blue-500/5 focus:border-blue-500 outline-none transition-all shadow-inner" 
                  v-model="productSearch" 
                  @input="debouncedSearch" 
                  @focus="showDropdown = true"
                  @keydown="handleSearchKeydown"
                  placeholder="اسم المنتج أو الباركود..." 
                />
                <i class="fas fa-search absolute right-5 top-1/2 -translate-y-1/2 text-slate-300 group-focus-within:text-blue-500 transition-colors"></i>
              </div>
              <button v-if="selectedProduct" @click="clearProduct" class="w-12 h-12 bg-slate-50 text-slate-400 rounded-lg flex items-center justify-center hover:bg-rose-50 hover:text-rose-500 transition-all border border-slate-200">
                <i class="fas fa-times"></i>
              </button>
            </div>

            <!-- Search Dropdown: Professional Elevation -->
            <transition name="dropdown">
              <div v-if="showDropdown && (isSearchingProducts || productSearchError || products.length || productSearch.trim())" id="product-dropdown" role="listbox" :aria-busy="isSearchingProducts" class="absolute z-50 mt-2 w-full bg-white border border-slate-200 rounded-lg shadow-2xl max-h-72 overflow-auto py-2 animate-fadeIn">
                <div v-if="isSearchingProducts" role="status" class="px-6 py-4 text-xs font-bold text-slate-500">جارٍ البحث عن المنتجات...</div>
                <div v-else-if="productSearchError" role="alert" class="px-6 py-4 text-xs font-bold text-rose-600">{{ productSearchError }}</div>
                <div v-else-if="!products.length" role="status" class="px-6 py-4 text-xs font-bold text-slate-400">لا توجد منتجات مطابقة.</div>
                <div v-for="(p, index) in products" :key="p.id" :data-dropdown-item="index" role="option" @mousedown.prevent="selectProduct(p)" class="px-6 py-3 cursor-pointer hover:bg-blue-50 transition-colors flex items-center justify-between border-b border-slate-50 last:border-0">
                  <div class="min-w-0 flex-1">
                    <span class="font-bold text-slate-800 text-sm block truncate">{{ p.name }}</span>
                    <span class="text-[10px] text-slate-400 font-mono mt-1 uppercase tracking-tighter">{{ p.barcode || 'NO BARCODE' }}</span>
                  </div>
                  <div class="text-left shrink-0">
                     <span class="text-[10px] font-bold text-slate-500 bg-slate-100 px-2 py-0.5 rounded border border-slate-200">المخزون: {{ p.stock || 0 }}</span>
                     <span v-if="selectedProduct && selectedProduct.id === p.id" class="text-[9px] font-bold text-emerald-600 block mt-1">المتبقي: {{ (selectedProduct.stock || 0) - totalQuantity }}</span>
                  </div>
                </div>
              </div>
            </transition>
          </div>

          <!-- Selected Product Dark Panel: Anchoring Context -->
          <transition name="slide-down">
            <div v-if="selectedProduct" class="mt-8 bg-slate-900 rounded-xl p-6 text-white shadow-xl flex items-center justify-between border border-white/5 relative overflow-hidden">
              <div class="absolute top-0 right-0 w-32 h-32 bg-white/5 rounded-full -translate-x-12 -translate-y-12"></div>
              <div class="flex items-center gap-6 relative z-10">
                <div class="w-12 h-12 bg-white/10 rounded-lg flex items-center justify-center text-blue-400 shadow-inner">
                  <i class="fas fa-box text-lg"></i>
                </div>
                <div>
                  <p class="text-[10px] font-bold text-white/40 uppercase tracking-widest mb-1">المنتج النشط حالياً</p>
                  <h4 class="text-lg font-bold">{{ selectedProduct.name }}</h4>
                </div>
              </div>
              <div class="flex gap-8 relative z-10 border-r border-white/10 pr-8">
                <div class="text-left">
                  <p class="text-[9px] font-bold text-white/30 uppercase tracking-widest mb-1">المتوفر بالمخزن</p>
                  <p class="text-2xl font-bold font-mono text-blue-400">{{ selectedProduct.stock || 0 }}</p>
                </div>
                <div class="text-left">
                  <p class="text-[9px] font-bold text-white/30 uppercase tracking-widest mb-1">الرصيد المتبقي</p>
                  <p :class="[(selectedProduct.stock || 0) - totalQuantity < 0 ? 'text-rose-400' : 'text-slate-400']" class="text-2xl font-bold font-mono">{{ (selectedProduct.stock || 0) - totalQuantity }}</p>
                </div>
              </div>
            </div>
          </transition>
        </section>

        <!-- Distribution Table Card -->
        <section class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
          <div class="p-6 bg-slate-50/50 border-b border-slate-100 flex items-center justify-between">
            <div class="space-y-1">
              <h3 class="text-sm font-bold text-slate-900 uppercase tracking-wider">2. مصفوفة التوزيع</h3>
              <p class="text-[11px] text-slate-400">حدد الحصة المخزنية لكل مستودع/فرع</p>
            </div>
            <div class="flex items-center gap-2">
              <button @click="fillEqualQuantities" class="h-8 px-4 rounded-md bg-white border border-slate-200 text-slate-600 text-[10px] font-bold uppercase hover:bg-slate-50 transition-all">توزيع متساوي</button>
              <button @click="resetQuantities" class="h-8 px-4 rounded-md bg-white border border-slate-200 text-rose-600 text-[10px] font-bold uppercase hover:bg-rose-50 transition-all">تصفير الكل</button>
            </div>
          </div>

          <div class="overflow-x-auto">
            <table class="w-full text-right border-collapse">
              <thead>
                <tr class="bg-white border-b border-slate-100 text-slate-400 font-bold uppercase text-[10px] tracking-widest">
                  <th class="px-8 py-5">اسم الفرع المستلم</th>
                  <th class="px-4 py-5 text-center">الكمية</th>
                  <th class="px-4 py-5 text-center">النسبة</th>
                  <th class="px-6 py-5">الملاحظات / البيان</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-50 font-medium">
                <tr v-for="row in rows" :key="row.id" class="hover:bg-slate-50 transition-all">
                  <td class="px-8 py-4">
                    <div class="flex items-center gap-3">
                      <div class="w-2 h-2 rounded-full bg-slate-200"></div>
                      <span class="text-xs font-bold text-slate-700">{{ row.name }}</span>
                    </div>
                  </td>
                  <td class="px-4 py-4 text-center">
                    <input 
                      type="number" 
                      v-model.number="row.quantity" 
                      class="w-28 h-9 text-center bg-white border border-slate-200 rounded-md font-bold font-mono text-blue-600 focus:ring-4 focus:ring-blue-500/5 focus:border-blue-500 transition-all outline-none" 
                      step="0.01" 
                      min="0"
                    />
                  </td>
                  <td class="px-4 py-4 text-center">
                    <div class="flex items-center justify-center gap-3">
                       <div class="w-16 h-1 bg-slate-100 rounded-full overflow-hidden shrink-0">
                         <div class="h-full bg-blue-600 transition-all duration-500" :style="{ width: getPercentage(row.quantity) + '%' }"></div>
                       </div>
                       <span class="text-[10px] font-bold text-slate-400 font-mono w-8">{{ getPercentage(row.quantity) }}%</span>
                    </div>
                  </td>
                  <td class="px-6 py-4">
                    <input 
                      type="text" 
                      v-model="row.notes" 
                      placeholder="ملاحظات الحركة..." 
                      class="w-full h-9 bg-slate-50 border border-transparent rounded-md px-4 text-xs font-medium outline-none focus:bg-white focus:border-slate-200 transition-all" 
                    />
                  </td>
                </tr>
              </tbody>
              <tfoot class="bg-slate-50/50 border-t border-slate-200 font-bold">
                <tr>
                  <td class="px-8 py-5 text-[10px] text-slate-400 uppercase tracking-widest">إجمالي الحركة الحالية</td>
                  <td class="px-4 py-5 text-center text-xl text-blue-600 font-mono tracking-tighter">{{ totalQuantity }}</td>
                  <td class="px-4 py-5 text-center text-[10px] text-slate-400 font-mono">100%</td>
                  <td class="px-6 py-5"></td>
                </tr>
              </tfoot>
            </table>
          </div>

          <!-- Bottom Action Tray -->
          <div class="p-8 bg-white border-t border-slate-100 flex items-center justify-between gap-6">
             <div class="flex items-center gap-4">
                <div class="w-10 h-10 bg-blue-50 text-blue-600 rounded-lg flex items-center justify-center shadow-inner"><i class="fas fa-info-circle"></i></div>
                <p class="text-[11px] font-bold text-slate-400 leading-relaxed max-w-sm">سيتم إنشاء قيود تسوية (Stock Adjustment) منفصلة لكل فرع في النظام المحاسبي.</p>
             </div>
             <LoadingButton
               text="تنفيذ التوزيع الآن"
               loadingText="جارٍ التوزيع..."
               icon="fas fa-check-circle"
               :isLoading="isSubmitting"
               :disabled="!selectedProduct || totalQuantity === 0"
               @click="submitBulk"
               class="h-12 rounded-lg px-12 text-xs shadow-lg shadow-blue-900/20"
             />
          </div>
        </section>
      </div>

      <!-- Tab Content: CSV Import: Stripe-style File Upload -->
      <div v-else-if="activeTab === 'csv'" class="animate-fadeIn">
        <div class="bg-white border border-slate-200 rounded-xl p-8 lg:p-10 shadow-sm space-y-10">
          <div class="flex flex-col md:flex-row md:items-center justify-between gap-6">
            <div class="space-y-1">
              <h3 class="text-sm font-bold text-slate-900 uppercase tracking-wider">الاستيراد عبر ملف CSV</h3>
              <p class="text-xs text-slate-400">تحديث أرصدة المنتجات بشكل دفعي من خلال ملفات الجداول.</p>
            </div>
            <a href="/templates/bulk_adjustments_template.csv" download class="h-9 px-4 rounded-md bg-slate-900 text-white text-[10px] font-bold uppercase tracking-widest hover:bg-black transition-all flex items-center gap-2">
              <i class="fas fa-download"></i> تحميل قالب البيانات
            </a>
          </div>
          
          <div class="grid grid-cols-1 lg:grid-cols-3 gap-10">
            <!-- Drop Zone -->
            <div class="lg:col-span-2">
              <label class="flex flex-col items-center justify-center w-full h-64 border-2 border-slate-100 border-dashed rounded-xl cursor-pointer bg-slate-50/50 hover:bg-white hover:border-blue-300 hover:shadow-xl hover:shadow-blue-900/5 transition-all duration-500 group">
                <div class="flex flex-col items-center justify-center pt-5 pb-6 text-center">
                  <div class="w-14 h-14 bg-white rounded-xl flex items-center justify-center text-slate-300 group-hover:text-blue-600 transition-all shadow-sm mb-4">
                    <i class="fas fa-cloud-upload-alt text-xl"></i>
                  </div>
                  <p class="mb-2 text-sm font-bold text-slate-700">اسحب الملف هنا أو انقر للاختيار</p>
                  <p class="text-[10px] text-slate-400 font-medium uppercase tracking-widest">ملفات CSV فقط (الحد الأقصى 10MB)</p>
                </div>
                <input type="file" accept=".csv" @change="onFileChange" class="hidden" />
              </label>

              <!-- Selected File UI -->
              <transition name="fade">
                <div v-if="csvFile" class="mt-4 p-4 bg-emerald-50 border border-emerald-100 rounded-lg flex items-center justify-between animate-fadeIn">
                  <div class="flex items-center gap-4">
                    <div class="w-10 h-10 bg-white rounded flex items-center justify-center text-emerald-500 shadow-sm border border-emerald-100"><i class="fas fa-file-csv text-xl"></i></div>
                    <div>
                      <p class="text-xs font-bold text-slate-800">{{ csvFile.name }}</p>
                      <p class="text-[9px] font-bold text-emerald-500 uppercase mt-1">{{ formatFileSize(csvFile.size) }}</p>
                    </div>
                  </div>
                  <button @click="clearFile" class="text-slate-300 hover:text-rose-500 transition-colors"><i class="fas fa-times-circle text-lg"></i></button>
                </div>
              </transition>
            </div>

            <!-- Context Settings -->
            <div class="space-y-6 bg-slate-50/50 p-6 rounded-xl border border-slate-100">
               <div class="space-y-3">
                  <label class="text-[10px] font-bold text-slate-500 uppercase tracking-widest flex items-center gap-2 px-1">
                    <i class="fas fa-cube text-indigo-400"></i> المعرف الافتراضي للمنتج
                  </label>
                  <input 
                    type="number" 
                    v-model.number="defaultProductId" 
                    class="w-full h-10 rounded-md border border-slate-200 bg-white px-4 text-sm font-bold font-mono outline-none focus:border-indigo-400 transition-all shadow-sm" 
                    placeholder="رقم المنتج (ID)"
                  />
                  <p class="text-[10px] text-slate-400 leading-relaxed italic">ملاحظة: سيتم تطبيق هذا الرقم في حال خلو الصف من معرف صريح.</p>
               </div>
               
               <div class="pt-6 border-t border-slate-200">
                 <LoadingButton
                   text="بدء عملية المعالجة"
                   loadingText="جارٍ استيراد الملف..."
                   icon="fas fa-file-import"
                   :isLoading="isSubmitting"
                   :disabled="!csvFile"
                   @click="submitCsv"
                   class="h-12 w-full rounded-lg text-[11px] tracking-widest shadow-lg"
                 />
               </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Tab Content: History: Minimalist Empty State -->
      <div v-else-if="activeTab === 'history'" class="animate-fadeIn">
        <div class="bg-white border border-slate-200 rounded-xl p-8 lg:p-12 shadow-sm flex flex-col items-center justify-center text-center">
           <div class="w-16 h-16 bg-slate-50 text-slate-200 rounded-full flex items-center justify-center mb-6">
             <i class="fas fa-history text-3xl"></i>
           </div>
           <h3 class="text-sm font-bold text-slate-900 uppercase tracking-widest">سجل التوزيعات</h3>
           <p class="text-xs text-slate-400 mt-2 max-w-xs leading-relaxed">سيتم تفعيل سجل المعاملات التاريخية قريباً لتتمكن من مراجعة العمليات السابقة.</p>
           <button @click="refreshHistory" class="mt-6 text-[10px] font-bold text-blue-600 hover:underline uppercase">تحديث القائمة</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, computed, watch } from 'vue';
import { useRoute } from 'vue-router';
import { useToast } from '@/composables/useToast';
import { useBranchStore } from '@/stores/branch/branchStore';
import { useProductStore } from '@/stores/product/productStore';
import { useBulkAdjustmentStore } from '@/stores/bulk/bulkAdjustmentStore';
import AlertService from '@/services/AlertService';
import LoadingButton from '@/components/ui/LoadingButton.vue';
import { useBreadcrumb } from '@/composables/useBreadcrumb';
import PageHeader from '@/components/PageHeader.vue';

// --- Logic Initialization (STRICTLY PRESERVED) ---
const route = useRoute();
const { showToast } = useToast();
const { breadcrumb } = useBreadcrumb();
const branchStore = useBranchStore();
const bulkAdjustmentStore = useBulkAdjustmentStore();
const productStore = useProductStore();
const activeTab = ref('form');
const isSubmitting = ref(false);
const isRefreshing = ref(false);
const totalQuantity = computed(() => rows.value.reduce((sum, row) => sum + (Number(row.quantity) || 0), 0));

// Product Search Logic
const productSearch = ref('');
const products = ref([]);
const productSearchError = ref('');
const isSearchingProducts = ref(false);
const selectedProduct = ref(null);
const showDropdown = ref(false);
let searchTimeout;
let productSearchRequestId = 0;

// Watch for search changes to close dropdown when cleared
watch(productSearch, (val) => {
  if (!val) showDropdown.value = false;
});

const selectProduct = (p) => {
  selectedProduct.value = {
    ...p,
    stock: Number(p.stock ?? p.current_quantity ?? p.quantity ?? 0),
  };
  productSearch.value = p.name;
  showDropdown.value = false;
};
const clearProduct = () => {
  selectedProduct.value = null;
  productSearch.value = '';
  clearTimeout(searchTimeout);
  productSearchRequestId += 1;
  products.value = [];
  productSearchError.value = '';
  isSearchingProducts.value = false;
  percentageCache.clear();
};
const handleSearchKeydown = (e) => {
  if (e.key === 'ArrowDown') {
    e.preventDefault();
    const firstItem = document.querySelector('[data-dropdown-item="0"]');
    if (firstItem) firstItem.focus();
  } else if (e.key === 'Escape') {
    showDropdown.value = false;
  }
};
const debouncedSearch = () => {
  showDropdown.value = true;
  clearTimeout(searchTimeout);
  productSearchError.value = '';
  const query = productSearch.value.trim();
  const requestId = ++productSearchRequestId;
  isSearchingProducts.value = Boolean(query);
  products.value = [];
  if (!query) {
    return;
  }

  searchTimeout = setTimeout(async () => {
    if (requestId !== productSearchRequestId) return;
    try {
      const result = await bulkAdjustmentStore.searchProducts(query);
      if (requestId !== productSearchRequestId) return;
      if (result.status === 'success') {
        products.value = result.data;
      } else {
        products.value = [];
        productSearchError.value = result.message || 'تعذر البحث عن المنتجات.';
      }
    } catch (error) {
      if (requestId !== productSearchRequestId) return;
      console.error('Product search failed:', error);
      products.value = [];
      productSearchError.value = error?.message || 'تعذر البحث عن المنتجات.';
    } finally {
      if (requestId === productSearchRequestId) {
        isSearchingProducts.value = false;
      }
    }
  }, 300);
};

// Branches Logic
const rows = ref([]);
const loadBranches = async () => {
  try {
    await branchStore.fetchBranches();
    rows.value = branchStore.branches.map(w => ({ id: w.id, name: w.name, quantity: 0, notes: '' }));
  } catch { rows.value = []; }
};

// Bulk Actions Logic
const submitBulk = async () => {
  const items = rows.value
    .filter(r => r.quantity && r.quantity !== 0)
    .map(r => ({ branch_id: String(r.id), quantity: Number(r.quantity), notes: r.notes || undefined }));
  
  if (!selectedProduct.value) { await AlertService.warning('اختر منتجاً أولاً', 'بيانات ناقصة'); return; }
  if (items.length === 0) { await AlertService.warning('أدخل كميات لواحد على الأقل من الفروع', 'بيانات ناقصة'); return; }
  if (items.some(item => item.quantity < 0)) { await AlertService.warning('لا يمكن إدخال كميات سالبة', 'خطأ في البيانات'); return; }
  
  // Stock validation - prevent exceeding available stock
  if (totalQuantity.value > (selectedProduct.value.stock || 0)) {
    await AlertService.warning('الكمية الإجمالية المطلوب توزيعها تتجاوز المخزون المتاح.', 'خطأ');
    return;
  }
  
  if (items.some(item => item.quantity > 999999)) {
    if (!await AlertService.confirm('هناك كميات كبيرة جداً. هل تريد المتابعة؟', 'تأكيد')) return;
  }
  
  isSubmitting.value = true;
  try {
    const res = await bulkAdjustmentStore.adjustProduct(selectedProduct.value.id, items);
    if (res.status === 'success') {
      await AlertService.success('تم تنفيذ التوزيع بنجاح', 'نجاح العمل');
      resetQuantities();
      productStore.invalidateCache();
    } else throw new Error(res.message || 'فشل التوزيع');
  } catch (e) { await AlertService.error(e.message || 'حدث خطأ غير متوقع', 'خطأ تقني'); } 
  finally { isSubmitting.value = false; }
};

// CSV Logic
const csvFile = ref(null);
const defaultProductId = ref(route.query.product_id ? Number(route.query.product_id) : null);
const onFileChange = (e) => { csvFile.value = e.target.files?.[0] || null; };
const clearFile = () => { csvFile.value = null; };

const submitCsv = async () => {
  if (!csvFile.value) { await AlertService.warning('اختر ملف CSV أولاً', 'ملف مطلوب'); return; }
  if (!csvFile.value.name.toLowerCase().endsWith('.csv')) { await AlertService.warning('يجب أن يكون الملف من نوع CSV', 'خطأ'); return; }
  
// File size validation (max 10MB)
const maxSize = 10 * 1024 * 1024; // 10MB
  if (csvFile.value.size > maxSize) {
    await AlertService.warning('حجم الملف كبير جداً. الحد الأقصى 10 ميجابايت', 'حجم الملف كبير');
    return;
  }
  
  isSubmitting.value = true;
  try {
    const res = await bulkAdjustmentStore.adjustFromCsv(csvFile.value, defaultProductId.value || undefined);
    if (res.status === 'success') {
      await AlertService.success(`تم الاستيراد: ${res.summary?.imported || 0} صف، تم تجاوز ${res.summary?.skipped || 0} صف`, 'تم الاستيراد');
      csvFile.value = null;
      productStore.invalidateCache();
    } else throw new Error(res.message);
  } catch (e) { await AlertService.error(e.message || 'فشل الاستيراد'); } 
  finally { isSubmitting.value = false; }
};

// UI & Formatting Helpers
const percentageCache = new Map();
const getPercentage = (qty) => {
  const key = `qty_${qty}_${totalQuantity.value}`;
  if (percentageCache.has(key)) {
    return percentageCache.get(key);
  }
  const result = totalQuantity.value === 0 ? 0 : ((Number(qty) || 0) / totalQuantity.value * 100).toFixed(1);
  percentageCache.set(key, result);
  return result;
};
const resetQuantities = () => {
  rows.value.forEach(r => { r.quantity = 0; r.notes = ''; });
  percentageCache.clear(); // Clear cache when resetting
};
const fillEqualQuantities = () => {
  const modal = document.createElement('div');
  modal.className = 'fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4';
  modal.innerHTML = `
    <div class="bg-white rounded-2xl p-6 max-w-md w-full shadow-2xl">
      <h3 class="text-lg font-black text-slate-900 mb-4">توزيع متساوي</h3>
      <p class="text-sm text-slate-600 mb-4">أدخل الكمية الإجمالية للتوزيع المتساوي على جميع الفروع:</p>
      <input type="number" id="qtyInput" class="w-full h-11 border border-slate-200 rounded-xl px-4 text-sm font-black focus:border-blue-500 focus:ring-4 focus:ring-blue-50 outline-none" placeholder="الكمية الإجمالية" min="0" step="0.01">
      <div class="flex gap-3 mt-6">
        <button id="cancelBtn" class="flex-1 h-11 bg-slate-100 text-slate-600 rounded-xl font-black hover:bg-slate-200 transition-all">إلغاء</button>
        <button id="confirmBtn" class="flex-1 h-11 bg-blue-600 text-white rounded-xl font-black hover:bg-blue-700 transition-all">تأكيد</button>
      </div>
    </div>
  `;
  document.body.appendChild(modal);
  
  const input = modal.querySelector('#qtyInput');
  const cancelBtn = modal.querySelector('#cancelBtn');
  const confirmBtn = modal.querySelector('#confirmBtn');
  
  const closeModal = () => {
    document.body.removeChild(modal);
  };
  
  cancelBtn.addEventListener('click', closeModal);
  confirmBtn.addEventListener('click', () => {
    const qty = parseFloat(input.value);
    if (!isNaN(qty) && qty >= 0) {
      const perWh = qty / rows.value.length;
      rows.value.forEach(r => r.quantity = perWh);
      percentageCache.clear(); // Clear cache after updating quantities
    }
    closeModal();
  });
  
  input.focus();
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') confirmBtn.click();
    if (e.key === 'Escape') closeModal();
  });
  
  modal.addEventListener('click', (e) => {
    if (e.target === modal) closeModal();
  });
};
const refreshData = async () => {
  if (isRefreshing.value) return;
  isRefreshing.value = true;
  try {
    await loadBranches();
    showToast('تم تحديث بيانات الفروع', 'success');
  } finally {
    isRefreshing.value = false;
  }
};
const formatFileSize = (b) => { if (b === 0) return '0 B'; const k = 1024, i = Math.floor(Math.log(b) / Math.log(k)); return parseFloat((b / Math.pow(k, i)).toFixed(2)) + ' ' + ['Bytes', 'KB', 'MB', 'GB'][i]; };
const refreshHistory = () => AlertService.info('سجل التوزيعات سيتوفر قريباً');

onMounted(async () => {
  await loadBranches();
  const pid = route.query.product_id ? Number(route.query.product_id) : null;
  if (pid) {
    try {
      const branchId = Number(branchStore.selectedBranchId);
      const res = await productStore.fetchProducts({
        branchId: Number.isInteger(branchId) && branchId > 0 ? branchId : undefined,
        force: true,
      });
      const found = res.status === 'success'
        && res.data.find(product => String(product.id) === String(pid));
      if (found) selectProduct(found);
    } catch (error) {
      console.error('Failed to load the requested distribution product:', error);
      showToast(error?.message || 'تعذر تحميل المنتج المحدد للتوزيع', 'error');
    }
  }
});
</script>

<style scoped>
@keyframes loading { 0% { transform: translateX(100%); } 100% { transform: translateX(-100%); } }

.custom-scroll::-webkit-scrollbar { width: 5px; }
.custom-scroll::-webkit-scrollbar-thumb { @apply bg-slate-200 rounded-full; }

/* Dashboard Identity Controls */
.tab-pill { @apply px-8 h-10 rounded-md text-[11px] font-bold transition-all flex items-center gap-2; }
.animate-fadeIn { animation: fadeIn 0.4s ease-out; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

/* Shared Select/Input Logic from LayoutRedesign */
/* Transitions */
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }

.slide-down-enter-active, .slide-down-leave-active { transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
.slide-down-enter-from, .slide-down-leave-to { opacity: 0; transform: translateY(-10px); }
</style>