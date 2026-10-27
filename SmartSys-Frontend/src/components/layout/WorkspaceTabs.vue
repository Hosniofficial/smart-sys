<template>
  <nav
    v-if="tabs.length"
    class="sticky top-0 z-20 flex h-11 items-center gap-1 overflow-x-auto border-b border-slate-200 bg-white/95 px-3 backdrop-blur"
    aria-label="تبويبات السجلات المفتوحة"
  >
    <div class="flex min-w-max items-center gap-1">
      <div
        v-for="tab in tabs"
        :key="tab.path"
        class="group flex h-8 max-w-56 items-center rounded-md border transition-colors"
        :class="isCurrent(tab) ? 'border-blue-200 bg-blue-50 text-blue-700' : 'border-transparent text-slate-600 hover:bg-slate-100'"
      >
        <button
          type="button"
          :aria-current="isCurrent(tab) ? 'page' : undefined"
          :title="tab.title"
          class="min-w-0 truncate px-3 text-right text-[11px] font-bold"
          @click="activateTab(tab)"
        >
          {{ tab.title }}
        </button>
        <button
          type="button"
          class="ml-1 mr-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded text-slate-400 transition-colors hover:bg-white hover:text-rose-600"
          :aria-label="`إغلاق ${tab.title}`"
          :title="`إغلاق ${tab.title}`"
          @click="closeTab(tab)"
        >
          <X :size="13" />
        </button>
      </div>
    </div>
    <span class="mr-auto shrink-0 px-2 text-[9px] font-medium text-slate-400">
      {{ tabs.length }}/{{ MAX_TABS }}
    </span>
  </nav>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { X } from 'lucide-vue-next'
import { useAuthStore } from '@/stores/auth/authStore'
import { useToast } from '@/composables/useToast'

const MAX_TABS = 8
const STORAGE_PREFIX = 'smartsys_workspace_tabs_v1'

const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()
const { showToast } = useToast()
const tabs = ref([])

const storageKey = computed(() => {
  const userId = authStore.user?.id ?? authStore.user?.user_id
  return userId ? `${STORAGE_PREFIX}_${userId}` : null
})

function isPositiveId(value) {
  return /^\d+$/.test(String(value || '')) && Number(value) > 0
}

function buildTab(routeLike) {
  const kind = routeLike.meta?.workspaceTab
  const recordId = kind === 'branch' || kind === 'contact'
    ? routeLike.params?.id
    : routeLike.query?.id

  if (!isPositiveId(recordId)) return null

  let title
  let fallbackPath
  let recordKey
  if (kind === 'branch') {
    title = `الفرع #${recordId}`
    fallbackPath = '/branches'
    recordKey = `branch:${recordId}`
  } else if (kind === 'contact') {
    if (!['customers', 'suppliers'].includes(routeLike.params?.type)) return null
    const isSupplier = routeLike.params?.type === 'suppliers'
    title = `${isSupplier ? 'المورد' : 'العميل'} #${recordId}`
    fallbackPath = isSupplier ? '/suppliers' : '/customers'
    recordKey = `contact:${routeLike.params.type}:${recordId}`
  } else if (kind === 'sale') {
    title = `فاتورة بيع #${recordId}`
    fallbackPath = '/sales/history'
    recordKey = `sale:${recordId}`
  } else if (kind === 'purchase') {
    title = `فاتورة شراء #${recordId}`
    fallbackPath = routeLike.path === '/purchases' ? '/purchases' : '/purchases/history'
    recordKey = `purchase:${recordId}`
  } else if (kind === 'return') {
    title = `مرتجع ${routeLike.query?.type === 'purchases' ? 'شراء' : 'بيع'} #${recordId}`
    fallbackPath = '/returns/history'
    recordKey = `return:${routeLike.query?.type === 'purchases' ? 'purchases' : 'sales'}:${recordId}`
  } else if (kind === 'voucher') {
    title = `سند #${recordId}`
    fallbackPath = '/vouchers'
    recordKey = `voucher:${recordId}`
  } else if (kind === 'warranty') {
    title = `طلب ضمان #${recordId}`
    fallbackPath = '/warranty'
    recordKey = `warranty:${recordId}`
  } else {
    return null
  }

  return {
    recordKey,
    path: routeLike.fullPath,
    title,
    fallbackPath
  }
}

function isCurrent(tab) {
  return tab.path === route.fullPath
}

function persistTabs() {
  if (!storageKey.value) return
  try {
    sessionStorage.setItem(storageKey.value, JSON.stringify(tabs.value))
  } catch (error) {
    showToast('تعذر حفظ تبويبات السجلات في هذه الجلسة.', 'warning')
  }
}

function restoreTabs() {
  if (!storageKey.value) return
  try {
    const savedTabs = JSON.parse(sessionStorage.getItem(storageKey.value) || '[]')
    if (!Array.isArray(savedTabs)) throw new Error('Invalid workspace tabs')

    const restoredTabs = []
    for (const savedTab of savedTabs.slice(-MAX_TABS)) {
      if (typeof savedTab?.path !== 'string') continue
      const resolvedRoute = router.resolve(savedTab.path)
      const tab = buildTab(resolvedRoute)
      if (tab && !restoredTabs.some(existing => existing.recordKey === tab.recordKey)) {
        restoredTabs.push(tab)
      }
    }
    tabs.value = restoredTabs
  } catch (error) {
    try {
      sessionStorage.removeItem(storageKey.value)
    } catch {
      tabs.value = []
    }
    showToast('تعذر استعادة تبويبات السجلات السابقة.', 'warning')
  }
}

function registerCurrentRoute() {
  const tab = buildTab(route)
  if (!tab) return

  const existingIndex = tabs.value.findIndex(item => item.recordKey === tab.recordKey)
  if (existingIndex >= 0) {
    tabs.value.splice(existingIndex, 1, tab)
    return
  }

  if (tabs.value.length >= MAX_TABS) tabs.value.shift()
  tabs.value.push(tab)
}

function activateTab(tab) {
  if (!isCurrent(tab)) router.push(tab.path)
}

async function closeTab(tab) {
  const index = tabs.value.findIndex(item => item.path === tab.path)
  if (index < 0) return

  const wasCurrent = isCurrent(tab)
  tabs.value.splice(index, 1)
  if (!wasCurrent) return

  const nextTab = tabs.value[Math.min(index, tabs.value.length - 1)]
  await router.push(nextTab?.path || tab.fallbackPath)
}

onMounted(() => {
  restoreTabs()
  registerCurrentRoute()
})

watch(() => route.fullPath, registerCurrentRoute)
watch(tabs, persistTabs, { deep: true })
watch(storageKey, (newKey, oldKey) => {
  if (newKey === oldKey) return
  tabs.value = []
  restoreTabs()
  registerCurrentRoute()
}, { flush: 'post' })
</script>
