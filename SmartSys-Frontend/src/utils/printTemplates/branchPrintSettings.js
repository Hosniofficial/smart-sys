import { settingsService } from '@/services/settings';

const CACHE_TTL_MS = 30_000;
const cache = new Map();
const validTemplates = new Set([
  'thermal-compact',
  'thermal-detailed',
  'a4-simple',
  'a4-professional',
]);

const normalizeTemplate = (value) => {
  const template = String(value || 'thermal-compact').toLowerCase();
  const normalized = template === 'thermal' ? 'thermal-compact' : template === 'a4' ? 'a4-simple' : template;
  return validTemplates.has(normalized) ? normalized : 'thermal-compact';
};

const legacyOptions = () => ({
  template: normalizeTemplate(localStorage.getItem('pos_print_template')),
  config: null,
});

export const clearBranchPrintSettingsCache = (branchId = null) => {
  if (branchId === null) {
    cache.clear();
  } else {
    cache.delete(String(branchId));
  }
};

export const resolvePrintOptions = async (document, fallbackBranchId = null) => {
  const documentBranchId = document?.branchId
    ?? document?.sale?.branchId
    ?? document?.purchase?.branchId;
  const branchId = documentBranchId !== null && documentBranchId !== undefined && documentBranchId !== ''
    ? documentBranchId
    : fallbackBranchId;

  if (!branchId) return legacyOptions();

  const cacheKey = String(branchId);
  const cached = cache.get(cacheKey);
  if (cached && Date.now() - cached.fetchedAt < CACHE_TTL_MS) {
    return cached.options;
  }

  const response = await settingsService.getBranchPrintSettings(branchId);
  const payload = response?.data?.data ?? {};
  const settings = payload.settings;
  if (response?.data?.status !== 'success' || !settings || typeof settings !== 'object') {
    throw new Error(response?.data?.message || 'تعذر تحميل إعدادات الطباعة لهذا الفرع.');
  }

  const hasBranchTemplate = Array.isArray(payload.overrides)
    && payload.overrides.includes('print.template');
  const template = hasBranchTemplate || payload.tenantTemplateConfigured
    ? settings['print.template']
    : localStorage.getItem('pos_print_template');
  const options = {
    template: normalizeTemplate(template),
    config: {
      headerEnabled: String(settings['printer.print_header'] ?? '1') !== '0',
      footerEnabled: String(settings['printer.print_footer'] ?? '1') !== '0',
      headerText: String(settings['print.header_text'] ?? ''),
      footerText: String(settings['print.footer_text'] ?? 'شكراً لتعاملكم معنا'),
      termsText: String(settings['print.terms_text'] ?? ''),
    },
  };
  cache.set(cacheKey, { fetchedAt: Date.now(), options });
  return options;
};
