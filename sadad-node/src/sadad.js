// Sadad (Bank Melli) VPG client: PaymentRequest, purchase redirect and Verify.
import crypto from 'node:crypto';

export const SADAD_LIVE_URL = 'https://sadad.shaparak.ir';

export class SadadError extends Error {
  constructor(message, { resCode = null, response = null } = {}) {
    super(message);
    this.name = 'SadadError';
    this.resCode = resCode;
    this.response = response;
  }
}

/** Decode the Base64 TerminalKey; Sadad keys are exactly 24 bytes (TripleDES). */
export function decodeKey(terminalKeyBase64) {
  const clean = String(terminalKeyBase64 ?? '').replace(/\s+/g, '');
  if (!/^[A-Za-z0-9+/]+={0,2}$/.test(clean) || clean.length % 4 !== 0) {
    throw new SadadError('TerminalKey is not valid Base64.');
  }
  const key = Buffer.from(clean, 'base64');
  if (key.length !== 24) {
    throw new SadadError(`TerminalKey must decode to 24 bytes, got ${key.length}.`);
  }
  return key;
}

/**
 * SignData = Base64(TripleDES-ECB-PKCS7(text, key)).
 * Same as Sadad's C# sample (TripleDES.Create, ECB, PKCS7, UTF8) and PHP openssl 'DES-EDE3'.
 */
export function signData(text, terminalKeyBase64) {
  const cipher = crypto.createCipheriv('des-ede3', decodeKey(terminalKeyBase64), null);
  cipher.setAutoPadding(true); // PKCS7
  return Buffer.concat([cipher.update(String(text), 'utf8'), cipher.final()]).toString('base64');
}

/** "MM/dd/yyyy hh:mm:ss AM" in Tehran time, as Sadad expects for LocalDateTime. */
export function localDateTime(date = new Date()) {
  const parts = Object.fromEntries(
    new Intl.DateTimeFormat('en-US', {
      timeZone: 'Asia/Tehran',
      year: 'numeric', month: '2-digit', day: '2-digit',
      hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true,
    }).formatToParts(date).map((p) => [p.type, p.value]),
  );
  return `${parts.month}/${parts.day}/${parts.year} ${parts.hour}:${parts.minute}:${parts.second} ${parts.dayPeriod.toUpperCase()}`;
}

/** Short, non-reversible fingerprint so logs can show which key was used without revealing it. */
export function keyFingerprint(terminalKeyBase64) {
  return crypto.createHash('sha256').update(decodeKey(terminalKeyBase64)).digest('hex').slice(0, 12);
}

export class SadadClient {
  /**
   * @param {object} opts
   * @param {string} opts.merchantId
   * @param {string} opts.terminalId
   * @param {string} opts.terminalKey  Base64 key from Sadad
   * @param {string} [opts.apiBaseUrl]       where PaymentRequest / Verify are POSTed
   * @param {string} [opts.purchaseBaseUrl]  where the customer's browser is sent
   * @param {number} [opts.timeoutMs]
   */
  constructor({ merchantId, terminalId, terminalKey, apiBaseUrl = SADAD_LIVE_URL, purchaseBaseUrl = SADAD_LIVE_URL, timeoutMs = 30000 }) {
    if (!/^\d+$/.test(String(merchantId))) throw new SadadError('MerchantId must contain digits only.');
    if (!/^\d+$/.test(String(terminalId))) throw new SadadError('TerminalId must contain digits only.');
    decodeKey(terminalKey); // fail fast on a malformed key
    this.merchantId = String(merchantId);
    this.terminalId = String(terminalId);
    this.terminalKey = terminalKey;
    this.apiBaseUrl = apiBaseUrl.replace(/\/+$/, '');
    this.purchaseBaseUrl = purchaseBaseUrl.replace(/\/+$/, '');
    this.timeoutMs = timeoutMs;
  }

  /**
   * Step 1: ask Sadad for a payment token.
   * @param {{orderId: string|number, amount: number, returnUrl: string}} p  amount in Rial
   */
  async paymentRequest({ orderId, amount, returnUrl }) {
    if (!Number.isSafeInteger(amount) || amount <= 0) throw new SadadError('Amount must be a positive integer (Rial).');
    const signSource = `${this.terminalId};${orderId};${amount}`;
    const payload = {
      TerminalId: this.terminalId,
      MerchantId: this.merchantId,
      Amount: amount,
      SignData: signData(signSource, this.terminalKey),
      ReturnUrl: returnUrl,
      LocalDateTime: localDateTime(),
      OrderId: String(orderId),
    };
    const response = await this.#post('/vpg/api/v0/Request/PaymentRequest', payload);
    const resCode = String(response.ResCode ?? '');
    const debug = { signSource, signSha256: sha256(payload.SignData), keyFingerprint: keyFingerprint(this.terminalKey), localDateTime: payload.LocalDateTime };
    if (resCode !== '0' || !response.Token) {
      throw new SadadError(describe(resCode, response.Description, this.terminalId), { resCode, response: { ...response, debug } });
    }
    return { token: String(response.Token), paymentUrl: this.paymentUrl(response.Token), response, debug };
  }

  /** Step 2: the URL the customer's browser must open. */
  paymentUrl(token) {
    return `${this.purchaseBaseUrl}/VPG/Purchase?Token=${encodeURIComponent(token)}`;
  }

  /**
   * Step 3: confirm (verify) the payment after the customer returns.
   * Returns Sadad's response; ResCode "0" means the money is settled for this merchant.
   */
  async verify(token) {
    const response = await this.#post('/vpg/api/v0/Advice/Verify', { Token: String(token), SignData: signData(String(token), this.terminalKey) });
    const resCode = String(response.ResCode ?? '');
    if (resCode !== '0') {
      throw new SadadError(describe(resCode, response.Description, this.terminalId), { resCode, response });
    }
    return {
      amount: response.Amount !== undefined ? Number(response.Amount) : null,
      retrievalRefNo: response.RetrivalRefNo != null ? String(response.RetrivalRefNo) : '', // sic: Sadad's spelling
      systemTraceNo: response.SystemTraceNo != null ? String(response.SystemTraceNo) : '',
      orderId: response.OrderId != null ? String(response.OrderId) : '',
      description: response.Description ?? '',
      response,
    };
  }

  async #post(path, payload) {
    let res;
    try {
      res = await fetch(this.apiBaseUrl + path, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json; charset=utf-8', Accept: 'application/json' },
        body: JSON.stringify(payload),
        signal: AbortSignal.timeout(this.timeoutMs),
      });
    } catch (err) {
      throw new SadadError(`ارتباط با سداد برقرار نشد: ${err.cause?.code || err.message}`);
    }
    const text = await res.text();
    let json;
    try { json = JSON.parse(text); } catch { json = null; }
    if (!res.ok || !json || typeof json !== 'object') {
      throw new SadadError(`پاسخ نامعتبر از سداد دریافت شد [HTTP ${res.status}]`, { response: text.slice(0, 500) });
    }
    return json;
  }
}

function sha256(s) {
  return crypto.createHash('sha256').update(s).digest('hex');
}

function describe(resCode, description, terminalId) {
  let msg = `${description || 'خطای نامشخص از سداد'} [ResCode: ${resCode || 'no-code'}]`;
  if (resCode === '1025') {
    msg += ` — TerminalKey با TerminalId ${terminalId} همخوانی ندارد؛ کلید را دقیقاً مطابق اطلاعات سداد وارد کنید.`;
  }
  return msg;
}
