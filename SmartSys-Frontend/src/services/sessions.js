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
      sessionIds: sessionIds,
    });
  },

  listSessions(params) {
    return apiClient.get('/sessions', { params });
  },

  async getCurrent(branchId, cashierId, deviceId) {
    const params = {};
    if (branchId !== undefined && branchId !== null) params.branchId = branchId;
    if (cashierId !== undefined && cashierId !== null) params.cashierId = cashierId;
    if (deviceId !== undefined && deviceId !== null && `${deviceId}` !== '') params.deviceId = deviceId;
    const res = await this.getCurrentSession(params, { skipLoader: true });
    return res.data?.data || null;
  },

  async openSession({ branchId: branch_id, openingCashAmount: opening_cash_amount = 0, sessionType: session_type = 'manual', notes = null, cashierId: cashier_id = undefined, deviceId: device_id = undefined, deviceName: device_name = undefined, terminalId: terminal_id = undefined }) {
    const body = { branchId: branch_id, openingCashAmount: opening_cash_amount, sessionType: session_type };
    if (notes) body.notes = notes;
    // Do not send cashier_id normally; backend infers from JWT. Allow override for admin tools.
    if (cashier_id) body.cashierId = cashier_id;
    if (device_id) body.deviceId = device_id;
    if (device_name) body.deviceName = device_name;
    if (terminal_id !== undefined && terminal_id !== null) body.terminalId = terminal_id;
    const res = await this.openSessionRequest(body);
    return res.data?.data || null;
  }
};

export default SessionsService;
