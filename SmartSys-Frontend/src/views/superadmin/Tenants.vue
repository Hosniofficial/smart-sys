<template>
  <div class="min-h-screen bg-[#fafafa] text-slate-900 font-sans antialiased" dir="rtl">
    <PageLoadingBar :active="loading" />
    <div class="max-w-[1600px] mx-auto p-6 lg:p-10 space-y-6">
      <PageHeader
        :breadcrumb="breadcrumb"
        title="إدارة المستأجرين"
        description="مراجعة حالة الشركات وحساباتها وتغيير حالة المستأجر مع توثيق السبب."
        :branches="[]"
        :selectedBranch="null"
      >
        <template #controls>
          <button
            type="button"
            :disabled="loading"
            class="h-9 px-4 rounded-md border border-slate-200 bg-white text-xs font-bold text-slate-700 hover:bg-slate-50 disabled:opacity-50"
            @click="load"
          >
            تحديث
          </button>
        </template>
      </PageHeader>

      <section class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div v-for="card in summaryCards" :key="card.label" class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
          <p class="text-xs font-bold text-slate-400">{{ card.label }}</p>
          <p class="mt-2 text-2xl font-black text-slate-900">{{ card.value }}</p>
        </div>
      </section>

      <section class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="grid grid-cols-1 gap-3 md:grid-cols-[1fr_220px_auto]">
          <input
            v-model.trim="filters.search"
            type="search"
            maxlength="100"
            placeholder="ابحث باسم الشركة أو رقمها"
            class="h-10 rounded-md border border-slate-200 px-3 text-sm outline-none focus:border-indigo-400"
            @keyup.enter="applyFilters"
          />
          <select v-model="filters.status" class="h-10 rounded-md border border-slate-200 px-3 text-sm">
            <option value="">كل الحالات</option>
            <option value="active">نشط</option>
            <option value="trial">تجريبي</option>
            <option value="inactive">موقوف</option>
          </select>
          <button
            type="button"
            :disabled="loading"
            class="h-10 rounded-md bg-slate-900 px-5 text-xs font-bold text-white disabled:opacity-50"
            @click="applyFilters"
          >
            بحث
          </button>
        </div>
      </section>

      <p v-if="errorMessage" role="alert" class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-bold text-rose-700">
        {{ errorMessage }}
      </p>

      <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="overflow-x-auto">
          <table class="w-full min-w-[760px] text-right">
            <thead class="bg-slate-50 text-[11px] text-slate-500">
              <tr>
                <th class="px-5 py-4">المستأجر</th>
                <th class="px-4 py-4">الحالة</th>
                <th class="px-4 py-4 text-center">المستخدمون</th>
                <th class="px-4 py-4">الإعداد</th>
                <th class="px-4 py-4">تاريخ الإنشاء</th>
                <th class="px-5 py-4 text-center">إجراء</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="tenant in tenants" :key="tenant.id" class="hover:bg-slate-50/70">
                <td class="px-5 py-4">
                  <div class="font-bold text-slate-900">{{ tenant.companyName }}</div>
                  <div class="mt-1 font-mono text-[10px] text-slate-400">#{{ tenant.id }}</div>
                </td>
                <td class="px-4 py-4">
                  <span :class="statusClass(tenant.status)" class="rounded-full border px-2.5 py-1 text-[10px] font-bold">
                    {{ statusLabel(tenant.status) }}
                  </span>
                </td>
                <td class="px-4 py-4 text-center font-mono text-sm">{{ tenant.userCount }}</td>
                <td class="px-4 py-4 text-xs font-bold text-slate-600">
                  {{ Number(tenant.isSetupComplete) === 1 ? 'مكتمل' : (tenant.currentStep || 'غير مكتمل') }}
                </td>
                <td class="px-4 py-4 text-xs text-slate-500">{{ formatDate(tenant.createdAt) }}</td>
                <td class="px-5 py-4 text-center">
                  <button
                    type="button"
                    class="rounded-md border border-slate-200 px-3 py-2 text-[10px] font-bold text-slate-700 hover:border-indigo-300 hover:text-indigo-700"
                    @click="openStatusDialog(tenant)"
                  >
                    تغيير الحالة
                  </button>
                </td>
              </tr>
              <tr v-if="!loading && tenants.length === 0">
                <td colspan="6" class="px-5 py-16 text-center text-sm font-bold text-slate-400">لا توجد نتائج مطابقة.</td>
              </tr>
              <tr v-if="loading && tenants.length === 0">
                <td colspan="6" class="px-5 py-16 text-center text-sm font-bold text-slate-400">جارٍ تحميل المستأجرين...</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="flex items-center justify-between border-t border-slate-100 px-5 py-3 text-xs text-slate-500">
          <span>النتائج: {{ pagination.total }} · الصفحة {{ pagination.page }} من {{ Math.max(1, pagination.total_pages) }}</span>
          <div class="flex gap-2">
            <button type="button" class="rounded border px-3 py-1.5 disabled:opacity-40" :disabled="loading || pagination.page <= 1" @click="changePage(pagination.page - 1)">السابق</button>
            <button type="button" class="rounded border px-3 py-1.5 disabled:opacity-40" :disabled="loading || pagination.page >= pagination.total_pages" @click="changePage(pagination.page + 1)">التالي</button>
          </div>
        </div>
      </section>

      <div v-if="selectedTenant" class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/50 p-4" @click.self="closeStatusDialog">
        <form class="w-full max-w-lg space-y-4 rounded-xl bg-white p-6 shadow-2xl" @submit.prevent="submitStatusChange">
          <div>
            <h2 class="text-lg font-black text-slate-900">تغيير حالة {{ selectedTenant.companyName }}</h2>
            <p class="mt-1 text-xs text-slate-500">الحالة الجديدة ستؤثر على إمكانية استخدام المستأجر للنظام.</p>
          </div>
          <label class="block space-y-1.5 text-xs font-bold text-slate-600">
            الحالة الجديدة
            <select v-model="statusForm.status" class="h-10 w-full rounded-md border border-slate-200 px-3">
              <option value="active">نشط</option>
              <option value="trial">تجريبي</option>
              <option value="inactive">موقوف</option>
            </select>
          </label>
          <label class="block space-y-1.5 text-xs font-bold text-slate-600">
            سبب التغيير (إلزامي)
            <textarea v-model.trim="statusForm.reason" required minlength="5" maxlength="500" rows="3" class="w-full rounded-md border border-slate-200 p-3" />
          </label>
          <p v-if="dialogError" role="alert" class="text-xs font-bold text-rose-600">{{ dialogError }}</p>
          <div class="flex justify-end gap-2">
            <button type="button" class="rounded-md border px-4 py-2 text-xs font-bold" :disabled="saving" @click="closeStatusDialog">إلغاء</button>
            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-xs font-bold text-white disabled:opacity-50" :disabled="saving || statusForm.status === selectedTenant.status">
              {{ saving ? 'جارٍ الحفظ...' : 'حفظ وتسجيل التدقيق' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import PageHeader from '@/components/PageHeader.vue'
import PageLoadingBar from '@/components/ui/PageLoadingBar.vue'
import { useBreadcrumb } from '@/composables/useBreadcrumb'
import { useToast } from '@/composables/useToast'
import { useTenantContextStore } from '@/stores/tenantContext/tenantContextStore'
import adminService from '@/services/admin'

const { breadcrumb } = useBreadcrumb()
const { showToast } = useToast()
const tenantContextStore = useTenantContextStore()
const tenants = ref([])
const loading = ref(false)
const saving = ref(false)
const errorMessage = ref('')
const dialogError = ref('')
const selectedTenant = ref(null)
const filters = reactive({ search: '', status: '' })
const statusForm = reactive({ status: '', reason: '' })
const pagination = reactive({ page: 1, per_page: 20, total: 0, total_pages: 0 })

const summaryCards = computed(() => [
  { label: 'النتائج في الصفحة', value: tenants.value.length },
  { label: 'المستأجرون النشطون في النتائج', value: tenants.value.filter(item => item.status === 'active').length },
  { label: 'إجمالي النتائج المطابقة', value: pagination.total },
])

function statusLabel(status) {
  return ({ active: 'نشط', trial: 'تجريبي', inactive: 'موقوف' })[status] || status
}

function statusClass(status) {
  return status === 'active'
    ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
    : status === 'trial'
      ? 'border-amber-200 bg-amber-50 text-amber-700'
      : 'border-slate-200 bg-slate-100 text-slate-600'
}

function formatDate(value) {
  if (!value) return '—'
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? '—' : date.toLocaleDateString('ar-EG')
}

async function load() {
  loading.value = true
  errorMessage.value = ''
  try {
    const response = await adminService.listPlatformTenants({
      page: pagination.page,
      per_page: pagination.per_page,
      search: filters.search || undefined,
      status: filters.status || undefined,
    })
    const payload = response.data?.data
    if (response.data?.status !== 'success' || !Array.isArray(payload?.items)) {
      throw new Error(response.data?.message || 'استجابة قائمة المستأجرين غير صالحة.')
    }
    tenants.value = payload.items
    Object.assign(pagination, payload.pagination || {})
  } catch (error) {
    tenants.value = []
    errorMessage.value = error.response?.data?.message || error.message || 'تعذر تحميل قائمة المستأجرين.'
  } finally {
    loading.value = false
  }
}

function applyFilters() {
  pagination.page = 1
  void load()
}

function changePage(page) {
  pagination.page = page
  void load()
}

function openStatusDialog(tenant) {
  selectedTenant.value = tenant
  statusForm.status = tenant.status
  statusForm.reason = ''
  dialogError.value = ''
}

function closeStatusDialog() {
  if (saving.value) return
  selectedTenant.value = null
  dialogError.value = ''
}

async function submitStatusChange() {
  if (!selectedTenant.value || statusForm.status === selectedTenant.value.status) return
  if (statusForm.reason.trim().length < 5) {
    dialogError.value = 'اكتب سببًا واضحًا لا يقل عن 5 أحرف.'
    return
  }

  saving.value = true
  dialogError.value = ''
  try {
    const response = await adminService.updatePlatformTenantStatus(selectedTenant.value.id, {
      status: statusForm.status,
      reason: statusForm.reason.trim(),
    })
    if (response.data?.status !== 'success') {
      throw new Error(response.data?.message || 'تعذر تحديث حالة المستأجر.')
    }

    if (
      Number(tenantContextStore.activeTenantId) === Number(selectedTenant.value.id) &&
      statusForm.status !== 'active'
    ) {
      tenantContextStore.clearActiveTenantId()
    }

    window.dispatchEvent(new Event('platform-tenant-state-changed'))
    showToast('تم تحديث حالة المستأجر وتسجيل العملية في سجل التدقيق.', 'success')
    selectedTenant.value = null
    await load()
  } catch (error) {
    dialogError.value = error.response?.data?.message || error.message || 'تعذر تحديث حالة المستأجر.'
  } finally {
    saving.value = false
  }
}

onMounted(() => load())
</script>
