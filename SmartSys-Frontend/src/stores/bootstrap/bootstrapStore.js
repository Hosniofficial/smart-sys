import { defineStore } from 'pinia'
import { ref } from 'vue'
import apiClient from '@/config/axios'

/**
 * Bootstrap Store
 * Handles aggregated API calls for improved performance
 * Reduces multiple HTTP requests to single endpoints
 */
export const useBootstrapStore = defineStore('bootstrap', () => {
  // ═══════════════════════════════════════════════════════
  // State
  // ═══════════════════════════════════════════════════════
  const posData = ref(null)
  const paymentsData = ref(null)
  const sessionsData = ref(null)
  const managementData = ref({})

  // Cache timestamps
  const posDataTimestamp = ref(null)
  const paymentsDataTimestamp = ref(null)
  const sessionsDataTimestamp = ref(null)
  const managementDataTimestamps = ref({})

  // Cache TTL (5 minutes)
  const CACHE_TTL = 5 * 60 * 1000

  // ═══════════════════════════════════════════════════════
  // Helpers
  // ═══════════════════════════════════════════════════════
  
  /**
   * Check if cached data is still valid
   */
  const isCacheValid = (timestamp) => {
    if (!timestamp) return false
    return (Date.now() - timestamp) < CACHE_TTL
  }

  // ═══════════════════════════════════════════════════════
  // POS Data
  // ═══════════════════════════════════════════════════════
  
  /**
   * Fetch POS (Point of Sale) page data
   * Returns: branches, categories, paymentMethods, currentSession, settings
   */
  const fetchPosData = async (forceRefresh = false) => {
    try {
      // Return cached data if valid
      if (!forceRefresh && isCacheValid(posDataTimestamp.value) && posData.value) {
        console.log('[Bootstrap] Using cached POS data')
        return posData.value
      }

      console.log('[Bootstrap] Fetching POS data...')
      const response = await apiClient.get('/bootstrap/pos')
      
      if (response.data.status === 'success') {
        posData.value = response.data.data
        posDataTimestamp.value = Date.now()
        console.log('[Bootstrap] POS data fetched successfully')
        return response.data.data
      } else {
        throw new Error(response.data.message || 'فشل في تحميل بيانات نقطة البيع')
      }
    } catch (error) {
      console.error('[Bootstrap] Failed to fetch POS data:', error)
      throw error
    }
  }

  /**
   * Invalidate POS data cache
   */
  const invalidatePosData = () => {
    posData.value = null
    posDataTimestamp.value = null
    console.log('[Bootstrap] POS data cache invalidated')
  }

  // ═══════════════════════════════════════════════════════
  // Payments Data
  // ═══════════════════════════════════════════════════════
  
  /**
   * Fetch Payments page data
   * Returns: paymentMethods, customers, suppliers, users, settings
   */
  const fetchPaymentsData = async (forceRefresh = false) => {
    try {
      // Return cached data if valid
      if (!forceRefresh && isCacheValid(paymentsDataTimestamp.value) && paymentsData.value) {
        console.log('[Bootstrap] Using cached Payments data')
        return paymentsData.value
      }

      console.log('[Bootstrap] Fetching Payments data...')
      const response = await apiClient.get('/bootstrap/payments')
      
      if (response.data.status === 'success') {
        paymentsData.value = response.data.data
        paymentsDataTimestamp.value = Date.now()
        console.log('[Bootstrap] Payments data fetched successfully')
        return response.data.data
      } else {
        throw new Error(response.data.message || 'فشل في تحميل بيانات الدفعات')
      }
    } catch (error) {
      console.error('[Bootstrap] Failed to fetch Payments data:', error)
      throw error
    }
  }

  /**
   * Invalidate Payments data cache
   */
  const invalidatePaymentsData = () => {
    paymentsData.value = null
    paymentsDataTimestamp.value = null
    console.log('[Bootstrap] Payments data cache invalidated')
  }

  // ═══════════════════════════════════════════════════════
  // Sessions Data
  // ═══════════════════════════════════════════════════════
  
  /**
   * Fetch Sessions page data
   * Returns: branches, users
   */
  const fetchSessionsData = async (forceRefresh = false) => {
    try {
      // Return cached data if valid
      if (!forceRefresh && isCacheValid(sessionsDataTimestamp.value) && sessionsData.value) {
        console.log('[Bootstrap] Using cached Sessions data')
        return sessionsData.value
      }

      console.log('[Bootstrap] Fetching Sessions data...')
      const response = await apiClient.get('/bootstrap/sessions')
      
      if (response.data.status === 'success') {
        sessionsData.value = response.data.data
        sessionsDataTimestamp.value = Date.now()
        console.log('[Bootstrap] Sessions data fetched successfully')
        return response.data.data
      } else {
        throw new Error(response.data.message || 'فشل في تحميل بيانات الجلسات')
      }
    } catch (error) {
      console.error('[Bootstrap] Failed to fetch Sessions data:', error)
      throw error
    }
  }

  /**
   * Invalidate Sessions data cache
   */
  const invalidateSessionsData = () => {
    sessionsData.value = null
    sessionsDataTimestamp.value = null
    console.log('[Bootstrap] Sessions data cache invalidated')
  }

  // ═══════════════════════════════════════════════════════
  // Management Data (Purchase/Sale)
  // ═══════════════════════════════════════════════════════
  
  /**
   * Fetch Management page data
   * @param {string} type - 'purchase' or 'sale'
   * Returns: branches, paymentMethods, suppliers (if purchase) or customers (if sale)
   */
  const fetchManagementData = async (type = 'purchase', forceRefresh = false) => {
    try {
      // Return cached data if valid
      if (!forceRefresh && 
          isCacheValid(managementDataTimestamps.value[type]) && 
          managementData.value[type]) {
        console.log(`[Bootstrap] Using cached ${type} Management data`)
        return managementData.value[type]
      }

      console.log(`[Bootstrap] Fetching ${type} Management data...`)
      const response = await apiClient.get(`/bootstrap/management/${type}`)
      
      if (response.data.status === 'success') {
        managementData.value[type] = response.data.data
        managementDataTimestamps.value[type] = Date.now()
        console.log(`[Bootstrap] ${type} Management data fetched successfully`)
        return response.data.data
      } else {
        throw new Error(response.data.message || 'فشل في تحميل بيانات الإدارة')
      }
    } catch (error) {
      console.error(`[Bootstrap] Failed to fetch ${type} Management data:`, error)
      throw error
    }
  }

  /**
   * Invalidate Management data cache
   */
  const invalidateManagementData = (type = null) => {
    if (type) {
      delete managementData.value[type]
      delete managementDataTimestamps.value[type]
      console.log(`[Bootstrap] ${type} Management data cache invalidated`)
    } else {
      managementData.value = {}
      managementDataTimestamps.value = {}
      console.log('[Bootstrap] All Management data cache invalidated')
    }
  }

  // ═══════════════════════════════════════════════════════
  // Clear All Cache
  // ═══════════════════════════════════════════════════════
  
  /**
   * Clear all cached data
   */
  const clearAllCache = () => {
    invalidatePosData()
    invalidatePaymentsData()
    invalidateSessionsData()
    invalidateManagementData()
    console.log('[Bootstrap] All cache cleared')
  }

  // ═══════════════════════════════════════════════════════
  // Return
  // ═══════════════════════════════════════════════════════
  
  return {
    // State
    posData,
    paymentsData,
    sessionsData,
    managementData,
    
    // POS
    fetchPosData,
    invalidatePosData,
    
    // Payments
    fetchPaymentsData,
    invalidatePaymentsData,
    
    // Sessions
    fetchSessionsData,
    invalidateSessionsData,
    
    // Management
    fetchManagementData,
    invalidateManagementData,
    
    // Clear all
    clearAllCache
  }
})
