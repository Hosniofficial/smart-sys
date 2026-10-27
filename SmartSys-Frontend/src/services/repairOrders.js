import apiClient from '@/config/axios'

const RepairOrdersService = {
  async list(params = {}) {
    const { data } = await apiClient.get('/repairs', { params })
    if (data?.status !== 'success' || !data?.data) {
      throw new Error(data?.message || 'تعذر تحميل أوامر الصيانة.')
    }
    // Backend يُعيد { items, total, page, limit, total_pages }
    return data.data
  },

  async get(id) {
    const { data } = await apiClient.get(`/repairs/${id}`)
    if (data?.status !== 'success' || !data?.data) {
      throw new Error(data?.message || 'تعذر تحميل أمر الصيانة.')
    }
    return data.data
  },

  async create(payload) {
    const { data } = await apiClient.post('/repairs', payload)
    if (data?.status !== 'success' || !data?.data?.id) {
      throw new Error(data?.message || 'تعذر تسجيل استلام الجهاز.')
    }
    return data.data
  },

  async update(id, payload) {
    const { data } = await apiClient.patch(`/repairs/${id}`, payload)
    if (data?.status !== 'success') {
      throw new Error(data?.message || 'تعذر تحديث أمر الصيانة.')
    }
    return data.data
  },

  async createInvoice(id) {
    const { data } = await apiClient.post(`/repairs/${id}/invoice`)
    if (data?.status !== 'success' || !data?.data?.sale_id) {
      throw new Error(data?.message || 'تعذر إصدار فاتورة الصيانة.')
    }
    return data.data
  }
}

export default RepairOrdersService
