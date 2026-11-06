<template>
  <div class="min-h-screen bg-[#fafafa] text-slate-900 font-sans antialiased selection:bg-blue-100" dir="rtl">

    <PageLoadingBar :active="isLoading || isSearching" />

    <div class="max-w-[1600px] mx-auto p-6 lg:p-10 space-y-8">

      <PageHeader
        :breadcrumb="{ parent: { label: 'المبيعات', path: '/sales/pos' }, current: { label: 'حملات الأسعار', path: '/sales/price-campaigns' } }"
        title="حملات الأسعار"
        description="حدد أسعاراً ترويجية للمنتجات خلال فترة زمنية، وستطبق تلقائياً عند البيع."
        :branches="[]"
        :selected-branch="null"
      >
        <template #controls>
          <button
            @click="showForm = true"
            class="h-9 px-4 rounded-md bg-blue-600 text-white text-xs font-bold hover:bg-blue-700 transition-all flex items-center gap-2 shadow-sm"
          >
            <i class="fas fa-plus"></i>
            إنشاء حملة
          </button>
        </template>
      </PageHeader>

      <section class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-6">
        <div v-for="kpi in [
          { label: 'إجمالي الحملات', val: total, icon: 'fa-chart-line', color: 'text-blue-600', bg: 'bg-blue-50', show: true },
          { label: 'حملات نشطة', val: activeCampaignsCount, icon: 'fa-play-circle', color: 'text-emerald-600', bg: 'bg-emerald-50', show: true },
          { label: 'حملات متوقفة', val: pausedCampaignsCount, icon: 'fa-pause-circle', color: 'text-amber-600', bg: 'bg-amber-50', show: true },
          { label: 'إجمالي المنتجات', val: totalProductsInCampaigns, icon: 'fa-box', color: 'text-indigo-600', bg: 'bg-indigo-50', show: true }
        ].filter(k => k.show)" :key="kpi.label" class="bg-white border border-slate-200 p-5 rounded-xl flex items-center justify-between group hover:border-slate-300 transition-all">
          <div>
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">{{ kpi.label }}</p>
            <p class="text-xl font-bold text-slate-900">{{ kpi.val }}</p>
          </div>
          <div :class="[kpi.bg, kpi.color]" class="w-10 h-10 rounded-lg flex items-center justify-center text-sm opacity-80 group-hover:opacity-100 transition-opacity">
            <i :class="['fas', kpi.icon]"></i>
          </div>
        </div>
      </section>

      <HistoryDetailsModal
        :show="showForm"
        @close="showForm = false"
        title="إنشاء حملة أسعار جديدة"
        subtitle="حدد بيانات الحملة والمنتجات والأسعار الترويجية"
        icon="fas fa-tags"
        maxWidth="4xl"
      >
            <form id="campaign-create-form" class="space-y-5" @submit.prevent="createCampaign">
              <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <label class="space-y-1.5">
                  <span class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest">اسم الحملة <span class="text-rose-500">*</span></span>
                  <input v-model.trim="form.name" required maxlength="160" class="filter-input" placeholder="مثال: افتتاح الفرع الجديد" />
                </label>
                <label class="space-y-1.5">
                  <span class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest">نوع الحملة</span>
                  <select v-model="form.campaignType" class="filter-input">
                    <option value="opening">افتتاح</option>
                    <option value="clearance">تصفية مخزون</option>
                    <option value="launch">إطلاق منتج</option>
                    <option value="custom">مخصصة</option>
                  </select>
                </label>
                <label class="space-y-1.5">
                  <span class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest">الفرع</span>
                  <select v-model="form.branchId" class="filter-input">
                    <option value="">كل الفروع</option>
                    <option v-for="branch in branches" :key="branch.id" :value="String(branch.id)">{{ branch.name }}</option>
                  </select>
                </label>
                <label class="space-y-1.5">
                  <span class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest">الأولوية</span>
                  <select v-model="priorityPreset" class="filter-input">
                    <option value="100">قصوى (100)</option>
                    <option value="50">عالية (50)</option>
                    <option value="0">عادية (0)</option>
                    <option value="-50">منخفضة (-50)</option>
                    <option value="-100">الأدنى (-100)</option>
                    <option value="custom">قيمة مخصصة</option>
                  </select>
                  <input
                    v-if="priorityPreset === 'custom'"
                    v-model.number="form.priority"
                    type="number"
                    min="-100"
                    max="100"
                    step="1"
                    class="filter-input mt-2"
                    placeholder="من -100 إلى 100"
                  />
                  <span class="block text-[9px] font-medium text-slate-400">القيمة الأكبر تُطبق أولًا عند تعارض الحملات.</span>
                </label>
                <label class="space-y-1.5">
                  <span class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest">تاريخ البداية</span>
                  <input v-model="form.startDate" type="date" required class="filter-input" />
                </label>
                <label class="space-y-1.5">
                  <span class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest">تاريخ النهاية</span>
                  <input v-model="form.endDate" type="date" required :min="form.startDate" class="filter-input" />
                </label>
              </div>

              <div class="rounded-lg border border-slate-100 bg-slate-50 p-4 space-y-4">
                <div class="mb-3 flex flex-col gap-2 sm:flex-row">
                  <input
                    v-model="productQuery"
                    class="h-10 min-w-0 flex-1 rounded-lg border border-slate-200 px-3 text-sm outline-none focus:border-blue-400"
                    placeholder="ابحث عن منتج بالاسم أو الباركود..."
                    @keydown.enter.prevent="searchProducts"
                  />
                  <button type="button" class="h-10 rounded-lg border border-slate-200 bg-white px-4 text-xs font-bold text-slate-700 disabled:opacity-50" :disabled="isSearching || productQuery.trim().length < 2" @click="searchProducts">
                    <i class="fas fa-search ml-1"></i>
                    بحث عن منتج
                  </button>
                </div>
                <div v-if="productError" role="alert" class="mb-3 text-xs font-bold text-rose-700">{{ productError }}</div>
                <div v-if="productResults.length" class="mb-4 max-h-56 overflow-auto rounded-lg border border-slate-200 bg-white">
                  <button
                    v-for="product in productResults"
                    :key="product.id"
                    type="button"
                    class="flex w-full items-center justify-between gap-3 border-b border-slate-100 px-3 py-2 text-right last:border-0 hover:bg-blue-50 disabled:opacity-40"
                    :disabled="isSelected(product.id)"
                    @click="addProduct(product)"
                  >
                    <span class="min-w-0">
                      <span class="block truncate text-sm font-bold text-slate-800">{{ product.name }}</span>
                      <span class="text-[10px] text-slate-500">{{ product.productCode || product.barcode || 'بدون كود' }} · {{ product.unitName || 'قطعة' }}</span>
                    </span>
                    <span class="shrink-0 text-xs font-bold text-slate-600">{{ formatPrice(product.salePrice) }}</span>
                  </button>
                </div>

                <div v-if="!form.items.length" class="rounded-lg border border-dashed border-slate-300 px-4 py-6 text-center text-xs text-slate-500">
                  ابحث عن المنتجات وأضفها إلى الحملة.
                </div>
                <div v-else class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
                  <table class="w-full min-w-[560px] text-right text-xs">
                    <thead class="bg-slate-50 text-[10px] font-bold text-slate-500 border-b border-slate-100">
                      <tr>
                        <th class="px-3 py-3">المنتج</th>
                        <th class="px-3 py-3">السعر الحالي</th>
                        <th class="px-3 py-3">سعر الحملة</th>
                        <th class="w-12 px-3 py-3"></th>
                      </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                      <tr v-for="item in form.items" :key="`${item.productId}:${item.unitId}`">
                        <td class="px-3 py-2 font-bold text-slate-800">{{ item.name }} <span class="font-normal text-slate-400">({{ item.unitName }})</span></td>
                        <td class="px-3 py-2 font-mono text-slate-500">{{ formatPrice(item.regularPrice) }}</td>
                        <td class="px-3 py-2">
                          <input v-model.number="item.campaignPrice" type="number" min="0.01" step="0.01" :max="item.regularPrice" required class="h-8 w-28 rounded border border-slate-200 px-2 font-mono text-sm focus:border-blue-400" />
                        </td>
                        <td class="px-3 py-2">
                          <button type="button" class="rounded p-2 text-rose-600 hover:bg-rose-50" title="إزالة المنتج" @click="removeProduct(item.productId)">
                            <i class="fas fa-trash"></i>
                          </button>
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>

              <div v-if="formError" role="alert" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-bold text-rose-700">{{ formError }}</div>

            </form>
            <template #footer>
              <div class="mr-auto flex items-center gap-3">
                <button
                  type="button"
                  class="inline-flex h-10 items-center justify-center px-4 text-xs font-bold text-slate-500 hover:text-slate-800 transition-colors"
                  :disabled="isSaving"
                  @click="showForm = false"
                >
                  إلغاء
                </button>
                <LoadingButton
                  type="submit"
                  form="campaign-create-form"
                  text="حفظ الحملة"
                  loadingText="جارٍ الحفظ..."
                  :isLoading="isSaving"
                  :disabled="!form.items.length"
                  class="h-10 rounded-lg px-5 text-xs"
                />
              </div>
            </template>
      </HistoryDetailsModal>

      <div class="flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:flex-row">
        <input v-model="search" class="h-10 flex-1 rounded-lg border border-slate-200 px-3 text-sm outline-none focus:border-blue-400" placeholder="ابحث باسم الحملة..." @keydown.enter="loadCampaigns(1)" />
        <select v-model="statusFilter" class="h-9 rounded-md border border-slate-200 px-3 text-[11px] font-bold text-slate-700 outline-none focus:ring-2 focus:ring-blue-500/10 focus:border-blue-500" @change="loadCampaigns(1)">
          <option value="">كل الحالات</option>
          <option value="active">نشطة</option>
          <option value="paused">متوقفة</option>
        </select>
        <button
          type="button"
          :disabled="isLoading"
          :aria-busy="isLoading"
          aria-label="تحديث الحملات"
          title="تحديث الحملات"
          class="h-9 w-9 rounded-md border border-slate-200 bg-white p-0 text-slate-500 shadow-sm transition-all hover:border-blue-200 hover:bg-blue-50 hover:text-blue-600 active:scale-95 disabled:cursor-not-allowed disabled:opacity-60"
          @click="loadCampaigns(1)"
        >
          <i class="fas fa-sync-alt text-xs transition-transform duration-500" :class="{ 'animate-spin': isLoading }" aria-hidden="true"></i>
        </button>
      </div>

      <div v-if="error" role="alert" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-bold text-rose-700">{{ error }}</div>

      <div class="bg-white rounded-xl overflow-hidden shadow-sm border border-slate-200">
        <div class="overflow-x-auto">
          <table class="w-full text-right border-collapse">
            <thead>
              <tr class="bg-slate-50 border-b border-slate-200">
                <th class="px-5 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">الحملة</th>
                <th class="px-5 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">النوع</th>
                <th class="px-5 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">النطاق</th>
                <th class="px-5 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">الفترة</th>
                <th class="px-5 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">المنتجات</th>
                <th class="px-5 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center" title="القيمة الأكبر تعني أولوية أعلى عند تعارض الحملات">الأولوية</th>
                <th class="px-5 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">الحالة</th>
                <th class="px-5 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">الإجراء</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <template v-if="isLoading">
                <tr v-for="n in 5" :key="n" class="animate-pulse">
                  <td v-for="m in 8" :key="m" class="px-5 py-4"><div class="h-3 bg-slate-100 rounded w-full"></div></td>
                </tr>
              </template>
              <tr v-else-if="!campaigns.length" class="hover:bg-blue-50/20 transition-all">
                <td colspan="8" class="py-20 text-center text-slate-300">
                   <i class="fas fa-chart-line text-3xl mb-4 opacity-20"></i>
                   <p class="text-xs font-bold uppercase tracking-widest">لا توجد حملات سعرية حتى الآن</p>
                </td>
              </tr>
              <tr v-for="campaign in campaigns" :key="campaign.id" class="hover:bg-blue-50/20 transition-all group">
                <td class="px-5 py-3 font-bold text-slate-900 text-[11px]">{{ campaign.name }}</td>
                <td class="px-5 py-3 text-slate-600 text-[11px]">{{ typeLabels[campaign.campaignType] || campaign.campaignType }}</td>
                <td class="px-5 py-3 text-slate-600 text-[11px]">{{ campaign.branchName || 'كل الفروع' }}</td>
                <td class="px-5 py-3 font-mono text-[10px] text-slate-500">{{ formatDate(campaign.startDate) }} — {{ formatDate(campaign.endDate) }}</td>
                <td class="px-5 py-3">
                  <span class="px-2 py-0.5 bg-slate-100 rounded text-[9px] font-bold text-slate-500">{{ Number(campaign.productCount) || 0 }}</span>
                </td>
                <td class="px-5 py-3 text-center">
                  <span
                    :class="priorityClass(campaign.priority)"
                    class="inline-flex items-center gap-1 rounded-full border px-2.5 py-1 text-[9px] font-bold"
                    :title="`الأولوية ${Number(campaign.priority) || 0}: القيمة الأكبر تُطبق أولًا عند تعارض الحملات`"
                  >
                    <i :class="priorityIcon(campaign.priority)" class="text-[8px]" aria-hidden="true"></i>
                    {{ priorityLabel(campaign.priority) }}
                    <b class="font-black">({{ Number(campaign.priority) || 0 }})</b>
                  </span>
                </td>
                <td class="px-5 py-3 text-center">
                  <span :class="campaign.status === 'active' ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-slate-200 bg-slate-100 text-slate-600'" class="rounded-full border px-2.5 py-1 text-[9px] font-bold">
                    {{ campaign.status === 'active' ? 'نشطة' : 'متوقفة' }}
                  </span>
                </td>
                <td class="px-5 py-3 text-center">
                  <button class="rounded-md border border-slate-200 px-2.5 py-1.5 text-[10px] font-bold text-slate-700 hover:bg-slate-100 disabled:opacity-50" :disabled="updatingId === campaign.id" @click="toggleStatus(campaign)">
                    {{ updatingId === campaign.id ? 'جارٍ التحديث...' : campaign.status === 'active' ? 'إيقاف' : 'تفعيل' }}
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="px-5 py-4 bg-slate-50/50 border-t border-slate-200 flex items-center justify-between">
          <div class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">
            صفحة <span class="text-slate-900">{{ page }}</span> من <span class="text-slate-900">{{ totalPages }}</span>
            <span class="mx-2 text-slate-200">|</span>
            إجمالي <span class="text-slate-900">{{ total }}</span> حملة
          </div>
          <div class="flex items-center gap-3">
            <div class="flex items-center gap-2">
              <span class="text-[10px] font-bold text-slate-400 uppercase">النتائج:</span>
              <select v-model.number="perPage" @change="loadCampaigns(1)" class="h-8 border border-slate-200 rounded px-2 text-[10px] font-bold outline-none">
                <option :value="10">10</option>
                <option :value="20">20</option>
                <option :value="50">50</option>
              </select>
            </div>
            <button class="rounded border border-slate-200 px-3 py-1.5 disabled:opacity-40 text-xs font-bold" :disabled="page <= 1 || isLoading" @click="loadCampaigns(page - 1)">السابق</button>
            <span class="px-2 py-1.5 text-xs font-bold text-slate-500">{{ page }} / {{ totalPages }}</span>
            <button class="rounded border border-slate-200 px-3 py-1.5 disabled:opacity-40 text-xs font-bold" :disabled="page >= totalPages || isLoading" @click="loadCampaigns(page + 1)">التالي</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import LoadingButton from '@/components/ui/LoadingButton.vue';
