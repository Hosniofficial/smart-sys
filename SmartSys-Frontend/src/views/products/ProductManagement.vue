<template>
  <div class="min-h-screen bg-[#fafafa] text-slate-900 font-sans antialiased selection:bg-blue-100" dir="rtl">
    
    <!-- Top Progress Bar -->
    <div v-if="isLoading" class="fixed top-0 left-0 right-0 h-0.5 bg-blue-600/10 z-[110]">
      <div class="h-full bg-blue-600 animate-[loading_2s_ease-in-out_infinite] w-1/3"></div>
    </div>

    <div class="max-w-[1600px] mx-auto p-6 lg:p-10 space-y-8">
      
      <!-- Page Header -->
      <PageHeader
        :breadcrumb="breadcrumb"
        title="إدارة المنتجات"
        description="تنظيم قائمة الأصناف، الأسعار، وتصنيف المنتجات عبر الفروع."
        :branches="branches"
        :selectedBranch="selectedBranch"
        branch-selection-mode="required"
        @branch-changed="handleBranchChange"
      >
        <template #controls>
          <div class="flex items-center gap-2">
            <button @click="openAddModal" class="h-9 px-4 rounded-md bg-blue-600 text-white text-xs font-bold shadow-sm hover:bg-blue-700 transition-all active:scale-95 flex items-center gap-2">
              <i class="fas fa-plus text-[10px]"></i> إضافة منتج
            </button>
            <button @click="exportProducts" class="h-9 w-9 bg-white border border-slate-200 rounded-md flex items-center justify-center text-slate-400 hover:text-emerald-600 hover:border-emerald-200 transition-all shadow-sm" title="تصدير CSV">
              <i class="fas fa-file-export text-xs"></i>
            </button>
          </div>
        </template>
      </PageHeader>

      <!-- KPI Section: Refined Stats -->
      <section class="grid grid-cols-1 sm:grid-cols-2 gap-6">
        <div v-for="kpi in [
          { label: 'إجمالي الأصناف', val: (Array.isArray(products) ? products : []).length, icon: 'fa-box', color: 'text-blue-600', bg: 'bg-blue-50' },
          { label: 'المنتجات النشطة', val: (Array.isArray(products) ? products : []).filter(p => p.active === 1).length, icon: 'fa-check-double', color: 'text-emerald-600', bg: 'bg-emerald-50' }
        ]" :key="kpi.label" class="bg-white border border-slate-200 p-5 rounded-xl flex items-center justify-between group hover:border-slate-300 transition-all">
          <div>
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">{{ kpi.label }}</p>
            <p class="text-2xl font-bold text-slate-900 font-mono tracking-tighter">{{ kpi.val }}</p>
          </div>
          <div :class="[kpi.bg, kpi.color]" class="w-10 h-10 rounded-lg flex items-center justify-center text-sm opacity-80 group-hover:opacity-100 transition-opacity">
            <i :class="['fas', kpi.icon]"></i>
          </div>
        </div>
      </section>

      <!-- Filters & Content -->
      <div class="space-y-6">
        
        <!-- Filter Bar -->
        <div class="bg-white border border-slate-200 rounded-xl p-5 flex flex-col lg:flex-row lg:items-center justify-between gap-6 shadow-sm">
          <div class="flex-grow space-y-2">
            <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest px-1">تصفية حسب التصنيف</label>
            <div class="flex flex-wrap gap-1.5">
              <button @click="selectedCategory = ''" :class="[selectedCategory === '' ? 'bg-slate-900 text-white shadow-sm' : 'bg-slate-50 text-slate-500 hover:bg-slate-100']" class="px-3 py-1.5 rounded-md text-[11px] font-bold transition-all">
                الكل <span class="mr-1 opacity-50">{{ (Array.isArray(products) ? products : []).length }}</span>
              </button>
              <button v-for="cat in categories" :key="cat.id" @click="selectedCategory = cat.id" :class="[selectedCategory === cat.id ? 'bg-blue-600 text-white shadow-md shadow-blue-900/10' : 'bg-slate-50 text-slate-500 hover:bg-slate-100']" class="px-3 py-1.5 rounded-md text-[11px] font-bold transition-all flex items-center gap-2">
                {{ cat.name }}
                <span class="opacity-50 font-mono">{{ (Array.isArray(products) ? products : []).filter(p => p.category_id == cat.id).length }}</span>
              </button>
            </div>
          </div>

          <div class="lg:w-80 space-y-2 relative group">
            <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest px-1">بحث سريع</label>
            <div class="relative">
              <input v-model="searchQuery" type="text" class="h-9 w-full bg-white border border-slate-200 rounded-md pr-9 pl-4 text-[11px] font-bold text-slate-700 focus:ring-2 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all" placeholder="الاسم أو الباركود..." />
              <i class="fas fa-search absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px]"></i>
            </div>
          </div>
        </div>

        <!-- Table Card -->
        <div class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm relative">
          
          <!-- Loading State -->
          <div v-if="isLoading" class="p-8 space-y-4">
            <div v-for="i in 6" :key="i" class="flex gap-4 items-center py-4 border-b border-slate-50 animate-pulse">
              <div class="w-10 h-10 bg-slate-100 rounded-lg"></div>
              <div class="flex-grow space-y-2">
                <div class="h-3 bg-slate-100 rounded w-1/3"></div>
                <div class="h-2 bg-slate-50 rounded w-1/4"></div>
              </div>
              <div class="h-3 bg-slate-100 rounded w-20"></div>
              <div class="h-3 bg-slate-100 rounded w-20"></div>
              <div class="w-20 h-6 bg-slate-100 rounded-full"></div>
            </div>
          </div>

          <!-- Error State -->
          <div v-else-if="error" class="py-24 text-center px-6">
            <div class="w-16 h-16 bg-rose-50 text-rose-500 rounded-2xl flex items-center justify-center mx-auto mb-4 shadow-inner">
              <i class="fas fa-exclamation-triangle text-xl"></i>
            </div>
            <h3 class="text-sm font-bold text-slate-900">{{ error }}</h3>
            <button @click="fetchAllData" class="mt-4 px-6 py-2 bg-slate-900 text-white rounded-md font-bold text-xs hover:bg-black transition-all">إعادة المحاولة</button>
          </div>

          <!-- Data Table -->
          <template v-else>
            <div class="overflow-x-auto">
              <table class="w-full text-right border-collapse">
                <thead>
                  <tr class="bg-slate-50/50 border-b border-slate-200">
                    <th class="px-6 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">بيانات المنتج</th>
                    <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">SKU</th>
                    <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">التصنيف</th>
                    <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-left">سعر البيع</th>
                    <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">الحالة</th>
                    <th class="px-6 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">إجراءات</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                  <tr v-if="filteredProducts.length === 0" class="text-center">
                    <td colspan="6" class="py-24 text-slate-300">
                      <i class="fas fa-box-open text-3xl mb-4 opacity-20"></i>
                      <p class="text-xs font-bold uppercase tracking-widest">لا توجد منتجات مضافة</p>
                    </td>
                  </tr>
                  <tr v-for="product in paginatedProducts" :key="product.id" class="hover:bg-blue-50/20 transition-all group">
                    <td class="px-6 py-4">
                      <div class="flex items-center gap-4">
                        <div class="w-9 h-9 bg-slate-50 rounded-lg flex items-center justify-center text-slate-400 group-hover:text-blue-600 transition-colors border border-slate-100 group-hover:border-blue-100">
                          <i class="fas fa-box text-xs"></i>
                        </div>
                        <div class="flex flex-col">
                          <span class="text-xs font-bold text-slate-900">{{ product.name }}</span>
                          <span class="text-[9px] font-bold text-slate-400 font-mono mt-1 uppercase tracking-widest">{{ product.barcode || '--' }}</span>
                        </div>
                      </div>
                    </td>
                    <td class="px-4 py-4">
                      <span class="text-[10px] font-bold text-indigo-600 bg-indigo-50 px-2 py-0.5 rounded border border-indigo-100 font-mono">{{ product.product_code || '--' }}</span>
                    </td>
                    <td class="px-4 py-4">
                      <span class="text-[10px] font-bold text-slate-500 bg-slate-100 px-2 py-0.5 rounded">{{ product.category_name || 'غير مصنف' }}</span>
                    </td>
                    <td class="px-4 py-4 text-left">
                      <span class="text-sm font-bold font-mono tracking-tighter text-blue-600">{{ formatCurrencyLocale(product.sale_price, 2) }}</span>
                    </td>
                    <td class="px-4 py-4 text-center">
                      <div :class="[product.active === 1 ? 'text-emerald-600 bg-emerald-50 border-emerald-100' : 'text-slate-400 bg-slate-50 border-slate-100']" class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[9px] font-bold border">
                        <span class="w-1 h-1 rounded-full bg-current"></span>
                        {{ product.active === 1 ? 'نشط' : 'معطل' }}
                      </div>
                    </td>
                    <td class="px-6 py-4">
                      <div class="flex items-center justify-center gap-2">
                        <button @click="openEditModal(product)" class="w-8 h-8 rounded-lg border border-slate-200 text-slate-400 hover:text-blue-600 hover:border-blue-200 transition-all flex items-center justify-center"><i class="fas fa-pen text-[10px]"></i></button>
                        <button @click="handleDelete(product.id)" class="w-8 h-8 rounded-lg border border-slate-200 text-slate-400 hover:text-rose-600 hover:border-rose-200 transition-all flex items-center justify-center"><i class="fas fa-trash-alt text-[10px]"></i></button>
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <!-- Pagination Footer (Unified Standard) -->
            <div class="px-6 py-4 bg-slate-50/50 border-t border-slate-200 flex items-center justify-between">
              <div class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">
                صفحة <span class="text-slate-900">{{ filters.page.value }}</span> من <span class="text-slate-900">{{ filters.totalPages.value }}</span>
                <span class="mx-2 text-slate-200">|</span>
                إجمالي <span class="text-slate-900">{{ filteredProducts.length }}</span> منتج
              </div>
              <div class="flex items-center gap-3">
                 <div class="flex items-center gap-2">
                   <span class="text-[10px] font-bold text-slate-400 uppercase tracking-tighter">النتائج:</span>
                   <select v-model.number="filters.perPage.value" class="h-8 border border-slate-200 rounded px-2 text-[10px] font-bold outline-none">
                     <option :value="10">10</option>
                     <option :value="20">20</option>
                     <option :value="50">50</option>
                   </select>
                 </div>
                 <div class="flex items-center gap-1">
                   <button @click="filters.previousPage()" :disabled="filters.page.value <= 1" class="pagination-btn-v2"><i class="fas fa-chevron-right"></i></button>
                   <button @click="filters.nextPage(filters.totalPages.value)" :disabled="filters.page.value >= filters.totalPages.value" class="pagination-btn-v2"><i class="fas fa-chevron-left"></i></button>
                 </div>
              </div>
            </div>
          </template>
        </div>
      </div>
    </div>

    <!-- Teleported Modal -->
    <BaseModal :show="showFormModal" @close="showFormModal = false" maxWidth="4xl">
      <template #header>
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 bg-slate-900 rounded-lg flex items-center justify-center text-white shadow-lg shadow-slate-200">
            <i :class="[selectedProduct ? 'fas fa-edit' : 'fas fa-plus', 'text-xs']"></i>
          </div>
          <div>
            <h3 class="text-sm font-bold text-slate-900 uppercase tracking-tight">{{ selectedProduct ? 'تحديث بيانات الصنف' : 'إضافة صنف جديد للمخزن' }}</h3>
            <p class="text-[10px] text-slate-400 font-bold uppercase tracking-[0.2em] mt-0.5">بيانات الصنف والتفاصيل المالية والمخزنية</p>
          </div>
        </div>
      </template>
      <ProductForm
        ref="productFormRef"
        :product-data="selectedProduct"
        :categories="categories"
        :branch-id="selectedBranch"
        :branches="branches"
        @close="showFormModal = false"
        @success="handleFormSuccess"
        @category-added="handleCategoryAdded"
      />
      <template #footer>
        <button @click="showFormModal = false" class="px-6 h-10 text-[11px] font-bold text-slate-500 hover:text-slate-900 transition-colors">إلغاء</button>
        <LoadingButton
          :text="selectedProduct ? 'تحديث البيانات' : 'حفظ المنتج للمخزن'"
          :isLoading="productFormRef?.isSaving"
          loadingText="جاري الحفظ..."
          icon="fas fa-save"
          variant="primary"
          loaderType="dots"
          @click="productFormRef?.handleSubmit()"
          class="h-10 px-10 flex items-center justify-center"
        />
      </template>
    </BaseModal>

  </div>
