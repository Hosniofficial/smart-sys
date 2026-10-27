<template>
  <div class="min-h-screen bg-[#fafafa] text-slate-900 font-sans antialiased selection:bg-blue-100" dir="rtl">
    
    <!-- Top Progress Bar -->
    <PageLoadingBar :active="loading" />

    <div class="max-w-[1600px] mx-auto p-6 lg:p-10 space-y-8 animate-fadeIn">
      
      <!-- Page Header -->
      <PageHeader
        :breadcrumb="breadcrumb"
        title="إدارة خطط الاشتراك"
        description="تخصيص الباقات، الأسعار، ودورات الفوترة للنظام."
        :branches="[]"
        :selectedBranch="null"
      >
        <template #controls>
          <div class="flex items-center gap-2">
            <LoadingButton
              text="تحديث"
              loadingText="جاري التحميل..."
              icon="fas fa-rotate"
              :isLoading="loading"
              variant="secondary"
              class="h-9 px-4 rounded-md border border-slate-200 bg-white text-slate-700 text-xs shadow-sm hover:bg-slate-50"
              title="تحديث البيانات"
              @click="load"
            />
            <button 
              @click="showAddModal = true" 
              class="h-9 px-4 rounded-md bg-blue-600 text-white text-xs font-bold shadow-sm hover:bg-blue-700 transition-all active:scale-95 flex items-center gap-2"
            >
              <i class="fas fa-plus text-[10px]"></i>
              <span>إضافة خطة جديدة</span>
            </button>
          </div>
        </template>
      </PageHeader>

      <!-- Plans Overview KPIs -->
      <section class="grid grid-cols-1 sm:grid-cols-2 gap-6">
        <div class="bg-white border border-slate-200 p-5 rounded-xl flex items-center justify-between group hover:border-slate-300 transition-all shadow-sm">
          <div>
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">إجمالي الخطط</p>
            <p class="text-2xl font-bold font-mono tracking-tighter text-slate-900">{{ plans.length }}</p>
          </div>
          <div class="w-10 h-10 rounded-lg flex items-center justify-center text-sm bg-blue-50 text-blue-600 opacity-80 group-hover:opacity-100 transition-opacity">
            <i class="fas fa-tags"></i>
          </div>
        </div>

        <div class="bg-white border border-slate-200 p-5 rounded-xl flex items-center justify-between group hover:border-slate-300 transition-all shadow-sm">
          <div>
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">الخطط النشطة</p>
            <p class="text-2xl font-bold font-mono tracking-tighter text-emerald-600">{{ Array.isArray(plans) ? plans.filter(p => p.is_active).length : 0 }}</p>
          </div>
          <div class="w-10 h-10 rounded-lg flex items-center justify-center text-sm bg-emerald-50 text-emerald-600 opacity-80 group-hover:opacity-100 transition-opacity">
            <i class="fas fa-check-double"></i>
          </div>
        </div>
      </section>

      <!-- Main Plans Table Card -->
      <div class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm relative min-h-[400px]">
        <!-- Sub-header banner -->
        <div class="p-4 px-6 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
          <div class="flex items-center gap-2">
            <i class="fas fa-layer-group text-slate-400 text-xs"></i>
            <h3 class="text-xs font-bold text-slate-900 uppercase tracking-wider">مصفوفة خطط الاشتراك الحالية</h3>
          </div>
          <div v-if="Array.isArray(plans) && plans.some(p => p.code === 'trial')" class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-amber-50 rounded-md border border-amber-200 text-[10px] font-bold text-amber-700">
            <i class="fas fa-shield-halved text-[10px] text-amber-500"></i>
            <span>الخطة التجريبية محمية من التعديل</span>
          </div>
        </div>

        <div class="overflow-x-auto">
          <table class="w-full text-right border-collapse">
            <thead>
              <tr class="bg-slate-50 border-b border-slate-200">
                <th class="px-6 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">كود الخطة</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">اسم الباقة</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">السعر (الوحدة)</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">العملة</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">الدورة (أيام)</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">الحالة</th>
                <th class="px-6 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center w-44">إجراءات</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <!-- Skeleton loading for table -->
              <template v-if="loading && !(Array.isArray(plans) && plans.length)">
                <tr v-for="row in 5" :key="row" class="animate-pulse">
                  <td class="px-6 py-4"><div class="h-3 bg-slate-100 rounded w-20"></div></td>
                  <td class="px-4 py-4"><div class="h-3 bg-slate-100 rounded w-28"></div></td>
                  <td class="px-4 py-4"><div class="h-7 bg-slate-100 rounded w-24"></div></td>
                  <td class="px-4 py-4"><div class="h-7 bg-slate-100 rounded w-20"></div></td>
                  <td class="px-4 py-4"><div class="h-7 bg-slate-100 rounded w-16"></div></td>
                  <td class="px-4 py-4 text-center"><div class="h-4 bg-slate-100 rounded-full w-10 mx-auto"></div></td>
                  <td class="px-6 py-4 text-center"><div class="h-8 bg-slate-100 rounded w-24 mx-auto"></div></td>
                </tr>
              </template>

              <!-- Empty State -->
              <tr v-else-if="!(Array.isArray(plans) && plans.length)">
                <td colspan="7" class="py-24 text-center text-slate-300">
                  <i class="fas fa-layer-group text-3xl mb-3 opacity-20"></i>
                  <p class="text-xs font-bold uppercase tracking-widest">لا توجد خطط معرفة في النظام</p>
                </td>
              </tr>

              <!-- Data Rows -->
              <tr v-for="p in plans" :key="p.code" class="hover:bg-blue-50/20 transition-all group font-bold">
                <td class="px-6 py-4">
                  <span class="font-mono text-[10px] font-bold text-blue-600 bg-blue-50 px-2.5 py-1 rounded border border-blue-100 uppercase tracking-wider">
                    {{ p.code }}
                  </span>
                </td>
                <td class="px-4 py-4 text-xs font-bold text-slate-900">{{ p.name }}</td>
                <td class="px-4 py-4">
                  <input 
                    v-model.number="p.price" 
                    type="number" 
                    step="0.01" 
                    class="w-24 h-8 bg-white border border-slate-200 rounded-md text-center text-xs font-mono font-bold text-slate-900 focus:ring-2 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all disabled:opacity-40 disabled:bg-slate-50 disabled:cursor-not-allowed shadow-sm"
                    :disabled="p.code === 'trial'" 
                  />
                </td>
                <td class="px-4 py-4">
                  <select 
                    v-model="p.currency" 
                    class="h-8 px-2.5 bg-white border border-slate-200 rounded-md text-xs font-bold text-slate-700 focus:ring-2 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all disabled:opacity-40 disabled:bg-slate-50 disabled:cursor-not-allowed shadow-sm" 
                    :disabled="p.code === 'trial'"
                  >
                    <option value="USD">USD</option>
                    <option value="EUR">EUR</option>
                    <option value="SAR">SAR</option>
                    <option value="EGP">EGP</option>
                  </select>
                </td>
                <td class="px-4 py-4">
                  <input 
                    v-model.number="p.billing_cycle_days" 
                    type="number" 
                    class="w-20 h-8 bg-white border border-slate-200 rounded-md text-center text-xs font-mono font-bold text-slate-700 focus:ring-2 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all disabled:opacity-40 disabled:bg-slate-50 disabled:cursor-not-allowed shadow-sm"
                    :disabled="p.code === 'trial'" 
                  />
                </td>
                <td class="px-4 py-4 text-center">
                  <label class="relative inline-flex items-center cursor-pointer" :class="{'opacity-40 cursor-not-allowed': p.code === 'trial'}">
                    <input type="checkbox" v-model="p.is_active" :true-value="1" :false-value="0" class="sr-only peer" :disabled="p.code === 'trial'">
                    <div 
                      @click="p.code !== 'trial' && (p.is_active = p.is_active ? 0 : 1)"
                      :class="[
                        p.is_active ? 'bg-emerald-500' : 'bg-slate-200',
                        p.code === 'trial' ? 'cursor-not-allowed opacity-40' : 'cursor-pointer'
                      ]"
                      class="w-8 h-4 rounded-full transition-colors duration-200 relative overflow-hidden"
                    >
                      <span
                        :class="p.is_active ? 'left-0.5' : 'right-0.5'"
                        class="absolute top-0.5 w-3 h-3 bg-white rounded-full shadow transition-all duration-200"
                      ></span>
                    </div>
                  </label>
                </td>
                <td class="px-6 py-4 text-center">
                  <div class="flex items-center justify-center gap-1.5">
                    <LoadingButton
                      text="حفظ"
                      loadingText="جارٍ الحفظ..."
                      icon="fas fa-save"
                      :isLoading="saveLoading[p.code]"
                      :disabled="p.code === 'trial'"
                      @click="save(p)"
                      class="h-8 rounded-md px-3 text-[11px] disabled:opacity-40"
                    />
                    <LoadingButton
                      v-if="p.code !== 'trial'" 
                      @click="deletePlan(p)" 
                      :isLoading="deleteLoading[p.code]"
                      text=""
                      loadingText=""
                      icon="fas fa-trash-alt"
                      :showTextDuringLoad="false"
                      variant="danger"
                      class="h-8 w-8 rounded-lg p-0"
                      aria-label="حذف الخطة"
                      title="حذف الخطة"
                    />
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Add Plan Modal -->
    <BaseModal :show="showAddModal" @close="showAddModal = false" maxWidth="lg">
      <template #header>
        <div class="flex items-center gap-3">
          <div class="w-8 h-8 bg-blue-600 rounded-lg flex items-center justify-center text-white text-xs">
            <i class="fas fa-plus"></i>
          </div>
          <div>
            <h3 class="text-sm font-bold text-slate-900 uppercase">إضافة خطة اشتراك</h3>
            <p class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">تحديد تفاصيل الباقة الجديدة</p>
          </div>
        </div>
      </template>

      <form @submit.prevent="addPlan" id="addPlanForm" class="space-y-4" dir="rtl">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div class="space-y-1.5">
            <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider px-1">كود الخطة <span class="text-rose-500">*</span></label>
            <input v-model="newPlan.code" type="text" required class="filter-input font-mono uppercase tracking-widest" placeholder="PREMIUM_X" pattern="[a-z0-9_]+" />
          </div>
          <div class="space-y-1.5">
            <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider px-1">اسم الباقة <span class="text-rose-500">*</span></label>
            <input v-model="newPlan.name" type="text" required class="filter-input" placeholder="باقة الشركات" />
          </div>
          <div class="space-y-1.5">
            <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider px-1">السعر المستهدف <span class="text-rose-500">*</span></label>
            <div class="relative">
              <input v-model.number="newPlan.price" type="number" step="0.01" required class="filter-input font-mono pr-3 pl-12" placeholder="0.00" />
              <span class="absolute left-3 top-1/2 -translate-y-1/2 text-[10px] font-bold text-slate-400 uppercase">{{ newPlan.currency }}</span>
            </div>
          </div>
          <div class="space-y-1.5">
            <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider px-1">دورة الفوترة (أيام) <span class="text-rose-500">*</span></label>
            <input v-model.number="newPlan.billing_cycle_days" type="number" required class="filter-input font-mono" placeholder="30" />
          </div>
        </div>

        <div class="space-y-3 pt-2">
          <div class="space-y-1.5">
            <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider px-1">عملة الاشتراك</label>
            <select v-model="newPlan.currency" class="filter-input appearance-none">
              <option value="USD">USD - دولار أمريكي</option>
              <option value="EUR">EUR - يورو</option>
              <option value="SAR">SAR - ريال سعودي</option>
              <option value="EGP">EGP - جنيه مصري</option>
            </select>
          </div>

          <label class="flex items-center gap-3 p-3 rounded-lg border border-slate-200 hover:bg-slate-50 transition-all cursor-pointer">
            <input type="checkbox" v-model="newPlan.is_active" :true-value="1" :false-value="0" class="w-4 h-4 rounded border-slate-300 text-blue-600 focus:ring-0 cursor-pointer" />
            <div>
              <p class="text-xs font-bold text-slate-900">تنشيط الخطة</p>
              <p class="text-[10px] text-slate-400 font-bold">إتاحة الباقة للاستخدام والاشتراك فور الحفظ</p>
            </div>
          </label>
        </div>
      </form>

      <template #footer>
        <button type="button" @click="showAddModal = false" class="px-6 h-9 text-xs font-bold text-slate-500 hover:text-slate-900 transition-colors">
          إلغاء
        </button>
        <LoadingButton
          form="addPlanForm"
          type="submit"
          text="إضافة الخطة الآن"
          loadingText="جارٍ الإضافة..."
          :disabled="addLoading" 
          :isLoading="addLoading"
          class="px-6 h-9 rounded-md text-xs shadow-sm"
        />
      </template>
    </BaseModal>

  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import BaseModal from '@/components/BaseModal.vue'
