<template>
  <div class="min-h-screen bg-[#fafafa] text-slate-900 font-sans antialiased selection:bg-blue-100 flex items-center justify-center p-4 lg:p-6" dir="rtl">

    <!-- Top Progress Bar -->
    <div v-if="loading" class="fixed top-0 left-0 right-0 h-0.5 bg-blue-600/10 z-[110]">
      <div class="h-full bg-blue-600 animate-[loading_2s_ease-in-out_infinite] w-1/3"></div>
    </div>

    <!-- Main Recovery Card -->
    <div class="w-full max-w-md bg-white border border-slate-200 rounded-xl p-8 shadow-2xl shadow-slate-200/50 relative overflow-hidden animate-fadeIn">
      <div class="absolute top-0 left-0 w-full h-1.5 bg-gradient-to-l from-blue-600 via-indigo-600 to-blue-500"></div>

      <!-- Card Header & Identity -->
      <div class="space-y-4 mb-6">
        <div class="w-14 h-14 bg-white border border-slate-100 rounded-xl flex items-center justify-center p-1.5 shadow-sm">
          <img src="/favicons/logo-smartsys.png" alt="SmartSys" class="w-11 h-11 object-contain" />
        </div>

        <div class="space-y-1">
          <h1 class="text-xl font-bold text-slate-900 tracking-tight">نسيت كلمة المرور؟ 🔑</h1>
          <p class="text-sm text-slate-600 font-medium leading-relaxed">
            ولا يهمك، هنساعدك ترجع لحسابك في خطوات بسيطة.
          </p>
        </div>
      </div>

      <!-- Recovery Form -->
      <form class="space-y-4" @submit.prevent="submit" novalidate>

        <!-- Section Header -->
        <div class="mb-5">
          <h2 class="text-sm font-bold text-slate-900">إزاي تستعيد حسابك؟</h2>
          <p class="text-xs text-slate-500 font-medium mt-2">اكتب البريد الإلكتروني اللي سجّلت بيه، وهنبعتلك رابط تقدر من خلاله تعيّن كلمة مرور جديدة. الرابط صالح لمدة 30 دقيقة.</p>
        </div>

        <!-- Email Input -->
        <div class="space-y-1.5">
          <label class="block text-[11px] font-bold text-slate-600 uppercase tracking-wide px-1">
            البريد الإلكتروني <span class="text-rose-500">*</span>
          </label>
          <div class="relative">
            <input
              v-model="email"
              type="email"
              autocomplete="email"
              placeholder="name@example.com"
              class="auth-input pr-9 font-mono"
              required
            />
            <i class="fas fa-envelope absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 text-[11px] pointer-events-none"></i>
          </div>
        </div>

        <!-- Submit Button -->
        <div class="pt-1">
          <button
            type="submit"
            :disabled="loading"
            class="w-full h-11 bg-blue-600 text-white rounded-lg font-bold text-xs shadow-lg shadow-blue-900/10 hover:bg-blue-700 active:scale-[0.98] transition-all flex items-center justify-center gap-2 disabled:opacity-50"
          >
            <template v-if="!loading">
              <span>ابعتلي رابط إعادة التعيين</span>
            </template>
            <template v-else>
              <i class="fas fa-circle-notch animate-spin text-xs"></i>
              <span>بنبعتلك دلوقتي...</span>
            </template>
          </button>
        </div>

        <!-- Feedback Alert Banner -->
        <transition name="slide-down">
          <div
            v-if="message"
            class="p-3.5 rounded-lg border text-xs font-bold flex items-center gap-2.5 leading-normal"
            :class="ok ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-rose-50 text-rose-700 border-rose-200'"
          >
            <i :class="ok ? 'fas fa-circle-check text-emerald-600' : 'fas fa-circle-exclamation text-rose-500'" class="text-xs shrink-0"></i>
            <span>{{ message }}</span>
          </div>
        </transition>

      </form>

      <!-- Return to Login Navigation -->
      <div class="mt-6 pt-5 border-t border-slate-100 flex items-center justify-center">
        <router-link
          to="/"
          class="text-xs font-bold text-slate-500 hover:text-blue-600 transition-colors inline-flex items-center gap-2 group"
        >
          <i class="fas fa-arrow-right text-[10px] group-hover:-translate-x-1 transition-transform"></i>
          <span>افتكرت كلمة المرور؟ تسجيل الدخول</span>
        </router-link>
      </div>

    </div>

  </div>
</template>

<script setup>
import { ref } from 'vue'
import apiClient from '@/config/axios'

// --- Logic (STRICTLY PRESERVED) ---
const email = ref('')
const loading = ref(false)
const message = ref('')
const ok = ref(false)

const submit = async () => {
  message.value = ''
  ok.value = false

  if (!email.value || !email.value.includes('@')) {
    message.value = 'يرجى إدخال بريد إلكتروني صالح'
    return
  }

  loading.value = true
  try {
    const res = await apiClient.post('/auth/forgot-password', { email: email.value.trim() }, { timeout: 30000 })
    ok.value = true
    message.value = res?.data?.message || 'لو البريد الإلكتروني مرتبط بحساب، هنبعتلك عليه تعليمات استعادة كلمة المرور'
    email.value = ''
  } catch (e) {
    ok.value = true // عرض الرسالة العامة حتى لو حصل خطأ (أمان)
    message.value = e?.response?.data?.message || 'لو البريد الإلكتروني مرتبط بحساب، هنبعتلك عليه تعليمات استعادة كلمة المرور'
  } finally {
    loading.value = false
  }
}
</script>

<style scoped>
@keyframes loading {
  0% { transform: translateX(100%); }
  100% { transform: translateX(-100%); }
}

.auth-input {
  @apply h-11 w-full bg-white border border-slate-200 rounded-lg px-3 text-xs font-medium text-slate-700 placeholder:text-slate-300 focus:ring-4 focus:ring-blue-500/5 focus:border-blue-500 outline-none transition-all;
}

.animate-fadeIn {
  animation: fadeIn 0.3s cubic-bezier(0.16, 1, 0.3, 1);
}

@keyframes fadeIn {
  from { opacity: 0; transform: translateY(8px); }
  to { opacity: 1; transform: translateY(0); }
}

.slide-down-enter-active, .slide-down-leave-active {
  transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
}

.slide-down-enter-from, .slide-down-leave-to {
  opacity: 0;
  transform: translateY(-6px);
}
</style>