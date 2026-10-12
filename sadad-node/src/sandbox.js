// Built-in Sadad sandbox (SADAD_MODE=sandbox): same endpoints and JSON as sadad.shaparak.ir.
// SignData is really checked with the sandbox key, so signing bugs fail here too. Accepts any card.
import crypto from 'node:crypto';
import { signData } from './sadad.js';
import { h, faNum } from './views.js';

const tokens = new Map(); // token -> { orderId, amount, returnUrl, status, rrn, trace }

const json = (res, body) => {
  res.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8' });
  res.end(JSON.stringify(body));
};

const signOk = (sign, text, key) => {
  const expected = Buffer.from(signData(text, key));
  const given = Buffer.from(String(sign ?? ''));
  return expected.length === given.length && crypto.timingSafeEqual(expected, given);
};

/** Returns true when the request was a sandbox route. */
export function handleSandbox(req, res, url, body, { sandbox }) {
  const path = url.pathname.replace(/^\/sandbox/, '');

  if (req.method === 'POST' && path === '/vpg/api/v0/Request/PaymentRequest') {
    const r = body || {};
    console.log('[sandbox] PaymentRequest', { OrderId: r.OrderId, Amount: r.Amount, LocalDateTime: r.LocalDateTime });
    if (String(r.TerminalId) !== sandbox.terminalId || String(r.MerchantId) !== sandbox.merchantId) {
      return json(res, { ResCode: '3', Token: null, Description: 'پذیرنده کارت فعال نیست.' }), true;
    }
    if (!signOk(r.SignData, `${r.TerminalId};${r.OrderId};${r.Amount}`, sandbox.terminalKey)) {
      return json(res, { ResCode: '1025', Token: null, Description: 'اطلاعات SignData ارسالی اشتباه است' }), true;
    }
    if (!Number.isSafeInteger(r.Amount) || r.Amount < 1000) {
      return json(res, { ResCode: '-1', Token: null, Description: 'مبلغ تراکنش نامعتبر است.' }), true;
    }
    const token = crypto.randomBytes(12).toString('hex').toUpperCase();
    tokens.set(token, { orderId: String(r.OrderId), amount: r.Amount, returnUrl: String(r.ReturnUrl), status: 'created' });
    return json(res, { ResCode: '0', Token: token, Description: 'عملیات با موفقیت انجام شد' }), true;
  }

  if (req.method === 'POST' && path === '/vpg/api/v0/Advice/Verify') {
    const r = body || {};
    console.log('[sandbox] Verify', { Token: r.Token });
    const tx = tokens.get(String(r.Token));
    if (!signOk(r.SignData, String(r.Token), sandbox.terminalKey)) {
      return json(res, { ResCode: '-1', Description: 'اطلاعات SignData ارسالی اشتباه است' }), true;
    }
    if (!tx || !['paid', 'verified'].includes(tx.status)) {
      return json(res, { ResCode: '-1', Description: 'تراکنش یافت نشد یا پرداخت نشده است.' }), true;
    }
    tx.status = 'verified';
    return json(res, {
      ResCode: '0', Amount: tx.amount, Description: 'عملیات با موفقیت انجام شد',
      RetrivalRefNo: tx.rrn, SystemTraceNo: tx.trace, OrderId: tx.orderId,
    }), true;
  }

  if (req.method === 'GET' && path === '/VPG/Purchase') {
    const token = url.searchParams.get('Token') || '';
    const tx = tokens.get(token);
    if (!tx || tx.status !== 'created') {
      res.writeHead(400, { 'Content-Type': 'text/plain; charset=utf-8' });
      return res.end('توکن نامعتبر یا منقضی است.'), true;
    }
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
    return res.end(bankPage(token, tx, sandbox)), true;
  }

  if (req.method === 'POST' && path === '/VPG/Pay') {
    const token = String(body?.Token || '');
    const tx = tokens.get(token);
    if (!tx || tx.status !== 'created') {
      res.writeHead(400, { 'Content-Type': 'text/plain; charset=utf-8' });
      return res.end('توکن نامعتبر یا منقضی است.'), true;
    }
    const paid = body.action === 'pay';
    tx.status = paid ? 'paid' : 'canceled';
    if (paid) {
      tx.trace = String(crypto.randomInt(100000, 999999));
      tx.rrn = String(crypto.randomInt(100000000000, 999999999999));
    }
    // Like the real bank: the browser POSTs OrderId, token and ResCode back to ReturnUrl.
    const fields = { OrderId: tx.orderId, token, ResCode: paid ? '0' : '-1' };
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
    return res.end(`<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><title>بازگشت به فروشگاه</title>
<body onload="document.forms[0].submit()" style="font-family:Tahoma,sans-serif;text-align:center;padding:4rem">
<p>در حال بازگشت به فروشگاه…</p><form method="post" action="${h(tx.returnUrl)}">
${Object.entries(fields).map(([k, v]) => `<input type="hidden" name="${h(k)}" value="${h(v)}">`).join('')}
<noscript><button>بازگشت به فروشگاه</button></noscript></form></body></html>`), true;
  }

  return false;
}

