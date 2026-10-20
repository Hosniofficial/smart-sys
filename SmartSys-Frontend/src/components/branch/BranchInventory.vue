<template>
  <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden animate-fadeIn">
    
    <!-- Inventory Header & Toolbar -->
    <div class="px-5 py-4 border-b border-slate-100 flex flex-col lg:flex-row lg:items-center justify-between gap-4 bg-slate-50/50">
      <div>
        <h3 class="text-base font-bold text-slate-900 leading-none">مخزون الفرع</h3>
        <p class="text-slate-500 text-xs mt-2">مراقبة أرصدة المنتجات وإتاحتها داخل هذا الفرع.</p>
      </div>

      <div class="flex flex-wrap items-center gap-3">
        <div class="relative group min-w-[240px]">
          <input 
            type="text" 
            v-model="searchQuery" 
            class="form-input-modern pr-10 h-9 text-xs"
            placeholder="ابحث بالمنتج، الباركود أو SKU..."
            @input="handleSearch"
          >
          <i class="fas fa-search absolute right-3.5 top-1/2 -translate-y-1/2 text-slate-300 group-focus-within:text-blue-500 transition-colors"></i>
        </div>
        
        <!-- زر إضافة مخزون يفتح صفحة إدارة المخزون -->
        <router-link
          :to="{ path: '/inventory', query: { branch_id: branchId, action: 'adjust' } }"
          class="h-9 px-4 rounded-md bg-blue-600 text-white text-xs font-bold shadow-sm hover:bg-blue-700 transition-all active:scale-95 flex items-center gap-2"
        >
          <i class="fas fa-plus"></i> إضافة مخزون
        </router-link>
      </div>
    </div>

    <!-- Inventory Quick Stats -->
    <section class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3 p-4 sm:p-5 border-b border-slate-100">
      <div class="kpi-card group border-l-4 border-l-blue-500">
        <div class="flex items-center gap-4">
          <div class="kpi-icon bg-blue-50 text-blue-600 group-hover:bg-blue-600 group-hover:text-white transition-all"><i class="fas fa-boxes"></i></div>
          <div>
            <p class="kpi-label">إجمالي الأصناف</p>
            <p class="kpi-value text-slate-800">{{ stats.total_products || 0 }}</p>
          </div>
        </div>
      </div>

      <div class="kpi-card group border-l-4 border-l-emerald-500">
        <div class="flex items-center gap-4">
          <div class="kpi-icon bg-emerald-50 text-emerald-600 group-hover:bg-emerald-600 group-hover:text-white transition-all"><i class="fas fa-check-circle"></i></div>
          <div>
            <p class="kpi-label">الأصناف المتوفرة</p>
            <p class="kpi-value text-emerald-600">{{ (stats.total_products || 0) - (stats.low_stock || 0) }}</p>
          </div>
        </div>
      </div>

      <div class="kpi-card group border-l-4 border-l-amber-500">
        <div class="flex items-center gap-4">
          <div class="kpi-icon bg-amber-50 text-amber-600 group-hover:bg-amber-600 group-hover:text-white transition-all"><i class="fas fa-triangle-exclamation"></i></div>
          <div>
            <p class="kpi-label">مخزون منخفض</p>
            <p class="kpi-value text-amber-600">{{ stats.low_stock || 0 }}</p>
          </div>
        </div>
      </div>

      <div class="kpi-card group border-l-4 border-l-indigo-500">
        <div class="flex items-center gap-4">
          <div class="kpi-icon bg-indigo-50 text-indigo-600 group-hover:bg-indigo-600 group-hover:text-white transition-all"><i class="fas fa-money-bill-wave"></i></div>
          <div>
            <p class="kpi-label">قيمة المخزون</p>
            <p class="kpi-value text-indigo-600 font-mono text-base">{{ formatCurrency(stats.total_value || 0) }}</p>
          </div>
        </div>
      </div>
    </section>

    <!-- Inventory Table -->
    <div class="overflow-x-auto min-h-[320px] relative">
      <table class="w-full text-right text-sm">
        <thead>
          <tr class="bg-slate-50/30 text-slate-500 font-black border-b border-slate-50 uppercase tracking-tighter">
            <th class="px-5 py-3.5">المنتج والترميز</th>
            <th class="px-4 py-3.5 text-center">الرصيد المتاح</th>
            <th class="px-4 py-3.5 text-center">الحد الأدنى</th>
            <th class="px-4 py-3.5 text-center">حالة التوفر</th>
            <th class="px-5 py-3.5 text-center">إجراءات التحكم</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-50 font-bold text-slate-700">
          <tr v-for="item in paginatedInventory" :key="item.id" class="hover:bg-blue-50/30 transition-all group">
            <td class="px-6 py-4">
              <div class="flex items-center gap-4">
                <div class="w-11 h-11 bg-slate-50 rounded-2xl flex items-center justify-center text-slate-300 border border-slate-100 group-hover:bg-white group-hover:border-blue-200 transition-all">
                  <i class="fas fa-box text-sm"></i>
                </div>
                <div class="flex flex-col">
                  <span class="font-black text-slate-800 leading-none group-hover:text-blue-600 transition-colors">{{ item.name }}</span>
                  <div class="flex gap-3 mt-1.5 text-[9px] font-black text-slate-400 uppercase font-mono tracking-widest">
                    <span>SKU: {{ item.product_code || '--' }}</span>
                    <span>BAR: {{ item.barcode || '--' }}</span>
                  </div>
                </div>
              </div>
            </td>
            <td class="px-4 py-4 text-center">
              <span class="text-base font-black text-slate-900 font-mono">{{ item.quantity }}</span>
              <p class="text-[9px] text-slate-300 uppercase mt-0.5">{{ item.unit || 'قطعة' }}</p>
            </td>
            <td class="px-4 py-4 text-center">
              <span v-if="item.min_quantity" class="text-xs font-bold text-slate-400 bg-slate-100 px-2 py-0.5 rounded-lg">{{ item.min_quantity }}</span>
              <span v-else class="text-slate-200">—</span>
            </td>
            <td class="px-4 py-4 text-center">
              <span :class="['status-badge', getStockStatusClass(item)]">
                {{ getStockStatus(item) }}
              </span>
            </td>
            <td class="px-8 py-4 text-center">
              <div class="flex items-center justify-center gap-1.5">
                <!-- تعديل في صفحة المنتجات — محفوظ كما هو -->
                <router-link
                  :to="{ path: '/products', query: { search: item.product_code || item.name || '' } }"
                  class="action-btn hover:bg-indigo-50 hover:text-indigo-600"
                  title="تعديل في صفحة المنتجات"
                >
                  <i class="fas fa-external-link-alt"></i>
                </router-link>
                <!-- توزيع جماعي — محفوظ كما هو -->
                <router-link
                  :to="{ path: '/branches/bulk-distribution', query: { product_id: (item.product_id || item.id) } }"
                  class="action-btn hover:bg-blue-50 hover:text-blue-600"
                  title="توزيع جماعي"
                >
                  <i class="fas fa-share-alt"></i>
                </router-link>
                <!-- تسوية يدوية: يفتح صفحة إدارة المخزون مع pre-select للمنتج -->
                <router-link
                  :to="{ path: '/inventory', query: { branch_id: branchId, product_id: (item.product_id || item.id), action: 'adjust' } }"
                  class="action-btn hover:bg-amber-50 hover:text-amber-600"
                  title="تسوية يدوية — يفتح إدارة المخزون"
                >
                  <i class="fas fa-sliders"></i>
                </router-link>
                <!-- نقل لمستودع آخر: يفتح صفحة إدارة المخزون مع pre-select للنقل -->
                <router-link
                  :to="{ path: '/inventory', query: { branch_id: branchId, product_id: (item.product_id || item.id), action: 'transfer' } }"
                  class="action-btn hover:bg-emerald-50 hover:text-emerald-600"
                  title="نقل لمستودع آخر — يفتح إدارة المخزون"
                >
                  <i class="fas fa-exchange-alt"></i>
                </router-link>
              </div>
            </td>
          </tr>

          <!-- Empty State -->
          <tr v-if="!isLoading && filteredInventory.length === 0">
            <td colspan="5" class="py-24 text-center">
              <div class="flex flex-col items-center opacity-20 text-slate-400">
                <i class="fas fa-box-open text-6xl mb-4"></i>
                <p class="font-black text-sm uppercase tracking-widest">الفرع فارغ حالياً</p>
                <router-link
                  :to="{ path: '/inventory', query: { branch_id: branchId, action: 'adjust' } }"
                  class="mt-4 text-blue-600 font-black text-xs hover:underline"
                >
                  إضافة منتجات الآن
                </router-link>
              </div>
            </td>
          </tr>

          <!-- Loading State -->
          <tr v-if="isLoading">
            <td colspan="5" class="py-24 text-center">
               <div class="flex flex-col items-center gap-4">
                  <BaseSpinner size="md" color="#2563eb" />
                  <p class="text-xs font-black text-slate-400 uppercase tracking-widest animate-pulse">جاري جلب بيانات المخزون...</p>
               </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Pagination Footer -->
    <div class="px-8 py-6 bg-slate-50/50 border-t border-slate-50 flex flex-col md:flex-row items-center justify-between gap-6">
      <div class="text-[11px] font-black text-slate-400 uppercase tracking-widest">
        عرض <span class="text-slate-800">{{ (currentPage - 1) * itemsPerPage + 1 }}</span> - <span class="text-slate-800">{{ Math.min(currentPage * itemsPerPage, filteredInventory.length) }}</span> من إجمالي <span class="text-slate-800">{{ filteredInventory.length }}</span> منتج
      </div>
      
      <div v-if="totalPages > 1" class="flex items-center gap-1">
        <button @click="currentPage--" :disabled="currentPage === 1" class="pagination-btn">
          <i class="fas fa-angle-right"></i>
        </button>
        <div class="flex items-center gap-1 mx-2">
          <button v-for="page in totalPages" :key="page" @click="currentPage = page" 
            :class="[currentPage === page ? 'bg-blue-600 text-white shadow-lg shadow-blue-100' : 'bg-white text-slate-400 hover:bg-slate-50']"
            class="w-9 h-9 rounded-xl text-xs font-black transition-all">
            {{ page }}
          </button>
        </div>
        <button @click="currentPage++" :disabled="currentPage >= totalPages" class="pagination-btn">
          <i class="fas fa-angle-left"></i>
        </button>
      </div>
    </div>

  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useToast } from '@/composables/useToast';
