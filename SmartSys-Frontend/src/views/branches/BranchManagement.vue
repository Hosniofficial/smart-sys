<template>
  <div class="min-h-screen bg-[#fafafa] text-slate-900 font-sans antialiased selection:bg-blue-100" dir="rtl">
    
    <!-- Top Progress Bar -->
    <PageLoadingBar :active="isLoading" />

    <div class="max-w-[1600px] mx-auto p-6 lg:p-10 space-y-8">
      
      <!-- Page Header -->
      <PageHeader
        :breadcrumb="breadcrumb"
        title="الفروع والمستودعات"
        description="أدر فروع المؤسسة ومستودعاتها، وتابع حالة التشغيل لكل موقع."
        :branches="[]"
        :selectedBranch="null"
      >
        <template #controls>
          <button @click="openAddModal" class="h-9 px-4 rounded-md bg-blue-600 text-white text-xs font-bold shadow-sm hover:bg-blue-700 transition-all active:scale-95 flex items-center gap-2">
            <i class="fas fa-plus text-[10px]"></i>
            إضافة فرع جديد
          </button>
        </template>
      </PageHeader>

      <!-- Overview KPIs: Metric Blocks -->
      <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
        <div v-for="kpi in [
          { label: 'إجمالي الفروع', val: branches.length, icon: 'fa-layer-group', color: 'text-blue-600', bg: 'bg-blue-50' },
          { label: 'الفروع النشطة', val: branches.filter(w => w.active === 1 || w.active === true).length, icon: 'fa-check-circle', color: 'text-emerald-600', bg: 'bg-emerald-50' },
          { label: 'الفروع المعطلة', val: branches.filter(w => w.active !== 1 && w.active !== true).length, icon: 'fa-eye-slash', color: 'text-slate-400', bg: 'bg-slate-50' }
        ]" :key="kpi.label" class="bg-white border border-slate-200 p-5 rounded-xl flex items-center justify-between group hover:border-slate-300 transition-all shadow-sm">
          <div>
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">{{ kpi.label }}</p>
            <p class="text-xl font-bold text-slate-900 font-mono tracking-tighter">{{ kpi.val }}</p>
          </div>
          <div :class="[kpi.bg, kpi.color]" class="w-10 h-10 rounded-lg flex items-center justify-center text-sm opacity-80 group-hover:opacity-100 transition-opacity shadow-inner">
            <i :class="['fas', kpi.icon]"></i>
          </div>
        </div>
      </section>

      <!-- Toolbar: Utility Bar -->
      <section class="bg-white border border-slate-200 rounded-xl p-5 flex flex-col md:flex-row md:items-center justify-between gap-6 shadow-sm">
        <div class="relative flex-grow group max-w-xl">
          <input 
            type="text" 
            v-model="searchQuery" 
            class="h-9 w-full bg-white border border-slate-200 rounded-md pr-9 pl-4 text-xs font-bold text-slate-700 focus:ring-2 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all" 
            placeholder="ابحث باسم الفرع أو الموقع الجغرافي..."
          />
          <i class="fas fa-search absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px]"></i>
        </div>
        
        <button @click="exportBranches" class="h-9 px-4 rounded-md border border-slate-200 bg-white text-slate-500 text-[10px] font-bold uppercase tracking-widest hover:text-emerald-600 hover:border-emerald-200 transition-all flex items-center justify-center gap-2">
          <i class="fas fa-file-export"></i>
          <span>تصدير البيانات</span>
        </button>
      </section>
        
      <!-- Main Table Card -->
      <div class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm relative">
        
        <!-- 1. Loading State -->
        <div v-if="isLoading" class="p-8 space-y-4">
          <div v-for="i in 5" :key="i" class="flex gap-4 items-center py-4 border-b border-slate-50 animate-pulse">
            <div class="w-10 h-10 bg-slate-100 rounded-lg"></div>
            <div class="flex-grow space-y-2">
              <div class="h-3 bg-slate-100 rounded w-1/3"></div>
              <div class="h-2 bg-slate-50 rounded w-1/4"></div>
            </div>
            <div class="h-3 bg-slate-100 rounded w-20"></div>
            <div class="w-20 h-6 bg-slate-100 rounded-full"></div>
          </div>
        </div>

        <!-- 2. Error State -->
        <div v-else-if="error" class="py-24 text-center px-6">
          <div class="w-16 h-16 bg-rose-50 text-rose-500 rounded-2xl flex items-center justify-center mx-auto mb-4 shadow-inner">
            <i class="fas fa-exclamation-triangle text-xl"></i>
          </div>
          <h3 class="text-sm font-bold text-slate-900">{{ error }}</h3>
          <button @click="fetchBranches" class="mt-4 px-6 py-2 bg-slate-900 text-white rounded-md font-bold text-xs hover:bg-black transition-all">
            إعادة المحاولة
          </button>
        </div>

        <!-- 3. Data Table -->
        <template v-else>
          <div class="overflow-x-auto">
            <table class="w-full text-right border-collapse">
              <thead>
                <tr class="bg-slate-50/50 border-b border-slate-200">
                  <th class="px-6 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">الفرع / المستودع</th>
                  <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">الموقع الجغرافي</th>
                  <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">حالة التشغيل</th>
                  <th class="px-8 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">الإجراءات</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100 font-medium">
                <tr v-if="paginatedBranches.length === 0 && !isLoading" class="text-center">
                  <td colspan="4" class="py-24 text-slate-300">
                    <i class="fas fa-building text-3xl mb-4 opacity-20"></i>
                    <p class="text-xs font-bold uppercase tracking-widest">لا توجد فروع مسجلة</p>
                  </td>
                </tr>
                <tr v-for="branch in paginatedBranches" :key="branch.id" class="hover:bg-blue-50/10 transition-all group">
                  <td class="px-6 py-4">
                    <div class="flex items-center gap-3">
                      <div class="w-9 h-9 rounded-lg bg-slate-50 flex items-center justify-center text-slate-400 border border-slate-100 group-hover:bg-white group-hover:border-blue-100 transition-colors">
                        <i class="fas fa-store-alt text-xs"></i>
                      </div>
                      <div class="flex flex-col">
                        <span class="text-xs font-bold text-slate-900 leading-none group-hover:text-blue-600 transition-colors">{{ branch.name }}</span>
                        <span class="text-[9px] font-bold text-slate-400 font-mono mt-1 uppercase tracking-widest">BR-{{ branch.id }}</span>
                      </div>
                    </div>
                  </td>
                  <td class="px-4 py-4">
                    <div class="flex items-center gap-2 text-[11px] font-bold text-slate-500">
                      <i class="fas fa-map-marker-alt text-[9px] text-slate-300"></i>
                      <span>{{ branch.location || 'غير محدد' }}</span>
                    </div>
                  </td>
                  <td class="px-4 py-4 text-center">
                    <span :class="[(branch.active === 1 || branch.active === true) ? 'text-emerald-600 bg-emerald-50 border-emerald-100' : 'text-slate-400 bg-slate-50 border-slate-100']" class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[9px] font-bold border">
                      <span class="w-1 h-1 rounded-full bg-current"></span>
                      {{ (branch.active === 1 || branch.active === true) ? 'نشط' : 'معطّل' }}
                    </span>
                  </td>
                  <td class="px-8 py-4 text-center">
                    <div class="flex items-center justify-center gap-1.5">
                      <router-link :to="`/branches/${branch.id}`" class="w-8 h-8 rounded-lg border border-slate-200 text-slate-400 hover:text-blue-600 hover:border-blue-200 transition-all flex items-center justify-center shadow-sm">
                        <i class="fas fa-eye text-[10px]"></i>
                      </router-link>
                      <button @click="openEditModal(branch)" class="w-8 h-8 rounded-lg border border-slate-200 text-slate-400 hover:text-emerald-600 hover:border-emerald-200 transition-all flex items-center justify-center shadow-sm">
                        <i class="fas fa-edit text-[10px]"></i>
                      </button>
                      <button @click="handleDelete(branch.id)" class="w-8 h-8 rounded-lg border border-slate-200 text-slate-400 hover:text-rose-600 hover:border-rose-200 transition-all flex items-center justify-center shadow-sm">
                        <i class="fas fa-trash text-[10px]"></i>
                      </button>
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
              إجمالي <span class="text-slate-900">{{ filteredBranches.length }}</span> فرع
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
               <PaginationControls
                 :page="filters.page.value"
                 :total-pages="filters.totalPages.value"
                 @previous="filters.previousPage()"
                 @next="filters.nextPage(filters.totalPages.value)"
               />
            </div>
          </div>
        </template>
      </div>
    </div>

    <!-- Branch Form Modal -->
    <BaseModal :show="showFormModal" @close="showFormModal = false" maxWidth="lg">
      <template #header>
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 bg-slate-900 rounded-lg flex items-center justify-center text-white shadow-lg shadow-slate-200">
            <i :class="[selectedBranch ? 'fas fa-edit' : 'fas fa-plus', 'text-xs']"></i>
          </div>
          <div>
            <h3 class="text-sm font-bold text-slate-900 uppercase tracking-tight">{{ selectedBranch ? 'تحديث بيانات الفرع / المستودع' : 'إضافة فرع / مستودع' }}</h3>
            <p class="text-[10px] text-slate-400 font-bold uppercase tracking-[0.2em] mt-0.5">إدارة وتعديل الفروع والمستودعات</p>
			</div>
        </div>
      </template>
      <BranchForm :branch="selectedBranch" @success="handleFormSuccess" @cancel="showFormModal = false" />
    </BaseModal>
  </div>
