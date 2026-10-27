<template>
  <AuthLayout
    wide
    title="أنشئ حسابك التجاري"
    description="ابدأ تجربتك لإدارة نشاطك ومتابعة أعمالك من مكان واحد."
    aside-title="ابدأ بتنظيم أعمالك"
    aside-highlight="بخطوات بسيطة"
    aside-description="أنشئ حسابك لمتابعة المبيعات والمخزون والحسابات اليومية في مساحة واحدة."
  >
    <transition name="fade-scale" mode="out-in">
      <section v-if="registered" class="space-y-6" aria-live="polite">
        <div class="text-center">
          <div class="mx-auto mb-5 flex h-16 w-16 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-600">
            <i class="fas fa-envelope-circle-check text-3xl" aria-hidden="true"></i>
          </div>
          <h2 class="text-xl font-bold text-slate-900">تم إنشاء حسابك</h2>
          <p class="mt-2 text-sm leading-7 text-slate-500">
            أرسلنا رابط التفعيل إلى بريدك الإلكتروني:
          </p>
          <p class="mt-1 break-all text-sm font-bold text-blue-600" dir="ltr">{{ form.email }}</p>
        </div>

        <div class="rounded-xl border border-amber-100 bg-amber-50 p-4 text-sm leading-6 text-amber-800">
          <p class="flex items-start gap-3">
            <i class="fas fa-circle-info mt-1 shrink-0" aria-hidden="true"></i>
            <span>إذا لم تجد الرسالة، تحقق من مجلد البريد غير المرغوب فيه أو أعد إرسال الرابط.</span>
          </p>
        </div>

        <div v-if="error" class="rounded-xl border border-rose-100 bg-rose-50 p-3.5 text-sm font-semibold text-rose-700" role="alert">
          {{ error }}
        </div>
        <div v-if="success" class="rounded-xl border border-emerald-100 bg-emerald-50 p-3.5 text-sm font-semibold text-emerald-700" role="status">
          {{ success }}
        </div>

        <LoadingButton
          type="button"
          text="إعادة إرسال رابط التفعيل"
          :isLoading="resendLoading"
          loadingText="جارٍ إرسال الرابط..."
          variant="primary"
          class="!h-12 !w-full !rounded-xl !text-sm"
          @click="resendVerification"
        />
      </section>

      <form v-else class="space-y-4" novalidate @submit.prevent="submit">
        <AuthField
          id="full_name"
          v-model="form.full_name"
          label="اسم صاحب النشاط"
          placeholder="اكتب اسمك بالكامل"
          autocomplete="name"
          icon="fas fa-id-card"
        />

        <AuthField
          id="username"
          v-model="form.username"
          label="اسم المستخدم"
          placeholder="مثال: smart_user"
          autocomplete="username"
          icon="fas fa-at"
          dir="ltr"
          input-class="font-mono"
          required
        />

        <AuthField
          id="email"
          v-model="form.email"
          label="البريد الإلكتروني"
          type="email"
          placeholder="name@example.com"
          autocomplete="email"
          icon="fas fa-envelope"
          dir="ltr"
          required
        />

        <AuthField
          id="password"
          v-model="form.password"
          label="كلمة المرور"
          type="password"
          placeholder="8 أحرف على الأقل"
          autocomplete="new-password"
          icon="fas fa-lock"
          hint="استخدم 8 أحرف على الأقل، ويفضّل تضمين أرقام ورموز."
          dir="ltr"
          required
        />

        <div v-if="error" class="rounded-xl border border-rose-100 bg-rose-50 p-3.5 text-sm font-semibold leading-6 text-rose-700" role="alert">
          {{ error }}
        </div>

        <LoadingButton
          type="submit"
          text="إنشاء الحساب"
          :isLoading="loading"
          loadingText="جارٍ إنشاء الحساب..."
          variant="primary"
          class="!h-12 !w-full !rounded-xl !text-sm"
        />
      </form>
    </transition>

    <template #footer>
      <div class="text-center">
        <p class="text-sm font-medium text-slate-500">
          {{ registered ? 'لديك حساب بالفعل؟' : 'عندك حساب بالفعل؟' }}
        </p>
        <router-link
          to="/"
          class="mt-2 inline-flex items-center gap-2 text-sm font-bold text-blue-600 transition hover:text-blue-800"
        >
          تسجيل الدخول
          <i class="fas fa-arrow-left text-[10px]" aria-hidden="true"></i>
        </router-link>
      </div>
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
const loading = ref(false)
const error = ref('')
const success = ref('')
const registered = ref(false)
const resendLoading = ref(false)

const form = ref({ full_name: '', username: '', email: '', password: '' })

function validate() {
  if (!form.value.username.trim() || !form.value.email.trim() || !form.value.password) {
    error.value = 'يرجى تعبئة الحقول المطلوبة'
    return false
  }

  if (form.value.password.length < 8) {
    error.value = 'كلمة المرور يجب أن تكون 8 أحرف على الأقل'
    return false
  }

  const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
  if (!emailRegex.test(form.value.email.trim())) {
    error.value = 'صيغة البريد الإلكتروني غير صحيحة'
    return false
  }

  const usernameRegex = /^[a-zA-Z0-9_]{3,20}$/
  if (!usernameRegex.test(form.value.username.trim())) {
    error.value = 'اسم المستخدم يجب أن يكون 3-20 حرف، أحرف وأرقام وشرطة سفلية فقط'
    return false
  }

  error.value = ''
  return true
}

async function submit() {
  if (!validate()) return

  loading.value = true
  error.value = ''
  success.value = ''
  try {
    const result = await authStore.register({
      full_name: form.value.full_name.trim(),
      username: form.value.username.trim(),
      email: form.value.email.trim(),
      password: form.value.password,
    })

    if (result?.status === 'success') {
      registered.value = true
    } else {
      error.value = result?.message || 'تعذر إكمال التسجيل'
    }
  } catch (requestError) {
    error.value = requestError.response?.data?.message || 'حدث خطأ أثناء إنشاء الحساب. حاول مرة أخرى.'
  } finally {
    loading.value = false
  }
}

async function resendVerification() {
  if (!form.value.email.trim()) return

  resendLoading.value = true
  error.value = ''
  success.value = ''
  try {
    await authStore.resendVerificationEmail(form.value.email.trim(), 'registration')
    success.value = 'تم إرسال رابط التفعيل مرة أخرى. تحقق من بريدك الإلكتروني.'
  } catch (requestError) {
    error.value = requestError.response?.data?.message || 'تعذر إرسال الرابط. حاول مرة أخرى.'
  } finally {
    resendLoading.value = false
  }
}
</script>

<style scoped>
.fade-scale-enter-active,
.fade-scale-leave-active {
  transition: opacity 0.2s ease, transform 0.2s ease;
}

.fade-scale-enter-from,
.fade-scale-leave-to {
  opacity: 0;
  transform: scale(0.98);
}
</style>
