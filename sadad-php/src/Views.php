<?php
// Server-rendered Persian (RTL) pages.
declare(strict_types=1);

namespace Sadad;

final class Views
{
    public static function h(mixed $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function faDigits(mixed $v): string
    {
        return strtr((string) $v, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
    }

    public static function faNum(int $n): string
    {
        return self::faDigits(number_format($n, 0, '.', '٬'));
    }

    /** Gregorian → Jalali (Persian calendar), so no ext-intl is needed. */
    private static function jalali(int $gy, int $gm, int $gd): array
    {
        $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $gy2 = $gm > 2 ? $gy + 1 : $gy;
        $days = 355666 + 365 * $gy + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400) + $gd + $g_d_m[$gm - 1];
        $jy = -1595 + 33 * intdiv($days, 12053);
        $days %= 12053;
        $jy += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) {
            $jy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }
        return $days < 186 ? [$jy, 1 + intdiv($days, 31), 1 + $days % 31] : [$jy, 7 + intdiv($days - 186, 30), 1 + ($days - 186) % 30];
    }

    public static function faDate(?string $iso): string
    {
        $t = (new \DateTimeImmutable($iso ?? 'now'))->setTimezone(new \DateTimeZone('Asia/Tehran'));
        [$y, $m, $d] = self::jalali((int) $t->format('Y'), (int) $t->format('n'), (int) $t->format('j'));
        return self::faDigits(sprintf('%04d/%02d/%02d %s', $y, $m, $d, $t->format('H:i')));
    }

    private const STATUS = [
        'created' => ['در انتظار تأیید', 'muted'],
        'pending' => ['در درگاه بانک', 'warn'],
        'verifying' => ['در حال تأیید', 'warn'],
        'paid' => ['پرداخت‌شده', 'ok'],
        'failed' => ['ناموفق', 'bad'],
        'canceled' => ['لغوشده', 'bad'],
    ];

    private static function badge(string $status): string
    {
        [$label, $cls] = self::STATUS[$status] ?? [self::h($status), 'muted'];
        return "<span class=\"badge {$cls}\">{$label}</span>";
    }

    private static function unit(string $currency): string
    {
        return $currency === 'IRT' ? 'تومان' : 'ریال';
    }

    private static function money(array $o): string
    {
        return self::faNum((int) $o['amount']) . ' <small>' . self::unit($o['currency']) . '</small>';
    }

    public static function layout(string $title, string $body, bool $sandbox): string
    {
        $t = self::h($title);
        $bar = $sandbox ? '<div class="sandbox-bar">حالت آزمایشی (Sandbox) — پرداخت‌ها به بانک واقعی ارسال نمی‌شوند</div>' : '';
        return <<<HTML
            <!doctype html>
            <html lang="fa" dir="rtl">
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width,initial-scale=1">
            <title>{$t} — پرداخت سداد</title>
            <link rel="preconnect" href="https://fonts.googleapis.com">
            <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;600;800&display=swap" rel="stylesheet">
            <link rel="stylesheet" href="/static/style.css">
            </head>
            <body>
            {$bar}
            <header class="top"><a class="brand" href="/"><span class="logo">س</span> پرداخت الکترونیک سداد</a><nav><a href="/">پرداخت جدید</a><a href="/admin">تراکنش‌ها</a></nav></header>
            <main>{$body}</main>
            <footer>درگاه پرداخت سداد بانک ملی ایران · PHP</footer>
            </body>
            </html>
            HTML;
    }

    public static function home(string $currency, ?string $error = null, array $values = []): string
    {
        $unit = self::unit($currency);
        $presets = $currency === 'IRT' ? [50000, 250000, 1000000] : [500000, 2500000, 10000000];
        $buttons = implode('', array_map(fn ($p) => "<button type=\"button\" data-amount=\"{$p}\">" . self::faNum($p) . " {$unit}</button>", $presets));
        $err = $error !== null ? '<div class="alert bad">' . self::h($error) . '</div>' : '';
        $v = fn (string $k) => self::h($values[$k] ?? '');
        $placeholder = self::faNum($presets[1]);
        return <<<HTML
            <section class="card narrow">
              <h1>پرداخت آنلاین</h1>
              <p class="lead">مبلغ و مشخصات خود را وارد کنید. در مرحله بعد اطلاعات را تأیید می‌کنید و به درگاه امن سداد منتقل می‌شوید.</p>
              {$err}
              <form method="post" action="/orders" class="form">
                <label for="amount">مبلغ ({$unit})</label>
                <div class="amount-input"><input id="amount" name="amount" inputmode="numeric" autocomplete="off" required value="{$v('amount')}" placeholder="مثلاً {$placeholder}"><span>{$unit}</span></div>
                <div class="presets">{$buttons}</div>
                <label for="name">نام و نام خانوادگی</label>
                <input id="name" name="name" required maxlength="80" value="{$v('name')}">
                <label for="mobile">شماره موبایل</label>
                <input id="mobile" name="mobile" inputmode="tel" dir="ltr" placeholder="09121234567" maxlength="11" value="{$v('mobile')}">
                <label for="description">بابت (اختیاری)</label>
                <input id="description" name="description" maxlength="120" value="{$v('description')}">
                <button class="btn primary" type="submit">ادامه و بررسی پرداخت</button>
              </form>
            </section>
            <script>
            document.querySelectorAll('[data-amount]').forEach(b => b.onclick = () => { document.getElementById('amount').value = b.dataset.amount; });
            </script>
            HTML;
    }

