<?php
// Sadad payment app: form → confirm → Sadad PaymentRequest → bank → callback → Verify → receipt.
// Front controller for Apache (see .htaccess) and `php -S` (router script).
declare(strict_types=1);

if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
    return false; // let the built-in server serve static files
}

require __DIR__ . '/../src/SadadClient.php';
require __DIR__ . '/../src/Storage.php';
require __DIR__ . '/../src/Config.php';
require __DIR__ . '/../src/SandboxBank.php';
require __DIR__ . '/../src/Views.php';

use Sadad\{Config, OrderStore, SadadClient, SadadException, SandboxBank, Views};

const MIN_RIAL = 1000;
const MAX_RIAL = 5_000_000_000; // 500 million Toman; adjust to your terminal's limit

$config = new Config();
$bank = $config->isSandbox() ? new SandboxBank($config->sandbox, $config->dataDir) : null;
try {
    $s = $config->sadad;
    $sadad = new SadadClient($s['merchantId'], $s['terminalId'], $s['terminalKey'], $s['apiBaseUrl'], $s['purchaseBaseUrl'], $s['timeout'], $bank?->transport());
} catch (SadadException $e) {
    http_response_code(500);
    error_log("Sadad configuration error ({$config->mode} mode): {$e->getMessage()}");
    exit("Sadad configuration error ({$config->mode} mode): " . htmlspecialchars($e->getMessage()) . ($config->isSandbox() ? '' : ' — set SADAD_MERCHANT_ID, SADAD_TERMINAL_ID and SADAD_TERMINAL_KEY in .env.'));
}
$store = new OrderStore($config->dataDir);

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: DENY');

// ---------------------------------------------------------------- helpers

function logLine(string $msg, array $data = []): void
{
    error_log('[' . gmdate('c') . "] {$msg} " . ($data ? json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''));
}

function page(Config $config, string $title, string $body, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo Views::layout($title, $body, $config->isSandbox());
    exit;
}

function redirect(string $location): never
{
    header('Location: ' . $location, true, 303);
    exit;
}

