// Settings from environment variables, optionally loaded from a .env file next to package.json.
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { SADAD_LIVE_URL } from './sadad.js';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

function loadDotEnv(file) {
  if (!fs.existsSync(file)) return;
  for (const line of fs.readFileSync(file, 'utf8').split(/\r?\n/)) {
    const m = line.match(/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*?)\s*$/);
    if (!m || line.trimStart().startsWith('#')) continue;
    let value = m[2];
    if (/^(['"]).*\1$/.test(value)) value = value.slice(1, -1);
    if (process.env[m[1]] === undefined) process.env[m[1]] = value; // real env vars win
  }
}
loadDotEnv(path.join(ROOT, '.env'));

const env = (name, fallback = '') => (process.env[name] ?? fallback).trim();

const port = Number(env('PORT', '3000'));
const mode = env('SADAD_MODE', 'sandbox').toLowerCase();
if (!['sandbox', 'live'].includes(mode)) throw new Error(`SADAD_MODE must be "sandbox" or "live", got "${mode}"`);

const appUrl = env('APP_URL', `http://localhost:${port}`).replace(/\/+$/, '');

// Sandbox: a random key per start, shared only with the built-in mock bank. Never sent to Sadad.
const sandbox = {
  merchantId: '000000140000000',
  terminalId: '24000000',
  terminalKey: crypto.randomBytes(24).toString('base64'),
};

export const config = {
  root: ROOT,
  port,
  appUrl,
  mode,
  currency: env('CURRENCY', 'IRT').toUpperCase() === 'IRR' ? 'IRR' : 'IRT', // IRT = Toman (×10 to Rial)
  dataDir: path.resolve(ROOT, env('DATA_DIR', 'data')),
  admin: { user: env('ADMIN_USER', 'admin'), password: env('ADMIN_PASSWORD', '') },
  sandbox,
  sadad: mode === 'live'
    ? {
        merchantId: env('SADAD_MERCHANT_ID'),
        terminalId: env('SADAD_TERMINAL_ID'),
        terminalKey: env('SADAD_TERMINAL_KEY'),
        apiBaseUrl: env('SADAD_API_URL', SADAD_LIVE_URL),
        purchaseBaseUrl: env('SADAD_PURCHASE_URL', SADAD_LIVE_URL),
        timeoutMs: Number(env('SADAD_TIMEOUT_MS', '30000')),
      }
    : {
        ...sandbox,
        apiBaseUrl: `http://127.0.0.1:${port}/sandbox`, // server-to-server, same process
        purchaseBaseUrl: `${appUrl}/sandbox`,          // customer's browser
        timeoutMs: 10000,
      },
};
