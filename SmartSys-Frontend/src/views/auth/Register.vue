<template>
  <div class="min-h-screen bg-[#fafafa] flex items-center justify-center p-4 lg:p-10 text-slate-900 font-sans antialiased selection:bg-blue-100" dir="rtl">
    
    <!-- Register Card Container: Geometric shell consistent with Login page -->
    <div class="w-full max-w-4xl bg-white rounded-xl shadow-2xl shadow-slate-200/50 overflow-hidden border border-slate-200 relative animate-fadeIn flex flex-col lg:flex-row">
      
      <!-- Top Decorative Identity Bar -->
      <div class="absolute top-0 left-0 w-full h-1.5 bg-gradient-to-l from-blue-600 via-indigo-600 to-blue-500"></div>

      <!-- Content Area -->
      <div class="w-full lg:w-[480px] p-8 md:p-14 flex flex-col justify-center relative z-10">
        
        <!-- Header: Focused and Professional -->
        <div class="mb-10">
          <div class="w-14 h-14 bg-white rounded-xl flex items-center justify-center mb-6 border border-slate-100 shadow-sm">
            <img src="/favicons/logo-smartsys.png" alt="SmartSys" class="w-11 h-11 object-contain" />
          </div>
          <h1 class="text-2xl font-bold tracking-tight text-slate-900">أنشئ حسابك التجاري 🚀</h1>
          <p class="text-sm text-slate-600 mt-3 font-medium leading-relaxed">خلّي شغلك أذكى وأسهل وابدأ تجربتك المجانية لإدارة نشاطك بكل سهولة وتنمية أعمالك.</p>
        </div>

        <!-- Success State: Trust-focused UI -->
        <transition name="fade-scale" mode="out-in">
          <div v-if="registered" class="text-center py-4 space-y-8 animate-fadeIn">
            <div class="w-20 h-20 bg-emerald-50 text-emerald-500 rounded-full flex items-center justify-center mx-auto shadow-inner border border-emerald-100">
              <i class="fas fa-envelope-circle-check text-3xl"></i>
            </div>
            <div class="space-y-2">
              <h2 class="text-xl font-bold text-slate-900">اتبعتلك رابط التفعيل!</h2>
              <p class="text-sm font-medium text-slate-500 leading-relaxed">
                افتح بريدك الإلكتروني واضغط على الرابط اللي بعتناهولك:<br/>
                <span class="text-blue-600 font-bold font-mono">{{ form.email }}</span>
              </p>
            </div>
            
            <div class="bg-amber-50 border border-amber-100 p-5 rounded-lg text-right">
              <p class="text-[11px] font-bold text-amber-700 leading-relaxed flex gap-3">
                <i class="fas fa-info-circle mt-0.5"></i>
                <span>لو الرسالة ماجاتكش خلال دقايق، اتأكد من مجلد الـ Spam أو ابعت تاني.</span>
              </p>
            </div>

            <div class="space-y-4">
              <button
                @click="resendVerification"
                :disabled="resendLoading"
                class="w-full h-11 bg-slate-900 text-white rounded-lg font-bold text-xs uppercase tracking-widest hover:bg-black active:scale-[0.98] transition-all disabled:opacity-50 shadow-lg shadow-slate-200"
              >
                {{ resendLoading ? 'بنبعت...' : 'ابعت الرابط تاني' }}
              </button>
              
              <router-link
                to="/"
                class="flex items-center justify-center gap-2 text-xs font-bold text-slate-400 hover:text-blue-600 transition-colors uppercase"
              >
                <i class="fas fa-chevron-right text-[8px]"></i>
                <span>ارجع لتسجيل الدخول</span>
              </router-link>
            </div>
          </div>

          <!-- Registration Form -->
          <form v-else @submit.prevent="submit" class="space-y-6" novalidate>
            
            <!-- Full Name Field -->
            <div class="space-y-1.5 group">
              <label for="full_name" class="metadata-label">اسم صاحب النشاط</label>
              <div class="relative">
                <input
                  id="full_name"
                  v-model="form.full_name"
                  type="text"
                  class="register-input"
                  placeholder="اكتب اسمك بالكامل"
                />
                <i class="fas fa-id-card absolute right-3.5 top-1/2 -translate-y-1/2 text-slate-300 group-focus-within:text-blue-500 transition-colors text-[10px]"></i>
              </div>
            </div>

            <!-- Username Field -->
            <div class="space-y-1.5 group">
              <label for="username" class="metadata-label">اسم المستخدم <span class="text-rose-500">*</span></label>
              <div class="relative">
                <input
                  id="username"
                  v-model="form.username"
                  type="text"
                  class="register-input font-mono tracking-tighter"
                  required
                  placeholder="username"
                />
                <i class="fas fa-at absolute right-3.5 top-1/2 -translate-y-1/2 text-slate-300 group-focus-within:text-blue-500 transition-colors text-[10px]"></i>
              </div>
            </div>

            <!-- Email Field -->
            <div class="space-y-1.5 group">
              <label for="email" class="metadata-label">البريد الإلكتروني <span class="text-rose-500">*</span></label>
              <div class="relative">
                <input
                  id="email"
                  v-model="form.email"
                  type="email"
                  class="register-input font-mono"
                  required
                  placeholder="mail@example.com"
                />
                <i class="fas fa-envelope absolute right-3.5 top-1/2 -translate-y-1/2 text-slate-300 group-focus-within:text-blue-500 transition-colors text-[10px]"></i>
              </div>
            </div>

            <!-- Password Field -->
            <div class="space-y-1.5 group">
              <label for="password" class="metadata-label">كلمة المرور <span class="text-rose-500">*</span></label>
              <div class="relative">
                <input
                  id="password"
                  v-model="form.password"
                  type="password"
                  class="register-input font-mono tracking-widest"
                  required
                  placeholder="••••••••"
                />
                <i class="fas fa-lock absolute right-3.5 top-1/2 -translate-y-1/2 text-slate-300 group-focus-within:text-blue-500 transition-colors text-[10px]"></i>
              </div>
              <p class="text-[10px] text-slate-400 font-medium px-1">استخدم 8 أحرف على الأقل، ويفضّل تضمين أرقام ورموز لزيادة الأمان</p>
            </div>

            <!-- Feedback Notifications -->
            <div class="space-y-3">
              <transition name="slide-down">
                <div v-if="error" class="bg-rose-50 border border-rose-100 p-3 rounded-lg flex items-center gap-3 shadow-sm shadow-rose-50/50">
                  <i class="fas fa-exclamation-circle text-rose-500 text-xs shrink-0"></i>
                  <span class="text-[11px] font-bold text-rose-700 leading-normal">{{ error }}</span>
                </div>
              </transition>

              <transition name="slide-down">
                <div v-if="success" class="bg-emerald-50 border border-emerald-100 p-3 rounded-lg flex items-center gap-3 shadow-sm shadow-emerald-50/50">
                  <i class="fas fa-check-circle text-emerald-500 text-xs shrink-0"></i>
                  <span class="text-[11px] font-bold text-emerald-700 leading-normal">{{ success }}</span>
                </div>
              </transition>
            </div>

            <!-- Submit Action -->
            <div class="pt-4">
              <button
                type="submit"
                :disabled="loading"
                class="w-full h-12 bg-blue-600 text-white rounded-lg font-bold text-xs uppercase tracking-widest shadow-lg shadow-blue-900/10 hover:bg-blue-700 active:scale-[0.98] transition-all flex items-center justify-center gap-3 disabled:opacity-50"
              >
                <template v-if="!loading">
                  <span>إنشاء حسابي والبدء مجانًا</span>
                </template>
                <BaseSpinner v-else :size="18" color="#fff" />
              </button>
            </div>

            <!-- Login Navigation -->
            <div class="text-center pt-8 border-t border-slate-100">
              <p class="text-[11px] font-bold text-slate-400 uppercase tracking-widest">عندك حساب بالفعل؟</p>
              <router-link to="/" class="inline-block mt-3 text-xs font-bold text-blue-600 hover:text-blue-800 transition-all">تسجيل الدخول</router-link>
            </div>
          </form>
        </transition>
      </div>

      <div class="hidden lg:flex lg:flex-1 relative bg-slate-50 flex-col justify-end p-16">
        <div class="absolute inset-0 z-0 overflow-hidden">
          <div class="absolute inset-0 bg-gradient-to-br from-blue-50 via-white to-slate-100"></div>
          <img src="/favicons/logo-smartsys.png" alt="" aria-hidden="true" class="absolute -left-20 -top-24 w-[28rem] opacity-[0.04] rotate-12" />
        </div>
        <div class="relative z-10">
          <div class="bg-white/85 backdrop-blur-md border border-white p-8 rounded-2xl shadow-sm">
            <img src="/favicons/banner-smartsys.png" alt="SmartSys" class="w-52 h-auto mb-8" />
            <h2 class="text-3xl font-bold text-slate-900 leading-tight mb-4">ابدأ بإدارة أعمالك<br><span class="text-blue-600">من مكان واحد</span></h2>
            <p class="text-slate-600 text-sm font-medium leading-relaxed max-w-sm">أنشئ حسابك لمتابعة المبيعات والمخزون والحسابات اليومية في مساحة منظمة.</p>
            <div class="mt-8 pt-6 border-t border-slate-100 flex flex-wrap items-center gap-x-5 gap-y-3 text-[11px] font-bold text-slate-500">
              <span class="inline-flex items-center gap-2"><i class="fas fa-receipt text-blue-500"></i>المبيعات</span>
              <span class="inline-flex items-center gap-2"><i class="fas fa-boxes-stacked text-blue-500"></i>المخزون</span>
              <span class="inline-flex items-center gap-2"><i class="fas fa-calculator text-blue-500"></i>الحسابات</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
