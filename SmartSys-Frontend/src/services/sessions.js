import apiClient from '@/config/axios';

// Sessions Service
// axios baseURL is '/api' and Vite proxy rewrites '/api' -> '/api/v1'.
// Therefore, DO NOT prefix paths with '/v1' here.
export const SessionsService = {
  getCurrentSession(params, config = {}) {
    return apiClient.get('/sessions/current', {
      ...config,
      params,
    });
  },

  openSessionRequest(payload) {
    return apiClient.post('/sessions/open', payload);
  },

  closeSession(sessionId, payload) {
    return apiClient.post(`/sessions/${sessionId}/close`, payload);
  },

  getSessionSummary(sessionId) {
    return apiClient.get(`/sessions/${sessionId}/summary`);
  },

  getSessionSummaries(sessionIds) {
    return apiClient.post('/sessions/batch-summaries', {
      session_ids: sessionIds,
    });
  },

  listSessions(params) {
    return apiClient.get('/sessions', { params });
  },

  async getCurrent(branchId, cashierId, deviceId) {
    const params = {};
    if (branchId !== undefined && branchId !== null) params.branch_id = branchId;
    if (cashierId !== undefined && cashierId !== null) params.cashier_id = cashierId;
    if (deviceId !== undefined && deviceId !== null && `${deviceId}` !== '') params.device_id = deviceId;
    const res = await this.getCurrentSession(params, { skipLoader: true });
    return res.data?.data || null;
  },

  async openSession({ branch_id, opening_cash_amount = 0, session_type = 'manual', notes = null, cashier_id = undefined, device_id = undefined, device_name = undefined, terminal_id = undefined }) {
    const body = { branch_id, opening_cash_amount, session_type };
    if (notes) body.notes = notes;
    // Do not send cashier_id normally; backend infers from JWT. Allow override for admin tools.
    if (cashier_id) body.cashier_id = cashier_id;
    if (device_id) body.device_id = device_id;
    if (device_name) body.device_name = device_name;
    if (terminal_id !== undefined && terminal_id !== null) body.terminal_id = terminal_id;
    const res = await this.openSessionRequest(body);
    return res.data?.data || null;
  }
};

export default SessionsService;
