<template>
  <div class="min-h-screen bg-[#fafafa] text-slate-900 font-sans antialiased selection:bg-blue-100" dir="rtl">

    <!-- Top Progress Bar -->
    <PageLoadingBar :active="loading" />

    <div class="max-w-[1600px] mx-auto p-6 lg:p-10 space-y-8">

      <!-- Page Header -->
      <PageHeader
        :breadcrumb="breadcrumb"
        title="إدارة التصنيفات"
        description="إضافة وتصنيف منتجات كل فرع بشكل مستقل."
        :branches="branches"
        :selected-branch="selectedBranch"
        branch-selection-mode="required"
        @branch-changed="handleBranchChange"
      >
        <template #controls>
          <button
            v-if="canCreate"
            type="button"
            :disabled="!selectedBranch || loading"
            class="h-9 px-4 rounded-md bg-blue-600 text-white text-xs font-bold hover:bg-blue-700 transition-all shadow-sm flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed"
            @click="openCreate"
          >
            <i class="fas fa-plus text-[10px]"></i>
            إضافة تصنيف
          </button>
        </template>
      </PageHeader>

      <!-- KPI Cards -->
      <section class="grid grid-cols-1 sm:grid-cols-3 gap-6">
        <div v-for="kpi in [
          { label: 'تصنيفات الفرع',   val: categories.length,  icon: 'fa-tags',        color: 'text-blue-600',    bg: 'bg-blue-50' },
          { label: 'تصنيفات نشطة',   val: activeCount,          icon: 'fa-check-circle', color: 'text-emerald-600', bg: 'bg-emerald-50' },
          { label: 'منتجات مرتبطة',  val: productsCount,        icon: 'fa-box',          color: 'text-indigo-600',  bg: 'bg-indigo-50' },
        ]" :key="kpi.label"
          class="bg-white border border-slate-200 p-5 rounded-xl flex items-center justify-between group hover:border-slate-300 transition-all">
          <div>
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">{{ kpi.label }}</p>
            <p class="text-xl font-bold text-slate-900">{{ kpi.val }}</p>
          </div>
          <div :class="[kpi.bg, kpi.color]" class="w-10 h-10 rounded-lg flex items-center justify-center text-sm opacity-80 group-hover:opacity-100 transition-opacity">
            <i :class="['fas', kpi.icon]"></i>
          </div>
        </div>
      </section>

      <!-- Search Bar -->
      <transition name="slide-down">
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
          <div class="p-4">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
              <div class="space-y-1.5 md:col-span-2">
                <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">بحث سريع</label>
                <div class="relative">
                  <input
                    v-model="search"
                    type="search"
                    class="filter-input"
                    style="padding-right: 2rem;"
                    placeholder="ابحث باسم التصنيف أو الوصف..."
                    aria-label="بحث عن تصنيف"
                  />
                  <i class="fas fa-search absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px] pointer-events-none"></i>
                </div>
              </div>
              <div class="flex items-end">
                <button
                  v-if="search"
                  @click="search = ''"
                  class="h-9 w-full rounded-md bg-slate-100 text-slate-600 text-[11px] font-bold hover:bg-slate-200 transition-all"
                >
                  مسح البحث
                </button>
              </div>
            </div>
          </div>
        </div>
      </transition>

      <!-- Active Search Chip -->
      <div v-if="search" class="flex flex-wrap gap-2">
        <div class="inline-flex items-center gap-2 px-2.5 py-1 bg-blue-50 border border-blue-100 rounded-md text-[10px] font-bold text-blue-700">
          {{ search }}
          <i @click="search = ''" class="fas fa-times cursor-pointer hover:text-blue-900 opacity-60"></i>
        </div>
      </div>

      <!-- Main Data Table -->
      <div class="bg-white rounded-xl overflow-hidden shadow-sm border border-slate-200">

        <!-- Error Banner -->
        <div v-if="error" class="m-4 p-3 rounded-lg border-l-4 border-rose-400 bg-rose-50 flex items-center gap-3">
          <i class="fas fa-exclamation-circle text-rose-500"></i>
          <p class="text-sm text-rose-700 flex-1">{{ error }}</p>
          <button @click="loadCategories" class="px-3 py-1 bg-rose-600 text-white text-xs font-bold rounded hover:bg-rose-700 transition-colors">
            إعادة المحاولة
          </button>
        </div>

        <div class="overflow-x-auto">
          <table class="w-full text-right border-collapse">
            <thead>
              <tr class="bg-slate-50 border-b border-slate-200">
                <th class="px-5 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">اسم التصنيف</th>
                <th class="px-5 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">الفرع</th>
                <th class="px-5 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest hidden md:table-cell">الوصف</th>
                <th class="px-5 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">المنتجات</th>
                <th class="px-5 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">الحالة</th>
                <th v-if="canEdit || canDelete" class="px-5 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">إجراءات</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">

              <!-- Loading Skeleton -->
              <template v-if="loading">
                <tr v-for="n in 5" :key="n" class="animate-pulse">
                  <td v-for="m in (canEdit || canDelete ? 6 : 5)" :key="m" class="px-5 py-4">
                    <div class="h-3 bg-slate-100 rounded w-full"></div>
                  </td>
                </tr>
              </template>

              <!-- No Branch Selected -->
              <tr v-else-if="!selectedBranch">
                <td :colspan="(canEdit || canDelete) ? 6 : 5" class="py-20 text-center">
                  <i class="fas fa-building text-3xl mb-4 text-slate-200"></i>
                  <p class="text-xs font-bold uppercase tracking-widest text-slate-300">اختر فرعًا لعرض تصنيفاته</p>
                </td>
              </tr>

              <!-- Empty State -->
              <tr v-else-if="!filteredCategories.length">
                <td :colspan="(canEdit || canDelete) ? 6 : 5" class="py-20 text-center">
                  <i class="fas fa-tags text-3xl mb-4 text-slate-200"></i>
                  <p class="text-xs font-bold uppercase tracking-widest text-slate-300">
                    {{ search ? 'لا توجد نتائج مطابقة للبحث' : 'لا توجد تصنيفات لهذا الفرع بعد' }}
                  </p>
                  <button v-if="canCreate && !search" @click="openCreate"
                    class="mt-4 text-xs font-bold text-blue-600 hover:text-blue-700">
                    أضف أول تصنيف
                  </button>
                </td>
              </tr>

              <!-- Data Rows -->
              <tr v-else v-for="category in filteredCategories" :key="category.id"
                class="hover:bg-blue-50/20 transition-all group">
                <td class="px-5 py-3">
                  <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-lg bg-blue-50 flex items-center justify-center shrink-0">
                      <i class="fas fa-tag text-blue-500 text-[10px]"></i>
                    </div>
                    <span class="text-[11px] font-bold text-slate-800">{{ category.name }}</span>
                  </div>
                </td>
                <td class="px-5 py-3 text-[10px] font-bold text-slate-500">
                  <div class="flex items-center gap-1.5">
                    <i class="fas fa-building text-[9px] text-slate-300"></i>
                    {{ getBranchName(category.branch_id) }}
                  </div>
                </td>
                <td class="px-5 py-3 text-[10px] text-slate-500 max-w-[200px] truncate hidden md:table-cell">
                  {{ category.description || '—' }}
                </td>
                <td class="px-5 py-3 text-center">
                  <span class="px-2 py-0.5 bg-slate-100 rounded text-[9px] font-bold text-slate-500">
                    {{ Number(category.product_count || 0) }}
                  </span>
                </td>
                <td class="px-5 py-3 text-center">
                  <span :class="isActive(category)
                    ? 'bg-emerald-50 text-emerald-600 border-emerald-100'
                    : 'bg-slate-50 text-slate-400 border-slate-100'"
                    class="px-2 py-0.5 rounded text-[8px] font-bold border">
                    {{ isActive(category) ? 'نشط' : 'غير نشط' }}
                  </span>
                </td>
                <td v-if="canEdit || canDelete" class="px-5 py-3 text-center">
                  <div class="flex items-center justify-center gap-2">
                    <button
                      v-if="canEdit"
                      @click="openEdit(category)"
                      class="w-8 h-8 rounded-lg border border-slate-200 text-slate-400 hover:text-blue-600 hover:border-blue-200 transition-all flex items-center justify-center"
                      :aria-label="`تعديل ${category.name}`"
                    >
                      <i class="fas fa-pen text-[10px]"></i>
                    </button>
                    <button
                      v-if="canDelete"
                      @click="deleteCategory(category)"
                      :disabled="hasReferences(category) || Number(category.child_count || 0) > 0 || deletingId === category.id"
                      :title="Number(category.child_count || 0) > 0
                        ? 'احذف التصنيفات الفرعية أولاً'
                        : hasReferences(category) ? 'انقل المنتجات المرتبطة قبل الحذف' : 'حذف'"
                      class="w-8 h-8 rounded-lg border border-slate-200 text-slate-400 hover:text-rose-600 hover:border-rose-200 transition-all flex items-center justify-center disabled:opacity-30 disabled:cursor-not-allowed"
                      :aria-label="`حذف ${category.name}`"
                    >
                      <i class="fas fa-trash text-[10px]"></i>
                    </button>
                  </div>
                </td>
              </tr>

            </tbody>
          </table>
        </div>

        <!-- Table Footer -->
        <div class="px-6 py-4 bg-slate-50/50 border-t border-slate-200 flex items-center justify-between">
          <div class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">
            إجمالي <span class="text-slate-900">{{ filteredCategories.length }}</span> تصنيف
            <span v-if="search" class="mx-2 text-slate-200">|</span>
            <span v-if="search" class="text-slate-500">نتائج البحث عن: "{{ search }}"</span>
          </div>
          <div v-if="canCreate && selectedBranch">
            <button
              @click="openCreate"
              class="h-8 px-3 rounded-md border border-blue-200 text-blue-700 text-[10px] font-bold hover:bg-blue-50 transition-all flex items-center gap-1.5"
            >
              <i class="fas fa-plus text-[9px]"></i> إضافة تصنيف
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- ═══════════════════════════════════════════════════
         نافذة الإنشاء / التعديل
    ═══════════════════════════════════════════════════ -->
    <HistoryDetailsModal
      :show="modalOpen"
      @close="closeModal"
      :title="editingCategory ? 'تعديل التصنيف' : 'إضافة تصنيف'"
      :icon="editingCategory ? 'fas fa-pen' : 'fas fa-plus'"
      maxWidth="lg"
    >
      <form class="space-y-4" @submit.prevent="saveCategory" id="category-form">
        <label class="block space-y-1.5">
          <span class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest">
            اسم التصنيف <span class="text-rose-500">*</span>
          </span>
          <input
            v-model.trim="form.name"
            type="text"
            maxlength="100"
            required
            autofocus
            class="filter-input"
            placeholder="مثال: الهواتف"
          />
        </label>

        <label class="block space-y-1.5">
          <span class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest">الوصف</span>
          <textarea
            v-model.trim="form.description"
            rows="3"
            class="filter-input h-24 py-2 resize-none"
            placeholder="وصف مختصر للتصنيف (اختياري)"
          ></textarea>
        </label>

        <p class="text-[10px] text-slate-400">سيُحفظ التصنيف ضمن الفرع المحدد فقط.</p>
      </form>

      <template #footer>
        <div class="mr-auto flex items-center gap-3">
          <button
            type="button"
            :disabled="saving"
            @click="closeModal"
            class="inline-flex h-10 items-center justify-center px-4 text-xs font-bold text-slate-500 hover:text-slate-800 transition-colors"
          >
            إلغاء
          </button>
          <LoadingButton
            type="submit"
            form="category-form"
            :text="editingCategory ? 'حفظ التعديلات' : 'حفظ التصنيف'"
            loadingText="جارٍ الحفظ..."
            :isLoading="saving"
            :disabled="saving || !form.name"
            class="h-10 rounded-md px-5 text-xs"
          />
        </div>
      </template>
    </HistoryDetailsModal>

  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import LoadingButton from '@/components/ui/LoadingButton.vue';
