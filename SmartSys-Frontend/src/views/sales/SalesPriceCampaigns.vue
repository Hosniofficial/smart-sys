<template>
  <div class="min-h-screen bg-[#fafafa] text-slate-900 font-sans antialiased selection:bg-blue-100" dir="rtl">

    <!-- Top Progress Bar -->
    <PageLoadingBar :active="isLoading || isSearching" />

    <div class="max-w-[1600px] mx-auto p-6 lg:p-10 space-y-8">

      <!-- Page Header -->
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

      <!-- KPI Summary: Campaign Stats -->
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

      <!-- Create Campaign Modal -->
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
                  <select v-model="form.campaign_type" class="filter-input">
                    <option value="opening">افتتاح</option>
                    <option value="clearance">تصفية مخزون</option>
                    <option value="launch">إطلاق منتج</option>
                    <option value="custom">مخصصة</option>
                  </select>
                </label>
                <label class="space-y-1.5">
                  <span class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest">الفرع</span>
                  <select v-model="form.branch_id" class="filter-input">
                    <option value="">كل الفروع</option>
                    <option v-for="branch in branches" :key="branch.id" :value="String(branch.id)">{{ branch.name }}</option>
                  </select>
                </label>
                <label class="space-y-1.5">
                  <span class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest">الأولوية</span>
                  <input v-model.number="form.priority" type="number" min="-100" max="100" class="filter-input" />
                </label>
                <label class="space-y-1.5">
                  <span class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest">تاريخ البداية</span>
                  <input v-model="form.start_date" type="date" required class="filter-input" />
                </label>
                <label class="space-y-1.5">
                  <span class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest">تاريخ النهاية</span>
                  <input v-model="form.end_date" type="date" required :min="form.start_date" class="filter-input" />
                </label>
              </div>

              <!-- Product Search Section -->
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
                      <span class="text-[10px] text-slate-500">{{ product.product_code || product.barcode || 'بدون كود' }} · {{ product.unit_name || 'قطعة' }}</span>
                    </span>
                    <span class="shrink-0 text-xs font-bold text-slate-600">{{ formatPrice(product.sale_price) }}</span>
                  </button>
                </div>

                <!-- Items Table -->
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
                      <tr v-for="item in form.items" :key="`${item.product_id}:${item.unit_id}`">
                        <td class="px-3 py-2 font-bold text-slate-800">{{ item.name }} <span class="font-normal text-slate-400">({{ item.unit_name }})</span></td>
                        <td class="px-3 py-2 font-mono text-slate-500">{{ formatPrice(item.regular_price) }}</td>
                        <td class="px-3 py-2">
                          <input v-model.number="item.campaign_price" type="number" min="0.01" step="0.01" :max="item.regular_price" required class="h-8 w-28 rounded border border-slate-200 px-2 font-mono text-sm focus:border-blue-400" />
                        </td>
                        <td class="px-3 py-2">
                          <button type="button" class="rounded p-2 text-rose-600 hover:bg-rose-50" title="إزالة المنتج" @click="removeProduct(item.product_id)">
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

      <!-- Search & Filter Controls -->
      <div class="flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:flex-row">
        <input v-model="search" class="h-10 flex-1 rounded-lg border border-slate-200 px-3 text-sm outline-none focus:border-blue-400" placeholder="ابحث باسم الحملة..." @keydown.enter="loadCampaigns(1)" />
        <select v-model="statusFilter" class="h-10 rounded-lg border border-slate-200 px-3 text-sm font-bold" @change="loadCampaigns(1)">
          <option value="">كل الحالات</option>
          <option value="active">نشطة</option>
          <option value="paused">متوقفة</option>
        </select>
        <LoadingButton
          text="تحديث"
          loadingText="جاري التحديث..."
          icon="fas fa-sync-alt"
          :isLoading="isLoading"
          variant="secondary"
          class="h-10 rounded-lg px-4 text-sm"
          @click="loadCampaigns(1)"
        />
      </div>

      <div v-if="error" role="alert" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-bold text-rose-700">{{ error }}</div>

      <!-- Main Data Table -->
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
                <th class="px-5 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">الحالة</th>
                <th class="px-5 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">الإجراء</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <template v-if="isLoading">
                <tr v-for="n in 5" :key="n" class="animate-pulse">
                  <td v-for="m in 7" :key="m" class="px-5 py-4"><div class="h-3 bg-slate-100 rounded w-full"></div></td>
                </tr>
              </template>
              <tr v-else-if="!campaigns.length" class="hover:bg-blue-50/20 transition-all">
                <td colspan="7" class="py-20 text-center text-slate-300">
                   <i class="fas fa-chart-line text-3xl mb-4 opacity-20"></i>
                   <p class="text-xs font-bold uppercase tracking-widest">لا توجد حملات سعرية حتى الآن</p>
                </td>
              </tr>
              <tr v-for="campaign in campaigns" :key="campaign.id" class="hover:bg-blue-50/20 transition-all group">
                <td class="px-5 py-3 font-bold text-slate-900 text-[11px]">{{ campaign.name }}</td>
                <td class="px-5 py-3 text-slate-600 text-[11px]">{{ typeLabels[campaign.campaign_type] || campaign.campaign_type }}</td>
                <td class="px-5 py-3 text-slate-600 text-[11px]">{{ campaign.branch_name || 'كل الفروع' }}</td>
                <td class="px-5 py-3 font-mono text-[10px] text-slate-500">{{ formatDate(campaign.start_date) }} — {{ formatDate(campaign.end_date) }}</td>
                <td class="px-5 py-3">
                  <span class="px-2 py-0.5 bg-slate-100 rounded text-[9px] font-bold text-slate-500">{{ Number(campaign.product_count) || 0 }}</span>
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

        <!-- Pagination -->
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
  campaign_type: 'custom',
  branch_id: '',
  priority: 0,
  start_date: localDate,
  end_date: endDate,
  items: [],
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
    page.value = Number(data.page) || nextPage;
    total.value = Number(data.total) || 0;

    // ── KPI: طلبات منفصلة للحصول على أعداد دقيقة بغض النظر عن الصفحة الحالية
    const [resActive, resPaused, resAll] = await Promise.allSettled([
      salesStore.fetchPriceCampaigns({ page: 1, perPage: 1,    query: search.value, status: 'active' }),
      salesStore.fetchPriceCampaigns({ page: 1, perPage: 1,    query: search.value, status: 'paused' }),
      salesStore.fetchPriceCampaigns({ page: 1, perPage: 1000, query: search.value, status: '' }),
    ]);
    const activeTotal = resActive.status === 'fulfilled' ? Number(resActive.value?.data?.total ?? 0) : 0;
    const pausedTotal = resPaused.status === 'fulfilled' ? Number(resPaused.value?.data?.total ?? 0) : 0;
    const allItems    = resAll.status    === 'fulfilled' && Array.isArray(resAll.value?.data?.items)
      ? resAll.value.data.items : [];

    kpiCounts.value = {
      active:        activeTotal,
      paused:        pausedTotal,
      totalProducts: allItems.reduce((sum, c) => sum + (Number(c.product_count) || 0), 0),
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
      branchId: form.value.branch_id || undefined,
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
  return form.value.items.some(item => Number(item.product_id) === Number(productId));
}

