<template>
  <div class="min-h-screen bg-[#fafafa] text-slate-900 font-sans antialiased selection:bg-blue-100" dir="rtl">
    
    <!-- Top Progress Bar: High-precision indicator -->
    <PageLoadingBar :active="loading || loadingCustomers || loadingSuppliers" />

    <div class="max-w-[1600px] mx-auto p-6 lg:p-10 space-y-8">
      
      <PageHeader
        :breadcrumb="{ parent: 'النظام والتدقيق', current: 'سلامة البيانات' }"
        title="الربط والنزاهة المحاسبية"
        description="اكتشف مشكلات الربط مع دليل الحسابات، وراجعها وعالجها لضمان تسجيل القيود المحاسبية الآلية بشكل صحيح."
        :branches="[]"
        :selected-branch="null"
      >
        <template #controls>
          <LoadingButton
            text="تحديث الحالة العامة"
            :isLoading="loading"
            loadingText="جارٍ التحديث..."
            icon="fas fa-sync-alt"
            variant="primary"
            class="h-9 px-6"
            @click="refreshAll"
          />
        </template>
      </PageHeader>

      <!-- Integrity Status KPIs: Diagnostic Metric Grid -->
      <section class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-10">
        <div class="bg-white border border-slate-200 p-6 rounded-xl flex items-center justify-between group hover:border-rose-200 transition-all shadow-sm border-r-4 border-r-rose-500">
          <div class="flex items-center gap-5">
            <div class="w-12 h-12 bg-rose-50 text-rose-600 rounded-lg flex items-center justify-center text-xl shadow-inner group-hover:scale-110 transition-transform duration-500">
              <i class="fas fa-user-slash"></i>
            </div>
            <div>
              <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">عملاء مفقود ربطهم</p>
              <p class="text-3xl font-bold font-mono tracking-tighter text-rose-600">{{ customers.length }}</p>
            </div>
          </div>
          <div v-if="customers.length > 0" class="flex items-center gap-2 px-3 py-1.5 bg-rose-50 border border-rose-100 rounded-full">
             <span class="w-1.5 h-1.5 rounded-full bg-rose-500 animate-pulse"></span>
             <span class="text-[9px] font-bold text-rose-700 uppercase tracking-tighter">إجراء مطلوب</span>
          </div>
        </div>

        <div class="bg-white border border-slate-200 p-6 rounded-xl flex items-center justify-between group hover:border-amber-200 transition-all shadow-sm border-r-4 border-r-amber-500">
          <div class="flex items-center gap-5">
            <div class="w-12 h-12 bg-amber-50 text-amber-600 rounded-lg flex items-center justify-center text-xl shadow-inner group-hover:scale-110 transition-transform duration-500">
              <i class="fas fa-truck-pickup"></i>
            </div>
            <div>
              <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">موردون مفقود ربطهم</p>
              <p class="text-3xl font-bold font-mono tracking-tighter text-amber-600">{{ suppliers.length }}</p>
            </div>
          </div>
          <div v-if="suppliers.length > 0" class="flex items-center gap-2 px-3 py-1.5 bg-amber-50 border border-amber-100 rounded-full">
             <span class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-pulse"></span>
             <span class="text-[9px] font-bold text-amber-700 uppercase tracking-tighter">قيد المراجعة</span>
          </div>
        </div>
      </section>

      <!-- Main Audit Workspace -->
      <div class="grid grid-cols-1 gap-12 max-w-6xl mx-auto">
        
        <!-- Customers Audit Card -->
        <section class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm">
          <div class="px-6 py-4 border-b border-slate-100 flex flex-col md:flex-row md:items-center justify-between gap-4 bg-slate-50/50">
            <div class="flex items-center gap-3">
               <span class="w-1 h-5 bg-rose-500 rounded-full"></span>
               <h2 class="text-xs font-bold text-slate-900 uppercase tracking-widest">فجوات ربط حسابات العملاء</h2>
            </div>
            <div class="flex items-center gap-2">
              <LoadingButton
                text=""
                loadingText=""
                :showTextDuringLoad="false"
                icon="fas fa-rotate"
                :isLoading="loadingCustomers"
                variant="secondary"
                class="h-8 w-8 rounded border border-slate-200 bg-white p-0 text-slate-400 shadow-sm hover:text-blue-600"
                aria-label="تحديث بيانات العملاء"
                @click="fetchCustomers"
              />
              <LoadingButton
                text="إصلاح الربط الجماعي"
                :isLoading="bulkCustomersInProgress"
                :loadingText="`جاري المعالجة (${bulkCustomersDone}/${bulkCustomersTotal})`"
                :disabled="loadingCustomers || customers.length === 0"
                icon="fas fa-magic"
                variant="danger"
                class="h-8 px-4"
                @click="fixAllCustomers"
              />
            </div>
          </div>

          <div v-if="errorCustomers" class="p-4 bg-rose-50 border-b border-rose-100 text-[11px] font-bold text-rose-700 flex items-center gap-2 animate-fadeIn">
            <i class="fas fa-times-circle"></i> {{ errorCustomers }}
          </div>
          
          <div class="overflow-x-auto">
            <table class="w-full text-right border-collapse">
              <thead>
                <tr class="bg-slate-50/50 border-b border-slate-100 text-slate-400 font-bold uppercase text-[9px] tracking-widest">
                  <th class="px-6 py-4 w-16">#</th>
                  <th class="px-4 py-4">اسم العميل</th>
                  <th class="px-4 py-4">رقم الهاتف</th>
                  <th class="px-4 py-4">الرصيد الدفتري</th>
                  <th class="px-8 py-4 text-center w-32">القرار</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100 font-medium text-xs">
                <!-- Skeleton Loader -->
                <template v-if="loadingCustomers">
                  <tr v-for="n in 4" :key="n" class="animate-pulse">
                    <td v-for="m in 5" :key="m" class="px-6 py-4"><div class="h-3 bg-slate-100 rounded w-full"></div></td>
                  </tr>
                </template>

                <tr v-else-if="customers.length === 0">
                  <td colspan="5" class="py-16 text-center text-slate-300">
                     <i class="fas fa-check-double text-3xl mb-4 text-emerald-500 opacity-20"></i>
                     <p class="text-xs font-bold uppercase tracking-widest">قاعدة بيانات العملاء سليمة ومؤمنة</p>
                  </td>
                </tr>

                <tr v-for="(c, idx) in customers" :key="c.id" class="hover:bg-slate-50 transition-colors group">
                  <td class="px-6 py-4 text-[10px] font-mono font-bold text-slate-300 group-hover:text-slate-900 transition-colors">{{ idx + 1 }}</td>
                  <td class="px-4 py-4 font-bold text-slate-800">{{ c.name }}</td>
                  <td class="px-4 py-4 text-[10px] text-slate-400 font-mono tracking-tighter">{{ c.phone || '—' }}</td>
                  <td class="px-4 py-4 font-mono font-bold text-slate-900">{{ formatCurrency(c.balance) }}</td>
                  <td class="px-8 py-4 text-center">
                    <LoadingButton
                      text="إصلاح الربط"
                      :isLoading="processingCustomerIds.has(c.id)"
                      loadingText="جاري..."
                      variant="danger"
                      class="h-7 px-3"
                      @click="ensureCustomerAccount(c)"
                    />
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <!-- Suppliers Audit Card -->
        <section class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm">
          <div class="px-6 py-4 border-b border-slate-100 flex flex-col md:flex-row md:items-center justify-between gap-4 bg-slate-50/50">
            <div class="flex items-center gap-3">
               <span class="w-1 h-5 bg-amber-500 rounded-full"></span>
               <h2 class="text-xs font-bold text-slate-900 uppercase tracking-widest">فجوات ربط حسابات الموردين</h2>
            </div>
            <div class="flex items-center gap-2">
              <LoadingButton
                text=""
                loadingText=""
                :showTextDuringLoad="false"
                icon="fas fa-rotate"
                :isLoading="loadingSuppliers"
                variant="secondary"
                class="h-8 w-8 rounded border border-slate-200 bg-white p-0 text-slate-400 shadow-sm hover:text-blue-600"
                aria-label="تحديث بيانات الموردين"
                @click="fetchSuppliers"
              />
              <LoadingButton
                text="إصلاح الربط الجماعي"
                :isLoading="bulkSuppliersInProgress"
                :loadingText="`جاري المعالجة (${bulkSuppliersDone}/${bulkSuppliersTotal})`"
                :disabled="loadingSuppliers || suppliers.length === 0"
                icon="fas fa-wrench"
                variant="warning"
                class="h-8 px-4"
                @click="fixAllSuppliers"
              />
            </div>
          </div>

          <div v-if="errorSuppliers" class="p-4 bg-rose-50 border-b border-rose-100 text-[11px] font-bold text-rose-700 flex items-center gap-2 animate-fadeIn">
            <i class="fas fa-times-circle"></i> {{ errorSuppliers }}
          </div>
          
          <div class="overflow-x-auto">
            <table class="w-full text-right border-collapse">
              <thead>
                <tr class="bg-slate-50/50 border-b border-slate-100 text-slate-400 font-bold uppercase text-[9px] tracking-widest">
                  <th class="px-6 py-4 w-16">#</th>
                  <th class="px-4 py-4">اسم المورد</th>
                  <th class="px-4 py-4">رقم الهاتف</th>
                  <th class="px-4 py-4">الرصيد الدفتري</th>
                  <th class="px-8 py-4 text-center w-32">القرار</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100 font-medium text-xs">
                <!-- Skeleton Loader -->
                <template v-if="loadingSuppliers">
                  <tr v-for="n in 4" :key="n" class="animate-pulse">
                    <td v-for="m in 5" :key="m" class="px-6 py-4"><div class="h-3 bg-slate-100 rounded w-full"></div></td>
                  </tr>
                </template>

                <tr v-else-if="suppliers.length === 0">
                  <td colspan="5" class="py-16 text-center text-slate-300">
                     <i class="fas fa-clipboard-check text-3xl mb-4 text-emerald-500 opacity-20"></i>
                     <p class="text-xs font-bold uppercase tracking-widest">كافة سجلات الموردين مرتبطة ومؤمنة</p>
                  </td>
                </tr>

                <tr v-for="(s, idx) in suppliers" :key="s.id" class="hover:bg-slate-50 transition-colors group">
                  <td class="px-6 py-4 text-[10px] font-mono font-bold text-slate-300 group-hover:text-slate-900 transition-colors">{{ idx + 1 }}</td>
                  <td class="px-4 py-4 font-bold text-slate-800">{{ s.name }}</td>
                  <td class="px-4 py-4 text-[10px] text-slate-400 font-mono tracking-tighter">{{ s.phone || '—' }}</td>
                  <td class="px-4 py-4 font-mono font-bold text-slate-900">{{ formatCurrency(s.balance) }}</td>
                  <td class="px-8 py-4 text-center">
                    <LoadingButton
                      text="إصلاح الربط"
                      :isLoading="processingSupplierIds.has(s.id)"
                      loadingText="جاري..."
                      variant="warning"
                      class="h-7 px-3"
                      @click="ensureSupplierAccount(s)"
                    />
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
      </div>
    </div>
  </div>
