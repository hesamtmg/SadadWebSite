// Sadad payment app: form → confirm → Sadad PaymentRequest → bank → callback → Verify → receipt.
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { config } from './config.js';
import { SadadClient, SadadError, keyFingerprint } from './sadad.js';
import { OrderStore } from './store.js';
import { handleSandbox } from './sandbox.js';
import * as views from './views.js';

let sadad;
try {
  sadad = new SadadClient(config.sadad);
} catch (err) {
  console.error(`\nSadad configuration error (${config.mode} mode): ${err.message}`);
  if (config.mode === 'live') console.error('Set SADAD_MERCHANT_ID, SADAD_TERMINAL_ID and SADAD_TERMINAL_KEY in .env.\n');
  process.exit(1);
}
const store = new OrderStore(config.dataDir);
const MIN_RIAL = 1000;
const MAX_RIAL = 5_000_000_000; // 500 million Toman; adjust to your terminal's limit

const log = (msg, data) => console.log(`[${new Date().toISOString()}] ${msg}`, data ? JSON.stringify(data) : '');

// ---------------------------------------------------------------- helpers

function send(res, status, html) {
  res.writeHead(status, {
    'Content-Type': 'text/html; charset=utf-8',
    'X-Content-Type-Options': 'nosniff',
    'Referrer-Policy': 'same-origin',
    'X-Frame-Options': 'DENY',
  });
  res.end(html);
}
const page = (res, status, title, body) => send(res, status, views.layout(title, body, { mode: config.mode }));
const redirect = (res, location) => { res.writeHead(303, { Location: location }); res.end(); };

async function readBody(req) {
  const chunks = [];
  let size = 0;
  for await (const chunk of req) {
    size += chunk.length;
    if (size > 64 * 1024) throw new Error('Request body too large');
    chunks.push(chunk);
  }
  const raw = Buffer.concat(chunks).toString('utf8');
  const type = req.headers['content-type'] || '';
  if (type.includes('application/json')) return raw ? JSON.parse(raw) : {};
  return Object.fromEntries(new URLSearchParams(raw));
}

const toAsciiDigits = (s) => String(s ?? '').replace(/[۰-۹]/g, (d) => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).replace(/[٠-٩]/g, (d) => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));

function checkAdmin(req, res) {
  if (!config.admin.password) return true;
  const [scheme, encoded] = (req.headers.authorization || '').split(' ');
  if (scheme === 'Basic' && encoded) {
    const [user, ...rest] = Buffer.from(encoded, 'base64').toString('utf8').split(':');
    const pass = rest.join(':');
    const ok = (a, b) => a.length === b.length && crypto.timingSafeEqual(Buffer.from(a), Buffer.from(b));
    if (ok(user, config.admin.user) && ok(pass, config.admin.password)) return true;
  }
  res.writeHead(401, { 'WWW-Authenticate': 'Basic realm="Sadad admin", charset="UTF-8"' });
  res.end('Authentication required');
  return false;
}