import PageHeader from '@/components/PageHeader.vue'
import { useAdminStore } from '@/stores/admin/adminStore'
import { useBreadcrumb } from '@/composables/useBreadcrumb'
import LoadingButton from '@/components/ui/LoadingButton.vue'
import BaseSkeleton from '@/components/ui/BaseSkeleton.vue'
import AlertService from '@/services/AlertService'

// --- State & Stores (Strictly Preserved) ---
const adminStore = useAdminStore()
const { breadcrumb } = useBreadcrumb()
const plans = ref([])
const loading = computed(() => adminStore.loading)
const showAddModal = ref(false)
const addLoading = computed(() => adminStore.actionLoading)
const saveLoading = reactive({})
const deleteLoading = reactive({})

const newPlan = reactive({
  code: '',
  name: '',
  price: 0,
  currency: 'USD',
  billing_cycle_days: 30,
  is_active: 1
})

async function load() {
  const result = await adminStore.fetchPlans()
  if (result.status === 'success') {
    plans.value = Array.isArray(result.data) ? result.data : []
  } else {
    plans.value = []
    if (typeof window?.showToast === 'function') window.showToast(result.message, 'error')
  }
}

async function addPlan() {
  const result = await adminStore.createPlan(newPlan)
  if (result.status === 'success') {
    if (typeof window?.showToast === 'function') window.showToast(result.message, 'success')
    Object.assign(newPlan, { code: '', name: '', price: 0, currency: 'USD', billing_cycle_days: 30, is_active: 1 })
    showAddModal.value = false
    await load()
  } else {
    if (typeof window?.showToast === 'function') window.showToast(result.message, 'error')
  }
}