</template>

<script setup>
// [بقاء المنطق البرمجي كما هو بنسبة 100%]
// تم الحفاظ على كافة الـ imports، الـ refs، الـ functions، والـ lifecycle hooks الأصلية.
import { ref, onMounted } from 'vue';
import { useAdminStore } from '@/stores/admin/adminStore';
import LoadingButton from '@/components/ui/LoadingButton.vue';
import BaseSkeleton from '@/components/ui/BaseSkeleton.vue';
import PageHeader from '@/components/PageHeader.vue';

const adminStore = useAdminStore();
const customers = ref([]);
const suppliers = ref([]);
const loadingCustomers = ref(false);
const loadingSuppliers = ref(false);
const errorCustomers = ref('');
const errorSuppliers = ref('');
const processingCustomerIds = ref(new Set());
const processingSupplierIds = ref(new Set());

const loading = ref(false);
const bulkCustomersInProgress = ref(false);
const bulkSuppliersInProgress = ref(false);
const bulkCustomersDone = ref(0);
const bulkSuppliersDone = ref(0);
const bulkCustomersTotal = ref(0);
const bulkSuppliersTotal = ref(0);

function formatCurrency(val) {
  const n = Number(val || 0);
  return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'EGP' }).format(n);
}

async function fetchCustomers() {
  loadingCustomers.value = true;
  errorCustomers.value = '';
  const result = await adminStore.fetchCustomersMissingAccounts();
  if (result.status === 'success') {
    customers.value = result.data;
  } else {
    errorCustomers.value = result.message;
  }
  loadingCustomers.value = false;
}

