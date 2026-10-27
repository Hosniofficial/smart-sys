<template>
  <teleport to="body">
    <div v-if="isVisible" class="fixed inset-0 z-[9999] flex items-center justify-center p-4">
      
      <!-- Backdrop Layer -->
      <div 
        class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm transition-opacity duration-200"
        :class="backdropOpacity"
        @click="handleBackdropClick"
      ></div>
      
      <!-- Alert Modal Card -->
      <div 
        class="relative bg-white border border-slate-200 rounded-xl shadow-xl max-w-md w-full overflow-hidden transform transition-all duration-200 font-sans antialiased text-slate-900"
        :class="modalScale"
        dir="rtl"
        role="dialog"
        aria-modal="true"
      >
        <!-- Modal Content Area -->
        <div class="p-6">
          <div class="flex items-start gap-4">
            
            <!-- Type Icon Container -->
            <div class="shrink-0">
              <!-- Success Icon -->
              <div v-if="type === 'success'" class="w-10 h-10 rounded-lg bg-emerald-50 text-emerald-600 border border-emerald-100 flex items-center justify-center text-sm shadow-sm">
                <i class="fas fa-check"></i>
              </div>
              
              <!-- Warning Icon -->
              <div v-else-if="type === 'warning'" class="w-10 h-10 rounded-lg bg-amber-50 text-amber-600 border border-amber-100 flex items-center justify-center text-sm shadow-sm">
                <i class="fas fa-triangle-exclamation"></i>
              </div>
              
              <!-- Error Icon -->
              <div v-else-if="type === 'error'" class="w-10 h-10 rounded-lg bg-rose-50 text-rose-600 border border-rose-100 flex items-center justify-center text-sm shadow-sm">
                <i class="fas fa-xmark text-base"></i>
              </div>
              
              <!-- Info Icon -->
              <div v-else-if="type === 'info'" class="w-10 h-10 rounded-lg bg-blue-50 text-blue-600 border border-blue-100 flex items-center justify-center text-sm shadow-sm">
                <i class="fas fa-circle-info"></i>
              </div>
              
              <!-- Question / Confirm Icon -->
              <div v-else-if="type === 'confirm'" class="w-10 h-10 rounded-lg bg-blue-50 text-blue-600 border border-blue-100 flex items-center justify-center text-sm shadow-sm">
                <i class="fas fa-circle-question"></i>
              </div>
            </div>
            
            <!-- Title & Message -->
            <div class="flex-1 space-y-1 pt-0.5">
              <div class="flex items-center gap-2">
                <h3 class="text-sm font-bold text-slate-900 leading-snug" v-if="title">
                  {{ title }}
                </h3>
                <span v-if="type !== 'confirm'" :class="badgeTypeClass" class="px-1.5 py-0.2 rounded text-[9px] font-bold tracking-wider border">
                  {{ typeLabel }}
                </span>
              </div>
              <p class="text-xs text-slate-500 font-medium leading-relaxed whitespace-pre-line" v-if="message">
                {{ message }}
              </p>
            </div>

          </div>
        </div>
        
        <!-- Actions Footer -->
        <div class="px-6 py-3 bg-slate-50/70 border-t border-slate-100 flex items-center justify-end gap-2.5">
          <!-- Cancel Button -->
          <button
            type="button"
            @click="handleCancel"
            class="h-9 px-4 rounded-md border border-slate-200 bg-white text-slate-700 text-xs font-bold hover:bg-slate-50 active:scale-95 transition-all shadow-sm flex items-center justify-center"
            :class="cancelButtonClass"
          >
            {{ cancelText || (type === 'confirm' ? 'إلغاء' : 'إغلاق') }}
          </button>

          <!-- Confirm Button -->
          <button
            v-if="type === 'confirm' || confirmText"
            type="button"
            @click="handleConfirm"
            class="h-9 px-4 rounded-md text-xs font-bold shadow-sm active:scale-95 transition-all flex items-center justify-center gap-1.5"
            :class="confirmButtonClass"
          >
            {{ confirmText || 'تأكيد' }}
          </button>
        </div>

      </div>
    </div>
  </teleport>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'

