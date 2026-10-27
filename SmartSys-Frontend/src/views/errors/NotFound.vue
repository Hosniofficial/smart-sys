<template>
  <ErrorPageLayout>
    <template #icon><i class="fas fa-compass text-base text-slate-500"></i></template>
    <template #status>HTTP 404: NOT_FOUND</template>
    <template #title>الصفحة المطلوبة غير موجودة</template>
    <template #description>
      عذراً، الرابط الذي تحاول الوصول إليه غير متاح أو تم نقله إلى مسار آخر داخل النظام.
    </template>

    <template #actions>
      <button
        type="button"
        @click="handleGoHome"
        class="flex h-9 items-center justify-center gap-2 rounded-md bg-slate-900 px-4 text-xs font-bold text-white shadow-sm transition-all hover:bg-slate-800 active:scale-95"
      >
        <i class="fas fa-home text-[10px]"></i>
        <span>لوحة التحكم</span>
      </button>
      <button
        type="button"
        @click="handleBack"
        class="flex h-9 items-center justify-center gap-2 rounded-md border border-slate-200 bg-white px-4 text-xs font-bold text-slate-700 shadow-sm transition-all hover:bg-slate-50 active:scale-95"
      >
        <i class="fas fa-arrow-right text-[10px]"></i>
        <span>الصفحة السابقة</span>
      </button>
    </template>

    <template #footer>
      <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
      <span>حالة النظام: متصل ويعمل بصورة طبيعية</span>
    </template>
  </ErrorPageLayout>
</template>

<script setup>
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth/authStore'
import ErrorPageLayout from '@/components/errors/ErrorPageLayout.vue'

const router = useRouter()
const authStore = useAuthStore()

// توجيه ذكي حسب الصلاحية
const dashboardRoute = computed(() => {
  return authStore.isAdmin ? '/dashboard' : '/cashier'
})

const handleGoHome = () => {
  router.push(dashboardRoute.value)
}

const handleBack = () => {
  if (window.history.state?.back) {
    router.back()
  } else {
    router.push(dashboardRoute.value)
  }
}
</script>