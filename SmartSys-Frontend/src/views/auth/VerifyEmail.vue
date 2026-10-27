<template>
  <AuthLayout
    centered
    :loading="loading"
    title="تأكيد البريد الإلكتروني"
    :description="statusText"
  >
    <div v-if="loading" class="py-6 text-center" role="status" aria-live="polite">
      <div class="mx-auto h-10 w-10 animate-spin rounded-full border-2 border-blue-600 border-t-transparent"></div>
      <p class="mt-4 text-sm font-medium text-slate-500">جارٍ التحقق من صلاحية الرابط...</p>
    </div>

    <div v-else class="space-y-5">
      <div
        v-if="message"
        class="rounded-xl border p-5 text-center"
        :class="ok ? 'border-emerald-100 bg-emerald-50' : 'border-rose-100 bg-rose-50'"
        :role="ok ? 'status' : 'alert'"
      >
        <div class="mb-3 text-3xl" aria-hidden="true">{{ ok ? '✓' : '!' }}</div>
        <p class="text-sm font-bold leading-6" :class="ok ? 'text-emerald-700' : 'text-rose-700'">
          {{ message }}
        </p>
        <p v-if="ok && countdown > 0" class="mt-2 text-xs font-medium text-emerald-700">
          {{ autoLoginFailed ? 'سيتم توجيهك لتسجيل الدخول' : 'سيتم توجيهك تلقائيًا' }}
          خلال {{ countdown }} ثوانٍ.
        </p>
      </div>

      <router-link
        v-if="ok && autoLoginFailed"
        to="/"
        class="flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-blue-600 px-4 text-sm font-bold text-white transition hover:bg-blue-700 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-500/20"
      >
        <i class="fas fa-arrow-right text-xs" aria-hidden="true"></i>
        تسجيل الدخول الآن
      </router-link>

      <form v-if="!ok" class="space-y-4" @submit.prevent="resend">
        <AuthField
          v-if="!emailFromQuery"
          id="verification-email"
          v-model="emailInput"
          label="البريد الإلكتروني"
          type="email"
          placeholder="name@example.com"
          autocomplete="email"
          icon="fas fa-envelope"
          dir="ltr"
          required
        />

        <LoadingButton
          type="submit"
          text="إعادة إرسال رابط التأكيد"
          :isLoading="resendLoading"
          :disabled="!emailFromQuery && !emailInput.trim()"
          loadingText="جارٍ إرسال الرابط..."
          variant="primary"
          class="!h-12 !w-full !rounded-xl !text-sm"
        />
      </form>
    </div>

    <template v-if="!ok" #footer>
      <router-link
        to="/"
        class="inline-flex w-full items-center justify-center gap-2 text-sm font-bold text-slate-500 transition hover:text-blue-600"
      >
        <i class="fas fa-arrow-right text-xs" aria-hidden="true"></i>
        العودة إلى تسجيل الدخول
      </router-link>
    </template>
  </AuthLayout>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth/authStore'
import AuthField from '@/components/auth/AuthField.vue'
import AuthLayout from '@/components/auth/AuthLayout.vue'
import LoadingButton from '@/components/ui/LoadingButton.vue'

const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()

const token = computed(() => (route.query.token || '').toString())
const emailFromQuery = computed(() => (route.query.email || '').toString())
const email = computed(() => emailFromQuery.value || emailInput.value.trim())
const loading = ref(true)
const resendLoading = ref(false)
const message = ref('')
const ok = ref(false)
const countdown = ref(3)
const emailInput = ref('')
const autoLoginFailed = ref(false)
const verified = ref(false)
let countdownInterval

const statusText = computed(() => {
  if (loading.value) return 'جارٍ التحقق من صلاحية الرابط.'
  if (verified.value) return 'تم تأكيد بريدك الإلكتروني بنجاح.'
  if (ok.value) return 'تم إرسال رابط التأكيد إلى بريدك الإلكتروني.'
  return 'الرابط غير صالح أو انتهت صلاحيته. يمكنك طلب رابط جديد.'
})

const clearCountdown = () => {
  if (countdownInterval) {
    clearInterval(countdownInterval)
    countdownInterval = undefined
  }
}

const startCountdown = (destination = '/') => {
  clearCountdown()
  countdown.value = 3
  countdownInterval = setInterval(async () => {
    countdown.value -= 1
    if (countdown.value <= 0) {
      clearCountdown()
      try {
        await router.push(destination)
      } catch (navigationError) {
        console.error('Email verification redirect failed:', navigationError)
        window.location.assign(destination === '/' ? window.location.origin + '/' : destination)
      }
    }
  }, 1000)
}

const verify = async () => {
  if (!token.value) {
    loading.value = false
    message.value = 'رابط التأكيد غير صالح أو مفقود.'
    return
  }

  try {
    const response = await authStore.verifyEmail(token.value)
    ok.value = true
    verified.value = true
    message.value = response?.message || 'تم تأكيد بريدك الإلكتروني بنجاح.'

    const accessToken = response?.access_token
    const userData = response?.data?.user
    const isSetupComplete = response?.data?.is_setup_complete

    if (accessToken && userData) {
      authStore.setAuthData(userData, accessToken)
      message.value = 'تم تأكيد البريد الإلكتروني. جارٍ تسجيل الدخول تلقائيًا...'
      startCountdown(isSetupComplete ? '/cashier' : '/setup')
    } else {
      autoLoginFailed.value = true
      startCountdown('/')
    }
  } catch (requestError) {
    ok.value = false
    message.value = requestError.response?.data?.message || 'الرابط غير صالح أو انتهت صلاحيته.'
  } finally {
    loading.value = false
  }
}

const resend = async () => {
  if (!email.value) {
    message.value = 'أدخل البريد الإلكتروني لإعادة إرسال رابط التأكيد.'
    return
  }

  resendLoading.value = true
  message.value = ''
  try {
    await authStore.resendVerificationEmail(email.value, 'registration')
    ok.value = true
    autoLoginFailed.value = true
    message.value = 'إذا كان البريد مرتبطًا بحساب غير مفعّل، فستصلك رسالة تأكيد.'
    startCountdown('/')
  } catch (requestError) {
    ok.value = false
    message.value = requestError.response?.data?.message || 'تعذر إرسال رابط التأكيد. حاول مرة أخرى.'
  } finally {
    resendLoading.value = false
  }
}

onMounted(verify)
onUnmounted(clearCountdown)
</script>
