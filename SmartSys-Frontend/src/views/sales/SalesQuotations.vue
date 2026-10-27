<template>
  <div class="min-h-screen bg-[#fafafa] text-slate-900 font-sans antialiased selection:bg-blue-100" dir="rtl">

    <!-- Top Progress Bar -->
    <PageLoadingBar :active="isLoading" />

    <div class="max-w-[1600px] mx-auto p-6 lg:p-10 space-y-8">

      <!-- Page Header -->
      <PageHeader
        :breadcrumb="{ parent: { label: 'المبيعات', path: '/sales/pos' }, current: { label: 'عروض الأسعار', path: '/sales/quotations' } }"
        title="عروض الأسعار"
        description="إدارة العروض المحفوظة ومتابعة تواريخ صلاحيتها."
        :branches="[]"
        :selected-branch="null"
      >
        <template #controls>
          <router-link to="/sales/pos" class="h-9 px-4 rounded-md border bg-blue-600 text-white text-xs font-bold transition-all flex items-center gap-2 shadow-sm hover:bg-blue-700">
            <i class="fas fa-plus"></i>
            إنشاء عرض سعر
          </router-link>
        </template>
      </PageHeader>

      <!-- KPI Summary: Quotations Stats -->
      <section class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-6">
        <div v-for="kpi in [
          { label: 'إجمالي العروض', val: total, icon: 'fa-file-invoice', color: 'text-blue-600', bg: 'bg-blue-50', show: true },
          { label: 'عروض سارية', val: activeQuotationsCount, icon: 'fa-check-circle', color: 'text-emerald-600', bg: 'bg-emerald-50', show: true },
          { label: 'عروض منتهية', val: expiredQuotationsCount, icon: 'fa-times-circle', color: 'text-rose-600', bg: 'bg-rose-50', show: true },
          { label: 'إجمالي القيمة', val: formatPrice(totalQuotationsValue), icon: 'fa-wallet', color: 'text-indigo-600', bg: 'bg-indigo-50', show: true }
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

      <!-- Search & Filter Controls -->
      <div class="flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:flex-row">
        <div class="relative flex-1">
          <input
            v-model="search"
            class="h-10 w-full rounded-lg border border-slate-200 px-3 pr-9 text-sm outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100"
            placeholder="ابحث برقم العرض أو اسم العميل..."
            @keydown.enter="loadQuotations(1)"
          />
          <i class="fas fa-search absolute right-3 top-1/2 -translate-y-1/2 text-xs text-slate-400"></i>
        </div>
        <select v-model="statusFilter" class="h-10 rounded-lg border border-slate-200 px-3 text-sm font-bold" @change="loadQuotations(1)">
          <option value="">كل العروض</option>
          <option value="active">سارية</option>
          <option value="expired">منتهية</option>
        </select>
        <LoadingButton
          text="تحديث"
          loadingText="جاري التحديث..."
          icon="fas fa-sync-alt"
          :isLoading="isLoading"
          variant="secondary"
          class="h-10 rounded-lg px-4 text-sm"
          @click="loadQuotations(1)"
        />
      </div>

      <div v-if="error" role="alert" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-bold text-rose-700">{{ error }}</div>

      <!-- Main Data Table -->
      <div class="bg-white rounded-xl overflow-hidden shadow-sm border border-slate-200">
        <div class="overflow-x-auto">
          <table class="w-full text-right border-collapse">
            <thead>
              <tr class="bg-slate-50 border-b border-slate-200">
                <th class="px-5 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">رقم العرض</th>
                <th class="px-5 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">العميل</th>
                <th class="px-5 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">تاريخ الإنشاء</th>
                <th class="px-5 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">صالح حتى</th>
                <th class="px-5 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">الإجمالي</th>
                <th class="px-5 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">الحالة</th>
                <th class="px-5 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">الإجراءات</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <template v-if="isLoading">
                <tr v-for="n in 5" :key="n" class="animate-pulse">
                  <td v-for="m in 7" :key="m" class="px-5 py-4"><div class="h-3 bg-slate-100 rounded w-full"></div></td>
                </tr>
              </template>
              <tr v-else-if="!quotations.length" class="hover:bg-blue-50/20 transition-all">
                <td colspan="7" class="py-20 text-center text-slate-300">
                   <i class="fas fa-file-invoice text-3xl mb-4 opacity-20"></i>
                   <p class="text-xs font-bold uppercase tracking-widest">لا توجد عروض أسعار حتى الآن</p>
                </td>
              </tr>
              <tr v-for="quote in quotations" :key="quote.id" class="hover:bg-blue-50/20 transition-all group">
                <td class="px-5 py-3 font-mono font-bold text-blue-700 text-[11px]">{{ quote.quote_number }}</td>
                <td class="px-5 py-3 font-bold text-slate-900 text-[11px]">{{ quote.customer_name || 'عميل نقدي' }}</td>
                <td class="px-5 py-3 font-mono text-[10px] text-slate-500">{{ formatDate(quote.created_at) }}</td>
                <td class="px-5 py-3 font-mono text-[10px] text-slate-500">{{ formatDate(quote.valid_until) }}</td>
                <td class="px-5 py-3 font-mono font-bold text-blue-600 text-[11px]">{{ formatMoney(quote.total_amount, quote.currency_code) }}</td>
                <td class="px-5 py-3 text-center">
                  <span :class="quote.status === 'expired' ? 'border-rose-200 bg-rose-50 text-rose-700' : 'border-emerald-200 bg-emerald-50 text-emerald-700'" class="rounded-full border px-2.5 py-1 text-[9px] font-bold inline-block">
                    {{ quote.status === 'expired' ? 'منتهي' : 'ساري' }}
                  </span>
                </td>
                <td class="px-5 py-3 text-center">
                  <div class="flex gap-2 justify-center">
                    <button
                      v-if="quote.status === 'active'"
                      class="rounded-md border border-blue-200 bg-blue-50 px-2.5 py-1.5 text-[10px] font-bold text-blue-700 hover:bg-blue-100 disabled:opacity-50"
                      :disabled="convertingId === quote.id"
                      @click="convertQuotation(quote)"
                    >
                      <i class="fas fa-file-invoice ml-1"></i>
                      {{ convertingId === quote.id ? 'جارٍ الفتح...' : 'تحويل' }}
                    </button>
                    <button class="rounded-md border border-slate-200 px-2.5 py-1.5 text-[10px] font-bold text-slate-700 hover:bg-slate-100" @click="printQuotation(quote)">
                      <i class="fas fa-print ml-1"></i>
                      طباعة
                    </button>
                  </div>
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
            إجمالي <span class="text-slate-900">{{ total }}</span> عرض
          </div>
          <div class="flex items-center gap-3">
            <div class="flex items-center gap-2">
              <span class="text-[10px] font-bold text-slate-400 uppercase">النتائج:</span>
              <select v-model.number="perPage" @change="loadQuotations(1)" class="h-8 border border-slate-200 rounded px-2 text-[10px] font-bold outline-none">
                <option :value="10">10</option>
                <option :value="20">20</option>
                <option :value="50">50</option>
              </select>
            </div>
            <button class="rounded border border-slate-200 px-3 py-1.5 disabled:opacity-40 text-xs font-bold" :disabled="page <= 1 || isLoading" @click="loadQuotations(page - 1)">السابق</button>
            <span class="px-2 py-1.5 text-xs font-bold text-slate-500">{{ page }} / {{ totalPages }}</span>
            <button class="rounded border border-slate-200 px-3 py-1.5 disabled:opacity-40 text-xs font-bold" :disabled="page >= totalPages || isLoading" @click="loadQuotations(page + 1)">التالي</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import PageHeader from '@/components/PageHeader.vue';
