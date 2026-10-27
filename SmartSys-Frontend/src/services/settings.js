import apiClient from '@/config/axios'

export const settingsService = {
  async getAll() {
    const res = await settingsService.getSettings()
    return res.data
  },

  async update(settings) {
    const res = await settingsService.updateSettings(settings)
    return res.data
  },

  getSettings({ skipLoader = false, suppress403 = false } = {}) {
    return apiClient.get('/settings', {
      meta: {
        ...(skipLoader ? { skipLoader: true } : {}),
        ...(suppress403 ? { suppress403: true } : {}),
      },
    })
  },

  updateSettings(settings) {
    return apiClient.put('/settings', { settings })
  },

  uploadLogo(formData) {
    return apiClient.post('/settings/logo', formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
  },
}
