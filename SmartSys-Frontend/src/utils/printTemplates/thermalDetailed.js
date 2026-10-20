import { getPrintConfig } from './printConfig';
import { esc, fmt, itemTotal, getCompanyData } from './printHelpers';
import { getLocalDateISO } from '@/utils';

export const buildThermalDetailedHtml = (sale) => {
  const cfg     = getPrintConfig();
  const company = getCompanyData(sale);
  const saleDate = sale.sale_date || sale.created_at || getLocalDateISO();
  const taxRate  = parseFloat(sale.tax_rate ?? 0);

  // ─── Items (numbered, with unit) ─────────────────────────────────────────
  const itemsHtml = (sale.items || []).map((it, idx) => `
    <div style="margin-bottom:9px;padding-bottom:6px;border-bottom:0.3mm dashed #ccc;">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:6px;">
        <span style="font-weight:bold;font-size:13px;flex:1;">${idx + 1}. ${esc(it.product_name || it.name)}</span>
        <span style="font-weight:bold;white-space:nowrap;">${fmt(itemTotal(it))}</span>
      </div>
      <div style="font-size:11px;color:#444;margin-top:2px;padding-right:14px;">
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
    ? `إجمالي الخصم (${rawDiscount}%) (-):`
    : 'إجمالي الخصم (-):';  

  return `<!doctype html>
<html dir="rtl">
<head>
  <meta charset="utf-8"/>
  <title>فاتورة ${esc(String(sale.invoice_number || sale.id || ''))}</title>
  <style>
    *{box-sizing:border-box;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    body{font-family:Tahoma,Arial,sans-serif;margin:0;padding:0;background:#fff;color:#111;font-size:12px;line-height:1.45;}
    .receipt{width:76mm;margin:0 auto;padding:5mm 3mm;}
    .tc{text-align:center;}
    .bold{font-weight:bold;}
    .row{display:flex;justify-content:space-between;align-items:center;font-size:11.5px;padding:3px 0;}
    .hr{border:none;border-top:1.5px solid #000;margin:10px 0;}
    .hr-dash{border:none;border-top:1px dashed #888;margin:8px 0;}
    .grid2{display:grid;grid-template-columns:1fr 1fr;font-size:11px;gap:4px 0;border-top:1px solid #000;border-bottom:1px solid #000;padding:7px 0;margin:10px 0;}
    .grid2 .lft{text-align:left;}
    .lbl{color:#555;}
    .val{font-weight:bold;}
    .grand-box{border-top:2px solid #000;border-bottom:2px solid #000;display:flex;justify-content:space-between;align-items:center;padding:8px 2px;margin:10px 0 6px;}
    .grand-box .g-lbl{font-size:15px;font-weight:bold;}
    .grand-box .g-val{font-size:22px;font-weight:900;}
    @media print{@page{size:80mm auto;margin:0;}body,html{width:76mm;}.receipt{padding:2mm 2mm;}}
  </style>
</head>
<body>
<div class="receipt">

  <!-- ① الهيدر: لوجو + بيانات الشركة -->
  <div class="tc" style="margin-bottom:12px;padding-bottom:10px;border-bottom:1px dashed #999;">
    ${company.logo ? `<img src="${esc(company.logo)}" style="max-height:60px;max-width:62mm;object-fit:contain;margin-bottom:7px;display:block;margin-left:auto;margin-right:auto;" />` : ''}
    <div style="font-size:22px;font-weight:900;margin-bottom:3px;">${esc(company.name || 'فاتورة مبيعات')}</div>
    ${company.branch_name ? `<div style="font-size:12px;color:#333;">${esc(company.branch_name)}</div>` : ''}
    ${company.address     ? `<div style="font-size:11px;color:#555;">${esc(company.address)}</div>` : ''}
    ${company.phone       ? `<div style="font-size:11px;">هاتف: ${esc(company.phone)}</div>` : ''}
    ${company.website     ? `<div style="font-size:10px;color:#777;">${esc(company.website)}</div>` : ''}
    ${company.tax         ? `<div style="font-size:10.5px;font-weight:bold;margin-top:4px;padding:2px 8px;background:#111;color:#fff;display:inline-block;">الرقم الضريبي: ${esc(company.tax)}</div>` : ''}
  </div>

  <!-- ② بيانات الفاتورة — شبكة 2×2 -->
  <div class="grid2">
    <div><span class="lbl">الفاتورة: </span><span class="val">#${esc(String(sale.invoice_number || sale.id || ''))}</span></div>
    <div class="lft"><span class="lbl">التاريخ: </span><span class="val">${dateStr}</span></div>
    <div><span class="lbl">الدفع: </span><span class="val">${esc(paymentName)}</span></div>
    <div class="lft"><span class="lbl">الوقت: </span><span class="val">${timeStr}</span></div>
    ${sale.customer_name ? `<div style="grid-column:span 2;margin-top:3px;"><span class="lbl">العميل: </span><span class="val">${esc(sale.customer_name)}</span></div>` : ''}
  </div>

  <!-- نص الهيدر الديناميكي -->
  ${cfg.headerEnabled && cfg.headerText ? `<div class="tc" style="font-size:12px;padding:6px;background:#f5f5f5;margin-bottom:10px;">${esc(cfg.headerText)}</div>` : ''}

  <!-- ③ الأصناف -->
  <div style="margin:8px 0;">
    ${itemsHtml || '<div class="tc" style="color:#888;padding:10px 0;">لا توجد أصناف</div>'}
  </div>

  <hr class="hr"/>

  <!-- ④ الإجماليات التفصيلية -->
  <div class="row"><span>الإجمالي الفرعي:</span><span>${fmt(sale.total_amount)}</span></div>
  ${discountAmt > 0 ? `<div class="row" style="color:#c0392b;"><span>${discountLabel}</span><span>${fmt(discountAmt)}</span></div>` : ''}
  ${parseFloat(sale.tax_amount) > 0 ? `<div class="row"><span>الضريبة${taxRate > 0 ? ` (${taxRate}%)` : ''}:</span><span>${fmt(sale.tax_amount)}</span></div>` : ''}

  <!-- الصافي النهائي البارز -->
  <div class="grand-box">
    <span class="g-lbl">الصافي النهائي</span>
    <span class="g-val">${fmt(sale.net_total_amount)}</span>
  </div>

  <div class="row" style="color:#555;font-size:11px;"><span>المبلغ المدفوع:</span><span>${fmt(sale.paid_amount)}</span></div>
  ${parseFloat(sale.change_amount) > 0 ? `<div class="row bold" style="color:#27ae60;"><span>الباقي للعميل:</span><span>${fmt(sale.change_amount)}</span></div>` : ''}

  <!-- ⑤ الشروط والأحكام -->
  ${cfg.termsText ? `
    <div style="margin-top:14px;font-size:10px;border:1px solid #ddd;padding:7px;border-radius:3px;background:#fafafa;">
      <strong style="display:block;margin-bottom:4px;padding-bottom:3px;border-bottom:1px solid #eee;">الشروط والأحكام</strong>
      <div style="line-height:1.5;">${esc(cfg.termsText).replace(/\n/g, '<br/>')}</div>
    </div>` : ''}

  <!-- ⑥ الفوتر -->
  ${cfg.footerEnabled && cfg.footerText ? `<div class="tc bold" style="margin-top:14px;font-size:13px;">${esc(cfg.footerText)}</div>` : ''}

  <div class="tc" style="margin-top:16px;font-size:9px;color:#aaa;border-top:1px solid #eee;padding-top:8px;">
    شكراً لتعاملكم معنا — Powered by POS System
  </div>

</div>
<script>window.onload=()=>{window.print();setTimeout(()=>window.close(),500);};</script>
</body>
</html>`;
};