</template>

<script setup>
import { ref, onMounted, computed, watch } from 'vue';
import BaseModal from '@/components/BaseModal.vue';
import LoadingButton from '@/components/ui/LoadingButton.vue';
import { useToast } from '@/composables/useToast';
import { useTableFilters } from '@/composables/useTableFilters';
import { getLocalDateISO } from '@/utils';
import { useCompanyCurrency } from '@/composables/useCompanyCurrency';
import ProductForm from '@/components/ProductForm.vue';
import AlertService from '@/services/AlertService';
import { useBranchStore } from '@/stores/branch/branchStore';
import { useBranchIsolation } from '@/composables/useBranchIsolation';
import { useRequiredBranch } from '@/composables/useRequiredBranch';
import { useAuthStore } from '@/stores/auth/authStore';
import { useCatalogStore } from '@/stores/catalog/catalogStore';
import { useProductStore } from '@/stores/product/productStore';
import { useBreadcrumb } from '@/composables/useBreadcrumb';
import PageHeader from '@/components/PageHeader.vue';

const { showToast } = useToast();
const { formatCurrencyLocale, fetchSettings } = useCompanyCurrency();
const { breadcrumb } = useBreadcrumb();
const branchStore = useBranchStore();
const branchIsolation = useBranchIsolation();
const authStore = useAuthStore();
const { ensureBranchSelected, setRequiredBranch } = useRequiredBranch();
const catalogStore = useCatalogStore();
const productStore = useProductStore();

