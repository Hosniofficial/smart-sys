<template>
  <div class="min-h-screen bg-[#fafafa] text-slate-900 font-sans antialiased selection:bg-indigo-100" dir="rtl">

    <!-- Top Progress Bar -->
    <div v-if="loading || saving || voidLoading || loadingLists" class="fixed top-0 left-0 right-0 h-0.5 bg-indigo-600/10 z-[110]">
      <div class="h-full bg-indigo-600 animate-[loading_2s_ease-in-out_infinite] w-1/3"></div>
    </div>

    <div class="max-w-[1600px] mx-auto p-6 lg:p-10 space-y-8">

      <!-- Page Header -->
      <PageHeader
        :breadcrumb="{ parent: 'المالية', current: 'مساهمات المالك' }"
        title="مساهمات المالك"
        description="سجّل مساهمات المالك في رأس المال وقروضه للمنشأة، وراجع العمليات المالية المرتبطة بها وقيود عكسها."
        :branches="[]"
        :selectedBranch="null"
      >
        <template #controls>
          <label v-if="isPlatformSuperAdmin" class="min-w-64 text-xs font-bold text-slate-600">
            <select
              class="h-9 w-full rounded-md border border-slate-200 bg-white px-3 text-xs font-bold text-slate-700 outline-none transition-all hover:border-slate-300"
              :value="selectedTenantId"
              :disabled="loadingTenants || loadingLists || loading || saving || voidLoading"
              @change="selectTenant($event.target.value)"
            >
              <option value="">{{ loadingTenants ? 'جاري تحميل الشركات...' : 'اختر الشركة' }}</option>
              <option v-for="tenant in availableTenants" :key="tenant.id" :value="String(tenant.id)">
                {{ tenant.company_name }} — #{{ tenant.id }}
              </option>
            </select>
          </label>
          <button
            v-if="canCreate && hasTenantContext"
            class="h-9 rounded-md bg-indigo-600 px-5 text-xs font-bold text-white shadow-sm hover:bg-indigo-700 active:scale-95 transition-all disabled:opacity-50 flex items-center gap-2"
            :disabled="loadingLists"
            @click="openCreate"
          >
            <i class="fas fa-plus text-[10px]"></i> تسجيل مساهمة
          </button>
        </template>
      </PageHeader>

    <div v-if="isPlatformSuperAdmin && selectedTenant" role="status" class="mb-5 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm font-bold text-amber-900">
      سياق التشغيل الحالي: {{ selectedTenant.company_name }} — #{{ selectedTenant.id }}. جميع البيانات والعمليات في هذه الصفحة تخص هذه الشركة فقط.
    </div>
    <div v-if="isPlatformSuperAdmin && !selectedTenantId && !pageError" role="status" class="mb-5 rounded-lg border border-sky-200 bg-sky-50 p-4 text-sm font-bold text-sky-800">
      اختر شركة نشطة لمراجعة مساهماتها. لا تُحمّل أي بيانات مالية قبل اختيار الشركة.
    </div>
    <div v-if="pageError" role="alert" class="mb-5 rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-bold text-rose-700">
      {{ pageError }}
    </div>
    <div v-if="notice" role="status" class="mb-5 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm font-bold text-emerald-700">
      {{ notice }}
    </div>

    <section v-if="hasTenantContext" class="mb-5 grid grid-cols-1 gap-3 rounded-xl border border-slate-200 bg-white p-4 md:grid-cols-4">
      <label class="text-xs font-bold text-slate-600">
        نوع المساهمة
        <select v-model="filters.contribution_type" class="mt-1 h-10 w-full rounded-md border border-slate-200 px-3">
          <option value="">الكل</option>
          <option value="capital">رأس مال</option>
          <option value="owner_loan">قرض من المالك</option>
          <option value="opening_balance">رصيد افتتاحي</option>
        </select>
      </label>
      <label class="text-xs font-bold text-slate-600">
        الحالة
        <select v-model="filters.status" class="mt-1 h-10 w-full rounded-md border border-slate-200 px-3">
          <option value="">الكل</option>
          <option value="recorded">مسجلة</option>
          <option value="voided">معكوسة</option>
          <option value="settled">مسواة</option>
        </select>
      </label>
      <div class="flex items-end gap-2 md:col-span-2">
        <button class="h-10 rounded-md border border-slate-200 px-4 text-xs font-bold" :disabled="loading" @click="loadContributions(1)">
          تطبيق الفلاتر
        </button>
        <button class="h-10 rounded-md bg-slate-900 px-4 text-xs font-bold text-white" :disabled="loading" @click="loadContributions(page)">
          <i class="fas fa-sync-alt ml-1"></i> تحديث
        </button>
      </div>
    </section>

    <section v-if="hasTenantContext" class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
      <div v-if="loading && contributions.length === 0" class="p-16 text-center text-sm font-bold text-slate-400">جاري تحميل المساهمات...</div>
      <div v-else-if="!canView" class="p-16 text-center text-sm font-bold text-slate-400">
        صلاحية عرض سجل المساهمات غير متاحة لحسابك. ستظهر هنا السجلات بعد منح صلاحية العرض.
      </div>
      <div v-else-if="!contributions.length" class="p-16 text-center text-sm font-bold text-slate-400">لا توجد مساهمات مطابقة.</div>
      <div v-else class="overflow-x-auto">
        <table class="w-full min-w-[760px] text-right">
          <thead class="bg-slate-50 text-[10px] font-bold text-slate-500">
            <tr>
              <th class="px-5 py-4">رقم</th>
              <th class="px-5 py-4">النوع</th>
              <th class="px-5 py-4 text-left">المبلغ</th>
              <th class="px-5 py-4">التاريخ</th>
              <th class="px-5 py-4">الحالة</th>
              <th class="px-5 py-4">القيد</th>
              <th v-if="canVoid" class="px-5 py-4 text-center">الإجراء</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100 text-xs">
            <tr v-for="item in contributions" :key="item.id" class="hover:bg-slate-50/70">
              <td class="px-5 py-4 font-bold">#{{ item.id }}</td>
              <td class="px-5 py-4">{{ typeLabel(item.contribution_type) }}</td>
              <td class="px-5 py-4 text-left font-mono font-bold">{{ formatAmount(item.amount) }}</td>
              <td class="px-5 py-4">{{ formatDate(item.created_at) }}</td>
              <td class="px-5 py-4">
                <span :class="statusClass(item.status)" class="rounded-full border px-2 py-1 text-[10px] font-bold">
                  {{ statusLabel(item.status) }}
                </span>
              </td>
              <td class="px-5 py-4 font-mono">{{ item.journal_entry_id || '—' }}</td>
              <td v-if="canVoid" class="px-5 py-4 text-center">
                <button
                  v-if="item.status === 'recorded'"
                  class="rounded border border-rose-200 px-3 py-1.5 text-[10px] font-bold text-rose-700 hover:bg-rose-50 disabled:opacity-50"
                  :disabled="voidLoading"
                  @click="openVoid(item)"
                >
                  عكس المساهمة
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <footer class="flex items-center justify-between border-t border-slate-100 px-5 py-4 text-xs font-bold text-slate-500">
        <span>{{ total }} مساهمة</span>
        <div class="flex gap-2">
          <button class="rounded border px-3 py-2 disabled:opacity-40" :disabled="page <= 1 || loading" @click="loadContributions(page - 1)">السابق</button>
          <span class="px-2 py-2">{{ page }}</span>
          <button class="rounded border px-3 py-2 disabled:opacity-40" :disabled="page * pageSize >= total || loading" @click="loadContributions(page + 1)">التالي</button>
        </div>
      </footer>
    </section>

    <div v-if="showCreate && hasTenantContext" class="fixed inset-0 z-[120] flex items-center justify-center bg-slate-950/50 p-4" @click.self="closeCreate">
      <section class="max-h-[92vh] w-full max-w-2xl overflow-auto rounded-xl bg-white p-6 shadow-2xl">
        <div class="mb-5 flex items-center justify-between">
          <h2 class="text-lg font-bold">تسجيل مساهمة مالك</h2>
          <button class="text-slate-400 hover:text-slate-900" :disabled="saving" @click="closeCreate" aria-label="إغلاق">✕</button>
        </div>
        <div v-if="formError" role="alert" class="mb-4 rounded-md bg-rose-50 p-3 text-xs font-bold text-rose-700">{{ formError }}</div>
        <form class="grid grid-cols-1 gap-4 md:grid-cols-2" @submit.prevent="submitCreate">
          <label class="text-xs font-bold text-slate-600">
            نوع المساهمة
            <select v-model="form.contribution_type" required class="mt-1 h-10 w-full rounded-md border border-slate-200 px-3" @change="form.related_gl_account_id = ''">
              <option value="capital">رأس مال</option>
              <option value="owner_loan">قرض من المالك</option>
              <option value="opening_balance">رصيد افتتاحي</option>
            </select>
          </label>
          <label class="text-xs font-bold text-slate-600">
            المبلغ
            <input v-model.number="form.amount" type="number" min="0.01" step="0.01" required class="mt-1 h-10 w-full rounded-md border border-slate-200 px-3" />
          </label>
          <label class="text-xs font-bold text-slate-600">
            حساب السيولة (أصول)
            <select v-model="form.target_account_id" required class="mt-1 h-10 w-full rounded-md border border-slate-200 px-3">
              <option value="">اختر الحساب</option>
              <option v-for="account in assetAccounts" :key="account.id" :value="String(account.id)">{{ account.code }} — {{ account.name }}</option>
            </select>
          </label>
          <label class="text-xs font-bold text-slate-600">
            الحساب المقابل ({{ relatedAccountTypeLabel }})
            <select v-model="form.related_gl_account_id" required class="mt-1 h-10 w-full rounded-md border border-slate-200 px-3">
              <option value="">اختر الحساب</option>
              <option v-for="account in relatedAccounts" :key="account.id" :value="String(account.id)">{{ account.code }} — {{ account.name }}</option>
            </select>
          </label>
          <template v-if="form.contribution_type === 'owner_loan'">
            <label class="text-xs font-bold text-slate-600">
              نسبة الفائدة (%)
              <input v-model.number="form.interest_rate" type="number" min="0" max="100" step="0.01" class="mt-1 h-10 w-full rounded-md border border-slate-200 px-3" />
            </label>
            <label class="text-xs font-bold text-slate-600">
              تاريخ الاستحقاق
              <input v-model="form.due_date" type="date" class="mt-1 h-10 w-full rounded-md border border-slate-200 px-3" />
            </label>
            <label class="text-xs font-bold text-slate-600 md:col-span-2">
              شروط السداد
              <input v-model="form.repayment_terms" type="text" maxlength="1000" class="mt-1 h-10 w-full rounded-md border border-slate-200 px-3" />
            </label>
            <label class="text-xs font-bold text-slate-600 md:col-span-2">
              جدول السداد
              <select v-model="form.repayment_schedule" class="mt-1 h-10 w-full rounded-md border border-slate-200 px-3">
                <option value="">غير محدد</option>
                <option value="monthly">شهري</option>
                <option value="quarterly">ربع سنوي</option>
                <option value="at_due_date">عند الاستحقاق</option>
              </select>
            </label>
          </template>
          <div class="flex justify-end gap-2 border-t border-slate-100 pt-4 md:col-span-2">
            <button type="button" class="h-10 rounded-md px-4 text-xs font-bold text-slate-500" :disabled="saving" @click="closeCreate">إلغاء</button>
            <button type="submit" class="h-10 rounded-md bg-indigo-600 px-5 text-xs font-bold text-white disabled:opacity-50" :disabled="saving || !assetAccounts.length || !relatedAccounts.length">
              {{ saving ? 'جاري الحفظ...' : (pendingCreate ? 'إعادة محاولة الحفظ' : 'حفظ المساهمة') }}
            </button>
          </div>
        </form>
      </section>
    </div>

    <div v-if="showVoid && hasTenantContext" class="fixed inset-0 z-[120] flex items-center justify-center bg-slate-950/50 p-4" @click.self="closeVoid">
      <section class="w-full max-w-lg rounded-xl bg-white p-6 shadow-2xl">
        <div class="mb-4 flex items-center justify-between">
          <h2 class="text-lg font-bold">عكس المساهمة #{{ selectedContribution?.id }}</h2>
          <button class="text-slate-400 hover:text-slate-900" :disabled="voidLoading" @click="closeVoid" aria-label="إغلاق">✕</button>
        </div>
        <p class="mb-4 text-sm text-slate-600">سيُنشأ قيد عكسي، ويلزم اختيار طريقة الدفع المرتبطة بحساب السيولة الأصلي.</p>
        <div v-if="voidError" role="alert" class="mb-4 rounded-md bg-rose-50 p-3 text-xs font-bold text-rose-700">{{ voidError }}</div>
        <form class="space-y-4" @submit.prevent="submitVoid">
          <label class="block text-xs font-bold text-slate-600">
            طريقة الدفع للحساب الأصلي
            <select v-model="voidForm.payment_method_id" required class="mt-1 h-10 w-full rounded-md border border-slate-200 px-3">
              <option value="">اختر طريقة الدفع</option>
              <option v-for="method in voidPaymentMethods" :key="method.id" :value="String(method.id)">{{ method.name }}</option>
            </select>
          </label>
          <label v-if="isCashVoid" class="block text-xs font-bold text-slate-600">
            الفرع
            <select v-model="voidForm.branch_id" required class="mt-1 h-10 w-full rounded-md border border-slate-200 px-3" :disabled="!canSelectBranches">
              <option value="">اختر الفرع</option>
              <option v-for="branch in branches" :key="branch.id" :value="String(branch.id)">{{ branch.name }}</option>
            </select>
          </label>
          <label class="block text-xs font-bold text-slate-600">
            سبب العكس
            <input v-model="voidForm.reason" type="text" maxlength="500" class="mt-1 h-10 w-full rounded-md border border-slate-200 px-3" />
          </label>
          <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
            <button type="button" class="h-10 rounded-md px-4 text-xs font-bold text-slate-500" :disabled="voidLoading" @click="closeVoid">إلغاء</button>
            <button type="submit" class="h-10 rounded-md bg-rose-600 px-5 text-xs font-bold text-white disabled:opacity-50" :disabled="voidLoading || !voidPaymentMethods.length">
              {{ voidLoading ? 'جاري العكس...' : 'تأكيد العكس' }}
            </button>
          </div>
        </form>
      </section>
    </div>

    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import { useAuthStore } from '@/stores/auth/authStore';
