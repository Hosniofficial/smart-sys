import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import apiClient from '@/config/axios';

export const useCostCenterStore = defineStore('costCenter', () => {
  const costCenters = ref([]);
  const selectedCostCenterId = ref(null);
  const loading = ref(false);
  const error = ref(null);

  // Get selected cost center details
  const selectedCostCenter = computed(() => {
    return costCenters.value.find(cc => cc.id === selectedCostCenterId.value);
  });

  // Fetch all cost centers
  const fetchCostCenters = async () => {
    loading.value = true;
    error.value = null;
    try {
      const response = await apiClient.get('/cost-centers');
      costCenters.value = response.data?.data || response.data || [];
      
      // Auto-select first cost center if none selected
      if (costCenters.value.length > 0 && !selectedCostCenterId.value) {
        selectedCostCenterId.value = costCenters.value[0].id;
      }
      
      return costCenters.value;
    } catch (err) {
      error.value = err.message || 'Failed to fetch cost centers';
      console.error('Error fetching cost centers:', err);
      return [];
    } finally {
      loading.value = false;
    }
  };

  // Set selected cost center
  const setSelectedCostCenter = (costCenterId) => {
    const exists = costCenters.value.find(cc => cc.id === costCenterId);
    if (exists) {
      selectedCostCenterId.value = costCenterId;
      // Persist to localStorage
      localStorage.setItem('selectedCostCenterId', costCenterId);
      return true;
    }
    return false;
  };

  // Load selected cost center from localStorage
  const loadFromStorage = () => {
    const stored = localStorage.getItem('selectedCostCenterId');
    if (stored) {
      selectedCostCenterId.value = parseInt(stored);
    }
  };

  // Initialize on store creation
  const initialize = async () => {
    loadFromStorage();
    if (costCenters.value.length === 0) {
      await fetchCostCenters();
    }
  };

  return {
    // State
    costCenters,
    selectedCostCenterId,
    loading,
    error,
    
    // Computed
    selectedCostCenter,
    
    // Actions
    fetchCostCenters,
    setSelectedCostCenter,
    loadFromStorage,
    initialize
  };
});