const products = ref([]);
// Guard: categories and product operations are always scoped to one branch.
const categories = computed(() => {
  const branchId = selectedBranch.value;
  if (!branchId) return [];
  return catalogStore.getCategoriesForBranch(branchId);
});
const branches = computed(() => branchStore.branches);
const showFormModal = ref(false);
const selectedProduct = ref(null);
const productFormRef = ref(null);
const selectedBranch = computed(() => branchIsolation.isExempt.value
  ? branchStore.selectedBranchId
  : authStore.user?.branch_id ?? null);
const isLoading = ref(true);
const branchContextReady = ref(false);
const error = ref(null);
const searchQuery = ref('');
const selectedCategory = ref('');

// ─── Pagination (using useTableFilters composable)
const filters = useTableFilters('products_filters');

const handleBranchChange = (branchId) => {
  if (!setRequiredBranch(branchId)) return;
  filters.page.value = 1;
};

const fetchAllData = async (force = false) => {
  isLoading.value = true;
  error.value = null;
  try {
    if (!selectedBranch.value) {
      products.value = [];
      error.value = 'يرجى تحديد فرع لعرض المنتجات.';
      return;
    }
    const branchId = String(selectedBranch.value);
    const [productsResponse] = await Promise.all([
      productStore.fetchProducts({ branchId, force }),
      catalogStore.fetchCategories(branchId, { force })
    ]);
    if (productsResponse && productsResponse.status === 'success') {
      products.value = productsResponse.data || [];
    }
  } catch (err) {
    error.value = err.message || 'فشل في تحميل البيانات';
    showToast(error.value, 'error');
  } finally { isLoading.value = false; }
};

