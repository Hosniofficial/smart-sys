<template>
  <div class="min-h-screen bg-[#fafafa] text-slate-900 font-sans antialiased" dir="rtl">
    <PageLoadingBar :active="loading" />
    <div class="max-w-[1600px] mx-auto p-6 lg:p-10 space-y-6">
      <PageHeader
        :breadcrumb="breadcrumb"
        title="صحة النظام والنسخ الاحتياطي"
        description="حالة التطبيق وقاعدة البيانات ونشر Railway وجداول النسخ الاحتياطي. هذه الصفحة للقراءة فقط."
        :branches="[]"
        :selectedBranch="null"
      >
        <template #controls>
          <button type="button" class="h-9 rounded-md border border-slate-200 bg-white px-4 text-xs font-bold disabled:opacity-50" :disabled="loading" @click="load">
            {{ loading ? 'جارٍ التحديث...' : 'تحديث الحالة' }}
          </button>
        </template>
      </PageHeader>

      <p v-if="errorMessage" role="alert" class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-bold text-rose-700">
        {{ errorMessage }}
      </p>

      <section v-if="overview" class="grid grid-cols-1 gap-4 md:grid-cols-3">
        <article v-for="check in healthChecks" :key="check.label" class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
          <div class="flex items-center justify-between">
            <h2 class="text-xs font-bold text-slate-500">{{ check.label }}</h2>
            <span :class="stateClass(check.status)" class="rounded-full border px-2.5 py-1 text-[10px] font-black">
              {{ stateLabel(check.status) }}
            </span>
          </div>
          <p v-if="check.detail" class="mt-3 text-xs text-slate-500">{{ check.detail }}</p>
        </article>
      </section>

      <section v-if="railway?.status === 'not_configured'" class="rounded-xl border border-amber-200 bg-amber-50 p-5">
        <h2 class="font-black text-amber-900">تكامل Railway غير مهيأ</h2>
        <p class="mt-1 text-xs text-amber-800">أضف المتغيرات التالية إلى خدمة API على Railway. استخدم Project Access Token محدودًا ببيئة الإنتاج، ولا تضع الرمز في الواجهة أو المستودع.</p>
        <ul class="mt-3 list-inside list-disc space-y-1 font-mono text-[11px] text-amber-900">
          <li v-for="name in railway.missingConfiguration" :key="name">{{ name }}</li>
        </ul>
        <p v-if="railway.volumeConfigurationMissing" class="mt-2 text-xs text-amber-800">
          لم تُضف معرّفات وحدات التخزين؛ ستظل النسخ غير معروضة حتى ضبط RAILWAY_VOLUME_INSTANCE_IDS.
        </p>
      </section>
      <p v-else-if="railway?.status === 'unavailable'" role="status" class="rounded-xl border border-rose-200 bg-rose-50 p-5 text-sm font-bold text-rose-700">
        {{ railway.message || 'تعذر تحميل حالة Railway.' }}
      </p>

      <section v-if="railway?.status === 'available'" class="space-y-4">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
          <article v-for="service in serviceCards" :key="service.label" class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex items-start justify-between gap-4">
              <div>
                <h2 class="font-black text-slate-800">{{ service.label }}</h2>
                <p class="mt-1 text-xs text-slate-500">{{ service.data?.name || 'الخدمة غير موجودة في البيئة المحددة' }}</p>
              </div>
              <span :class="deploymentClass(service.data?.status)" class="rounded-full border px-2.5 py-1 text-[10px] font-black">
                {{ deploymentLabel(service.data?.status) }}
              </span>
            </div>
            <dl v-if="service.data" class="mt-4 grid grid-cols-2 gap-3 text-xs">
              <div>
                <dt class="text-slate-400">المنطقة</dt>
                <dd class="mt-1 font-bold text-slate-700">{{ service.data.region || '—' }}</dd>
              </div>
              <div>
                <dt class="text-slate-400">آخر نشر ناجح/حديث</dt>
                <dd class="mt-1 font-bold text-slate-700">{{ formatDate(service.data.latestDeploymentAt) }}</dd>
              </div>
              <div v-if="service.data.healthcheckPath" class="col-span-2">
                <dt class="text-slate-400">مسار فحص Railway</dt>
                <dd class="mt-1 font-mono font-bold text-slate-700">{{ service.data.healthcheckPath }}</dd>
              </div>
            </dl>
          </article>
        </div>

        <article class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
          <div class="border-b border-slate-100 px-5 py-4">
            <h2 class="font-black text-slate-800">نسخ وحدات التخزين</h2>
            <p class="mt-1 text-xs text-slate-500">عرض النسخ والجداول فقط؛ الإنشاء والاستعادة تتم من لوحة Railway بعد مراجعة أثرها.</p>
          </div>
          <div v-if="railway.volumes?.length" class="divide-y divide-slate-100">
            <section v-for="volume in railway.volumes" :key="volume.id" class="p-5">
              <div class="flex flex-wrap items-center justify-between gap-3">
                <h3 class="font-bold text-slate-800">وحدة تخزين <span class="font-mono text-xs text-slate-500">#{{ volume.id }}</span></h3>
                <span :class="stateClass(volume.status)" class="rounded-full border px-2.5 py-1 text-[10px] font-black">
                  {{ stateLabel(volume.status) }}
                </span>
              </div>
              <p v-if="volume.message" role="status" class="mt-3 text-xs font-bold text-rose-600">{{ volume.message }}</p>
              <div v-else class="mt-4 grid grid-cols-1 gap-5 lg:grid-cols-2">
                <div>
                  <h4 class="mb-2 text-[11px] font-black text-slate-500">النسخ المتاحة ({{ volume.backups?.length || 0 }})</h4>
                  <div v-if="volume.backups?.length" class="space-y-2">
                    <div v-for="backup in sortedBackups(volume.backups)" :key="backup.id" class="flex items-center justify-between gap-3 rounded-lg bg-slate-50 p-3 text-xs">
                      <div>
                        <div class="font-bold text-slate-700">{{ backup.name || 'نسخة تلقائية' }}</div>
                        <div class="mt-1 text-[10px] text-slate-400">تاريخ الإنشاء: {{ formatDate(backup.createdAt) }}</div>
                      </div>
                      <div class="text-left text-[10px] text-slate-500">
                        <div>{{ formatSize(backup.usedMB) }}</div>
                        <div class="mt-1">تنتهي: {{ formatDate(backup.expiresAt) }}</div>
                      </div>
                    </div>
                  </div>
                  <p v-else class="rounded-lg bg-slate-50 p-3 text-xs text-slate-500">لا توجد نسخ ظاهرة لهذه الوحدة.</p>
                </div>
                <div>
                  <h4 class="mb-2 text-[11px] font-black text-slate-500">جدول النسخ ({{ volume.schedules?.length || 0 }})</h4>
                  <div v-if="volume.schedules?.length" class="space-y-2">
                    <div v-for="schedule in volume.schedules" :key="schedule.id" class="rounded-lg bg-slate-50 p-3 text-xs">
                      <div class="font-bold text-slate-700">{{ schedule.name || schedule.kind }}</div>
                      <div class="mt-1 text-[10px] text-slate-500">
                        {{ schedule.cron }} · احتفاظ {{ retentionLabel(schedule.retentionSeconds) }}
                      </div>
                    </div>
                  </div>
                  <p v-else class="rounded-lg bg-amber-50 p-3 text-xs font-bold text-amber-800">لا يظهر جدول نسخ مهيأ لهذه الوحدة.</p>
                </div>
              </div>
            </section>
          </div>
          <p v-else class="p-5 text-sm font-bold text-amber-800">
            لا توجد وحدات تخزين مضافة إلى التكامل. أضف معرّفات volumes الخاصة بقاعدة البيانات وملفات التطبيق في RAILWAY_VOLUME_INSTANCE_IDS.
          </p>
        </article>
      </section>

      <p v-if="overview?.checkedAt" class="text-left text-[10px] text-slate-400">
        آخر فحص: {{ formatDate(overview.checkedAt) }}
      </p>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import PageHeader from '@/components/PageHeader.vue'
