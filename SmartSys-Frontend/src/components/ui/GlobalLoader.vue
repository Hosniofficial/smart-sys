<template>
  <transition name="fade">
    <div v-if="isLoading" class="fixed inset-0 bg-black bg-opacity-40 flex items-center justify-center z-[10000]">
      <BaseSpinner :size="64" color="#3b82f6" />
    </div>
  </transition>
</template>

<script setup>
import { computed } from 'vue'
import { useUiStore } from '@/stores/ui/uiStore'
import BaseSpinner from './BaseSpinner.vue'

const ui = useUiStore()
// Temporarily disable the global loading overlay because pages are slow to load.
// To re-enable, restore the original computed above.
const isLoading = computed(() => false)
</script>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity .15s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