import { useCompanyCurrency } from '@/composables/useCompanyCurrency';
import BaseSpinner from '@/components/ui/BaseSpinner.vue';
import { getBranchInventory } from '@/services/branchInventoryService';

const props = defineProps({
  branchId: {
    type: [String, Number],
    required: true
  }
});

const emit = defineEmits(['inventory-updated']);
const { showToast } = useToast();
const { formatCurrency, fetchSettings } = useCompanyCurrency();

// --- State (PRESERVED) ---
const inventory = ref([]);
const stats = ref({ total_products: 0, low_stock: 0, total_value: 0 });
const isLoading = ref(true);
const error = ref(null);
const searchQuery = ref('');
const currentPage = ref(1);
const itemsPerPage = 10;


// Logic: Filtering & Pagination (PRESERVED)
const filteredInventory = computed(() => {
  const data = inventory.value;
  if (!Array.isArray(data)) return [];
  if (!searchQuery.value) return data;
  const q = searchQuery.value.toLowerCase();
  return data.filter(item => 
    item.name?.toLowerCase().includes(q) ||
    item.barcode?.toLowerCase().includes(q) ||
    item.product_code?.toLowerCase().includes(q)
  );
});

const paginatedInventory = computed(() =>
  filteredInventory.value.slice((currentPage.value - 1) * itemsPerPage, currentPage.value * itemsPerPage)
);
const totalPages = computed(() =>
  Math.max(1, Math.ceil(filteredInventory.value.length / itemsPerPage))
);

