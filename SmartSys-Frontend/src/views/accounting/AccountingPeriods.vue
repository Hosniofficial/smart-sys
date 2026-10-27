<template>
  <div class="min-h-screen bg-[#fafafa] text-slate-900 font-sans antialiased selection:bg-indigo-100" dir="rtl">
    
    <!-- Top Progress Bar -->
    <PageLoadingBar :active="loading" />

    <div class="max-w-[1600px] mx-auto p-6 lg:p-10 space-y-8">
      
      <PageHeader
        :breadcrumb="{ parent: 'المالية', current: 'الفترات المحاسبية' }"
        title="إدارة الفترات المحاسبية"
        description="أدر الفترات المحاسبية، وتحكّم في فتحها وإغلاقها وتحديد إمكانية تسجيل القيود والعمليات المالية خلالها."
        :branches="[]"
        :selected-branch="null"
      >
        <template #controls>
          <button @click="showCreate = true" class="h-9 px-6 rounded-md bg-indigo-600 text-white text-xs font-bold shadow-sm hover:bg-indigo-700 transition-all active:scale-95 flex items-center gap-2">
            <i class="fas fa-plus-circle text-[10px]"></i>
            إنشاء دورة جديدة
          </button>
        </template>
      </PageHeader>

      <!-- System Status Banner: Professional Alert Style -->
      <transition name="fade">
        <div v-if="openPeriods.length === 0 && periods.length > 0" class="bg-amber-50 border border-amber-200 rounded-xl p-4 flex items-center gap-4 shadow-sm animate-pulse-subtle">
          <div class="w-10 h-10 rounded-lg bg-white flex items-center justify-center text-amber-500 shadow-sm border border-amber-100">
            <i class="fas fa-exclamation-triangle"></i>
          </div>
          <div class="flex-1">
            <p class="text-xs font-bold text-amber-900 uppercase tracking-wide">تنبيه: لا توجد دورات مفتوحة</p>
            <p class="text-[11px] text-amber-700 mt-0.5 font-medium leading-relaxed">النظام حالياً في وضع "القراءة فقط" للقيود المالية؛ لن يتم قبول أي سجلات جديدة تقع خارج نطاق زمني مفتوح.</p>
          </div>
        </div>
      </transition>

      <!-- Main Data Table Card -->
      <div class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm relative min-h-[400px]">
        
        <!-- Loading State -->
        <div v-if="loading && periods.length === 0" class="p-20 text-center flex flex-col items-center">
          <BaseSpinner :size="32" color="#4f46e5" />
          <p class="text-xs font-bold text-slate-400 mt-4 uppercase tracking-widest">جاري جلب السجلات المالية...</p>
        </div>

        <!-- Empty State -->
        <div v-else-if="periods.length === 0" class="py-32 text-center text-slate-300 flex flex-col items-center">
          <div class="w-20 h-20 bg-slate-50 rounded-full flex items-center justify-center mb-4">
            <i class="fas fa-calendar-times text-3xl"></i>
          </div>
          <p class="text-xs font-bold uppercase tracking-widest leading-loose">لا توجد دورات محاسبية مضافة.<br>يرجى إنشاء أول دورة للبدء في ترحيل العمليات.</p>
        </div>

        <!-- Period List Table -->
        <div v-else class="overflow-x-auto">
          <table class="w-full text-right border-collapse">
            <thead>
              <tr class="bg-slate-50/50 border-b border-slate-200">
                <th class="px-6 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">اسم الدورة</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">تاريخ البداية</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">تاريخ النهاية</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">الحالة</th>
                <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">تحديث</th>
                <th class="px-6 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">الإجراءات</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 font-medium">
              <tr v-for="p in periods" :key="p.id" class="hover:bg-blue-50/10 transition-all group">
                <td class="px-6 py-4">
                  <div class="flex items-center gap-3">
                    <div :class="[p.status === 'open' ? 'text-emerald-500' : 'text-slate-300']" class="w-2 h-2 rounded-full bg-current shadow-sm"></div>
                    <span class="text-xs font-bold text-slate-900">{{ p.period_name }}</span>
                  </div>
                </td>
                <td class="px-4 py-4 text-center text-[10px] font-mono font-bold text-slate-500">{{ p.start_date }}</td>
                <td class="px-4 py-4 text-center text-[10px] font-mono font-bold text-slate-500">{{ p.end_date }}</td>
                <td class="px-4 py-4 text-center">
                  <span :class="[p.status === 'open' ? 'bg-emerald-50 text-emerald-700 border-emerald-100' : 'bg-rose-50 text-rose-700 border-rose-100']" 
                        class="px-2 py-0.5 rounded text-[9px] font-bold border uppercase tracking-tighter">
                    {{ p.status === 'open' ? 'مفتوحة' : 'مغلقة' }}
                  </span>
                </td>
                <td class="px-4 py-4 text-center text-[9px] font-mono text-slate-400">
                  {{ p.updated_at ? p.updated_at.slice(0,10) : '—' }}
                </td>
                <td class="px-6 py-4">
                  <div class="flex items-center justify-center gap-2">
                    <button v-if="p.status === 'open'"
                      @click="confirmClose(p)"
                      class="h-7 px-3 rounded bg-white border border-rose-200 text-rose-600 text-[10px] font-bold hover:bg-rose-600 hover:text-white transition-all flex items-center gap-2">
                      <i class="fas fa-lock text-[9px]"></i> إغلاق الدورة
                    </button>
                    <button v-else
                      @click="doReopen(p.id)"
                      class="h-7 px-3 rounded bg-white border border-emerald-200 text-emerald-700 text-[10px] font-bold hover:bg-emerald-600 hover:text-white transition-all flex items-center gap-2">
                      <i class="fas fa-lock-open text-[9px]"></i> إعادة فتح
                    </button>
                    <button v-if="p.status === 'open'"
                      @click="doDelete(p.id)"
                      class="w-7 h-7 rounded bg-slate-50 text-slate-400 border border-slate-100 flex items-center justify-center hover:bg-rose-50 hover:text-rose-600 transition-all">
                      <i class="fas fa-trash-alt text-[9px]"></i>
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Create Modal: Refined Form UI -->
    <Teleport to="body">
      <transition name="fade">
        <div v-if="showCreate" class="fixed inset-0 z-[120] flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-md" @click.self="showCreate = false">
          <div class="bg-white w-full max-w-md rounded-xl shadow-2xl overflow-hidden border border-slate-200 animate-modalIn flex flex-col">
            <div class="px-8 py-5 border-b border-slate-100 bg-slate-50/50 flex items-center justify-between">
              <div class="flex items-center gap-3">
                <div class="w-8 h-8 bg-indigo-600 rounded-lg flex items-center justify-center text-white"><i class="fas fa-calendar-plus text-xs"></i></div>
                <h3 class="text-sm font-bold text-slate-900 uppercase">دورة محاسبية جديدة</h3>
              </div>
              <button @click="showCreate = false" class="text-slate-400 hover:text-slate-900 transition-colors"><i class="fas fa-times text-lg"></i></button>
            </div>

            <div class="p-8 space-y-6">
              <div class="space-y-1.5">
                <label class="metadata-label">مُسمى الدورة <span class="text-rose-500">*</span></label>
                <input v-model="form.period_name" type="text" placeholder="مثال: الربع الأول 2025" class="filter-input-v2 h-10 font-bold" />
              </div>
              <div class="grid grid-cols-2 gap-4">
                <div class="space-y-1.5">
                  <label class="metadata-label">تاريخ البداية <span class="text-rose-500">*</span></label>
                  <input v-model="form.start_date" type="date" class="filter-input-v2 h-10 font-mono" />
                </div>
                <div class="space-y-1.5">
                  <label class="metadata-label">تاريخ النهاية <span class="text-rose-500">*</span></label>
                  <input v-model="form.end_date" type="date" class="filter-input-v2 h-10 font-mono" />
                </div>
              </div>
            </div>

            <div class="px-8 py-4 bg-slate-50 border-t border-slate-100 flex gap-3">
              <button @click="showCreate = false" class="flex-1 h-10 text-xs font-bold text-slate-500">إلغاء</button>
              <LoadingButton
                text="إنشاء الدورة"
                :isLoading="saving"
                loadingText="جاري المعالجة..."
                :disabled="saving"
                variant="primary"
                class="flex-2 h-10"
                @click="doCreate"
              />
            </div>
          </div>
        </div>
      </transition>
    </Teleport>

    <!-- Confirm Close Modal -->
    <Teleport to="body">
      <transition name="fade">
        <div v-if="closeTarget" class="fixed inset-0 z-[130] flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-md" @click.self="closeTarget = null">
          <div class="bg-white w-full max-w-sm rounded-xl shadow-2xl overflow-hidden border border-slate-200 animate-modalIn p-8 text-center space-y-6">
            <div class="w-16 h-16 rounded-full bg-rose-50 text-rose-600 flex items-center justify-center mx-auto shadow-inner border border-rose-100">
              <i class="fas fa-lock text-2xl"></i>
            </div>
            <div>
              <h3 class="text-base font-bold text-slate-900 uppercase">تأكيد إغلاق الفترة</h3>
              <p class="text-[10px] text-slate-400 mt-2 leading-relaxed">
                سيتم إغلاق <strong class="text-slate-700">{{ closeTarget?.period_name }}</strong> نهائياً.<br>
                لن يُسمح بتعديل أو إضافة أي قيود مالية ضمن هذا النطاق الزمني.
              </p>
            </div>
            <div class="flex gap-3 pt-2">
              <button @click="closeTarget = null" class="flex-1 h-10 text-xs font-bold text-slate-500">تراجع</button>
              <LoadingButton
                text="تأكيد الإغلاق"
                :isLoading="saving"
                loadingText="جاري الإغلاق..."
                :disabled="saving"
                variant="danger"
                class="flex-2 h-10"
                @click="doClose(closeTarget.id)"
              />
            </div>
          </div>
        </div>
      </transition>
    </Teleport>

    <!-- Global Toast Notifications -->
    <Teleport to="body">
      <transition name="slide-up">
        <div v-if="toast.show" :class="[toast.type === 'success' ? 'bg-slate-900 border-emerald-500' : 'bg-rose-600 border-white/20']"
             class="fixed bottom-10 left-1/2 -translate-x-1/2 z-[9999] px-6 py-3 rounded-xl shadow-2xl text-white font-bold text-xs flex items-center gap-3 border transition-all">
          <i :class="toast.type === 'success' ? 'fas fa-check-circle text-emerald-400' : 'fas fa-times-circle'"></i>
          {{ toast.message }}
        </div>
      </transition>
    </Teleport>

  </div>
