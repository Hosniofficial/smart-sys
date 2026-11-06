// Configurable locale for date formatting
const LOCALE = 'en-US';

// Internal helper
const pad = n => String(n).padStart(2, '0');

// Local date/time formatting

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

// Local → UTC conversion

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

// Display formatting

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
    hour12: true,
  };

  // Use Intl.DateTimeFormat to reliably render both date and time parts
  return new Intl.DateTimeFormat(LOCALE, { ...defaultOptions, ...options }).format(date);
}

// Format date only for display
export function formatDate(dateString, options = {}) {
  return formatDateForLocale(dateString, LOCALE, options);
}

export function formatDateForLocale(dateString, locale = LOCALE, options = {}) {
  if (!dateString) return '';
  const date = new Date(dateString);
  if (isNaN(date.getTime())) return dateString;

  const defaultOptions = {
    year:  'numeric',
    month: '2-digit',
    day:   '2-digit',
  };

  return new Intl.DateTimeFormat(locale, { ...defaultOptions, ...options }).format(date);
}

export function formatDateUsingNativeLocale(value, locale, options) {
  return value ? new Date(value).toLocaleDateString(locale, options) : '';
}

export function formatTimeUsingNativeLocale(value, locale, options) {
  return value ? new Date(value).toLocaleTimeString(locale, options) : '';
}

export function formatDateTimeUsingNativeLocale(value, locale, options) {
  return value ? new Date(value).toLocaleString(locale, options) : '';
}

export function formatDateTimeWithSqlSpace(value, options) {
  if (!value) return '';
  const parsed = new Date(String(value).replace(' ', 'T'));
  return Number.isNaN(parsed.getTime())
    ? String(value)
    : parsed.toLocaleString('en-US', options);
}

export function formatReturnDateTime(returnDate, createdAt) {
  const dateValue = String(returnDate || createdAt || '');
  if (!dateValue) return '';
  const datePart = dateValue.slice(0, 10);
  const directTime = dateValue.match(/[T ](\d{2}:\d{2}(?::\d{2})?)/)?.[1];
  const createdTime = String(createdAt || '').match(/[T ](\d{2}:\d{2}(?::\d{2})?)/)?.[1];
  const time = directTime || createdTime;
  const dateOptions = { day: '2-digit', month: '2-digit', year: 'numeric' };
  if (!time) return formatDateUsingNativeLocale(datePart, 'en-US', dateOptions);
  const normalizedTime = time.length === 5 ? `${time}:00` : time;
  const date = new Date(`${datePart}T${normalizedTime}`);
  return Number.isNaN(date.getTime())
    ? formatDateUsingNativeLocale(datePart, 'en-US', dateOptions)
    : date.toLocaleString('en-US', {
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      hour12: true
    });
}

export function formatLocalDateDayEnUS(value) {
  if (!value) return '-';
  const date = new Date(`${String(value).slice(0, 10)}T00:00:00`);
  return Number.isNaN(date.getTime()) ? '-' : date.toLocaleDateString('en-US');
}