// Logic: API Calls (PRESERVED)
const fetchInventory = async () => {
  isLoading.value = true;
  error.value = null;
  try {
    const response = await getBranchInventory(props.branchId, {
      search: searchQuery.value,
      page: currentPage.value,
      per_page: itemsPerPage
    });
    if (response.status === 'success') {
      const data = response.data;
      inventory.value = (data?.rows || data?.items || []).map(item => ({
        ...item,
        id: item.id ?? item.product_id,
        name: item.name ?? item.product_name,
        product_code: item.product_code ?? item.sku
      }));
      stats.value = data?.stats || data?.totals || { total_products: 0, low_stock: 0, total_value: 0 };
    }
  } catch (err) {
    error.value = err.message || 'خطأ في جلب البيانات';
    showToast(error.value, 'error');
  } finally { isLoading.value = false; }
};

const handleSearch = () => { currentPage.value = 1; fetchInventory(); };

// UI Class Helpers (PRESERVED)
const getStockStatus = (item) => {
  if (item.quantity <= 0) return 'نفذ المخزون';
  if (item.min_quantity && item.quantity <= item.min_quantity) return 'رصيد منخفض';
  return 'متوفر';
};

const getStockStatusClass = (item) => {
  if (item.quantity <= 0) return 'status-red';
  if (item.min_quantity && item.quantity <= item.min_quantity) return 'status-amber';
  return 'status-emerald';
};