</template>

<script setup>
// [SCRIPT SECTION REMAINS 100% IDENTICAL TO THE ORIGINAL AS PER BUSINESS LOGIC RULE]
import { ref, computed, onMounted } from 'vue'
import axios from 'axios'
import { useAuthStore } from '@/stores/auth/authStore'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import PageHeader from '@/components/PageHeader.vue'
import AlertService from '@/services/AlertService'

const authStore = useAuthStore()

const periods   = ref([])
const loading   = ref(false)
const saving    = ref(false)
const showCreate = ref(false)
const closeTarget = ref(null)

const form = ref({ period_name: '', start_date: '', end_date: '' })
const toast = ref({ show: false, message: '', type: 'success' })

const openPeriods = computed(() => periods.value.filter(p => p.status === 'open'))

const showToast = (message, type = 'success') => {
  toast.value = { show: true, message, type }
  setTimeout(() => { toast.value.show = false }, 3500)
}

const api = () => axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000/smartsys/api/v1',
  headers: { Authorization: `Bearer ${authStore.token}`, 'X-Tenant-ID': authStore.tenantId }
})

const load = async () => {
  loading.value = true
  try {
    const res = await api().get('/accounting-periods')
    let resData = res.data
    if (typeof resData === 'string') {
      try { resData = JSON.parse(resData) } catch { resData = {} }
    }
    const raw = resData?.data ?? resData ?? []
    periods.value = Array.isArray(raw) ? raw : []
    if (!Array.isArray(raw)) console.error('Unexpected response format:', raw)
  } catch (e) {
    periods.value = []
    showToast(e?.response?.data?.message || 'تعذر تحميل الدورات', 'error')
  } finally { loading.value = false }
}