import LoadingButton from '@/components/ui/LoadingButton.vue';
import { useSalesStore } from '@/stores/sales/salesStore';
import { useToast } from '@/composables/useToast';

const salesStore = useSalesStore();
const router = useRouter();
const { showToast } = useToast();
const quotations = ref([]);
const search = ref('');
const statusFilter = ref('');
const isLoading = ref(false);
const convertingId = ref(null);
const error = ref('');
const page = ref(1);
const perPage = ref(50);
const total = ref(0);
const totalPages = computed(() => Math.max(1, Math.ceil(total.value / perPage.value)));

// KPI — أعداد دقيقة من الـ Backend (لا تتأثر بالصفحة الحالية)
const kpiCounts = ref({ active: 0, expired: 0, totalValue: 0 });
const activeQuotationsCount  = computed(() => kpiCounts.value.active);
const expiredQuotationsCount = computed(() => kpiCounts.value.expired);
const totalQuotationsValue   = computed(() => kpiCounts.value.totalValue);

async function loadQuotations(nextPage = page.value) {
  isLoading.value = true;
  error.value = '';
  try {
    const result = await salesStore.fetchQuotations({
      page: nextPage,
      perPage: perPage.value,
      query: search.value,
      status: statusFilter.value,
    });
    if (result.status !== 'success') throw new Error(result.message || 'تعذر تحميل عروض الأسعار');
    const data = result.data || {};
    quotations.value = Array.isArray(data.items) ? data.items : [];
    page.value = Number(data.page) || nextPage;
    total.value = Number(data.total) || 0;

    // ── KPI: طلبات منفصلة للحصول على أعداد دقيقة بغض النظر عن الصفحة الحالية
    const [resActive, resExpired] = await Promise.allSettled([
      salesStore.fetchQuotations({ page: 1, perPage: 1, query: search.value, status: 'active' }),
      salesStore.fetchQuotations({ page: 1, perPage: 1, query: search.value, status: 'expired' }),
    ]);
    const activeTotal  = resActive.status  === 'fulfilled' ? Number(resActive.value?.data?.total  ?? 0) : 0;
    const expiredTotal = resExpired.status === 'fulfilled' ? Number(resExpired.value?.data?.total ?? 0) : 0;

    // إجمالي القيمة: نجمعها من الصفحة الكاملة (بدون فلتر حالة وبدون pagination)
    const resAll = await salesStore.fetchQuotations({ page: 1, perPage: 1000, query: search.value, status: '' });
    const allItems = Array.isArray(resAll.data?.items) ? resAll.data.items : [];
    kpiCounts.value = {
      active:     activeTotal,
      expired:    expiredTotal,
      totalValue: allItems.reduce((sum, q) => sum + (Number(q.total_amount) || 0), 0),
    };
  } catch (loadError) {
    error.value = loadError.message || 'تعذر تحميل عروض الأسعار';
  } finally {
    isLoading.value = false;
  }
}

