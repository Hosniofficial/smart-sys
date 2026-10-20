/**
 * Format a number as currency
 * @param {number} value - The number to format
 * @param {string} currency - The currency code (default: 'EGP')
 * @param {number} decimals - Number of decimal places (default: 2)
 * @returns {string} Formatted currency string
 */
import { getCurrencySymbol } from '../../config/currencies.js';

export const formatCurrency = (value, currency = 'EGP', decimals = 2) => {
  if (value === null || value === undefined) return '0.00';

  const numericValue = typeof value === 'string' ? parseFloat(value) : value;

  if (isNaN(numericValue)) return '0.00';

  // Format number with fixed decimals and add thousands separators
  const formattedValue = numericValue.toFixed(decimals).replace(/\B(?=(\d{3})+(?!\d))/g, ',');

  // Get currency symbol from config (supports dynamic symbols from settings)
  const symbol = getCurrencySymbol(currency);

  // For RTL languages, put the symbol at the end
  return `${formattedValue} ${symbol}`;
};

/**
 * Format a number with thousands separators
 * @param {number|string} value - The number to format
 * @param {number} decimals - Number of decimal places (default: 2)
 * @returns {string} Formatted number string
 */
export const formatNumber = (value, decimals = 2) => {
  if (value === null || value === undefined) return '0';

  const numericValue = typeof value === 'string' ? parseFloat(value) : value;

  if (isNaN(numericValue)) return '0';

  return numericValue.toLocaleString(undefined, {
    minimumFractionDigits: 0,
    maximumFractionDigits: decimals
  });
};

/**
 * Format a percentage value
 * @param {number|string} value - The percentage value (0-100)
 * @param {number} decimals - Number of decimal places (default: 1)
 * @returns {string} Formatted percentage string
 */
export const formatPercentage = (value, decimals = 1) => {
  if (value === null || value === undefined) return '0%';

  const numericValue = typeof value === 'string' ? parseFloat(value) : value;

  if (isNaN(numericValue)) return '0%';

  return `${numericValue.toFixed(decimals)}%`;
};

/**
 * Format price using US number format (standard for statements)
 * Used across ContactDetails, AccountStatement, and other financial views
 *
 * @param {number|string} value - The amount to format
 * @returns {string} Formatted price (e.g., "1,234.56")
 */
export const formatPrice = (value) => {
  if (value === null || value === undefined) return '0.00';
  const numericValue = typeof value === 'string' ? parseFloat(value) : value;
  if (isNaN(numericValue)) return '0.00';
  return new Intl.NumberFormat('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  }).format(numericValue);
};

/**
 * Alias for formatPrice (used in some components)
 * Maintains backward compatibility
 */
export const formatPriceEn = formatPrice;

export default {
  formatCurrency,
  formatNumber,
  formatPercentage,
  formatPrice,
  formatPriceEn
};
