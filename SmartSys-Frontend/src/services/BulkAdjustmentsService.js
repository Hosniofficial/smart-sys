import apiClient from '../config/axios';
import productsService from '@/services/products';

export const searchBulkAdjustmentProducts = async (query = '') => {
  const normalizedQuery = query.trim();
  const { data } = await productsService.search(
    normalizedQuery ? { q: normalizedQuery, limit: 10 } : {},
  );
  return data;
};

/**
 * Bulk adjustments (JSON): distribute a single product across multiple branches.
 * @param {number} productId
 * @param {Array<{branch_id:number, quantity:number, notes?:string}>} items
 * @returns {Promise<{status:string,message:string}>}
 */
export const bulkAdjustProduct = async (productId, items) => {
  const payload = { product_id: productId, items };
  const { data } = await apiClient.post('/branches/adjustments/bulk', payload);
  return data;
};

/**
 * Bulk adjustments via CSV upload.
 * CSV columns supported: product_id or product_code, branch_id or branch_code, quantity, notes
 * Optionally pass a defaultProductId to apply to all rows missing product identifiers.
 * @param {File} file - CSV file
 * @param {number=} defaultProductId
 * @returns {Promise<{status:string,message:string,summary?:{imported:number,skipped:number}}>} 
 */
export const bulkAdjustFromCsv = async (file, defaultProductId) => {
  const form = new FormData();
  form.append('file', file);
  if (defaultProductId) form.append('product_id', String(defaultProductId));
  const { data } = await apiClient.post('/branches/adjustments/bulk/csv', form, {
    headers: { 'Content-Type': 'multipart/form-data' }
  });
  return data;
};
