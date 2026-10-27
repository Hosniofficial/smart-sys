<template>
  <div class="flex flex-col h-screen bg-white overflow-hidden text-slate-900 antialiased" dir="rtl">

    <!-- Top Header - Full Width -->
    <header
      class="h-14 md:h-16 bg-white border-b border-slate-200 sticky top-0 z-40 shrink-0 px-2 sm:px-4 lg:px-6 transition-all duration-200 w-full overflow-visible"
    >
      <div class="h-full max-w-full mx-auto flex justify-between items-center">

        <!-- Right side: Branding + Mobile Toggle -->
        <div class="flex items-center gap-2 sm:gap-3 min-w-0">
          <!-- Mobile Menu Toggle -->
          <button
            @click="mobileMenuOpen = true"
            class="md:hidden w-8 h-8 flex items-center justify-center text-slate-500 hover:bg-slate-50 rounded-md transition-colors shrink-0"
            aria-label="فتح القائمة"
          >
            <Menu :size="20" class="shrink-0" />
          </button>

          <!-- Branding -->
          <div
            class="flex items-center group cursor-pointer shrink-0"
            @click="router.push('/cashier')"
          >
            <img
              src="/favicons/banner-smartsys.png"
              alt="SmartSys ERP"
              class="w-24 h-12 sm:w-28 md:w-32 md:h-14 object-contain transition-transform group-hover:scale-[1.02] shrink-0"
            />
          </div>
        </div>

        <!-- Left side: Notification & Profile -->
        <div class="flex items-center gap-1 sm:gap-2 md:gap-3 shrink-0">

          <!-- Notifications -->
          <div class="relative" ref="notificationsRef">
            <button
              @click="toggleNotifications"
              class="w-8 h-8 sm:w-9 sm:h-9 flex items-center justify-center text-slate-500 hover:text-slate-900 hover:bg-slate-50 rounded-md transition-all relative shrink-0"
              :class="{ '!text-red-600': unreadCount > 0 }"
              :aria-expanded="showNotifications"
              aria-haspopup="true"
              aria-label="الإشعارات"
            >
              <Bell :size="16" class="sm:hidden" :class="{ 'animate-bounce': unreadCount > 0 }" />
              <Bell :size="18" class="hidden sm:block" :class="{ 'animate-bounce': unreadCount > 0 }" />
              <span
                v-if="unreadCount > 0"
                class="absolute -top-0.5 -right-0.5 sm:-top-1 sm:-right-1 min-w-[14px] sm:min-w-[16px] h-3.5 sm:h-4 px-0.5 flex items-center justify-center text-[8px] sm:text-[9px] font-black text-white bg-gradient-to-br from-red-500 to-rose-600 rounded-full border border-white shadow-sm"
              >
                {{ unreadCount > 99 ? '99+' : unreadCount }}
              </span>
            </button>

            <!-- Notifications Panel -->
            <Teleport to="body">
              <Transition name="dropdown-v2">
                <div
                  v-if="showNotifications"
                  class="fixed top-[58px] md:top-[66px] w-[calc(100vw-16px)] sm:w-80 md:w-96 max-h-[calc(100vh-70px)] md:max-h-96 bg-white border border-slate-200 rounded-lg shadow-2xl z-[200] overflow-hidden"
                  :style="{ 
                    right: notificationsPosition, 
                    left: 'auto',
                    maxWidth: 'calc(100vw - 32px)'
                  }"
                >
                <div class="px-3 sm:px-4 py-2 sm:py-3 border-b border-slate-100 flex justify-between items-center bg-slate-50/50 sticky top-0 z-10">
                  <span class="text-[9px] sm:text-[10px] font-bold text-slate-500 uppercase tracking-widest">الإشعارات</span>
                  <button @click="markAllAsRead" class="text-[9px] sm:text-[10px] font-bold text-blue-600 hover:underline">تمييز الكل</button>
                </div>

                <div class="max-h-[calc(100vh-140px)] sm:max-h-80 overflow-y-auto custom-scroll">
                  <div v-if="loadingNotifications" class="p-6 text-center"><BaseSpinner :size="16" /></div>
                  <div v-else-if="notifications.length === 0" class="p-6 sm:p-8 text-center text-slate-400">
                    <p class="text-[10px] sm:text-[11px] font-medium">لا توجد إشعارات حالياً</p>
                  </div>
                  <div v-else class="divide-y divide-slate-50">
                    <div
                      v-for="note in notifications"
                      :key="note.id"
                      class="p-3 sm:p-4 hover:bg-slate-50 transition-colors group flex items-start justify-between gap-2 sm:gap-3"
                    >
                      <div class="flex items-start gap-2 sm:gap-3 flex-1 min-w-0 cursor-pointer" @click="markNotificationAsRead(note)">
                        <div v-if="!note.is_read" class="w-1 h-1 sm:w-1.5 sm:h-1.5 rounded-full bg-blue-600 mt-1.5 shrink-0"></div>
                        <div class="flex-1 min-w-0">
                          <div class="flex items-center gap-1.5 sm:gap-2 mb-0.5">
                            <span v-if="note.icon" class="text-xs sm:text-sm">{{ note.icon }}</span>
                            <p class="text-[10px] sm:text-xs font-bold text-slate-900 truncate">{{ note.title }}</p>
                          </div>
                          <p class="text-[9px] sm:text-[10px] text-slate-500 leading-normal line-clamp-2">{{ note.message }}</p>

                          <div v-if="note.action" class="mt-1.5 sm:mt-2 pt-1 sm:pt-1.5 border-t border-slate-100">
                            <router-link
                              v-if="note.action.path"
                              :to="note.action.path"
                              class="text-[9px] sm:text-[10px] font-bold text-blue-600 hover:text-blue-700 transition-all inline-flex items-center gap-1"
                              @click.stop
                            >
                              {{ note.action.label }}
                              <span class="text-[7px] sm:text-[8px]">→</span>
                            </router-link>
                          </div>

                          <p class="text-[8px] sm:text-[9px] text-slate-400 mt-1 sm:mt-1.5 font-mono">{{ formatRelativeTime(note.created_at) }}</p>
                        </div>
                      </div>
                      <button
                        v-if="note.dismissible !== false"
                        @click.stop="markNotificationAsRead(note)"
                        class="text-slate-300 hover:text-slate-500 hover:bg-slate-100 p-0.5 sm:p-1 rounded transition-all opacity-0 group-hover:opacity-100 shrink-0"
                        title="إغلاق الإشعار"
                      >
                        <X :size="10" class="sm:hidden" />
                        <X :size="12" class="hidden sm:block" />
                      </button>
                    </div>
                  </div>
                </div>
              </div>
            </Transition>
            </Teleport>
          </div>

          <div class="h-6 w-px bg-slate-200 mx-0.5 sm:mx-1 hidden md:block"></div>

          <!-- Profile Menu -->
          <div class="relative" ref="profileRef">
            <button
              @click="showProfileMenu = !showProfileMenu"
              class="flex items-center gap-1.5 sm:gap-2 md:gap-3 p-0.5 sm:p-1 rounded-md hover:bg-slate-50 transition-all"
              :aria-expanded="showProfileMenu"
              aria-haspopup="true"
              aria-label="قائمة الحساب"
            >
              <div class="w-7 h-7 sm:w-8 sm:h-8 rounded bg-slate-100 flex items-center justify-center text-slate-600 border border-slate-200 shrink-0">
                <User :size="14" class="sm:hidden" />
                <User :size="16" class="hidden sm:block" />
              </div>
              <div class="hidden md:block text-right min-w-0">
                <p class="text-[10px] md:text-[11px] font-bold text-slate-900 leading-none truncate max-w-[120px]">{{ authStore.user?.name }}</p>
                <p class="text-[8px] md:text-[9px] font-medium text-slate-400 mt-0.5 md:mt-1">{{ userRoleDisplay }}</p>
              </div>
              <ChevronDown
                :size="10"
                class="text-slate-300 hidden sm:block transition-transform shrink-0"
                :class="{ 'rotate-180': showProfileMenu }"
              />
            </button>

            <Teleport to="body">
              <Transition name="dropdown-v2">
                <div
                  v-if="showProfileMenu"
                  class="fixed top-[58px] md:top-[66px] w-52 bg-white border border-slate-200 rounded-lg shadow-xl py-1.5 z-[200]"
                  :style="{ 
                    right: profilePosition, 
                    left: 'auto',
                    maxWidth: 'calc(100vw - 32px)'
                  }"
                >
                <div class="px-4 py-2.5 border-b border-slate-100 mb-1">
                  <p class="text-[11px] font-bold text-slate-900 truncate">{{ authStore.user?.name }}</p>
                  <p class="text-[9px] text-slate-400 mt-0.5">{{ userRoleDisplay }}</p>
                </div>
                <router-link to="/profile" class="flex items-center gap-2 px-4 py-2 text-[11px] font-bold text-slate-600 hover:bg-slate-50 hover:text-slate-900" @click="showProfileMenu = false">
                  <User :size="14" /> <span>الملف الشخصي</span>
                </router-link>
                <div class="h-px bg-slate-100 my-1"></div>
                <button @click="logout" class="w-full flex items-center gap-2 px-4 py-2 text-[11px] font-bold text-rose-600 hover:bg-rose-50">
                  <LogOut :size="14" /> <span>تسجيل خروج</span>
                </button>
              </div>
            </Transition>
            </Teleport>
          </div>
        </div>
      </div>
    </header>

    <!-- Sidebar + Content Wrapper - starts below header -->
    <div class="flex flex-1 overflow-hidden relative z-10 pt-0">
      <!-- Sidebar -->
      <AppSidebar
        :menu-items="menuItems"
        :collapsed="sidebarCollapsed"
        :active-section="activeSection"
        @section="toggleSection"
        @toggle="toggleSidebar"
      />

      <!-- Section Panel -->
      <Transition name="dropdown-v2">
        <SectionPanel
          v-if="activeSection"
          :section="activeSection"
          :badge-counts="badgeCounts"
          :sidebar-collapsed="sidebarCollapsed"
          @close="activeSection = null"
          @navigate="activeSection = null"
        />
      </Transition>

      <!-- Main Content -->
      <main 
        class="flex-1 overflow-x-hidden overflow-y-auto custom-scroll relative bg-slate-50"
        :class="{ 'md:mr-[72px]': sidebarCollapsed, 'md:mr-[256px]': !sidebarCollapsed }"
      >
        <WorkspaceTabs />
        <slot></slot>
      </main>
    </div>

    <!-- Mobile Navigation Drawer (Only on Mobile) -->
    <Transition name="fade">
       <div v-if="mobileMenuOpen" class="fixed inset-0 z-50 md:hidden" @click.self="mobileMenuOpen = false">
          <div class="absolute inset-0 bg-slate-900/50 backdrop-blur-sm transition-opacity"></div>
          <aside class="absolute top-0 right-0 w-[min(280px,80vw)] h-full bg-white flex flex-col shadow-2xl overflow-hidden animate-slide-in">
             <!-- Header -->
             <div class="p-4 sm:p-5 border-b border-slate-100 flex items-center justify-between shrink-0 bg-gradient-to-r from-slate-50 to-white">
                <div class="flex items-center gap-2.5">
                  <img src="/favicons/logo-smartsys.png" alt="SmartSys" class="w-7 h-7 object-contain" />
                  <span class="text-xs sm:text-sm font-bold text-slate-700">القائمة</span>
                </div>
                <button @click="mobileMenuOpen = false" class="w-8 h-8 flex items-center justify-center text-slate-400 hover:text-slate-900 hover:bg-slate-100 rounded-md transition-colors" aria-label="إغلاق القائمة">
                  <X :size="18" />
                </button>
             </div>

             <!-- Menu Items -->
             <div class="flex-1 overflow-y-auto p-2 sm:p-3 space-y-0.5 custom-scroll overscroll-contain">
                <template v-for="item in menuItems" :key="item.key || item.path">
                  <!-- Direct Link -->
                  <router-link
                    v-if="!item.items"
                    :to="item.path"
                    @click="mobileMenuOpen = false"
                    class="flex items-center gap-2.5 sm:gap-3 px-3 sm:px-4 py-2.5 sm:py-3 rounded-lg text-[11px] sm:text-xs font-bold transition-all"
                    :class="[isActive(item) ? 'bg-blue-600 text-white shadow-sm' : 'text-slate-700 hover:bg-slate-50 active:bg-slate-100']"
                  >
                    <component :is="item.icon" :size="16" class="sm:w-[18px] sm:h-[18px] shrink-0" />
                    <span class="flex-1">{{ item.name }}</span>
                  </router-link>

                  <!-- Parent with Children -->
                  <div v-else class="space-y-0.5">
                    <button
                      @click="toggleMobileItem(item.key)"
                      class="w-full flex items-center gap-2.5 sm:gap-3 px-3 sm:px-4 py-2.5 sm:py-3 rounded-lg text-[11px] sm:text-xs font-bold text-slate-700 hover:bg-slate-50 active:bg-slate-100 transition-all"
                      :class="[isActive(item) ? 'bg-slate-100' : '']"
                      :aria-expanded="expandedMobileItems.has(item.key)"
                    >
                      <component :is="item.icon" :size="16" class="sm:w-[18px] sm:h-[18px] shrink-0" />
                      <span class="flex-1 text-right">{{ item.name }}</span>
                      <ChevronDown :size="12" class="sm:w-[14px] sm:h-[14px] transition-transform duration-200 shrink-0" :class="{ 'rotate-180': expandedMobileItems.has(item.key) }" />
                    </button>

                    <!-- Children -->
                    <Transition name="expand">
                      <div v-if="expandedMobileItems.has(item.key)" class="mr-3 sm:mr-4 mt-0.5 border-r-2 border-slate-100 space-y-0.5">
                        <router-link
                          v-for="child in item.items"
                          :key="child.path"
                          :to="child.path"
                          @click="mobileMenuOpen = false"
                          class="flex items-center gap-2 sm:gap-2.5 px-4 sm:px-5 py-2 sm:py-2.5 rounded-lg text-[10px] sm:text-[11px] font-bold transition-all"
                          :class="[isLeafActive(child) ? 'text-blue-600 bg-blue-50 border-r-2 border-blue-600' : 'text-slate-500 hover:text-slate-900 hover:bg-slate-50']"
                        >
                          <component :is="child.icon" :size="13" class="sm:w-[14px] sm:h-[14px] shrink-0 opacity-70" />
                          <span class="flex-1">{{ child.name }}</span>
                        </router-link>
                      </div>
                    </Transition>
                  </div>
                </template>
             </div>

             <!-- Footer -->
             <div class="p-3 sm:p-4 border-t border-slate-100 bg-slate-50/50 shrink-0">
               <div class="flex items-center gap-2 sm:gap-3 px-2 sm:px-3 py-2 mb-2">
                 <div class="w-8 h-8 sm:w-9 sm:h-9 rounded-full bg-slate-200 flex items-center justify-center text-slate-600 shrink-0">
                   <User :size="14" class="sm:w-4 sm:h-4" />
                 </div>
                 <div class="flex-1 min-w-0">
                   <p class="text-[10px] sm:text-[11px] font-bold text-slate-900 truncate">{{ authStore.user?.name }}</p>
                   <p class="text-[8px] sm:text-[9px] text-slate-500 mt-0.5">{{ userRoleDisplay }}</p>
                 </div>
               </div>
               <div class="flex gap-2">
                 <router-link
                   to="/profile"
                   @click="mobileMenuOpen = false"
                   class="flex-1 flex items-center justify-center gap-1.5 h-9 rounded-lg border border-slate-200 text-[11px] font-bold text-slate-600 hover:bg-slate-100 transition-colors"
                 >
                   <User :size="13" />
                   <span>الملف الشخصي</span>
                 </router-link>
                 <button
                   @click="logout"
                   class="flex-1 flex items-center justify-center gap-1.5 h-9 rounded-lg bg-rose-50 border border-rose-100 text-[11px] font-bold text-rose-600 hover:bg-rose-100 transition-colors"
                 >
                   <LogOut :size="13" />
                   <span>تسجيل خروج</span>
                 </button>
               </div>
             </div>
          </aside>
       </div>
    </Transition>

  </div>