import HistoryDetailsModal from '@/components/HistoryDetailsModal.vue';
import AlertService from '@/services/AlertService';
import { useToast } from '@/composables/useToast';
import { useBreadcrumb } from '@/composables/useBreadcrumb';
import { useBranchIsolation } from '@/composables/useBranchIsolation';
import { useRequiredBranch } from '@/composables/useRequiredBranch';
import { useAuthStore } from '@/stores/auth/authStore';
import { useBranchStore } from '@/stores/branch/branchStore';
import { useCatalogStore } from '@/stores/catalog/catalogStore';

// ─── Stores & Composables ─────────────────────────────────────────────────────
const { breadcrumb }                        = useBreadcrumb();
const { showToast }                         = useToast();
const authStore                             = useAuthStore();
const branchStore                           = useBranchStore();
const catalogStore                          = useCatalogStore();
const branchIsolation                       = useBranchIsolation();
const { ensureBranchSelected, setRequiredBranch } = useRequiredBranch();

// ─── Computed ─────────────────────────────────────────────────────────────────
const branches       = computed(() => branchStore.branches);
const selectedBranch = computed(() =>
  branchIsolation.isExempt.value
    ? branchStore.selectedBranchId
    : authStore.user?.branch_id ?? null
);
const categories = computed(() =>
  selectedBranch.value ? catalogStore.getCategoriesForBranch(selectedBranch.value) : []
);
const permissions  = computed(() => authStore.user?.permissions || []);
const canCreate    = computed(() => authStore.isSuperAdmin || permissions.value.includes('category.create'));
const canEdit      = computed(() => authStore.isSuperAdmin || permissions.value.includes('category.edit'));
const canDelete    = computed(() => authStore.isSuperAdmin || permissions.value.includes('category.delete'));
const activeCount  = computed(() => categories.value.filter(isActive).length);
const productsCount = computed(() =>
  categories.value.reduce((total, c) => total + Number(c.product_count || 0), 0)
);
const filteredCategories = computed(() => {
  const q = search.value.trim().toLocaleLowerCase();
  if (!q) return categories.value;
  return categories.value.filter(c =>
    `${c.name || ''} ${c.description || ''}`.toLocaleLowerCase().includes(q)
  );
});