function asciiDigits(string $s): string
{
    return strtr($s, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
}

function checkAdmin(Config $config): void
{
    if ($config->adminPassword === '') return;
    $user = $_SERVER['PHP_AUTH_USER'] ?? null;
    $pass = $_SERVER['PHP_AUTH_PW'] ?? null;
    // Some servers (CGI/FPM) only pass the raw header.
    if ($user === null && preg_match('/^Basic\s+(.+)$/i', $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '', $m)) {
        [$user, $pass] = array_pad(explode(':', (string) base64_decode($m[1]), 2), 2, null);
    }
    if (is_string($user) && is_string($pass) && hash_equals($config->adminUser, $user) && hash_equals($config->adminPassword, $pass)) return;
    header('WWW-Authenticate: Basic realm="Sadad admin", charset="UTF-8"');
    http_response_code(401);
    exit('Authentication required');
}

// ---------------------------------------------------------------- routes

$method = $_SERVER['REQUEST_METHOD'];
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

if ($method === 'GET' && $path === '/') {
    page($config, 'پرداخت آنلاین', Views::home($config->currency));
}

if ($method === 'GET' && $path === '/health') {
    header('Content-Type: application/json');
    exit(json_encode(['ok' => true, 'mode' => $config->mode]));
}

if ($method === 'POST' && $path === '/orders') {
    $values = array_map(fn ($k) => (string) ($_POST[$k] ?? ''), ['amount' => 'amount', 'name' => 'name', 'mobile' => 'mobile', 'description' => 'description']);
    $amountText = str_replace([',', ' ', '٬', '،'], '', asciiDigits($values['amount']));
    $name = mb_substr(trim($values['name']), 0, 80);
    $mobile = str_replace(' ', '', asciiDigits($values['mobile']));
    $description = mb_substr(trim($values['description']), 0, 120);

    $error = null;
    $amount = ctype_digit($amountText) && strlen($amountText) < 16 ? (int) $amountText : 0;
    $amountRial = $config->currency === 'IRT' ? $amount * 10 : $amount;
    if ($amount <= 0) $error = 'مبلغ را به صورت عدد صحیح وارد کنید.';
    elseif ($amountRial < MIN_RIAL) $error = 'حداقل مبلغ ' . Views::faNum($config->currency === 'IRT' ? intdiv(MIN_RIAL, 10) : MIN_RIAL) . ($config->currency === 'IRT' ? ' تومان' : ' ریال') . ' است.';
    elseif ($amountRial > MAX_RIAL) $error = 'مبلغ بیش از سقف مجاز است.';
    elseif ($name === '') $error = 'نام پرداخت‌کننده را وارد کنید.';
    elseif ($mobile !== '' && !preg_match('/^09\d{9}$/', $mobile)) $error = 'شماره موبایل باید ۱۱ رقم و با ۰۹ شروع شود.';
    if ($error !== null) page($config, 'پرداخت آنلاین', Views::home($config->currency, $error, $values), 422);

    $order = $store->create(['amount' => $amount, 'amountRial' => $amountRial, 'currency' => $config->currency, 'name' => $name, 'mobile' => $mobile, 'description' => $description]);
    redirect("/orders/{$order['id']}");
}

if (preg_match('#^/orders/([a-f0-9]{16})(/pay|/result)?$#', $path, $m)) {
    $id = $m[1];
    $action = $m[2] ?? '';
    $order = $store->get($id) ?? page($config, 'یافت نشد', Views::error('سفارش پیدا نشد.'), 404);

    if ($method === 'GET' && $action === '') {
        $order['status'] === 'created' ? page($config, 'تأیید اطلاعات پرداخت', Views::confirm($order)) : redirect("/orders/{$id}/result");
    }
    if ($method === 'GET' && $action === '/result') {
        page($config, 'نتیجه پرداخت', Views::result($order));
    }

    // Step 1–2: PaymentRequest, then send the customer to the bank.
    if ($method === 'POST' && $action === '/pay') {
        if (!in_array($order['status'], ['created', 'failed', 'canceled'], true)) redirect("/orders/{$id}/result");
        $bankOrderId = $store->nextBankOrderId(); // fresh OrderId per attempt: Sadad rejects reused ones
        $returnUrl = "{$config->appUrl}/payment/callback";
        try {
            $r = $sadad->paymentRequest($bankOrderId, (int) $order['amountRial'], $returnUrl);
            $store->update($id, function (array &$o) use ($bankOrderId, $r) {
                $o['status'] = 'pending'; $o['bankOrderId'] = $bankOrderId; $o['token'] = $r['token']; $o['message'] = '';
            }, 'payment_request.ok', $r['debug'] + ['bankOrderId' => $bankOrderId, 'token' => $r['token'], 'returnUrl' => $returnUrl]);
            logLine('PaymentRequest ok', ['order' => $id, 'bankOrderId' => $bankOrderId, 'amountRial' => $order['amountRial']]);
            redirect($r['paymentUrl']);
        } catch (SadadException $e) {
            $o = $store->update($id, function (array &$o) use ($bankOrderId, $e) {
                $o['status'] = 'failed'; $o['bankOrderId'] = $bankOrderId; $o['message'] = $e->getMessage();
            }, 'payment_request.error', ['bankOrderId' => $bankOrderId, 'resCode' => $e->resCode, 'response' => $e->response, 'message' => $e->getMessage()]);
            logLine('PaymentRequest failed', ['order' => $id, 'bankOrderId' => $bankOrderId, 'resCode' => $e->resCode, 'message' => $e->getMessage()]);
            page($config, 'تأیید اطلاعات پرداخت', Views::confirm($o, 'درخواست پرداخت توسط سداد پذیرفته نشد: ' . $e->getMessage()), 502);
        }
    }
}

// Step 3–4: Sadad posts OrderId, token and ResCode here (some setups use GET; accept both), then we Verify.
if ($path === '/payment/callback' && in_array($method, ['POST', 'GET'], true)) {
    $in = $method === 'POST' ? $_POST : $_GET;
    $token = trim((string) ($in['token'] ?? $in['Token'] ?? ''));
    $resCode = trim((string) ($in['ResCode'] ?? $in['rescode'] ?? ''));
    $postedOrderId = trim(asciiDigits((string) ($in['OrderId'] ?? $in['orderId'] ?? '')));
    $order = $store->findBy('token', $token) ?? $store->findBy('bankOrderId', $postedOrderId);
    logLine('Callback', ['token' => $token, 'resCode' => $resCode, 'postedOrderId' => $postedOrderId, 'found' => $order !== null]);

    if ($order === null) page($config, 'خطا', Views::error('سفارش مربوط به این پرداخت پیدا نشد.'), 400);
    $id = $order['id'];
    $result = "/orders/{$id}/result";
    $fail = function (string $status, string $message, array $data) use ($store, $id, $result): never {
        $store->update($id, function (array &$o) use ($status, $message) {
            if ($o['status'] === 'paid') return false;
            $o['status'] = $status; $o['message'] = $message;
        }, "callback.{$status}", $data + ['message' => $message]);
        redirect($result);
    };

    if (in_array($order['status'], ['paid', 'verifying'], true)) redirect($result); // refresh / double POST
    if ($token !== '' && $order['token'] !== null && $token !== $order['token']) $fail('failed', 'توکن بازگشتی با درخواست اولیه مطابقت ندارد.', ['token' => $token]);
    if ($postedOrderId !== '' && $postedOrderId !== $order['bankOrderId']) $fail('failed', 'شماره سفارش بازگشتی با درخواست اولیه مطابقت ندارد.', ['postedOrderId' => $postedOrderId]);
    if ($resCode !== '0' || $token === '') $fail('canceled', 'پرداخت در بانک انجام نشد یا توسط شما لغو شد. [ResCode: ' . ($resCode !== '' ? $resCode : 'نامشخص') . ']', ['resCode' => $resCode]);

    // Claim the order atomically (file lock) so two concurrent callbacks never verify twice.
    $claimed = $store->update($id, function (array &$o) {
        if (!in_array($o['status'], ['pending', 'failed', 'canceled'], true)) return false;
        $o['status'] = 'verifying';
    }, 'callback.ok', ['resCode' => $resCode, 'token' => $token]);
    if ($claimed === null) redirect($result);

    try {
        $v = $sadad->verify($token);
    } catch (SadadException $e) {
        logLine('Verify failed', ['order' => $id, 'resCode' => $e->resCode, 'message' => $e->getMessage()]);
        $fail('failed', 'تأیید تراکنش در سداد ناموفق بود: ' . $e->getMessage(), ['resCode' => $e->resCode, 'response' => $e->response]);
    }
    if ($v['amount'] !== null && $v['amount'] !== (int) $order['amountRial']) {
        $fail('failed', 'مبلغ تأییدشده با مبلغ سفارش مطابقت ندارد. با پشتیبانی تماس بگیرید.', ['expected' => $order['amountRial'], 'verified' => $v['amount'], 'response' => $v['response']]);
    }
    $store->update($id, function (array &$o) use ($v) {
        $o['status'] = 'paid'; $o['message'] = ''; $o['paidAt'] = gmdate('c');
        $o['systemTraceNo'] = $v['systemTraceNo']; $o['retrievalRefNo'] = $v['retrievalRefNo'];
    }, 'verify.ok', $v['response']);
    logLine('Verify ok', ['order' => $id, 'trace' => $v['systemTraceNo'], 'rrn' => $v['retrievalRefNo']]);
    redirect($result);
}

if ($method === 'GET' && $path === '/admin') {
    checkAdmin($config);
    page($config, 'تراکنش‌ها', Views::admin($store->all(), $config->isSandbox(), $sadad->terminalId, $sadad->keyFingerprint()));
}

if ($method === 'GET' && preg_match('#^/admin/orders/([a-f0-9]{16})$#', $path, $m)) {
    checkAdmin($config);
    $order = $store->get($m[1]) ?? page($config, 'یافت نشد', Views::error('سفارش پیدا نشد.'), 404);
    page($config, 'جزئیات تراکنش', Views::adminOrder($order));
}

// Sandbox bank pages (SADAD_MODE=sandbox only).
if ($bank !== null && $method === 'GET' && $path === '/sandbox/VPG/Purchase') {
    [$status, $html] = $bank->purchasePage((string) ($_GET['Token'] ?? ''));
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    exit($html);
}
if ($bank !== null && $method === 'POST' && $path === '/sandbox/VPG/Pay') {
    [$status, $html] = $bank->pay((string) ($_POST['Token'] ?? ''), (string) ($_POST['action'] ?? ''));
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    exit($html);
}

page($config, 'یافت نشد', Views::error('صفحه مورد نظر پیدا نشد.'), 404);
