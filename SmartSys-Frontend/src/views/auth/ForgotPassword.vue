<template>
  <AuthLayout
    centered
    :loading="loading"
    title="استعادة كلمة المرور"
    description="أدخل البريد الإلكتروني المرتبط بحسابك وسنرسل لك تعليمات إعادة التعيين."
  >
    <form class="space-y-5" novalidate @submit.prevent="submit">
      <AuthField
        id="recovery-email"
        v-model="email"
        label="البريد الإلكتروني"
        type="email"
        placeholder="name@example.com"
        autocomplete="email"
        icon="fas fa-envelope"
        dir="ltr"
        required
        autofocus
      />

      <div class="rounded-xl border border-blue-100 bg-blue-50 p-4 text-sm leading-6 text-blue-800">
        <p class="flex items-start gap-3">
          <i class="fas fa-circle-info mt-1 shrink-0 text-blue-600" aria-hidden="true"></i>
          <span>إذا كان البريد مرتبطًا بحساب، فستصلك رسالة تحتوي على رابط صالح لمدة 30 دقيقة.</span>
        </p>
      </div>

      <transition name="slide-down">
        <div
          v-if="message"
          class="flex items-start gap-3 rounded-xl border p-3.5 text-sm font-semibold leading-6"
          :class="ok ? 'border-emerald-100 bg-emerald-50 text-emerald-700' : 'border-rose-100 bg-rose-50 text-rose-700'"
          :role="ok ? 'status' : 'alert'"
        >
          <i
            :class="ok ? 'fas fa-circle-check text-emerald-600' : 'fas fa-circle-exclamation text-rose-500'"
            class="mt-1 shrink-0"
            aria-hidden="true"
          ></i>
          <span>{{ message }}</span>
        </div>
      </transition>

      <LoadingButton
        type="submit"
        text="إرسال رابط إعادة التعيين"
        :isLoading="loading"
        loadingText="جارٍ إرسال التعليمات..."
        variant="primary"
        class="!h-12 !w-full !rounded-xl !text-sm"
      />
    </form>

    <template #footer>
      <router-link
        to="/"
        class="inline-flex w-full items-center justify-center gap-2 text-sm font-bold text-slate-500 transition hover:text-blue-600"
      >
        <i class="fas fa-arrow-right text-xs" aria-hidden="true"></i>
        تذكرت كلمة المرور؟ تسجيل الدخول
      </router-link>
    </template>
  </AuthLayout>
</template>

<script setup>
import { ref } from 'vue'
import { useAuthStore } from '@/stores/auth/authStore'
import AuthField from '@/components/auth/AuthField.vue'
import AuthLayout from '@/components/auth/AuthLayout.vue'
import LoadingButton from '@/components/ui/LoadingButton.vue'

const authStore = useAuthStore()
const email = ref('')
const loading = ref(false)
const message = ref('')
const ok = ref(false)

const submit = async () => {
  message.value = ''
  ok.value = false

  const normalizedEmail = email.value.trim()
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(normalizedEmail)) {
    message.value = 'يرجى إدخال بريد إلكتروني صالح'
    return
  }

  loading.value = true
  try {
    const response = await authStore.requestPasswordReset(normalizedEmail)
    ok.value = true
    message.value = response?.message
      || 'إذا كان البريد مرتبطًا بحساب، فستصلك تعليمات استعادة كلمة المرور.'
    email.value = ''
  } catch {
    message.value = 'تعذر إرسال الطلب الآن. يرجى المحاولة مرة أخرى لاحقًا.'
  } finally {
    loading.value = false
  }
}
</script>

<style scoped>
.slide-down-enter-active,
.slide-down-leave-active {
  transition: opacity 0.2s ease, transform 0.2s ease;
}

.slide-down-enter-from,
.slide-down-leave-to {
  opacity: 0;
  transform: translateY(-4px);
}
</style>