import { useTenantContextStore } from '@/stores/tenantContext/tenantContextStore';
import { useAccountStore } from '@/stores/account/accountStore';
import { useBranchStore } from '@/stores/branch/branchStore';
import { useOwnerContributionStore } from '@/stores/accounting/ownerContributionStore';
import { usePaymentStore } from '@/stores/payment/paymentStore';
import { useSessionStore } from '@/stores/session/sessionStore';
import { useSessionExemption } from '@/composables/useCashierSessionGuard';
import { useBranchIsolation } from '@/composables/useBranchIsolation';
import { useCompanyCurrency } from '@/composables/useCompanyCurrency';
import { isSettingEnabled } from '@/utils';
import PageHeader from '@/components/PageHeader.vue';

const permissionNames = {
  create: 'accounting.owner_contribution.create',
  view: 'accounting.owner_contribution.view',
  void: 'accounting.owner_contribution.void',
};
const authStore = useAuthStore();
const tenantContextStore = useTenantContextStore();
const accountStore = useAccountStore();
const branchStore = useBranchStore();
const contributionStore = useOwnerContributionStore();
const paymentStore = usePaymentStore();
const sessionStore = useSessionStore();
const branchIsolation = useBranchIsolation();
const { isExempt, ensureLoaded: ensureExemptionLoaded } = useSessionExemption();
const { fetchSettings } = useCompanyCurrency();

