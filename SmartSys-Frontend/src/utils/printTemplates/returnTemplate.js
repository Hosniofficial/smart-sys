import { getPrintConfig, clearPrintConfigCache } from './printConfig';
import { esc, fmt, getCompanyData } from './printHelpers';

const itemReturnTotal = (it) =>
  parseFloat(it.subtotal ?? it.netTotal ?? it.total ?? (it.quantity * (it.unitPrice || it.salePrice || 0)) ?? 0);

const isPurchaseReturn = (doc) => String(doc.returnType || '').toLowerCase() === 'purchase';
const returnTypeLabel = (doc) => doc.returnTypeLabel || (isPurchaseReturn(doc) ? 'مرتجع مشتريات' : 'مرتجع مبيعات');
const returnPartyLabel = (doc) => isPurchaseReturn(doc) ? 'المورد' : 'العميل';
const returnPartyName = (doc) => doc.partyName || (isPurchaseReturn(doc) ? doc.supplierName : doc.customerName) || (isPurchaseReturn(doc) ? 'غير محدد' : 'عميل نقدي');
const returnSettlementRows = (doc, rowClass) => {
  const cashRefund = Math.max(0, Number(doc.cashRefundAmount ?? doc.paidAmount ?? 0));
  const offset = Math.max(0, Number(doc.offsetAmount ?? 0));
  const net = Number(doc.netSettlementAmount ?? cashRefund + offset);
  const cashLabel = Number(doc.isCash) === 1 ? 'المبلغ المسترد نقدًا:' : 'المبلغ المسترد:';
  return `${offset > 0 ? `<div class="${rowClass}"><span>المبلغ المُقاصّ:</span><span>${fmt(offset)}</span></div>` : ''}
    ${cashRefund > 0 ? `<div class="${rowClass}"><span>${cashLabel}</span><span>${fmt(cashRefund)}</span></div>` : ''}
    <div class="${rowClass}"><span>صافي التسوية:</span><span>${fmt(net)}</span></div>`;
};
const returnTaxRow = (doc, rowClass) => Number(doc.taxAmount) > 0
  ? `<div class="${rowClass}"><span>الضريبة (ضمن إجمالي المرتجع):</span><span>${fmt(doc.taxAmount)}</span></div>`
  : '';

/**
 * Thermal template for return documents (76mm roll)
 */