import HistoryDetailsModal from '@/components/HistoryDetailsModal.vue';
import { useToast } from '@/composables/useToast';
import { useBranchStore } from '@/stores/branch/branchStore';
import { useProductStore } from '@/stores/product/productStore';
import { useSalesStore } from '@/stores/sales/salesStore';

const salesStore = useSalesStore();
const productStore = useProductStore();
const branchStore = useBranchStore();
const { showToast } = useToast();
const campaigns = ref([]);
const branches = computed(() => branchStore.branches);
const search = ref('');
const statusFilter = ref('');
const isLoading = ref(false);
const isSaving = ref(false);
const isSearching = ref(false);
const updatingId = ref(null);
const showForm = ref(false);
const error = ref('');
const formError = ref('');
const productError = ref('');
const productQuery = ref('');
const productResults = ref([]);
const page = ref(1);
const perPage = ref(20);
const total = ref(0);
const totalPages = computed(() => Math.max(1, Math.ceil(total.value / perPage.value)));
const typeLabels = {
  opening: 'افتتاح',
  clearance: 'تصفية مخزون',
  launch: 'إطلاق منتج',
  custom: 'مخصصة',
};

// KPI — أعداد دقيقة من الـ Backend (لا تتأثر بالصفحة الحالية)
const kpiCounts = ref({ active: 0, paused: 0, totalProducts: 0 });
const activeCampaignsCount      = computed(() => kpiCounts.value.active);
const pausedCampaignsCount      = computed(() => kpiCounts.value.paused);
const totalProductsInCampaigns  = computed(() => kpiCounts.value.totalProducts);

