import { formatPrice } from './numbers.js';

/**
 * Format transaction reference/ID
 * Removes leading zeros and extracts numeric ID
 * 
 * @param {string} reference - The reference string (e.g., "sale#123")
 * @returns {string} Formatted reference
 */
export const formatTransactionRef = (reference) => {
  if (!reference) return '-';
  return String(reference).split('#').pop();
};

/**
 * Get type label for transaction (Arabic)
 * Unified mapping used in both statement views
 * 
 * @param {string} type - The transaction type
 * @returns {string} Localized label
 */
export const getTransactionTypeLabel = (type) => {
  if (!type) return 'حركة';
  const t = String(type).toLowerCase().trim();

  const typeMap = {
    'sale': 'فاتورة بيع',
    'sales': 'فاتورة بيع',
    'purchase': 'فاتورة شراء',
    'purchases': 'فاتورة شراء',
    'receipt': 'قبض',
    'payment': 'صرف',
    'refund': 'صرف',
    'sales_return': 'مرتجع بيع',
    'sale_return': 'مرتجع بيع',
    'return_sale': 'مرتجع بيع',
    'purchase_return': 'مرتجع شراء',
    'return_purchase': 'مرتجع شراء',
    'return': 'مرتجع',
    'discount': 'خصم',
    'tax': 'ضريبة',
    'cash_voucher': 'سند نقدي',
    'journal': 'قيد يومية',
    'return_payment': 'سداد مرتجع',
    'sales_return_refund': 'استرداد عميل',
    'purchase_return_refund': 'استرجاع مورد'
  };

  return typeMap[t] || t || 'حركة';
};

/**
 * Get CSS badge class for transaction status
 * Used to color-code transactions in tables
 * 
 * @param {string} type - The transaction type
 * @returns {string} CSS class
 */
export const getTransactionBadgeClass = (type) => {
  const t = String(type || '').toLowerCase();

  const statusMap = {
    'closed_by_return': 'bg-indigo-50 text-indigo-700 border border-indigo-200',
    'settled_by_return': 'bg-teal-50 text-teal-700 border border-teal-200',
    'settled_by_credit': 'bg-cyan-50 text-cyan-700 border border-cyan-200',
    'settled_mixed': 'bg-purple-50 text-purple-700 border border-purple-200',
    'returned': 'bg-indigo-50 text-indigo-700 border border-indigo-200',
    'paid': 'bg-emerald-50 text-emerald-700 border border-emerald-200',
    'partial': 'bg-amber-50 text-amber-700 border border-amber-200',
    'unpaid': 'bg-rose-50 text-rose-700 border border-rose-200'
  };

  if (statusMap[t]) return statusMap[t];

  if (['sale', 'sales', 'purchase', 'purchases'].includes(t)) {
    return 'bg-rose-50 text-rose-700 border border-rose-200';
  }
  if (['receipt', 'refund', 'payment'].includes(t)) {
    return 'bg-emerald-50 text-emerald-700 border border-emerald-200';
  }
  if (['sales_return', 'return_sale', 'purchase_return', 'return_purchase', 'return'].includes(t)) {
    return 'bg-blue-50 text-blue-700 border border-blue-200';
  }
  if (['journal', 'cash_voucher'].includes(t)) {
    return 'bg-amber-50 text-amber-700 border border-amber-200';
  }

  return 'bg-slate-50 text-slate-700 border border-slate-200';
};

/**
 * Get balance display with nature indicator (مدين/دائن)
 * Used in AccountStatement balance column
 * 
 * @param {number} balance - The balance amount
 * @param {string} nature - The balance nature ('debit', 'credit', 'zero')
 * @returns {string} Formatted balance with indicator
 */
export const getBalanceDisplay = (balance, nature) => {
  if (!balance && balance !== 0) return '-';
  const formatted = formatPrice(Math.abs(balance));

  if (nature === 'debit') return `${formatted} مدين`;
  if (nature === 'credit') return `${formatted} دائن`;
  return formatted;
};

/**
 * Get CSS color class for balance display
 * Indicates debit (blue), credit (amber), or neutral (gray)
 * 
 * @param {string} nature - The balance nature
 * @returns {string} CSS class
 */
export const getBalanceColorClass = (nature) => {
  const n = String(nature || 'zero').toLowerCase();
  if (n === 'debit') return 'text-blue-700 font-bold';
  if (n === 'credit') return 'text-amber-700 font-bold';
  return 'text-slate-600';
};

export default {
  formatTransactionRef,
  getTransactionTypeLabel,
  getTransactionBadgeClass,
  getBalanceDisplay,
  getBalanceColorClass
};