</template>

<script setup>
import { ref, onMounted, computed, watch } from 'vue';
import BaseModal from '@/components/BaseModal.vue';
import { useToast } from '@/composables/useToast';
import BaseSkeleton from '@/components/ui/BaseSkeleton.vue';

import BranchForm from '@/components/BranchForm.vue';
import { useBranchStore } from '@/stores/branch/branchStore';
import AlertService from '@/services/AlertService';
import { useBreadcrumb } from '@/composables/useBreadcrumb';
import { useTableFilters } from '@/composables/useTableFilters';
import PageHeader from '@/components/PageHeader.vue';
import PaginationControls from '@/components/ui/PaginationControls.vue';

const { showToast } = useToast();
const { breadcrumb } = useBreadcrumb();
const branchStore = useBranchStore();

const branches = computed(() => branchStore.branches);

// ─── Pagination (using useTableFilters composable)
const filters = useTableFilters('branches_filters');

const showFormModal = ref(false);
const selectedBranch = ref(null);
const isLoading = ref(false);
const error = ref(null);
const searchQuery = ref('');
const currentPage = ref(1);
const itemsPerPage = ref(10);

const fetchBranches = async () => {
  isLoading.value = true;
  error.value = null;
  try {
    await branchStore.fetchBranches();
  } catch (err) {
    error.value = 'حدث خطأ أثناء تحميل البيانات. يرجى المحاولة مرة أخرى.';
    showToast(err.response?.data?.message || 'فشل في تحميل البيانات', 'error');
  } finally {
    isLoading.value = false;
  }
};

