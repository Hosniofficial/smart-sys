// ─── Configurable locale for date formatting ───────────────────────────────
const LOCALE = 'en-US';

// ─── Internal helper ────────────────────────────────────────────────────────
const pad = n => String(n).padStart(2, '0');

// ─── Local date/time formatting ─────────────────────────────────────────────

// Returns local date in YYYY-MM-DD format (avoids UTC toISOString date shift)
export function getLocalDateISO(d = new Date()) {
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

export function getLocalTimestamp(d = new Date()) {
  return [
    d.getFullYear(),
    pad(d.getMonth() + 1),
    pad(d.getDate()),
    pad(d.getHours()),
    pad(d.getMinutes()),
    pad(d.getSeconds()),
  ].join('-');
}

export default getLocalDateISO;

// ─── Local → UTC conversion ──────────────────────────────────────────────────

// Thin wrapper kept for naming clarity and future extensibility
export function toUTCISOString(d) {
  return d.toISOString();
}

// Convert a local date string (YYYY-MM-DD) to UTC ISO for start of day (local 00:00:00 → UTC)
export function localDateStartToUTCISO(dateStr) {
  if (!dateStr) return null;
  if (dateStr.includes('T') || dateStr.endsWith('Z')) return dateStr;

  const [y, m, d] = dateStr.split('-').map(Number);
  return toUTCISOString(new Date(y, m - 1, d, 0, 0, 0, 0));
}

// Convert a local date string (YYYY-MM-DD) to UTC ISO for end of day (local 23:59:59.999 → UTC)
export function localDateEndToUTCISO(dateStr) {
  if (!dateStr) return null;
  if (dateStr.includes('T') || dateStr.endsWith('Z')) return dateStr;

  const [y, m, d] = dateStr.split('-').map(Number);
  return toUTCISOString(new Date(y, m - 1, d, 23, 59, 59, 999)); // 999ms to cover full last second
}

export function localDateRangeToUTC(startDateStr, endDateStr) {
  return {
    startUtcIso: localDateStartToUTCISO(startDateStr),
    endUtcIso:   localDateEndToUTCISO(endDateStr),
  };
}

// ─── Display formatting ──────────────────────────────────────────────────────

// Format date and time for display
export function formatDateTime(dateString, options = {}) {
  if (!dateString) return '';
  const date = new Date(dateString);
  if (isNaN(date.getTime())) return dateString;

  const defaultOptions = {
    year:   'numeric',
    month:  '2-digit',
    day:    '2-digit',
    hour:   '2-digit',
    minute: '2-digit',
    hour12: false,
  };

  // Use Intl.DateTimeFormat to reliably render both date and time parts
  return new Intl.DateTimeFormat(LOCALE, { ...defaultOptions, ...options }).format(date);
}

// Format date only for display
export function formatDate(dateString, options = {}) {
  if (!dateString) return '';
  const date = new Date(dateString);
  if (isNaN(date.getTime())) return dateString;

  const defaultOptions = {
    year:  'numeric',
    month: '2-digit',
    day:   '2-digit',
  };

  return new Intl.DateTimeFormat(LOCALE, { ...defaultOptions, ...options }).format(date);
}