// ─── State ────────────────────────────────────────────────────────────────────
const search           = ref('');
const loading          = ref(false);
const error            = ref('');
const modalOpen        = ref(false);
const editingCategory  = ref(null);
const saving           = ref(false);
const deletingId       = ref(null);
const branchContextReady = ref(false);
const form             = reactive({ name: '', description: '' });

// ─── Helpers ──────────────────────────────────────────────────────────────────
function isActive(category) {
  return category.active !== 0 && category.active !== '0' && category.active !== false;
}
function hasReferences(category) {
  return Number(category.product_count || 0) > 0 || Number(category.purchase_count || 0) > 0;
}
function getBranchName(branchId) {
  const branch = branches.value.find(b => String(b.id) === String(branchId));
  return branch?.name || `فرع #${branchId}`;
}

// ─── Branch ───────────────────────────────────────────────────────────────────
function handleBranchChange(branchId) {
  if (!setRequiredBranch(branchId)) return;
  search.value = '';
}

// ─── API ──────────────────────────────────────────────────────────────────────
async function loadCategories() {
  if (!selectedBranch.value) { error.value = 'يرجى تحديد فرع لعرض تصنيفاته.'; return; }
  loading.value = true;
  error.value = '';
  try {
    await catalogStore.fetchCategories(selectedBranch.value, { force: true });
  } catch (err) {
    error.value = err.response?.data?.message || err.message || 'فشل تحميل التصنيفات.';
    showToast(error.value, 'error');
  } finally {
    loading.value = false;
  }
}

