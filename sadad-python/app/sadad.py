"""Sadad (Bank Melli) VPG client: PaymentRequest, purchase redirect and Verify."""
from __future__ import annotations

import base64
import binascii
import hashlib
import json
import re
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass, field
from datetime import datetime, timezone
from typing import Any, Callable
from zoneinfo import ZoneInfo

from cryptography.hazmat.primitives import padding
from cryptography.hazmat.primitives.ciphers import Cipher, modes

try:  # cryptography >= 43 moved TripleDES to the "decrepit" module
    from cryptography.hazmat.decrepit.ciphers.algorithms import TripleDES
except ImportError:  # pragma: no cover
    from cryptography.hazmat.primitives.ciphers.algorithms import TripleDES

SADAD_LIVE_URL = "https://sadad.shaparak.ir"
TEHRAN = ZoneInfo("Asia/Tehran")

Transport = Callable[[str, dict], dict]


class SadadError(Exception):
    def __init__(self, message: str, res_code: str | None = None, response: Any = None):
        super().__init__(message)
        self.res_code = res_code
        self.response = response


def decode_key(terminal_key_b64: str) -> bytes:
    """Decode the Base64 TerminalKey; Sadad keys are exactly 24 bytes (TripleDES)."""
    clean = re.sub(r"\s+", "", terminal_key_b64 or "")
    try:
        key = base64.b64decode(clean, validate=True) if clean else b""
    except (binascii.Error, ValueError):
        raise SadadError("TerminalKey is not valid Base64.") from None
    if not clean:
        raise SadadError("TerminalKey is not valid Base64.")
    if len(key) != 24:
        raise SadadError(f"TerminalKey must decode to 24 bytes, got {len(key)}.")
    return key


def sign_data(text: str, terminal_key_b64: str) -> str:
    """SignData = Base64(TripleDES-ECB-PKCS7(UTF8(text), key)) — same as Sadad's C# MakeSign."""
    padder = padding.PKCS7(64).padder()
    data = padder.update(str(text).encode("utf-8")) + padder.finalize()
    encryptor = Cipher(TripleDES(decode_key(terminal_key_b64)), modes.ECB()).encryptor()
    return base64.b64encode(encryptor.update(data) + encryptor.finalize()).decode("ascii")


def local_datetime(at: datetime | None = None) -> str:
    """"MM/dd/yyyy hh:mm:ss AM" in Tehran time, as Sadad expects for LocalDateTime."""
    t = (at or datetime.now(timezone.utc)).astimezone(TEHRAN)
    return f"{t:%m/%d/%Y %I:%M:%S} {'AM' if t.hour < 12 else 'PM'}"  # avoid locale-dependent %p


def key_fingerprint(terminal_key_b64: str) -> str:
    """Short, non-reversible fingerprint so logs can show which key is used without revealing it."""
    return hashlib.sha256(decode_key(terminal_key_b64)).hexdigest()[:12]


@dataclass
class PaymentRequestResult:
    token: str
    payment_url: str
    debug: dict[str, str]


@dataclass
class VerifyResult:
    amount: int | None
    retrieval_ref_no: str
    system_trace_no: str
    order_id: str
    description: str
    response: dict = field(repr=False)


