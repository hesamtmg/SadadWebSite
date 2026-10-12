<?php
/**
 * Local Sadad (Bank Melli) sandbox for development.
 *
 * Implements the three endpoints the plugin uses, with the same JSON shapes:
 *   POST /vpg/api/v0/Request/PaymentRequest
 *   GET  /VPG/Purchase?Token=...            (bank payment page shown to the customer)
 *   POST /vpg/api/v0/Advice/Verify
 *
 * SignData is checked with the same TripleDES key the store is configured with,
 * so a wrong key or wrong sign format fails here just like it would at the bank.
 * NEVER use this in production — it accepts any card.
 */

$terminal_id  = getenv('SADAD_TERMINAL_ID') ?: '24000000';
$merchant_id  = getenv('SADAD_MERCHANT_ID') ?: '000000140000000';
$terminal_key = base64_decode((string) getenv('SADAD_TERMINAL_KEY'), true);
$store_file   = sys_get_temp_dir() . '/sadad-mock-tokens.json';

$path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

function load_tokens($file) {
    $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : array();
    return is_array($data) ? $data : array();
}

function save_tokens($file, array $tokens) {
    file_put_contents($file, json_encode($tokens, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}

function json_out(array $body) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

function sign_ok($sign, $plain, $key) {
    if (!is_string($key) || strlen($key) !== 24) return false;
    $expected = base64_encode((string) openssl_encrypt($plain, 'DES-EDE3', $key, OPENSSL_RAW_DATA));
    return hash_equals($expected, (string) $sign);
}

function log_line($msg) {
    file_put_contents('php://stderr', '[sadad-mock] ' . $msg . "\n");
}

function h($v) {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function fa_digits($v) {
    return strtr((string) $v, array('0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹'));
}

// ---------------------------------------------------------------- PaymentRequest
if ($method === 'POST' && strcasecmp($path, '/vpg/api/v0/Request/PaymentRequest') === 0) {
    $req = json_decode((string) file_get_contents('php://input'), true) ?: array();
    log_line('PaymentRequest ' . json_encode(array_diff_key($req, array('SignData' => 1))));

    if ((string) ($req['TerminalId'] ?? '') !== $terminal_id || (string) ($req['MerchantId'] ?? '') !== $merchant_id) {
        json_out(array('ResCode' => '3', 'Token' => null, 'Description' => 'پذیرنده کارت فعال نیست لطفا با بخش امور پذیرندگان، تماس حاصل فرمائید.'));
    }
    $sign_source = $req['TerminalId'] . ';' . ($req['OrderId'] ?? '') . ';' . ($req['Amount'] ?? '');
    if (!sign_ok($req['SignData'] ?? '', $sign_source, $terminal_key)) {
        json_out(array('ResCode' => '1011', 'Token' => null, 'Description' => 'اطلاعات امضا (SignData) نامعتبر است.'));
    }
    if (!is_int($req['Amount'] ?? null) || $req['Amount'] < 1000) {
        json_out(array('ResCode' => '1', 'Token' => null, 'Description' => 'مبلغ تراکنش نامعتبر است.'));
    }
    if (empty($req['ReturnUrl'])) {
        json_out(array('ResCode' => '1', 'Token' => null, 'Description' => 'آدرس بازگشت ارسال نشده است.'));
    }

    $token = strtoupper(bin2hex(random_bytes(12)));
    $tokens = load_tokens($store_file);
    $tokens[$token] = array(
        'order_id'   => (string) $req['OrderId'],
        'amount'     => (int) $req['Amount'],
        'return_url' => (string) $req['ReturnUrl'],
        'status'     => 'created',
        'created'    => time(),
    );
    save_tokens($store_file, $tokens);
    json_out(array('ResCode' => '0', 'Token' => $token, 'Description' => 'عملیات با موفقیت انجام شد'));
}

// ---------------------------------------------------------------- Verify
if ($method === 'POST' && strcasecmp($path, '/vpg/api/v0/Advice/Verify') === 0) {
    $req = json_decode((string) file_get_contents('php://input'), true) ?: array();
    $token = (string) ($req['Token'] ?? '');
    log_line('Verify token=' . $token);
    $tokens = load_tokens($store_file);

    if (!sign_ok($req['SignData'] ?? '', $token, $terminal_key)) {
        json_out(array('ResCode' => '-1', 'Description' => 'اطلاعات امضا (SignData) نامعتبر است.'));
    }
    if (!isset($tokens[$token]) || !in_array($tokens[$token]['status'], array('paid', 'verified'), true)) {
        json_out(array('ResCode' => '-1', 'Description' => 'تراکنش یافت نشد یا پرداخت نشده است.'));
    }
    $tx = $tokens[$token];
    $tokens[$token]['status'] = 'verified';
    save_tokens($store_file, $tokens);
    json_out(array(
        'ResCode'         => '0',
        'Amount'          => $tx['amount'],
        'Description'     => 'عملیات با موفقیت انجام شد',
        'RetrivalRefNo'   => $tx['rrn'],
        'SystemTraceNo'   => $tx['trace'],
        'OrderId'         => $tx['order_id'],
    ));
}

// ---------------------------------------------------------------- Customer pays / cancels
if ($method === 'POST' && strcasecmp($path, '/VPG/Pay') === 0) {
    $token = (string) ($_POST['Token'] ?? '');
    $tokens = load_tokens($store_file);
    if (!isset($tokens[$token])) {
        http_response_code(404);
        exit('Token not found');
    }
    $paid = ($_POST['action'] ?? '') === 'pay';
    if ($paid) {
        $tokens[$token]['status'] = 'paid';
        $tokens[$token]['trace']  = (string) random_int(100000, 999999);
        $tokens[$token]['rrn']    = (string) random_int(100000000000, 999999999999);
    } else {
        $tokens[$token]['status'] = 'canceled';
    }
    save_tokens($store_file, $tokens);
    $tx = $tokens[$token];
    $fields = array('OrderId' => $tx['order_id'], 'token' => $token, 'ResCode' => $paid ? '0' : '-1');
    ?><!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><title>بازگشت به فروشگاه</title>
    <body onload="document.forms[0].submit()" style="font-family:Tahoma,sans-serif;text-align:center;padding:4rem">
    <p>در حال بازگشت به فروشگاه…</p>
    <form method="post" action="<?php echo h($tx['return_url']); ?>">
    <?php foreach ($fields as $k => $v) : ?><input type="hidden" name="<?php echo h($k); ?>" value="<?php echo h($v); ?>"><?php endforeach; ?>
    <noscript><button>بازگشت به فروشگاه</button></noscript>
    </form></body></html><?php
    exit;
}

// ---------------------------------------------------------------- Bank page
if (strcasecmp($path, '/VPG/Purchase') === 0) {
    $token = (string) ($_GET['Token'] ?? '');
    $tokens = load_tokens($store_file);
    if (!isset($tokens[$token]) || $tokens[$token]['status'] !== 'created') {
        http_response_code(400);
        exit('توکن نامعتبر یا منقضی است.');
    }
    $tx = $tokens[$token];
    ?><!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>درگاه پرداخت سداد — محیط آزمایشی</title>
<style>
*{box-sizing:border-box}body{margin:0;font-family:Tahoma,Vazirmatn,sans-serif;background:#eef2f7;color:#1d2939}
.sandbox{background:#f79009;color:#fff;text-align:center;padding:.5rem;font-size:.85rem}
.wrap{max-width:860px;margin:2rem auto;padding:0 1rem;display:grid;grid-template-columns:1fr 300px;gap:1rem}
.card{background:#fff;border-radius:14px;box-shadow:0 4px 18px rgba(16,24,40,.06);padding:1.4rem}
h1{font-size:1.1rem;margin:0 0 1.2rem;color:#0b3b8c}
label{display:block;font-size:.85rem;margin:.9rem 0 .35rem;color:#475467}
input{width:100%;padding:.7rem .8rem;border:1px solid #d0d5dd;border-radius:9px;font-size:1rem;direction:ltr;text-align:center;font-family:monospace}
.row{display:grid;grid-template-columns:1fr 1fr 1fr;gap:.6rem}
.btns{display:flex;gap:.6rem;margin-top:1.4rem}
button{flex:1;padding:.8rem;border:0;border-radius:9px;font-size:1rem;font-family:inherit;cursor:pointer}
.pay{background:#155eef;color:#fff}.cancel{background:#f2f4f7;color:#b42318}
dl{margin:0}dt{font-size:.8rem;color:#667085;margin-top:.9rem}dd{margin:.2rem 0 0;font-weight:700}
.amount{font-size:1.4rem;color:#0b3b8c}.brand{display:flex;align-items:center;gap:.6rem;margin-bottom:.6rem;font-weight:700;color:#0b3b8c}
.hint{font-size:.78rem;color:#667085;margin-top:1rem;line-height:1.8}
@media(max-width:720px){.wrap{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="sandbox">محیط آزمایشی سداد — هیچ پولی جابه‌جا نمی‌شود</div>
<div class="wrap">
  <form class="card" method="post" action="/VPG/Pay">
    <h1>اطلاعات کارت</h1>
    <input type="hidden" name="Token" value="<?php echo h($token); ?>">
    <label>شماره کارت</label><input name="pan" value="6037 9911 1111 1111" inputmode="numeric">
    <div class="row">
      <div><label>CVV2</label><input name="cvv2" value="123"></div>
      <div><label>ماه انقضا</label><input name="mm" value="12"></div>
      <div><label>سال انقضا</label><input name="yy" value="08"></div>
    </div>
    <label>رمز دوم (پویا)</label><input name="pin2" value="123456" type="password">
    <div class="btns">
      <button class="pay" name="action" value="pay">پرداخت</button>
      <button class="cancel" name="action" value="cancel">انصراف</button>
    </div>
    <p class="hint">این صفحه شبیه‌ساز درگاه است. «پرداخت» تراکنش موفق و «انصراف» تراکنش لغوشده را به فروشگاه برمی‌گرداند.</p>
  </form>
  <div class="card">
    <div class="brand">سداد — بانک ملی ایران</div>
    <dl>
      <dt>مبلغ قابل پرداخت</dt><dd class="amount"><?php echo fa_digits(number_format($tx['amount'])); ?> ریال</dd>
      <dt>شماره سفارش</dt><dd><?php echo fa_digits(h($tx['order_id'])); ?></dd>
      <dt>کد ترمینال</dt><dd><?php echo fa_digits(h($terminal_id)); ?></dd>
      <dt>توکن</dt><dd style="font-family:monospace;font-size:.75rem;direction:ltr;text-align:right"><?php echo h($token); ?></dd>
    </dl>
  </div>
</div>
</body>
</html><?php
    exit;
}

if ($path === '/' || $path === '/health') {
    header('Content-Type: text/plain; charset=utf-8');
    exit("Sadad sandbox is running.\n");
}

http_response_code(404);
echo 'Not found';