    public static function confirm(array $o, ?string $error = null): string
    {
        $err = $error !== null ? '<div class="alert bad">' . self::h($error) . '</div>' : '';
        $rial = $o['currency'] === 'IRT' ? '<div><dt>مبلغ ارسالی به بانک</dt><dd>' . self::faNum((int) $o['amountRial']) . ' <small>ریال</small></dd></div>' : '';
        $mobile = $o['mobile'] !== '' ? '<div><dt>موبایل</dt><dd>' . self::faDigits($o['mobile']) . '</dd></div>' : '';
        $desc = $o['description'] !== '' ? '<div><dt>بابت</dt><dd>' . self::h($o['description']) . '</dd></div>' : '';
        $money = self::money($o);
        $name = self::h($o['name']);
        $badge = self::badge($o['status']);
        $id = self::h($o['id']);
        return <<<HTML
            <section class="card narrow">
              <h1>تأیید اطلاعات پرداخت</h1>
              <p class="lead">لطفاً اطلاعات زیر را بررسی کنید. با زدن «تأیید و پرداخت» به درگاه سداد منتقل می‌شوید.</p>
              {$err}
              <dl class="summary">
                <div><dt>مبلغ</dt><dd class="big">{$money}</dd></div>
                {$rial}
                <div><dt>پرداخت‌کننده</dt><dd>{$name}</dd></div>
                {$mobile}
                {$desc}
                <div><dt>وضعیت</dt><dd>{$badge}</dd></div>
              </dl>
              <form method="post" action="/orders/{$id}/pay"><button class="btn primary" type="submit">تأیید و پرداخت</button></form>
              <a class="btn ghost" href="/">انصراف و ویرایش</a>
            </section>
            HTML;
    }

    public static function result(array $o): string
    {
        $money = self::money($o);
        if ($o['status'] === 'paid') {
            $trace = self::faDigits($o['systemTraceNo'] ?: '—');
            $rrn = self::faDigits($o['retrievalRefNo'] ?: '—');
            $bankOrder = self::faDigits($o['bankOrderId']);
            $date = self::faDate($o['paidAt'] ?? $o['updatedAt']);
            return <<<HTML
                <section class="card narrow result ok">
                  <div class="icon">✓</div>
                  <h1>پرداخت با موفقیت انجام شد</h1>
                  <p class="lead">پرداخت توسط بانک تأیید (Verify) شد. این اطلاعات را برای پیگیری نگه دارید.</p>
                  <dl class="summary">
                    <div><dt>مبلغ</dt><dd class="big">{$money}</dd></div>
                    <div><dt>شماره پیگیری (Trace)</dt><dd class="mono">{$trace}</dd></div>
                    <div><dt>شماره مرجع (RRN)</dt><dd class="mono">{$rrn}</dd></div>
                    <div><dt>شماره سفارش</dt><dd class="mono">{$bankOrder}</dd></div>
                    <div><dt>تاریخ</dt><dd>{$date}</dd></div>
                  </dl>
                  <a class="btn primary" href="/">پرداخت جدید</a>
                </section>
                HTML;
        }
        $title = match ($o['status']) { 'canceled' => 'پرداخت لغو شد', 'failed' => 'پرداخت ناموفق بود', default => 'وضعیت پرداخت نامشخص است' };
        $message = self::h($o['message'] !== '' ? $o['message'] : 'مبلغی از حساب شما کسر نشده است؛ در صورت کسر، طی ۷۲ ساعت به حساب شما بازمی‌گردد.');
        $badge = self::badge($o['status']);
        $retry = in_array($o['status'], ['failed', 'canceled'], true)
            ? '<form method="post" action="/orders/' . self::h($o['id']) . '/pay"><button class="btn primary" type="submit">تلاش دوباره</button></form>' : '';
        return <<<HTML
            <section class="card narrow result bad">
              <div class="icon">!</div>
              <h1>{$title}</h1>
              <p class="lead">{$message}</p>
              <dl class="summary">
                <div><dt>مبلغ</dt><dd>{$money}</dd></div>
                <div><dt>وضعیت</dt><dd>{$badge}</dd></div>
              </dl>
              {$retry}
              <a class="btn ghost" href="/">بازگشت</a>
            </section>
            HTML;
    }