const props = defineProps({
  isVisible: {
    type: Boolean,
    default: false
  },
  type: {
    type: String,
    default: 'info',
    validator: (value) => ['success', 'warning', 'error', 'info', 'confirm'].includes(value)
  },
  title: {
    type: String,
    default: ''
  },
  message: {
    type: String,
    default: ''
  },
  confirmText: {
    type: String,
    default: ''
  },
  cancelText: {
    type: String,
    default: ''
  },
  closeOnBackdrop: {
    type: Boolean,
    default: true
  },
  persistent: {
    type: Boolean,
    default: false
  }
})

const emit = defineEmits(['confirm', 'cancel', 'close'])

// Animation states
const animationState = ref('enter')

// Computed classes for animations
const backdropOpacity = computed(() => {
  return animationState.value === 'enter' ? 'opacity-0' : 'opacity-100'
})

const modalScale = computed(() => {
  switch (animationState.value) {
    case 'enter':
      return 'scale-95 opacity-0'
    case 'active':
      return 'scale-100 opacity-100'
    case 'exit':
      return 'scale-95 opacity-0'
    default:
      return 'scale-100 opacity-100'
  }
})

// Design system buttons classes
const confirmButtonClass = computed(() => {
  switch (props.type) {
    case 'error':
      return 'bg-rose-600 text-white hover:bg-rose-700'
    case 'warning':
      return 'bg-amber-500 text-white hover:bg-amber-600'
    case 'success':
      return 'bg-emerald-600 text-white hover:bg-emerald-700'
    case 'confirm':
      return 'bg-blue-600 text-white hover:bg-blue-700'
    default:
      return 'bg-blue-600 text-white hover:bg-blue-700'
  }
})

const cancelButtonClass = computed(() => {
  return ''
})

const typeLabel = computed(() => ({
  success: 'نجاح',
  warning: 'تحذير',
  error: 'خطأ',
  info: 'معلومات'
}[props.type] || ''))

const badgeTypeClass = computed(() => {
  switch (props.type) {
    case 'error':
      return 'bg-rose-50 text-rose-600 border-rose-100'
    case 'warning':
      return 'bg-amber-50 text-amber-600 border-amber-100'
    case 'success':
      return 'bg-emerald-50 text-emerald-600 border-emerald-100'
    case 'confirm':
      return 'bg-slate-100 text-slate-700 border-slate-200'
    default:
      return 'bg-blue-50 text-blue-600 border-blue-100'
  }
})

// Handle animation states
watch(() => props.isVisible, (newValue) => {
  if (newValue) {
    animationState.value = 'enter'
    setTimeout(() => {
      animationState.value = 'active'
    }, 30)
  } else {
    animationState.value = 'exit'
  }
})

// Event handlers
const handleConfirm = () => {
  emit('confirm')
  closeAlert()
}

const handleCancel = () => {
  emit('cancel')
  closeAlert()
}

const handleBackdropClick = () => {
  if (props.closeOnBackdrop && !props.persistent) {
    handleCancel()
  }
}

const closeAlert = () => {
  animationState.value = 'exit'
  setTimeout(() => {
    emit('close')
  }, 200)
}

// Keyboard support
const handleKeydown = (event) => {
  if (!props.isVisible) return
  
  if (event.key === 'Escape') {
    handleCancel()
  } else if (event.key === 'Enter' && (props.type === 'confirm' || props.confirmText)) {
    handleConfirm()
  }
}

onMounted(() => {
  document.addEventListener('keydown', handleKeydown)
})

onUnmounted(() => {
  document.removeEventListener('keydown', handleKeydown)
})
</script>

<style scoped>
.transform {
  transition-property: transform, opacity;
  transition-timing-function: cubic-bezier(0.16, 1, 0.3, 1);
}
</style>