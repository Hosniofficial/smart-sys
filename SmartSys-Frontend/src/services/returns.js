import apiClient from '@/config/axios';

const getReturnPath = (type) => type === 'sales' ? '/returns/sale' : '/returns/purchase';

export const returnsService = {
  list(type, params, signal) {
    return apiClient.get(getReturnPath(type), { params, signal });
  },

  get(type, returnId, signal) {
    return apiClient.get(`${getReturnPath(type)}/${returnId}`, { signal });
  },

  search(type, params, signal) {
    return apiClient.get(getReturnPath(type), { params, signal });
  },

  searchInvoices(params, signal) {
    return apiClient.get('/returns/invoices', { params, signal });
  },

  getInvoiceItems(type, invoiceId) {
    return apiClient.get('/returns/invoice-items', {
      params: { type, invoice_id: invoiceId },
    });
  },

  getReturnedQuantities(type, invoiceId) {
    return apiClient.get('/returns/returned-qty', {
      params: { type, invoice_id: invoiceId },
    });
  },

  create(payload) {
    return apiClient.post('/returns', payload);
  },
};

export default returnsService;