const buildReturnThermalHtml = (doc, options = {}) => {
  const cfg     = getPrintConfig(options.config);
  const company = getCompanyData(doc);
  const returnDate = doc.returnDate || doc.createdAt || new Date().toISOString();
  const createdAt = doc.createdAt || returnDate;

  const itemsHtml = (doc.items || []).map((it, idx) => `
    <div style="margin-bottom:8px;font-size:12px;">
      <div style="display:flex;justify-content:space-between;font-weight:bold;">
        <span>${idx + 1}. ${esc(it.productName || it.name)}</span>
        <span>${fmt(itemReturnTotal(it))}</span>
      </div>
      <div style="font-size:11px;color:#555;">
        ${fmt(it.quantity)} × ${fmt(it.unitPrice || it.salePrice || 0)}
      </div>
    </div>`).join('');

  return `<!doctype html><html dir="rtl"><head><meta charset="utf-8"/>
  <title>إشعار ${esc(returnTypeLabel(doc))} ${esc(doc.returnNumber || doc.id || '')}</title>
  <style>
    *{box-sizing:border-box;}
    body{font-family:Tahoma,Arial,sans-serif;margin:0;padding:0;background:#fff;}
    .receipt{width:76mm;margin:0 auto;padding:10px;}
    .text-center{text-align:center;}
    .header-title{font-size:18px;font-weight:bold;margin:5px 0;}
    .info-line{font-size:11px;color:#444;margin-bottom:2px;}
    .return-badge{background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;border-radius:4px;padding:3px 8px;font-size:12px;font-weight:bold;display:inline-block;margin:6px 0;}
    .meta-grid{display:grid;grid-template-columns:1fr 1fr;border-top:1px dashed #000;border-bottom:1px dashed #000;padding:5px 0;margin:10px 0;font-size:11px;}
    .totals-area{border-top:1px solid #000;padding-top:5px;margin-top:10px;}
    .total-row{display:flex;justify-content:space-between;font-size:12px;margin-bottom:3px;}
    .grand-total{font-size:15px;font-weight:bold;border-top:1px dashed #000;padding-top:5px;margin-top:5px;}
    @media print{@page{size:80mm auto;margin:0;}body{width:76mm;}.receipt{width:76mm;padding:4mm;}}
  </style></head>
  <body>
  <div class="receipt">
    <div class="text-center">
      <div class="header-title">${esc(company.name || 'وثيقة مرتجع')}</div>
      ${company.branchName ? `<div class="info-line">${esc(company.branchName)}</div>` : ''}
      <div class="info-line">
        ${company.phone ? `هاتف: ${esc(company.phone)} | ` : ''}
        ${company.tax ? `ر.ضريبي: ${esc(company.tax)}` : ''}
      </div>
      ${company.address ? `<div class="info-line">${esc(company.address)}</div>` : ''}
      <div class="return-badge">↩ إشعار ${esc(returnTypeLabel(doc))}</div>
    </div>

    <div class="meta-grid">
      <div><strong>رقم المرتجع:</strong> ${esc(doc.returnNumber || doc.id)}</div>
      <div><strong>تاريخ المرتجع:</strong> ${new Date(returnDate).toLocaleDateString('en-US')}</div>
      <div><strong>الفاتورة الأصلية:</strong> ${esc(doc.originalInvoiceNumber || doc.invoiceNumber || 'غير محدد')}</div>
      <div><strong>${returnPartyLabel(doc)}:</strong> ${esc(returnPartyName(doc))}</div>
      <div><strong>أنشأه:</strong> ${esc(doc.createdByName || 'النظام')}</div>
      <div><strong>تاريخ ووقت الإنشاء:</strong> ${new Date(createdAt).toLocaleString('en-US')}</div>
    </div>

    ${cfg.headerEnabled && cfg.headerText
      ? `<div class="text-center" style="font-size:12px;margin-bottom:10px;">${esc(cfg.headerText)}</div>`
      : ''}

    <div style="font-size:10px;color:#666;margin:6px 0;">مبالغ الأصناف قبل الضريبة؛ إجمالي المرتجع أدناه هو المبلغ النهائي شامل الضريبة.</div>
    <div>${itemsHtml}</div>

    <div class="totals-area">
      <div class="total-row"><span>إجمالي المرتجع (شامل الضريبة):</span><span>${fmt(doc.grandTotal || doc.totalAmount)}</span></div>
      ${returnTaxRow(doc, 'total-row')}
      ${returnSettlementRows(doc, 'total-row')}
      ${(doc.notes || doc.returnReason) ? `<div class="total-row" style="color:#666;font-size:11px;"><span>سبب الإرجاع:</span><span>${esc(doc.notes || doc.returnReason)}</span></div>` : ''}
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
 * A4 template for return documents
 */
const buildReturnA4Html = (doc, options = {}) => {
  const cfg     = getPrintConfig(options.config);
  const company = getCompanyData(doc);
  const returnDate = doc.returnDate || doc.createdAt || new Date().toISOString();
  const createdAt = doc.createdAt || returnDate;

  const itemsHtml = (doc.items || []).map((it, idx) => `
    <tr>
      <td>${idx + 1}</td>
      <td>${esc(it.productName || it.name)}</td>
      <td style="text-align:center">${fmt(it.quantity)}</td>
      <td style="text-align:center">${fmt(it.unitPrice || it.salePrice || 0)}</td>
      <td style="text-align:center">${fmt(itemReturnTotal(it))}</td>
    </tr>`).join('');

  return `<!doctype html><html dir="rtl"><head><meta charset="utf-8"/>
  <title>إشعار ${esc(returnTypeLabel(doc))} ${esc(doc.returnNumber || doc.id || '')}</title>
  <style>
    *{box-sizing:border-box;}
    body{font-family:Tahoma,Arial,sans-serif;margin:0;padding:24px;color:#1e293b;direction:rtl;}
    .header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:24px;padding-bottom:16px;border-bottom:2px solid #dc2626;}
    .company-name{font-size:22px;font-weight:900;color:#1e293b;}
    .company-sub{font-size:12px;color:#64748b;margin-top:4px;}
    .return-badge{background:#fee2e2;color:#dc2626;border:2px solid #fca5a5;border-radius:8px;padding:8px 20px;font-size:20px;font-weight:900;text-align:center;}
    .return-no{font-size:13px;color:#dc2626;font-weight:bold;margin-top:6px;text-align:center;}
    .info-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:20px;}
    .info-box{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px;font-size:12px;}
    .info-box label{display:block;font-size:10px;color:#94a3b8;margin-bottom:4px;}
    table{width:100%;border-collapse:collapse;font-size:12px;margin-bottom:20px;}
    th{background:#fee2e2;padding:8px;border:1px solid #fca5a5;text-align:right;color:#dc2626;}
    td{padding:8px;border:1px solid #e2e8f0;}
    .totals{max-width:300px;margin-right:auto;font-size:13px;}
    .totals-row{display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #f1f5f9;}
    .totals-final{display:flex;justify-content:space-between;padding:10px 0;font-size:16px;font-weight:900;color:#dc2626;}
    @media print{@page{size:A4;margin:15mm;}body{padding:0;}}
  </style></head>
  <body>
    <div class="header">
      <div>
        <div class="company-name">${esc(company.name || '')}</div>
        <div class="company-sub">
          ${company.branchName ? esc(company.branchName) + ' | ' : ''}
          ${company.phone ? 'هاتف: ' + esc(company.phone) : ''}
          ${company.address ? ' | ' + esc(company.address) : ''}
        </div>
      </div>
      <div>
        <div class="return-badge">↩ إشعار ${esc(returnTypeLabel(doc))}</div>
        <div class="return-no">${esc(doc.returnNumber || 'RET-' + doc.id)}</div>
      </div>
    </div>

    <div class="info-grid">
      <div class="info-box"><label>تاريخ المرتجع</label>${new Date(returnDate).toLocaleDateString('en-US')}</div>
      <div class="info-box"><label>الفاتورة الأصلية</label>${esc(doc.originalInvoiceNumber || doc.invoiceNumber || 'غير محدد')}</div>
      <div class="info-box"><label>${returnPartyLabel(doc)}</label>${esc(returnPartyName(doc))}</div>
      <div class="info-box"><label>الفرع</label>${esc(doc.branchName || '-')}</div>
      <div class="info-box"><label>أنشأه</label>${esc(doc.createdByName || 'النظام')}</div>
      <div class="info-box"><label>تاريخ ووقت الإنشاء</label>${new Date(createdAt).toLocaleString('en-US')}</div>
    </div>

    <p style="font-size:11px;color:#64748b;">مبالغ الأصناف قبل الضريبة؛ إجمالي المرتجع أدناه هو المبلغ النهائي شامل الضريبة.</p>
    <table>
      <thead><tr><th>#</th><th>المنتج</th><th style="text-align:center">الكمية</th><th style="text-align:center">سعر الوحدة قبل الضريبة</th><th style="text-align:center">صافي الصنف قبل الضريبة</th></tr></thead>
      <tbody>${itemsHtml}</tbody>
    </table>

    <div class="totals">
      <div class="totals-row"><span>إجمالي المرتجع (شامل الضريبة):</span><span>${fmt(doc.grandTotal || doc.totalAmount)}</span></div>
      ${returnTaxRow(doc, 'totals-row')}
      ${returnSettlementRows(doc, 'totals-row')}
      ${(doc.notes || doc.returnReason) ? `<div class="totals-row" style="color:#666"><span>سبب الإرجاع:</span><span>${esc(doc.notes || doc.returnReason)}</span></div>` : ''}
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
export const buildReturnHtml = (doc, options = {}) => {
  const t = String(options.template || localStorage.getItem('pos_print_template') || 'thermal-compact').toLowerCase();
  const isA4 = t === 'a4-simple' || t === 'a4-professional' || t === 'a4';
  return isA4 ? buildReturnA4Html(doc, options) : buildReturnThermalHtml(doc, options);
};
