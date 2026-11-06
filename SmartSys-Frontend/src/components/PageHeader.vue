<template>
  <header class="flex flex-col md:flex-row md:items-end justify-between gap-6 pb-6 border-b border-slate-200">
    <div class="space-y-1">
      <div class="flex items-center gap-2 text-slate-500 text-xs font-medium uppercase tracking-wider">
        <template v-for="(item, index) in breadcrumbItems" :key="`${item.label}-${index}`">
          <RouterLink v-if="item.path && index < breadcrumbItems.length - 1" :to="item.path" class="hover:text-slate-700 transition-colors">
            {{ item.label }}
          </RouterLink>
          <span v-else :class="index === breadcrumbItems.length - 1 ? 'text-slate-900' : ''">{{ item.label }}</span>
          <i v-if="index < breadcrumbItems.length - 1" class="fas fa-chevron-left text-[8px]"></i>
        </template>
      </div>
      
      <h1 class="text-2xl font-bold tracking-tight text-slate-900" v-html="title"></h1>
      
      <p class="text-sm text-slate-500 font-medium" v-html="description"></p>
    </div>

    <div class="flex flex-wrap items-center gap-3">
      <!-- Branch Selector — يظهر فقط للـ exempt users (branches غير فارغة) -->
      <div v-if="canSelectBranch && branches && branches.length > 0" class="relative">
        <select 
          :value="selectedBranch === null
            ? (branchSelectionMode === 'optional' ? 'all' : '')
            : String(selectedBranch)"
          @change="onSelectChange"
          class="h-9 pr-9 pl-4 rounded-md border border-slate-200 bg-white text-xs font-bold hover:border-slate-300 focus:ring-2 focus:ring-blue-500/10 outline-none transition-all appearance-none cursor-pointer min-w-[180px]"
          :class="isUnselected ? 'text-slate-400' : 'text-slate-700'"
        >
          <option v-if="branchSelectionMode === 'required'" value="" disabled>اختر فرعاً...</option>
          <option v-if="branchSelectionMode === 'optional'" value="all">جميع الفروع</option>
          <option v-for="branch in branches" :key="branch.id" :value="String(branch.id)">
            {{ branch.name }}
          </option>
        </select>
        <i class="fas fa-building absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 text-[10px]"></i>
      </div>

      <div v-if="canSelectBranch && branches && branches.length > 0" class="h-6 w-px bg-slate-200 mx-1 hidden sm:block"></div>

      <slot name="controls"></slot>
    </div>
  </header>
</template>

<script setup>
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import { useBranchIsolation } from '@/composables/useBranchIsolation'
import { useBranchStore } from '@/stores/branch/branchStore'

const props = defineProps({
  breadcrumb: {
    type: Object,
    required: true,
    validator: (value) => Array.isArray(value.items) || ('parent' in value && 'current' in value)
  },
  title: {
    type: String,
    required: true
  },
  description: {
    type: String,
    required: true
  },
  branches: {
    type: Array,
    required: true
  },
  selectedBranch: {
    type: [String, Number, null],
    default: null
  },
  // Kept for compatibility with pages that pass the previous explicit-selection state.
  hasExplicitSelection: {
    type: Boolean,
    default: false
  },
  branchSelectionMode: {
    type: String,
    default: 'optional',
    validator: (value) => ['optional', 'required'].includes(value)
  }
})

const emit = defineEmits(['branch-changed'])
const branchIsolation = useBranchIsolation()
const branchStore = useBranchStore()
const canSelectBranch = computed(() => branchIsolation.isExempt.value)
const breadcrumbItems = computed(() => {
  if (Array.isArray(props.breadcrumb.items)) {
    return props.breadcrumb.items.filter(item => item && item.label)
  }

  const { parent, current } = props.breadcrumb
  return [
    typeof parent === 'object' ? parent : { label: parent },
    typeof current === 'object' ? current : { label: current }
  ].filter(item => item.label)
})

// Optional branch filters default to all branches; only required filters show a placeholder.
const isUnselected = computed(() => {
  return props.branchSelectionMode === 'required'
    && (props.selectedBranch === null || props.selectedBranch === '')
})

const onSelectChange = (e) => {
  const val = e.target.value
  const branchId = val === 'all' || val === '' ? null : val

  if (props.branchSelectionMode === 'required') {
    if (branchId !== null) {
      const current = branchStore.selectedBranchId
      if (String(current) !== String(branchId)) {
        branchStore.setTemporaryBranch(branchId)
      }
    }
  } else {
    const current = branchStore.selectedBranchId
    if (String(current ?? 'all') !== String(branchId ?? 'all')) {
      branchStore.setSelectedBranch(branchId)
    }
  }

  emit('branch-changed', branchId)
}
</script>

<style scoped>
/* Additional custom styles if needed */
</style>