const exportBranches = () => {
  showToast('سيتم تنفيذ عملية التصدير قريباً', 'info');
};

const filteredBranches = computed(() => {
  let result = branches.value;
  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase();
    result = result.filter(w => 
      w.name.toLowerCase().includes(query) ||
      (w.location && w.location.toLowerCase().includes(query))
    );
  }
  return result;
});


const paginatedBranches = computed(() => {
  filters.totalCount.value = filteredBranches.value.length;
  const start = (filters.page.value - 1) * filters.perPage.value;
  const end = start + filters.perPage.value;
  return filteredBranches.value.slice(start, end);
});

watch(searchQuery, () => filters.page.value = 1);
onMounted(() => fetchBranches());

const openAddModal = () => { selectedBranch.value = null; showFormModal.value = true; };
const openEditModal = (branch) => { selectedBranch.value = { ...branch }; showFormModal.value = true; };
const handleFormSuccess = () => { showFormModal.value = false; fetchBranches(); };
const goToPage = (page) => { if (page >= 1 && page <= filters.totalPages.value) filters.page.value = page; };

const handleDelete = async (branchId) => {
  if (await AlertService.confirm('هل أنت متأكد من حذف هذا الفرع؟ سيتم تعطيله في النظام.', 'حذف الفرع')) {
    try {
      const response = await branchStore.deleteBranch(branchId);
      if (response.status === 'success') {
        showToast('تم حذف الفرع بنجاح', 'success');
      } else {
        showToast(response.message || 'فشل في حذف الفرع', 'error');
      }
    } catch (error) {
      showToast(error.response?.data?.message || 'فشل في حذف الفرع', 'error');
    }
  }
};
</script>

<style scoped>
@keyframes loading { 0% { transform: translateX(100%); } 100% { transform: translateX(-100%); } }

.animate-fadeIn { animation: fadeIn 0.4s ease-out; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

@keyframes modalIn { from { opacity: 0; transform: scale(0.98) translateY(10px); } to { opacity: 1; transform: scale(1) translateY(0); } }
.animate-modalIn { animation: modalIn 0.2s cubic-bezier(0.16, 1, 0.3, 1); }

.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>