async function fetchSuppliers() {
  loadingSuppliers.value = true;
  errorSuppliers.value = '';
  const result = await adminStore.fetchSuppliersMissingAccounts();
  if (result.status === 'success') {
    suppliers.value = result.data;
  } else {
    errorSuppliers.value = result.message;
  }
  loadingSuppliers.value = false;
}

async function refreshAll() {
  loading.value = true;
  try {
    await Promise.all([fetchCustomers(), fetchSuppliers()]);
  } finally {
    loading.value = false;
  }
}

onMounted(() => {
  refreshAll();
});

async function ensureCustomerAccount(c) {
  if (!c?.id) return;
  processingCustomerIds.value.add(c.id);
  errorCustomers.value = '';
  const result = await adminStore.ensureCustomerAccount(c.id);
  if (result.status === 'success') {
    customers.value = customers.value.filter(item => item.id !== c.id);
  } else {
    errorCustomers.value = result.message;
  }
  processingCustomerIds.value.delete(c.id);
  processingCustomerIds.value = new Set(processingCustomerIds.value);
}

async function ensureSupplierAccount(s) {
  if (!s?.id) return;
  processingSupplierIds.value.add(s.id);
  errorSuppliers.value = '';
  const result = await adminStore.ensureSupplierAccount(s.id);
  if (result.status === 'success') {
    suppliers.value = suppliers.value.filter(item => item.id !== s.id);
  } else {
    errorSuppliers.value = result.message;
  }
  processingSupplierIds.value.delete(s.id);
  processingSupplierIds.value = new Set(processingSupplierIds.value);
}

