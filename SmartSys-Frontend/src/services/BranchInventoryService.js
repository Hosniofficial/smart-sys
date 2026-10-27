import apiClient from '@/config/axios';
import inventoryService from '@/services/inventory';
import productsService from '@/services/products';

/**
 * Fetch inventory for a specific branch
 * @param {number} branchId - ID of the branch
 * @param {Object} filters - Optional filters for the inventory list
 * @returns {Promise<Object>} - Inventory data and stats
 */
export const getBranchInventory = async (branchId, filters = {}) => {
  const response = await apiClient.get(`/branches/${branchId}/stock`, { params: filters });
  return response.data;
};

/**
 * Adjust stock quantity (add/remove)
 * @param {number} branchId - ID of the branch
 * @param {Object} adjustmentData - Adjustment data
 * @returns {Promise<Object>} - Response data
 */
export const adjustStock = async (branchId, adjustmentData) => {
  const response = await inventoryService.adjustStock(branchId, adjustmentData);
  return response.data;
};

/**
 * Search for products by name, barcode, or SKU
 * @param {string} query - Search query
 * @returns {Promise<Array>} - List of matching products
 */
export const searchProducts = async (query) => {
  const response = await productsService.search({ q: query, limit: 10 });
  const products = response.data?.data;
  if (!Array.isArray(products)) {
    throw new Error('Invalid response when searching products');
  }
  return products;
};