function bankPage(token, tx, sandbox) {
  return `<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1"><title>درگاه پرداخت سداد — محیط آزمایشی</title>
<style>
*{box-sizing:border-box}body{margin:0;font-family:Vazirmatn,Tahoma,sans-serif;background:#eef2f7;color:#1d2939}
.sandbox{background:#f79009;color:#fff;text-align:center;padding:.5rem;font-size:.85rem}
.wrap{max-width:860px;margin:2rem auto;padding:0 1rem;display:grid;grid-template-columns:1fr 300px;gap:1rem}
.card{background:#fff;border-radius:14px;box-shadow:0 4px 18px rgba(16,24,40,.06);padding:1.4rem}
h1{font-size:1.1rem;margin:0 0 1.2rem;color:#0b3b8c}label{display:block;font-size:.85rem;margin:.9rem 0 .35rem;color:#475467}
input{width:100%;padding:.7rem .8rem;border:1px solid #d0d5dd;border-radius:9px;font-size:1rem;direction:ltr;text-align:center;font-family:monospace}
.row{display:grid;grid-template-columns:1fr 1fr 1fr;gap:.6rem}.btns{display:flex;gap:.6rem;margin-top:1.4rem}
button{flex:1;padding:.8rem;border:0;border-radius:9px;font-size:1rem;font-family:inherit;cursor:pointer}
.pay{background:#155eef;color:#fff}.cancel{background:#f2f4f7;color:#b42318}
dt{font-size:.8rem;color:#667085;margin-top:.9rem}dd{margin:.2rem 0 0;font-weight:700}.amount{font-size:1.4rem;color:#0b3b8c}
.hint{font-size:.78rem;color:#667085;margin-top:1rem;line-height:1.8}@media(max-width:720px){.wrap{grid-template-columns:1fr}}
</style></head><body>
<div class="sandbox">محیط آزمایشی سداد — هیچ پولی جابه‌جا نمی‌شود</div>
<div class="wrap">
<form class="card" method="post" action="/sandbox/VPG/Pay"><h1>اطلاعات کارت</h1>
<input type="hidden" name="Token" value="${h(token)}">
<label>شماره کارت</label><input name="pan" value="6037 9911 1111 1111" inputmode="numeric">
<div class="row"><div><label>CVV2</label><input name="cvv2" value="123"></div><div><label>ماه انقضا</label><input name="mm" value="12"></div><div><label>سال انقضا</label><input name="yy" value="08"></div></div>
<label>رمز دوم (پویا)</label><input name="pin2" value="123456" type="password">
<div class="btns"><button class="pay" name="action" value="pay">پرداخت</button><button class="cancel" name="action" value="cancel">انصراف</button></div>
<p class="hint">شبیه‌ساز درگاه: «پرداخت» تراکنش موفق و «انصراف» تراکنش لغوشده را به فروشگاه برمی‌گرداند.</p></form>
<div class="card"><strong style="color:#0b3b8c">سداد — بانک ملی ایران</strong><dl>
<dt>مبلغ قابل پرداخت</dt><dd class="amount">${faNum(tx.amount)} ریال</dd>
<dt>شماره سفارش</dt><dd>${h(tx.orderId)}</dd><dt>کد ترمینال</dt><dd>${h(sandbox.terminalId)}</dd></dl></div>
</div></body></html>`;
}
