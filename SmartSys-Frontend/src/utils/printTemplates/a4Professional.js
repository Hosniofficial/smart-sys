import { getPrintConfig } from './printConfig';
import { esc, fmt, itemTotal, getCompanyData } from './printHelpers';
import { getLocalDateISO } from '@/utils';

export const buildA4ProfessionalHtml = (sale) => {
  const cfg     = getPrintConfig();
  const company = getCompanyData(sale);

  const qrRaw = `INV:${sale.invoice_number || sale.id}|TOTAL:${fmt(sale.net_total_amount)}|DATE:${sale.sale_date || getLocalDateISO()}`;

  const rows = (sale.items || []).map((it, idx) => `
    <tr>
      <td style='border:1px solid #ddd; padding:6px'>${idx + 1}</td>
      <td style='border:1px solid #ddd; padding:6px'>${esc(it.product_name || it.name)}</td>
      <td style='border:1px solid #ddd; padding:6px; text-align:center'>${fmt(it.quantity)}</td>
      <td style='border:1px solid #ddd; padding:6px; text-align:right'>${fmt(it.sale_price || it.net_price)}</td>
      <td style='border:1px solid #ddd; padding:6px; text-align:right'>${fmt(itemTotal(it))}</td>
    </tr>`).join('');

  const saleDate = sale.sale_date || getLocalDateISO();

  return `<!doctype html><html dir="rtl"><head><meta charset="utf-8"/>
  <title>فاتورة ${esc(sale.invoice_number || '')}</title>

  <!-- QRCode.js (davidshimjs — MIT) -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"><\/script>

  <style>
    body { font-family: Tahoma, Arial, sans-serif; padding: 24px; }
    .header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 16px; }
    .brand { display: flex; align-items: center; gap: 12px; }
    .brand img { height: 64px; }
    .company h2 { margin: 0 0 4px; }
    .company .sub { font-size: 13px; color: #444; line-height: 1.6; }
    .meta { display: flex; justify-content: space-between; margin: 10px 0; font-size: 13px; }
    table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .totals { margin-top: 10px; display: flex; gap: 20px; justify-content: flex-end; font-size: 13px; }
    .footer { margin-top: 16px; text-align: center; font-size: 13px; }
    .qr-box { width: 128px; height: 128px; }
    .section { margin-top: 12px; }
    .terms { font-size: 12px; line-height: 1.6; }
    @media print { @page { size: A4; margin: 15mm; } }
  </style></head><body>

  <div class="header">
    <div class="brand">
      ${company.logo ? `<img src="${esc(company.logo)}" alt="logo"/>` : ''}
      <div class="company">
        <h2>${esc(company.name) || 'فاتورة مبيعات'}</h2>
          ${company.branch_name ? `<div style="font-size:12px; color:#666; margin-top:2px;">${esc(company.branch_name)}</div>` : ''}
        <div class="sub">
          ${company.phone   ? `<div>هاتف: ${esc(company.phone)}</div>`               : ''}
          ${company.address ? `<div>${esc(company.address)}</div>`                   : ''}
          ${company.tax     ? `<div>الرقم الضريبي: ${esc(company.tax)}</div>` : ''}
          ${company.website ? `<div>${esc(company.website)}</div>`                   : ''}
        </div>
      </div>
    </div>
    <!-- QR Code يتولد بالـ JS أدناه -->
    <div id="qr-container" class="qr-box"></div>
  </div>

  <div class="meta">
    <div>رقم الفاتورة: <strong>#${esc(sale.invoice_number || sale.id)}</strong></div>
    <div>تاريخ: ${new Date(saleDate).toLocaleString('en-US')}</div>
    <div>العميل: ${esc(sale.customer_name || 'عميل نقدي')}</div>
  </div>

  ${cfg.headerEnabled && cfg.headerText
    ? `<div class="section"><div style='text-align:center'>${esc(cfg.headerText)}</div></div>`
    : ''}

  <table>
    <thead>
      <tr>
        <th style='border:1px solid #ddd; padding:6px'>#</th>
        <th style='border:1px solid #ddd; padding:6px'>الصنف</th>
        <th style='border:1px solid #ddd; padding:6px'>الكمية</th>
        <th style='border:1px solid #ddd; padding:6px'>السعر</th>
        <th style='border:1px solid #ddd; padding:6px'>الإجمالي</th>
      </tr>
    </thead>
    <tbody>${rows}</tbody>
  </table>

  <div class="totals">
    <div><strong>الإجمالي:</strong> ${fmt(sale.total_amount)}</div>
    <div><strong>الخصم:</strong> ${fmt(sale.discount_value)}</div>
    <div><strong>الضريبة:</strong> ${fmt(sale.tax_amount)}</div>
    <div><strong>الصافي:</strong> ${fmt(sale.net_total_amount)}</div>
    <div><strong>المدفوع:</strong> ${fmt(sale.paid_amount)}</div>
  </div>

  ${cfg.footerEnabled && cfg.footerText
    ? `<div class="footer">${esc(cfg.footerText)}</div>`
    : ''}
  ${cfg.termsText
    ? `<div class="section">
         <strong>الشروط والأحكام</strong>
         <div class="terms">${esc(cfg.termsText).replace(/\n/g, '<br/>')}</div>
       </div>`
    : ''}

  <script>
    window.onload = () => {
      const container = document.getElementById('qr-container');
      if (container && window.QRCode) {
        new QRCode(container, {
          text:          ${JSON.stringify(qrRaw)},
          width:         128,
          height:        128,
          correctLevel:  QRCode.CorrectLevel.M,
        });
      }
      // انتظر رسم الـ QR قبل الطباعة
      setTimeout(() => {
        window.print();
        setTimeout(() => window.close(), 300);
      }, 400);
    };
  <\/script>
  </body></html>`;
};