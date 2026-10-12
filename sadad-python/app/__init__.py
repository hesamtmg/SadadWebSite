"""Sadad payment app: form → confirm → Sadad PaymentRequest → bank → callback → Verify → receipt."""
from __future__ import annotations

import base64
import hmac
import json
import logging
import re
import sys
from datetime import datetime, timezone

from flask import Flask, Response, abort, redirect, render_template, request

from .config import Config
from .sadad import TEHRAN, SadadClient, SadadError
from .sandbox import SandboxBank
from .store import Store, now

MIN_RIAL = 1000
MAX_RIAL = 5_000_000_000  # 500 million Toman; adjust to your terminal's limit
ORDER_ID = re.compile(r"^[a-f0-9]{16}$")
FA = str.maketrans("0123456789", "۰۱۲۳۴۵۶۷۸۹")
ASCII = str.maketrans("۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩", "01234567890123456789")

log = logging.getLogger("sadad")


def jalali(gy: int, gm: int, gd: int) -> tuple[int, int, int]:
    """Gregorian → Jalali (Persian calendar)."""
    g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334]
    gy2 = gy + 1 if gm > 2 else gy
    days = 355666 + 365 * gy + (gy2 + 3) // 4 - (gy2 + 99) // 100 + (gy2 + 399) // 400 + gd + g_d_m[gm - 1]
    jy = -1595 + 33 * (days // 12053)
    days %= 12053
    jy += 4 * (days // 1461)
    days %= 1461
    if days > 365:
        jy += (days - 1) // 365
        days = (days - 1) % 365
    if days < 186:
        return jy, 1 + days // 31, 1 + days % 31
    return jy, 7 + (days - 186) // 30, 1 + (days - 186) % 30


def create_app(config: Config | None = None) -> Flask:
    config = config or Config.load()
    store = Store(config.data_dir / "sadad.db")
    bank = SandboxBank(config.sandbox, store) if config.is_sandbox else None
    s = config.sadad
    try:
        sadad = SadadClient(s.merchant_id, s.terminal_id, s.terminal_key, s.api_base_url, s.purchase_base_url, s.timeout,
                            bank.transport if bank else None)
    except SadadError as e:
        hint = "" if config.is_sandbox else " Set SADAD_MERCHANT_ID, SADAD_TERMINAL_ID and SADAD_TERMINAL_KEY in .env."
        sys.exit(f"Sadad configuration error ({config.mode} mode): {e}.{hint}")

    app = Flask(__name__)
    logging.basicConfig(level=logging.INFO, format="[%(asctime)s] %(name)s %(message)s")

    # ---------------------------------------------------------------- template helpers

    @app.template_filter("fa_digits")
    def fa_digits(v) -> str:
        return str(v if v is not None else "").translate(FA)

    @app.template_filter("fa_num")
    def fa_num(n) -> str:
        return f"{int(n):,}".replace(",", "٬").translate(FA)

    @app.template_filter("fa_date")
    def fa_date(iso: str | None) -> str:
        t = (datetime.fromisoformat(iso) if iso else datetime.now(timezone.utc)).astimezone(TEHRAN)
        y, m, d = jalali(t.year, t.month, t.day)
        return f"{y:04d}/{m:02d}/{d:02d} {t:%H:%M}".translate(FA)

    @app.template_filter("tojson_pretty")
    def tojson_pretty(v) -> str:
        return json.dumps(v if v is not None else {}, ensure_ascii=False, indent=2)

    def page(template: str, title: str, status: int = 200, **ctx) -> tuple[str, int]:
        return render_template(template, title=title, sandbox=config.is_sandbox, **ctx), status

    @app.after_request
    def security_headers(resp: Response) -> Response:
        resp.headers.setdefault("X-Content-Type-Options", "nosniff")
        resp.headers.setdefault("Referrer-Policy", "same-origin")
        resp.headers.setdefault("X-Frame-Options", "DENY")
        return resp

    def require_admin() -> None:
        if not config.admin_password:
            return
        auth = request.headers.get("Authorization", "")
        if auth.startswith("Basic "):
            try:
                user, _, pw = base64.b64decode(auth[6:]).decode("utf-8").partition(":")
            except (ValueError, UnicodeDecodeError):
                user = pw = ""
            if hmac.compare_digest(user.encode(), config.admin_user.encode()) and hmac.compare_digest(pw.encode(), config.admin_password.encode()):
                return
        abort(Response("Authentication required", 401, {"WWW-Authenticate": 'Basic realm="Sadad admin", charset="UTF-8"'}))

    def get_order(order_id: str) -> dict:
        order = store.get(order_id) if ORDER_ID.match(order_id) else None
        if not order:
            abort(Response(*page("error.html", "یافت نشد", 404, message="سفارش پیدا نشد.")))
        return order

    # ---------------------------------------------------------------- pages

    @app.get("/")
    def home():
        return page("home.html", "پرداخت آنلاین", currency=config.currency, values={}, error=None)

    @app.get("/health")
    def health():
        return {"ok": True, "mode": config.mode}

    @app.post("/orders")
    def create_order():
        f = request.form
        values = {k: f.get(k, "") for k in ("amount", "name", "mobile", "description")}
        amount_text = re.sub(r"[,\s٬،]", "", values["amount"].translate(ASCII))
        name = values["name"].strip()[:80]
        mobile = values["mobile"].translate(ASCII).replace(" ", "")
        description = values["description"].strip()[:120]

        amount = int(amount_text) if amount_text.isascii() and amount_text.isdigit() and len(amount_text) < 16 else 0
        amount_rial = amount * 10 if config.currency == "IRT" else amount
        error = None
        if amount <= 0:
            error = "مبلغ را به صورت عدد صحیح وارد کنید."
        elif amount_rial < MIN_RIAL:
            minimum = MIN_RIAL // 10 if config.currency == "IRT" else MIN_RIAL
            error = f"حداقل مبلغ {fa_num(minimum)} {'تومان' if config.currency == 'IRT' else 'ریال'} است."
        elif amount_rial > MAX_RIAL:
            error = "مبلغ بیش از سقف مجاز است."
        elif not name:
            error = "نام پرداخت‌کننده را وارد کنید."
        elif mobile and not re.fullmatch(r"09\d{9}", mobile):
            error = "شماره موبایل باید ۱۱ رقم و با ۰۹ شروع شود."
        if error:
            return page("home.html", "پرداخت آنلاین", 422, currency=config.currency, values=values, error=error)

        order = store.create(amount, amount_rial, config.currency, name, mobile, description)
        return redirect(f"/orders/{order['id']}", 303)

    @app.get("/orders/<order_id>")
    def confirm(order_id: str):
        order = get_order(order_id)
        if order["status"] != "created":
            return redirect(f"/orders/{order_id}/result", 303)
        return page("confirm.html", "تأیید اطلاعات پرداخت", o=order, error=None)

    @app.get("/orders/<order_id>/result")
    def result(order_id: str):
        return page("result.html", "نتیجه پرداخت", o=get_order(order_id))

    # ---------------------------------------------------------------- step 1–2: PaymentRequest and redirect

    @app.post("/orders/<order_id>/pay")
    def pay(order_id: str):
        order = get_order(order_id)
        if order["status"] not in ("created", "failed", "canceled"):
            return redirect(f"/orders/{order_id}/result", 303)
        bank_order_id = store.next_bank_order_id()  # fresh OrderId per attempt: Sadad rejects reused ones
        return_url = f"{config.app_url}/payment/callback"
        try:
            r = sadad.payment_request(bank_order_id, order["amount_rial"], return_url)
        except SadadError as e:
            store.update(order_id, {"status": "failed", "bank_order_id": bank_order_id, "message": str(e)}, "payment_request.error",
                         {"bankOrderId": bank_order_id, "resCode": e.res_code, "response": e.response, "message": str(e)})
            log.info("PaymentRequest failed %s", {"order": order_id, "bankOrderId": bank_order_id, "resCode": e.res_code, "message": str(e)})
            return page("confirm.html", "تأیید اطلاعات پرداخت", 502, o=store.get(order_id),
                        error=f"درخواست پرداخت توسط سداد پذیرفته نشد: {e}")
        store.update(order_id, {"status": "pending", "bank_order_id": bank_order_id, "token": r.token, "message": ""}, "payment_request.ok",
                     {**r.debug, "bankOrderId": bank_order_id, "token": r.token, "returnUrl": return_url})
        log.info("PaymentRequest ok %s", {"order": order_id, "bankOrderId": bank_order_id, "amountRial": order["amount_rial"]})
        return redirect(r.payment_url, 303)

    # ---------------------------------------------------------------- step 3–4: bank callback and Verify

    @app.route("/payment/callback", methods=["POST", "GET"])  # Sadad posts back here; accept GET too
    def callback():
        data = request.form if request.method == "POST" else request.args
        token = (data.get("token") or data.get("Token") or "").strip()
        res_code = (data.get("ResCode") or data.get("rescode") or "").strip()
        posted_order_id = (data.get("OrderId") or data.get("orderId") or "").translate(ASCII).strip()
        order = store.find_by("token", token) or store.find_by("bank_order_id", posted_order_id)
        log.info("Callback %s", {"token": token, "resCode": res_code, "postedOrderId": posted_order_id, "found": bool(order)})
        if not order:
            return page("error.html", "خطا", 400, message="سفارش مربوط به این پرداخت پیدا نشد.")

        oid = order["id"]
        result_url = f"/orders/{oid}/result"

        def fail(status: str, message: str, info: dict):
            store.update(oid, {"status": status, "message": message}, f"callback.{status}", {**info, "message": message},
                         only_if_status=("pending", "verifying", "failed", "canceled", "created"))
            return redirect(result_url, 303)

        if order["status"] in ("paid", "verifying"):
            return redirect(result_url, 303)  # refresh / double POST
        if token and order["token"] and token != order["token"]:
            return fail("failed", "توکن بازگشتی با درخواست اولیه مطابقت ندارد.", {"token": token})
        if posted_order_id and posted_order_id != order["bank_order_id"]:
            return fail("failed", "شماره سفارش بازگشتی با درخواست اولیه مطابقت ندارد.", {"postedOrderId": posted_order_id})
        if res_code != "0" or not token:
            return fail("canceled", f"پرداخت در بانک انجام نشد یا توسط شما لغو شد. [ResCode: {res_code or 'نامشخص'}]", {"resCode": res_code})

        # Claim the order atomically (conditional UPDATE) so two concurrent callbacks never verify twice.
        if not store.update(oid, {"status": "verifying"}, "callback.ok", {"resCode": res_code, "token": token},
                            only_if_status=("pending", "failed", "canceled")):
            return redirect(result_url, 303)

        try:
            v = sadad.verify(token)
        except SadadError as e:
            log.info("Verify failed %s", {"order": oid, "resCode": e.res_code, "message": str(e)})
            return fail("failed", f"تأیید تراکنش در سداد ناموفق بود: {e}", {"resCode": e.res_code, "response": e.response})
        if v.amount is not None and v.amount != order["amount_rial"]:
            return fail("failed", "مبلغ تأییدشده با مبلغ سفارش مطابقت ندارد. با پشتیبانی تماس بگیرید.",
                        {"expected": order["amount_rial"], "verified": v.amount, "response": v.response})
        store.update(oid, {"status": "paid", "message": "", "paid_at": now(), "system_trace_no": v.system_trace_no,
                           "retrieval_ref_no": v.retrieval_ref_no}, "verify.ok", v.response)
        log.info("Verify ok %s", {"order": oid, "trace": v.system_trace_no, "rrn": v.retrieval_ref_no})
        return redirect(result_url, 303)

    # ---------------------------------------------------------------- admin

    @app.get("/admin")
    def admin():
        require_admin()
        return page("admin.html", "تراکنش‌ها", orders=store.all(), terminal_id=sadad.terminal_id, key_fp=sadad.key_fingerprint)

    @app.get("/admin/orders/<order_id>")
    def admin_order(order_id: str):
        require_admin()
        return page("admin_order.html", "جزئیات تراکنش", o=get_order(order_id), events=store.events(order_id))

    # ---------------------------------------------------------------- sandbox bank pages

    if bank:
        @app.get("/sandbox/VPG/Purchase")
        def sandbox_purchase():
            token = request.args.get("Token", "")
            tx = bank.get_open(token)
            if not tx:
                return "توکن نامعتبر یا منقضی است.", 400
            return render_template("sandbox_bank.html", token=token, tx=tx, terminal_id=config.sandbox.terminal_id)

        @app.post("/sandbox/VPG/Pay")
        def sandbox_pay():
            back = bank.pay(request.form.get("Token", ""), request.form.get("action", ""))
            if not back:
                return "توکن نامعتبر یا منقضی است.", 400
            return render_template("autopost.html", action=back["return_url"], fields=back["fields"])

    @app.errorhandler(404)
    def not_found(_):
        return page("error.html", "یافت نشد", 404, message="صفحه مورد نظر پیدا نشد.")

    log.info("Sadad payment app: mode=%s terminal=%s key=%s callback=%s/payment/callback admin=%s/admin%s",
             config.mode.upper(), sadad.terminal_id, sadad.key_fingerprint, config.app_url, config.app_url,
             "" if config.admin_password else " (no password set!)")
    return app