// [SCRIPT SECTION REMAINS 100% IDENTICAL AS PER THE CRITICAL BUSINESS LOGIC RULE]
import { ref } from 'vue'
import { useAuthStore } from '@/stores/auth/authStore';
import { useRouter } from 'vue-router'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import apiClient from '@/config/axios'

const authStore = useAuthStore();
const router = useRouter()
const loading = ref(false)
const error = ref('')
const success = ref('')
const registered = ref(false)
const resendLoading = ref(false)

const form = ref({ full_name: '', username: '', email: '', password: '' })

function validate() {
  if (!form.value.username || !form.value.email || !form.value.password) {
    error.value = 'يرجى تعبئة الحقول المطلوبة'
    return false
  }
  
  // Validate password length
  if (form.value.password.length < 8) {
    error.value = 'كلمة المرور يجب أن تكون 8 أحرف على الأقل'
    return false
  }
  
  // Validate email format
  const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
  if (!emailRegex.test(form.value.email)) {
    error.value = 'صيغة البريد الإلكتروني غير صحيحة'
    return false
  }
  
  // Validate username format (3-20 characters, alphanumeric and underscore only)
  const usernameRegex = /^[a-zA-Z0-9_]{3,20}$/
  if (!usernameRegex.test(form.value.username)) {
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
  const result = await authStore.register({
    full_name: form.value.full_name,
    username: form.value.username,
    email: form.value.email,
    password: form.value.password,
  })
  if (result.status === 'success') {
    registered.value = true
  } else {
    error.value = result.message || 'تعذر إكمال التسجيل'
  }
  loading.value = false
}

async function resendVerification() {
  if (!form.value.email) return
  resendLoading.value = true
  try {
    await apiClient.post('/auth/verify-email/resend', {
      email: form.value.email,
      purpose: 'registration'
    }, {
      timeout: 30000
    })
    success.value = 'تم إعادة إرسال رابط التأكيد'
  } catch (e) {
    error.value = 'فشل إعادة إرسال الرابط'
  } finally {
    resendLoading.value = false
  }
}
</script>

<style scoped>
/* High-Density SaaS Form Elements */
.register-input {
  @apply w-full h-11 bg-white border border-slate-200 rounded-lg px-4 pr-10 outline-none transition-all duration-300 focus:ring-4 focus:ring-blue-500/5 focus:border-blue-500 text-xs font-medium text-slate-700 placeholder-slate-300;
}

.metadata-label {
  @apply block text-[11px] font-bold text-slate-600 uppercase tracking-wide mb-2 px-1;
}

/* Animations */
.animate-fadeIn { animation: fadeIn 0.4s cubic-bezier(0.16, 1, 0.3, 1); }
@keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

.slide-down-enter-active { transition: all 0.3s ease-out; }
.slide-down-enter-from { opacity: 0; transform: translateY(-5px); }

.fade-scale-enter-active { transition: all 0.3s ease; }
.fade-scale-enter-from { opacity: 0; transform: scale(0.95); }

button:disabled { cursor: not-allowed; }
</style>