const userPermissions = computed(() => {
  const permissions = authStore.user?.permissions;
  if (!Array.isArray(permissions)) return [];
  return permissions.map(permission => typeof permission === 'string' ? permission : permission?.name).filter(Boolean);
});
const isPlatformSuperAdmin = computed(() => authStore.isSuperAdmin);
const hasTenantContext = computed(() => !isPlatformSuperAdmin.value || Number(selectedTenantId.value) > 0);
const canCreate = computed(() => userPermissions.value.includes(permissionNames.create));
const canView = computed(() => userPermissions.value.includes(permissionNames.view));
const canVoid = computed(() => userPermissions.value.includes(permissionNames.void));
const canSelectBranches = branchIsolation.isExempt;
const branches = computed(() => branchStore.branches || []);
const accounts = ref([]);
const paymentMethods = computed(() => paymentStore.paymentMethods || []);
const availableTenants = ref([]);
const selectedTenantId = ref('');
const loadingTenants = ref(false);
const contributions = ref([]);
const page = ref(1);
const pageSize = 50;
const total = ref(0);
const loading = ref(false);
const loadingLists = ref(false);
const saving = ref(false);
const voidLoading = ref(false);
const pageError = ref('');
const notice = ref('');
const formError = ref('');
const voidError = ref('');
const showCreate = ref(false);
const showVoid = ref(false);
const selectedContribution = ref(null);
const filters = reactive({ contribution_type: '', status: '' });
const form = reactive({
  contribution_type: 'capital',
  amount: '',
  target_account_id: '',
  related_gl_account_id: '',
  interest_rate: '',
  due_date: '',
  repayment_terms: '',
  repayment_schedule: '',
});
const voidForm = reactive({ payment_method_id: '', branch_id: '', reason: '' });
const pendingCreateStorageKey = computed(() =>
  `pending_owner_contribution:${selectedTenantId.value || authStore.user?.tenant_id || 'no-tenant'}:${authStore.user?.id || 'no-user'}`
);
const readPendingCreate = () => {
  try {
    const saved = JSON.parse(sessionStorage.getItem(pendingCreateStorageKey.value) || 'null');
    return saved?.key && saved?.payload ? saved : null;
  } catch {
    return null;
  }
};
const pendingCreate = ref(readPendingCreate());
const persistPendingCreate = (pending) => {
  try {
    if (pending) sessionStorage.setItem(pendingCreateStorageKey.value, JSON.stringify(pending));
    else sessionStorage.removeItem(pendingCreateStorageKey.value);
    pendingCreate.value = pending;
    return true;
  } catch {
    return false;
  }
};