// ─── Modal ────────────────────────────────────────────────────────────────────
function resetForm() { form.name = ''; form.description = ''; }

function openCreate() {
  editingCategory.value = null;
  resetForm();
  modalOpen.value = true;
}

function openEdit(category) {
  editingCategory.value = category;
  form.name = category.name || '';
  form.description = category.description || '';
  modalOpen.value = true;
}

function closeModal() {
  if (saving.value) return;
  modalOpen.value = false;
  editingCategory.value = null;
}

async function saveCategory() {
  if (!selectedBranch.value || !form.name.trim()) return;
  saving.value = true;
  try {
    const payload = {
      name: form.name.trim(),
      description: form.description.trim(),
      branch_id: selectedBranch.value,
    };
    if (editingCategory.value) {
      await catalogStore.updateCategory(editingCategory.value.id, payload);
    } else {
      await catalogStore.createCategory(payload);
    }
    const wasEditing = Boolean(editingCategory.value);
    modalOpen.value = false;
    editingCategory.value = null;
    showToast(wasEditing ? 'تم تحديث التصنيف بنجاح.' : 'تمت إضافة التصنيف بنجاح.', 'success');
    await loadCategories();
  } catch (err) {
    showToast(err.response?.data?.message || err.message || 'تعذر حفظ التصنيف.', 'error');
  } finally {
    saving.value = false;
  }
}

