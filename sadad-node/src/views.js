// Server-rendered Persian (RTL) pages.

export const h = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
export const faNum = (n) => Number(n).toLocaleString('fa-IR');
const faDigits = (s) => String(s ?? '').replace(/\d/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[d]);
const faDate = (iso) => new Intl.DateTimeFormat('fa-IR-u-ca-persian', { timeZone: 'Asia/Tehran', dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso));

const STATUS = {
  created: { label: 'در انتظار تأیید', cls: 'muted' },
  pending: { label: 'در درگاه بانک', cls: 'warn' },
  verifying: { label: 'در حال تأیید', cls: 'warn' },
  paid: { label: 'پرداخت‌شده', cls: 'ok' },
  failed: { label: 'ناموفق', cls: 'bad' },
  canceled: { label: 'لغوشده', cls: 'bad' },
};
const badge = (status) => `<span class="badge ${STATUS[status]?.cls || 'muted'}">${STATUS[status]?.label || h(status)}</span>`;

const money = (order) => `${faNum(order.amount)} <small>${order.currency === 'IRT' ? 'تومان' : 'ریال'}</small>`;

export function layout(title, body, { mode } = {}) {
  return `<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>${h(title)} — پرداخت سداد</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;600;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/static/style.css">
</head>
<body>
${mode === 'sandbox' ? '<div class="sandbox-bar">حالت آزمایشی (Sandbox) — پرداخت‌ها به بانک واقعی ارسال نمی‌شوند</div>' : ''}
<header class="top"><a class="brand" href="/"><span class="logo">س</span> پرداخت الکترونیک سداد</a><nav><a href="/">پرداخت جدید</a><a href="/admin">تراکنش‌ها</a></nav></header>
<main>${body}</main>
<footer>درگاه پرداخت سداد بانک ملی ایران · Node.js</footer>
</body>
</html>`;
}

export function homePage({ currency, error, values = {} }) {
  const unit = currency === 'IRT' ? 'تومان' : 'ریال';
  const presets = currency === 'IRT' ? [50000, 250000, 1000000] : [500000, 2500000, 10000000];
  return `
<section class="card narrow">
  <h1>پرداخت آنلاین</h1>
  <p class="lead">مبلغ و مشخصات خود را وارد کنید. در مرحله بعد اطلاعات را تأیید می‌کنید و به درگاه امن سداد منتقل می‌شوید.</p>
  ${error ? `<div class="alert bad">${h(error)}</div>` : ''}
  <form method="post" action="/orders" class="form">
    <label for="amount">مبلغ (${unit})</label>
    <div class="amount-input"><input id="amount" name="amount" inputmode="numeric" autocomplete="off" required value="${h(values.amount)}" placeholder="مثلاً ${faNum(presets[1])}"><span>${unit}</span></div>
    <div class="presets">${presets.map((p) => `<button type="button" data-amount="${p}">${faNum(p)} ${unit}</button>`).join('')}</div>
    <label for="name">نام و نام خانوادگی</label>
    <input id="name" name="name" required maxlength="80" value="${h(values.name)}">
    <label for="mobile">شماره موبایل</label>
    <input id="mobile" name="mobile" inputmode="tel" dir="ltr" placeholder="09121234567" maxlength="11" value="${h(values.mobile)}">
    <label for="description">بابت (اختیاری)</label>
    <input id="description" name="description" maxlength="120" value="${h(values.description)}">
    <button class="btn primary" type="submit">ادامه و بررسی پرداخت</button>
  </form>
</section>
<script>
document.querySelectorAll('[data-amount]').forEach(b => b.onclick = () => { document.getElementById('amount').value = b.dataset.amount; });
</script>`;
}

export function confirmPage(order, { error } = {}) {
  return `
<section class="card narrow">
  <h1>تأیید اطلاعات پرداخت</h1>
  <p class="lead">لطفاً اطلاعات زیر را بررسی کنید. با زدن «تأیید و پرداخت» به درگاه سداد منتقل می‌شوید.</p>
  ${error ? `<div class="alert bad">${h(error)}</div>` : ''}
  <dl class="summary">
    <div><dt>مبلغ</dt><dd class="big">${money(order)}</dd></div>
    ${order.currency === 'IRT' ? `<div><dt>مبلغ ارسالی به بانک</dt><dd>${faNum(order.amountRial)} <small>ریال</small></dd></div>` : ''}
    <div><dt>پرداخت‌کننده</dt><dd>${h(order.name)}</dd></div>
    ${order.mobile ? `<div><dt>موبایل</dt><dd>${faDigits(order.mobile)}</dd></div>` : ''}
    ${order.description ? `<div><dt>بابت</dt><dd>${h(order.description)}</dd></div>` : ''}
    <div><dt>وضعیت</dt><dd>${badge(order.status)}</dd></div>
  </dl>
  <form method="post" action="/orders/${h(order.id)}/pay"><button class="btn primary" type="submit">تأیید و پرداخت</button></form>
  <a class="btn ghost" href="/">انصراف و ویرایش</a>
</section>`;
}

