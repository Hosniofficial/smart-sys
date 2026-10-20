/**
 * PrintService.js
 * ─────────────────────────────────────────────────────────────────
 * Unified print abstraction layer.
 *
 * Modes:
 *   browser  — window.open() + window.print()  (default)
 *   qztray   — QZ Tray WebSocket bridge (direct hardware printing)
 *
 * Mode is controlled by `print.mode` in localStorage / DB settings.
 * Printer name for QZ Tray is controlled by `print.qztray_printer`.
 */

import qz from 'qz-tray';

const MODE_KEY    = 'print.mode';
const PRINTER_KEY = 'print.qztray_printer';

// ─── Public API ──────────────────────────────────────────────────────────────

/** @returns {'browser'|'qztray'} */
export const getPrintMode = () => {
  if (import.meta.env.PROD) return 'browser';
  const val = localStorage.getItem(MODE_KEY) || 'browser';
  return val === 'qztray' ? 'qztray' : 'browser';
};

/** @returns {string} stored printer name or empty string */
export const getQzPrinterName = () => localStorage.getItem(PRINTER_KEY) || '';

/** Persist QZ Tray printer name */
export const setQzPrinterName = (name) => {
  localStorage.setItem(PRINTER_KEY, name || '');
};

/**
 * Get list of available printers from QZ Tray.
 * Connects, fetches printers, disconnects.
 * @returns {Promise<string[]>}
 */
export const getAvailablePrinters = async () => {
  _assertQzTrayIsDevelopmentOnly();
  await _ensureConnected();
  const printers = await qz.printers.find();
  return Array.isArray(printers) ? printers : [printers];
};

/**
 * Print an HTML document string.
 *
 * @param {string}      html       - Full HTML document to print
 * @param {Window|null} preOpened  - Pre-opened window (browser mode only)
 * @returns {Promise<Window|null>}
 */
export const printDocument = async (html, preOpened = null) => {
  if (getPrintMode() === 'qztray') {
    await _printQzTray(html);
    if (preOpened) try { preOpened.close(); } catch (_) {}
    return null;
  }
  return _printBrowser(html, preOpened);
};

// ─── Browser mode ────────────────────────────────────────────────────────────

const _printBrowser = (html, preOpened = null) => {
  const w = preOpened || window.open('', '_blank');
  if (!w) {
    console.error('[PrintService] Could not open print window — check popup blocker settings.');
    return null;
  }
  try {
    w.document.open();
    w.document.write(html);
    w.document.close();
  } catch (e) {
    console.error('[PrintService] Error writing to print window:', e);
  }
  return w;
};

// ─── QZ Tray mode ────────────────────────────────────────────────────────────

let _connected = false;

/** Ensure QZ Tray WebSocket is connected (idempotent) */
const _ensureConnected = async () => {
  if (_connected && qz.websocket.isActive()) return;

  _setupSecurity();

  await qz.websocket.connect();
  _connected = true;

  qz.websocket.setClosedCallbacks(() => { _connected = false; });
};

/**
 * Unsigned mode is development-only. Production must integrate QZ's trusted
 * certificate and server-side message signing before enabling direct printing.
 * See: https://qz.io/docs/signing
 */
const _assertQzTrayIsDevelopmentOnly = () => {
  if (import.meta.env.PROD) {
    throw new Error('الطباعة المباشرة عبر QZ Tray متوقفة في الإنتاج حتى استكمال تكامل شهادة موثوقة وموقّع خادمي. استخدم طباعة المتصفح حاليًا.');
  }
};

const _setupSecurity = () => {
  _assertQzTrayIsDevelopmentOnly();
  qz.security.setCertificatePromise((resolve) => resolve(''));
  qz.security.setSignatureAlgorithm('SHA512');
  qz.security.setSignaturePromise(() => (resolve) => resolve(''));
};

const _printQzTray = async (html) => {
  try {
    await _ensureConnected();

    const printerName = getQzPrinterName() || await qz.printers.getDefault();

    if (!printerName) {
      throw new Error('لم يتم تحديد طابعة. يرجى اختيار طابعة من إعدادات الطباعة.');
    }

    const config = qz.configs.create(printerName);

    const data = [{
      type:   'html',
      format: 'plain',
      data:   html,
    }];

    await qz.print(config, data);
    console.info(`[PrintService] QZ Tray: printed to "${printerName}"`);
  } catch (e) {
    console.error('[PrintService] QZ Tray print failed:', e);
    throw e;
  }
};

export default {
  getPrintMode,
  getQzPrinterName,
  setQzPrinterName,
  getAvailablePrinters,
  printDocument,
};
