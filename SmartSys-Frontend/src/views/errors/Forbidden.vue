<template>
  <ErrorPageLayout tone="danger">
    <template #icon><i class="fas fa-shield-halved text-base"></i></template>
    <template #status>HTTP 403: ACCESS_DENIED</template>
    <template #title>ممنوع الوصول</template>
    <template #description>{{ message }}</template>

    <template #actions>
      <router-link
        to="/cashier"
        class="flex h-9 items-center justify-center gap-2 rounded-md bg-slate-900 px-4 text-xs font-bold text-white shadow-sm transition-all hover:bg-slate-800 active:scale-95"
      >
        <i class="fas fa-home text-[10px]"></i>
        <span>لوحة التحكم</span>
      </router-link>
      <button
        type="button"
        @click="goBack"
        class="flex h-9 items-center justify-center gap-2 rounded-md border border-slate-200 bg-white px-4 text-xs font-bold text-slate-700 shadow-sm transition-all hover:bg-slate-50 active:scale-95"
      >
        <i class="fas fa-arrow-right text-[10px]"></i>
        <span>رجوع للخلف</span>
      </button>
    </template>

    <template #footer>
      <span class="h-1.5 w-1.5 rounded-full bg-rose-500"></span>
      <span>ERROR_CODE: 403_FORBIDDEN</span>
    </template>
  </ErrorPageLayout>
</template>

<script setup>
import { useRouter, useRoute } from 'vue-router'
import { computed } from 'vue'
import ErrorPageLayout from '@/components/errors/ErrorPageLayout.vue'

// --- Logic (STRICTLY PRESERVED) ---
const router = useRouter()
const route = useRoute()

// Logic: Reason Computation
const reason = computed(() => (route.query.reason || '').toString())

// Logic: Message Generator
const message = computed(() => {
  if (reason.value === 'super_admin') {
    return 'عذراً، هذه الصفحة محمية ومتاحة فقط للمشرف العام على النظام.'
  }
  if (reason.value === 'admin') {
    return 'تحتاج إلى صلاحيات مسؤول المنشأة للوصول إلى محتويات هذه الصفحة.'
  }
  return 'ليست لديك الصلاحيات الكافية للوصول إلى هذه الصفحة حالياً.'
})

// Logic: Navigation
const goBack = () => {
  if (window.history.length > 1) {
    router.back()
  } else {
    router.push('/cashier')
  }
}
</script>