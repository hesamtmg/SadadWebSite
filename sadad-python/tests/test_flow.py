"""End-to-end through the Flask app against the built-in sandbox bank."""
import base64
import re
from urllib.parse import parse_qs, urlparse


def start_order(client, amount):
    created = client.post("/orders", data={"amount": amount, "name": "علی رضایی", "mobile": "09121234567"})
    assert created.status_code == 303
    order_path = created.headers["Location"]
    pay = client.post(f"{order_path}/pay")
    assert pay.status_code == 303
    bank_url = urlparse(pay.headers["Location"])
    assert bank_url.path == "/sandbox/VPG/Purchase"
    return order_path, parse_qs(bank_url.query)["Token"][0]


def bank_action(client, token, action):
    html = client.post("/sandbox/VPG/Pay", data={"Token": token, "action": action}).get_data(as_text=True)
    return dict(re.findall(r'name="([^"]+)" value="([^"]*)"', html))


def text(client, path):
    return client.get(path).get_data(as_text=True)


def test_successful_payment_is_verified_once(client):
    order_path, token = start_order(client, "250000")
    assert "۲٬۵۰۰٬۰۰۰ ریال" in text(client, f"/sandbox/VPG/Purchase?Token={token}")  # Toman ×10
    fields = bank_action(client, token, "pay")
    assert fields["ResCode"] == "0"
    assert client.post("/payment/callback", data=fields).headers["Location"] == f"{order_path}/result"
    assert "پرداخت با موفقیت انجام شد" in text(client, f"{order_path}/result")
    client.post("/payment/callback", data=fields)  # replay
    assert "پرداخت با موفقیت انجام شد" in text(client, f"{order_path}/result")


def test_cancel_then_retry(client):
    order_path, token = start_order(client, "100000")
    client.post("/payment/callback", data=bank_action(client, token, "cancel"))
    result = text(client, f"{order_path}/result")
    assert "پرداخت لغو شد" in result and "تلاش دوباره" in result
    assert "/sandbox/VPG/Purchase?Token=" in client.post(f"{order_path}/pay").headers["Location"]


def test_forged_success_callback_fails_verify(client):
    order_path, token = start_order(client, "100000")
    order_id = re.search(r"شماره سفارش</dt><dd>(\d+)", text(client, f"/sandbox/VPG/Purchase?Token={token}")).group(1)
    client.post("/payment/callback", data={"OrderId": order_id, "token": token, "ResCode": "0"})
    assert "پرداخت ناموفق بود" in text(client, f"{order_path}/result")


def test_validation_and_admin_auth(client):
    assert client.post("/orders", data={"amount": "50", "name": "x"}).status_code == 422
    assert client.get("/admin").status_code == 401
    auth = {"Authorization": "Basic " + base64.b64encode(b"admin:pw").decode()}
    assert client.get("/admin", headers=auth).status_code == 200
