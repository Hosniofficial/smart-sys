<template>
  <nav class="flex items-center gap-1" aria-label="التنقل بين الصفحات">
    <button
      type="button"
      :disabled="page <= 1"
      aria-label="الصفحة السابقة"
      class="flex h-8 w-8 items-center justify-center rounded border border-slate-200 bg-white text-slate-500 transition-all hover:border-blue-200 hover:text-blue-600 disabled:cursor-not-allowed disabled:opacity-40"
      @click="$emit('previous')"
    >
      <i class="fas fa-chevron-right" aria-hidden="true"></i>
    </button>
    <span
      v-if="showPageIndicator"
      class="rounded border border-slate-200 bg-white px-3 py-1 font-mono text-[10px] font-bold text-slate-700"
      aria-live="polite"
    >
      {{ page }} / {{ totalPages }}
    </span>
    <button
      type="button"
      :disabled="page >= totalPages"
      aria-label="الصفحة التالية"
      class="flex h-8 w-8 items-center justify-center rounded border border-slate-200 bg-white text-slate-500 transition-all hover:border-blue-200 hover:text-blue-600 disabled:cursor-not-allowed disabled:opacity-40"
      @click="$emit('next')"
    >
      <i class="fas fa-chevron-left" aria-hidden="true"></i>
    </button>
  </nav>
</template>

<script setup>
defineProps({
  page: { type: Number, required: true },
  totalPages: { type: Number, required: true },
  showPageIndicator: { type: Boolean, default: false },
})

defineEmits(['previous', 'next'])
</script>
