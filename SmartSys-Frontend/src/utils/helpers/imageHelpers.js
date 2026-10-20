/**
 * imageHelpers.js
 * ─────────────────────────────────────────────
 * Centralized image URL builder for dev + production
 */

const API_BASE = import.meta.env.VITE_API_TARGET || import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000';

/**
 * Build full image URL from path
 * @param {string|null} path - relative path or full URL
 * @returns {string|null} full URL or null
 */
export const getImageUrl = (path) => {
  if (!path) return null;

  if (path.startsWith('http')) return path;

  const base = API_BASE;

  let cleanPath = path.trim();

  // Clean all leading uploads/ duplicates → single uploads/
  cleanPath = cleanPath.replace(/^\/?(uploads\/)+/i, 'uploads/');

  // Ensure starts with /uploads/
  cleanPath = '/' + cleanPath;

  return `${base}${cleanPath}`;
};

export default { getImageUrl };