const mappedLiquidityAccountIds = computed(() => new Set(
  paymentMethods.value
    .map(method => Number(method.account_id))
    .filter(accountId => accountId > 0)
));
const selectedTenant = computed(() =>
  availableTenants.value.find(tenant => String(tenant.id) === selectedTenantId.value) || null
);
const assetAccounts = computed(() => accounts.value.filter(account =>
  account.type === 'asset' && mappedLiquidityAccountIds.value.has(Number(account.id))
));
const relatedAccountType = computed(() => form.contribution_type === 'owner_loan' ? 'liability' : 'equity');
const relatedAccountTypeLabel = computed(() => relatedAccountType.value === 'equity' ? 'حقوق الملكية' : 'الالتزامات');
const relatedAccounts = computed(() => accounts.value.filter(account => account.type === relatedAccountType.value));
const voidPaymentMethods = computed(() => paymentMethods.value.filter(method =>
  Number(method.account_id) === Number(selectedContribution.value?.target_account_id)
));
const selectedVoidMethod = computed(() => voidPaymentMethods.value.find(method =>
  String(method.id) === String(voidForm.payment_method_id)
));
const isCashVoid = computed(() => String(selectedVoidMethod.value?.kind || '').toLowerCase() === 'cash');

const typeLabel = type => ({
  capital: 'رأس مال',
  owner_loan: 'قرض من المالك',
  opening_balance: 'رصيد افتتاحي',
}[type] || type || '—');
const statusLabel = status => ({
  recorded: 'مسجلة',
  voided: 'معكوسة',
  settled: 'مسواة',
}[status] || status || '—');
const statusClass = status => status === 'recorded'
  ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
  : status === 'voided'
    ? 'border-rose-200 bg-rose-50 text-rose-700'
    : 'border-slate-200 bg-slate-50 text-slate-600';
