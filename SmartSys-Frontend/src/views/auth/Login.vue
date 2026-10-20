<template>
  <div class="min-h-screen bg-[#fafafa] flex items-center justify-center p-4 lg:p-10 text-slate-900 font-sans antialiased selection:bg-blue-100" dir="rtl">
    
    <!-- Login Card Container: High-contrast geometric shell -->
    <div class="w-full max-w-4xl bg-white rounded-xl shadow-2xl shadow-slate-200/50 flex flex-col lg:flex-row overflow-hidden border border-slate-200 relative animate-fadeIn">
      <div class="absolute top-0 left-0 w-full h-1.5 bg-gradient-to-l from-blue-600 via-indigo-600 to-blue-500 z-20"></div>
      
      <!-- Right Panel: Login Form -->
      <div class="w-full lg:w-[480px] p-8 md:p-14 flex flex-col justify-center relative z-10">
        <div class="mb-10">
          <div class="w-14 h-14 bg-white rounded-xl flex items-center justify-center mb-6 border border-slate-100 shadow-sm">
            <img src="/favicons/logo-smartsys.png" alt="SmartSys" class="w-11 h-11 object-contain" />
          </div>
          <h1 class="text-2xl font-bold tracking-tight text-slate-900">أهلاً بيك من جديد 👋</h1>
          <p class="text-sm text-slate-600 mt-3 font-medium leading-relaxed">سجّل دخولك لحسابك وكمّل شغلك بكل سهولة</p>
        </div>

        <form @submit.prevent="handleLogin" @keydown.enter="handleKeyDown" class="space-y-5" novalidate>
          
          <!-- Username/Email Field -->
          <div class="space-y-1.5 group">
            <label for="username" class="metadata-label">اسم المستخدم أو البريد الإلكتروني <span class="text-rose-500">*</span></label>
            <div class="relative">
              <input
                v-model="username"
                type="text"
                id="username"
                class="login-input"
                required
                autocomplete="username"
                placeholder="اكتب اسم المستخدم أو البريد الإلكتروني"
              />
              <i class="fas fa-user absolute right-3.5 top-1/2 -translate-y-1/2 text-slate-300 group-focus-within:text-blue-500 transition-colors text-[10px]"></i>
            </div>
          </div>

          <!-- Password Field -->
          <div class="space-y-1.5 group">
            <label for="password" class="metadata-label">كلمة المرور <span class="text-rose-500">*</span></label>
            <div class="relative">
              <input
                v-model="password"
                :type="passwordVisible ? 'text' : 'password'"
                id="password"
                class="login-input pl-12 font-mono tracking-widest"
                required
                autocomplete="current-password"
                placeholder="••••••••"
              />
              <i class="fas fa-lock absolute right-3.5 top-1/2 -translate-y-1/2 text-slate-300 group-focus-within:text-blue-500 transition-colors text-[10px]"></i>
              
              <!-- Toggle Visibility -->
              <button
                type="button"
                @click="togglePasswordVisibility"
                class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-300 hover:text-blue-600 transition-colors p-1 flex items-center"
              >
                <i :class="[passwordVisible ? 'fa fa-eye-slash' : 'fa fa-eye']" class="text-xs"></i>
              </button>
            </div>
          </div>

          <!-- Error Alert: Stripe-style -->
          <transition name="slide-down">
            <div v-if="error" class="bg-rose-50 border border-rose-100 p-3 rounded-lg flex items-center gap-3">
              <i class="fas fa-exclamation-circle text-rose-500 text-xs shrink-0"></i>
              <span class="text-[11px] font-bold text-rose-700 leading-normal">{{ error }}</span>
            </div>
          </transition>

          <!-- Extra Options -->
          <div class="flex items-center justify-between py-1">
            <label class="flex items-center gap-2 cursor-pointer group select-none">
              <input type="checkbox" v-model="rememberMe" class="w-4 h-4 rounded border-slate-300 text-blue-600 focus:ring-0 transition-all cursor-pointer" />
              <span class="text-[11px] font-bold text-slate-500 group-hover:text-slate-900 transition-colors">تذكر دخولي</span>
            </label>
            <router-link to="/forgot-password" class="text-[11px] font-bold text-blue-600 hover:text-blue-800 transition-colors uppercase tracking-tighter">نسيت كلمة المرور؟</router-link>
          </div>

          <!-- Login Button -->
          <div class="pt-2">
            <button
              type="submit"
              :disabled="loading"
              class="w-full h-11 bg-slate-900 text-white rounded-lg font-bold text-xs shadow-lg shadow-slate-200 hover:bg-black active:scale-[0.98] transition-all flex items-center justify-center gap-3 disabled:opacity-50"
            >
              <template v-if="!loading">
                <span>تسجيل الدخول</span>
                <i class="fas fa-chevron-left text-[8px]"></i>
              </template>
              <BaseSpinner v-else :size="16" color="#fff" />
            </button>
          </div>

          <!-- Registration Footer -->
          <div class="text-center pt-8 border-t border-slate-100">
            <p class="text-[11px] font-bold text-slate-400 uppercase tracking-widest">لسه معندكش حساب؟</p>
            <router-link to="/register" class="inline-block mt-3 text-xs font-bold text-blue-600 hover:text-blue-800 transition-all">أنشئ حسابك التجاري دلوقتي</router-link>
          </div>
        </form>
      </div>

      <!-- Left Panel: Professional Brand Visual -->
      <div class="hidden lg:flex lg:flex-1 relative bg-slate-50 flex-col justify-end p-16">
        <!-- Abstract Brand Image -->
        <div class="absolute inset-0 z-0 overflow-hidden">
          <div class="absolute inset-0 bg-gradient-to-br from-blue-50 via-white to-slate-100"></div>
          <img
            src="/favicons/logo-smartsys.png"
            alt=""
            aria-hidden="true"
            class="absolute -left-20 -top-24 w-[28rem] opacity-[0.04] rotate-12"
          />
        </div>
        
        <div class="relative z-20 space-y-6">
          <div class="bg-white/85 backdrop-blur-md border border-white p-8 rounded-2xl shadow-sm">
            <img src="/favicons/banner-smartsys.png" alt="SmartSys" class="w-52 h-auto mb-8" />
            <h2 class="text-3xl font-bold text-slate-900 leading-tight mb-4">إدارة أعمالك<br><span class="text-blue-600">من مكان واحد</span></h2>
            <p class="text-slate-600 text-sm font-medium leading-relaxed max-w-sm">تابع المبيعات، راقب المخزون، وأدر حساباتك اليومية بكل سهولة.</p>
            
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
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useToast } from '@/composables/useToast';
import { useLoader } from '@/composables/useLoader';
import { useAuthStore } from '@/stores/auth/authStore';
import BaseSpinner from '@/components/ui/BaseSpinner.vue';

