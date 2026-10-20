import { ref, computed } from 'vue'

/**
 * Enterprise loading state management
 * Maps loading types to appropriate UI patterns (SAP Fiori inspired)
 * 
 * Usage patterns:
 * - 'page': Full page skeleton loading
 * - 'action': Button/inline spinner for user actions
 * - 'background': Subtle busy indicator for background fetches
 * - 'section': Skeleton for specific content areas
 */
export function useLoading() {
  const states = ref(new Map())

  /**
   * Set loading state for a specific key
   * @param {string} key - Unique identifier for this loading state
   * @param {boolean} value - Loading state
   * @param {string} type - Loading type: 'page' | 'action' | 'background' | 'section'
   */
  const setLoading = (key, value, type = 'background') => {
    states.value.set(key, { loading: value, type, timestamp: Date.now() })
  }

  /**
   * Get loading state for a specific key
   */
  const isLoading = (key) => {
    return computed(() => states.value.get(key)?.loading ?? false)
  }

  /**
   * Get loading type for a specific key
   */
  const getType = (key) => {
    return computed(() => states.value.get(key)?.type ?? 'background')
  }

  /**
   * Clear loading state
   */
  const clear = (key) => {
    states.value.delete(key)
  }

  /**
   * Clear all loading states
   */
  const clearAll = () => {
    states.value.clear()
  }

  /**
   * Check if any loading is active
   */
  const anyLoading = computed(() => {
    for (const [, state] of states.value) {
      if (state.loading) return true
    }
    return false
  })

  /**
   * Get appropriate component for loading type
   * Use this to determine which loading UI to show
   */
  const getLoadingComponent = (key) => {
    return computed(() => {
      const state = states.value.get(key)
      if (!state?.loading) return null

      const componentMap = {
        page: 'skeleton',      // Full page skeleton
        section: 'skeleton',   // Section skeleton  
        action: 'spinner',     // Inline spinner
        background: 'busy'     // Subtle busy indicator
      }

      return componentMap[state.type] || 'busy'
    })
  }

  /**
   * Wrap an async function with loading state
   * Automatically handles set/clear
   */
  const withLoading = async (key, fn, type = 'background') => {
    setLoading(key, true, type)
    try {
      return await fn()
    } finally {
      setLoading(key, false, type)
    }
  }

  return {
    // State
    states: computed(() => Object.fromEntries(states.value)),
    anyLoading,
    
    // Actions
    setLoading,
    isLoading,
    getType,
    getLoadingComponent,
    clear,
    clearAll,
    withLoading
  }
}