const formatAmount = amount => new Intl.NumberFormat('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(amount) || 0);
const formatDate = date => date ? String(date).slice(0, 10) : '—';

const loadContributions = async (requestedPage = 1) => {
  if (!canView.value) {
    pageError.value = 'لا تملك صلاحية عرض مساهمات المالك.';
    return;
  }
  loading.value = true;
  pageError.value = '';
  try {
    const result = await contributionStore.fetchContributions({
      limit: pageSize,
      offset: (requestedPage - 1) * pageSize,
      contribution_type: filters.contribution_type || undefined,
      status: filters.status || undefined,
    });
    if (result.status !== 'success') throw new Error(result.message || 'تعذر تحميل المساهمات.');
    contributions.value = result.data?.data || [];
    total.value = Number(result.data?.total) || 0;
    page.value = requestedPage;
  } catch (error) {
    pageError.value = error.message || 'تعذر تحميل المساهمات.';
  } finally {
    loading.value = false;
  }
};

const loadLists = async () => {
  loadingLists.value = true;
  try {
    const [accountResult, methodResult] = await Promise.all([
      accountStore.fetchGroupedAccounts({ force: true }),
      paymentStore.fetchPaymentMethods({ force: true }),
      branchStore.fetchBranches(true),
      ensureExemptionLoaded(),
    ]);
    if (accountResult.status !== 'success') throw new Error(accountResult.message || 'تعذر تحميل الحسابات.');
    if (methodResult.status !== 'success') throw new Error(methodResult.message || 'تعذر تحميل طرق الدفع.');
    accounts.value = accountResult.data?.tenant || [];
    return true;
  } catch (error) {
    pageError.value = error.message || 'تعذر تحميل القوائم المالية.';
    return false;
  } finally {
    loadingLists.value = false;
  }
};

const loadAvailableTenants = async () => {
  loadingTenants.value = true;
  pageError.value = '';
  try {
    const result = await contributionStore.fetchAvailableTenants();
    if (result.status !== 'success') throw new Error(result.message || 'تعذر تحميل الشركات النشطة.');
    availableTenants.value = Array.isArray(result.data) ? result.data : [];
    if (!availableTenants.value.length) pageError.value = 'لا توجد شركات نشطة متاحة للاختيار.';
  } catch (error) {
    pageError.value = error.message || 'تعذر تحميل الشركات النشطة.';
  } finally {
    loadingTenants.value = false;
  }
};

const selectTenant = async (tenantId) => {
  const tenant = availableTenants.value.find(item => String(item.id) === String(tenantId));
  if (!tenant) {
    selectedTenantId.value = '';
    tenantContextStore.clearActiveTenantId();
    contributions.value = [];
    total.value = 0;
    accounts.value = [];
    pageError.value = '';
    return;
  }

  if (saving.value || voidLoading.value || loadingLists.value || loading.value) return;
  selectedTenantId.value = String(tenant.id);
  tenantContextStore.setActiveTenantId(tenant.id);
  pendingCreate.value = readPendingCreate();
  showCreate.value = false;
  showVoid.value = false;
  selectedContribution.value = null;
  contributions.value = [];
  total.value = 0;
  page.value = 1;
  pageError.value = '';
  notice.value = '';
  const listsLoaded = await loadLists();
  if (listsLoaded && canView.value) await loadContributions(1);
};

const resetCreateForm = () => {
  Object.assign(form, {
    contribution_type: 'capital',
    amount: '',
    target_account_id: '',
    related_gl_account_id: '',
    interest_rate: '',
    due_date: '',
    repayment_terms: '',
    repayment_schedule: '',
  });
  formError.value = '';
};
const openCreate = () => {
  resetCreateForm();
  if (pendingCreate.value) {
    Object.assign(form, pendingCreate.value.payload);
    formError.value = 'هناك طلب سابق لم تتأكد نتيجته. أعد المحاولة بنفس البيانات لتجنب تكرار القيد.';
  }
  showCreate.value = true;
};
const closeCreate = () => {
  if (saving.value) return;
  showCreate.value = false;
};
const submitCreate = async () => {
  if (!canCreate.value || saving.value) return;
  formError.value = '';
  const payload = {
    contribution_type: form.contribution_type,
    amount: Number(form.amount),
    target_account_id: Number(form.target_account_id),
    related_gl_account_id: Number(form.related_gl_account_id),
  };
  if (form.contribution_type === 'owner_loan') {
    if (form.interest_rate !== '') payload.interest_rate = Number(form.interest_rate);
    if (form.due_date) payload.due_date = form.due_date;
    if (form.repayment_terms) payload.repayment_terms = form.repayment_terms;
    if (form.repayment_schedule) payload.repayment_schedule = form.repayment_schedule;
  }
  if (pendingCreate.value && JSON.stringify(payload) !== JSON.stringify(pendingCreate.value.payload)) {
    formError.value = 'بيانات المساهمة تغيّرت بعد طلب غير مؤكد. استعد البيانات الأصلية أو تحقق من السجل قبل تسجيل مساهمة جديدة.';
    return;
  }
  if (!pendingCreate.value) {
    const pending = {
      key: globalThis.crypto?.randomUUID?.() || `owner-contribution-${Date.now()}-${Math.random().toString(36).slice(2)}`,
      payload,
    };
    if (!persistPendingCreate(pending)) {
      formError.value = 'تعذر حفظ مفتاح أمان الطلب في جلسة المتصفح؛ لم يتم إرسال المساهمة.';
      return;
    }
  }
  saving.value = true;
  try {
    const result = await contributionStore.createContribution({
      ...pendingCreate.value.payload,
      idempotency_key: pendingCreate.value.key,
    });
    if (result.status !== 'success') {
      formError.value = result.outcomeUnknown
        ? 'تعذر تأكيد نتيجة الحفظ؛ أعد المحاولة دون تغيير البيانات.'
        : (result.message || 'تعذر تسجيل المساهمة.');
      if (!result.outcomeUnknown) persistPendingCreate(null);
      return;
    }
    persistPendingCreate(null);
    showCreate.value = false;
    resetCreateForm();
    notice.value = 'تم تسجيل المساهمة والقيد المحاسبي بنجاح.';
    if (canView.value) await loadContributions(1);
  } finally {
    saving.value = false;
  }
};

const openVoid = async (item) => {
  if (!canVoid.value || voidLoading.value) return;
  voidError.value = '';
  notice.value = '';
  const result = await contributionStore.fetchContribution(item.id);
  if (result.status !== 'success') {
    pageError.value = result.message || 'تعذر تحميل تفاصيل المساهمة.';
    return;
  }
  selectedContribution.value = result.data;
  const targetMethod = paymentMethods.value.find(method =>
    Number(method.account_id) === Number(result.data.target_account_id)
  );
  Object.assign(voidForm, {
    payment_method_id: targetMethod ? String(targetMethod.id) : '',
    branch_id: '',
    reason: '',
  });
  showVoid.value = true;
};
const closeVoid = () => {
  if (voidLoading.value) return;
  showVoid.value = false;
  selectedContribution.value = null;
  Object.assign(voidForm, { payment_method_id: '', branch_id: '', reason: '' });
};
const submitVoid = async () => {
  if (!canVoid.value || voidLoading.value || !selectedContribution.value) return;
  voidError.value = '';
  let branchId = voidForm.branch_id || null;
  if (isCashVoid.value) {
    if (!canSelectBranches.value) {
      try {
        branchId = branchIsolation.getRequiredBranchId();
      } catch (error) {
        voidError.value = error.message || 'يجب تحديد الفرع لعكس المساهمة نقدًا.';
        return;
      }
    }
    try {
      const settings = await fetchSettings(true);
      if (isSettingEnabled(settings?.['pos.sessions.enabled']) && !isExempt.value) {
        if (!branchId) {
          voidError.value = 'يجب تحديد الفرع لعكس المساهمة نقدًا.';
          return;
        }
        const session = await sessionStore.getCurrentSession(String(branchId), authStore.user?.id || null, undefined, true);
        if (!session.data?.id) {
          voidError.value = session.message || 'لا توجد جلسة كاشير مفتوحة لهذا الفرع.';
          return;
        }
      }
    } catch (error) {
      voidError.value = error.message || 'تعذر التحقق من جلسة الكاشير.';
      return;
    }
  }

  voidLoading.value = true;
  try {
    const result = await contributionStore.voidContribution(selectedContribution.value.id, {
      payment_method_id: Number(voidForm.payment_method_id),
      branch_id: branchId ? Number(branchId) : undefined,
      reason: voidForm.reason || '',
    });
    if (result.status !== 'success') {
      voidError.value = result.message || 'تعذر عكس المساهمة.';
      return;
    }
    closeVoid();
    notice.value = 'تم عكس المساهمة وإنشاء القيد العكسي.';
    await loadContributions(page.value);
  } catch (error) {
    voidError.value = error.response?.data?.message || error.message || 'تعذر عكس المساهمة.';
  } finally {
    voidLoading.value = false;
  }
};

onMounted(async () => {
  if (isPlatformSuperAdmin.value) {
    await loadAvailableTenants();
    return;
  }
  await loadLists();
  if (canView.value) await loadContributions();
});

onUnmounted(() => {
  tenantContextStore.clearActiveTenantId();
});
</script>