const router = useRouter();
const authStore = useAuthStore();
const { showToast } = useToast();
const { showLoader, hideLoader } = useLoader();

const username = ref('');
const password = ref('');
const passwordVisible = ref(false);
const loading = ref(false);
const error = ref('');
const rememberMe = ref(false);

onMounted(() => {
  const usernameField = document.getElementById('username');
  if (usernameField) {
    usernameField.focus();
  }
});

const togglePasswordVisibility = () => {
  passwordVisible.value = !passwordVisible.value;
};

const handleLogin = async () => {
  error.value = '';
  if (!username.value || !password.value) {
    showToast('يرجى تعبئة جميع الحقول', 'warning');
    return;
  }
  loading.value = true;
  error.value = '';
  showLoader();
  try {
    const result = await authStore.login(username.value, password.value);
    if (result?.success) {
      showToast('تم تسجيل الدخول بنجاح', 'success');
      const redirectPath = authStore.returnUrl || '/cashier';
      authStore.returnUrl = null;
      router.push(redirectPath).catch(err => {
        console.error('Navigation error:', err);
        router.push('/cashier');
      });
    } else {
      const errorMessage = result?.message || 'اسم المستخدم أو كلمة المرور غير صحيحة';
      error.value = errorMessage;
      showToast(errorMessage, 'error');
    }
  } catch (err) {
    console.error('Login error:', err);
    const errorMessage = err.response?.data?.message || 'حدث خطأ أثناء تسجيل الدخول';
    error.value = errorMessage;
    showToast(errorMessage, 'error');
  } finally {
    loading.value = false;
    hideLoader();
  }
};

const handleKeyDown = (e) => {
  if (e.key === 'Enter') {
    handleLogin();
  }
};
</script>

<style scoped>
/* High-Density SaaS Form Elements */
.login-input {
  @apply w-full h-11 bg-white border border-slate-200 rounded-lg px-4 pr-10 outline-none transition-all duration-300 focus:ring-4 focus:ring-blue-500/5 focus:border-blue-500 text-xs font-medium text-slate-700;
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
</style>