const today = new Date();
const localDate = new Date(today.getTime() - today.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
const endDate = new Date(today.getTime() - today.getTimezoneOffset() * 60000 + 7 * 86400000).toISOString().slice(0, 10);
const form = ref({
  name: '',
  campaignType: 'custom',
  branchId: '',
  priority: 0,
  startDate: localDate,
  endDate: endDate,
  items: [],
});
const priorityPreset = computed({
  get() {
    const priority = Number(form.value.priority);
    return [-100, -50, 0, 50, 100].includes(priority) ? String(priority) : 'custom';
  },
  set(value) {
    if (value !== 'custom') form.value.priority = Number(value);
  },
});

async function loadCampaigns(nextPage = page.value) {
  isLoading.value = true;
  error.value = '';
  try {
    const result = await salesStore.fetchPriceCampaigns({
      page: nextPage,
      perPage: perPage.value,
      query: search.value,
      status: statusFilter.value,
    });
    if (result.status !== 'success') throw new Error(result.message || 'تعذر تحميل الحملات السعرية');
    const data = result.data || {};
    campaigns.value = Array.isArray(data.items) ? data.items : [];
    page.value = Number(result.meta?.page || result.meta?.currentPage) || nextPage;
    total.value = Number(result.meta?.total) || 0;

    // KPI: طلبات منفصلة للحصول على أعداد دقيقة بغض النظر عن الصفحة الحالية
    const [resActive, resPaused, resAll] = await Promise.allSettled([
      salesStore.fetchPriceCampaigns({ page: 1, perPage: 1,    query: search.value, status: 'active' }),
      salesStore.fetchPriceCampaigns({ page: 1, perPage: 1,    query: search.value, status: 'paused' }),
      salesStore.fetchPriceCampaigns({ page: 1, perPage: 1000, query: search.value, status: '' }),
    ]);
    const activeTotal = resActive.status === 'fulfilled' ? Number(resActive.value?.meta?.total ?? 0) : 0;
    const pausedTotal = resPaused.status === 'fulfilled' ? Number(resPaused.value?.meta?.total ?? 0) : 0;
    const allItems    = resAll.status    === 'fulfilled' && Array.isArray(resAll.value?.data?.items)
      ? resAll.value.data.items : [];

    kpiCounts.value = {
      active:        activeTotal,
      paused:        pausedTotal,
      totalProducts: allItems.reduce((sum, c) => sum + (Number(c.productCount) || 0), 0),
    };
  } catch (loadError) {
    error.value = loadError.message || 'تعذر تحميل الحملات السعرية';
  } finally {
    isLoading.value = false;
  }
}

async function searchProducts() {
  if (productQuery.value.trim().length < 2) return;
  isSearching.value = true;
  productError.value = '';
  try {
    const result = await productStore.searchProducts({
      query: productQuery.value,
      branchId: form.value.branchId || undefined,
      limit: 100,
      force: true,
    });
    if (result.status !== 'success') throw new Error(result.message || 'تعذر البحث عن المنتجات');
    productResults.value = Array.isArray(result.data) ? result.data : [];
  } catch (searchError) {
    productError.value = searchError.message || 'تعذر البحث عن المنتجات';
  } finally {
    isSearching.value = false;
  }
}

function isSelected(productId) {
  return form.value.items.some(item => Number(item.productId) === Number(productId));
}

function addProduct(product) {
  const regularPrice = Number(product.regularSalePrice ?? product.salePrice);
  const campaignPrice = Number(product.campaignPrice ?? regularPrice * 0.9);
  if (!product.unitId || !Number.isFinite(regularPrice) || regularPrice <= 0) {
    productError.value = 'تعذر إضافة المنتج لعدم توفر وحدته أو سعره.';
    return;
  }
  form.value.items.push({
    productId: Number(product.id),
    unitId: Number(product.unitId),
    name: product.name,
    unitName: product.unitName || 'قطعة',
    regularPrice: regularPrice,
    campaignPrice: Math.min(campaignPrice, regularPrice - 0.01),
  });
}

function removeProduct(productId) {
  form.value.items = form.value.items.filter(item => Number(item.productId) !== Number(productId));
}

async function createCampaign() {
  formError.value = '';
  if (!form.value.items.length) {
    formError.value = 'أضف منتجاً واحداً على الأقل.';
    return;
  }
  if (form.value.items.some(item => !Number.isFinite(Number(item.campaignPrice)) || Number(item.campaignPrice) <= 0 || Number(item.campaignPrice) >= item.regularPrice)) {
    formError.value = 'يجب أن يكون سعر الحملة موجباً وأقل من السعر الحالي لكل منتج.';
    return;
  }
  isSaving.value = true;
  try {
    const result = await salesStore.createPriceCampaign({
      name: form.value.name,
      campaignType: form.value.campaignType,
      branchId: form.value.branchId || null,
      priority: Number(form.value.priority) || 0,
      startDate: form.value.startDate,
      endDate: form.value.endDate,
      items: form.value.items.map(item => ({
        productId: item.productId,
        unitId: item.unitId,
        campaignPrice: Number(item.campaignPrice),
      })),
    });
    if (result.status !== 'success') throw new Error(result.message || 'تعذر إنشاء الحملة السعرية');
    showToast('تم إنشاء الحملة السعرية بنجاح.', 'success');
    showForm.value = false;
    form.value = {
      name: '',
      campaignType: 'custom',
      branchId: '',
      priority: 0,
      startDate: localDate,
      endDate: endDate,
      items: [],
    };
    productResults.value = [];
    productQuery.value = '';
    await loadCampaigns(1);
  } catch (saveError) {
    formError.value = saveError.message || 'تعذر إنشاء الحملة السعرية';
  } finally {
    isSaving.value = false;
  }
}

async function toggleStatus(campaign) {
  updatingId.value = campaign.id;
  try {
    const nextStatus = campaign.status === 'active' ? 'paused' : 'active';
    const result = await salesStore.setPriceCampaignStatus(campaign.id, nextStatus);
    if (result.status !== 'success') throw new Error(result.message || 'تعذر تحديث حالة الحملة');
    campaign.status = nextStatus;
    showToast(nextStatus === 'active' ? 'تم تفعيل الحملة.' : 'تم إيقاف الحملة.', 'success');
  } catch (statusError) {
    showToast(statusError.message || 'تعذر تحديث حالة الحملة.', 'error');
  } finally {
    updatingId.value = null;
  }
}

function formatPrice(value) {
  return new Intl.NumberFormat('ar-EG', { maximumFractionDigits: 2 }).format(Number(value) || 0);
}

function formatDate(value) {
  if (!value) return '-';
  const date = new Date(`${String(value).slice(0, 10)}T00:00:00`);
  return Number.isNaN(date.getTime()) ? '-' : date.toLocaleDateString('en-US');
}

function priorityLabel(value) {
  const priority = Number(value) || 0;
  if (priority > 0) return 'عالية';
  if (priority < 0) return 'منخفضة';
  return 'عادية';
}

function priorityClass(value) {
  const priority = Number(value) || 0;
  if (priority > 0) return 'border-rose-200 bg-rose-50 text-rose-700';
  if (priority < 0) return 'border-slate-200 bg-slate-100 text-slate-600';
  return 'border-blue-200 bg-blue-50 text-blue-700';
}

function priorityIcon(value) {
  const priority = Number(value) || 0;
  if (priority > 0) return 'fas fa-arrow-up';
  if (priority < 0) return 'fas fa-arrow-down';
  return 'fas fa-minus';
}

onMounted(async () => {
  await Promise.all([branchStore.fetchBranches(), loadCampaigns()]);
  if (!form.value.branchId && branchStore.selectedBranchId) {
    form.value.branchId = String(branchStore.selectedBranchId);
  }
});

watch(() => form.value.branchId, () => {
  productResults.value = [];
  productError.value = '';
});
</script>

<style scoped>
@keyframes loading {
  0% { transform: translateX(100%); }
  100% { transform: translateX(-100%); }
}

@keyframes fadeIn {
  from { opacity: 0; transform: translateY(8px); }
  to { opacity: 1; transform: translateY(0); }
}

.filter-input {
  @apply h-9 w-full bg-white border border-slate-200 rounded-md px-3 text-[11px] font-bold text-slate-700 focus:ring-2 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all;
}

.animate-fadeIn {
  animation: fadeIn 0.3s cubic-bezier(0.16, 1, 0.3, 1);
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
