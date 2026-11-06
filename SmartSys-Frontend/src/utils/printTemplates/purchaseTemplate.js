import { getPrintConfig, clearPrintConfigCache } from './printConfig';
import { esc, fmt, getCompanyData } from './printHelpers';

const itemPurchaseTotal = (it) =>
  parseFloat(it.netTotal || it.total || ((it.quantity || it.qty || 0) * (it.price || it.unitPrice || 0)) || 0);

/**
 * Thermal template for purchase invoices (76mm roll)
 */
const buildPurchaseThermalHtml = (doc, options = {}) => {
  const cfg     = getPrintConfig(options.config);
  const company = getCompanyData(null);
  const docDate = doc.invoiceDate || doc.createdAt || new Date().toISOString();
  const remaining = (parseFloat(doc.totalAmount) || 0) - (parseFloat(doc.paidAmount) || 0);

  const itemsHtml = (doc.items || []).map((it, idx) => `
    <div style="margin-bottom:8px;font-size:12px;">
      <div style="display:flex;justify-content:space-between;font-weight:bold;">
        <span>${idx + 1}. ${esc(it.productName || it.name)}</span>
        <span>${fmt(itemPurchaseTotal(it))}</span>
      </div>
      <div style="font-size:11px;color:#555;">
        ${fmt(it.quantity || it.qty)} × ${fmt(it.price || it.unitPrice || 0)}
      </div>
    </div>`).join('');

  return `<!doctype html><html dir="rtl"><head><meta charset="utf-8"/>
  <title>فاتورة شراء ${esc(doc.invoiceNumber || doc.id || '')}</title>
  <style>
    *{box-sizing:border-box;}
    body{font-family:Tahoma,Arial,sans-serif;margin:0;padding:0;background:#fff;}
    .receipt{width:76mm;margin:0 auto;padding:10px;}
    .text-center{text-align:center;}
    .header-title{font-size:18px;font-weight:bold;margin:5px 0;}
    .info-line{font-size:11px;color:#444;margin-bottom:2px;}
    .purchase-badge{background:#dbeafe;color:#1d4ed8;border:1px solid #93c5fd;border-radius:4px;padding:3px 8px;font-size:12px;font-weight:bold;display:inline-block;margin:6px 0;}
    .meta-grid{display:grid;grid-template-columns:1fr 1fr;border-top:1px dashed #000;border-bottom:1px dashed #000;padding:5px 0;margin:10px 0;font-size:11px;}
    .totals-area{border-top:1px solid #000;padding-top:5px;margin-top:10px;}
    .total-row{display:flex;justify-content:space-between;font-size:12px;margin-bottom:3px;}
    .grand-total{font-size:15px;font-weight:bold;border-top:1px dashed #000;padding-top:5px;margin-top:5px;}
    @media print{@page{size:80mm auto;margin:0;}body{width:76mm;}.receipt{width:76mm;padding:4mm;}}
  </style></head>
  <body>
  <div class="receipt">
    <div class="text-center">
      <div class="header-title">${esc(company.name || 'فاتورة شراء')}</div>
      <div class="info-line">
        ${company.phone ? `هاتف: ${esc(company.phone)} | ` : ''}
        ${company.tax ? `ر.ضريبي: ${esc(company.tax)}` : ''}
      </div>
      ${company.address ? `<div class="info-line">${esc(company.address)}</div>` : ''}
      <div class="purchase-badge">🛒 فاتورة شراء</div>
    </div>

    <div class="meta-grid">
      <div><strong>رقم:</strong> ${esc(doc.invoiceNumber || doc.id)}</div>
      <div style="text-align:left"><strong>التاريخ:</strong> ${new Date(docDate).toLocaleDateString('en-US')}</div>
      <div><strong>المورد:</strong> ${esc(doc.supplierName || doc.supplier?.name || '-')}</div>
      <div style="text-align:left"><strong>الفرع:</strong> ${esc(doc.branchName || '-')}</div>
    </div>

    ${cfg.headerEnabled && cfg.headerText
      ? `<div class="text-center" style="font-size:12px;margin-bottom:10px;">${esc(cfg.headerText)}</div>`
      : ''}

    <div>${itemsHtml}</div>

    <div class="totals-area">
      <div class="total-row"><span>الإجمالي:</span><span>${fmt(doc.totalAmount)}</span></div>
      <div class="total-row grand-total"><span>المدفوع:</span><span>${fmt(doc.paidAmount)}</span></div>
      ${remaining > 0.01 ? `<div class="total-row" style="color:#dc2626"><span>المتبقي:</span><span>${fmt(remaining)}</span></div>` : ''}
    </div>

    ${cfg.footerEnabled && cfg.footerText
      ? `<div class="text-center" style="font-size:12px;margin-top:15px;font-weight:bold;">${esc(cfg.footerText)}</div>`
      : ''}
    <div class="text-center" style="font-size:9px;color:#aaa;margin-top:15px;">Powered by POS System</div>
  </div>
  <script>window.onload=()=>{window.print();setTimeout(()=>{(${clearPrintConfigCache.toString()})();window.close();},500);};<\/script>
  </body></html>`;
};

/**
 * A4 template for purchase invoices
 */
