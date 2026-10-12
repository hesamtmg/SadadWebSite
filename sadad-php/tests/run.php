<?php
// Dependency-free tests: `php tests/run.php`. Unit tests in-process, flow tests over HTTP against `php -S`.
declare(strict_types=1);

require __DIR__ . '/../src/SadadClient.php';

use Sadad\{SadadException, SadadSign};

$failures = 0;
$passed = 0;
function check(string $name, callable $fn): void
{
    global $failures, $passed;
    try {
        $fn();
        $passed++;
        echo "ok   {$name}\n";
    } catch (Throwable $e) {
        $failures++;
        echo "FAIL {$name}: {$e->getMessage()}\n";
    }
}
function same(mixed $expected, mixed $actual, string $what = ''): void
{
    if ($expected !== $actual) throw new RuntimeException("{$what} expected " . var_export($expected, true) . ', got ' . var_export($actual, true));
}
function contains(string $needle, string $haystack, string $what = ''): void
{
    if (!str_contains($haystack, $needle)) throw new RuntimeException("{$what} missing: {$needle}");
}

// ---------------------------------------------------------------- unit

// Vectors produced by Sadad's C# MakeSign sample (TripleDES, ECB, PKCS7, UTF8).
const KEY = 'prK/jNrK/zPp/lASBVyCRFMcx8y5dFUi';
check('SignData matches C# MakeSign', function () {
    foreach ([
        ['24095674;1001;1250000', '6/5zC6mPoyX0bTyaKyBo28AbIERIj9oP'],
        ['24095674;987654321;124950000', '6/5zC6mPoyU9i1EwGfOWDTPWTvjpo53NWyj345RzM1E='],
        ['8D2F1C77A4E09B3C5D6E7F80', '2/S4taCdoFSFV9wGlJn0MHPcZ7xCMJ5L78k++F3Hk7E='],
        ['3F9A2B7C1D0E4F5A6B7C8D9E0F1A2B3C', 'B1IHgP/LaMyGI+aSY1U/mxNiV9DV732E4odbh0Z4ccfvyT74XceTsQ=='],
    ] as [$in, $out]) same($out, SadadSign::signData($in, KEY), $in);
});
check('rejects bad keys', function () {
    foreach (['not base64!', base64_encode(str_repeat("\0", 16))] as $bad) {
        try { SadadSign::decodeKey($bad); throw new RuntimeException("accepted {$bad}"); } catch (SadadException) {}
    }
    same(24, strlen(SadadSign::decodeKey(' ' . KEY . "\n")));
});
check('LocalDateTime is Tehran MM/dd/yyyy hh:mm:ss AM', function () {
    same('03/21/2026 12:00:00 AM', SadadSign::localDateTime(new DateTimeImmutable('2026-03-20T20:30:00Z')));
    same('07/01/2026 01:45:05 PM', SadadSign::localDateTime(new DateTimeImmutable('2026-07-01T10:15:05Z')));
});

// ---------------------------------------------------------------- flow over HTTP

$port = 8999;
$base = "http://127.0.0.1:{$port}";
$dataDir = sys_get_temp_dir() . '/sadad-php-test-' . bin2hex(random_bytes(4));
$env = ['SADAD_MODE' => 'sandbox', 'DATA_DIR' => $dataDir, 'ADMIN_PASSWORD' => 'pw', 'CURRENCY' => 'IRT', 'APP_URL' => $base, 'PHP_CLI_SERVER_WORKERS' => '4'] + getenv();
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', __DIR__ . '/../public', __DIR__ . '/../public/index.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $env);
for ($i = 0; $i < 50 && @file_get_contents("{$base}/health") === false; $i++) usleep(100_000);

/** @return array{status: int, location: string, body: string} */
function http(string $method, string $url, array $form = [], array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_HTTPHEADER => $headers]);
    if ($method === 'POST') curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
    $raw = (string) curl_exec($ch);
    $size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    preg_match('/^Location:\s*(.+)$/mi', substr($raw, 0, $size), $m);
    return ['status' => $status, 'location' => trim($m[1] ?? ''), 'body' => substr($raw, $size)];
}

function startOrder(string $base, string $amount): array
{
    $created = http('POST', "{$base}/orders", ['amount' => $amount, 'name' => 'علی رضایی', 'mobile' => '09121234567']);
    same(303, $created['status'], 'create');
    $orderPath = $created['location'];
    $pay = http('POST', "{$base}{$orderPath}/pay");
    same(303, $pay['status'], 'pay');
    same('/sandbox/VPG/Purchase', parse_url($pay['location'], PHP_URL_PATH));
    parse_str((string) parse_url($pay['location'], PHP_URL_QUERY), $q);
    return [$orderPath, $q['Token']];
}

function bankAction(string $base, string $token, string $action): array
{
    preg_match_all('/name="([^"]+)" value="([^"]*)"/', http('POST', "{$base}/sandbox/VPG/Pay", ['Token' => $token, 'action' => $action])['body'], $m, PREG_SET_ORDER);
    return array_column($m, 2, 1);
}

check('successful payment is verified once', function () use ($base) {
    [$orderPath, $token] = startOrder($base, '250000');
    contains('۲٬۵۰۰٬۰۰۰ ریال', http('GET', "{$base}/sandbox/VPG/Purchase?Token={$token}")['body'], 'Toman ×10');
    $fields = bankAction($base, $token, 'pay');
    same('0', $fields['ResCode']);
    same("{$orderPath}/result", http('POST', "{$base}/payment/callback", $fields)['location']);
    contains('پرداخت با موفقیت انجام شد', http('GET', "{$base}{$orderPath}/result")['body']);
    http('POST', "{$base}/payment/callback", $fields); // replay
    contains('پرداخت با موفقیت انجام شد', http('GET', "{$base}{$orderPath}/result")['body']);
});

check('cancel then retry', function () use ($base) {
    [$orderPath, $token] = startOrder($base, '100000');
    http('POST', "{$base}/payment/callback", bankAction($base, $token, 'cancel'));
    $result = http('GET', "{$base}{$orderPath}/result")['body'];
    contains('پرداخت لغو شد', $result);
    contains('تلاش دوباره', $result);
    contains('/sandbox/VPG/Purchase?Token=', http('POST', "{$base}{$orderPath}/pay")['location']);
});

check('forged success callback fails Verify', function () use ($base) {
    [$orderPath, $token] = startOrder($base, '100000');
    preg_match('/شماره سفارش<\/dt><dd>(\d+)/', http('GET', "{$base}/sandbox/VPG/Purchase?Token={$token}")['body'], $m);
    http('POST', "{$base}/payment/callback", ['OrderId' => $m[1], 'token' => $token, 'ResCode' => '0']);
    contains('پرداخت ناموفق بود', http('GET', "{$base}{$orderPath}/result")['body']);
});

check('validation and admin auth', function () use ($base) {
    same(422, http('POST', "{$base}/orders", ['amount' => '50', 'name' => 'x'])['status']);
    same(401, http('GET', "{$base}/admin")['status']);
    $ok = http('GET', "{$base}/admin", [], ['Authorization: Basic ' . base64_encode('admin:pw')]);
    same(200, $ok['status']);
    contains('پرداخت‌شده', $ok['body']);
});

proc_terminate($server);
array_map('unlink', glob("{$dataDir}/*") ?: []);
@rmdir($dataDir);

echo "\n{$passed} passed, {$failures} failed\n";
exit($failures ? 1 : 0);
