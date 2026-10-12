# WooCommerce + Sadad (Bank Melli) demo store

A complete WordPress + WooCommerce shop with 6 sample products and the **Sadad electronic payment** gateway, running in Docker.
It includes a local **Sadad sandbox** (a fake bank), so you can test the whole checkout flow on your own computer without real credentials or real money.

```
Customer ──► WooCommerce checkout ──► Sadad PaymentRequest ──► bank page ──► callback ──► Sadad Verify ──► order paid
```

## Requirements

- [Docker Desktop](https://www.docker.com/products/docker-desktop/) (Windows / macOS) or Docker Engine + Compose v2 (Linux)
- Internet access on the first run (downloads WordPress, WooCommerce and the Persian language pack)

## Run it

```bash
cd woocommerce-sadad
cp .env.example .env        # Windows PowerShell: Copy-Item .env.example .env
docker compose up -d
docker compose logs -f setup   # wait for "Store: http://localhost:8080", then Ctrl+C
```

| What | URL |
|---|---|
| Shop | http://localhost:8080 |
| Admin | http://localhost:8080/wp-admin (user `admin`, password `admin123`) |
| Sadad dashboard | Admin → «پرداخت الکترونیک سداد» |
| Sandbox bank | http://localhost:8081 |

The first start takes 1–3 minutes. Later starts are instant: the setup step sees everything is already installed and skips it.

## Try a payment

1. Open http://localhost:8080 and add products to the cart.
2. Go to **Checkout**, fill in the address and choose **«پرداخت با کارت بانکی (سداد)»**.
3. Click **Place Order**. You are sent to the sandbox bank page (orange "محیط آزمایشی" bar).
4. Click **«پرداخت»** to pay or **«انصراف»** to cancel.
   - Pay → you land on *Order received*; the order becomes **Processing** with the bank tracking code.
   - Cancel → you return to the pay-for-order page with the error, and the attempt is logged as failed.
5. See all attempts under **Admin → پرداخت الکترونیک سداد → پرداخت‌ها**.

## What gets installed

- WordPress 7.1 (PHP 8.3, Apache), MariaDB 11.4, WooCommerce 11.2.1, in Persian (`fa_IR`)
- Currency **Toman (IRT)**. The gateway converts to Rial (×10) before sending amounts to Sadad.
- 6 products in 3 categories (two on sale)
- Shipping: flat rate 95,000 Toman, free above 3,000,000 Toman
- Sadad gateway enabled and pre-configured with the sandbox credentials from `.env`

## Going live with real Sadad

1. Get **MerchantId**, **TerminalId** and **TerminalKey** from Bank Melli / Sadad.
2. Set them in the admin: **پرداخت الکترونیک سداد → تنظیمات درگاه**. Or put them in `.env` before the very first `docker compose up`.
3. Set `SADAD_MODE=live` in `.env`, then run `docker compose up -d` again.
4. Sadad only talks to a server it can reach. The site needs a public domain (usually on an Iranian IP), and Sadad must have your callback URL registered. The settings page shows that URL under «آدرس بازگشت». `localhost` will not work with the real bank.

## Folder layout

```
woocommerce-sadad/
├── docker-compose.yml        # db, wordpress, setup (one-shot), sadad-mock
├── .env.example              # ports, admin login, store and Sadad settings
├── plugins/sadad-electronic-payment/   # the gateway plugin (mounted into WordPress)
├── mu-plugins/sadad-sandbox.php        # points the gateway at the sandbox when SADAD_MODE=sandbox
├── sadad-mock/index.php                # the fake Sadad bank (PaymentRequest / Purchase / Verify)
└── setup/                              # setup.sh + product images
```

The plugin folder is mounted live, so edits to its PHP files take effect on the next page load.

## Useful commands

```bash
docker compose ps                     # status
docker compose logs -f wordpress      # PHP / Apache logs
docker compose logs -f sadad-mock     # every request the sandbox bank receives
docker compose run --rm --entrypoint wp setup plugin list   # run any WP-CLI command
docker compose down                   # stop (keeps data)
docker compose down -v                # stop and DELETE everything (fresh install on next up)
```

## Changes made to the original plugin (v2.2.2)

- **Checkout block support**: new `includes/class-sadad-electronic-payment-blocks.php` + `assets/blocks.js`. Without it the gateway is missing from WooCommerce's default (block) checkout, which says "There are no payment methods available".
- Declares compatibility with HPOS (custom order tables) and the cart/checkout blocks.
- Endpoints go through a `sadad_electronic_payment_endpoints` filter, so the sandbox can replace them. The defaults are still the real `sadad.shaparak.ir` URLs.
- Fixed the settings-page health check that showed **DES-EDE3 «در دسترس نیست»** on OpenSSL 3 (the cipher is listed as `des-ede3-ecb` there; encryption itself always worked).

## Troubleshooting

- **Port already in use**: change `WP_PORT` / `SADAD_SANDBOX_PORT` in `.env`. If you change `WP_PORT`, also change `SITE_URL`, then run `docker compose down -v && docker compose up -d`.
- **Setup failed downloading WooCommerce**: check internet access, then run `docker compose up setup` again.
- **Site shows in English**: the language pack download failed. Run `docker compose up setup` again when online.
