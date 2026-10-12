# Sadad payment — ASP.NET Core (.NET 8)

Takes payments through the **Sadad (Bank Melli) VPG** gateway, with a Persian (RTL) UI:

```
payment form → confirm page → PaymentRequest → Sadad bank page → callback → Verify → receipt
```

- ASP.NET Core minimal API. **No NuGet packages** in the app (tests use xUnit).
- **Built-in sandbox bank** (`SADAD_MODE=sandbox`): try the whole flow locally without credentials or money. It checks SignData with real TripleDES, like the bank.
- **Admin page** (`/admin`, password-protected) listing every transaction, each with a full event log: what was signed, Sadad's replies, the Verify result.
- `Sadad.cs` signs exactly like Sadad's C# `MakeSign` sample (TripleDES, ECB, PKCS7, UTF-8). The tests check it against fixed vectors.

## Run

.NET 8 SDK:

```bash
cd sadad-dotnet
cp .env.example .env
dotnet run --project src/SadadPayment
```

Or Docker:

```bash
cd sadad-dotnet
cp .env.example .env
docker compose up -d --build
docker compose logs -f app
```

| Page | URL |
|---|---|
| Payment form | http://localhost:5080 |
| Transactions | http://localhost:5080/admin (`admin` / `admin123`, from `.env`) |

The app reads `.env` from the directory you start it in. Real environment variables take precedence.

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
3. Run it on a server Sadad can reach (public domain, usually an Iranian IP), behind HTTPS (IIS, nginx or a reverse proxy in front of port 5080).

On start the app prints the TerminalId and a **key fingerprint** (a hash, not the key). In live mode it refuses to start if the credentials are missing or malformed.

## Using the client in your own project

Copy `src/SadadPayment/Sadad.cs`; it has no dependencies beyond the BCL:

```csharp
var sadad = new SadadClient(new SadadSettings(merchantId, terminalId, terminalKey, SadadClient.LiveUrl, SadadClient.LiveUrl));

// 1. request a token, then redirect the customer to r.PaymentUrl
var r = await sadad.PaymentRequestAsync(orderId: "179182491416857", amount: 2_500_000 /* Rial */, returnUrl: "https://pay.example.com/payment/callback");

// 2. in your callback (form fields OrderId, token, ResCode): if ResCode == "0"
var v = await sadad.VerifyAsync(token);   // throws SadadException unless ResCode is "0"
// check v.Amount == your order amount, then mark the order paid (v.SystemTraceNo, v.RetrievalRefNo)
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
- The callback claims the order atomically, so a refresh, double POST or replay never verifies twice.

## Errors

| Message | Meaning |
|---|---|
| `ResCode: 1025` «اطلاعات SignData ارسالی اشتباه است» | Sadad can't verify the signature with the key it has for this TerminalId. The key or TerminalId is wrong (keys are per terminal). |
| «ارتباط با سداد برقرار نشد» | The server can't reach `sadad.shaparak.ir` (network, firewall, or a non-Iranian IP) |
| «مبلغ تأییدشده با مبلغ سفارش مطابقت ندارد» | Verify returned a different amount; the order is not marked paid |

Logs: the console (`docker compose logs -f app`) and each order's event list at `/admin`.

## Tests

```bash
dotnet test
```

They cover the C# SignData vectors, key validation, the LocalDateTime format, and full HTTP flows via `WebApplicationFactory`: pay, replay, cancel plus retry, a forged success callback, validation, and admin auth.

## Files

```
sadad-dotnet/
├── src/SadadPayment/
│   ├── Sadad.cs        # SadadClient, SadadSign (SignData, LocalDateTime)
│   ├── Program.cs      # routes and payment flow
│   ├── Sandbox.cs      # built-in test bank + in-process HttpMessageHandler
│   ├── Views.cs        # HTML pages
│   ├── OrderStore.cs   # orders in data/orders.json (use a real DB for production)
│   ├── Config.cs       # .env / environment settings
│   └── wwwroot/style.css
└── tests/SadadPayment.Tests/
```