class SadadClient:
    def __init__(
        self,
        merchant_id: str,
        terminal_id: str,
        terminal_key: str,
        api_base_url: str = SADAD_LIVE_URL,
        purchase_base_url: str = SADAD_LIVE_URL,
        timeout: float = 30,
        transport: Transport | None = None,
    ):
        """`transport(url, payload) -> dict` replaces the HTTP call; the sandbox passes an in-process one."""
        if not re.fullmatch(r"\d+", merchant_id or ""):
            raise SadadError("MerchantId must contain digits only.")
        if not re.fullmatch(r"\d+", terminal_id or ""):
            raise SadadError("TerminalId must contain digits only.")
        decode_key(terminal_key)  # fail fast on a malformed key
        self.merchant_id = merchant_id
        self.terminal_id = terminal_id
        self._terminal_key = terminal_key
        self.api_base_url = api_base_url.rstrip("/")
        self.purchase_base_url = purchase_base_url.rstrip("/")
        self.timeout = timeout
        self._transport = transport or self._http_post

    @property
    def key_fingerprint(self) -> str:
        return key_fingerprint(self._terminal_key)

    def payment_request(self, order_id: str, amount: int, return_url: str) -> PaymentRequestResult:
        """Step 1: ask Sadad for a payment token. `amount` is in Rial."""
        if not isinstance(amount, int) or amount <= 0:
            raise SadadError("Amount must be a positive integer (Rial).")
        sign_source = f"{self.terminal_id};{order_id};{amount}"
        payload = {
            "TerminalId": self.terminal_id,
            "MerchantId": self.merchant_id,
            "Amount": amount,
            "SignData": sign_data(sign_source, self._terminal_key),
            "ReturnUrl": return_url,
            "LocalDateTime": local_datetime(),
            "OrderId": str(order_id),
        }
        debug = {
            "signSource": sign_source,
            "signSha256": hashlib.sha256(payload["SignData"].encode()).hexdigest(),
            "keyFingerprint": self.key_fingerprint,
            "localDateTime": payload["LocalDateTime"],
        }
        response = self._post("/vpg/api/v0/Request/PaymentRequest", payload)
        res_code = _str(response.get("ResCode"))
        token = _str(response.get("Token"))
        if res_code != "0" or not token:
            raise SadadError(self._describe(res_code, _str(response.get("Description"))), res_code, {**response, "debug": debug})
        return PaymentRequestResult(token, self.payment_url(token), debug)

    def payment_url(self, token: str) -> str:
        """Step 2: the URL the customer's browser must open."""
        return f"{self.purchase_base_url}/VPG/Purchase?Token={urllib.parse.quote(token, safe='')}"

    def verify(self, token: str) -> VerifyResult:
        """Step 3: confirm the payment after the customer returns. Raises unless ResCode is "0"."""
        response = self._post("/vpg/api/v0/Advice/Verify", {"Token": token, "SignData": sign_data(token, self._terminal_key)})
        res_code = _str(response.get("ResCode"))
        if res_code != "0":
            raise SadadError(self._describe(res_code, _str(response.get("Description"))), res_code, response)
        amount = response.get("Amount")
        return VerifyResult(
            amount=int(amount) if amount is not None else None,
            retrieval_ref_no=_str(response.get("RetrivalRefNo")),  # sic: Sadad's spelling
            system_trace_no=_str(response.get("SystemTraceNo")),
            order_id=_str(response.get("OrderId")),
            description=_str(response.get("Description")),
            response=response,
        )

    def _post(self, path: str, payload: dict) -> dict:
        return self._transport(self.api_base_url + path, payload)

    def _http_post(self, url: str, payload: dict) -> dict:
        req = urllib.request.Request(
            url,
            data=json.dumps(payload, ensure_ascii=False).encode("utf-8"),
            headers={"Content-Type": "application/json; charset=utf-8", "Accept": "application/json"},
            method="POST",
        )
        try:
            with urllib.request.urlopen(req, timeout=self.timeout) as res:
                status, body = res.status, res.read()
        except urllib.error.HTTPError as e:
            status, body = e.code, e.read()
        except (urllib.error.URLError, TimeoutError, OSError) as e:
            raise SadadError(f"ارتباط با سداد برقرار نشد: {getattr(e, 'reason', e)}") from None
        try:
            data = json.loads(body)
        except ValueError:
            data = None
        if not (200 <= status < 300) or not isinstance(data, dict):
            raise SadadError(f"پاسخ نامعتبر از سداد دریافت شد [HTTP {status}]")
        return data

    def _describe(self, res_code: str, description: str) -> str:
        msg = f"{description or 'خطای نامشخص از سداد'} [ResCode: {res_code or 'no-code'}]"
        if res_code == "1025":
            msg += f" — TerminalKey با TerminalId {self.terminal_id} همخوانی ندارد؛ کلید را دقیقاً مطابق اطلاعات سداد وارد کنید."
        return msg


def _str(v: Any) -> str:
    return "" if v is None else str(v)
