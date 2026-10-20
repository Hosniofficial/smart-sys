import { ref, reactive } from 'vue'

// Global alert state
const alertState = reactive({
  isVisible: false,
  type: 'info',
  title: '',
  message: '',
  confirmText: '',
  cancelText: '',
  closeOnBackdrop: true,
  persistent: false,
  resolve: null,
  reject: null
})

// Track auto-close timer to cancel it when a new alert opens
let autoCloseTimer = null

/**
 * Alert Service - Unified alert system for Vue 3 applications
 * Provides different types of alerts: success, warning, error, info, and confirm
 */
class AlertService {
  /**
   * Show a success alert
   * @param {string} message - Alert message
   * @param {string} title - Alert title (optional)
   * @param {Object} options - Additional options
   * @returns {Promise} Resolves when alert is closed
   */
  static success(message, title = 'نجاح', options = {}) {
    return this.show({
      type: 'success',
      title,
      message,
      ...options
    })
  }

  /**
   * Show a warning alert
   * @param {string} message - Alert message
   * @param {string} title - Alert title (optional)
   * @param {Object} options - Additional options
   * @returns {Promise} Resolves when alert is closed
   */
  static warning(message, title = 'تحذير', options = {}) {
    return this.show({
      type: 'warning',
      title,
      message,
      ...options
    })
  }

  /**
   * Show an error alert
   * @param {string} message - Alert message
   * @param {string} title - Alert title (optional)
   * @param {Object} options - Additional options
   * @returns {Promise} Resolves when alert is closed
   */
  static error(message, title = 'خطأ', options = {}) {
    return this.show({
      type: 'error',
      title,
      message,
      ...options
    })
  }

  /**
   * Show an info alert
   * @param {string} message - Alert message
   * @param {string} title - Alert title (optional)
   * @param {Object} options - Additional options
   * @returns {Promise} Resolves when alert is closed
   */
  static info(message, title = 'معلومات', options = {}) {
    return this.show({
      type: 'info',
      title,
      message,
      ...options
    })
  }

  /**
   * Show a confirmation dialog
   * @param {string} message - Confirmation message
   * @param {string} title - Confirmation title (optional)
   * @param {Object} options - Additional options
   * @returns {Promise<boolean>} Resolves to true if confirmed, false if cancelled
   */
  static confirm(message, title = 'تأكيد', options = {}) {
    return new Promise((resolve, reject) => {
      this.show({
        type: 'confirm',
        title,
        message,
        confirmText: options.confirmText || 'تأكيد',
        cancelText: options.cancelText || 'إلغاء',
        closeOnBackdrop: options.closeOnBackdrop !== false,
        persistent: options.persistent || false,
        ...options
      }).then(() => {
        resolve(true)
      }).catch(() => {
        resolve(false)
      })
    })
  }

  /**
   * Show a custom alert
   * @param {Object} config - Alert configuration
   * @returns {Promise} Resolves when alert is closed
   */
  static show(config) {
    return new Promise((resolve, reject) => {
      // Store resolve/reject for later use
      alertState.resolve = resolve
      alertState.reject = reject

      // Update alert state
      Object.assign(alertState, {
        isVisible: true,
        type: config.type || 'info',
        title: config.title || '',
        message: config.message || '',
        confirmText: config.confirmText || '',
        cancelText: config.cancelText || '',
        closeOnBackdrop: config.closeOnBackdrop !== false,
        persistent: config.persistent || false
      })

      // Cancel any pending auto-close timer from a previous alert
      if (autoCloseTimer !== null) {
        clearTimeout(autoCloseTimer)
        autoCloseTimer = null
      }

      // Auto-close for non-confirm alerts after timeout
      if (config.type !== 'confirm' && config.autoClose !== false) {
        const timeout = config.timeout || 3000
        autoCloseTimer = setTimeout(() => {
          autoCloseTimer = null
          this.hide()
        }, timeout)
      }
    })
  }

  /**
   * Hide the current alert
   */
  static hide() {
    // Never auto-close a confirm dialog — it must be resolved by explicit user action
    if (alertState.type === 'confirm') return

    alertState.isVisible = false
    if (alertState.resolve) {
      alertState.resolve()
      alertState.resolve = null
      alertState.reject = null
    }
  }

  /**
   * Handle confirm action
   */
  static handleConfirm() {
    if (alertState.resolve) {
      alertState.resolve()
      alertState.resolve = null
      alertState.reject = null
    }
    alertState.isVisible = false
  }

  /**
  * Handle cancel action
  */
  static handleCancel() {
    // For non-confirm alerts, the "cancel" button is effectively an "OK/close" action.
    // Rejecting here can create unhandled promise rejections if the caller didn't attach a catch.
    if (alertState.type === 'confirm') {
      if (alertState.reject) {
        alertState.reject()
      }
    } else {
      if (alertState.resolve) {
        alertState.resolve()
      }
    }

    alertState.resolve = null
    alertState.reject = null
    alertState.isVisible = false
  }

  /**
   * Show loading state (useful for async operations)
   * @param {string} message - Loading message
   * @param {string} title - Loading title
   */
  static loading(message = 'جاري التحميل...', title = 'الرجاء الانتظار') {
    return this.show({
      type: 'info',
      title,
      message,
      closeOnBackdrop: false,
      persistent: true,
      autoClose: false
    })
  }

  /**
   * Show toast notification (small, non-intrusive)
   * @param {string} message - Toast message
   * @param {string} type - Toast type
   * @param {number} duration - Duration in milliseconds
   */
  static toast(message, type = 'info', duration = 3000) {
    // This would require a separate toast component
    // For now, we'll use the regular alert with minimal options
    return this.show({
      type,
      message,
      title: '',
      timeout: duration,
      closeOnBackdrop: true
    })
  }

  /**
   * Batch multiple alerts
   * @param {Array} alerts - Array of alert configurations
   * @param {boolean} sequential - Show alerts one after another
   */
  static async batch(alerts, sequential = true) {
    if (sequential) {
      for (const alert of alerts) {
        await this.show(alert)
      }
    } else {
      // For parallel alerts, you'd need multiple alert instances
      // This is a placeholder for future enhancement
      console.warn('Parallel alerts not yet implemented')
    }
  }
}

// Export for use in components
export default AlertService
export { alertState }