</template>

<script setup>
import { computed, ref, reactive, onMounted, onUnmounted, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth/authStore'
import { useToast } from '@/composables/useToast'
import { useActiveRoute } from '@/composables/useActiveRoute'
import { getMenuItems } from '@/config/menuConfig'
import notificationsService from '@/services/notifications'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import AppSidebar from '@/components/layout/AppSidebar.vue'
import SectionPanel from '@/components/layout/SectionPanel.vue'
import WorkspaceTabs from '@/components/layout/WorkspaceTabs.vue'
import {
  Bell, User, LogOut, Menu, X, ChevronDown, Building
} from 'lucide-vue-next'

const router = useRouter()
const route = useRoute()
const authStore = useAuthStore()
const { showToast } = useToast()
const { isActive, isLeafActive } = useActiveRoute()

const mobileMenuOpen = ref(false)
const sidebarCollapsed = ref(true)
const activeSection = ref(null)
const expandedMobileItems = ref(new Set())
const badgeCounts = reactive({})
const notifications = ref([])
const unreadCount = ref(0)
const showNotifications = ref(false)
const showProfileMenu = ref(false)
const loadingNotifications = ref(false)
const previousUnreadCount = ref(-1)

const notificationsRef = ref(null)
const profileRef = ref(null)

const isUnread = (notification) => Number(notification.is_read) === 0

// Calculate dropdown right position so it aligns with button's right edge
// and never clips off the left side of the viewport
function calcDropdownRight(btnRef, dropdownWidth) {
  if (typeof window === 'undefined' || !btnRef?.value) return '8px'
  try {
    const rect = btnRef.value.getBoundingClientRect()
    const viewportWidth = window.innerWidth
    
    // Calculate dropdown width based on viewport for responsive dropdowns
    const actualDropdownWidth = viewportWidth < 640 
      ? Math.min(dropdownWidth, viewportWidth - 32) // Mobile: full width - padding
      : dropdownWidth // Desktop: fixed width
    
    // Align right edge of dropdown with right edge of button
    let right = viewportWidth - rect.right
    
    // Ensure left edge of dropdown stays inside viewport with 16px margin
    const leftEdgeX = viewportWidth - right - actualDropdownWidth
    if (leftEdgeX < 16) {
      right = viewportWidth - actualDropdownWidth - 16
    }
    
    // Ensure right doesn't go negative
    return `${Math.max(16, right)}px`
  } catch (e) {
    return '16px'
  }
}

const notificationsPosition = computed(() => calcDropdownRight(notificationsRef, 384)) // md:w-96 = 384px
const profilePosition       = computed(() => calcDropdownRight(profileRef, 208)) // w-52 = 208px

const userRoleDisplay = computed(() => {
  const rawRole = authStore.user?.role
  const role = Number(authStore.user?.tenant_id) > 0 && rawRole === 'super_admin'
    ? (authStore.isOwner ? 'admin' : 'employee')
    : rawRole
  const roleNames = {
    'super_admin': 'مالك النظام',
    'admin': 'مسؤول المنشأة',
    'manager': 'مدير الفرع',
    'cashier': 'كاشير',
    'inventory_clerk': 'مسؤول المخزون',
    'finance_officer': 'مسؤول مالي',
    'employee': 'موظف'
  }
  return roleNames[role] || role
})

const menuItems = computed(() => {
  return getMenuItems(
    authStore.user?.role || 'employee',
    authStore.user?.permissions || [],
    authStore.user || {}
  )
})

function toggleMobileItem(key) {
  const newSet = new Set(expandedMobileItems.value)
  if (newSet.has(key)) newSet.delete(key)
  else newSet.add(key)
  expandedMobileItems.value = newSet
}

function toggleSection(item) {
  activeSection.value = activeSection.value?.key === item.key ? null : item
}

function toggleSidebar() {
  sidebarCollapsed.value = !sidebarCollapsed.value
  localStorage.setItem('smartsys_sidebar_collapsed', String(sidebarCollapsed.value))
}

function handleDocumentClick(e) {
  if (activeSection.value && !e.target.closest('.section-panel') && !e.target.closest('aside')) {
    activeSection.value = null
  }
  if (showNotifications.value && notificationsRef.value && !notificationsRef.value.contains(e.target)) {
    showNotifications.value = false
  }
  if (showProfileMenu.value && profileRef.value && !profileRef.value.contains(e.target)) {
    showProfileMenu.value = false
  }
}

function handleEscKey(e) {
  if (e.key === 'Escape') {
    mobileMenuOpen.value = false
    activeSection.value = null
    showNotifications.value = false
    showProfileMenu.value = false
  }
}

async function logout() {
  await authStore.logout()
  router.push('/')
}

async function loadNotifications() {
  // Prevent multiple concurrent requests
  if (loadingNotifications.value) return

  loadingNotifications.value = true
  try {
    const response = await notificationsService.list(
      { page: 1, per_page: 10 },
      { skipLoader: true }
    )
    if (response.data.status === 'success') {
      const responseData = response.data.data || {}
      const apiNotifications = Array.isArray(responseData.notifications) ? responseData.notifications : []
      notifications.value = apiNotifications
      const currentUnread = notifications.value.filter(isUnread).length
      // Toast when new unread notifications arrive during polling
      if (currentUnread > previousUnreadCount.value && previousUnreadCount.value >= 0) {
        const newCount = currentUnread - previousUnreadCount.value
        showToast(`لديك ${newCount} إشعار جديد`, 'info')
      }
      previousUnreadCount.value = currentUnread
      unreadCount.value = currentUnread
    }
  } catch (error) {
    // Only log timeout errors in development
    if (import.meta.env.DEV && error.code !== 'ECONNABORTED') {
      console.error('Error loading notifications', error)
    }
  } finally {
    loadingNotifications.value = false
  }
}

async function markNotificationAsRead(notification) {
  try {
    if (notification.is_read) return
    const result = await notificationsService.markAsRead(notification.id)
    if (result.data?.status === 'success') {
      notification.is_read = 1
      unreadCount.value = notifications.value.filter(isUnread).length
    }
  } catch (error) {
    if (import.meta.env.DEV) console.error('Error marking notification read', error)
  }
}

async function markAllAsRead() {
  for (const note of notifications.value) {
    if (!note.is_read) {
      await markNotificationAsRead(note)
    }
  }
}

function toggleNotifications() {
  showNotifications.value = !showNotifications.value
  showProfileMenu.value = false
}

const formatRelativeTime = (date) => {
  if (!date) return 'للتو'
  try {
    const now = new Date()
    const notifDate = new Date(date)
    if (isNaN(notifDate.getTime())) return 'للتو'
    const diff = now - notifDate
    const mins = Math.floor(diff / 60000)
    if (mins < 1) return 'للتو'
    if (mins < 60) return `منذ ${mins} د`
    const hours = Math.floor(mins / 60)
    if (hours < 24) return `منذ ${hours} س`
    const days = Math.floor(hours / 24)
    if (days < 7) return `منذ ${days} ي`
    return notifDate.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })
  } catch (e) {
    return 'للتو'
  }
}

