<?php
// Settings from environment variables, optionally loaded from a .env file in the project root.
declare(strict_types=1);

namespace Sadad;

final class Config
{
    public readonly string $root;
    public readonly string $appUrl;
    public readonly string $mode;       // "sandbox" | "live"
    public readonly string $currency;   // "IRT" (Toman, ×10 to Rial) | "IRR"
    public readonly string $dataDir;
    public readonly string $adminUser;
    public readonly string $adminPassword;
    /** @var array{merchantId: string, terminalId: string, terminalKey: string, apiBaseUrl: string, purchaseBaseUrl: string, timeout: int} */
    public readonly array $sadad;
    /** @var array{merchantId: string, terminalId: string, terminalKey: string} */
    public readonly array $sandbox;

    public function __construct()
    {
        $this->root = dirname(__DIR__);
        self::loadDotEnv($this->root . '/.env');
        $env = static fn (string $name, string $fallback = '') => ($v = trim((string) getenv($name))) !== '' ? $v : $fallback;

        $this->mode = strtolower($env('SADAD_MODE', 'sandbox'));
        if (!in_array($this->mode, ['sandbox', 'live'], true)) {
            throw new \RuntimeException("SADAD_MODE must be \"sandbox\" or \"live\", got \"{$this->mode}\"");
        }
        $this->appUrl = rtrim($env('APP_URL', 'http://localhost:' . $env('PORT', '8090')), '/');
        $this->currency = strtoupper($env('CURRENCY', 'IRT')) === 'IRR' ? 'IRR' : 'IRT';
        $dataDir = $env('DATA_DIR', $this->root . '/data');
        $this->dataDir = str_starts_with($dataDir, '/') ? $dataDir : $this->root . '/' . $dataDir;
        $this->adminUser = $env('ADMIN_USER', 'admin');
        $this->adminPassword = $env('ADMIN_PASSWORD');

        // Sandbox: a random key generated once and kept in data/, shared only with the built-in test bank.
        $this->sandbox = ['merchantId' => '000000140000000', 'terminalId' => '24000000', 'terminalKey' => $this->sandboxKey()];

        $this->sadad = $this->mode === 'live'
            ? [
                'merchantId' => $env('SADAD_MERCHANT_ID'), 'terminalId' => $env('SADAD_TERMINAL_ID'), 'terminalKey' => $env('SADAD_TERMINAL_KEY'),
                'apiBaseUrl' => $env('SADAD_API_URL', SadadClient::LIVE_URL), 'purchaseBaseUrl' => $env('SADAD_PURCHASE_URL', SadadClient::LIVE_URL),
                'timeout' => (int) $env('SADAD_TIMEOUT', '30'),
            ]
            : $this->sandbox + ['apiBaseUrl' => 'http://sandbox.local', 'purchaseBaseUrl' => $this->appUrl . '/sandbox', 'timeout' => 10];
    }

    public function isSandbox(): bool
    {
        return $this->mode === 'sandbox';
    }

    private function sandboxKey(): string
    {
        if (!is_dir($this->dataDir)) mkdir($this->dataDir, 0775, true);
        $file = $this->dataDir . '/sandbox-key';
        $key = is_file($file) ? trim((string) file_get_contents($file)) : '';
        if ($key === '') {
            $key = base64_encode(random_bytes(24));
            file_put_contents($file, $key, LOCK_EX);
        }
        return $key;
    }

    private static function loadDotEnv(string $file): void
    {
        if (!is_file($file)) return;
        foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
            if (!preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*?)\s*$/', $line, $m) || str_starts_with(ltrim($line), '#')) continue;
            $value = preg_match('/^([\'"])(.*)\1$/', $m[2], $q) ? $q[2] : $m[2];
            if (getenv($m[1]) === false) putenv("{$m[1]}={$value}"); // real env vars win
        }
    }
}
