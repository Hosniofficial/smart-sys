<template>
  <div class="min-h-screen bg-[#fafafa] text-slate-900 font-sans antialiased selection:bg-blue-100" dir="rtl">
    
    <!-- Top Progress Bar -->
    <div v-if="bulkSaving" class="fixed top-0 left-0 right-0 h-0.5 bg-blue-600/10 z-[110]">
      <div class="h-full bg-blue-600 animate-[loading_2s_ease-in-out_infinite] w-1/3"></div>
    </div>

    <div class="max-w-[1600px] mx-auto p-6 lg:p-10 space-y-8">
      
      <!-- Page Header -->
      <PageHeader
        :breadcrumb="breadcrumb"
        title="توجيه الحسابات للمستودعات"
        description="تخصيص حسابات الأستاذ العام (GL) لكل مستودع لضمان دقة التقارير المالية والقيود الآلية."
        :branches="[]"
        :selectedBranch="null"
      >
        <template #controls>
          <label class="flex items-center gap-3 px-4 h-9 bg-white border border-slate-200 rounded-md cursor-pointer hover:bg-slate-50 transition-all shadow-sm">
            <input type="checkbox" v-model="showMissingOnly" class="w-4 h-4 rounded border-slate-300 text-blue-600 focus:ring-0" />
            <span class="text-[11px] font-bold text-slate-600 uppercase tracking-tight">إظهار غير المرتبط فقط</span>
          </label>
        </template>
      </PageHeader>

      <!-- Mapping Statistics Overview -->
      <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
        <div v-for="kpi in [
          { label: 'إجمالي الفروع', val: branches.length, icon: 'fa-warehouse', color: 'text-blue-600', bg: 'bg-blue-50' },
          { label: 'مستودعات مرتبطة', val: branches.filter(w => w.account_id).length, icon: 'fa-link', color: 'text-emerald-600', bg: 'bg-emerald-50' },
          { label: 'بانتظار الربط', val: branches.filter(w => !w.account_id).length, icon: 'fa-link-slash', color: 'text-amber-600', bg: 'bg-amber-50' }
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

      <!-- Toolbar: Utility Grid -->
      <section class="bg-white border border-slate-200 rounded-xl p-5 flex flex-col md:flex-row md:items-center justify-between gap-6 shadow-sm">
        <div class="flex-grow group max-w-xl">
          <label class="metadata-label">البحث الشامل في دليل الحسابات</label>
          <div class="relative">
            <input v-model="accountSearch" type="text" class="h-9 w-full bg-slate-50 border border-slate-100 rounded-md pr-9 pl-3 text-[11px] font-bold text-slate-700 focus:bg-white focus:border-blue-500 outline-none transition-all" placeholder="بحث بالاسم أو الكود..." />
            <i class="fas fa-search absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px]"></i>
          </div>
        </div>

        <button @click="reload" class="h-9 px-4 rounded-md border border-slate-200 bg-white text-slate-500 text-[10px] font-bold uppercase tracking-widest hover:text-blue-600 hover:border-blue-200 transition-all flex items-center gap-2">
          <i class="fas fa-sync-alt text-[10px]"></i> تحديث القوائم
        </button>
      </section>

      <!-- Main Mapping Table: Professional Audit Grid -->
      <div class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm relative">
        <div class="overflow-x-auto">
          <table class="w-full text-right border-collapse">
            <thead>
              <tr class="bg-slate-50/50 border-b border-slate-200">
                <th class="px-6 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">الفرع المستهدف</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">الموقع</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">تعيين الحساب المحاسبي</th>
                <th class="px-8 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center w-32">التحكم</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 font-medium">
              <tr v-if="!filteredbranchs.length" class="text-center">
                <td colspan="4" class="py-24 text-slate-300">
                  <i class="fas fa-link-slash text-3xl mb-4 opacity-20"></i>
                  <p class="text-xs font-bold uppercase tracking-widest">لا توجد مستودعات مطابقة</p>
                </td>
              </tr>
              <tr v-for="w in filteredbranchs" :key="w.id" class="hover:bg-blue-50/10 transition-all group">
                <td class="px-6 py-4">
                  <div class="flex items-center gap-4">
                    <div class="w-9 h-9 rounded-lg bg-slate-50 flex items-center justify-center text-slate-400 group-hover:text-blue-600 transition-colors border border-slate-100 group-hover:border-blue-100 shadow-inner">
                      <i class="fas fa-store-alt text-xs"></i>
                    </div>
                    <div>
                      <span class="text-xs font-bold text-slate-900 leading-none block mb-1.5">{{ w.name }}</span>
                      <span :class="[w.account_id ? 'text-emerald-600 bg-emerald-50 border-emerald-100' : 'text-amber-600 bg-amber-50 border-amber-100']" class="px-2 py-0.5 rounded text-[9px] font-bold border uppercase tracking-tighter">
                        <i :class="[w.account_id ? 'fas fa-check-circle' : 'fas fa-link-slash', 'text-[8px] ml-1']"></i>
                        {{ w.account_id ? 'مرتبط' : 'غير مرتبط' }}
                      </span>
                    </div>
                  </div>
                </td>
                <td class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase">{{ w.location || '—' }}</td>
                <td class="px-4 py-4">
                  <div class="flex flex-col gap-2 max-w-sm">
                    <div class="relative group">
                      <input type="text" v-model="w._search" class="h-8 w-full bg-slate-50 border border-slate-200 rounded-md pr-8 pl-3 text-[10px] font-bold outline-none focus:bg-white focus:border-blue-500 transition-all" placeholder="البحث في الدليل..." />
                      <i class="fas fa-magnifying-glass absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-300 text-[9px]"></i>
                    </div>
                    <select v-model.number="w._account_id" class="h-8 w-full bg-white border border-slate-200 rounded-md px-2 text-[11px] font-bold text-slate-700 focus:ring-4 focus:ring-blue-500/5 outline-none transition-all appearance-none cursor-pointer">
                      <option :value="0">-- اختر الحساب المالي --</option>
                      <optgroup label="حسابات المنشأة" v-if="tenantAccounts.length">
                        <option v-for="acc in filterAccounts(tenantAccounts, w._search)" :key="'t-'+acc.id" :value="acc.id">{{ accLabel(acc) }}</option>
                      </optgroup>
                      <optgroup label="الحسابات العامة" v-if="globalAccounts.length">
                        <option v-for="acc in filterAccounts(globalAccounts, w._search)" :key="'g-'+acc.id" :value="acc.id">{{ accLabel(acc) }}</option>
                      </optgroup>
                    </select>
                  </div>
                </td>
                <td class="px-8 py-4 text-center">
                  <LoadingButton
                    text="تحديث"
                    :isLoading="w._saving"
                    loadingText="حفظ..."
                    variant="primary"
                    loaderType="dots"
                    @click="saveOne(w)"
                    class="h-8 px-5 text-[10px]"
                  />
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Bulk Actions Tray: High Contrast SaaS Footer -->
        <footer class="px-8 py-6 bg-slate-900 text-white flex flex-col md:flex-row justify-between items-center gap-8 relative overflow-hidden">
          <div class="absolute top-0 right-0 w-64 h-64 bg-blue-600/10 rounded-full translate-x-12 -translate-y-12"></div>
          
          <div class="flex items-center gap-5 relative z-10">
             <div class="w-12 h-12 bg-white/10 rounded-xl flex items-center justify-center text-blue-400 shadow-inner"><i class="fas fa-info-circle text-lg"></i></div>
             <div class="space-y-1">
               <h4 class="text-sm font-bold uppercase tracking-widest text-blue-400">تنفيذ الربط الجماعي</h4>
               <p class="text-[11px] text-slate-400 font-medium leading-relaxed max-w-lg">سيقوم النظام بتحديث سجلات كافة المستودعات التي طرأ عليها تغيير في الحساب المحاسبي المرتبط. سيتم تجاهل الصفوف غير المعدلة.</p>
             </div>
          </div>
          
          <LoadingButton
            text="حفظ كافة التغييرات"
            :isLoading="bulkSaving"
            loadingText="جاري الحفظ..."
            icon="fas fa-check-double"
            variant="primary"
            loaderType="dots"
            @click="saveAll"
            class="relative z-10 h-12 px-10"
          />
        </footer>
      </div>
    </div>
  </div>
