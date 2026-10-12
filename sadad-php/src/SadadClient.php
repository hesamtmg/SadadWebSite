<?php
// Sadad (Bank Melli) VPG client: PaymentRequest, purchase redirect and Verify. No dependencies (ext-openssl, ext-curl).
declare(strict_types=1);

namespace Sadad;

final class SadadException extends \RuntimeException
{
    public function __construct(string $message, public readonly ?string $resCode = null, public readonly ?array $response = null)
    {
        parent::__construct($message);
    }
}

final class SadadSign
{
    /** Decode the Base64 TerminalKey; Sadad keys are exactly 24 bytes (TripleDES). */
    public static function decodeKey(string $terminalKeyBase64): string
    {
        $clean = preg_replace('/\s+/', '', $terminalKeyBase64);
        $key = $clean === '' ? false : base64_decode($clean, true);
        if ($key === false) {
            throw new SadadException('TerminalKey is not valid Base64.');
        }
        if (strlen($key) !== 24) {
            throw new SadadException('TerminalKey must decode to 24 bytes, got ' . strlen($key) . '.');
        }
        return $key;
    }

    /** SignData = Base64(TripleDES-ECB-PKCS7(text, key)) — same as Sadad's C# MakeSign. */
    public static function signData(string $text, string $terminalKeyBase64): string
    {
        $cipher = openssl_encrypt($text, 'DES-EDE3', self::decodeKey($terminalKeyBase64), OPENSSL_RAW_DATA);
        if ($cipher === false) {
            throw new SadadException('TripleDES (DES-EDE3) encryption failed on this server.');
        }
        return base64_encode($cipher);
    }

    /** "MM/dd/yyyy hh:mm:ss AM" in Tehran time, as Sadad expects for LocalDateTime. */
    public static function localDateTime(?\DateTimeInterface $at = null): string
    {
        $at = \DateTimeImmutable::createFromInterface($at ?? new \DateTimeImmutable('now'));
        return $at->setTimezone(new \DateTimeZone('Asia/Tehran'))->format('m/d/Y h:i:s A');
    }

    /** Short, non-reversible fingerprint so logs can show which key is used without revealing it. */
    public static function keyFingerprint(string $terminalKeyBase64): string
    {
        return substr(hash('sha256', self::decodeKey($terminalKeyBase64)), 0, 12);
    }
}

final class SadadClient
{
    public const LIVE_URL = 'https://sadad.shaparak.ir';

    /** @var callable(string, array): array */
    private $transport;

    /**
     * @param callable|null $transport fn(string $url, array $payload): array — the sandbox passes an in-process one
     */
    public function __construct(
        public readonly string $merchantId,
        public readonly string $terminalId,
        private readonly string $terminalKey,
        private readonly string $apiBaseUrl = self::LIVE_URL,
        private readonly string $purchaseBaseUrl = self::LIVE_URL,
        private readonly int $timeoutSeconds = 30,
        ?callable $transport = null,
    ) {
        if (!preg_match('/^\d+$/', $merchantId)) throw new SadadException('MerchantId must contain digits only.');
        if (!preg_match('/^\d+$/', $terminalId)) throw new SadadException('TerminalId must contain digits only.');
        SadadSign::decodeKey($terminalKey); // fail fast on a malformed key
        $this->transport = $transport ?? $this->curlPost(...);
    }

    public function keyFingerprint(): string
    {
        return SadadSign::keyFingerprint($this->terminalKey);
    }

    /**
     * Step 1: ask Sadad for a payment token. $amount is in Rial.
     * @return array{token: string, paymentUrl: string, debug: array<string, string>}
     */
    public function paymentRequest(string $orderId, int $amount, string $returnUrl): array
    {
        if ($amount <= 0) throw new SadadException('Amount must be a positive integer (Rial).');
        $signSource = "{$this->terminalId};{$orderId};{$amount}";
        $payload = [
            'TerminalId' => $this->terminalId,
            'MerchantId' => $this->merchantId,
            'Amount' => $amount,
            'SignData' => SadadSign::signData($signSource, $this->terminalKey),
            'ReturnUrl' => $returnUrl,
            'LocalDateTime' => SadadSign::localDateTime(),
            'OrderId' => $orderId,
        ];
        $debug = [
            'signSource' => $signSource,
            'signSha256' => hash('sha256', $payload['SignData']),
            'keyFingerprint' => $this->keyFingerprint(),
            'localDateTime' => $payload['LocalDateTime'],
        ];
        $response = $this->post('/vpg/api/v0/Request/PaymentRequest', $payload);
        $resCode = (string) ($response['ResCode'] ?? '');
        $token = (string) ($response['Token'] ?? '');
        if ($resCode !== '0' || $token === '') {
            throw new SadadException($this->describe($resCode, (string) ($response['Description'] ?? '')), $resCode, $response + ['debug' => $debug]);
        }
        return ['token' => $token, 'paymentUrl' => $this->paymentUrl($token), 'debug' => $debug];
    }

    /** Step 2: the URL the customer's browser must open. */
    public function paymentUrl(string $token): string
    {
        return rtrim($this->purchaseBaseUrl, '/') . '/VPG/Purchase?Token=' . rawurlencode($token);
    }

    /**
     * Step 3: confirm the payment after the customer returns. Throws unless ResCode is "0".
     * @return array{amount: ?int, retrievalRefNo: string, systemTraceNo: string, orderId: string, description: string, response: array}
     */
    public function verify(string $token): array
    {
        $response = $this->post('/vpg/api/v0/Advice/Verify', ['Token' => $token, 'SignData' => SadadSign::signData($token, $this->terminalKey)]);
        $resCode = (string) ($response['ResCode'] ?? '');
        if ($resCode !== '0') {
            throw new SadadException($this->describe($resCode, (string) ($response['Description'] ?? '')), $resCode, $response);
        }
        return [
            'amount' => isset($response['Amount']) ? (int) $response['Amount'] : null,
            'retrievalRefNo' => (string) ($response['RetrivalRefNo'] ?? ''), // sic: Sadad's spelling
            'systemTraceNo' => (string) ($response['SystemTraceNo'] ?? ''),
            'orderId' => (string) ($response['OrderId'] ?? ''),
            'description' => (string) ($response['Description'] ?? ''),
            'response' => $response,
        ];
    }

    private function post(string $path, array $payload): array
    {
        return ($this->transport)(rtrim($this->apiBaseUrl, '/') . $path, $payload);
    }

    private function curlPost(string $url, array $payload): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new SadadException("ارتباط با سداد برقرار نشد: {$error}");
        }
        $json = json_decode((string) $body, true);
        if ($status < 200 || $status >= 300 || !is_array($json)) {
            throw new SadadException("پاسخ نامعتبر از سداد دریافت شد [HTTP {$status}]");
        }
        return $json;
    }

    private function describe(string $resCode, string $description): string
    {
        $msg = ($description !== '' ? $description : 'خطای نامشخص از سداد') . ' [ResCode: ' . ($resCode !== '' ? $resCode : 'no-code') . ']';
        if ($resCode === '1025') {
            $msg .= " — TerminalKey با TerminalId {$this->terminalId} همخوانی ندارد؛ کلید را دقیقاً مطابق اطلاعات سداد وارد کنید.";
        }
        return $msg;
    }
}
