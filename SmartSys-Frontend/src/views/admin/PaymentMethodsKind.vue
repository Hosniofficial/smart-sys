<template>
  <div class="min-h-screen bg-[#fafafa] text-slate-900 font-sans antialiased selection:bg-blue-100" dir="rtl">
    
    <!-- Top Progress Bar -->
    <div v-if="loading" class="fixed top-0 left-0 right-0 h-0.5 bg-blue-600/10 z-[110]">
      <div class="h-full bg-blue-600 animate-[loading_2s_ease-in-out_infinite] w-1/3"></div>
    </div>

    <div class="max-w-[1600px] mx-auto p-6 lg:p-10 space-y-8">
      
      <!-- Page Header -->
      <PageHeader
        :breadcrumb="breadcrumb"
        title="إدارة طرق الدفع"
        description="أضف طرق الدفع المتاحة في النظام، ونظّمها، واربط كل طريقة بالحساب المحاسبي المناسب."
        :branches="[]"
        :selectedBranch="null"
      >
        <template #controls>
          <button @click="load" :disabled="loading" class="h-9 w-9 flex items-center justify-center rounded-md border border-slate-200 bg-white text-slate-400 hover:text-blue-600 transition-colors shadow-sm">
            <i class="fas fa-sync-alt text-[10px]" :class="{'animate-spin': loading}"></i>
          </button>
          <button @click="openCreate" class="h-9 px-4 rounded-md bg-blue-600 text-white text-xs font-bold shadow-sm hover:bg-blue-700 transition-all active:scale-95 flex items-center gap-2">
            <i class="fas fa-plus-circle text-[10px]"></i>
            إضافة طريقة دفع
          </button>
        </template>
      </PageHeader>

      <!-- KPI Summary Section -->
      <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
        <div v-for="kpi in [
          { label: 'إجمالي الطرق', val: methods.length, icon: 'fa-list-ul', color: 'text-blue-600', bg: 'bg-blue-50' },
          { label: 'طرق نقدية', val: methods.filter(m => m._kind === 'cash').length, icon: 'fa-money-bill-1', color: 'text-emerald-600', bg: 'bg-emerald-50' },
          { label: 'تحويلات / بطاقات', val: methods.filter(m => m._kind === 'bank' || m._kind === 'card').length, icon: 'fa-university', color: 'text-indigo-600', bg: 'bg-indigo-50' },
          { label: 'الربط المحاسبي', val: `${methods.filter(m => m._account_id).length} / ${methods.length}`, icon: 'fa-link', color: 'text-violet-600', bg: 'bg-violet-50' }
        ]" :key="kpi.label" class="bg-white border border-slate-200 p-5 rounded-xl flex items-center justify-between group hover:border-slate-300 transition-all shadow-sm">
          <div>
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">{{ kpi.label }}</p>
            <p class="text-xl font-bold text-slate-900 font-mono">{{ kpi.val }}</p>
          </div>
          <div :class="[kpi.bg, kpi.color]" class="w-10 h-10 rounded-lg flex items-center justify-center text-sm opacity-80 group-hover:opacity-100 transition-opacity">
            <i :class="['fas', kpi.icon]"></i>
          </div>
        </div>
      </section>

      <!-- Error Handling -->
      <transition name="slide-down">
        <div v-if="error" class="bg-white border-r-4 border-rose-500 rounded-lg p-4 shadow-lg flex items-center justify-between">
          <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-full bg-rose-50 flex items-center justify-center text-rose-600">
              <i class="fas fa-exclamation-circle text-sm"></i>
            </div>
            <div>
              <p class="text-sm font-bold text-slate-900">خطأ في النظام</p>
              <p class="text-xs text-slate-500">{{ error }}</p>
            </div>
          </div>
          <button @click="error = ''" class="p-1.5 text-slate-400 hover:text-slate-600 transition-colors">
            <i class="fas fa-times text-xs"></i>
          </button>
        </div>
      </transition>

      <!-- Main Settings Grid: Utility Table -->
      <div class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm relative min-h-[400px]">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
           <h3 class="text-xs font-bold text-slate-900 uppercase tracking-widest flex items-center gap-2">
             <i class="fas fa-cog text-slate-400"></i>
             قائمة التكوين النشطة
           </h3>
           <span v-if="lastRefreshed" class="text-[9px] font-bold text-slate-400 uppercase tracking-widest">مزامنة: {{ lastRefreshed }}</span>
        </div>

        <div class="overflow-x-auto">
          <table class="w-full text-right border-collapse">
            <thead>
              <tr class="bg-slate-50/50 border-b border-slate-200">
                <th class="px-6 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest w-16 text-center">#</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">الوسيلة / الطريقة</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">التصنيف التقني</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">الربط مع GL</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">حدود الرصيد</th>
                <th class="px-8 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">التحكم</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 font-medium">
              <!-- Loading State -->
              <template v-if="loading && !methods.length">
                <tr v-for="n in 5" :key="n" class="animate-pulse">
                  <td v-for="m in 6" :key="m" class="px-6 py-4"><div class="h-3 bg-slate-100 rounded w-full"></div></td>
                </tr>
              </template>
              
              <!-- Empty State -->
              <tr v-else-if="methods.length === 0">
                <td colspan="6" class="py-24 text-center text-slate-300">
                   <i class="fas fa-credit-card text-3xl mb-4 opacity-20"></i>
                   <p class="text-xs font-bold uppercase tracking-widest">لا توجد طرق دفع معرفة</p>
                </td>
              </tr>

              <!-- Data Rows -->
              <tr v-for="(m, idx) in methods" :key="m.id" class="hover:bg-blue-50/10 transition-all group">
                <td class="px-6 py-4 text-center font-mono text-[10px] text-slate-400">{{ idx + 1 }}</td>
                <td class="px-4 py-4">
                  <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-lg bg-slate-50 flex items-center justify-center border border-slate-100 group-hover:bg-white transition-colors">
                      <i :class="[iconForKind(m._kind), 'text-xs']"></i>
                    </div>
                    <div>
                      <p class="text-xs font-bold text-slate-900 leading-none">{{ m.name }}</p>
                      <p v-if="m.description" class="text-[9px] text-slate-400 mt-1 italic truncate max-w-[200px]">{{ m.description }}</p>
                    </div>
                  </div>
                </td>
                <td class="px-4 py-4">
                  <select v-model="m._kind" class="filter-input-v3 h-8 bg-transparent hover:bg-white border-transparent hover:border-slate-200">
                    <option v-for="k in kinds" :key="k" :value="k">{{ kindLabel(k) }}</option>
                  </select>
                </td>
                <td class="px-4 py-4">
                  <div class="flex flex-col gap-1.5">
                    <select v-model.number="m._account_id" class="filter-input-v3 h-8 text-[10px] bg-transparent hover:bg-white border-transparent hover:border-slate-200">
                      <option :value="null">-- غير مربوط --</option>
                      <option v-for="acc in accounts" :key="acc.id" :value="acc.id">{{ acc.code }} - {{ acc.name }}</option>
                    </select>
                    <div class="flex items-center gap-1.5 px-1">
                      <span v-if="m._account_id" class="text-[8px] font-bold text-indigo-500 uppercase tracking-widest flex items-center gap-1">
                        <i class="fas fa-link"></i> متصل محاسبياً
                      </span>
                      <span v-else class="text-[8px] font-bold text-amber-500 uppercase tracking-widest flex items-center gap-1">
                        <i class="fas fa-unlink"></i> ربط يدوي مطلوب
                      </span>
                    </div>
                  </div>
                </td>
                <td class="px-4 py-3 min-w-[180px]">
                  <div v-if="m._kind === 'bank'" class="space-y-1">
                    <label class="text-[9px] text-slate-400 font-bold">حد السحب على المكشوف</label>
                    <input v-model.number="m._overdraft_limit" type="number" min="0" step="0.01" class="filter-input-v3 h-8" placeholder="0 = ممنوع" />
                  </div>
                  <div v-else-if="m._kind === 'wallet'" class="space-y-1.5">
                    <select v-model="m._wallet_type" class="filter-input-v3 h-8 text-[10px]">
                      <option value="prepaid">مسبقة الدفع</option>
                      <option value="postpaid">لاحقة الدفع</option>
                    </select>
                    <input v-if="m._wallet_type === 'postpaid'" v-model.number="m._credit_limit" type="number" min="0.01" step="0.01" class="filter-input-v3 h-8" placeholder="حد الائتمان" />
                  </div>
                  <span v-else class="text-[9px] text-slate-400">{{ m._kind === 'cash' ? 'السالب من إعداد POS والجلسة' : 'لا يسمح بالسالب' }}</span>
                </td>
                <td class="px-8 py-4 text-center">
                  <button @click="saveMethod(m)" :disabled="m._saving" class="h-8 px-5 rounded-md bg-blue-600 text-white text-[10px] font-bold uppercase shadow-sm hover:bg-blue-700 disabled:opacity-50 transition-all flex items-center justify-center gap-2 mx-auto">
                    <BaseSpinner v-if="m._saving" size="12" color="#fff" />
                    <i v-else class="fas fa-save text-[9px]"></i>
                    تحديث
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Create Modal: Restructured for Technical Clarity -->
    <transition name="fade">
      <div v-if="showCreate" class="fixed inset-0 z-[120] flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-md">
        <div class="bg-white w-full max-w-2xl rounded-xl shadow-2xl overflow-hidden border border-slate-200 animate-modalIn flex flex-col max-h-[90vh]">
          <div class="px-8 py-5 border-b border-slate-100 bg-slate-50/50 flex items-center justify-between">
            <div class="flex items-center gap-3">
              <div class="w-8 h-8 bg-blue-600 rounded-lg flex items-center justify-center text-white"><i class="fas fa-plus text-xs"></i></div>
              <h3 class="text-sm font-bold text-slate-900 uppercase">إصدار وسيلة دفع جديدة</h3>
            </div>
            <button @click="closeCreate" class="text-slate-400 hover:text-slate-900 transition-colors"><i class="fas fa-times text-lg"></i></button>
          </div>

          <div class="p-8 overflow-y-auto custom-scroll space-y-10">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
              <!-- General Info -->
              <div class="space-y-6">
                 <div class="space-y-1.5 group">
                    <label class="metadata-label">اسم الطريقة <span class="text-rose-500">*</span></label>
                    <div class="relative">
                      <input v-model="form.name" type="text" class="filter-input-v2 h-10 font-bold pr-9" placeholder="مثال: تحويل الراجحي" required />
                      <i class="fas fa-signature absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 group-focus-within:text-blue-500 transition-colors text-[10px]"></i>
                    </div>
                 </div>

                 <div class="space-y-1.5">
                    <label class="metadata-label">النوع الفني <span class="text-rose-500">*</span></label>
                    <select v-model="form.kind" class="filter-input-v2 h-10 font-bold appearance-none">
                       <option v-for="k in kinds" :key="k" :value="k">{{ kindLabel(k) }}</option>
                    </select>
                 </div>

                 <div class="space-y-1.5">
                    <label class="metadata-label">ملاحظات داخلية</label>
                    <textarea v-model="form.description" rows="2" class="filter-input-v2 h-auto py-2 italic" placeholder="اختياري..."></textarea>
                 </div>
              </div>

              <!-- Integration Settings -->
              <div class="space-y-6">
                 <div class="space-y-1.5">
                    <label class="metadata-label">شروط السداد</label>
                    <input v-model="form.payment_terms" type="text" class="filter-input-v2 h-10" placeholder="مثال: فوري، 30 يوم..." />
                 </div>

                  <div v-if="form.kind === 'bank'" class="space-y-1.5">
                    <label class="metadata-label">حد السحب على المكشوف</label>
                    <input v-model.number="form.overdraft_limit" type="number" min="0" step="0.01" class="filter-input-v2 h-10" placeholder="0 = منع السالب" />
                  </div>

                  <div v-if="form.kind === 'wallet'" class="space-y-3">
                    <label class="metadata-label">سياسة المحفظة</label>
                    <select v-model="form.wallet_type" class="filter-input-v2 h-10">
                     <option value="prepaid">مسبقة الدفع</option>
                     <option value="postpaid">لاحقة الدفع</option>
                    </select>
                    <input v-if="form.wallet_type === 'postpaid'" v-model.number="form.credit_limit" type="number" min="0.01" step="0.01" class="filter-input-v2 h-10" placeholder="حد الائتمان" />
                  </div>

                 <div class="p-6 bg-slate-50 border border-slate-200 rounded-xl space-y-5">
                    <h4 class="text-[10px] font-bold text-slate-400 uppercase tracking-widest flex items-center gap-2 border-b border-slate-200 pb-3">
                      <i class="fas fa-link text-blue-500"></i>
                      الربط المحاسبي التلقائي
                    </h4>
                    <div class="space-y-3">
                       <div class="relative group">
                         <input v-model="accountSearch" type="text" class="h-8 w-full bg-white border border-slate-200 rounded-md pr-8 pl-3 text-[10px] font-bold outline-none focus:border-blue-500" placeholder="البحث في الدليل..." />
                         <i class="fas fa-magnifying-glass absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-300 text-[9px]"></i>
                       </div>
                       <select v-model.number="form.account_id" class="filter-input-v2 h-9 text-[11px] font-bold appearance-none">
                          <option :value="null">-- بدون ربط مباشر --</option>
                          <option v-for="acc in filteredAccounts" :key="acc.id" :value="acc.id">{{ acc.code }} - {{ acc.name }}</option>
                       </select>
                       <p v-if="accountsLoading" class="text-[8px] font-bold text-blue-500 flex items-center gap-2 animate-pulse">
                         <i class="fas fa-spinner fa-spin"></i> مزامنة شجرة الحسابات...
                       </p>
                    </div>
                 </div>
              </div>
            </div>
          </div>

          <div class="px-8 py-4 bg-slate-50 border-t border-slate-200 flex justify-end gap-3 shrink-0">
            <button @click="closeCreate" class="px-6 h-10 text-xs font-bold text-slate-500">إلغاء</button>
            <button @click="create" :disabled="creating" class="px-10 h-10 bg-blue-600 text-white rounded-md text-xs font-bold shadow-lg shadow-blue-900/20 active:scale-95 transition-all flex items-center gap-2">
              <BaseSpinner v-if="creating" size="16" color="#fff" />
              <span>تأكيد الإضافة</span>
            </button>
          </div>
        </div>
      </div>
    </transition>

  </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue';
