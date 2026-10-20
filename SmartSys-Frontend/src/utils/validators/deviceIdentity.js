/**
 * POS Device Identity — Single Source of Truth
 * Multi-terminal safe (POS / cashier systems)
 */

const DEVICE_ID_KEY   = 'pos_device_id';
const DEVICE_NAME_KEY = 'pos_device_name';

/**
 * Generate stable random ID
 */
function generateDeviceId() {
  return (
    'dev-' +
    Math.random().toString(36).slice(2, 10) +
    '-' +
    Date.now().toString(36).slice(-6)
  );
}

/**
 * Get fallback device label from environment
 */
function getSystemFallbackName() {
  if (typeof navigator === 'undefined') return 'POS Device';

  const platform = navigator.platform || '';
  const ua = navigator.userAgent || '';

  const base =
    platform ||
    (ua.includes('Windows') && 'Windows Device') ||
    (ua.includes('Android') && 'Android Device') ||
    (ua.includes('iPhone') && 'iPhone Device') ||
    'POS Device';

  return base;
}

/**
 * Stable device identity (MAIN API)
 */
export function getDeviceIdentity() {
  let id = localStorage.getItem(DEVICE_ID_KEY);

  if (!id) {
    id = generateDeviceId();
    try {
      localStorage.setItem(DEVICE_ID_KEY, id);
    } catch {}
  }

  const custom = (localStorage.getItem(DEVICE_NAME_KEY) || '').trim();

  const baseName = custom || getSystemFallbackName();

  const name = baseName.slice(0, 64);

  return {
    device_id: id,
    device_name: name,
  };
}