async function save(p) {
  if (!['monthly', 'yearly'].includes(p.code)) return

  saveLoading[p.code] = true
  const planData = {
    price: p.price,
    currency: p.currency,
    billing_cycle_days: p.billing_cycle_days,
    is_active: p.is_active
  }
  const result = await adminStore.updatePlan(p.code, planData)
  if (result.status === 'success') {
    if (typeof window?.showToast === 'function') window.showToast(result.message, 'success')
  } else {
    if (typeof window?.showToast === 'function') window.showToast(result.message, 'error')
  }
  saveLoading[p.code] = false
}

async function deletePlan(p) {
  if (p.code === 'trial') return
  const confirmed = await AlertService.confirm(
    `هل أنت متأكد من حذف خطة "${p.name}"؟`,
    'حذف الخطة',
    { confirmText: 'حذف الخطة', cancelText: 'إلغاء' }
  )
  if (!confirmed) return
  deleteLoading[p.code] = true
  const result = await adminStore.deletePlan(p.code)
  if (result.status === 'success') {
    if (typeof window?.showToast === 'function') window.showToast(result.message, 'success')
    await load()
  } else {
    if (typeof window?.showToast === 'function') window.showToast(result.message, 'error')
  }
  deleteLoading[p.code] = false
}

onMounted(() => load())
</script>

<style scoped>
@keyframes loading {
  0% { transform: translateX(100%); }
  100% { transform: translateX(-100%); }
}

.filter-input {
  @apply h-9 w-full bg-white border border-slate-200 rounded-md px-3 text-[11px] font-bold text-slate-700 focus:ring-2 focus:ring-blue-500/10 focus:border-blue-500 outline-none transition-all;
}

.custom-scroll::-webkit-scrollbar { width: 5px; }
.custom-scroll::-webkit-scrollbar-thumb { @apply bg-slate-200 rounded-full; }

.animate-fadeIn { animation: fadeIn 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
@keyframes fadeIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
</style>