</template>

<script setup>
// [SCRIPT SECTION REMAINS 100% IDENTICAL AS PER BUSINESS LOGIC RULE]
import { ref, computed, onMounted } from 'vue'
import { useAuthStore } from '@/stores/auth/authStore';
import { useBranchStore } from '@/stores/branch/branchStore';
import { useAccountStore } from '@/stores/account/accountStore';
import LoadingButton from '@/components/ui/LoadingButton.vue'
import { useBreadcrumb } from '@/composables/useBreadcrumb';
import PageHeader from '@/components/PageHeader.vue';

const branchStore = useBranchStore();
const branches = computed(() => branchStore.branches)
const { breadcrumb } = useBreadcrumb();
const accountStore = useAccountStore();
const tenantAccounts = computed(() => accountStore.tenantAccounts);
const globalAccounts = computed(() => accountStore.globalAccounts);
const accountSearch = ref('')
const showMissingOnly = ref(false)
const bulkSaving = ref(false)

const accLabel = (acc) => acc?.code ? `${acc.code} — ${acc.name}` : (acc?.name || '')

function filterAccounts(list, q) {
  const s = (q || accountSearch.value || '').toString().trim().toLowerCase()
  if (!s) return list
  return list.filter(a => (a.name || '').toLowerCase().includes(s) || (a.code || '').toLowerCase().includes(s))
}

