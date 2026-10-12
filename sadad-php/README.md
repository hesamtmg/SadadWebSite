# Sadad payment — PHP

Takes payments through the **Sadad (Bank Melli) VPG** gateway, with a Persian (RTL) UI:

```
payment form → confirm page → PaymentRequest → Sadad bank page → callback → Verify → receipt
```

- Plain **PHP 8.1+**, no Composer packages (needs `ext-openssl` and `ext-curl`, both standard).
- **Built-in sandbox bank** (`SADAD_MODE=sandbox`): try the whole flow locally without credentials or money. It checks SignData with real TripleDES, like the bank.
- **Admin page** (`/admin`, password-protected) listing every transaction, each with a full event log: what was signed, Sadad's replies, the Verify result.
- `src/SadadClient.php` signs exactly like Sadad's C# `MakeSign` sample. The tests check it against fixed vectors.

## Run

PHP 8.1 or newer:

```bash
cd sadad-php
cp .env.example .env
PHP_CLI_SERVER_WORKERS=4 php -S localhost:8090 -t public public/index.php
```

Or Docker (Apache):

```bash
cd sadad-php
cp .env.example .env
docker compose up -d --build
docker compose logs -f app
```

| Page | URL |
|---|---|
| Payment form | http://localhost:8090 |
| Transactions | http://localhost:8090/admin (`admin` / `admin123`, from `.env`) |

### On shared hosting / your own Apache or nginx

- Make `public/` the document root. `public/.htaccess` routes everything to `index.php` and passes the `Authorization` header through for `/admin`.
- For nginx: `try_files $uri /index.php?$query_string;` plus the usual PHP-FPM block.
- `data/` (outside `public/`) must be writable by the web server user.
- Put settings in `.env` in the project root, or in real environment variables.

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
3. Host it where Sadad can reach it (public domain, usually an Iranian IP), on HTTPS.

If the live credentials are missing or malformed, every page shows a configuration error naming the problem. The admin page shows the TerminalId and a **key fingerprint** (a hash, not the key), so you can see which key is loaded.

## Using the client in your own project

`src/SadadClient.php` is self-contained:

```php
require 'src/SadadClient.php';
$sadad = new Sadad\SadadClient($merchantId, $terminalId, $terminalKey);

// 1. request a token, then redirect the customer to $r['paymentUrl']
$r = $sadad->paymentRequest('179182491416857', 2500000 /* Rial */, 'https://pay.example.com/payment/callback');

// 2. in your callback ($_POST OrderId, token, ResCode): if ResCode === '0'
$v = $sadad->verify($_POST['token']);   // throws Sadad\SadadException unless ResCode is "0"
// check $v['amount'] === your order amount, then mark the order paid ($v['systemTraceNo'], $v['retrievalRefNo'])
```

## How the payment works

| Step | Route | What |
|---|---|---|
| 1 | `POST /orders` | Validate amount, name and mobile; save the order; show the confirm page |
| 2 | `POST /orders/{id}/pay` | `PaymentRequest` with `SignData = TripleDES("TerminalId;OrderId;Amount")`, then redirect to Sadad's `/VPG/Purchase?Token=…` |
| 3 | `POST /payment/callback` | Sadad sends `OrderId`, `token` and `ResCode`; they're checked against the saved order |
| 4 | same | `Verify` with `SignData = TripleDES(Token)`. Paid only if `ResCode` is `0` **and** the verified amount equals the order amount |
| 5 | `GET /orders/{id}/result` | Receipt with SystemTraceNo and RRN, or the failure reason with a retry button |

- Amounts: with `CURRENCY=IRT` users enter Toman and Sadad gets Rial (×10).
- Every attempt gets a new numeric `OrderId`, because Sadad rejects reused ones.
- Orders live in `data/orders.json`, and each change happens under a file lock. The callback claims the order before Verify, so a refresh, double POST or replay never verifies twice, even with several PHP processes. Use a database for production volume.

## Errors

| Message | Meaning |
|---|---|
| `ResCode: 1025` «اطلاعات SignData ارسالی اشتباه است» | Sadad can't verify the signature with the key it has for this TerminalId. The key or TerminalId is wrong (keys are per terminal). |
| «ارتباط با سداد برقرار نشد» | The server can't reach `sadad.shaparak.ir` (network, firewall, or a non-Iranian IP) |
| «مبلغ تأییدشده با مبلغ سفارش مطابقت ندارد» | Verify returned a different amount; the order is not marked paid |

Logs: the PHP error log (`docker compose logs -f app`) and each order's event list at `/admin`.

## Tests

```bash
php tests/run.php
```

No PHPUnit needed. The runner covers the C# SignData vectors, key validation and the LocalDateTime format. It then starts `php -S` and tests the full HTTP flows: pay, replay, cancel plus retry, a forged success callback, validation, and admin auth.

## Files

```
sadad-php/
├── public/index.php        # front controller: routes and payment flow
├── public/.htaccess        # Apache rewrite + Authorization header
├── public/static/style.css
├── src/SadadClient.php     # SadadClient, SadadSign (SignData, LocalDateTime)
├── src/SandboxBank.php     # built-in test bank (in-process transport)
├── src/Storage.php         # locked JSON files, OrderStore
├── src/Views.php           # HTML pages (+ Jalali dates without ext-intl)
├── src/Config.php          # .env / environment settings
└── tests/run.php
```