import PageLoadingBar from '@/components/ui/PageLoadingBar.vue'
import { useBreadcrumb } from '@/composables/useBreadcrumb'
import adminService from '@/services/admin'

const { breadcrumb } = useBreadcrumb()
const overview = ref(null)
const loading = ref(false)
const errorMessage = ref('')
const railway = computed(() => overview.value?.railway || null)
const healthChecks = computed(() => {
  if (!overview.value) return []
  return [
    { label: 'واجهة النظام', status: overview.value.application?.status || 'unknown', detail: 'تم الرد على طلب لوحة الإدارة.' },
    {
      label: 'قاعدة البيانات',
      status: overview.value.database?.status || 'unknown',
      detail: overview.value.database?.latencyMs != null ? `زمن الاستجابة ${overview.value.database.latencyMs} ms` : '',
    },
    { label: 'واجهة Railway', status: railway.value?.status || 'unknown', detail: railway.value?.environmentId ? `بيئة ${railway.value.environmentId}` : '' },
  ]
})
const serviceCards = computed(() => [
  { label: 'خدمة API على Railway', data: railway.value?.services?.api },
  { label: 'خدمة قاعدة البيانات على Railway', data: railway.value?.services?.database },
])

function stateLabel(status) {
  return ({
    online: 'متصل',
    connected: 'متصلة',
    available: 'متاح',
    not_configured: 'غير مهيأ',
    unavailable: 'غير متاح',
    error: 'خطأ',
    unknown: 'غير معروف',
  })[status] || status || 'غير معروف'
}

