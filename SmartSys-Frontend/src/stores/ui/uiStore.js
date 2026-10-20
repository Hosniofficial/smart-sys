import { defineStore } from 'pinia'

export const useUiStore = defineStore('ui', {
  state: () => ({
    loadingCount: 0,
    toasts: [] // { id, message, type }
  }),
  actions: {
    showLoader() {
      this.loadingCount++
    },
    hideLoader() {
      if (this.loadingCount > 0) this.loadingCount--
    },
    addToast(message, type = 'success', duration = 2000) {
      const id = Date.now() + Math.random()
      this.toasts.push({ id, message, type })
      setTimeout(() => this.removeToast(id), duration)
    },
    removeToast(id) {
      this.toasts = this.toasts.filter(t => t.id !== id)
    }
  }
})