const doCreate = async () => {
  if (!form.value.period_name || !form.value.start_date || !form.value.end_date) {
    showToast('يرجى تعبئة جميع الحقول المطلوبة', 'error'); return
  }
  saving.value = true
  try {
    await api().post('/accounting-periods', form.value)
    showToast('تم إنشاء الدورة المحاسبية بنجاح')
    showCreate.value = false
    form.value = { period_name: '', start_date: '', end_date: '' }
    await load()
  } catch (e) {
    showToast(e?.response?.data?.message || 'فشل الإنشاء', 'error')
  } finally { saving.value = false }
}

const confirmClose = (p) => { closeTarget.value = p }

const doClose = async (id) => {
  saving.value = true
  try {
    await api().put(`/accounting-periods/${id}/close`)
    showToast('تم إغلاق الدورة المحاسبية')
    closeTarget.value = null
    await load()
  } catch (e) {
    showToast(e?.response?.data?.message || 'فشل الإغلاق', 'error')
  } finally { saving.value = false }
}

const doReopen = async (id) => {
  const confirmed = await AlertService.confirm(
    'هل أنت متأكد من إعادة فتح هذه الدورة؟',
    'إعادة فتح الدورة',
    { confirmText: 'إعادة الفتح', cancelText: 'إلغاء' }
  )
  if (!confirmed) return
  try {
    await api().put(`/accounting-periods/${id}/reopen`)
    showToast('تم إعادة فتح الدورة')
    await load()
  } catch (e) {
    showToast(e?.response?.data?.message || 'فشل إعادة الفتح', 'error')
  }
}

