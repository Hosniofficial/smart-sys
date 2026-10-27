<template>
  <AuthLayout
    wide
    title="أهلاً بيك من جديد"
    description="سجّل دخولك لحسابك وكمّل شغلك بكل سهولة."
  >
    <form class="space-y-5" novalidate @submit.prevent="handleLogin">
      <AuthField
        id="username"
        v-model="username"
        label="اسم المستخدم أو البريد الإلكتروني"
        placeholder="اكتب اسم المستخدم أو البريد الإلكتروني"
        autocomplete="username"
        icon="fas fa-user"
        required
        autofocus
      />

      <AuthField
        id="password"
        v-model="password"
        label="كلمة المرور"
        :type="passwordVisible ? 'text' : 'password'"
        placeholder="••••••••"
        autocomplete="current-password"
        icon="fas fa-lock"
        input-class="tracking-[0.2em]"
        required
      >
        <template #suffix>
          <button
            type="button"
            class="rounded-md p-1.5 text-slate-400 transition hover:text-blue-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500"
            :aria-label="passwordVisible ? 'إخفاء كلمة المرور' : 'إظهار كلمة المرور'"
            :aria-pressed="passwordVisible"
            @click="togglePasswordVisibility"
          >
            <i :class="passwordVisible ? 'fas fa-eye-slash' : 'fas fa-eye'" aria-hidden="true"></i>
          </button>
        </template>
      </AuthField>

      <transition name="slide-down">
        <div
          v-if="error"
          class="flex items-start gap-3 rounded-xl border border-rose-100 bg-rose-50 p-3.5 text-sm font-semibold leading-6 text-rose-700"
          role="alert"
        >
          <i class="fas fa-circle-exclamation mt-1 shrink-0 text-rose-500" aria-hidden="true"></i>
          <span>{{ error }}</span>
        </div>
      </transition>

      <div class="flex justify-end">
        <router-link
          to="/forgot-password"
          class="text-sm font-bold text-blue-600 transition hover:text-blue-800"
        >
          نسيت كلمة المرور؟
        </router-link>
      </div>

      <LoadingButton
        type="submit"
        text="تسجيل الدخول"
        :isLoading="loading"
        loadingText="جارٍ تسجيل الدخول..."
        icon="fas fa-arrow-left"
        variant="primary"
        class="!h-12 !w-full !rounded-xl !text-sm"
      />
    </form>

    <template #footer>
      <div class="text-center">
        <p class="text-sm font-medium text-slate-500">لسه معندكش حساب؟</p>
        <router-link
          to="/register"
          class="mt-2 inline-flex items-center gap-2 text-sm font-bold text-blue-600 transition hover:text-blue-800"
        >
          أنشئ حسابك التجاري
          <i class="fas fa-arrow-left text-[10px]" aria-hidden="true"></i>
        </router-link>
      </div>
    </template>
  </AuthLayout>
</template>

<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth/authStore'
import AuthField from '@/components/auth/AuthField.vue'
import AuthLayout from '@/components/auth/AuthLayout.vue'
import LoadingButton from '@/components/ui/LoadingButton.vue'

const router = useRouter()
const authStore = useAuthStore()
const { showToast } = useToast()

const username = ref('')
const password = ref('')
const passwordVisible = ref(false)
const loading = ref(false)
const error = ref('')

const togglePasswordVisibility = () => {
  passwordVisible.value = !passwordVisible.value
}

const handleLogin = async () => {
  error.value = ''
  if (!username.value.trim() || !password.value) {
    showToast('يرجى تعبئة جميع الحقول', 'warning')
    return
  }

  loading.value = true
  try {
    const result = await authStore.login(username.value.trim(), password.value)
    if (result?.success) {
      showToast('تم تسجيل الدخول بنجاح', 'success')
      const redirectPath = authStore.returnUrl || '/cashier'
      authStore.returnUrl = null
      router.push(redirectPath).catch((navigationError) => {
        console.error('Navigation error:', navigationError)
        router.push('/cashier')
      })
    } else {
      const errorMessage = result?.message || 'اسم المستخدم أو كلمة المرور غير صحيحة'
      error.value = errorMessage
      showToast(errorMessage, 'error')
    }
  } catch (requestError) {
    console.error('Login error:', requestError)
    const errorMessage = requestError.response?.data?.message || 'حدث خطأ أثناء تسجيل الدخول'
    error.value = errorMessage
    showToast(errorMessage, 'error')
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
