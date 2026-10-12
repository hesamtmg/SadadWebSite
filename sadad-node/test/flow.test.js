// End-to-end over HTTP against the built-in sandbox bank: pay, cancel, and tampered callbacks.
import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

const PORT = 3999;
const BASE = `http://127.0.0.1:${PORT}`;
let proc;
const dataDir = fs.mkdtempSync(path.join(os.tmpdir(), 'sadad-node-'));

before(async () => {
  proc = spawn(process.execPath, ['src/server.js'], {
    env: { ...process.env, PORT: String(PORT), APP_URL: BASE, SADAD_MODE: 'sandbox', DATA_DIR: dataDir, ADMIN_PASSWORD: 'pw', CURRENCY: 'IRT' },
    stdio: 'ignore',
  });
  for (let i = 0; i < 50; i++) {
    try { if ((await fetch(`${BASE}/health`)).ok) return; } catch {}
    await new Promise((r) => setTimeout(r, 100));
  }
  throw new Error('server did not start');
});
after(() => { proc.kill(); fs.rmSync(dataDir, { recursive: true, force: true }); });

const form = (data) => ({ method: 'POST', redirect: 'manual', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(data) });

async function startOrder(amount = '250000') {
  const created = await fetch(`${BASE}/orders`, form({ amount, name: 'علی رضایی', mobile: '09121234567' }));
  assert.equal(created.status, 303);
  const orderPath = created.headers.get('location');
  const pay = await fetch(`${BASE}${orderPath}/pay`, form({}));
  assert.equal(pay.status, 303, 'redirects to bank');
  const bankUrl = new URL(pay.headers.get('location'));
  return { orderPath, bankUrl, token: bankUrl.searchParams.get('Token') };
}

async function bankAction(token, action) {
  const html = await (await fetch(`${BASE}/sandbox/VPG/Pay`, form({ Token: token, action }))).text();
  return Object.fromEntries([...html.matchAll(/name="([^"]+)" value="([^"]*)"/g)].map((m) => [m[1], m[2]]));
}

test('successful payment is verified and shown as paid', async () => {
  const { orderPath, bankUrl, token } = await startOrder('250000');
  assert.equal(bankUrl.pathname, '/sandbox/VPG/Purchase');
  assert.match(await (await fetch(bankUrl)).text(), /۲٬۵۰۰٬۰۰۰ ریال/); // 250,000 Toman sent as 2,500,000 Rial
  const fields = await bankAction(token, 'pay');
  assert.equal(fields.ResCode, '0');
  const cb = await fetch(`${BASE}/payment/callback`, form(fields));
  assert.equal(cb.headers.get('location'), `${orderPath}/result`);
  const result = await (await fetch(`${BASE}${orderPath}/result`)).text();
  assert.match(result, /پرداخت با موفقیت انجام شد/);
  // Replaying the callback must not verify twice or change the result.
  await fetch(`${BASE}/payment/callback`, form(fields));
  assert.match(await (await fetch(`${BASE}${orderPath}/result`)).text(), /پرداخت با موفقیت انجام شد/);
});

test('cancel at the bank marks the order canceled and offers retry', async () => {
  const { orderPath, token } = await startOrder('100000');
  await fetch(`${BASE}/payment/callback`, form(await bankAction(token, 'cancel')));
  const result = await (await fetch(`${BASE}${orderPath}/result`)).text();
  assert.match(result, /پرداخت لغو شد/);
  assert.match(result, /تلاش دوباره/);
  const retry = await fetch(`${BASE}${orderPath}/pay`, form({}));
  assert.match(retry.headers.get('location'), /\/sandbox\/VPG\/Purchase\?Token=/);
});

test('callback claiming success without paying fails Verify', async () => {
  const { orderPath, token } = await startOrder('100000');
  const html = await (await fetch(`${BASE}/sandbox/VPG/Purchase?Token=${token}`)).text();
  const orderId = html.match(/شماره سفارش<\/dt><dd>(\d+)/)[1];
  await fetch(`${BASE}/payment/callback`, form({ OrderId: orderId, token, ResCode: '0' })); // forged
  const result = await (await fetch(`${BASE}${orderPath}/result`)).text();
  assert.match(result, /پرداخت ناموفق بود/);
});

test('validation and admin auth', async () => {
  const bad = await fetch(`${BASE}/orders`, form({ amount: '50', name: 'x' }));
  assert.equal(bad.status, 422);
  assert.equal((await fetch(`${BASE}/admin`)).status, 401);
  const ok = await fetch(`${BASE}/admin`, { headers: { Authorization: `Basic ${Buffer.from('admin:pw').toString('base64')}` } });
  assert.equal(ok.status, 200);
  assert.match(await ok.text(), /پرداخت‌شده/);
});
