<?php
// Built-in Sadad sandbox (SADAD_MODE=sandbox): same endpoints and JSON as sadad.shaparak.ir.
// SignData is really checked with the sandbox key, so signing bugs fail here too. Accepts any card.
declare(strict_types=1);

namespace Sadad;

final class SandboxBank
{
    private JsonFile $tokens;

    public function __construct(private readonly array $creds, string $dataDir)
    {
        $this->tokens = new JsonFile($dataDir . '/sandbox-tokens.json');
    }

    /** In-process transport for SadadClient: no network, no request to ourselves. */
    public function transport(): callable
    {
        return fn (string $url, array $payload): array => $this->handleApi((string) parse_url($url, PHP_URL_PATH), $payload);
    }

    private function signOk(mixed $sign, string $text): bool
    {
        return hash_equals(SadadSign::signData($text, $this->creds['terminalKey']), (string) $sign);
    }

    public function handleApi(string $path, array $r): array
    {
        if (str_ends_with($path, '/vpg/api/v0/Request/PaymentRequest')) {
            error_log("[sandbox] PaymentRequest OrderId={$r['OrderId']} Amount={$r['Amount']}");
            if ((string) ($r['TerminalId'] ?? '') !== $this->creds['terminalId'] || (string) ($r['MerchantId'] ?? '') !== $this->creds['merchantId']) {
                return ['ResCode' => '3', 'Token' => null, 'Description' => 'پذیرنده کارت فعال نیست.'];
            }
            if (!$this->signOk($r['SignData'] ?? '', "{$r['TerminalId']};{$r['OrderId']};{$r['Amount']}")) {
                return ['ResCode' => '1025', 'Token' => null, 'Description' => 'اطلاعات SignData ارسالی اشتباه است'];
            }
            if (!is_int($r['Amount'] ?? null) || $r['Amount'] < 1000) {
                return ['ResCode' => '-1', 'Token' => null, 'Description' => 'مبلغ تراکنش نامعتبر است.'];
            }
            $token = strtoupper(bin2hex(random_bytes(12)));
            $this->tokens->transaction(function (array &$t) use ($token, $r) {
                $t[$token] = ['orderId' => (string) $r['OrderId'], 'amount' => $r['Amount'], 'returnUrl' => (string) $r['ReturnUrl'], 'status' => 'created'];
            });
            return ['ResCode' => '0', 'Token' => $token, 'Description' => 'عملیات با موفقیت انجام شد'];
        }

        if (str_ends_with($path, '/vpg/api/v0/Advice/Verify')) {
            $token = (string) ($r['Token'] ?? '');
            error_log("[sandbox] Verify Token={$token}");
            if (!$this->signOk($r['SignData'] ?? '', $token)) {
                return ['ResCode' => '-1', 'Description' => 'اطلاعات SignData ارسالی اشتباه است'];
            }
            return $this->tokens->transaction(function (array &$t) use ($token) {
                if (!isset($t[$token]) || !in_array($t[$token]['status'], ['paid', 'verified'], true)) {
                    return ['ResCode' => '-1', 'Description' => 'تراکنش یافت نشد یا پرداخت نشده است.'];
                }
                $t[$token]['status'] = 'verified';
                $tx = $t[$token];
                return ['ResCode' => '0', 'Amount' => $tx['amount'], 'Description' => 'عملیات با موفقیت انجام شد',
                    'RetrivalRefNo' => $tx['rrn'], 'SystemTraceNo' => $tx['trace'], 'OrderId' => $tx['orderId']];
            });
        }

        return ['ResCode' => '-1', 'Description' => 'Unknown sandbox endpoint'];
    }

    /** GET /sandbox/VPG/Purchase?Token=… — the card page. Returns [status, html]. */
    public function purchasePage(string $token): array
    {
        $tx = $this->tokens->read()[$token] ?? null;
        if (!$tx || $tx['status'] !== 'created') return [400, 'توکن نامعتبر یا منقضی است.'];
        return [200, Views::sandboxBankPage($token, $tx['orderId'], (int) $tx['amount'], $this->creds['terminalId'])];
    }

    /** POST /sandbox/VPG/Pay — like the real bank, auto-POSTs OrderId, token and ResCode back to ReturnUrl. */
    public function pay(string $token, string $action): array
    {
        $tx = $this->tokens->transaction(function (array &$t) use ($token, $action) {
            if (!isset($t[$token]) || $t[$token]['status'] !== 'created') return null;
            $paid = $action === 'pay';
            $t[$token]['status'] = $paid ? 'paid' : 'canceled';
            if ($paid) {
                $t[$token]['trace'] = (string) random_int(100000, 999999);
                $t[$token]['rrn'] = (string) random_int(100000000000, 999999999999);
            }
            return $t[$token];
        });
        if (!$tx) return [400, 'توکن نامعتبر یا منقضی است.'];
        return [200, Views::autoPostPage($tx['returnUrl'], ['OrderId' => $tx['orderId'], 'token' => $token, 'ResCode' => $tx['status'] === 'paid' ? '0' : '-1'])];
    }
}
