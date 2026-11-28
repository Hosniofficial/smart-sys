<template>
  <div class="min-h-screen bg-[#fafafa] text-slate-900 font-sans antialiased" dir="rtl">
    <PageLoadingBar :active="loading" />
    <div class="max-w-[1600px] mx-auto p-6 lg:p-10 space-y-6">
      <PageHeader
        :breadcrumb="breadcrumb"
        title="سجل تدقيق المنصة"
        description="سجل مركزي لتغييرات إدارة المنصة، مع عزل واضح عن سجلات عمليات المستأجرين."
        :branches="[]"
        :selectedBranch="null"
      >
        <template #controls>
          <button type="button" class="h-9 rounded-md border border-slate-200 bg-white px-4 text-xs font-bold disabled:opacity-50" :disabled="loading" @click="load">
            تحديث السجل
          </button>
        </template>
      </PageHeader>

      <section class="grid grid-cols-1 gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm md:grid-cols-5">
        <label class="space-y-1 text-[10px] font-bold text-slate-500">
          من تاريخ
          <input v-model="filters.start_date" type="date" class="h-10 w-full rounded-md border border-slate-200 px-3 text-xs" />
        </label>
        <label class="space-y-1 text-[10px] font-bold text-slate-500">
          إلى تاريخ
          <input v-model="filters.end_date" type="date" class="h-10 w-full rounded-md border border-slate-200 px-3 text-xs" />
        </label>
        <label class="space-y-1 text-[10px] font-bold text-slate-500">
          نوع الإجراء
          <select v-model="filters.action" class="h-10 w-full rounded-md border border-slate-200 px-3 text-xs">
            <option value="">كل الإجراءات</option>
            <option value="tenant_status_changed">تغيير حالة مستأجر</option>
            <option value="subscription_activated">تفعيل اشتراك</option>
            <option value="subscription_plan_changed">تغيير خطة اشتراك</option>
            <option value="subscription_security_checked">فحص أمان الاشتراك</option>
            <option value="subscription_blocked">حظر اشتراك</option>
            <option value="subscription_expired">إنهاء اشتراك</option>
            <option value="subscription_extended">تمديد اشتراك</option>
            <option value="subscription_plan_created">إنشاء خطة</option>
            <option value="subscription_plan_updated">تعديل خطة</option>
            <option value="subscription_plan_deleted">حذف خطة</option>
          </select>
        </label>
        <label class="space-y-1 text-[10px] font-bold text-slate-500">
          رقم المستأجر
          <input v-model.trim="filters.tenant_id" inputmode="numeric" class="h-10 w-full rounded-md border border-slate-200 px-3 text-xs" placeholder="اختياري" />
        </label>
        <button type="button" class="h-10 self-end rounded-md bg-slate-900 px-4 text-xs font-bold text-white disabled:opacity-50" :disabled="loading" @click="applyFilters">
          تطبيق الفلاتر
        </button>
      </section>

      <p v-if="errorMessage" role="alert" class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-bold text-rose-700">
        {{ errorMessage }}
      </p>

      <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
          <h2 class="text-sm font-black text-slate-800">عمليات المنصة</h2>
          <span class="text-xs font-bold text-slate-400">إجمالي السجلات: {{ pagination.total }}</span>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full min-w-[780px] text-right">
            <thead class="bg-slate-50 text-[10px] font-bold text-slate-500">
              <tr>
                <th class="px-5 py-4">التاريخ والوقت</th>
                <th class="px-4 py-4">المنفذ</th>
                <th class="px-4 py-4">الإجراء</th>
                <th class="px-4 py-4">المستأجر</th>
                <th class="px-5 py-4">التفاصيل والسبب</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="item in items" :key="item.id" class="align-top">
                <td class="whitespace-nowrap px-5 py-4 text-xs text-slate-500">{{ formatDate(item.timestamp) }}</td>
                <td class="px-4 py-4 text-xs font-bold text-slate-700">{{ item.actorName || `#${item.actorId}` }}</td>
                <td class="px-4 py-4 text-xs font-bold text-indigo-700">{{ actionLabel(item.action) }}</td>
                <td class="px-4 py-4 text-xs">
                  <span class="font-bold text-slate-700">{{ item.tenantName || '—' }}</span>
                  <span v-if="item.tenantId" class="mr-1 font-mono text-[10px] text-slate-400">#{{ item.tenantId }}</span>
                </td>
                <td class="px-5 py-4 text-xs text-slate-600">
                  <div v-for="(value, key) in item.details" :key="key" class="mb-1 last:mb-0">
                    <span class="font-bold text-slate-500">{{ detailLabel(key) }}:</span>
                    {{ displayDetail(key, value) }}
                  </div>
                  <span v-if="!Object.keys(item.details || {}).length">—</span>
                </td>
              </tr>
              <tr v-if="!loading && items.length === 0">
                <td colspan="5" class="px-5 py-16 text-center text-sm font-bold text-slate-400">لا توجد سجلات مطابقة.</td>
              </tr>
              <tr v-if="loading && items.length === 0">
                <td colspan="5" class="px-5 py-16 text-center text-sm font-bold text-slate-400">جارٍ تحميل السجل...</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="flex items-center justify-between border-t border-slate-100 px-5 py-3 text-xs text-slate-500">
          <span>الصفحة {{ pagination.page }} من {{ Math.max(1, pagination.total_pages) }}</span>
          <div class="flex gap-2">
            <button type="button" class="rounded border px-3 py-1.5 disabled:opacity-40" :disabled="loading || pagination.page <= 1" @click="changePage(pagination.page - 1)">السابق</button>
            <button type="button" class="rounded border px-3 py-1.5 disabled:opacity-40" :disabled="loading || pagination.page >= pagination.total_pages" @click="changePage(pagination.page + 1)">التالي</button>
          </div>
        </div>
      </section>
    </div>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import PageHeader from '@/components/PageHeader.vue'