function stateClass(status) {
  return ['online', 'connected', 'available'].includes(status)
    ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
    : status === 'not_configured'
      ? 'border-amber-200 bg-amber-50 text-amber-800'
      : 'border-rose-200 bg-rose-50 text-rose-700'
}

function deploymentLabel(status) {
  return ({
    SUCCESS: 'نشر ناجح',
    BUILDING: 'قيد البناء',
    DEPLOYING: 'قيد النشر',
    FAILED: 'فشل النشر',
    CRASHED: 'توقف بعد النشر',
    QUEUED: 'في قائمة الانتظار',
    WAITING: 'بانتظار الموافقة',
    SLEEPING: 'في وضع السكون',
    REMOVED: 'محذوف',
    no_deployment: 'لا يوجد نشر',
    not_found: 'غير موجود',
  })[status] || status || 'غير معروف'
}

function deploymentClass(status) {
  return status === 'SUCCESS'
    ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
    : ['BUILDING', 'DEPLOYING', 'QUEUED', 'WAITING'].includes(status)
      ? 'border-amber-200 bg-amber-50 text-amber-800'
      : 'border-rose-200 bg-rose-50 text-rose-700'
}

function formatDate(value) {
  if (!value) return '—'
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString('ar-EG')
}

function formatSize(value) {
  const size = Number(value)
  return Number.isFinite(size) ? `${size.toLocaleString('ar-EG')} MB` : '—'
}

function retentionLabel(value) {
  const seconds = Number(value)
  if (!Number.isFinite(seconds) || seconds <= 0) return 'غير محدد'
  const days = Math.round(seconds / 86400)
  return `${days} يوم`
}

function sortedBackups(backups) {
  return [...backups].sort((a, b) => new Date(b.createdAt || 0) - new Date(a.createdAt || 0))
}

async function load() {
  loading.value = true
  errorMessage.value = ''
  try {
    const response = await adminService.getPlatformOperationsOverview()
    if (response.data?.status !== 'success' || !response.data?.data) {
      throw new Error(response.data?.message || 'استجابة حالة النظام غير صالحة.')
    }
    overview.value = response.data.data
  } catch (error) {
    overview.value = null
    errorMessage.value = error.response?.data?.message || error.message || 'تعذر تحميل حالة النظام.'
  } finally {
    loading.value = false
  }
}

onMounted(() => load())
</script>
