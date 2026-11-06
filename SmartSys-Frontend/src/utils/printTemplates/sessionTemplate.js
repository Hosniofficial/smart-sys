import { getPrintConfig } from './printConfig';
import { esc, fmt, getCompanyData } from './printHelpers';

export const buildSessionHtml = (session, options = {}) => {
  const cfg = getPrintConfig(options.config);
  const summary = session.summary || {};
  const transactions = Array.isArray(session.transactions) ? session.transactions : [];
  const company = getCompanyData(session);
  const formatDateTime = (value) => value
    ? new Date(value).toLocaleString('en-US', {
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      hour12: true,
    })
    : '—';
  const formatTime = (value) => value
    ? new Date(value).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: true })
    : '—';
  const transactionLabel = {
    sale: 'عملية بيع',
    expense: 'مصروف',
    income: 'إيراد إضافي',
    return: 'مرتجع مبيعات',
    withdrawal: 'سحب نقدي',
    deposit: 'إيداع نقدي',
  };
  const isExpense = (type) => ['expense', 'withdrawal'].includes(String(type || '').toLowerCase());
  const transactionRows = transactions.length
    ? transactions.map((transaction) => {
      const cancelled = transaction.status === 'cancelled';
      const expense = isExpense(transaction.type);
      return `<tr>
        <td>${esc(formatTime(transaction.createdAt))}</td>
        <td>${esc(transactionLabel[transaction.type] || transaction.type || 'حركة نقدية')}${cancelled ? ' (ملغاة)' : ''}</td>
        <td class="${cancelled ? 'muted' : expense ? 'expense' : 'income'}">${cancelled ? '' : expense ? '-' : '+'}${esc(fmt(transaction.amount))}</td>
      </tr>`;
    }).join('')
    : '<tr><td colspan="3" class="empty">لا توجد حركات مسجلة لهذه الجلسة</td></tr>';

  const expectedCash = summary.calculated?.expectedCash ?? 0;
  const variance = summary.calculated?.varianceAmount ?? session.varianceAmount ?? 0;
  return `<!doctype html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8"/>
  <title>تقرير جلسة الكاشير #${esc(session.id)}</title>
  <style>
    *{box-sizing:border-box;-webkit-print-color-adjust:exact;print-color-adjust:exact}
    body{font-family:Tahoma,Arial,sans-serif;color:#1e293b;margin:0;padding:20px;direction:rtl;font-size:12px}
    .page{max-width:980px;margin:0 auto}
    .center{text-align:center}.company{font-size:20px;font-weight:900;margin:4px 0}
    .sub{font-size:11px;color:#64748b;line-height:1.6}.title{font-size:18px;font-weight:900;margin:18px 0 4px}
    .meta{font-size:11px;color:#64748b;margin-bottom:16px}
    .grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin:14px 0}
    .card{border:1px solid #dbeafe;background:#eff6ff;border-radius:8px;padding:10px}
    .label{display:block;color:#64748b;font-size:10px;margin-bottom:5px}.value{font-weight:bold;font-size:13px}
    table{width:100%;border-collapse:collapse;margin-top:18px}th,td{border:1px solid #e2e8f0;padding:8px;font-size:11px;text-align:right}th{background:#f8fafc;color:#64748b}
    .income{color:#059669;font-weight:bold}.expense{color:#e11d48;font-weight:bold}.muted{color:#94a3b8;text-decoration:line-through}.empty{text-align:center;color:#94a3b8}
    .variance{margin-top:18px;padding:12px;border:1px solid #fde68a;background:#fffbeb;border-radius:8px;color:#92400e}
    .custom{margin:14px 0;padding:8px;border:1px solid #e2e8f0;text-align:center}
    @media print{body{padding:0}.page{max-width:none}@page{size:A4;margin:12mm}}
  </style>
</head>
<body>
  <div class="page">
    ${cfg.headerEnabled && cfg.headerText ? `<div class="custom">${esc(cfg.headerText)}</div>` : ''}
    <div class="center">
      ${company.logo ? `<img src="${esc(company.logo)}" style="max-height:60px;max-width:180px;object-fit:contain"/>` : ''}
      <div class="company">${esc(company.name || 'تقرير جلسة الكاشير')}</div>
      ${company.branchName ? `<div class="sub">${esc(company.branchName)}</div>` : ''}
      ${company.address ? `<div class="sub">${esc(company.address)}</div>` : ''}
      ${company.phone ? `<div class="sub">هاتف: ${esc(company.phone)}</div>` : ''}
    </div>
    <div class="center title">تقرير جلسة الكاشير #${esc(session.id)}</div>
    <div class="center meta">${esc(session.deviceName || session.deviceId || '—')} — ${esc(session.status === 'open' ? 'نشطة' : 'مغلقة')}</div>
    <div class="grid">
      <div class="card"><span class="label">البداية</span><span class="value">${esc(formatDateTime(session.startTime))}</span></div>
      <div class="card"><span class="label">النهاية</span><span class="value">${esc(session.endTime ? formatDateTime(session.endTime) : 'حتى الآن')}</span></div>
      <div class="card"><span class="label">الرصيد الافتتاحي</span><span class="value">${esc(fmt(summary.calculated?.openingBalance ?? session.openingCashAmount))}</span></div>
      <div class="card"><span class="label">المتوقع حاليًا</span><span class="value">${esc(fmt(expectedCash))}</span></div>
    </div>
    <div class="grid">
      <div class="card"><span class="label">إجمالي المبيعات</span><span class="value">${esc(fmt(summary.totals?.cashIn))}</span></div>
      <div class="card"><span class="label">إجمالي المصروفات</span><span class="value">${esc(fmt(summary.totals?.cashOut))}</span></div>
      <div class="card"><span class="label">الفرق</span><span class="value">${esc(fmt(variance))}</span></div>
      <div class="card"><span class="label">عدد الحركات</span><span class="value">${transactions.length}</span></div>
    </div>
    <table><thead><tr><th>الوقت</th><th>النوع</th><th>المبلغ</th></tr></thead><tbody>${transactionRows}</tbody></table>
    ${Number(variance) ? `<div class="variance"><strong>تقرير الفروقات:</strong> ${esc(summary.session?.varianceReason || 'تم إغلاق الجلسة دون تبرير للفروقات المذكورة.')}</div>` : ''}
    ${cfg.footerEnabled && cfg.footerText ? `<div class="custom">${esc(cfg.footerText)}</div>` : ''}
    ${cfg.termsText ? `<div class="custom">${esc(cfg.termsText).replace(/\n/g, '<br/>')}</div>` : ''}
  </div>
  <script>window.onload=()=>{window.print();setTimeout(()=>window.close(),300)};<\/script>
</body>
</html>`;
};