import { usePaymentStore } from '@/stores/payment/paymentStore';
import { useAccountStore } from '@/stores/account/accountStore';
import AlertService from '@/services/AlertService';
import BaseSpinner from '@/components/ui/BaseSpinner.vue';
import BaseSkeleton from '@/components/ui/BaseSkeleton.vue';;
import { useBreadcrumb } from '@/composables/useBreadcrumb';
import PageHeader from '@/components/PageHeader.vue';

const { breadcrumb } = useBreadcrumb();
const paymentStore = usePaymentStore();
const accountStore = useAccountStore();
const loading = ref(false);
const error = ref('');
const methods = ref([]);
const accounts = ref([]);
const lastRefreshed = ref('');
const kinds = ['cash','bank','card','credit','wallet','other'];

const form = ref({ name: '', kind: 'cash', description: '', payment_terms: '', account_id: null, overdraft_limit: null, wallet_type: 'prepaid', credit_limit: null });
const creating = ref(false);
const showCreate = ref(false);
const accountsLoading = ref(false);
const accountSearch = ref('');
const accountGroup = ref('10');

const filteredAccounts = computed(() => {
  const q = accountSearch.value.trim().toLowerCase();
  const g = accountGroup.value.trim();
  return accounts.value.filter(a => {
    const matchGroup = g ? String(a.account_group_code) === g : true;
    if (!q) return matchGroup;
    const text = `${a.code || ''} ${a.name || ''}`.toLowerCase();
    return matchGroup && text.includes(q);
  });
});