    public static function admin(array $orders, bool $sandbox, string $terminalId, string $keyFp): string
    {
        $paid = array_filter($orders, fn ($o) => $o['status'] === 'paid');
        $rows = '';
        foreach ($orders as $o) {
            $refs = implode(' / ', array_filter([$o['systemTraceNo'], $o['retrievalRefNo']]));
            $rows .= '<tr><td>' . self::faDate($o['createdAt']) . '</td><td>' . self::h($o['name']) . '<br><small>' . self::faDigits($o['mobile']) . '</small></td>'
                . '<td>' . self::money($o) . '</td><td>' . self::badge($o['status']) . '</td><td class="mono">' . self::h($o['bankOrderId'] ?? '—') . '</td>'
                . '<td class="mono">' . self::h($refs !== '' ? $refs : '—') . '</td><td><a href="/admin/orders/' . self::h($o['id']) . '">جزئیات</a></td></tr>';
        }
        if (!$orders) $rows = '<tr><td colspan="7" class="empty">هنوز تراکنشی ثبت نشده است.</td></tr>';
        $count = self::faNum(count($orders));
        $paidCount = self::faNum(count($paid));
        $total = self::faNum(array_sum(array_map(fn ($o) => (int) $o['amountRial'], $paid)));
        $mode = $sandbox ? 'SANDBOX' : 'LIVE';
        $tid = self::h($terminalId);
        $fp = self::h($keyFp);
        return <<<HTML
            <section class="stats">
              <div class="card stat"><span>تراکنش‌ها</span><strong>{$count}</strong></div>
              <div class="card stat"><span>موفق</span><strong>{$paidCount}</strong></div>
              <div class="card stat"><span>جمع دریافتی</span><strong>{$total} <small>ریال</small></strong></div>
              <div class="card stat"><span>درگاه</span><strong class="mono small">{$mode} · {$tid}</strong><small class="mono">key {$fp}</small></div>
            </section>
            <section class="card">
              <h1>تراکنش‌ها</h1>
              <div class="table-wrap"><table>
                <thead><tr><th>تاریخ</th><th>پرداخت‌کننده</th><th>مبلغ</th><th>وضعیت</th><th>OrderId بانک</th><th>پیگیری / RRN</th><th></th></tr></thead>
                <tbody>{$rows}</tbody>
              </table></div>
            </section>
            HTML;
    }