// Logic: Handlers (PRESERVED — handleAdjustStock و handleTransfer أُزيلا
// لأنهما كانا يفتحان Modals مكررة، استُبدلا بـ router-link في القالب.
// request-transfer emit أُزيل لأن النقل أصبح في صفحة إدارة المخزون مباشرةً)

// Watchers & Lifecycle (PRESERVED)
watch(() => props.branchId, (nv) => {
  if (nv) { currentPage.value = 1; fetchInventory(); }
}, { immediate: true });

onMounted(async () => { await fetchSettings(); });
</script>

<style scoped>
/* KPI Cards — matches ProductManagement */
.kpi-card { @apply bg-white p-4 rounded-lg shadow-sm border border-slate-200 transition-all duration-200; }
.kpi-icon { @apply w-10 h-10 rounded-lg flex items-center justify-center text-base; }
.kpi-label { @apply text-[10px] font-bold text-slate-500 mb-1; }
.kpi-value { @apply text-xl font-bold leading-none; }
.form-input-modern { @apply w-full bg-white border border-slate-200 rounded-md px-3 outline-none transition-all duration-200 focus:border-blue-500 focus:ring-2 focus:ring-blue-50 font-medium; }
.status-badge { @apply px-2.5 py-1 rounded-full text-[10px] font-bold; }

/* Status Classes */
.status-emerald { @apply bg-emerald-100 text-emerald-700; }
.status-amber { @apply bg-amber-100 text-amber-700; }
.status-red { @apply bg-rose-100 text-rose-700; }

.action-btn { @apply w-8 h-8 rounded-md flex items-center justify-center text-slate-400 transition-all active:scale-90; }
.pagination-btn { @apply w-8 h-8 rounded-md bg-white border border-slate-200 flex items-center justify-center hover:bg-slate-50 transition-all disabled:opacity-30 disabled:cursor-not-allowed shadow-sm; }

/* Custom Scrollbar */
div::-webkit-scrollbar { width: 5px; }
div::-webkit-scrollbar-thumb { @apply bg-slate-200 rounded-full; }

.animate-fadeIn { animation: fadeIn 0.4s ease-out; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
</style>