export function resultPage(order) {
  if (order.status === 'paid') {
    return `
<section class="card narrow result ok">
  <div class="icon">✓</div>
  <h1>پرداخت با موفقیت انجام شد</h1>
  <p class="lead">پرداخت توسط بانک تأیید (Verify) شد. این اطلاعات را برای پیگیری نگه دارید.</p>
  <dl class="summary">
    <div><dt>مبلغ</dt><dd class="big">${money(order)}</dd></div>
    <div><dt>شماره پیگیری (Trace)</dt><dd class="mono">${faDigits(order.systemTraceNo || '—')}</dd></div>
    <div><dt>شماره مرجع (RRN)</dt><dd class="mono">${faDigits(order.retrievalRefNo || '—')}</dd></div>
    <div><dt>شماره سفارش</dt><dd class="mono">${faDigits(order.bankOrderId)}</dd></div>
    <div><dt>تاریخ</dt><dd>${faDate(order.paidAt || order.updatedAt)}</dd></div>
  </dl>
  <a class="btn primary" href="/">پرداخت جدید</a>
</section>`;
  }
  const retry = ['failed', 'canceled'].includes(order.status);
  return `
<section class="card narrow result bad">
  <div class="icon">!</div>
  <h1>${order.status === 'canceled' ? 'پرداخت لغو شد' : order.status === 'failed' ? 'پرداخت ناموفق بود' : 'وضعیت پرداخت نامشخص است'}</h1>
  <p class="lead">${h(order.message || 'مبلغی از حساب شما کسر نشده است؛ در صورت کسر، طی ۷۲ ساعت به حساب شما بازمی‌گردد.')}</p>
  <dl class="summary">
    <div><dt>مبلغ</dt><dd>${money(order)}</dd></div>
    <div><dt>وضعیت</dt><dd>${badge(order.status)}</dd></div>
  </dl>
  ${retry ? `<form method="post" action="/orders/${h(order.id)}/pay"><button class="btn primary" type="submit">تلاش دوباره</button></form>` : ''}
  <a class="btn ghost" href="/">بازگشت</a>
</section>`;
}

export function adminPage(orders, { mode, terminalId, keyFp }) {
  const paid = orders.filter((o) => o.status === 'paid');
  const total = paid.reduce((s, o) => s + o.amountRial, 0);
  return `
<section class="stats">
  <div class="card stat"><span>تراکنش‌ها</span><strong>${faNum(orders.length)}</strong></div>
  <div class="card stat"><span>موفق</span><strong>${faNum(paid.length)}</strong></div>
  <div class="card stat"><span>جمع دریافتی</span><strong>${faNum(total)} <small>ریال</small></strong></div>
  <div class="card stat"><span>درگاه</span><strong class="mono small">${mode === 'live' ? 'LIVE' : 'SANDBOX'} · ${h(terminalId)}</strong><small class="mono">key ${h(keyFp)}</small></div>
</section>
<section class="card">
  <h1>تراکنش‌ها</h1>
  <div class="table-wrap"><table>
    <thead><tr><th>تاریخ</th><th>پرداخت‌کننده</th><th>مبلغ</th><th>وضعیت</th><th>OrderId بانک</th><th>پیگیری / RRN</th><th></th></tr></thead>
    <tbody>
    ${orders.length ? orders.map((o) => `<tr>
      <td>${faDate(o.createdAt)}</td>
      <td>${h(o.name)}<br><small>${faDigits(o.mobile)}</small></td>
      <td>${money(o)}</td>
      <td>${badge(o.status)}</td>
      <td class="mono">${h(o.bankOrderId || '—')}</td>
      <td class="mono">${h([o.systemTraceNo, o.retrievalRefNo].filter(Boolean).join(' / ') || '—')}</td>
      <td><a href="/admin/orders/${h(o.id)}">جزئیات</a></td></tr>`).join('') : '<tr><td colspan="7" class="empty">هنوز تراکنشی ثبت نشده است.</td></tr>'}
    </tbody>
  </table></div>
</section>`;
}

export function adminOrderPage(order) {
  return `
<section class="card">
  <h1>جزئیات تراکنش ${badge(order.status)}</h1>
  <dl class="summary grid">
    <div><dt>مبلغ</dt><dd>${money(order)} (${faNum(order.amountRial)} ریال)</dd></div>
    <div><dt>پرداخت‌کننده</dt><dd>${h(order.name)} ${faDigits(order.mobile)}</dd></div>
    <div><dt>OrderId بانک</dt><dd class="mono">${h(order.bankOrderId || '—')}</dd></div>
    <div><dt>Token</dt><dd class="mono">${h(order.token || '—')}</dd></div>
    <div><dt>Trace / RRN</dt><dd class="mono">${h(order.systemTraceNo || '—')} / ${h(order.retrievalRefNo || '—')}</dd></div>
    <div><dt>پیام</dt><dd>${h(order.message || '—')}</dd></div>
  </dl>
  <h2>رویدادها</h2>
  <ol class="events">${order.events.map((e) => `<li><time>${faDate(e.at)}</time> <strong>${h(e.type)}</strong><pre>${h(JSON.stringify(e.data ?? {}, null, 2))}</pre></li>`).join('')}</ol>
  <a class="btn ghost" href="/admin">بازگشت به فهرست</a>
</section>`;
}

export function errorPage(message) {
  return `<section class="card narrow result bad"><div class="icon">!</div><h1>خطا</h1><p class="lead">${h(message)}</p><a class="btn ghost" href="/">بازگشت</a></section>`;
}
