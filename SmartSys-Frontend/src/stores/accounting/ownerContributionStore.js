import { defineStore } from 'pinia';
import apiClient from '@/config/axios';

export const useOwnerContributionStore = defineStore('ownerContribution', () => {
  const fetchAvailableTenants = async () => {
    try {
      const response = await apiClient.get('/admin/owner-contribution-tenants');
      return { status: 'success', data: response?.data?.data || [], message: null };
    } catch (error) {
      return {
        status: 'error',
        data: null,
        message: error.response?.data?.message || error.message,
      };
    }
  };

  const fetchContributions = async (params = {}) => {
    try {
      const response = await apiClient.get('/setup/owner-contributions', { params });
      return { status: 'success', data: response?.data?.data || {}, message: null };
    } catch (error) {
      return {
        status: 'error',
        data: null,
        message: error.response?.data?.message || error.message,
      };
    }
  };

  const fetchContribution = async (id) => {
    try {
      const response = await apiClient.get(`/setup/owner-contribution/${id}`);
      return { status: 'success', data: response?.data?.data || {}, message: null };
    } catch (error) {
      return {
        status: 'error',
        data: null,
        message: error.response?.data?.message || error.message,
      };
    }
  };

  const createContribution = async (payload) => {
    try {
      const response = await apiClient.post('/setup/owner-contribution/create', payload);
      return { status: 'success', data: response?.data?.data || {}, message: null };
    } catch (error) {
      const responseData = error.response?.data;
      if (responseData?.status === 'success' && responseData?.data?.duplicate) {
        return { status: 'success', data: responseData.data, message: null };
      }
      return {
        status: 'error',
        data: responseData?.data || null,
        message: responseData?.message || error.message,
        outcomeUnknown: !error.response || error.response.status >= 500,
      };
    }
  };

  const voidContribution = async (id, payload) => {
    try {
      const response = await apiClient.post(`/setup/owner-contribution/${id}/void`, payload);
      return { status: 'success', data: response?.data?.data || {}, message: null };
    } catch (error) {
      return {
        status: 'error',
        data: error.response?.data?.data || null,
        message: error.response?.data?.message || error.message,
        outcomeUnknown: !error.response || error.response.status >= 500,
      };
    }
  };

  return {
    fetchAvailableTenants,
    fetchContributions,
    fetchContribution,
    createContribution,
    voidContribution,
  };
});
