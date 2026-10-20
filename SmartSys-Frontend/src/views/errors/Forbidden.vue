<template>
  <div class="min-h-screen bg-[#fafafa] text-slate-900 font-sans antialiased selection:bg-rose-100 flex items-center justify-center p-6" dir="rtl">
    
    <!-- Top Progress Line -->
    <div class="fixed top-0 left-0 right-0 h-0.5 bg-rose-600/10 z-50">
      <div class="h-full bg-rose-600/40 w-full"></div>
    </div>

    <!-- 403 Card Container -->
    <div class="w-full max-w-md bg-white border border-slate-200 rounded-xl p-8 sm:p-10 shadow-sm text-center relative animate-fadeIn">
      
      <!-- Icon & Status Tag -->
      <div class="space-y-4 mb-6">
        <div class="w-12 h-12 rounded-lg bg-rose-50 border border-rose-100 flex items-center justify-center text-rose-600 mx-auto shadow-sm">
          <i class="fas fa-shield-halved text-base"></i>
        </div>

        <div>
          <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded text-[10px] font-bold font-mono tracking-wider bg-rose-50 text-rose-600 border border-rose-100">
            HTTP 403: ACCESS_DENIED
          </span>
        </div>
      </div>

      <!-- Text Section -->
      <div class="space-y-2 mb-8">
        <h1 class="text-base sm:text-lg font-bold text-slate-900 tracking-tight">
          ممنوع الوصول
        </h1>
        <p class="text-xs text-slate-500 font-medium leading-relaxed max-w-xs mx-auto">
          {{ message }}
        </p>
      </div>

      <!-- Action Buttons -->
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <!-- Dashboard Button -->
        <router-link 
          to="/cashier"
          class="h-9 px-4 rounded-md bg-slate-900 text-white text-xs font-bold shadow-sm hover:bg-slate-800 active:scale-95 transition-all flex items-center justify-center gap-2"
        >
          <i class="fas fa-home text-[10px]"></i>
          <span>لوحة التحكم</span>
        </router-link>
        
        <!-- Go Back Button -->
        <button 
          type="button"
          @click="goBack" 
          class="h-9 px-4 rounded-md border border-slate-200 bg-white text-slate-700 text-xs font-bold hover:bg-slate-50 active:scale-95 transition-all flex items-center justify-center gap-2 shadow-sm"
        >
          <i class="fas fa-arrow-right text-[10px]"></i>
          <span>رجوع للخلف</span>
        </button>
      </div>

      <!-- Footer Note -->
      <div class="mt-8 pt-6 border-t border-slate-100 flex items-center justify-center gap-2 text-[10px] font-bold text-slate-400 uppercase tracking-widest font-mono">
        <span class="w-1.5 h-1.5 rounded-full bg-rose-500"></span>
        <span>ERROR_CODE: 403_FORBIDDEN</span>
      </div>

    </div>

  </div>
</template>

<script setup>
import { useRouter, useRoute } from 'vue-router'
import { computed } from 'vue'

// --- Logic (STRICTLY PRESERVED) ---
const router = useRouter()
const route = useRoute()

// Logic: Reason Computation
const reason = computed(() => (route.query.reason || '').toString())

// Logic: Message Generator
const message = computed(() => {
  if (reason.value === 'super_admin') {
    return 'عذراً، هذه الصفحة محمية ومتاحة فقط للمشرف العام على النظام.'
  }
  if (reason.value === 'admin') {
    return 'تحتاج إلى صلاحيات مدير النظام للوصول إلى محتويات هذه الصفحة.'
  }
  return 'ليست لديك الصلاحيات الكافية للوصول إلى هذه الصفحة حالياً.'
})

// Logic: Navigation
const goBack = () => {
  if (window.history.length > 1) {
    router.back()
  } else {
    router.push('/cashier')
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