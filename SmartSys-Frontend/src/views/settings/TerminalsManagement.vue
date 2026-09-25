<template>
  <div class="min-h-screen bg-[#fafafa] text-slate-900 font-sans antialiased selection:bg-blue-100" dir="rtl">
    
    <!-- Top Progress Bar: High-precision indicator -->
    <div v-if="isLoading || isSaving" class="fixed top-0 left-0 right-0 h-0.5 bg-blue-600/10 z-[110]">
      <div class="h-full bg-blue-600 animate-[loading_2s_ease-in-out_infinite] w-1/3"></div>
    </div>

    <!-- Page Header Area -->
    <header class="sticky top-0 z-40 bg-white/80 backdrop-blur-md border-b border-slate-200 px-6 py-3">
      <div class="max-w-[1600px] mx-auto flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div class="flex items-center gap-4">
          <div class="w-10 h-10 bg-slate-900 rounded-lg flex items-center justify-center text-white shadow-sm shrink-0">
            <i class="fas fa-desktop text-sm"></i>
          </div>
          <div>
            <h1 class="text-sm font-bold text-slate-900">إدارة أجهزة نقاط البيع</h1>
            <p class="text-[10px] text-slate-500 font-medium uppercase tracking-widest mt-0.5">تعريف ومراقبة محطات الكاشير (Terminals)</p>
          </div>
        </div>

        <div class="flex items-center gap-3">
          <!-- Quick Status Metric -->
          <div class="px-4 py-1.5 bg-blue-50 border border-blue-100 rounded-lg flex items-center gap-4 shadow-inner">
            <div class="text-center">
              <p class="text-[8px] font-bold text-blue-400 uppercase tracking-widest leading-none mb-1">إجمالي الأجهزة</p>
              <p class="text-sm font-bold text-blue-700 leading-none font-mono tracking-tighter">{{ terminals.length }}</p>
            </div>
          </div>
        </div>
      </div>
    </header>

    <main class="max-w-[1600px] mx-auto p-4 lg:p-8 space-y-8">

      <!-- Registration Form Section: High-Density Utility Card -->
      <section class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden relative group">
        <div class="absolute top-0 left-0 w-32 h-32 bg-blue-500/5 rounded-full -translate-x-12 -translate-y-12 group-hover:scale-110 transition-transform duration-1000"></div>
        
        <div class="p-6 border-b border-slate-100 bg-slate-50/50 flex items-center justify-between relative z-10">
          <h3 class="text-xs font-bold text-slate-900 uppercase tracking-widest flex items-center gap-2">
            <i class="fas fa-plus-circle text-blue-500"></i>
            تسجيل محطة بيع جديدة
          </h3>
          <span class="text-[9px] font-bold text-slate-400 uppercase tracking-tighter">Terminal Registration</span>
        </div>

        <div class="p-8 grid grid-cols-1 md:grid-cols-4 gap-6 relative z-10">
          <div class="space-y-1.5">
            <label class="metadata-label">الفرع المرتبط <span class="text-rose-500">*</span></label>
            <select v-model="form.branch_id" class="filter-input-v2 appearance-none font-bold">
              <option v-for="wh in branches" :key="wh.id" :value="wh.id">{{ wh.name }}</option>
            </select>
          </div>

          <div class="space-y-1.5 group">
            <label class="metadata-label">كود المحطة (Unique Code) <span class="text-rose-500">*</span></label>
            <div class="relative">
              <input v-model="form.code" type="text" class="filter-input-v2 font-mono font-bold text-blue-600 uppercase tracking-widest" placeholder="POS-01" style="padding-right: 2rem;" />
              <i class="fas fa-barcode absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px]"></i>
            </div>
          </div>

          <div class="space-y-1.5 group">
            <label class="metadata-label">اسم الجهاز المخصص</label>
            <div class="relative">
              <input v-model="form.name" type="text" class="filter-input-v2" placeholder="مثال: كاشير الواجهة" style="padding-right: 2rem;" />
              <i class="fas fa-tag absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 text-[10px]"></i>
            </div>
          </div>

          <div class="space-y-1.5">
            <label class="metadata-label">حالة التشغيل</label>
            <select v-model="form.status" class="filter-input-v2 appearance-none font-bold">
              <option value="active">نشط (جاهز للعمل)</option>
              <option value="inactive">معطل مؤقتاً</option>
            </select>
          </div>
        </div>

        <div class="px-8 py-4 bg-slate-50/50 border-t border-slate-100 flex justify-end relative z-10">
          <LoadingButton
            text="تسجيل الجهاز الآن"
            :isLoading="isSaving"
            loadingText="جاري التسجيل..."
            icon="fas fa-save"
            variant="primary"
            loaderType="dots"
            @click="saveTerminal"
            class="h-9 px-8 rounded-md text-[11px] font-bold uppercase tracking-widest shadow-lg shadow-slate-200"
          />
        </div>
      </section>

      <!-- Terminals List Table: Professional Audit Grid -->
      <section class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm relative min-h-[400px]">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
          <h3 class="text-xs font-bold text-slate-900 uppercase tracking-widest flex items-center gap-2">
            <i class="fas fa-list-ul text-slate-400"></i>
            الأجهزة المعرّفة في النظام
          </h3>
          <button @click="loadTerminals" :disabled="isLoading" class="h-8 w-8 rounded-lg border border-slate-200 bg-white text-slate-400 hover:text-blue-600 transition-all active:scale-95 shadow-sm">
            <i class="fas fa-sync-alt text-[10px]" :class="{'animate-spin': isLoading}"></i>
          </button>
        </div>

        <div class="overflow-x-auto">
          <table class="w-full text-right border-collapse">
            <thead>
              <tr class="bg-slate-50/50 border-b border-slate-200 text-slate-400 font-bold uppercase text-[10px] tracking-widest">
                <th class="px-6 py-4 w-16 text-center">#</th>
                <th class="px-4 py-4">كود المحطة</th>
                <th class="px-4 py-4">اسم الجهاز / المسمى</th>
                <th class="px-4 py-4">الفرع المرتبط</th>
                <th class="px-8 py-4 text-center">الحالة</th>
                <th class="px-8 py-4 text-center w-32">التحكم</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 font-medium text-xs">
              <!-- Loading Skeleton -->
              <template v-if="isLoading">
                <tr v-for="n in 5" :key="n" class="animate-pulse">
                  <td v-for="m in 6" :key="m" class="px-6 py-4"><div class="h-3 bg-slate-100 rounded w-full"></div></td>
                </tr>
              </template>
              
              <!-- Empty State -->
              <tr v-else-if="!terminals.length">
                <td colspan="6" class="py-24 text-center text-slate-300">
                   <i class="fas fa-desktop text-3xl mb-4 opacity-20"></i>
                   <p class="text-xs font-bold uppercase tracking-widest">لا توجد أجهزة مسجلة</p>
                </td>
              </tr>

              <!-- Data Rows -->
              <tr v-for="(t, idx) in terminals" :key="t.id" class="hover:bg-blue-50/20 transition-all group">
                <td class="px-6 py-4 text-center font-mono text-[10px] text-slate-300">{{ idx + 1 }}</td>
                <td class="px-4 py-4">
                  <span class="text-xs font-bold text-blue-600 font-mono uppercase tracking-widest bg-blue-50 border border-blue-100 px-2 py-0.5 rounded">{{ t.code }}</span>
                </td>
                <td class="px-4 py-4">
                  <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-lg bg-slate-50 flex items-center justify-center text-slate-400 group-hover:bg-white transition-colors border border-slate-100">
                      <i class="fas fa-display text-[10px]"></i>
                    </div>
                    <span class="text-slate-900 font-bold leading-none">{{ t.name || '—' }}</span>
                  </div>
                </td>
                <td class="px-4 py-4 text-slate-500 font-bold uppercase">
                   <i class="fas fa-building text-[10px] ml-3 opacity-30"></i>
                   {{ branches.find(w => w.id === t.branch_id)?.name || t.branch_id }}
                </td>
                <td class="px-8 py-4 text-center">
                  <span :class="[t.status === 'active' ? 'bg-emerald-50 text-emerald-700 border-emerald-100' : 'bg-slate-50 text-slate-400 border-slate-100']" class="px-2 py-0.5 rounded text-[9px] font-bold border uppercase tracking-tighter">
                    {{ t.status === 'active' ? 'متصل' : 'معطل' }}
                  </span>
                </td>
                <td class="px-8 py-4 text-center">
                  <div class="flex items-center justify-center gap-2">
                    <button @click="openEdit(t)" class="w-8 h-8 rounded-lg border border-slate-200 text-slate-400 hover:text-blue-600 hover:border-blue-200 transition-all flex items-center justify-center"><i class="fas fa-pen text-[10px]"></i></button>
                    <button @click="toggleStatus(t)" 
                      :class="t.status === 'active' ? 'text-rose-400 hover:text-rose-600' : 'text-emerald-400 hover:text-emerald-600'"
                      class="w-8 h-8 rounded-lg border border-slate-200 transition-all flex items-center justify-center">
                      <i :class="t.status === 'active' ? 'fas fa-power-off' : 'fas fa-check'" class="text-[10px]"></i>
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- Edit Terminal Modal: Framer-Style Refinement -->
      <Teleport to="body">
        <transition name="fade">
          <div v-if="editModal.open" class="fixed inset-0 z-[150] flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-md" @click.self="editModal.open = false">
            <div class="bg-white w-full max-w-md rounded-xl shadow-2xl overflow-hidden border border-slate-200 animate-modalIn flex flex-col" dir="rtl">
              <div class="px-8 py-5 border-b border-slate-100 bg-slate-50/50 flex items-center justify-between">
                <div class="flex items-center gap-3">
                  <div class="w-8 h-8 bg-slate-900 rounded-lg flex items-center justify-center text-white"><i class="fas fa-edit text-xs"></i></div>
                  <h3 class="text-sm font-bold text-slate-900 uppercase">تعديل بيانات المحطة</h3>
                </div>
                <button @click="editModal.open = false" class="text-slate-400 hover:text-slate-900 transition-colors"><i class="fas fa-times text-lg"></i></button>
              </div>

              <div class="p-8 space-y-6">
                <div class="space-y-1.5"><label class="metadata-label">مسمى الجهاز</label><input v-model="editModal.name" type="text" class="filter-input-v2 h-10 font-bold" /></div>
                <div class="space-y-1.5"><label class="metadata-label">الفرع المرتبط</label><select v-model="editModal.branch_id" class="filter-input-v2 h-10 appearance-none font-bold"><option v-for="wh in branches" :key="wh.id" :value="wh.id">{{ wh.name }}</option></select></div>
                <div class="space-y-1.5"><label class="metadata-label">حالة التشغيل</label><select v-model="editModal.status" class="filter-input-v2 h-10 appearance-none font-bold"><option value="active">نشط</option><option value="inactive">معطل</option></select></div>
              </div>

              <div class="px-8 py-4 bg-slate-50 border-t border-slate-100 flex gap-3">
                <button @click="editModal.open = false" class="flex-1 h-9 text-xs font-bold text-slate-500">إلغاء</button>
                <LoadingButton
                  text="حفظ التعديلات"
                  :isLoading="isSaving"
                  loadingText="جاري الحفظ..."
                  icon="fas fa-save"
                  variant="primary"
                  loaderType="dots"
                  @click="saveEdit"
                  class="flex-2 h-9 px-4 rounded-md text-xs font-bold shadow-lg shadow-blue-900/20"
                />
              </div>
            </div>
          </div>
        </transition>
      </Teleport>

    </main>
  </div>
