import apiClient from '@/config/axios';

export function getCurrentShift(branchId, terminalId) {
  return apiClient.get('/shifts/current', {
    params: {
      branch_id: branchId,
      terminal_id: terminalId,
    },
  });
}
