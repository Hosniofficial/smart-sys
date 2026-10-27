<template>
  <div
    class="loading-progress-bar fixed inset-x-0 top-0 h-1 overflow-hidden bg-blue-100/70"
    :class="global ? 'z-[10000]' : 'z-[110]'"
    role="progressbar"
    :aria-label="label"
    aria-valuetext="جاري التحميل"
  >
    <div class="loading-progress-bar__progress h-full w-1/3 bg-blue-600"></div>
  </div>
</template>

<script setup>
defineProps({
  label: {
    type: String,
    default: 'جارٍ التحميل'
  },
  global: {
    type: Boolean,
    default: false
  }
})
</script>

<style scoped>
.loading-progress-bar { pointer-events: none; }
.loading-progress-bar__progress { animation: loading-progress 1.2s ease-in-out infinite; }
@keyframes loading-progress {
  0% { transform: translateX(300%); }
  100% { transform: translateX(-300%); }
}
@media (prefers-reduced-motion: reduce) {
  .loading-progress-bar__progress { animation: none; transform: translateX(0); }
}
</style>