</template>

<script setup>
// [SCRIPT SECTION REMAINS 100% IDENTICAL AS PER THE CRITICAL BUSINESS LOGIC RULE]
import { ref, computed, onMounted } from 'vue'
import { useToast } from '@/composables/useToast'
import { useBranchStore } from '@/stores/branch'
import { useTerminalStore } from '@/stores/terminal/terminalStore'
import LoadingButton from '@/components/ui/LoadingButton.vue';
import BaseSkeleton from '@/components/ui/BaseSkeleton.vue';
import { useBreadcrumb } from '@/composables/useBreadcrumb';
import PageHeader from '@/components/PageHeader.vue';

const { showToast } = useToast()
const { breadcrumb } = useBreadcrumb();
const branchStore = useBranchStore()
const terminalStore = useTerminalStore()
const branches = computed(() => branchStore.branches)
const terminals = ref([])
const isLoading = ref(false)
const isSaving = ref(false)

const form = ref({
  branch_id: null,
  code: '',
  name: '',
  status: 'active',
})

const editModal = ref({ open: false, id: null, name: '', branch_id: null, status: 'active' })

const openEdit = (t) => {
  editModal.value = { open: true, id: t.id, name: t.name || '', branch_id: t.branch_id, status: t.status }
}

const saveEdit = async () => {
  try {
    isSaving.value = true
    const res = await terminalStore.updateTerminal(editModal.value.id, {
      name:      editModal.value.name.trim() || null,
      branch_id: editModal.value.branch_id,
      status:    editModal.value.status,
    })
    if (res.status === 'success') {
      showToast('تم تحديث بيانات الجهاز بنجاح', 'success')
      editModal.value.open = false
      await loadTerminals()
    } else {
      showToast(res.message || 'فشل التحديث', 'error')
    }
  } catch (e) {
    showToast(e?.response?.data?.message || e.message || 'تعذر تحديث الجهاز', 'error')
  } finally {
    isSaving.value = false
  }
}

