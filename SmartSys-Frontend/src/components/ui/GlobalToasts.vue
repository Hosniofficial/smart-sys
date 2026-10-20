<template>
  <!-- Toast Container: Positioned at the top center with high z-index -->
  <div class="fixed top-8 left-1/2 -translate-x-1/2 z-[10000] space-y-3 w-full max-w-[400px] px-4 pointer-events-none" dir="rtl">
    <transition-group 
      name="toast-list" 
      tag="div" 
      class="flex flex-col items-center gap-3 w-full"
    >
      <div 
        v-for="t in toasts" 
        :key="t.id" 
        class="pointer-events-auto w-full group relative overflow-hidden"
      >
        <!-- Modern Toast Card -->
        <div 
          class="flex items-start gap-4 p-4 rounded-2xl shadow-2xl border border-white backdrop-blur-md bg-white/90 transition-all duration-300 hover:scale-[1.02]"
          :class="toastBorderClass(t.type)"
        >
          <!-- Status Icon -->
          <div 
            class="w-10 h-10 rounded-xl flex items-center justify-center shrink-0 shadow-sm transition-transform duration-500 group-hover:rotate-12"
            :class="iconBgClass(t.type)"
          >
            <i :class="[iconClass(t.type), 'text-lg']"></i>
          </div>

          <!-- Message Content -->
          <div class="flex-grow pt-1">
            <p class="text-xs font-black uppercase tracking-widest opacity-40 mb-1 leading-none">
              {{ typeTitle(t.type) }}
            </p>
            <p class="text-sm font-bold text-slate-700 leading-relaxed">
              {{ t.message }}
            </p>
          </div>

          <!-- Progress Bar (Visual Timer) -->
          <div class="absolute bottom-0 right-0 h-1 bg-slate-100/30 w-full">
            <div 
              class="h-full transition-all duration-[3000ms] ease-linear"
              :class="progressColorClass(t.type)"
              :style="{ width: '0%' }"
            ></div>
          </div>
        </div>
      </div>
    </transition-group>
  </div>
</template>

<script setup>
import { storeToRefs } from 'pinia'
import { useUiStore } from '@/stores/ui/uiStore'

// ✅ مستعادة من النسخة القديمة: ربط الـ store بنفس الطريقة
const ui = useUiStore()
const { toasts } = storeToRefs(ui)

// ✅ مستعادة من النسخة القديمة: دالة toastClass الأصلية (للـ fallback أو الاستخدام المستقبلي)
const toastClass = (type) => {
  switch (type) {
    case 'error': return 'bg-red-500'
    case 'info': return 'bg-blue-500'
    default: return 'bg-green-500'
  }
}

// طبقة التصميم الجديدة
const typeTitle = (type) => {
  switch (type) {
    case 'error': return 'خطأ في النظام'
    case 'info': return 'تنبيه معلوماتي'
    case 'warning': return 'تحذير'
    default: return 'إشعار نجاح'
  }
}

const iconClass = (type) => {
  switch (type) {
    case 'error': return 'fas fa-circle-xmark text-rose-600'
    case 'info': return 'fas fa-info-circle text-blue-600'
    case 'warning': return 'fas fa-exclamation-triangle text-amber-600'
    default: return 'fas fa-check-circle text-emerald-600'
  }
}

const iconBgClass = (type) => {
  switch (type) {
    case 'error': return 'bg-rose-50'
    case 'info': return 'bg-blue-50'
    case 'warning': return 'bg-amber-50'
    default: return 'bg-emerald-50'
  }
}

const toastBorderClass = (type) => {
  switch (type) {
    case 'error': return 'border-rose-100 shadow-rose-100/20'
    case 'info': return 'border-blue-100 shadow-blue-100/20'
    case 'warning': return 'border-amber-100 shadow-amber-100/20'
    default: return 'border-emerald-100 shadow-emerald-100/20'
  }
}

const progressColorClass = (type) => {
  switch (type) {
    case 'error': return 'bg-rose-500'
    case 'info': return 'bg-blue-500'
    case 'warning': return 'bg-amber-500'
    default: return 'bg-emerald-500'
  }
}
</script>

<style scoped>

.toast-list-enter-active {
  transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
}
.toast-list-leave-active {
  transition: all 0.3s ease-in;
  position: absolute;
}

.toast-list-enter-from {
  opacity: 0;
  transform: translateY(-20px) scale(0.9);
}
.toast-list-leave-to {
  opacity: 0;
  transform: translateY(-10px) scale(0.95);
}

/* Move animation for existing toasts when one is removed */
.toast-list-move {
  transition: transform 0.4s ease;
}

.shadow-2xl {
  box-shadow: 0 20px 40px -10px rgba(15, 23, 42, 0.1);
}

.custom-scroll::-webkit-scrollbar { width: 4px; }
.custom-scroll::-webkit-scrollbar-thumb { @apply bg-slate-200 rounded-full; }
</style>