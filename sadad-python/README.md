# Sadad payment — Python (Flask)

Takes payments through the **Sadad (Bank Melli) VPG** gateway, with a Persian (RTL) UI:

```
payment form → confirm page → PaymentRequest → Sadad bank page → callback → Verify → receipt
```

- **Flask** with Jinja templates, plus `cryptography` for TripleDES (Python's standard library has no 3DES).
- Orders in **SQLite** (standard library): atomic across gunicorn workers, nothing extra to install.
- **Built-in sandbox bank** (`SADAD_MODE=sandbox`): try the whole flow locally without credentials or money. It checks SignData with real TripleDES, like the bank.
- **Admin page** (`/admin`, password-protected) listing every transaction, each with a full event log: what was signed, Sadad's replies, the Verify result.
- `app/sadad.py` signs exactly like Sadad's C# `MakeSign` sample. The tests check it against fixed vectors.

## Run

Python 3.10 or newer:

```bash
cd sadad-python
python -m venv .venv
source .venv/bin/activate          # Windows: .venv\Scripts\activate
pip install -r requirements.txt
cp .env.example .env               # Windows: copy .env.example .env
python wsgi.py
```

Or Docker (gunicorn, 3 workers):

```bash
cd sadad-python
cp .env.example .env
docker compose up -d --build
docker compose logs -f app
```

| Page | URL |
|---|---|
| Payment form | http://localhost:8000 |
| Transactions | http://localhost:8000/admin (`admin` / `admin123`, from `.env`) |

For production use `gunicorn --preload -w 3 -b 0.0.0.0:8000 wsgi:app` (as in the Dockerfile) behind nginx with HTTPS. `python wsgi.py` is for development.

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
3. Run it on a server Sadad can reach (public domain, usually an Iranian IP), behind HTTPS.

On start the app logs the TerminalId and a **key fingerprint** (a hash, not the key). In live mode it refuses to start if the credentials are missing or malformed.

## Using the client in your own project

`app/sadad.py` depends only on `cryptography`:

```python
from app.sadad import SadadClient, SadadError

sadad = SadadClient(merchant_id, terminal_id, terminal_key)

# 1. request a token, then redirect the customer to r.payment_url
r = sadad.payment_request("179182491416857", 2_500_000, "https://pay.example.com/payment/callback")  # amount in Rial

# 2. in your callback (form fields OrderId, token, ResCode): if ResCode == "0"
v = sadad.verify(token)   # raises SadadError unless ResCode is "0"
# check v.amount == your order amount, then mark the order paid (v.system_trace_no, v.retrieval_ref_no)
```

## How the payment works

| Step | Route | What |
|---|---|---|
| 1 | `POST /orders` | Validate amount, name and mobile; save the order; show the confirm page |
| 2 | `POST /orders/<id>/pay` | `PaymentRequest` with `SignData = TripleDES("TerminalId;OrderId;Amount")`, then redirect to Sadad's `/VPG/Purchase?Token=…` |
| 3 | `POST /payment/callback` | Sadad sends `OrderId`, `token` and `ResCode`; they're checked against the saved order |
| 4 | same | `Verify` with `SignData = TripleDES(Token)`. Paid only if `ResCode` is `0` **and** the verified amount equals the order amount |
| 5 | `GET /orders/<id>/result` | Receipt with SystemTraceNo and RRN, or the failure reason with a retry button |

- Amounts: with `CURRENCY=IRT` users enter Toman and Sadad gets Rial (×10).
- Every attempt gets a new numeric `OrderId`, because Sadad rejects reused ones.
- The callback claims the order with a conditional SQL `UPDATE` before Verify, so a refresh, double POST or replay never verifies twice, even across workers.

## Errors

| Message | Meaning |
|---|---|
| `ResCode: 1025` «اطلاعات SignData ارسالی اشتباه است» | Sadad can't verify the signature with the key it has for this TerminalId. The key or TerminalId is wrong (keys are per terminal). |
| «ارتباط با سداد برقرار نشد» | The server can't reach `sadad.shaparak.ir` (network, firewall, or a non-Iranian IP) |
| «مبلغ تأییدشده با مبلغ سفارش مطابقت ندارد» | Verify returned a different amount; the order is not marked paid |

Logs: stdout (`docker compose logs -f app`) and each order's event list at `/admin`.

## Tests

```bash
pip install -r requirements-dev.txt
pytest
```

They cover the C# SignData vectors, key validation, the LocalDateTime format, and full flows through Flask's test client: pay, replay, cancel plus retry, a forged success callback, validation, and admin auth. They also start 12 processes at the same moment to check that they share one sandbox key and don't collide on the database.

## Files

```
sadad-python/
├── app/sadad.py       # SadadClient, sign_data, local_datetime
├── app/__init__.py    # create_app(): routes and payment flow
├── app/sandbox.py     # built-in test bank (in-process transport)
├── app/store.py       # SQLite orders + events
├── app/config.py      # .env / environment settings
├── app/templates/     # Jinja pages
├── app/static/style.css
├── wsgi.py
└── tests/
```
