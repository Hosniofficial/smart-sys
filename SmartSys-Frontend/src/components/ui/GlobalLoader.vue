<template>
  <transition name="fade">
    <LoadingProgressBar
      v-if="isLoading"
      label="جاري تحميل البيانات"
      global
    />
  </transition>
</template>

<script setup>
import { computed } from 'vue'
import { useUiStore } from '@/stores/ui/uiStore'
import LoadingProgressBar from '@/components/ui/LoadingProgressBar.vue'

const ui = useUiStore()
const isLoading = computed(() => ui.loadingCount > 0)
</script>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity .15s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