import PageLoadingBar from '@/components/ui/PageLoadingBar.vue'
import { useBreadcrumb } from '@/composables/useBreadcrumb'
import adminService from '@/services/admin'

const { breadcrumb } = useBreadcrumb()
const loading = ref(false)
const items = ref([])
const errorMessage = ref('')
const filters = reactive({ start_date: '', end_date: '', action: '', tenant_id: '' })
const pagination = reactive({ page: 1, per_page: 20, total: 0, total_pages: 0 })

function actionLabel(action) {
  return ({
    tenant_status_changed: 'تغيير حالة مستأجر',
    subscription_activated: 'تفعيل اشتراك',
    subscription_plan_changed: 'تغيير خطة اشتراك',
    subscription_security_checked: 'فحص أمان الاشتراك',
    subscription_blocked: 'حظر اشتراك',
    subscription_expired: 'إنهاء اشتراك',
    subscription_extended: 'تمديد اشتراك',
    subscription_plan_created: 'إنشاء خطة',
    subscription_plan_updated: 'تعديل خطة',
    subscription_plan_deleted: 'حذف خطة',
  })[action] || action || '—'
}

function detailLabel(key) {
  return ({
    reason: 'السبب',
    from_status: 'الحالة السابقة',
    to_status: 'الحالة الجديدة',
    subscription_id: 'رقم الاشتراك',
    plan_id: 'رقم الخطة',
    plan_code: 'رمز الخطة',
    from_plan: 'الخطة السابقة',
    to_plan: 'الخطة الجديدة',
    days: 'الأيام المضافة',
    risk_score: 'درجة المخاطر',
    security_flags: 'مؤشرات الأمان',
    updated_fields: 'الحقول المعدلة',
  })[key] || key
}

function displayDetail(key, value) {
  if (key === 'from_status' || key === 'to_status') return statusLabel(value)
  if (Array.isArray(value)) return value.join('، ')
  if (value === null || value === undefined || value === '') return '—'
  return String(value)
}

function statusLabel(status) {
  return ({ active: 'نشط', trial: 'تجريبي', inactive: 'موقوف', blocked: 'محظور' })[status] || status || '—'
}

function formatDate(value) {
  if (!value) return '—'
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString('ar-EG')
}

async function load() {
  loading.value = true
  errorMessage.value = ''
  try {
    const response = await adminService.listPlatformAuditLogs({
      page: pagination.page,
      per_page: pagination.per_page,
      start_date: filters.start_date || undefined,
      end_date: filters.end_date || undefined,
      action: filters.action || undefined,
      tenant_id: filters.tenant_id || undefined,
    })
    const payload = response.data?.data
    if (response.data?.status !== 'success' || !Array.isArray(payload?.items)) {
      throw new Error(response.data?.message || 'استجابة سجل التدقيق غير صالحة.')
    }
    items.value = payload.items
    Object.assign(pagination, payload.pagination || {})
  } catch (error) {
    items.value = []
    errorMessage.value = error.response?.data?.message || error.message || 'تعذر تحميل سجل التدقيق.'
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

onMounted(() => load())
</script>
