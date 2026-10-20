<template>
  <div class="min-h-screen bg-[#fafafa] text-slate-900 font-sans antialiased selection:bg-blue-100 flex items-center justify-center p-4 lg:p-6" dir="rtl">
    <div class="relative w-full max-w-md bg-white border border-slate-200 rounded-xl p-8 shadow-2xl shadow-slate-200/50 overflow-hidden">
      <div class="absolute top-0 left-0 w-full h-1.5 bg-gradient-to-l from-blue-600 via-indigo-600 to-blue-500"></div>
      <div class="text-center mb-6">
        <div class="w-14 h-14 bg-white rounded-xl flex items-center justify-center mb-5 mx-auto border border-slate-100 shadow-sm">
          <img src="/favicons/logo-smartsys.png" alt="SmartSys" class="w-11 h-11 object-contain" />
        </div>
        <h1 class="text-2xl font-bold text-slate-900 mb-2">تأكيد الإيميل 📧</h1>
        <p class="text-sm text-slate-600 font-medium leading-relaxed">
          {{ statusText }}
        </p>
      </div>

      <div v-if="loading" class="text-center py-8">
        <div class="animate-spin w-8 h-8 border-2 border-blue-600 border-t-transparent rounded-full mx-auto"></div>
        <p class="mt-4 text-xs font-medium text-slate-400">بنتأكد من الرابط دلوقتي...</p>
      </div>

      <div
        v-else-if="message"
        class="p-4 rounded-lg text-center"
        :class="ok ? 'bg-emerald-50 border border-emerald-100' : 'bg-rose-50 border border-rose-100'"
      >
        <div class="text-4xl mb-3">
          {{ ok ? '🎉' : '⚠️' }}
        </div>
        <p class="text-sm font-bold" :class="ok ? 'text-emerald-700' : 'text-rose-700'">
          {{ message }}
        </p>
        <p v-if="ok && countdown > 0" class="text-xs text-emerald-600 font-medium mt-2">
          {{ autoLoginFailed ? 'هنودّيك لتسجيل الدخول' : 'بنسجّل دخولك تلقائياً' }} خلال {{ countdown }} ثواني...
        </p>
      </div>

      <div class="mt-6 text-center space-y-3">
        <router-link
          v-if="ok && autoLoginFailed"
          to="/"
          class="inline-flex items-center gap-2 h-11 px-6 bg-blue-600 text-white rounded-lg font-bold text-xs shadow-lg shadow-blue-900/10 hover:bg-blue-700 active:scale-[0.98] transition-all"
        >
          <i class="fas fa-arrow-right"></i>
          <span>سجّل دخولك دلوقتي</span>
        </router-link>

        <!-- Resend Form -->
        <div v-if="!ok" class="space-y-3">
          <div v-if="!emailFromQuery" class="relative">
            <input
              v-model="emailInput"
              type="email"
              placeholder="اكتب إيميلك هنا"
              class="w-full h-11 rounded-lg border border-slate-200 px-4 pr-11 text-xs font-medium text-slate-700 placeholder:text-slate-300 outline-none transition-all focus:border-blue-500 focus:ring-4 focus:ring-blue-50"
            />
            <i class="fas fa-envelope absolute right-4 top-1/2 -translate-y-1/2 text-slate-300"></i>
          </div>

          <button
            @click="resend"
            :disabled="resendLoading || (!emailFromQuery && !emailInput)"
            class="w-full h-11 bg-slate-100 text-slate-700 rounded-lg font-bold text-xs hover:bg-slate-200 active:scale-[0.98] transition-all disabled:opacity-50"
          >
            {{ resendLoading ? 'بنبعتلك...' : 'ابعتلي الرابط تاني' }}
          </button>
        </div>

        <router-link
          v-if="!ok"
          to="/"
          class="block text-[11px] font-bold text-slate-400 hover:text-blue-600 transition-colors"
        >
          ارجع لتسجيل الدخول
        </router-link>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import apiClient from '@/config/axios'
import { useAuthStore } from '@/stores/auth/authStore'

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

const statusText = computed(() => {
  if (loading.value) return 'بنتأكد من صلاحية الرابط...'
  if (ok.value) return 'تم تأكيد إيميلك بنجاح! 🎉'
  return 'الرابط ده مش صالح أو انتهت صلاحيته.'
})

const verify = async () => {
  if (!token.value) {
    loading.value = false
    message.value = 'الرابط غير صالح أو مفقود'
    return
  }

  try {
    const res = await apiClient.post('/auth/verify-email', { token: token.value })
    
    ok.value = true
    message.value = res?.data?.message || 'تم تأكيد بريدك الإلكتروني بنجاح!'

    const accessToken = res?.data?.access_token
    const userData    = res?.data?.data?.user
    const isSetupComplete = res?.data?.data?.is_setup_complete

    if (accessToken && userData) {
      // Server already sent refresh token cookie automatically in response
      // No flags, no workarounds needed — just store auth data and redirect
      authStore.setAuthData(userData, accessToken)
      
      const destination = isSetupComplete ? '/cashier' : '/setup'
      message.value = 'تم تأكيد بريدك الإلكتروني — جاري تسجيل الدخول تلقائياً...'
      startCountdown(destination)
    } else {
      // Fallback: redirect to login page
      autoLoginFailed.value = true
      startCountdown('/')
    }
  } catch (e) {
    ok.value = false
    message.value = e?.response?.data?.message || 'الرابط غير صالح أو منتهي الصلاحية'
  } finally {
    loading.value = false
  }
}

const startCountdown = (destination = '/') => {
  const interval = setInterval(async () => {
    countdown.value--
    if (countdown.value <= 0) {
      clearInterval(interval)
      try {
        await router.push(destination)
      } catch (err) {
        window.location.href = destination === '/' ? window.location.origin + '/' : destination
      }
    }
  }, 1000)
}

const resend = async () => {
  const targetEmail = email.value
  if (!targetEmail) {
    message.value = 'البريد الإلكتروني مطلوب لإعادة الإرسال'
    return
  }

  resendLoading.value = true
  try {
    await apiClient.post('/auth/verify-email/resend', {
      email: targetEmail,
      purpose: 'registration'
    }, { timeout: 30000 })
    message.value = 'تم إعادة إرسال رابط التأكيد — تحقق من بريدك الإلكتروني'
    ok.value = true
    startCountdown('/')
  } catch (e) {
    message.value = e?.response?.data?.message || 'فشل إعادة إرسال الرابط'
    ok.value = false
  } finally {
    resendLoading.value = false
  }
}

onMounted(() => {
  verify()
})
</script>
