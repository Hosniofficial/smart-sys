/**
 * Error Handling Utilities
 * Provides safe error handling patterns for silent failures
 */

/**
 * Silently ignore errors from a function execution
 * Used in places where errors are non-critical and should not block execution
 *
 * @param {Function} fn - Function to execute
 * @param {*} fallback - Optional fallback value to return on error (default: undefined)
 * @returns {*} - Return value of fn, or fallback if an error occurs
 *
 * @example
 * // Instead of: try { fn(); } catch (_) {}
 * silentCatch(() => someFunction());
 */
export const silentCatch = (fn, fallback = undefined) => {
  try {
    return fn?.();
  } catch (_) {
    return fallback;
  }
};

/**
 * Silently ignore an async function execution
 *
 * @param {AsyncFunction} fn - Async function to execute
 * @param {*} fallback - Optional fallback value to return on error
 * @returns {Promise}
 *
 * @example
 * await silentCatchAsync(() => apiClient.get('/endpoint'));
 */
export const silentCatchAsync = async (fn, fallback = undefined) => {
  try {
    return await fn?.();
  } catch (_) {
    return fallback;
  }
};

export default {
  silentCatch,
  silentCatchAsync,
};
