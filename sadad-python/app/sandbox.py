"""Built-in Sadad sandbox (SADAD_MODE=sandbox): same endpoints and JSON as sadad.shaparak.ir.
SignData is really checked with the sandbox key, so signing bugs fail here too. Accepts any card."""
from __future__ import annotations

import hmac
import logging
import secrets
from urllib.parse import urlparse

from .config import SadadSettings
from .sadad import sign_data
from .store import Store

log = logging.getLogger("sadad.sandbox")


class SandboxBank:
    def __init__(self, creds: SadadSettings, store: Store):
        self.creds = creds
        self.store = store

    def transport(self, url: str, payload: dict) -> dict:
        """In-process stand-in for the HTTP call (no network, no request to ourselves)."""
        return self.handle_api(urlparse(url).path, payload)

    def _sign_ok(self, sign, text: str) -> bool:
        return hmac.compare_digest(sign_data(text, self.creds.terminal_key), str(sign or ""))

    def handle_api(self, path: str, r: dict) -> dict:
        if path.endswith("/vpg/api/v0/Request/PaymentRequest"):
            log.info("PaymentRequest OrderId=%s Amount=%s", r.get("OrderId"), r.get("Amount"))
            if str(r.get("TerminalId")) != self.creds.terminal_id or str(r.get("MerchantId")) != self.creds.merchant_id:
                return {"ResCode": "3", "Token": None, "Description": "پذیرنده کارت فعال نیست."}
            if not self._sign_ok(r.get("SignData"), f"{r.get('TerminalId')};{r.get('OrderId')};{r.get('Amount')}"):
                return {"ResCode": "1025", "Token": None, "Description": "اطلاعات SignData ارسالی اشتباه است"}
            amount = r.get("Amount")
            if not isinstance(amount, int) or amount < 1000:
                return {"ResCode": "-1", "Token": None, "Description": "مبلغ تراکنش نامعتبر است."}
            token = secrets.token_hex(12).upper()
            with self.store.db() as db:
                db.execute("INSERT INTO sandbox_tokens (token, order_id, amount, return_url, status) VALUES (?, ?, ?, ?, 'created')",
                           (token, str(r.get("OrderId")), amount, str(r.get("ReturnUrl"))))
            return {"ResCode": "0", "Token": token, "Description": "عملیات با موفقیت انجام شد"}

        if path.endswith("/vpg/api/v0/Advice/Verify"):
            token = str(r.get("Token") or "")
            log.info("Verify Token=%s", token)
            if not self._sign_ok(r.get("SignData"), token):
                return {"ResCode": "-1", "Description": "اطلاعات SignData ارسالی اشتباه است"}
            with self.store.db() as db:
                tx = db.execute("SELECT * FROM sandbox_tokens WHERE token = ?", (token,)).fetchone()
                if not tx or tx["status"] not in ("paid", "verified"):
                    return {"ResCode": "-1", "Description": "تراکنش یافت نشد یا پرداخت نشده است."}
                db.execute("UPDATE sandbox_tokens SET status = 'verified' WHERE token = ?", (token,))
            return {"ResCode": "0", "Amount": tx["amount"], "Description": "عملیات با موفقیت انجام شد",
                    "RetrivalRefNo": tx["rrn"], "SystemTraceNo": tx["trace"], "OrderId": tx["order_id"]}

        return {"ResCode": "-1", "Description": "Unknown sandbox endpoint"}

    def get_open(self, token: str) -> dict | None:
        with self.store.db() as db:
            tx = db.execute("SELECT * FROM sandbox_tokens WHERE token = ? AND status = 'created'", (token,)).fetchone()
            return dict(tx) if tx else None

    def pay(self, token: str, action: str) -> dict | None:
        """Customer clicked pay/cancel. Returns the fields the bank POSTs back to ReturnUrl."""
        paid = action == "pay"
        with self.store.db() as db:
            tx = db.execute("SELECT * FROM sandbox_tokens WHERE token = ? AND status = 'created'", (token,)).fetchone()
            if not tx:
                return None
            db.execute("UPDATE sandbox_tokens SET status = ?, trace = ?, rrn = ? WHERE token = ?",
                       ("paid" if paid else "canceled",
                        str(secrets.randbelow(900000) + 100000) if paid else None,
                        str(secrets.randbelow(900000000000) + 100000000000) if paid else None, token))
        return {"return_url": tx["return_url"], "fields": {"OrderId": tx["order_id"], "token": token, "ResCode": "0" if paid else "-1"}}