const exportProducts = () => {
  const productList = Array.isArray(products.value) ? products.value : Object.values(products.value).flat();
  if (!productList.length) { showToast('لا توجد منتجات للتصدير', 'warning'); return; }
  const rows = [['ID', 'الاسم', 'الباركود', 'التصنيف', 'سعر البيع', 'الحالة']];
  productList.forEach(p => {
    const cat = categories.value.find(c => c.id === p.category_id)?.name || 'غير مصنف';
    rows.push([p.id, p.name, p.barcode || '', cat, p.sale_price, p.active === 1 ? 'نشط' : 'غير نشط']);
  });
  const csv = rows.map(r => r.map(v => `"${String(v ?? '').replace(/"/g, '""')}"`).join(',')).join('\n');
  const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a'); a.href = url; a.download = `products_${getLocalDateISO()}.csv`; a.click(); URL.revokeObjectURL(url);
  showToast('تم تصدير المنتجات بنجاح', 'success');
};

const filteredProducts = computed(() => {
  const productList = Array.isArray(products.value) ? products.value : Object.values(products.value).flat();
  let result = [...productList];
  if (selectedCategory.value) result = result.filter(p => p.category_id == selectedCategory.value);
  if (searchQuery.value) {
    const q = searchQuery.value.toLowerCase();
    result = result.filter(p => p.name.toLowerCase().includes(q) || (p.barcode && p.barcode.toLowerCase().includes(q)));
  }
  return result;
});


