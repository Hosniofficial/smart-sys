import { createApp } from 'vue'
import { createPinia } from 'pinia'
import './style.css'
import './assets/tailwind.css'
import App from './App.vue'
import '@fortawesome/fontawesome-free/css/all.css'
import router from './router'
import apiClient from './config/axios'
import { useAuthStore, setStoreRouter } from '@/stores/auth/authStore'
import logger from './services/logger'
import { validateMenuConfig } from '@/config/menuConfig'

// Create app
const app = createApp(App)

// Initialize Pinia
const pinia = createPinia()

// Make api available globally
app.config.globalProperties.$api = apiClient
app.config.globalProperties.$logger = logger

logger.info('Vue application initializing', {
  env: import.meta.env.VITE_APP_ENV,
  logLevel: import.meta.env.VITE_LOG_LEVEL
})

// Validate menuConfig at boot time (fail fast in dev/CI)
try {
  validateMenuConfig()
  if (import.meta.env.DEV) {
    console.log('✅ menuConfig validation passed')
  }
} catch (error) {
  console.error('❌ menuConfig validation failed:', error.message)
  throw error
}

// Initialize Pinia and Router FIRST
app.use(pinia)
app.use(router)

// Register router in auth store AFTER pinia is ready
// This breaks the circular dependency: router → auth → router
setStoreRouter(router)

// Initialize auth store after pinia is ready
const authStore = useAuthStore()

// Restore user metadata from localStorage (non-sensitive).
const storedUser = localStorage.getItem('user')
if (storedUser && storedUser !== 'undefined') {
  try {
    authStore.user = JSON.parse(storedUser)
  } catch (_) {
    localStorage.removeItem('user')
  }
}

// Legacy cleanup
localStorage.removeItem('token')
localStorage.removeItem('refresh_token')

// Mount the app
app.mount('#app')
