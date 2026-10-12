# Sadad payment — Node.js

A small Node.js app that takes payments through the **Sadad (Bank Melli) VPG** gateway, with a Persian (RTL) UI:

```
payment form → confirm page → PaymentRequest → Sadad bank page → callback → Verify → receipt
```

- **No dependencies**: plain Node.js 18+ (`http`, `crypto`, `fetch`). Nothing to `npm install`.
- **Built-in sandbox bank** (`SADAD_MODE=sandbox`) so you can try the whole flow locally without credentials or money. It checks SignData with real TripleDES, like the bank.
- **Admin page** (`/admin`, password-protected) listing every transaction. Each one has a full event log: what was signed, Sadad's replies, Verify result.
- Signing is tested against Sadad's C# `MakeSign` output (see `test/sadad.test.js`).

## Run

Node.js 18.17 or newer:

```bash
cd sadad-node
cp .env.example .env
npm start
```

Or with Docker:

```bash
cd sadad-node
cp .env.example .env
docker compose up -d --build
docker compose logs -f app
```

| Page | URL |
|---|---|
| Payment form | http://localhost:3000 |
| Transactions | http://localhost:3000/admin (`admin` / `admin123`, from `.env`) |

In sandbox mode the bank page has an orange "محیط آزمایشی" bar: **«پرداخت»** pays, **«انصراف»** cancels.

## Going live

1. In `.env` set:
   ```
   SADAD_MODE=live
   SADAD_MERCHANT_ID=...      # from Sadad
   SADAD_TERMINAL_ID=...
   SADAD_TERMINAL_KEY=...     # Base64, 24 bytes after decoding
   APP_URL=https://pay.example.com   # your public address
   ADMIN_PASSWORD=<something strong>
   ```
2. Register `APP_URL/payment/callback` with Sadad as your return URL.
3. Run it on a server Sadad can reach (public domain, usually an Iranian IP), behind HTTPS (nginx, Caddy, etc. in front of port 3000).

On start the app prints the TerminalId and a **key fingerprint** (a hash, not the key), so you can see which key is loaded. In live mode it refuses to start if the credentials are missing or malformed.

## How the payment works

| Step | Where | What |
|---|---|---|
| 1 | `POST /orders` | Validate amount/name/mobile, save order, show confirm page |
| 2 | `POST /orders/:id/pay` | `PaymentRequest` with `SignData = TripleDES("TerminalId;OrderId;Amount")`, then redirect to `https://sadad.shaparak.ir/VPG/Purchase?Token=…` |
| 3 | `POST /payment/callback` | Sadad sends back `OrderId`, `token`, `ResCode`. Checked against the saved order |
| 4 | same | `Verify` with `SignData = TripleDES(Token)`. Paid only if `ResCode` is `0` **and** the verified amount equals the order amount |
| 5 | `GET /orders/:id/result` | Receipt with trace number (SystemTraceNo) and RRN, or the failure reason with a retry button |

Details:
- Amounts: with `CURRENCY=IRT` users enter Toman and Sadad gets Rial (×10).
- Every payment attempt gets a new numeric `OrderId`, because Sadad rejects reused ones. A retry after a cancel or failure just works.
- A refreshed or replayed callback never verifies twice.
- Code: `src/sadad.js` is the reusable Sadad client (`SadadClient.paymentRequest()`, `.verify()`, `signData()`). Copy it into another project if you only need the API.

## Compare SignData with another implementation

```bash
SADAD_TERMINAL_KEY=<base64 key> npm run sign -- "24095674;123;124950000"
```

It prints the SignData for that input. Feed the same string and key to your C# `MakeSign` and the output must be identical. For a real attempt, the admin detail page shows the exact `signSource` the app sent.

## Errors

| Message | Meaning |
|---|---|
| `ResCode: 1025` «اطلاعات SignData ارسالی اشتباه است» | Sadad can't verify the signature with the key it has for this TerminalId. The key or TerminalId in `.env` is wrong (keys are per terminal). |
| `ResCode: 3` | Merchant or terminal inactive, or wrong MerchantId |
| «ارتباط با سداد برقرار نشد» | The server can't reach `sadad.shaparak.ir` (network, firewall, or a non-Iranian IP) |
| «مبلغ تأییدشده با مبلغ سفارش مطابقت ندارد» | Verify returned a different amount; the order is not marked paid |

Logs: the console (`docker compose logs -f app`) and each order's event list at `/admin`.

## Tests

```bash
npm test
```

They cover SignData against the C# vectors, key validation, the LocalDateTime format, and full HTTP flows against the sandbox: pay, cancel plus retry, a forged success callback (rejected by Verify), validation, and admin auth.

## Files

```
sadad-node/
├── src/sadad.js     # Sadad client: signData, PaymentRequest, Verify
├── src/server.js    # HTTP routes and payment flow
├── src/sandbox.js   # built-in test bank (sandbox mode only)
├── src/views.js     # HTML pages
├── src/store.js     # orders in data/orders.json (use a real DB for production)
├── src/config.js    # .env / environment settings
├── public/style.css
├── scripts/sign.js  # print SignData for comparison
└── test/            # node --test
```