const paginatedProducts = computed(() => {
  filters.totalCount.value = filteredProducts.value.length;
  const start = (filters.page.value - 1) * filters.perPage.value;
  return filteredProducts.value.slice(start, start + filters.perPage.value);
});

watch(selectedBranch, async (newId, oldId) => {
  if (!branchContextReady.value) return;
  if (newId === oldId) return;
  if (!newId) {
    products.value = [];
    selectedCategory.value = '';
    error.value = 'يرجى تحديد فرع لعرض المنتجات.';
    return;
  }
  isLoading.value = true;
  error.value = null;
  try {
    const [prodRes] = await Promise.all([productStore.fetchProducts({ branchId: newId }), catalogStore.fetchCategories(newId)]);
    if (prodRes && prodRes.status === 'success') { products.value = prodRes.data || []; }
    selectedCategory.value = '';
    filters.page.value = 1;
  } catch { showToast('فشل تحميل البيانات', 'error'); }
  finally { isLoading.value = false; }
}, { flush: 'sync' });

watch([searchQuery, selectedCategory], () => filters.page.value = 1);

const openAddModal = () => { selectedProduct.value = null; showFormModal.value = true; };
const openEditModal = async (p) => { 
  try {
    const response = await productStore.getProductDetail(p.id);
    if (response.status === 'success') {
      const fullData = response.data;
      selectedProduct.value = {
        ...fullData,
        sale_price: fullData.pricing?.sale_price ?? fullData.sale_price,
        purchase_price: fullData.pricing?.purchase_price ?? fullData.purchase_price,
        min_sale_price: fullData.pricing?.min_sale_price ?? fullData.min_sale_price ?? 0,
        fixed_discount_percentage: fullData.pricing?.fixed_discount_percentage ?? fullData.fixed_discount_percentage ?? 0,
        current_quantity: fullData.inventory?.current_quantity ?? fullData.current_quantity ?? 0,
        unit_id: fullData.inventory?.unit_id ?? fullData.unit_id,
        unit_name: fullData.inventory?.unit_name ?? fullData.unit_name,
        min_quantity: fullData.inventory?.min_quantity ?? fullData.min_quantity ?? 0,
        max_quantity: fullData.inventory?.max_quantity ?? fullData.maximum_quantity ?? 0,
        product_type: fullData.configuration?.product_type ?? fullData.product_type ?? 'stock',
        active: fullData.configuration?.active ?? fullData.active ?? 1,
        has_expiry_date: fullData.configuration?.has_expiry_date ?? fullData.has_expiry_date ?? false,
        has_batch_number: fullData.configuration?.has_batch_number ?? fullData.has_batch_number ?? false,
        has_serial_number: fullData.configuration?.has_serial_number ?? fullData.has_serial_number ?? false,
        expiry_date: fullData.configuration?.expiry_date ?? fullData.expiry_date ?? '',
        batch_number: fullData.configuration?.batch_number ?? fullData.batch_number ?? '',
        serial_number: fullData.configuration?.serial_number ?? fullData.serial_number ?? '',
      };
      showFormModal.value = true;
    } else { showToast('فشل في جلب بيانات المنتج', 'error'); }
  } catch (error) { showToast(error.message || 'فشل في جلب بيانات المنتج', 'error'); }
};
const handleFormSuccess = async () => {
  try {
    showFormModal.value = false;
    await fetchAllData(true);
    // ✅ invalidateCache هو alias لـ clear - يمسح كل cache المنتجات
    productStore.invalidateCacheForBranch(selectedBranch.value);
    // ✅ لا toast هنا — ProductForm يعرض toast بالفعل قبل emit('success')
  } catch (error) {
    console.error('[ProductManagement] Error in handleFormSuccess:', error);
  }
};
const handleCategoryAdded = async () => {
  const branchId = selectedBranch.value;
  if (!branchId) return;
  try {
    await catalogStore.fetchCategories(branchId, { force: true });
  } catch (error) {
    console.error('[ProductManagement] Error refreshing categories:', error);
  }
};
const handleDelete = async (id) => {
  if (await AlertService.confirm('هل أنت متأكد من حذف هذا المنتج؟ سيتم إخفاؤه من نقاط البيع.', 'حذف المنتج')) {
    try {
      const response = await productStore.deleteProduct(id);
      if (response.status === 'success') { 
        showToast('تم حذف المنتج بنجاح', 'success'); 
        await fetchAllData(true); 
        productStore.invalidateCache();
      }
    } catch (e) { showToast(e.response?.data?.message || 'فشل الحذف', 'error'); }
  }
};

