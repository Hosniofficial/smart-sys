<template>
  <BaseModal
    :show="show"
    @close="$emit('close')"
    :maxWidth="maxWidth"
    variant="standard"
  >
    <template #header>
      <div class="flex min-w-0 items-center gap-3">
        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-indigo-600 text-sm text-white shadow-sm">
          <i :class="icon"></i>
        </div>
        <div class="min-w-0">
          <h3 class="truncate text-sm font-bold text-slate-900">{{ title }}</h3>
          <p v-if="subtitle" class="mt-1 text-[10px] font-medium text-slate-500">{{ subtitle }}</p>
        </div>
      </div>
    </template>

    <slot />

    <template v-if="$slots.footer" #footer>
      <slot name="footer" />
    </template>
  </BaseModal>
</template>

<script setup>
import BaseModal from '@/components/BaseModal.vue'

defineProps({
  show: { type: Boolean, default: false },
  title: { type: String, required: true },
  subtitle: { type: String, default: '' },
  icon: { type: String, default: 'fas fa-file-invoice' },
  maxWidth: { type: String, default: '6xl' },
})

defineEmits(['close'])
</script>