    public static function adminOrder(array $o): string
    {
        $events = '';
        foreach ($o['events'] as $e) {
            $events .= '<li><time>' . self::faDate($e['at']) . '</time> <strong>' . self::h($e['type']) . '</strong><pre>'
                . self::h(json_encode($e['data'] ?? new \stdClass(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</pre></li>';
        }
        $badge = self::badge($o['status']);
        $money = self::money($o);
        $rial = self::faNum((int) $o['amountRial']);
        $payer = self::h($o['name']) . ' ' . self::faDigits($o['mobile']);
        $bankOrder = self::h($o['bankOrderId'] ?? '—');
        $token = self::h($o['token'] ?? '—');
        $refs = self::h(($o['systemTraceNo'] ?: '—') . ' / ' . ($o['retrievalRefNo'] ?: '—'));
        $msg = self::h($o['message'] !== '' ? $o['message'] : '—');
        return <<<HTML
            <section class="card">
              <h1>جزئیات تراکنش {$badge}</h1>
              <dl class="summary grid">
                <div><dt>مبلغ</dt><dd>{$money} ({$rial} ریال)</dd></div>
                <div><dt>پرداخت‌کننده</dt><dd>{$payer}</dd></div>
                <div><dt>OrderId بانک</dt><dd class="mono">{$bankOrder}</dd></div>
                <div><dt>Token</dt><dd class="mono">{$token}</dd></div>
                <div><dt>Trace / RRN</dt><dd class="mono">{$refs}</dd></div>
                <div><dt>پیام</dt><dd>{$msg}</dd></div>
              </dl>
              <h2>رویدادها</h2>
              <ol class="events">{$events}</ol>
              <a class="btn ghost" href="/admin">بازگشت به فهرست</a>
            </section>
            HTML;
    }

    public static function error(string $message): string
    {
        return '<section class="card narrow result bad"><div class="icon">!</div><h1>خطا</h1><p class="lead">' . self::h($message) . '</p><a class="btn ghost" href="/">بازگشت</a></section>';
    }

    public static function sandboxBankPage(string $token, string $orderId, int $amount, string $terminalId): string
    {
        $t = self::h($token);
        $oid = self::h($orderId);
        $amt = self::faNum($amount);
        $tid = self::h($terminalId);
        return <<<HTML
            <!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">
            <meta name="viewport" content="width=device-width,initial-scale=1"><title>درگاه پرداخت سداد — محیط آزمایشی</title>
            <style>
            *{box-sizing:border-box}body{margin:0;font-family:Vazirmatn,Tahoma,sans-serif;background:#eef2f7;color:#1d2939}
            .sandbox{background:#f79009;color:#fff;text-align:center;padding:.5rem;font-size:.85rem}
            .wrap{max-width:860px;margin:2rem auto;padding:0 1rem;display:grid;grid-template-columns:1fr 300px;gap:1rem}
            .card{background:#fff;border-radius:14px;box-shadow:0 4px 18px rgba(16,24,40,.06);padding:1.4rem}
            h1{font-size:1.1rem;margin:0 0 1.2rem;color:#0b3b8c}label{display:block;font-size:.85rem;margin:.9rem 0 .35rem;color:#475467}
            input{width:100%;padding:.7rem .8rem;border:1px solid #d0d5dd;border-radius:9px;font-size:1rem;direction:ltr;text-align:center;font-family:monospace}
            .row{display:grid;grid-template-columns:1fr 1fr 1fr;gap:.6rem}.btns{display:flex;gap:.6rem;margin-top:1.4rem}
            button{flex:1;padding:.8rem;border:0;border-radius:9px;font-size:1rem;font-family:inherit;cursor:pointer}
            .pay{background:#155eef;color:#fff}.cancel{background:#f2f4f7;color:#b42318}
            dt{font-size:.8rem;color:#667085;margin-top:.9rem}dd{margin:.2rem 0 0;font-weight:700}.amount{font-size:1.4rem;color:#0b3b8c}
            .hint{font-size:.78rem;color:#667085;margin-top:1rem;line-height:1.8}@media(max-width:720px){.wrap{grid-template-columns:1fr}}
            </style></head><body>
            <div class="sandbox">محیط آزمایشی سداد — هیچ پولی جابه‌جا نمی‌شود</div>
            <div class="wrap">
            <form class="card" method="post" action="/sandbox/VPG/Pay"><h1>اطلاعات کارت</h1>
            <input type="hidden" name="Token" value="{$t}">
            <label>شماره کارت</label><input name="pan" value="6037 9911 1111 1111" inputmode="numeric">
            <div class="row"><div><label>CVV2</label><input name="cvv2" value="123"></div><div><label>ماه انقضا</label><input name="mm" value="12"></div><div><label>سال انقضا</label><input name="yy" value="08"></div></div>
            <label>رمز دوم (پویا)</label><input name="pin2" value="123456" type="password">
            <div class="btns"><button class="pay" name="action" value="pay">پرداخت</button><button class="cancel" name="action" value="cancel">انصراف</button></div>
            <p class="hint">شبیه‌ساز درگاه: «پرداخت» تراکنش موفق و «انصراف» تراکنش لغوشده را به فروشگاه برمی‌گرداند.</p></form>
            <div class="card"><strong style="color:#0b3b8c">سداد — بانک ملی ایران</strong><dl>
            <dt>مبلغ قابل پرداخت</dt><dd class="amount">{$amt} ریال</dd>
            <dt>شماره سفارش</dt><dd>{$oid}</dd><dt>کد ترمینال</dt><dd>{$tid}</dd></dl></div>
            </div></body></html>
            HTML;
    }

    public static function autoPostPage(string $action, array $fields): string
    {
        $inputs = '';
        foreach ($fields as $k => $v) $inputs .= '<input type="hidden" name="' . self::h($k) . '" value="' . self::h($v) . '">';
        $a = self::h($action);
        return <<<HTML
            <!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><title>بازگشت به فروشگاه</title>
            <body onload="document.forms[0].submit()" style="font-family:Tahoma,sans-serif;text-align:center;padding:4rem">
            <p>در حال بازگشت به فروشگاه…</p><form method="post" action="{$a}">{$inputs}
            <noscript><button>بازگشت به فروشگاه</button></noscript></form></body></html>
            HTML;
    }
}