onMounted(async () => {
  await branchStore.initialize();
  if (branchIsolation.isExempt.value) {
    await ensureBranchSelected();
  } else {
    const assignedBranchId = authStore.user?.branch_id;
    if (assignedBranchId) setRequiredBranch(assignedBranchId);
  }
  branchContextReady.value = true;
  await Promise.all([fetchSettings(), fetchAllData()]);
});
</script>

<style scoped>
@keyframes loading { 0% { transform: translateX(100%); } 100% { transform: translateX(-100%); } }

.pagination-btn-v2 {
  @apply w-8 h-8 flex items-center justify-center rounded border border-slate-200 bg-white text-slate-500 hover:text-blue-600 hover:border-blue-200 disabled:opacity-40 transition-all;
}

.custom-scroll::-webkit-scrollbar { width: 5px; }
.custom-scroll::-webkit-scrollbar-thumb { @apply bg-slate-200 rounded-full; }

.animate-fadeIn { animation: fadeIn 0.4s ease-out; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

@keyframes modalIn { from { opacity: 0; transform: scale(0.98) translateY(10px); } to { opacity: 1; transform: scale(1) translateY(0); } }
.animate-modalIn { animation: modalIn 0.2s cubic-bezier(0.16, 1, 0.3, 1); }

.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>