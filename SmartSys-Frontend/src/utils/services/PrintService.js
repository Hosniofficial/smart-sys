/**
 * PrintService.js
 * ─────────────────────────────────────────────────────────────────
 * Unified print abstraction layer.
 *
 */

/**
 * Print an HTML document string.
 *
 * @param {string}      html       - Full HTML document to print
 * @param {Window|null} preOpened  - Pre-opened window (browser mode only)
 * @returns {Promise<Window|null>}
 */
export const printDocument = async (html, preOpened = null) => {
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

export default {
  printDocument,
};
