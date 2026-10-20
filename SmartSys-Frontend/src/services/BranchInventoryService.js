import apiClient from '../config/axios';

/**
 * Fetch inventory for a specific branch
 * @param {number} branchId - ID of the branch
 * @param {Object} filters - Optional filters for the inventory list
 * @returns {Promise<Object>} - Inventory data and stats
 */
export const getBranchInventory = async (branchId, filters = {}) => {
  try {
    const response = await apiClient.get(`/branches/${branchId}/stock`, { params: filters });
    return response.data;
  } catch (error) {
    console.error('Error fetching branch inventory:', error);
    throw error;
  }
};

/**
 * Adjust stock quantity (add/remove)
 * @param {number} branchId - ID of the branch
 * @param {Object} adjustmentData - Adjustment data
 * @returns {Promise<Object>} - Response data
 */
export const adjustStock = async (branchId, adjustmentData) => {
  try {
    const response = await apiClient.post(
      `/branches/${branchId}/adjustments`,
      adjustmentData
    );
    return response.data;
  } catch (error) {
    console.error('Error adjusting stock:', error);
    throw error;
  }
};

/**
 * Search for products by name, barcode, or SKU
 * @param {string} query - Search query
 * @returns {Promise<Array>} - List of matching products
 */
export const searchProducts = async (query) => {
  try {
    const response = await apiClient.get('/products/search', {
      params: { q: query, limit: 10 }
    });
    return response.data.data || [];
  } catch (error) {
    console.error('Error searching products:', error);
    return [];
  }
};