function kindLabel(k) {
  switch (k) {
    case 'cash': return 'نقدي';
    case 'bank': return 'تحويل بنكي';
    case 'card': return 'بطاقة';
    case 'credit': return 'آجل/دين';
    case 'wallet': return 'محفظة إلكترونية';
    default: return 'أخرى';
  }
}

function iconForKind(k) {
  const icons = { cash: 'fas fa-money-bill-wave text-emerald-500', bank: 'fas fa-university text-indigo-500', card: 'fas fa-credit-card text-blue-500', credit: 'fas fa-file-invoice-dollar text-amber-500', wallet: 'fas fa-wallet text-purple-500' };
  return icons[k] || 'fas fa-coins text-slate-300';
}

async function load() {
  loading.value = true;
  error.value = '';
  try {
    const result = await paymentStore.fetchPaymentMethods({ force: true });
    if (result.status !== 'success') throw new Error(result.message || 'فشل تحميل طرق الدفع');
    const items = (Array.isArray(result.data) ? result.data : [])
      .filter(x => !x.is_global); // صفحة الإدارة تعرض طرق الـ tenant فقط — لا الـ global templates
    methods.value = items.map(x => ({
      ...x,
      _kind: (x.kind || 'other').toLowerCase(),
      _account_id: x.account_id ? Number(x.account_id) : null,
      _overdraft_limit: x.overdraft_limit == null ? null : Number(x.overdraft_limit),
      _wallet_type: x.wallet_type || 'prepaid',
      _credit_limit: x.credit_limit == null ? null : Number(x.credit_limit),
      _saving: false,
    }));
    lastRefreshed.value = new Date().toLocaleString();
  } catch (e) {
    error.value = e?.response?.data?.message || e?.message || 'فشل تحميل طرق الدفع';
  } finally {
    loading.value = false;
  }
}

