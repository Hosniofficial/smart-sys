<template>
  <button
    type="button"
    :disabled="isLoading"
    class="loading-button inline-flex items-center justify-center gap-2 border-none cursor-pointer transition-all whitespace-nowrap font-bold uppercase"
    :class="[
      `loading-button--${variant}`,
      { 'loading-button--loading': isLoading }
    ]"
    v-bind="$attrs"
  >
    <!-- Loading state -->
    <div v-if="isLoading" class="loading-button__loader">
      <!-- Option 1: Dots Animation (Default) -->
      <div v-if="loaderType === 'dots'" class="dots-loader">
        <span class="dot"></span>
        <span class="dot"></span>
        <span class="dot"></span>
      </div>

      <!-- Option 2: Linear Progress -->
      <div v-else-if="loaderType === 'line'" class="line-loader"></div>

      <!-- Option 3: Shimmer -->
      <div v-else-if="loaderType === 'shimmer'" class="shimmer-loader"></div>
    </div>

    <!-- Text or Icon -->
    <span v-if="!isLoading || showTextDuringLoad" class="loading-button__text">
      <i v-if="icon && !isLoading" :class="icon" class="loading-button__icon text-[10px]"></i>
      {{ isLoading ? loadingText : text }}
    </span>
  </button>
</template>

<script setup>
defineProps({
  text: {
    type: String,
    required: true
  },
  isLoading: {
    type: Boolean,
    default: false
  },
  loadingText: {
    type: String,
    default: 'جاري...'
  },
  icon: {
    type: String,
    default: null // e.g., 'fas fa-save'
  },
  variant: {
    type: String,
    default: 'primary', // primary, secondary, danger
    validator: (v) => ['primary', 'secondary', 'danger'].includes(v)
  },
  loaderType: {
    type: String,
    default: 'dots', // dots, line, shimmer
    validator: (v) => ['dots', 'line', 'shimmer'].includes(v)
  },
  showTextDuringLoad: {
    type: Boolean,
    default: true
  }
})
</script>

<style scoped>
.loading-button {
  position: relative;
  border-radius: 0.375rem;
  font-size: 0.6875rem;
  letter-spacing: 0.0625rem;
}

/* ═══════════════════════════════════════════════════════════ */
/* VARIANTS */
/* ═══════════════════════════════════════════════════════════ */

.loading-button--primary {
  background-color: #1f2937;
  color: #fff;
  box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
}

.loading-button--primary:hover:not(:disabled) {
  background-color: #111827;
  box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.15);
}

.loading-button--primary:active:not(:disabled) {
  transform: scale(0.95);
}

.loading-button--secondary {
  background-color: #f3f4f6;
  color: #374151;
  border: 1px solid #d1d5db;
}

.loading-button--secondary:hover:not(:disabled) {
  background-color: #e5e7eb;
  border-color: #9ca3af;
}

.loading-button--danger {
  background-color: #ef4444;
  color: #fff;
}

.loading-button--danger:hover:not(:disabled) {
  background-color: #dc2626;
}

/* ═══════════════════════════════════════════════════════════ */
/* DISABLED STATE */
/* ═══════════════════════════════════════════════════════════ */

.loading-button:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

/* ═══════════════════════════════════════════════════════════ */
/* LOADING STATE */
/* ═══════════════════════════════════════════════════════════ */

.loading-button--loading {
  pointer-events: none;
}

.loading-button__loader {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.25rem;
  height: 1rem;
}

.loading-button__text {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  font-size: inherit;
}

.loading-button__icon {
  font-size: 0.625rem;
}

/* ═══════════════════════════════════════════════════════════ */
/* LOADERS: DOTS (Default) */
/* ═══════════════════════════════════════════════════════════ */

.dots-loader {
  display: inline-flex;
  gap: 0.25rem;
  align-items: center;
}

.dots-loader .dot {
  width: 0.375rem;
  height: 0.375rem;
  border-radius: 50%;
  background-color: currentColor;
  animation: dot-pulse 1.4s ease-in-out infinite;
}

.dots-loader .dot:nth-child(1) {
  animation-delay: 0s;
}

.dots-loader .dot:nth-child(2) {
  animation-delay: 0.2s;
}

.dots-loader .dot:nth-child(3) {
  animation-delay: 0.4s;
}

@keyframes dot-pulse {
  0%, 100% {
    opacity: 0.3;
    transform: scale(0.8);
  }
  50% {
    opacity: 1;
    transform: scale(1);
  }
}

/* ═══════════════════════════════════════════════════════════ */
/* LOADERS: LINE */
/* ═══════════════════════════════════════════════════════════ */

.line-loader {
  width: 1rem;
  height: 0.1875rem;
  background: linear-gradient(90deg, currentColor 0%, transparent 100%);
  border-radius: 0.0625rem;
  overflow: hidden;
  position: relative;
}

.line-loader::after {
  content: '';
  position: absolute;
  inset: 0;
  background: linear-gradient(90deg, transparent 0%, currentColor 50%, transparent 100%);
  animation: line-slide 1.2s ease-in-out infinite;
}

@keyframes line-slide {
  0% {
    transform: translateX(-100%);
  }
  100% {
    transform: translateX(100%);
  }
}

/* ═══════════════════════════════════════════════════════════ */
/* LOADERS: SHIMMER */
/* ═══════════════════════════════════════════════════════════ */

.shimmer-loader {
  width: 1.5rem;
  height: 1rem;
  background: linear-gradient(
    90deg,
    currentColor 0%,
    rgba(255, 255, 255, 0.3) 50%,
    currentColor 100%
  );
  background-size: 200% 100%;
  border-radius: 0.25rem;
  animation: shimmer 1.5s ease-in-out infinite;
}

@keyframes shimmer {
  0% {
    background-position: 200% 0;
  }
  100% {
    background-position: -200% 0;
  }
}
</style>
