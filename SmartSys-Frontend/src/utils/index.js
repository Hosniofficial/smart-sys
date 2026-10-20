/**
 * Central utilities export point
 * Unified interface for all utility functions
 */

// Formatters
export {
  formatCurrency,
  formatNumber,
  formatPercentage,
  formatPrice,
  formatPriceEn,
  getLocalDateISO,
  getLocalTimestamp,
  toUTCISOString,
  localDateStartToUTCISO,
  localDateEndToUTCISO,
  localDateRangeToUTC,
  formatDateTime,
  formatDate,
  formatTransactionRef,
  getTransactionTypeLabel,
  getTransactionBadgeClass,
  getBalanceDisplay,
  getBalanceColorClass
} from './formatters/index.js';

// Helpers
export {
  getImageUrl,
  deviceFingerprint
} from './helpers/index.js';

// Services
export {
  PrintService,
  logger
} from './services/index.js';

// Re-export PrintService methods for convenience
export {
  getPrintMode,
  getQzPrinterName,
  setQzPrinterName,
  getAvailablePrinters,
  printDocument
} from './services/PrintService.js';

// Validators
export {
  silentCatch,
  silentCatchAsync,
  isSettingEnabled,
  getDeviceIdentity
} from './validators/index.js';

// Converters
export {
  downloadCSV
} from './converters/index.js';

// Global utilities
export { cleanParams } from './params.js';
