<template>
  <Layout v-if="showLayout">
    <router-view v-slot="{ Component }">
      <transition name="fade" mode="out-in">
        <div>
          <component :is="Component" />
        </div>
      </transition>
    </router-view>
  </Layout>

  <router-view v-else v-slot="{ Component }">
    <transition name="fade" mode="out-in">
      <div>
        <component :is="Component" />
      </div>
    </transition>
  </router-view>

  <!-- Global overlays -->
  <GlobalLoader />
  <GlobalToasts />
  <AlertComponent 
    :isVisible="alertState.isVisible"
    :type="alertState.type"
    :title="alertState.title"
    :message="alertState.message"
    :confirmText="alertState.confirmText"
    :cancelText="alertState.cancelText"
    :closeOnBackdrop="alertState.closeOnBackdrop"
    :persistent="alertState.persistent"
    @confirm="AlertService.handleConfirm"
    @cancel="AlertService.handleCancel"
    @close="AlertService.hide"
  />
</template>

<script setup>
import { computed } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import Layout from './components/layout/Layout.vue'
import GlobalLoader from './components/ui/GlobalLoader.vue'
import GlobalToasts from './components/ui/GlobalToasts.vue'
import AlertService from './services/AlertService'
import { alertState } from './services/AlertService'
import AlertComponent from './components/common/AlertComponent.vue'

const router = useRouter()
const route = useRoute()

const showLayout = computed(() => {
  const publicRoutes = ['/', '/login', '/register', '/forgot-password']
  return !publicRoutes.includes(route.path)
})
</script>

<style>
/* Transitions */
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.15s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
