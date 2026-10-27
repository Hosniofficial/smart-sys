<template>
  <LoadingProgressBar v-if="isVisible" label="جاري تحميل الصفحة" />
</template>

<script setup>
import { computed } from 'vue'
import { useUiStore } from '@/stores/ui/uiStore'
import LoadingProgressBar from '@/components/ui/LoadingProgressBar.vue'

const props = defineProps({
  active: {
    type: [Boolean, Number, String],
    default: false
  }
})

const ui = useUiStore()
const isVisible = computed(() => Boolean(props.active) && ui.loadingCount === 0)
</script>