const buildPurchaseA4Html = (doc, options = {}) => {
  const cfg     = getPrintConfig(options.config);
  const company = getCompanyData(null);
  const docDate = doc.invoiceDate || doc.createdAt || new Date().toISOString();
  const remaining = (parseFloat(doc.totalAmount) || 0) - (parseFloat(doc.paidAmount) || 0);

  const statusLabel = doc.status === 'paid' ? 'مدفوعة بالكامل'
    : doc.status === 'partial' ? 'مدفوعة جزئياً' : 'غير مدفوعة';
  const statusColor = doc.status === 'paid' ? '#16a34a' : doc.status === 'partial' ? '#d97706' : '#dc2626';

  const itemsHtml = (doc.items || []).map((it, idx) => `
    <tr>
      <td>${idx + 1}</td>
      <td>${esc(it.productName || it.name)}</td>
      <td style="text-align:center">${fmt(it.quantity || it.qty)}</td>
      <td style="text-align:center">${fmt(it.price || it.unitPrice || 0)}</td>
      <td style="text-align:center">${fmt(itemPurchaseTotal(it))}</td>
    </tr>`).join('');

  return `<!doctype html><html dir="rtl"><head><meta charset="utf-8"/>
  <title>فاتورة شراء ${esc(doc.invoiceNumber || doc.id || '')}</title>
  <style>
    *{box-sizing:border-box;}
    body{font-family:Tahoma,Arial,sans-serif;margin:0;padding:24px;color:#1e293b;direction:rtl;}
    .header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:24px;padding-bottom:16px;border-bottom:2px solid #1d4ed8;}
    .company-name{font-size:22px;font-weight:900;color:#1e293b;}
    .company-sub{font-size:12px;color:#64748b;margin-top:4px;}
    .purchase-badge{background:#dbeafe;color:#1d4ed8;border:2px solid #93c5fd;border-radius:8px;padding:8px 20px;font-size:20px;font-weight:900;text-align:center;}
    .invoice-no{font-size:13px;color:#1d4ed8;font-weight:bold;margin-top:6px;text-align:center;}
    .info-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:20px;}
    .info-box{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px;font-size:12px;}
    .info-box label{display:block;font-size:10px;color:#94a3b8;margin-bottom:4px;}
    table{width:100%;border-collapse:collapse;font-size:12px;margin-bottom:20px;}
    th{background:#dbeafe;padding:8px;border:1px solid #93c5fd;text-align:right;color:#1d4ed8;}
    td{padding:8px;border:1px solid #e2e8f0;}
    .totals{max-width:300px;margin-right:auto;font-size:13px;}
    .totals-row{display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #f1f5f9;}
    .totals-final{display:flex;justify-content:space-between;padding:10px 0;font-size:16px;font-weight:900;color:#1d4ed8;}
    @media print{@page{size:A4;margin:15mm;}body{padding:0;}}
  </style></head>
  <body>
    <div class="header">
      <div>
        <div class="company-name">${esc(company.name || '')}</div>
        <div class="company-sub">
          ${company.phone ? 'هاتف: ' + esc(company.phone) : ''}
          ${company.address ? ' | ' + esc(company.address) : ''}
          ${company.tax ? ' | ر.ضريبي: ' + esc(company.tax) : ''}
        </div>
      </div>
      <div>
        <div class="purchase-badge">🛒 فاتورة شراء</div>
        <div class="invoice-no">${esc(doc.invoiceNumber || '#' + doc.id)}</div>
      </div>
    </div>

    <div class="info-grid">
      <div class="info-box"><label>التاريخ</label>${new Date(docDate).toLocaleDateString('en-US')}</div>
      <div class="info-box"><label>المورد</label>${esc(doc.supplierName || doc.supplier?.name || '-')}</div>
      <div class="info-box"><label>الفرع</label>${esc(doc.branchName || '-')}</div>
      <div class="info-box"><label>الحالة</label><span style="color:${statusColor};font-weight:bold;">${statusLabel}</span></div>
      <div class="info-box"><label>المدفوع</label>${fmt(doc.paidAmount)}</div>
      <div class="info-box"><label>المتبقي</label><span style="color:${remaining > 0.01 ? '#dc2626' : '#16a34a'}">${fmt(remaining)}</span></div>
    </div>

    <table>
      <thead><tr><th>#</th><th>المنتج</th><th style="text-align:center">الكمية</th><th style="text-align:center">سعر الوحدة</th><th style="text-align:center">الإجمالي</th></tr></thead>
      <tbody>${itemsHtml}</tbody>
    </table>

    <div class="totals">
      <div class="totals-row"><span>إجمالي المبلغ:</span><span>${fmt(doc.totalAmount)}</span></div>
      <div class="totals-row"><span>المبلغ المدفوع:</span><span>${fmt(doc.paidAmount)}</span></div>
      <div class="totals-final"><span>الإجمالي النهائي:</span><span>${fmt(doc.totalAmount)}</span></div>
    </div>

    ${cfg.footerEnabled && cfg.footerText
      ? `<p style="text-align:center;margin-top:30px;font-size:13px;color:#64748b;">${esc(cfg.footerText)}</p>`
      : ''}
  <script>window.onload=()=>{window.print();setTimeout(()=>{(${clearPrintConfigCache.toString()})();window.close();},500);};<\/script>
  </body></html>`;
};

/**
 * Returns the appropriate builder based on template setting
 */
export const buildPurchaseHtml = (doc, options = {}) => {
  const t = String(options.template || localStorage.getItem('pos_print_template') || 'thermal-compact').toLowerCase();
  const isA4 = t === 'a4-simple' || t === 'a4-professional' || t === 'a4';
  return isA4 ? buildPurchaseA4Html(doc, options) : buildPurchaseThermalHtml(doc, options);
};