function serveStatic(res, pathname) {
  const file = path.join(config.root, 'public', path.normalize(pathname.replace(/^\/static\//, '')).replace(/^(\.\.[/\\])+/, ''));
  if (!file.startsWith(path.join(config.root, 'public')) || !fs.existsSync(file)) return false;
  const types = { '.css': 'text/css; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.png': 'image/png', '.svg': 'image/svg+xml' };
  res.writeHead(200, { 'Content-Type': types[path.extname(file)] || 'application/octet-stream', 'Cache-Control': 'public, max-age=3600' });
  fs.createReadStream(file).pipe(res);
  return true;
}

// ---------------------------------------------------------------- payment flow

function createOrder(res, body) {
  const values = { amount: body.amount, name: body.name, mobile: body.mobile, description: body.description };
  const amount = Number(toAsciiDigits(body.amount).replace(/[,\s٬،]/g, ''));
  const name = String(body.name || '').trim().slice(0, 80);
  const mobile = toAsciiDigits(body.mobile).replace(/\s/g, '');
  const description = String(body.description || '').trim().slice(0, 120);
  const amountRial = config.currency === 'IRT' ? amount * 10 : amount;

  let error = '';
  if (!Number.isSafeInteger(amount) || amount <= 0) error = 'مبلغ را به صورت عدد صحیح وارد کنید.';
  else if (amountRial < MIN_RIAL) error = `حداقل مبلغ ${views.faNum(config.currency === 'IRT' ? MIN_RIAL / 10 : MIN_RIAL)} ${config.currency === 'IRT' ? 'تومان' : 'ریال'} است.`;
  else if (amountRial > MAX_RIAL) error = 'مبلغ بیش از سقف مجاز است.';
  else if (!name) error = 'نام پرداخت‌کننده را وارد کنید.';
  else if (mobile && !/^09\d{9}$/.test(mobile)) error = 'شماره موبایل باید ۱۱ رقم و با ۰۹ شروع شود.';
  if (error) return page(res, 422, 'پرداخت آنلاین', views.homePage({ currency: config.currency, error, values }));

  const order = store.create({ amount, amountRial, currency: config.currency, name, mobile, description });
  store.update(order.id, {}, { type: 'created', data: { amount, amountRial, currency: config.currency } });
  return redirect(res, `/orders/${order.id}`);
}

async function startPayment(res, order) {
  if (!['created', 'failed', 'canceled'].includes(order.status)) return redirect(res, `/orders/${order.id}/result`);

  const bankOrderId = store.nextBankOrderId(); // fresh OrderId per attempt: Sadad rejects reused ones
  const returnUrl = `${config.appUrl}/payment/callback`;
  try {
    const { token, paymentUrl, debug } = await sadad.paymentRequest({ orderId: bankOrderId, amount: order.amountRial, returnUrl });
    store.update(order.id, { status: 'pending', bankOrderId, token, message: '' }, { type: 'payment_request.ok', data: { bankOrderId, token, returnUrl, ...debug } });
    log('PaymentRequest ok', { order: order.id, bankOrderId, amountRial: order.amountRial });
    return redirect(res, paymentUrl);
  } catch (err) {
    const message = err instanceof SadadError ? err.message : `خطای داخلی: ${err.message}`;
    store.update(order.id, { status: 'failed', bankOrderId, message }, { type: 'payment_request.error', data: { bankOrderId, resCode: err.resCode ?? null, response: err.response ?? null, message } });
    log('PaymentRequest failed', { order: order.id, bankOrderId, resCode: err.resCode, message });
    return page(res, 502, 'تأیید اطلاعات پرداخت', views.confirmPage(store.get(order.id), { error: `درخواست پرداخت توسط سداد پذیرفته نشد: ${message}` }));
  }
}

/** The bank POSTs OrderId, token and ResCode here after the customer pays or cancels. */
async function paymentCallback(res, body) {
  const token = String(body.token ?? body.Token ?? '').trim();
  const resCode = String(body.ResCode ?? body.rescode ?? '').trim();
  const postedOrderId = toAsciiDigits(body.OrderId ?? body.orderId ?? '').trim();
  const order = store.findByToken(token) || store.findByBankOrderId(postedOrderId);
  log('Callback', { token, resCode, postedOrderId, found: Boolean(order) });

  if (!order) return page(res, 400, 'خطا', views.errorPage('سفارش مربوط به این پرداخت پیدا نشد.'));
  const result = `/orders/${order.id}/result`;
  if (order.status === 'paid' || order.status === 'verifying') return redirect(res, result); // refresh / double POST

  const fail = (status, message, data) => {
    store.update(order.id, { status, message }, { type: `callback.${status}`, data: { ...data, message } });
    return redirect(res, result);
  };

  if (token && order.token && token !== order.token) return fail('failed', 'توکن بازگشتی با درخواست اولیه مطابقت ندارد.', { token });
  if (postedOrderId && postedOrderId !== order.bankOrderId) return fail('failed', 'شماره سفارش بازگشتی با درخواست اولیه مطابقت ندارد.', { postedOrderId });
  if (resCode !== '0' || !token) return fail('canceled', `پرداخت در بانک انجام نشد یا توسط شما لغو شد. [ResCode: ${resCode || 'نامشخص'}]`, { resCode });

  // Confirm with Sadad. Until Verify succeeds the money is not settled to the merchant.
  store.update(order.id, { status: 'verifying' }, { type: 'callback.ok', data: { resCode, token } });
  try {
    const v = await sadad.verify(token);
    if (v.amount !== null && v.amount !== order.amountRial) {
      return fail('failed', 'مبلغ تأییدشده با مبلغ سفارش مطابقت ندارد. با پشتیبانی تماس بگیرید.', { expected: order.amountRial, verified: v.amount, response: v.response });
    }
    store.update(order.id, {
      status: 'paid', message: '', paidAt: new Date().toISOString(),
      systemTraceNo: v.systemTraceNo, retrievalRefNo: v.retrievalRefNo,
    }, { type: 'verify.ok', data: v.response });
    log('Verify ok', { order: order.id, trace: v.systemTraceNo, rrn: v.retrievalRefNo });
  } catch (err) {
    log('Verify failed', { order: order.id, resCode: err.resCode, message: err.message });
    return fail('failed', `تأیید تراکنش در سداد ناموفق بود: ${err.message}`, { resCode: err.resCode ?? null, response: err.response ?? null });
  }
  return redirect(res, result);
}

// ---------------------------------------------------------------- router

async function route(req, res) {
  const url = new URL(req.url, config.appUrl);
  const { pathname } = url;
  const method = req.method;

  if (pathname.startsWith('/static/') && method === 'GET') {
    if (serveStatic(res, pathname)) return;
  }

  if (config.mode === 'sandbox' && pathname.startsWith('/sandbox/')) {
    const body = method === 'POST' ? await readBody(req) : null;
    if (handleSandbox(req, res, url, body, config)) return;
  }

  if (method === 'GET' && pathname === '/') {
    return page(res, 200, 'پرداخت آنلاین', views.homePage({ currency: config.currency }));
  }
  if (method === 'POST' && pathname === '/orders') return createOrder(res, await readBody(req));

  let m;
  if ((m = pathname.match(/^\/orders\/([a-f0-9]{16})(\/pay|\/result)?$/))) {
    const order = store.get(m[1]);
    if (!order) return page(res, 404, 'یافت نشد', views.errorPage('سفارش پیدا نشد.'));
    if (method === 'GET' && !m[2]) {
      return order.status === 'created' ? page(res, 200, 'تأیید اطلاعات پرداخت', views.confirmPage(order)) : redirect(res, `/orders/${order.id}/result`);
    }
    if (method === 'POST' && m[2] === '/pay') return startPayment(res, order);
    if (method === 'GET' && m[2] === '/result') return page(res, 200, 'نتیجه پرداخت', views.resultPage(order));
  }

  // Sadad posts back here (some setups use GET; accept both).
  if (pathname === '/payment/callback' && (method === 'POST' || method === 'GET')) {
    const body = method === 'POST' ? await readBody(req) : Object.fromEntries(url.searchParams);
    return paymentCallback(res, body);
  }

  if (pathname === '/admin' || pathname.startsWith('/admin/')) {
    if (!checkAdmin(req, res)) return;
    if (method === 'GET' && pathname === '/admin') {
      return page(res, 200, 'تراکنش‌ها', views.adminPage(store.list(), { mode: config.mode, terminalId: sadad.terminalId, keyFp: keyFingerprint(sadad.terminalKey) }));
    }
    if (method === 'GET' && (m = pathname.match(/^\/admin\/orders\/([a-f0-9]{16})$/))) {
      const order = store.get(m[1]);
      if (order) return page(res, 200, 'جزئیات تراکنش', views.adminOrderPage(order));
    }
  }

  if (pathname === '/health') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    return res.end(JSON.stringify({ ok: true, mode: config.mode }));
  }

  return page(res, 404, 'یافت نشد', views.errorPage('صفحه مورد نظر پیدا نشد.'));
}

const server = http.createServer((req, res) => {
  route(req, res).catch((err) => {
    console.error(err);
    if (!res.headersSent) page(res, 500, 'خطا', views.errorPage('خطای داخلی سرور. دوباره تلاش کنید.'));
    else res.end();
  });
});

server.listen(config.port, () => {
  console.log(`\nSadad payment app on ${config.appUrl}  (port ${config.port})`);
  console.log(`  mode:       ${config.mode.toUpperCase()}${config.mode === 'sandbox' ? ' (built-in test bank, no real money)' : ' → ' + config.sadad.apiBaseUrl}`);
  console.log(`  terminal:   ${sadad.terminalId}   merchant: ${sadad.merchantId}   key fingerprint: ${keyFingerprint(sadad.terminalKey)}`);
  console.log(`  callback:   ${config.appUrl}/payment/callback`);
  console.log(`  admin:      ${config.appUrl}/admin${config.admin.password ? ` (user "${config.admin.user}")` : ' (no password set!)'}\n`);
});

for (const sig of ['SIGINT', 'SIGTERM']) process.on(sig, () => server.close(() => process.exit(0)));