const filteredbranchs = computed(() => {
  const arr = branches.value || []
  return arr.filter(w => !showMissingOnly.value || !w.account_id)
})

async function reload() {
  try {
    await Promise.all([
      accountStore.fetchGroupedAccounts(),
      branchStore.fetchBranches()
    ])
    branchStore.branches.forEach(w => {
      if (!('_account_id' in w)) {
        w._account_id = Number(w.account_id || 0)
        w._search = ''
        w._saving = false
      }
    })
  } catch (error) {
    console.error('Error loading data:', error)
    window.showToast && window.showToast('فشل تحميل البيانات', 'error')
  }
}

async function saveOne(w) {
  try {
    w._saving = true
    const account_id = w._account_id && w._account_id > 0 ? w._account_id : null
    const response = await branchStore.updateBranch(w.id, { account_id })
    if (response.status === 'success') {
      w.account_id = account_id
      window.showToast && window.showToast(`تم ربط ${w.name} بنجاح`, 'success')
    } else {
      window.showToast && window.showToast(response.message || 'فشل حفظ التغييرات', 'error')
    }
  } catch (e) {
    window.showToast && window.showToast('فشل حفظ التغييرات', 'error')
  } finally {
    w._saving = false
  }
}

async function saveAll() {
  try {
    bulkSaving.value = true
    let successCount = 0
    let errorCount = 0
    for (const w of branches.value) {
      if (w._account_id !== (Number(w.account_id || 0))) {
        const response = await branchStore.updateBranch(w.id, { account_id: w._account_id && w._account_id > 0 ? w._account_id : null })
        if (response.status === 'success') {
          w.account_id = w._account_id
          successCount++
        } else {
          errorCount++
        }
      }
    }
    const message = errorCount > 0 
      ? `تم حفظ ${successCount} تغييرات، وفشل ${errorCount}` 
      : 'تم حفظ كافة تغييرات الربط بنجاح'
    window.showToast && window.showToast(message, errorCount > 0 ? 'warning' : 'success')
  } finally {
    bulkSaving.value = false
  }
}

onMounted(reload)
</script>

<style scoped>
@keyframes loading { 0% { transform: translateX(100%); } 100% { transform: translateX(-100%); } }

.metadata-label { @apply block text-[10px] font-bold text-slate-400 uppercase tracking-widest px-1 mb-1.5; }

.filter-input-v2 {
  @apply w-full bg-white border border-slate-200 rounded-md px-3 text-[11px] font-bold text-slate-700 focus:ring-4 focus:ring-blue-500/5 focus:border-blue-500 outline-none transition-all;
}

.filter-input-v3 {
  @apply h-9 w-full bg-white border border-slate-200 rounded-md px-3 text-[11px] font-bold text-slate-700 focus:ring-4 focus:ring-blue-500/5 focus:border-blue-500 outline-none transition-all;
}

.custom-scroll::-webkit-scrollbar { width: 5px; height: 5px; }
.custom-scroll::-webkit-scrollbar-thumb { @apply bg-slate-200 rounded-full; }

.animate-fadeIn { animation: fadeIn 0.4s ease-out; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
</style>