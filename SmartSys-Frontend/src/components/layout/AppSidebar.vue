<template>
  <aside
    class="fixed top-14 md:top-16 right-0 h-[calc(100vh-3.5rem)] md:h-[calc(100vh-4rem)] z-50 bg-white text-slate-900 flex flex-col transition-all duration-200 border-l border-slate-200 shadow-sm hidden md:flex"
    :style="{ width: collapsed ? '72px' : '256px' }"
    :aria-label="'التنقل الرئيسي'"
  >

    <nav
      class="flex-1 overflow-y-auto custom-scroll px-2 py-4 space-y-1"
      :aria-label="'قائمة التنقل'"
    >
      <template v-for="item in menuItems" :key="item.key || item.path">
        <router-link
          v-if="!item.items"
          :to="item.path"
          :title="!collapsed ? '' : item.name"
          class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-bold transition-all"
          :class="[
            isActive(item)
              ? 'bg-blue-600 text-white shadow-lg'
              : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'
          ]"
          @click="$emit('navigate', item)"
        >
          <component :is="item.icon" :size="18" class="shrink-0" />
          <span v-if="!collapsed" class="truncate">{{ item.name }}</span>
        </router-link>

        <button
          v-else
          :title="!collapsed ? '' : item.name"
          class="w-full flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-bold transition-all"
          :class="[
            isActive(item)
              ? 'bg-slate-100 text-blue-600'
              : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'
          ]"
          :aria-expanded="activeSection?.key === item.key"
          @click="$emit('section', item)"
        >
          <component :is="item.icon" :size="18" class="shrink-0" />
          <span v-if="!collapsed" class="flex-1 text-right truncate">{{ item.name }}</span>
          <ChevronDown
            v-if="!collapsed"
            :size="14"
            class="shrink-0 transition-transform duration-200"
            :class="{ 'rotate-180': activeSection?.key === item.key }"
          />
        </button>
      </template>
    </nav>

    <div class="border-t border-slate-200 p-2 shrink-0">
      <button
        class="w-full flex items-center justify-center h-10 rounded-lg text-slate-400 hover:text-blue-600 hover:bg-slate-100 transition-all"
        :title="collapsed ? 'توسيع' : 'طي'"
        @click="$emit('toggle')"
      >
        <ChevronRight v-if="collapsed" :size="18" />
        <ChevronLeft v-else :size="18" />
      </button>
    </div>
  </aside>
</template>

<script setup>
import { computed } from 'vue'
import { Building, ChevronDown, ChevronLeft, ChevronRight } from 'lucide-vue-next'
import { useActiveRoute } from '@/composables/useActiveRoute'

defineProps({
  menuItems: {
    type: Array,
    required: true
  },
  collapsed: {
    type: Boolean,
    default: true
  },
  activeSection: {
    type: Object,
    default: null
  }
})

defineEmits(['section', 'navigate', 'toggle'])

const { isActive } = useActiveRoute()
</script>

<style scoped>
.custom-scroll::-webkit-scrollbar {
  width: 4px;
}

.custom-scroll::-webkit-scrollbar-track {
  background: transparent;
}

.custom-scroll::-webkit-scrollbar-thumb {
  background: rgba(51, 65, 85, 0.3);
  border-radius: 999px;
}

.custom-scroll::-webkit-scrollbar-thumb:hover {
  background: rgba(51, 65, 85, 0.5);
}
</style>
