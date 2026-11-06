<template>
  <!-- 
    BaseModal Component
    ==================
    مكون مشترك لجميع النوافذ المنبثقة في النظام
    يحل مشكلة التداخل مع الشريط العلوي ويوحد السلوك
    
    استخدام:
    <BaseModal :show="showModal" @close="showModal = false">
      <template #header>
        <h3>عنوان النافذة</h3>
      </template>
      <template #default>
        محتوى النافذة
      </template>
      <template #footer>
        أزرار التحكم
      </template>
    </BaseModal>
  -->
  <Teleport to="body">
    <Transition name="modal-fade">
      <div 
        v-if="show" 
        :class="overlayClass"
        :style="overlayStyle"
        @click.self="closeOnClickOutside && $emit('close')"
      >
        <div :class="contentClass">
          <div v-if="$slots.header || showCloseButton" :class="headerClass">
            <slot name="header"></slot>
            <button 
              v-if="showCloseButton"
              @click="$emit('close')" 
              class="absolute top-4 left-4 w-8 h-8 rounded-lg text-slate-400 hover:text-red-500 hover:bg-red-50 flex items-center justify-center transition-all active:scale-95"
              aria-label="إغلاق"
            >
              <i class="fas fa-times text-sm"></i>
            </button>
          </div>

          <div :class="bodyClass">
            <slot></slot>
          </div>

          <div v-if="$slots.footer" :class="footerClass">
            <slot name="footer"></slot>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
import { computed, watch, onMounted, onBeforeUnmount } from 'vue'

// ✅ تعطيل الـ automatic attribute inheritance لأن المكون يستخدم Teleport
defineOptions({
  inheritAttrs: false
})

const props = defineProps({
  show: {
    type: Boolean,
    default: false
  },
  maxWidth: {
    type: String,
    default: '2xl' // sm, md, lg, xl, 2xl, 3xl, 4xl, 5xl, 6xl, full
  },
  closeOnClickOutside: {
    type: Boolean,
    default: true
  },
  closeOnEscape: {
    type: Boolean,
    default: true
  },
  showCloseButton: {
    type: Boolean,
    default: true
  },
  // Z-index للـ modal - افتراضي 120 لتجنب التداخل مع Header (z-40) و Mobile Menu (z-50)
  zIndex: {
    type: Number,
    default: 120
  },
  // نوع التصميم: modern (rounded-3xl) أو standard (rounded-xl)
  variant: {
    type: String,
    default: 'standard', // modern, standard
    validator: (value) => ['modern', 'standard'].includes(value)
  },
  // محاذاة المحتوى: start (أعلى تحت Header), center (منتصف), end (أسفل)
  align: {
    type: String,
    default: 'start',
    validator: (value) => ['start', 'center', 'end'].includes(value)
  }
})

const emit = defineEmits(['close'])

// حساب classes ديناميكياً
const overlayClass = computed(() => [
  'fixed inset-0 bg-slate-900/60 backdrop-blur-sm flex p-4',
  // محاذاة المحتوى - افتراضياً يبدأ أسفل Header
  props.align === 'start' ? 'items-start pt-20 md:pt-24' : '',
  props.align === 'center' ? 'items-center' : '',
  props.align === 'end' ? 'items-end pb-8' : '',
  'justify-center'
])

const overlayStyle = computed(() => ({
  zIndex: props.zIndex
}))

const contentClass = computed(() => [
  'bg-white w-full shadow-2xl overflow-hidden border border-slate-200 flex flex-col animate-modal-in',
  // Max Width
  props.maxWidth === 'sm' ? 'max-w-sm' : '',
  props.maxWidth === 'md' ? 'max-w-md' : '',
  props.maxWidth === 'lg' ? 'max-w-lg' : '',
  props.maxWidth === 'xl' ? 'max-w-xl' : '',
  props.maxWidth === '2xl' ? 'max-w-2xl' : '',
  props.maxWidth === '3xl' ? 'max-w-3xl' : '',
  props.maxWidth === '4xl' ? 'max-w-4xl' : '',
  props.maxWidth === '5xl' ? 'max-w-5xl' : '',
  props.maxWidth === '6xl' ? 'max-w-6xl' : '',
  props.maxWidth === 'full' ? 'max-w-full' : '',
  // Variant
  props.variant === 'modern' ? 'rounded-[2.5rem]' : 'rounded-xl',
  // Max Height - يترك مساحة للـ Header والـ padding
  props.align === 'start' ? 'max-h-[calc(100vh-6rem)] md:max-h-[calc(100vh-7rem)]' : 'max-h-[90vh]'
])

const headerClass = computed(() => [
  'px-6 md:px-8 py-4 md:py-5 border-b border-slate-100 bg-slate-50/50 flex items-center justify-between relative'
])

const bodyClass = computed(() => [
  'flex-1 overflow-y-auto p-6 md:p-8'
])

const footerClass = computed(() => [
  'px-6 md:px-8 py-4 border-t border-slate-100 bg-slate-50/30 flex items-center justify-end gap-3'
])

// Handle ESC key
const handleEscape = (e) => {
  if (props.show && props.closeOnEscape && e.key === 'Escape') {
    emit('close')
  }
}

// منع التمرير في الخلفية عند فتح Modal
watch(() => props.show, (newVal) => {
  if (newVal) {
    document.body.style.overflow = 'hidden'
  } else {
    document.body.style.overflow = ''
  }
})

onMounted(() => {
  document.addEventListener('keydown', handleEscape)
})

onBeforeUnmount(() => {
  document.removeEventListener('keydown', handleEscape)
  document.body.style.overflow = ''
})
</script>

<style scoped>
/* Modal Transitions */
.modal-fade-enter-active,
.modal-fade-leave-active {
  transition: opacity 0.3s ease;
}

.modal-fade-enter-from,
.modal-fade-leave-to {
  opacity: 0;
}

/* Modal Animation */
.animate-modal-in {
  animation: modal-in 0.3s cubic-bezier(0.16, 1, 0.3, 1);
}

@keyframes modal-in {
  from {
    opacity: 0;
    transform: scale(0.95) translateY(20px);
  }
  to {
    opacity: 1;
    transform: scale(1) translateY(0);
  }
}

/* تخصيص scrollbar للمحتوى */
.overflow-y-auto::-webkit-scrollbar {
  width: 8px;
}

.overflow-y-auto::-webkit-scrollbar-track {
  background: #f1f5f9;
  border-radius: 10px;
}

.overflow-y-auto::-webkit-scrollbar-thumb {
  background: #cbd5e1;
  border-radius: 10px;
}

.overflow-y-auto::-webkit-scrollbar-thumb:hover {
  background: #94a3b8;
}
</style>