async function saveMethod(m) {
  if (!m || !m.id) return;
  m._saving = true;
  try {
    const result = await paymentStore.updatePaymentMethodKind(m.id, m._kind, m._account_id ?? null, {
      overdraft_limit: m._overdraft_limit,
      wallet_type: m._wallet_type,
      credit_limit: m._credit_limit,
    });
    if (result.status !== 'success') {
      error.value = result.message;
    } else {
      await AlertService.success('تم حفظ التعديلات بنجاح', '');
    }
    await load();
  } catch (e) {
    await AlertService.error(e?.response?.data?.message || e?.message || 'فشل حفظ الطريقة', 'خطأ');
  } finally {
    m._saving = false;
  }
}

function resetForm() {
  form.value = { name: '', kind: 'cash', description: '', payment_terms: '', account_id: null, overdraft_limit: null, wallet_type: 'prepaid', credit_limit: null };
}

async function create() {
  if (!form.value.name) {
    await AlertService.warning('يرجى إدخال الاسم', 'حقل مطلوب');
    return;
  }
  creating.value = true;
  try {
    const payload = { ...form.value };
    if (!payload.account_id) payload.account_id = null;
    payload.global = false;
    const result = await paymentStore.createPaymentMethod(payload);
    if (result.status !== 'success') {
      error.value = result.message;
    }
    resetForm();
    await load();
    closeCreate();
  } catch (e) {
    await AlertService.error(e?.response?.data?.message || e?.message || 'فشل إضافة طريقة الدفع', 'خطأ');
  } finally {
    creating.value = false;
  }
}