async function convertQuotation(quote) {
  convertingId.value = quote.id;
  try {
    await router.push({
      path: '/sales/pos',
      query: {
        quotation_id: String(quote.id),
        branch_id: String(quote.branch_id),
      },
    });
  } catch (navigationError) {
    showToast(navigationError.message || 'تعذر فتح نقطة البيع لتحويل العرض.', 'error');
  } finally {
    convertingId.value = null;
  }
}

function formatDate(value) {
  if (!value) return '-';
  const date = new Date(`${String(value).slice(0, 10)}T00:00:00`);
  return Number.isNaN(date.getTime()) ? '-' : date.toLocaleDateString('ar-EG');
}

function formatPrice(value) {
  return new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(value) || 0);
}

function formatMoney(value, currencyCode = 'EGP') {
  const amount = Number(value) || 0;
  try {
    return new Intl.NumberFormat('en-US', {
      style: 'currency',
      currency: currencyCode || 'EGP',
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    }).format(amount);
  } catch (formatError) {
    console.warn('Unsupported quotation currency code; using numeric fallback.', formatError);
    return `${amount.toFixed(2)} ${currencyCode || 'EGP'}`;
  }
}

function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, character => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;',
  })[character]);
}

async function printQuotation(row) {
  const printWindow = window.open('', '_blank');
  if (!printWindow) {
    showToast('يرجى السماح بالنوافذ المنبثقة لطباعة عرض السعر.', 'warning');
    return;
  }
  try {
    const result = await salesStore.fetchQuotation(row.id);
    if (result.status !== 'success' || !result.data) {
      throw new Error(result.message || 'تعذر تحميل تفاصيل عرض السعر');
    }
    const quote = result.data;
    const rows = (Array.isArray(quote.items) ? quote.items : []).map((item, index) => `
      <tr>
        <td>${index + 1}</td>
        <td>${escapeHtml(item.product_name)}</td>
        <td>${escapeHtml(item.unit_name || '')}</td>
        <td>${Number(item.quantity || 0)}</td>
        <td>${Number(item.sale_price || 0).toFixed(2)}</td>
        <td>${Number(item.line_total || 0).toFixed(2)}</td>
      </tr>`).join('');
    printWindow.document.write(`<!doctype html>
      <html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>${escapeHtml(quote.quote_number)}</title>
      <style>
        body{font-family:Tahoma,Arial,sans-serif;margin:32px;color:#0f172a}
        h1{margin:0 0 8px;font-size:24px} .meta{display:flex;gap:24px;margin:20px 0;color:#475569}
        table{width:100%;border-collapse:collapse;margin-top:20px} th,td{border:1px solid #cbd5e1;padding:9px;text-align:right}
        th{background:#f1f5f9} .total{margin-top:20px;font-size:18px;font-weight:bold;text-align:left}
        .notes{margin-top:18px;white-space:pre-wrap}
        @media print{body{margin:10mm}}
      </style></head><body>
      <h1>عرض سعر</h1><strong>${escapeHtml(quote.quote_number)}</strong>
      <div class="meta"><span>العميل: ${escapeHtml(quote.customer_name || 'عميل نقدي')}</span><span>صالح حتى: ${escapeHtml(quote.valid_until)}</span></div>
      <table><thead><tr><th>#</th><th>الصنف</th><th>الوحدة</th><th>الكمية</th><th>سعر الوحدة</th><th>الإجمالي</th></tr></thead><tbody>${rows}</tbody></table>
      <div class="meta">
        <span>الإجمالي قبل الخصم: ${escapeHtml(formatMoney(quote.subtotal, quote.currency_code))}</span>
        <span>الخصم: ${escapeHtml(formatMoney(quote.discount_amount, quote.currency_code))}</span>
        <span>الضريبة: ${escapeHtml(formatMoney(quote.tax_amount, quote.currency_code))}</span>
      </div>
      <div class="total">الإجمالي النهائي: ${escapeHtml(formatMoney(quote.total_amount, quote.currency_code))}</div>
      ${quote.notes ? `<div class="notes">ملاحظات: ${escapeHtml(quote.notes)}</div>` : ''}
      </body></html>`);
    printWindow.onload = () => {
      printWindow.focus();
      printWindow.print();
    };
    printWindow.document.close();
  } catch (printError) {
    printWindow.close();
    showToast(printError.message || 'تعذر طباعة عرض السعر.', 'error');
  }
}

onMounted(() => loadQuotations(1));
</script>

<style scoped>
@keyframes loading {
  0% { transform: translateX(100%); }
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
