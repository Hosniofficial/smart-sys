<template>
  <main
    class="flex min-h-screen items-center justify-center bg-[#fafafa] p-6 font-sans text-slate-900 antialiased"
    :class="tone === 'danger' ? 'selection:bg-rose-100' : 'selection:bg-blue-100'"
    dir="rtl"
  >
    <div
      class="fixed left-0 right-0 top-0 z-50 h-0.5"
      :class="tone === 'danger' ? 'bg-rose-600/10' : 'bg-slate-900/10'"
      aria-hidden="true"
    >
      <div
        class="h-full w-full"
        :class="tone === 'danger' ? 'bg-rose-600/40' : 'bg-slate-900/30'"
      ></div>
    </div>

    <section class="relative w-full max-w-md animate-fadeIn rounded-xl border border-slate-200 bg-white p-8 text-center shadow-sm sm:p-10">
      <div class="mb-6 space-y-4">
        <div
          class="mx-auto flex h-12 w-12 items-center justify-center rounded-lg border shadow-sm"
          :class="tone === 'danger'
            ? 'border-rose-100 bg-rose-50 text-rose-600'
            : 'border-slate-200 bg-slate-50 text-slate-700'"
        >
          <slot name="icon"></slot>
        </div>
        <div>
          <span
            class="inline-flex items-center gap-1.5 rounded border px-2.5 py-0.5 font-mono text-[10px] font-bold tracking-wider"
            :class="tone === 'danger'
              ? 'border-rose-100 bg-rose-50 text-rose-600'
              : 'border-rose-100 bg-rose-50 text-rose-600'"
          >
            <slot name="status"></slot>
          </span>
        </div>
      </div>

      <div class="mb-8 space-y-2">
        <h1 class="text-base font-bold tracking-tight text-slate-900 sm:text-lg">
          <slot name="title"></slot>
        </h1>
        <p class="mx-auto max-w-xs text-xs font-medium leading-relaxed text-slate-500">
          <slot name="description"></slot>
        </p>
      </div>

      <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <slot name="actions"></slot>
      </div>

      <footer class="mt-8 flex items-center justify-center gap-2 border-t border-slate-100 pt-6 font-mono text-[10px] font-bold uppercase tracking-widest text-slate-400">
        <slot name="footer"></slot>
      </footer>
    </section>
  </main>
</template>

<script setup>
defineProps({
  tone: {
    type: String,
    default: 'neutral',
    validator: (value) => ['neutral', 'danger'].includes(value),
  },
})
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
