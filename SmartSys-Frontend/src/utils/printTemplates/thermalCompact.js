import { getPrintConfig } from './printConfig';
import { esc, fmt, itemTotal, getCompanyData } from './printHelpers';
import { getLocalDateISO } from '@/utils';

export const buildThermalCompactHtml = (sale) => {
  const cfg     = getPrintConfig();
  const company = getCompanyData(sale);
  const saleDate = sale.sale_date || sale.created_at || getLocalDateISO();
  const taxRate  = parseFloat(sale.tax_rate ?? 0);

  // ─── Items ───────────────────────────────────────────────────────────────
  const itemsHtml = (sale.items || []).map((it) => `
    <div style="padding:5px 0;border-bottom:0.3mm dashed #ccc;">
      <div style="display:flex;justify-content:space-between;font-weight:700;font-size:13px;">
        <span style="flex:1;">${esc(it.product_name || it.name)}</span>
        <span style="white-space:nowrap;margin-right:6px;">${fmt(itemTotal(it))}</span>
      </div>
      <div style="font-size:11px;color:#555;margin-top:2px;">
        ${fmt(it.quantity)} ${esc(it.unit || '')} × ${fmt(it.sale_price || it.net_price || 0)}
      </div>
    </div>`).join('');

  const d = new Date(saleDate);
  const dateStr = d.toLocaleDateString('en-US');
  const timeStr = d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
  const paymentName = sale.payment_method_name || sale.payment_method || 'نقداً';

  // ─── Discount: handle both 'percentage' and 'fixed' stored values ───────
  const rawDiscount  = parseFloat(sale.discount_value) || 0;
  const discountAmt  = sale.discount_type === 'percentage'
    ? rawDiscount / 100 * (parseFloat(sale.total_amount) || 0)
    : rawDiscount;
  const discountLabel = sale.discount_type === 'percentage' && rawDiscount > 0
    ? `الخصم (${rawDiscount}%):`
    : 'الخصم (-):';  

  return `<!doctype html>
<html dir="rtl">
<head>
  <meta charset="utf-8"/>
  <title>فاتورة ${esc(sale.invoice_number || sale.id || '')}</title>
  <style>
    *{box-sizing:border-box;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    body{font-family:Tahoma,Arial,sans-serif;margin:0;padding:0;background:#fff;color:#111;font-size:12px;line-height:1.4;}
    .receipt{width:76mm;margin:0 auto;padding:5mm 3mm;}
    .tc{text-align:center;}
    .flex{display:flex;justify-content:space-between;align-items:center;}
    .bold{font-weight:bold;}
    .hr-solid{border:none;border-top:1.5px solid #000;margin:8px 0;}
    .hr-dash{border:none;border-top:1px dashed #888;margin:6px 0;}
    .meta-row{display:flex;justify-content:space-between;font-size:11px;padding:2px 0;}
    .grand-box{display:flex;justify-content:space-between;align-items:center;background:#111;color:#fff;padding:7px 8px;margin:8px 0 5px;border-radius:2px;}
    .grand-box .label{font-size:13px;font-weight:bold;}
    .grand-box .value{font-size:20px;font-weight:900;}
    @media print{@page{size:80mm auto;margin:0;}body,html{width:76mm;}.receipt{padding:2mm 2mm;}}
  </style>
</head>
<body>
<div class="receipt">

  <!-- ① الهيدر: لوجو + اسم الشركة -->
  <div class="tc" style="margin-bottom:10px;">
    ${company.logo ? `<img src="${esc(company.logo)}" style="max-height:55px;max-width:60mm;object-fit:contain;margin-bottom:6px;display:block;margin-left:auto;margin-right:auto;" />` : ''}
    <div style="font-size:20px;font-weight:900;letter-spacing:1px;">${esc(company.name || 'فاتورة مبيعات')}</div>
    ${company.branch_name ? `<div style="font-size:11px;color:#444;">${esc(company.branch_name)}</div>` : ''}
    ${company.address     ? `<div style="font-size:11px;color:#555;">${esc(company.address)}</div>` : ''}
    ${company.phone       ? `<div style="font-size:11px;">هاتف: ${esc(company.phone)}</div>` : ''}
    ${company.tax         ? `<div style="font-size:10px;font-weight:bold;margin-top:3px;">الرقم الضريبي: ${esc(company.tax)}</div>` : ''}
  </div>

  <hr class="hr-solid"/>

  <!-- ② بيانات الفاتورة -->
  <div class="meta-row"><span>رقم الفاتورة:</span><span class="bold">#${esc(String(sale.invoice_number || sale.id || ''))}</span></div>
  <div class="meta-row"><span>التاريخ:</span><span>${dateStr}</span></div>
  <div class="meta-row"><span>الوقت:</span><span>${timeStr}</span></div>
  <div class="meta-row"><span>طريقة الدفع:</span><span class="bold">${esc(paymentName)}</span></div>
  ${sale.customer_name ? `<div class="meta-row"><span>العميل:</span><span class="bold">${esc(sale.customer_name)}</span></div>` : ''}

  <!-- نص الهيدر الديناميكي -->
  ${cfg.headerEnabled && cfg.headerText ? `<div style="text-align:center;font-size:11px;margin:8px 0;padding:5px;border:1px solid #ddd;">${esc(cfg.headerText)}</div>` : ''}

  <hr class="hr-solid"/>

  <!-- ③ الأصناف -->
  <div style="margin:6px 0;">${itemsHtml || '<div style="text-align:center;color:#888;padding:10px 0;">لا توجد أصناف</div>'}</div>

  <hr class="hr-solid"/>

  <!-- ④ الإجماليات -->
  <div class="meta-row"><span>الإجمالي الفرعي:</span><span>${fmt(sale.total_amount)}</span></div>
  ${discountAmt > 0 ? `<div class="meta-row" style="color:#c0392b;"><span>${discountLabel}</span><span>${fmt(discountAmt)}</span></div>` : ''}
  ${parseFloat(sale.tax_amount) > 0 ? `<div class="meta-row"><span>الضريبة${taxRate > 0 ? ` (${taxRate}%)` : ''}:</span><span>${fmt(sale.tax_amount)}</span></div>` : ''}

  <!-- الصافي النهائي -->
  <div class="grand-box">
    <span class="label">الصافي النهائي</span>
    <span class="value">${fmt(sale.net_total_amount)}</span>
  </div>

  <div class="meta-row" style="color:#555;"><span>المبلغ المدفوع:</span><span>${fmt(sale.paid_amount)}</span></div>
  ${parseFloat(sale.change_amount) > 0 ? `<div class="meta-row bold" style="color:#27ae60;"><span>الباقي للعميل:</span><span>${fmt(sale.change_amount)}</span></div>` : ''}

  <!-- ⑤ الشروط والأحكام -->
  ${cfg.termsText ? `<div style="margin-top:12px;font-size:10px;border-top:1px dashed #999;padding-top:7px;"><div class="bold" style="margin-bottom:3px;">الشروط والأحكام:</div><div style="line-height:1.5;color:#333;">${esc(cfg.termsText).replace(/\n/g, '<br/>')}</div></div>` : ''}

  <!-- ⑥ الفوتر -->
  ${cfg.footerEnabled && cfg.footerText ? `<div class="tc bold" style="margin-top:12px;font-size:13px;">${esc(cfg.footerText)}</div>` : ''}

  <div class="tc" style="margin-top:14px;font-size:9px;color:#aaa;border-top:1px solid #eee;padding-top:8px;">
    Powered by POS System
  </div>

</div>
<script>window.onload=()=>{window.print();setTimeout(()=>window.close(),500);};</script>
</body>
</html>`;
};