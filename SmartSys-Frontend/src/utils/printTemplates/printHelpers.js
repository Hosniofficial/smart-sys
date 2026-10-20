/**
 * printHelpers.js
 * ─────────────────────────────────────────────
 * Shared utilities used by all print templates
 */
import { getImageUrl } from '@/utils';

/**
 * Escape HTML to prevent XSS
 * Use on every user-supplied value before embedding in HTML
 */
export const esc = (s) =>
  String(s ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');

/**
 * Format a numeric value to 2 decimal places
 */
export const fmt = (v) => parseFloat(v || 0).toFixed(2);

/**
 * Get display data for the invoice header.
 *
 * Company data comes from localStorage (tenant-level).
 * If a `sale` object is passed and it contains branch fields
 * (branch_name, branch_phone, branch_location, branch_email),
 * those override the company contact info on the printed invoice.
 *
 * Hierarchy:
 *   Branch phone/address  → overrides company phone/address
 *   Company name          → always the company name (never overridden)
 *   branch_name           → shown as a sub-line below company name (branch only)
 *
 * @param {object|null} sale - sale object from API (optional)
 */
export const getCompanyData = (sale = null) => {
  const company = {
    name:    localStorage.getItem('pos_company_name')       || '',
    phone:   localStorage.getItem('pos_company_phone')      || '',
    tax:     localStorage.getItem('pos_company_tax_number') || '',
    logo:    getImageUrl(localStorage.getItem('pos_company_logo')) || '',
    address: localStorage.getItem('pos_company_address')    || '',
    website: localStorage.getItem('pos_company_website')    || '',
  };

  if (!sale) return { ...company, branch_name: null, branch_email: null };

  return {
    ...company,
    branch_name:  sale.branch_name  || null,
    branch_email: sale.branch_email || null,
    phone:        sale.branch_phone    || company.phone,
    address:      sale.branch_location || company.address,
  };
};

/**
 * Build a single item row — same logic for all templates
 * Uses backend net_total if available (respects item-level discounts),
 * falls back to quantity × price
 */
export const itemTotal = (it) =>
  parseFloat(it.net_total || it.total || (it.quantity * (it.sale_price || it.net_price || 0)) || 0);