function addProduct(product) {
  const regularPrice = Number(product.regular_sale_price ?? product.sale_price);
  const campaignPrice = Number(product.campaign_price ?? regularPrice * 0.9);
  if (!product.unit_id || !Number.isFinite(regularPrice) || regularPrice <= 0) {
    productError.value = 'تعذر إضافة المنتج لعدم توفر وحدته أو سعره.';
    return;
  }
  form.value.items.push({
    product_id: Number(product.id),
    unit_id: Number(product.unit_id),
    name: product.name,
    unit_name: product.unit_name || 'قطعة',
    regular_price: regularPrice,
    campaign_price: Math.min(campaignPrice, regularPrice - 0.01),
  });
}

function removeProduct(productId) {
  form.value.items = form.value.items.filter(item => Number(item.product_id) !== Number(productId));
}

async function createCampaign() {
  formError.value = '';
  if (!form.value.items.length) {
    formError.value = 'أضف منتجاً واحداً على الأقل.';
    return;
  }
  if (form.value.items.some(item => !Number.isFinite(Number(item.campaign_price)) || Number(item.campaign_price) <= 0 || Number(item.campaign_price) >= item.regular_price)) {
    formError.value = 'يجب أن يكون سعر الحملة موجباً وأقل من السعر الحالي لكل منتج.';
    return;
  }
  isSaving.value = true;
  try {
    const result = await salesStore.createPriceCampaign({
      name: form.value.name,
      campaign_type: form.value.campaign_type,
      branch_id: form.value.branch_id || null,
      priority: Number(form.value.priority) || 0,
      start_date: form.value.start_date,
      end_date: form.value.end_date,
      items: form.value.items.map(item => ({
        product_id: item.product_id,
        unit_id: item.unit_id,
        campaign_price: Number(item.campaign_price),
      })),
    });
    if (result.status !== 'success') throw new Error(result.message || 'تعذر إنشاء الحملة السعرية');
    showToast('تم إنشاء الحملة السعرية بنجاح.', 'success');
    showForm.value = false;
    form.value = {
      name: '',
      campaign_type: 'custom',
      branch_id: '',
      priority: 0,
      start_date: localDate,
      end_date: endDate,
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
  return Number.isNaN(date.getTime()) ? '-' : date.toLocaleDateString('ar-EG');
}

onMounted(async () => {
  await Promise.all([branchStore.fetchBranches(), loadCampaigns()]);
  if (!form.value.branch_id && branchStore.selectedBranchId) {
    form.value.branch_id = String(branchStore.selectedBranchId);
  }
});

watch(() => form.value.branch_id, () => {
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
