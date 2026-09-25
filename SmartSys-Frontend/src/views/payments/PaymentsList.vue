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
        title="سجل العمليات المالية"
        description="عرض مركزي وموحد لكافة المقبوضات والمدفوعات المالية عبر النظام."
        :branches="[]"
        :selectedBranch="null"
      >
        <template #controls>
          <button @click="load" :disabled="isLoading" class="h-9 px-4 rounded-md border border-slate-200 bg-white text-slate-600 text-xs font-bold hover:bg-slate-50 transition-all flex items-center gap-2">
            <i class="fas fa-sync-alt text-[10px]" :class="{'animate-spin': isLoading}"></i> تحديث
          </button>
          <button @click="exportCsv" class="h-9 px-4 rounded-md border border-slate-200 bg-white text-emerald-600 text-xs font-bold hover:bg-emerald-50 transition-all flex items-center gap-2">
            <i class="fas fa-file-csv text-[10px]"></i> تصدير
          </button>
          <button @click="exportPdf" class="h-9 px-4 rounded-md bg-slate-900 text-white text-xs font-bold hover:bg-black transition-all flex items-center gap-2 shadow-sm">
            <i class="fas fa-print text-[10px]"></i> طباعة PDF
          </button>
        </template>
      </PageHeader>

      <!-- Advanced Filters Panel: Utility Grid -->
      <section class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-visible relative z-10">
        <div class="p-6 space-y-6">
          <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="space-y-1.5">
              <label class="metadata-label">من تاريخ</label>
              <div class="relative group">
                <input ref="dateFromRef" type="date" v-model="dateFrom" @change="page=1; load();" class="filter-input-v3" style="padding-left: 2rem;" />
                <i class="fas fa-calendar absolute left-3 top-1/2 -translate-y-1/2 text-slate-300 group-hover:text-blue-500 transition-colors pointer-events-none text-[10px]"></i>
              </div>
            </div>
            <div class="space-y-1.5">
              <label class="metadata-label">إلى تاريخ</label>
              <div class="relative group">
                <input ref="dateToRef" type="date" v-model="dateTo" @change="page=1; load();" class="filter-input-v3" style="padding-left: 2rem;" />
                <i class="fas fa-calendar-check absolute left-3 top-1/2 -translate-y-1/2 text-slate-300 group-hover:text-blue-500 transition-colors pointer-events-none text-[10px]"></i>
              </div>
            </div>
            <div class="space-y-1.5">
              <label class="metadata-label">نوع السند</label>
              <select v-model="type" @change="page=1; load();" class="filter-input-v3 appearance-none font-bold">
                <option value="">الكل</option>
                <option value="receipt">سند قبض (وارد)</option>
                <option value="payment">سند دفع (صادر)</option>
              </select>
            </div>
            <div class="space-y-1.5">
              <label class="metadata-label">حالة العملية</label>
              <!-- ✅ RESTORED: القائمة الكاملة للحالات (كانت مختصرة لـ 4 فقط في النسخة الجديدة) -->
              <select v-model="status" @change="page=1; load();" class="filter-input-v3 appearance-none font-bold">
                <option value="">الكل</option>
                <option value="paid">مدفوعة</option>
                <option value="partial">جزئية</option>
                <option value="pending">قيد الانتظار</option>
                <option value="completed">مكتملة</option>
                <option value="canceled">ملغاة</option>
                <option value="draft">مسودة</option>
                <option value="unpaid">غير مدفوعة</option>
              </select>
            </div>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 pt-4 border-t border-slate-50">
            <div class="space-y-1.5">
              <label class="metadata-label">طريقة الدفع</label>
              <select v-model="paymentMethodId" @change="page=1; load();" class="filter-input-v3 appearance-none font-bold">
                <option value="">الكل</option>
                <option v-for="m in paymentMethods" :key="m.id" :value="m.id">{{ m.name }}</option>
              </select>
            </div>

            <div class="space-y-1.5 relative" ref="customerWrap">
              <label class="metadata-label">تصفية بالعميل</label>
              <div class="relative">
                <input type="text" v-model="customerQuery" @focus="showCustomerList = true" @input="showCustomerList = true" class="filter-input-v3" placeholder="ابحث بالاسم..." style="padding-right: 2rem;" />
                <i class="fas fa-user-tag absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px]"></i>
                <div v-if="showCustomerList" class="absolute top-full right-0 mt-1 w-full bg-white border border-slate-200 rounded-lg shadow-2xl z-[40] max-h-48 overflow-auto py-1">
                  <div v-if="!filteredCustomers.length" class="px-4 py-2 text-[10px] text-slate-400 font-bold uppercase">لا نتائج</div>
                  <div v-for="c in filteredCustomers" :key="c.id" @mousedown.prevent="customerId = c.id; customerQuery = c.name; page = 1; load(); showCustomerList = false;" class="px-4 py-2 hover:bg-blue-50 cursor-pointer text-[11px] font-bold text-slate-700 transition-colors border-b border-slate-50 last:border-0">{{ c.name }}</div>
                </div>
              </div>
            </div>

            <div class="space-y-1.5 relative" ref="supplierWrap">
              <label class="metadata-label">تصفية بالمورد</label>
              <div class="relative">
                <input type="text" v-model="supplierQuery" @focus="showSupplierList = true" @input="showSupplierList = true" class="filter-input-v3" placeholder="ابحث بالمورد..." style="padding-right: 2rem;" />
                <i class="fas fa-truck absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px]"></i>
                <div v-if="showSupplierList" class="absolute top-full right-0 mt-1 w-full bg-white border border-slate-200 rounded-lg shadow-2xl z-[40] max-h-48 overflow-auto py-1">
                  <div v-for="s in filteredSuppliers" :key="s.id" @mousedown.prevent="supplierId = s.id; supplierQuery = s.name; page = 1; load(); showSupplierList = false;" class="px-4 py-2 hover:bg-blue-50 cursor-pointer text-[11px] font-bold text-slate-700 border-b border-slate-50 last:border-0">{{ s.name }}</div>
                </div>
              </div>
            </div>

            <!-- ✅ RESTORED: حقل "أنشئ بواسطة" (كان محذوفاً بالكامل من النسخة الجديدة رغم بقاء منطقه في الـ script) -->
            <div class="space-y-1.5 relative" ref="userWrap">
              <label class="metadata-label">أنشئ بواسطة</label>
              <div class="relative">
                <input type="text" v-model="userQuery"
                       @focus="showUserList = true"
                       @input="showUserList = true"
                       @keydown.esc.prevent="showUserList = false"
                       class="filter-input-v3" placeholder="ابحث بالمستخدم..." style="padding-right: 2rem;" />
                <i class="fas fa-user-shield absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px]"></i>
                <button v-if="userQuery || createdBy"
                        @mousedown.prevent="createdBy = ''; userQuery = ''; page = 1; load();"
                        class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-300 hover:text-rose-500 transition-colors">
                  <i class="fas fa-times-circle text-[10px]"></i>
                </button>
                <div v-if="showUserList" class="absolute top-full right-0 mt-1 w-full bg-white border border-slate-200 rounded-lg shadow-2xl z-[40] max-h-48 overflow-auto py-1">
                  <div v-if="!filteredUsers.length" class="px-4 py-2 text-[10px] text-slate-400 font-bold uppercase">لا نتائج</div>
                  <div v-for="u in filteredUsers" :key="u.id"
                       @mousedown.prevent="createdBy = u.id; userQuery = u.name || String(u.id); page = 1; load(); showUserList = false;"
                       class="px-4 py-2 hover:bg-blue-50 cursor-pointer text-[11px] font-bold text-slate-700 transition-colors border-b border-slate-50 last:border-0">
                    {{ u.name || ('#' + u.id) }}
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div class="flex items-center gap-3 pt-4 border-t border-slate-50">
            <button @click="resetFilters" class="h-9 px-4 rounded-md bg-slate-100 text-slate-600 text-[11px] font-bold hover:bg-slate-200 transition-all flex items-center justify-center gap-2">
              <i class="fas fa-broom text-[10px]"></i> إعادة تعيين الفلاتر
            </button>
          </div>
        </div>
      </section>

      <!-- Main Data Table -->
      <div class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm relative">
        <div class="overflow-x-auto">
          <table class="w-full text-right border-collapse">
            <thead>
              <tr class="bg-slate-50 border-b border-slate-200">
                <th class="px-6 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">تاريخ السند</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">المرجع / المستند</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">النوع</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">الحالة</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">طريقة الدفع</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">المبلغ</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">جهة الاتصال</th>
                <th class="px-6 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">بواسطة</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 font-medium text-xs">
              <template v-if="isLoading">
                <tr v-for="n in 8" :key="n" class="animate-pulse">
                  <td v-for="m in 8" :key="m" class="px-6 py-4"><div class="h-3 bg-slate-100 rounded w-full"></div></td>
                </tr>
              </template>
              <tr v-else-if="!items.length">
                <td colspan="8" class="py-24 text-center text-slate-300">
                   <i class="fas fa-receipt text-3xl mb-4 opacity-20"></i>
                   <p class="text-xs font-bold uppercase tracking-widest">لا توجد عمليات مالية مسجلة</p>
                </td>
              </tr>
              <tr v-for="p in items" :key="p.id" class="hover:bg-blue-50/20 transition-all group">
                <td class="px-6 py-4 text-[10px] font-mono font-bold text-slate-400 group-hover:text-slate-900">{{ p.payment_date ? new Date(p.payment_date).toLocaleDateString('en-GB') : '-' }}</td>
                <td class="px-4 py-4">
                  <div class="flex flex-col">
                    <router-link v-if="p.sale_id" :to="{ name: 'SalesHistory', query: { id: p.sale_id } }" class="text-blue-600 font-bold hover:underline underline-offset-4">
                      {{ p.reference || '#' + p.id }}
                    </router-link>
                    <router-link v-else-if="p.purchase_id" :to="{ name: 'PurchaseHistory', query: { id: p.purchase_id } }" class="text-indigo-600 font-bold hover:underline underline-offset-4">
                      {{ p.reference || '#' + p.id }}
                    </router-link>
                    <span v-else class="text-slate-800 font-bold">{{ p.reference || '-' }}</span>
                    <span class="text-[9px] text-slate-400 font-mono mt-1">ID: #{{ p.id }}</span>
                  </div>
                </td>
                <td class="px-4 py-4 text-center">
                  <span class="px-2 py-0.5 bg-slate-100 border border-slate-200 rounded text-[9px] font-bold text-slate-500 uppercase tracking-tighter">
                    {{ p.reference_label || 'سند' }}
                  </span>
                </td>
                <td class="px-4 py-4 text-center">
                  <span :class="[badgeClass(p.status || p.status_code)]" class="px-2 py-0.5 rounded text-[9px] font-bold border">
                    {{ p.status_label || (p.status || '-') }}
                  </span>
                </td>
                <td class="px-4 py-4">
                  <span :class="[
                    (p.payment_method_name||'').toLowerCase().includes('cash') ? 'text-emerald-600' : 
                    (p.payment_method_name||'').toLowerCase().includes('card') ? 'text-blue-600' : 'text-slate-500'
                  ]" class="text-[10px] font-bold">
                    {{ p.payment_method_name || '-' }}
                  </span>
                </td>
                <td class="px-4 py-4 text-sm font-bold font-mono tracking-tighter text-slate-900">
                  {{ formatCurrency(p.amount || 0) }}
                </td>
                <td class="px-4 py-4">
                  <div class="flex flex-col">
                    <router-link v-if="p.customer_id" :to="{ name: 'AccountStatement', params: { type: 'customers', id: p.customer_id } }" class="text-blue-600 font-bold hover:underline">{{ p.customer_name }}</router-link>
                    <router-link v-else-if="p.supplier_id" :to="{ name: 'AccountStatement', params: { type: 'suppliers', id: p.supplier_id } }" class="text-indigo-600 font-bold hover:underline">{{ p.supplier_name }}</router-link>
                    <span v-else class="text-slate-300 italic">—</span>
                  </div>
                </td>
                <td class="px-6 py-4 text-center">
                  <div class="flex flex-col items-center">
                    <span class="text-[10px] font-bold text-slate-700">{{ p.created_by_name || '-' }}</span>
                    <span class="text-[9px] font-mono text-slate-400 mt-1 uppercase">{{ p.created_at ? new Date(p.created_at).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' }) : '' }}</span>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Pagination -->
        <div class="px-6 py-4 bg-slate-50/50 border-t border-slate-200 flex items-center justify-between">
          <div class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">
            صفحة <span class="text-slate-900">{{ page }}</span> من <span class="text-slate-900">{{ Math.max(1, Math.ceil(meta.total / perPage)) }}</span>
            <span class="mx-2 text-slate-200">|</span>
            إجمالي <span class="text-slate-900">{{ meta.total }}</span> عملية
          </div>
          <div class="flex items-center gap-3">
             <div class="flex items-center gap-2">
               <span class="text-[10px] font-bold text-slate-400 uppercase tracking-tighter">النتائج:</span>
               <select v-model.number="perPage" @change="page = 1; load();" class="h-8 border border-slate-200 rounded px-2 text-[10px] font-bold outline-none">
                 <option :value="10">10</option>
                 <option :value="20">20</option>
                 <option :value="50">50</option>
               </select>
             </div>
             <div class="flex items-center gap-1">
               <button @click="prevPage()" :disabled="page <= 1" class="pagination-btn-v2"><i class="fas fa-chevron-right"></i></button>
               <button @click="nextPage()" :disabled="page * perPage >= meta.total" class="pagination-btn-v2"><i class="fas fa-chevron-left"></i></button>
             </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue';
import getLocalDateISO from '@/utils/date';
import { useAuthStore } from '@/stores/auth';
import { useBranchStore } from '@/stores/branch';
import { usePaymentStore } from '@/stores/payment/paymentStore';
import { useCustomerStore } from '@/stores/customer/customerStore';
import { useSupplierStore } from '@/stores/supplier/supplierStore';
import { useUserStore } from '@/stores/user/userStore';
import { useCompanyCurrency } from '@/composables/useCompanyCurrency';
import { useBootstrapStore } from '@/stores/bootstrap';

import BaseSkeleton from '@/components/ui/BaseSkeleton.vue';
import { useBreadcrumb } from '@/composables/useBreadcrumb';
import PageHeader from '@/components/PageHeader.vue';

// --- State ---
const { breadcrumb } = useBreadcrumb();
const isLoading = ref(false);
const items = ref([]);
const meta = ref({ total: 0, page: 1, per_page: 50 });

// Filters
const dateFrom = ref('');
const dateTo = ref('');

// Date input refs
const dateFromRef = ref(null);
const dateToRef = ref(null);
const type = ref('');
const status = ref('');
const paymentMethodId = ref('');
const perPage = ref(50);
const page = ref(1);

const { formatCurrency, fetchSettings } = useCompanyCurrency();
const bootstrapStore = useBootstrapStore();
const paymentStore = usePaymentStore();
const paymentMethods = computed(() => paymentStore.paymentMethods);
const customerStore = useCustomerStore();
const customers = computed(() => customerStore.customers);
const supplierStore = useSupplierStore();
const userStore = useUserStore();
const suppliers = computed(() => supplierStore.suppliers);
const users = computed(() => userStore.users);

const customerId = ref('');
const supplierId = ref('');
const createdBy = ref('');

const customerQuery = ref('');
const supplierQuery = ref('');
const userQuery = ref('');

const showCustomerList = ref(false);
const showSupplierList = ref(false);
const showUserList = ref(false);

const customerWrap = ref(null);
const supplierWrap = ref(null);
const userWrap = ref(null);

// --- Badge helper ---
const badgeClass = (s) => {
  const v = String(s || '').toLowerCase();
  if (['paid', 'posted', 'completed'].includes(v)) return 'bg-emerald-100 text-emerald-700';
  if (['partial', 'partially_paid'].includes(v)) return 'bg-amber-100 text-amber-700';
  if (['unpaid', 'pending', 'pending_payment'].includes(v)) return 'bg-yellow-100 text-yellow-700';
  if (['rejected', 'canceled', 'cancelled'].includes(v)) return 'bg-rose-100 text-rose-700';
  return 'bg-slate-100 text-slate-500';
};

// --- Filtered dropdown lists ---
const filteredUsers = computed(() => {
  const q = (userQuery.value || '').toLowerCase().trim();
  const arr = users.value || [];
  if (!q) return arr.slice(0, 50);
  return arr.filter(u => String(u.name || '').toLowerCase().includes(q) || String(u.id).includes(q)).slice(0, 50);
});
const filteredCustomers = computed(() => {
  const q = (customerQuery.value || '').toLowerCase().trim();
  const arr = customers.value || [];
  if (!q) return arr.slice(0, 50);
  return arr.filter(c => String(c.name || '').toLowerCase().includes(q) || String(c.id).includes(q)).slice(0, 50);
});
const filteredSuppliers = computed(() => {
  const q = (supplierQuery.value || '').toLowerCase().trim();
  const arr = suppliers.value || [];
  if (!q) return arr.slice(0, 50);
  return arr.filter(s => String(s.name || '').toLowerCase().includes(q) || String(s.id).includes(q)).slice(0, 50);
});

// --- Query params ---
const queryParams = computed(() => {
  const p = { per_page: perPage.value, page: page.value };
  if (dateFrom.value) p.date_from = dateFrom.value;
  if (dateTo.value) p.date_to = dateTo.value;
  if (type.value) p.type = type.value;
  if (status.value) p.status = status.value;
  if (paymentMethodId.value) p.payment_method_id = Number(paymentMethodId.value);
  if (customerId.value) p.customer_id = Number(customerId.value);
  if (supplierId.value) p.supplier_id = Number(supplierId.value);
  if (createdBy.value) p.created_by = Number(createdBy.value);
  return p;
});

// --- Load data ---
async function load() {
  isLoading.value = true;
  try {
    const result = await paymentStore.fetchPayments(queryParams.value);
    if (result.status === 'success') {
      const { items: rows, meta: m } = result.data || { items: [], meta: {} };
      items.value = rows || [];
      meta.value = m || { total: (rows || []).length, page: 1, per_page: (rows || []).length };
    } else {
      items.value = [];
      meta.value = { total: 0, page: 1, per_page: perPage.value };
    }
  } catch (e) {
    console.error('Failed to load payments', e);
    items.value = [];
    meta.value = { total: 0, page: 1, per_page: perPage.value };
  } finally {
    isLoading.value = false;
  }
}

// --- Reset filters ---
function resetFilters() {
  dateFrom.value = '';
  dateTo.value = '';
  type.value = '';
  status.value = '';
  paymentMethodId.value = '';
  createdBy.value = '';
  customerId.value = '';
  supplierId.value = '';
  userQuery.value = '';
  customerQuery.value = '';
  supplierQuery.value = '';
  page.value = 1;
  load();
}

// --- Export CSV ---
function exportCsv() {
  const rows = (items.value || []).map(r => ({
    id: r.id,
    date: r.payment_date,
    reference: r.reference,
    reference_label: r.reference_label,
    status: r.status_code || r.status,
    status_label: r.status_label,
    amount: r.amount,
    payment_method_id: r.payment_method_id,
    payment_method_name: r.payment_method_name,
    created_by: r.created_by_name,
    customer: r.customer_name || r.customer_id,
    supplier: r.supplier_name || r.supplier_id,
    sale_id: r.sale_id,
    purchase_id: r.purchase_id,
    created_at: r.created_at,
  }));
  paymentStore.exportToCsv(`payments_${getLocalDateISO()}.csv`, rows);
}

// --- Export PDF (full columns like original) ---
function exportPdf() {
  const rows = items.value || [];
  const win = window.open('', '_blank');
  if (!win) return;
  const style = `
    <style>
      body { font-family: 'Cairo', Arial, sans-serif; direction: rtl; padding: 20px; }
      h1 { font-size: 18px; margin-bottom: 8px; }
      .summary { font-size: 12px; color: #555; margin-bottom: 12px; }
      table { width: 100%; border-collapse: collapse; font-size: 12px; }
      th, td { border: 1px solid #e5e7eb; padding: 8px 10px; text-align: center; }
      thead { background: #f8fafc; font-weight: 900; }
      tr:nth-child(even) { background: #f9fafb; }
    </style>
  `;
  const header = `
    <tr>
      <th>التاريخ</th>
      <th>المعرف</th>
      <th>المرجع</th>
      <th>النوع</th>
      <th>الحالة</th>
      <th>طريقة الدفع</th>
      <th>أنشئ بواسطة</th>
      <th>القيمة</th>
      <th>عميل</th>
      <th>مورد</th>
    </tr>
  `;
  const body = rows.map(r => `
    <tr>
      <td>${r.payment_date ? new Date(r.payment_date).toLocaleDateString('en-GB') : '-'}</td>
      <td>#${r.id}</td>
      <td>${r.reference || '-'}</td>
      <td>${r.reference_label || (String(r.reference || '').split('#')[0] || '')}</td>
      <td>${r.status_label || r.status || r.status_code || '-'}</td>
      <td>${r.payment_method_name || '-'}</td>
      <td>${r.created_by_name || '-'}</td>
      <td>${Number(r.amount || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</td>
      <td>${r.customer_name || r.customer_id || '-'}</td>
      <td>${r.supplier_name || r.supplier_id || '-'}</td>
    </tr>
  `).join('');
  const summary = `
    <div class="summary">
      <div><strong>الفترة:</strong> ${dateFrom.value || '-'} إلى ${dateTo.value || '-'}</div>
      <div><strong>النوع:</strong> ${type.value || 'الكل'} | <strong>الحالة:</strong> ${status.value || 'الكل'} | <strong>طريقة الدفع:</strong> ${paymentMethodId.value || 'الكل'}</div>
      <div><strong>أنشئ بواسطة:</strong> ${userQuery.value || '-'} | <strong>عميل:</strong> ${customerQuery.value || '-'} | <strong>مورد:</strong> ${supplierQuery.value || '-'}</div>
    </div>
  `;
  win.document.write(`<!doctype html><html><head><meta charset="utf-8">${style}</head><body>`);
  win.document.write(`<h1>تقرير المدفوعات</h1>${summary}`);
  win.document.write(`<table><thead>${header}</thead><tbody>${body}</tbody></table>`);
  win.document.write('</body></html>');
  win.document.close();
  win.focus();
  win.print();
}

// --- Pagination ---
function nextPage() { if (page.value * perPage.value < meta.value.total) { page.value += 1; load(); } }
function prevPage() { if (page.value > 1) { page.value -= 1; load(); } }

// --- Lifecycle ---
const cleanup = ref(() => {});

onMounted(async () => {
  // Use bootstrap API to fetch all required data in a single request
  try {
    const data = await bootstrapStore.fetchPaymentsData();
    
    // Map bootstrap data to individual stores
    if (data.paymentMethods) paymentStore.paymentMethods = data.paymentMethods;
    if (data.customers) customerStore.customers = data.customers;
    if (data.suppliers) supplierStore.suppliers = data.suppliers;
    if (data.users) userStore.users = data.users;
    if (data.settings) {
      // Apply settings to currency composable if needed
      if (data.settings.currency || data.settings.currency_symbol) {
        // Settings are already handled by fetchSettings, but we can use cached values
      }
    }
    
    console.log('[PaymentsList] Bootstrap data loaded successfully');
  } catch (e) {
    console.warn('[PaymentsList] Bootstrap API failed, falling back to individual requests', e);
    
    // Fallback to individual API calls if bootstrap fails
    try { await fetchSettings(); } catch { /* ignore */ }
    try { await paymentStore.fetchPaymentMethods(); } catch { /* ignore */ }
    try { await customerStore.fetchCustomers(); } catch { /* ignore */ }
    try { await supplierStore.fetchSuppliers(); } catch { /* ignore */ }
    try { await userStore.fetchUsers(); } catch { /* ignore */ }
  }

  await load();

  // Close dropdowns on outside click
  const onDocClick = (e) => {
    if (!customerWrap.value?.contains(e.target)) showCustomerList.value = false;
    if (!supplierWrap.value?.contains(e.target)) showSupplierList.value = false;
    if (!userWrap.value?.contains(e.target)) showUserList.value = false;
  };
  // Close dropdowns on scroll (preserved from original)
  const onScroll = () => {
    showCustomerList.value = false;
    showSupplierList.value = false;
    showUserList.value = false;
  };

  document.addEventListener('click', onDocClick, true);
  window.addEventListener('scroll', onScroll, true);

  cleanup.value = () => {
    document.removeEventListener('click', onDocClick, true);
    window.removeEventListener('scroll', onScroll, true);
  };
});

onBeforeUnmount(() => {
  try { cleanup.value && cleanup.value(); } catch {}
});

watch([perPage], () => { page.value = 1; load(); });
</script>

<style scoped>
@keyframes loading { 0% { transform: translateX(100%); } 100% { transform: translateX(-100%); } }

.filter-input-v3 {
  @apply h-9 w-full bg-white border border-slate-200 rounded-md px-3 text-[11px] font-bold text-slate-700 focus:ring-2 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all;
}

.metadata-label { @apply block text-[10px] font-bold text-slate-400 uppercase tracking-widest px-1; }

.pagination-btn-v2 {
  @apply w-8 h-8 flex items-center justify-center rounded border border-slate-200 bg-white text-slate-500 hover:text-blue-600 hover:border-blue-200 disabled:opacity-40 transition-all;
}

.custom-scroll::-webkit-scrollbar { width: 5px; }
.custom-scroll::-webkit-scrollbar-thumb { @apply bg-slate-200 rounded-full; }

.animate-fadeIn { animation: fadeIn 0.4s ease-out; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

/* Global helper for status badges to match main design system */
.status-badge { @apply px-2 py-0.5 rounded text-[9px] font-bold border inline-flex items-center justify-center; }
</style>