async function loadAccounts() {
  accountsLoading.value = true;
  try {
    const result = await accountStore.fetchAccounts({ force: true });
    accounts.value = result.status === 'success' ? (result.data || []) : [];
  } catch (e) {
    accounts.value = [];
  } finally {
    accountsLoading.value = false;
  }
}

function openCreate() {
  resetForm();
  showCreate.value = true;
}

function closeCreate() {
  showCreate.value = false;
}

onMounted(async () => {
  await Promise.all([load(), loadAccounts()]);
});
</script>

<style scoped>
@keyframes loading { 0% { transform: translateX(100%); } 100% { transform: translateX(-100%); } }

.filter-input-v3 {
  @apply w-full bg-white border border-slate-200 rounded-md px-3 text-[11px] font-bold text-slate-700 focus:ring-2 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all;
}

.filter-input-v2 {
  @apply w-full bg-white border border-slate-200 rounded-md px-3 text-[11px] font-bold text-slate-700 focus:ring-2 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all;
}

.metadata-label { @apply block text-[10px] font-bold text-slate-400 uppercase tracking-widest px-1; }

.custom-scroll::-webkit-scrollbar { width: 5px; }
.custom-scroll::-webkit-scrollbar-thumb { @apply bg-slate-200 rounded-full; }

.animate-fadeIn { animation: fadeIn 0.4s ease-out; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

@keyframes modalIn { from { opacity: 0; transform: scale(0.98) translateY(10px); } to { opacity: 1; transform: scale(1) translateY(0); } }
.animate-modalIn { animation: modalIn 0.2s cubic-bezier(0.16, 1, 0.3, 1); }

.fade-enter-active, .fade-leave-active { transition: opacity 0.25s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }

.slide-down-enter-active, .slide-down-leave-active { transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
.slide-down-enter-from, .slide-down-leave-to { opacity: 0; transform: translateY(-10px); }
</style>