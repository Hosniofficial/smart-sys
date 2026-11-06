import apiClient from '@/config/axios';

export function getCurrentShift(branchId, terminalId) {
  return apiClient.get('/shifts/current', {
    params: {
      branchId: branchId,
      terminalId: terminalId,
    },
  });
}