const toggleStatus = async (t) => {
  const newStatus = t.status === 'active' ? 'inactive' : 'active'
  try {
    const res = await terminalStore.updateTerminal(t.id, { status: newStatus })
    if (res.status === 'success') {
      showToast(newStatus === 'active' ? 'تم تفعيل الجهاز' : 'تم تعطيل الجهاز', 'success')
      await loadTerminals()
    } else {
      showToast(res.message || 'فشل تغيير الحالة', 'error')
    }
  } catch (e) {
    showToast(e?.response?.data?.message || e.message || 'تعذر تغيير حالة الجهاز', 'error')
  }
}

const loadbranchs = async () => {
  try {
    await branchStore.fetchBranches()
    if (!form.value.branch_id && branches.value.length > 0) {
      form.value.branch_id = branches.value[0].id
    }
  } catch (e) {
    console.error('Failed to load branches', e)
    showToast('تعذر تحميل المخازن', 'error')
  }
}

const loadTerminals = async () => {
  try {
    isLoading.value = true
    const result = await terminalStore.fetchTerminals(form.value.branch_id || undefined);
    terminals.value = Array.isArray(result) ? result : [];
  } catch (e) {
    console.error('Failed to load terminals', e);
    showToast('فشل تحميل أجهزة نقطة البيع', 'error');
  } finally {
    isLoading.value = false
  }
}

