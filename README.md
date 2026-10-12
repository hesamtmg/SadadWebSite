# Sadad (Bank Melli) payment gateway — examples

Working integrations of the **Sadad VPG** gateway (`sadad.shaparak.ir`), each in its own folder:

| Folder | Stack | Run | Port |
|---|---|---|---|
| [`woocommerce-sadad/`](woocommerce-sadad/) | WordPress + WooCommerce store with the Sadad plugin | `docker compose up -d` | 8080 |
| [`sadad-node/`](sadad-node/) | Node.js 18+, no dependencies | `npm start` | 3000 |
| [`sadad-dotnet/`](sadad-dotnet/) | ASP.NET Core (.NET 8), no NuGet packages | `dotnet run --project src/SadadPayment` | 5080 |
| [`sadad-php/`](sadad-php/) | PHP 8.1+, no Composer packages | `php -S localhost:8090 -t public public/index.php` | 8090 |
| [`sadad-python/`](sadad-python/) | Python 3.10+, Flask | `python wsgi.py` | 8000 |

Every folder also runs with `docker compose up -d --build`. Copy `.env.example` to `.env` first.

The four standalone apps (Node, .NET, PHP, Python) work the same way:

```
payment form → confirm page → PaymentRequest → Sadad bank page → callback → Verify → receipt
```

- A **sandbox mode** (the default) with a built-in test bank, so the whole flow runs locally without credentials or real money.
- An **admin page** listing every transaction with a step-by-step event log (what was signed, Sadad's replies).
- **SignData** is checked against the same test vectors produced by Sadad's C# `MakeSign` sample (TripleDES, ECB, PKCS7). All four implementations produce identical signatures.
- Safety checks: a new OrderId per attempt; the callback must match the saved token and OrderId; Verify runs at most once per payment; an order is paid only if the verified amount matches.

Each folder's README covers going live, using the client in your own code, and troubleshooting (for example ResCode 1025).