async function fixAllCustomers() {
  if (bulkCustomersInProgress.value || customers.value.length === 0) return;
  bulkCustomersInProgress.value = true;
  bulkCustomersDone.value = 0;
  bulkCustomersTotal.value = customers.value.length;
  errorCustomers.value = '';
  const toFix = [...customers.value];
  for (const c of toFix) {
    const result = await adminStore.ensureCustomerAccount(c.id);
    if (result.status === 'success') {
      customers.value = customers.value.filter(item => item.id !== c.id);
    } else {
      const msg = result.message || 'فشل إصلاح عميل';
      errorCustomers.value = (errorCustomers.value ? errorCustomers.value + ' | ' : '') + `ID ${c.id}: ${msg}`;
    }
    bulkCustomersDone.value++;
    }
  bulkCustomersInProgress.value = false;
}

async function fixAllSuppliers() {
  if (bulkSuppliersInProgress.value || suppliers.value.length === 0) return;
  bulkSuppliersInProgress.value = true;
  bulkSuppliersDone.value = 0;
  bulkSuppliersTotal.value = suppliers.value.length;
  errorSuppliers.value = '';
  const toFix = [...suppliers.value];
  for (const s of toFix) {
    const result = await adminStore.ensureSupplierAccount(s.id);
    if (result.status === 'success') {
      suppliers.value = suppliers.value.filter(item => item.id !== s.id);
    } else {
      const msg = result.message || 'فشل إصلاح مورد';
      errorSuppliers.value = (errorSuppliers.value ? errorSuppliers.value + ' | ' : '') + `ID ${s.id}: ${msg}`;
    }
  bulkSuppliersDone.value++;  }
  bulkSuppliersInProgress.value = false;
}
</script>

<style scoped>
@keyframes loading { 0% { transform: translateX(100%); } 100% { transform: translateX(-100%); } }

.metadata-label { @apply block text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1.5; }

.custom-scroll::-webkit-scrollbar { width: 5px; height: 5px; }
.custom-scroll::-webkit-scrollbar-thumb { @apply bg-slate-200 rounded-full; }

.animate-fadeIn { animation: fadeIn 0.4s ease-out; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
</style>