async function deleteCategory(category) {
  if (hasReferences(category) || Number(category.child_count || 0) > 0) {
    showToast('لا يمكن حذف تصنيف مرتبط بمنتجات أو مشتريات أو تصنيفات فرعية.', 'warning');
    return;
  }
  const confirmed = await AlertService.confirm(
    `سيتم حذف تصنيف "${category.name}" من هذا الفرع فقط. هل تريد المتابعة؟`,
    'حذف التصنيف'
  );
  if (!confirmed) return;
  deletingId.value = category.id;
  try {
    await catalogStore.deleteCategory(category.id, selectedBranch.value);
    showToast('تم حذف التصنيف بنجاح.', 'success');
    await loadCategories();
  } catch (err) {
    showToast(err.response?.data?.message || err.message || 'تعذر حذف التصنيف.', 'error');
  } finally {
    deletingId.value = null;
  }
}

// ─── Watchers & Mount ─────────────────────────────────────────────────────────
watch(selectedBranch, async (newBranch, oldBranch) => {
  if (!branchContextReady.value || String(newBranch || '') === String(oldBranch || '')) return;
  search.value = '';
  await loadCategories();
});

onMounted(async () => {
  await branchStore.initialize();
  if (branchIsolation.isExempt.value) {
    await ensureBranchSelected();
  } else if (authStore.user?.branch_id) {
    setRequiredBranch(authStore.user.branch_id);
  }
  branchContextReady.value = true;
  await loadCategories();
});
</script>

<style scoped>
@keyframes loading {
  0%   { transform: translateX(100%); }
  100% { transform: translateX(-100%); }
}

.filter-input {
  @apply h-9 w-full bg-white border border-slate-200 rounded-md px-3 text-[11px] font-bold text-slate-700 focus:ring-2 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all;
}

.slide-down-enter-active,
.slide-down-leave-active {
  transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
}
.slide-down-enter-from,
.slide-down-leave-to {
  opacity: 0;
  transform: translateY(-10px);
}
</style>