const doDelete = async (id) => {
  const confirmed = await AlertService.confirm(
    'حذف هذه الدورة؟',
    'حذف الدورة المحاسبية',
    { confirmText: 'حذف الدورة', cancelText: 'إلغاء' }
  )
  if (!confirmed) return
  try {
    await api().delete(`/accounting-periods/${id}`)
    showToast('تم الحذف')
    await load()
  } catch (e) {
    showToast(e?.response?.data?.message || 'فشل الحذف', 'error')
  }
}

onMounted(load)
</script>

<style scoped>
@keyframes loading { 0% { transform: translateX(100%); } 100% { transform: translateX(-100%); } }

.filter-input-v2 {
  @apply focus:ring-2 focus:ring-indigo-500/10 focus:border-indigo-500;
}

.custom-scroll::-webkit-scrollbar { width: 5px; }
.custom-scroll::-webkit-scrollbar-thumb { @apply bg-slate-200 rounded-full; }

.animate-fadeIn { animation: fadeIn 0.4s ease-out; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

@keyframes modalIn { from { opacity: 0; transform: scale(0.98) translateY(10px); } to { opacity: 1; transform: scale(1) translateY(0); } }
.animate-modalIn { animation: modalIn 0.2s cubic-bezier(0.16, 1, 0.3, 1); }

@keyframes pulse-subtle { 0%, 100% { opacity: 1; } 50% { opacity: 0.85; } }
.animate-pulse-subtle { animation: pulse-subtle 3s infinite ease-in-out; }

.slide-up-enter-active, .slide-up-leave-active { transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
.slide-up-enter-from, .slide-up-leave-to { transform: translate(-50%, 20px); opacity: 0; }
</style>