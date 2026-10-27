<template>
  <AuthLayout
    centered
    :loading="loading"
    title="أنشئ كلمة مرور جديدة"
    description="اختر كلمة مرور قوية لحماية حسابك."
  >
    <form class="space-y-5" novalidate @submit.prevent="submit">
      <AuthField
        id="new-password"
        v-model="newPassword"
        label="كلمة المرور الجديدة"
        :type="passwordVisible ? 'text' : 'password'"
        placeholder="8 أحرف على الأقل"
        autocomplete="new-password"
        icon="fas fa-lock"
        dir="ltr"
        required
        autofocus
      >
        <template #suffix>
          <button
            type="button"
            class="rounded-md p-1.5 text-slate-400 transition hover:text-blue-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500"
            :aria-label="passwordVisible ? 'إخفاء كلمة المرور' : 'إظهار كلمة المرور'"
            :aria-pressed="passwordVisible"
            @click="passwordVisible = !passwordVisible"
          >
            <i :class="passwordVisible ? 'fas fa-eye-slash' : 'fas fa-eye'" aria-hidden="true"></i>
          </button>
        </template>
      </AuthField>

      <div v-if="newPassword" class="space-y-3 rounded-xl bg-slate-50 p-4" aria-live="polite">
        <div class="flex items-center gap-3">
          <div
            class="h-2 flex-1 overflow-hidden rounded-full bg-slate-200"
            role="progressbar"
            aria-label="قوة كلمة المرور"
            aria-valuemin="0"
            aria-valuemax="4"
            :aria-valuenow="strengthScore"
          >
            <div
              class="h-full rounded-full transition-all duration-300"
              :class="strengthColor"
              :style="{ width: strengthWidth }"
            ></div>
          </div>
          <span class="text-xs font-bold" :class="strengthTextColor">{{ strengthLabel }}</span>
        </div>
        <ul class="space-y-1.5 text-xs text-slate-500">
          <li :class="hasMinLength ? 'text-emerald-600' : ''">
            <i :class="hasMinLength ? 'fas fa-check' : 'fas fa-circle text-[5px]'" class="ml-2" aria-hidden="true"></i>
            8 أحرف على الأقل
          </li>
          <li :class="hasNumber ? 'text-emerald-600' : ''">
            <i :class="hasNumber ? 'fas fa-check' : 'fas fa-circle text-[5px]'" class="ml-2" aria-hidden="true"></i>
            رقم واحد على الأقل
          </li>
          <li :class="hasSpecial ? 'text-emerald-600' : ''">
            <i :class="hasSpecial ? 'fas fa-check' : 'fas fa-circle text-[5px]'" class="ml-2" aria-hidden="true"></i>
            رمز خاص واحد على الأقل
          </li>
        </ul>
      </div>

      <AuthField
        id="confirm-password"
        v-model="confirmPassword"
        label="تأكيد كلمة المرور"
        :type="passwordVisible ? 'text' : 'password'"
        placeholder="أعد كتابة كلمة المرور"
        autocomplete="new-password"
        icon="fas fa-lock"
        dir="ltr"
        required
      />

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
        text="حفظ كلمة المرور الجديدة"
        :isLoading="loading"
        loadingText="جارٍ تحديث كلمة المرور..."
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
        العودة إلى تسجيل الدخول
      </router-link>
    </template>
  </AuthLayout>
</template>

<script setup>
import { computed, onUnmounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth/authStore'
import AuthField from '@/components/auth/AuthField.vue'
import AuthLayout from '@/components/auth/AuthLayout.vue'
import LoadingButton from '@/components/ui/LoadingButton.vue'

const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()
const token = computed(() => (route.query.token || '').toString())
const newPassword = ref('')
const confirmPassword = ref('')
const passwordVisible = ref(false)
const loading = ref(false)
const message = ref('')
const ok = ref(false)
let redirectTimer

const hasMinLength = computed(() => newPassword.value.length >= 8)
const hasNumber = computed(() => /\d/.test(newPassword.value))
const hasSpecial = computed(() => /[!@#$%^&*()_+\-=[\]{};':"\\|,.<>/?]/.test(newPassword.value))
const hasUppercase = computed(() => /[A-Z]/.test(newPassword.value))
const strengthScore = computed(() => [
  hasMinLength.value,
  hasNumber.value,
  hasSpecial.value,
  hasUppercase.value,
].filter(Boolean).length)

const strengthLabel = computed(() => {
  if (strengthScore.value <= 1) return 'ضعيفة'
  if (strengthScore.value === 2) return 'متوسطة'
  if (strengthScore.value === 3) return 'جيدة'
  return 'قوية'
})

const strengthColor = computed(() => {
  if (strengthScore.value <= 1) return 'bg-rose-500'
  if (strengthScore.value === 2) return 'bg-amber-500'
  if (strengthScore.value === 3) return 'bg-blue-500'
  return 'bg-emerald-500'
})

const strengthTextColor = computed(() => {
  if (strengthScore.value <= 1) return 'text-rose-600'
  if (strengthScore.value === 2) return 'text-amber-600'
  if (strengthScore.value === 3) return 'text-blue-600'
  return 'text-emerald-600'
})

const strengthWidth = computed(() => `${(strengthScore.value / 4) * 100}%`)

const submit = async () => {
  message.value = ''
  ok.value = false

  if (!token.value) {
    message.value = 'رابط إعادة التعيين غير صالح أو مفقود'
    return
  }
  if (!newPassword.value) {
    message.value = 'كلمة المرور الجديدة مطلوبة'
    return
  }
  if (!hasMinLength.value) {
    message.value = 'كلمة المرور يجب أن تكون 8 أحرف على الأقل'
    return
  }
  if (newPassword.value !== confirmPassword.value) {
    message.value = 'كلمتا المرور غير متطابقتين'
    return
  }

  loading.value = true
  try {
    const response = await authStore.resetPassword({
      token: token.value,
      new_password: newPassword.value,
    })

    ok.value = true
    message.value = response?.message || 'تم تحديث كلمة المرور بنجاح'
    redirectTimer = setTimeout(() => router.push('/'), 1200)
  } catch (requestError) {
    message.value = requestError.response?.data?.message || 'تعذر تحديث كلمة المرور. تحقق من الرابط وحاول مرة أخرى.'
  } finally {
    loading.value = false
  }
}

onUnmounted(() => {
  clearTimeout(redirectTimer)
})
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
