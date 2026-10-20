import apiClient from '@/config/axios'

export const currencyService = {
  async getRates() {
    const response = await apiClient.get('/settings/currencies/rates')
    return response.data
  },

  async refreshRates() {
    const response = await apiClient.post('/settings/currencies/rates/refresh')
    return response.data
  },
}