const resetForm = () => {
  form.value.code = ''
  form.value.name = ''
  form.value.status = 'active'
  if (branches.value.length > 0) {
    form.value.branch_id = branches.value[0].id
  } else {
    form.value.branch_id = null
  }
}

const saveTerminal = async () => {
  if (!form.value.branch_id || !form.value.code.trim()) {
    showToast('الرجاء إدخال كود الجهاز واختيار المخزن', 'error')
    return
  }
  try {
    isSaving.value = true
    const payload = {
      branch_id: form.value.branch_id,
      code: form.value.code.trim(),
      name: form.value.name.trim() || null,
      status: form.value.status,
    }
    const response = await terminalStore.createTerminal(payload)
    if (response.status === 'success') {
      showToast('تم حفظ جهاز نقطة البيع بنجاح', 'success')
      resetForm()
      await loadTerminals()
    } else {
      showToast(response.message || 'Failed to save POS device', 'error')
    }
  } catch (e) {
    const msg = e?.response?.data?.message || e.message || 'تعذر حفظ جهاز نقطة البيع'
    console.error('Failed to save terminal', e)
    showToast(msg, 'error')
  } finally {
    isSaving.value = false
  }
}

onMounted(async () => {
  await loadbranchs()
  await loadTerminals()
})
</script>

<style scoped>
@keyframes loading { 0% { transform: translateX(100%); } 100% { transform: translateX(-100%); } }

.filter-input-v2 {
  @apply h-9 w-full bg-white border border-slate-200 rounded-md px-3 text-[11px] font-bold text-slate-700 focus:ring-4 focus:ring-blue-500/5 focus:border-blue-500 outline-none transition-all;
}

.metadata-label { @apply block text-[10px] font-bold text-slate-400 uppercase tracking-widest px-1 mb-1; }

.custom-scroll::-webkit-scrollbar { width: 5px; }
.custom-scroll::-webkit-scrollbar-thumb { @apply bg-slate-200 rounded-full; }

.animate-fadeIn { animation: fadeIn 0.4s ease-out; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

@keyframes modalIn { from { opacity: 0; transform: scale(0.98) translateY(10px); } to { opacity: 1; transform: scale(1) translateY(0); } }
.animate-modalIn { animation: modalIn 0.2s cubic-bezier(0.16, 1, 0.3, 1); }

.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>