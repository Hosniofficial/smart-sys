import apiClient from '@/config/axios';

// Warranty Service
export const WarrantyService = {
  async list(params = {}) {
    const res = await apiClient.get('/warranty/requests', { params });
    return {
      ...(res.data?.data || { items: [] }),
      meta: res.data?.meta || {},
    };
  },

  async create(payload) {
    // invoice_id, when supplied, is the selected internal sales.id for this customer and tenant.
    // payload: { customerId, invoiceId, productSerial, issueDescription, purchaseDate, priority, items: [{productId, quantity, issueNotes}] }
    const res = await apiClient.post('/warranty/requests', payload);
    return res.data?.data || null;
  },

  async get(id) {
    const res = await apiClient.get(`/warranty/requests/${id}`);
    return res.data?.data || null;
  },

  async update(id, updates) {
    const res = await apiClient.patch(`/warranty/requests/${id}`, updates);
    return res.data?.data || null;
  },

  async changeStatus(id, status, note = null) {
    const body = { status };
    if (note) body.reason = note;
    const res = await apiClient.post(`/warranty/requests/${id}/status`, body);
    return res.data?.data || null;
  },

  async addNote(id, { content, isInternal: is_internal = false }) {
    const res = await apiClient.post(`/warranty/requests/${id}/notes`, { note: content, isInternal: is_internal });
    return res.data?.data || null;
  },

  async uploadAttachment(id, file) {
    const form = new FormData();
    form.append('file', file);
    const res = await apiClient.post(`/warranty/requests/${id}/attachments`, form, {
      headers: { 'Content-Type': 'multipart/form-data' }
    });
    return res.data?.data || null;
  },

  async deleteAttachment(attachmentId) {
    const res = await apiClient.delete(`/warranty/attachments/${attachmentId}`);
    return res.data?.data || { success: true };
  }
};

export default WarrantyService;