onMounted(() => {
  const savedSidebarState = localStorage.getItem('smartsys_sidebar_collapsed')
  if (savedSidebarState !== null) sidebarCollapsed.value = savedSidebarState !== 'false'

  document.addEventListener('click', handleDocumentClick)
  document.addEventListener('keydown', handleEscKey)

  // Request browser notification permission once
  if (typeof window !== 'undefined' && 'Notification' in window && Notification.permission === 'default') {
    Notification.requestPermission()
  }

  if (route.path !== '/setup' && route.path !== '/upgrade') {
    loadNotifications()
    const notificationIntervalId = setInterval(() => {
      if (route.path !== '/setup' && route.path !== '/upgrade') loadNotifications()
    }, 60000)
    onUnmounted(() => clearInterval(notificationIntervalId))
  }
})

onUnmounted(() => {
  document.removeEventListener('click', handleDocumentClick)
  document.removeEventListener('keydown', handleEscKey)
})

watch(showProfileMenu, (val) => {
  if (val) showNotifications.value = false
})
</script>

<style scoped>
.custom-scroll::-webkit-scrollbar {
  width: 5px;
}

.custom-scroll::-webkit-scrollbar-thumb {
  background: #e2e8f0;
  border-radius: 999px;
}

.custom-scroll::-webkit-scrollbar-thumb:hover {
  background: #cbd5e1;
}

.animate-slide-in {
  animation: slideIn 0.3s ease-out;
}

@keyframes slideIn {
  from {
    transform: translateX(-100%);
  }
  to {
    transform: translateX(0);
  }
}

@keyframes fadeIn {
  from {
    opacity: 0;
  }
  to {
    opacity: 1;
  }
}

.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}

.expand-enter-active,
.expand-leave-active {
  transition: all 0.2s ease;
}

.expand-enter-from {
  opacity: 0;
  transform: scaleY(0.9);
}

.expand-leave-to {
  opacity: 0;
  transform: scaleY(0.9);
}

.dropdown-v2-enter-active,
.dropdown-v2-leave-active {
  transition: opacity 0.15s ease;
}

.dropdown-v2-enter-from,
.dropdown-v2-leave-to {
  opacity: 0;
}
</style>
