<template>
  <div class="min-h-screen bg-[#fafafa] text-slate-900 font-sans antialiased selection:bg-blue-100 flex items-center justify-center p-6" dir="rtl">
    
    <!-- Top Progress Line -->
    <div class="fixed top-0 left-0 right-0 h-0.5 bg-slate-900/10 z-50">
      <div class="h-full bg-slate-900/30 w-full"></div>
    </div>

    <!-- 404 Container Card -->
    <div class="w-full max-w-md bg-white border border-slate-200 rounded-xl p-8 sm:p-10 shadow-sm text-center relative animate-fadeIn">
      
      <!-- Icon & Status Tag -->
      <div class="space-y-4 mb-6">
        <div class="w-12 h-12 rounded-lg bg-slate-50 border border-slate-200 flex items-center justify-center text-slate-700 mx-auto shadow-sm">
          <i class="fas fa-compass text-base text-slate-500"></i>
        </div>

        <div>
          <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded text-[10px] font-bold font-mono tracking-wider bg-rose-50 text-rose-600 border border-rose-100">
            HTTP 404: NOT_FOUND
          </span>
        </div>
      </div>

      <!-- Text Section -->
      <div class="space-y-2 mb-8">
        <h1 class="text-base sm:text-lg font-bold text-slate-900 tracking-tight">
          الصفحة المطلوبة غير موجودة
        </h1>
        <p class="text-xs text-slate-500 leading-relaxed max-w-xs mx-auto">
          عذراً، الرابط الذي تحاول الوصول إليه غير متاح أو تم نقله إلى مسار آخر داخل النظام.
        </p>
      </div>

      <!-- Action Controls -->
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <!-- Return to Dashboard -->
        <button 
          @click="handleGoHome"
          class="h-9 px-4 rounded-md bg-slate-900 text-white text-xs font-bold shadow-sm hover:bg-slate-800 active:scale-95 transition-all flex items-center justify-center gap-2"
        >
          <i class="fas fa-home text-[10px]"></i>
          <span>لوحة التحكم</span>
        </button>
        
        <!-- Go Back -->
        <button 
          type="button"
          @click="handleBack" 
          class="h-9 px-4 rounded-md border border-slate-200 bg-white text-slate-700 text-xs font-bold hover:bg-slate-50 active:scale-95 transition-all flex items-center justify-center gap-2 shadow-sm"
        >
          <i class="fas fa-arrow-right text-[10px]"></i>
          <span>الصفحة السابقة</span>
        </button>
      </div>

      <!-- System Status Footer -->
      <div class="mt-8 pt-6 border-t border-slate-100 flex items-center justify-center gap-2 text-[10px] font-bold text-slate-400 uppercase tracking-widest">
        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
        <span>حالة النظام: متصل ويعمل بصورة طبيعية</span>
      </div>

    </div>

  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth/authStore'

const router = useRouter()
const authStore = useAuthStore()

// توجيه ذكي حسب الصلاحية
const dashboardRoute = computed(() => {
  return authStore.isAdmin ? '/dashboard' : '/cashier'
})

const handleGoHome = () => {
  router.push(dashboardRoute.value)
}

const handleBack = () => {
  if (window.history.state?.back) {
    router.back()
  } else {
    router.push(dashboardRoute.value)
  }
}
</script>

<style scoped>
.animate-fadeIn {
  animation: fadeIn 0.3s cubic-bezier(0.16, 1, 0.3, 1);
}

@keyframes fadeIn {
  from {
    opacity: 0;
    transform: translateY(8px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}
</style>