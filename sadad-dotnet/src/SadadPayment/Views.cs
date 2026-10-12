// Server-rendered Persian (RTL) pages.
using System.Globalization;
using System.Net;
using System.Text;

namespace SadadPayment;

public static class Views
{
    public static string H(object? v) => WebUtility.HtmlEncode(v?.ToString() ?? "");

    public static string FaDigits(object? v) =>
        string.Concat((v?.ToString() ?? "").Select(c => c is >= '0' and <= '9' ? (char)('۰' + (c - '0')) : c));

    public static string FaNum(long n) => FaDigits(n.ToString("N0", CultureInfo.InvariantCulture)).Replace(',', '٬');

    private static readonly PersianCalendar Pc = new();
    private static readonly TimeZoneInfo Tehran = FindTehran();
    private static TimeZoneInfo FindTehran()
    {
        try { return TimeZoneInfo.FindSystemTimeZoneById("Asia/Tehran"); }
        catch (Exception) { return TimeZoneInfo.CreateCustomTimeZone("Tehran", TimeSpan.FromMinutes(210), "Tehran", "Tehran"); }
    }

    public static string FaDate(DateTimeOffset at)
    {
        var t = TimeZoneInfo.ConvertTime(at, Tehran).DateTime;
        return FaDigits($"{Pc.GetYear(t):0000}/{Pc.GetMonth(t):00}/{Pc.GetDayOfMonth(t):00} {t:HH:mm}");
    }

    private static readonly Dictionary<string, (string Label, string Cls)> Status = new()
    {
        ["created"] = ("در انتظار تأیید", "muted"),
        ["pending"] = ("در درگاه بانک", "warn"),
        ["verifying"] = ("در حال تأیید", "warn"),
        ["paid"] = ("پرداخت‌شده", "ok"),
        ["failed"] = ("ناموفق", "bad"),
        ["canceled"] = ("لغوشده", "bad"),
    };

    private static string Badge(string status) =>
        Status.TryGetValue(status, out var s) ? $"<span class=\"badge {s.Cls}\">{s.Label}</span>" : $"<span class=\"badge muted\">{H(status)}</span>";

    private static string Unit(string currency) => currency == "IRT" ? "تومان" : "ریال";
    private static string Money(Order o) => $"{FaNum(o.Amount)} <small>{Unit(o.Currency)}</small>";

    public static string Layout(string title, string body, bool sandbox) => $$"""
        <!doctype html>
        <html lang="fa" dir="rtl">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>{{H(title)}} — پرداخت سداد</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;600;800&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="/static/style.css">
        </head>
        <body>
        {{(sandbox ? "<div class=\"sandbox-bar\">حالت آزمایشی (Sandbox) — پرداخت‌ها به بانک واقعی ارسال نمی‌شوند</div>" : "")}}
        <header class="top"><a class="brand" href="/"><span class="logo">س</span> پرداخت الکترونیک سداد</a><nav><a href="/">پرداخت جدید</a><a href="/admin">تراکنش‌ها</a></nav></header>
        <main>{{body}}</main>
        <footer>درگاه پرداخت سداد بانک ملی ایران · ASP.NET Core</footer>
        </body>
        </html>
        """;

    public static string Home(string currency, string? error = null, IDictionary<string, string>? values = null)
    {
        values ??= new Dictionary<string, string>();
        string V(string k) => H(values.TryGetValue(k, out var v) ? v : "");
        var unit = Unit(currency);
        long[] presets = currency == "IRT" ? [50000, 250000, 1000000] : [500000, 2500000, 10000000];
        var presetButtons = string.Concat(presets.Select(p => $"<button type=\"button\" data-amount=\"{p}\">{FaNum(p)} {unit}</button>"));
        return $$"""
            <section class="card narrow">
              <h1>پرداخت آنلاین</h1>
              <p class="lead">مبلغ و مشخصات خود را وارد کنید. در مرحله بعد اطلاعات را تأیید می‌کنید و به درگاه امن سداد منتقل می‌شوید.</p>
              {{(error != null ? $"<div class=\"alert bad\">{H(error)}</div>" : "")}}
              <form method="post" action="/orders" class="form">
                <label for="amount">مبلغ ({{unit}})</label>
                <div class="amount-input"><input id="amount" name="amount" inputmode="numeric" autocomplete="off" required value="{{V("amount")}}" placeholder="مثلاً {{FaNum(presets[1])}}"><span>{{unit}}</span></div>
                <div class="presets">{{presetButtons}}</div>
                <label for="name">نام و نام خانوادگی</label>
                <input id="name" name="name" required maxlength="80" value="{{V("name")}}">
                <label for="mobile">شماره موبایل</label>
                <input id="mobile" name="mobile" inputmode="tel" dir="ltr" placeholder="09121234567" maxlength="11" value="{{V("mobile")}}">
                <label for="description">بابت (اختیاری)</label>
                <input id="description" name="description" maxlength="120" value="{{V("description")}}">
                <button class="btn primary" type="submit">ادامه و بررسی پرداخت</button>
              </form>
            </section>
            <script>
            document.querySelectorAll('[data-amount]').forEach(b => b.onclick = () => { document.getElementById('amount').value = b.dataset.amount; });
            </script>
            """;
    }

    public static string Confirm(Order o, string? error = null) => $$"""
        <section class="card narrow">
          <h1>تأیید اطلاعات پرداخت</h1>
          <p class="lead">لطفاً اطلاعات زیر را بررسی کنید. با زدن «تأیید و پرداخت» به درگاه سداد منتقل می‌شوید.</p>
          {{(error != null ? $"<div class=\"alert bad\">{H(error)}</div>" : "")}}
          <dl class="summary">
            <div><dt>مبلغ</dt><dd class="big">{{Money(o)}}</dd></div>
            {{(o.Currency == "IRT" ? $"<div><dt>مبلغ ارسالی به بانک</dt><dd>{FaNum(o.AmountRial)} <small>ریال</small></dd></div>" : "")}}
            <div><dt>پرداخت‌کننده</dt><dd>{{H(o.Name)}}</dd></div>
            {{(o.Mobile.Length > 0 ? $"<div><dt>موبایل</dt><dd>{FaDigits(o.Mobile)}</dd></div>" : "")}}
            {{(o.Description.Length > 0 ? $"<div><dt>بابت</dt><dd>{H(o.Description)}</dd></div>" : "")}}
            <div><dt>وضعیت</dt><dd>{{Badge(o.Status)}}</dd></div>
          </dl>
          <form method="post" action="/orders/{{H(o.Id)}}/pay"><button class="btn primary" type="submit">تأیید و پرداخت</button></form>
          <a class="btn ghost" href="/">انصراف و ویرایش</a>
        </section>
        """;

    public static string Result(Order o)
    {
        if (o.Status == "paid")
        {
            return $$"""
                <section class="card narrow result ok">
                  <div class="icon">✓</div>
                  <h1>پرداخت با موفقیت انجام شد</h1>
                  <p class="lead">پرداخت توسط بانک تأیید (Verify) شد. این اطلاعات را برای پیگیری نگه دارید.</p>
                  <dl class="summary">
                    <div><dt>مبلغ</dt><dd class="big">{{Money(o)}}</dd></div>
                    <div><dt>شماره پیگیری (Trace)</dt><dd class="mono">{{FaDigits(o.SystemTraceNo ?? "—")}}</dd></div>
                    <div><dt>شماره مرجع (RRN)</dt><dd class="mono">{{FaDigits(o.RetrievalRefNo ?? "—")}}</dd></div>
                    <div><dt>شماره سفارش</dt><dd class="mono">{{FaDigits(o.BankOrderId)}}</dd></div>
                    <div><dt>تاریخ</dt><dd>{{FaDate(o.PaidAt ?? o.UpdatedAt)}}</dd></div>
                  </dl>
                  <a class="btn primary" href="/">پرداخت جدید</a>
                </section>
                """;
        }
        var retry = o.Status is "failed" or "canceled";
        var title = o.Status switch { "canceled" => "پرداخت لغو شد", "failed" => "پرداخت ناموفق بود", _ => "وضعیت پرداخت نامشخص است" };
        var message = o.Message.Length > 0 ? o.Message : "مبلغی از حساب شما کسر نشده است؛ در صورت کسر، طی ۷۲ ساعت به حساب شما بازمی‌گردد.";
        return $$"""
            <section class="card narrow result bad">
              <div class="icon">!</div>
              <h1>{{title}}</h1>
              <p class="lead">{{H(message)}}</p>
              <dl class="summary">
                <div><dt>مبلغ</dt><dd>{{Money(o)}}</dd></div>
                <div><dt>وضعیت</dt><dd>{{Badge(o.Status)}}</dd></div>
              </dl>
              {{(retry ? $"<form method=\"post\" action=\"/orders/{H(o.Id)}/pay\"><button class=\"btn primary\" type=\"submit\">تلاش دوباره</button></form>" : "")}}
              <a class="btn ghost" href="/">بازگشت</a>
            </section>
            """;
    }

    public static string Admin(List<Order> orders, bool sandbox, string terminalId, string keyFp)
    {
        var paid = orders.Where(o => o.Status == "paid").ToList();
        var rows = new StringBuilder();
        foreach (var o in orders)
        {
            var refs = string.Join(" / ", new[] { o.SystemTraceNo, o.RetrievalRefNo }.Where(s => !string.IsNullOrEmpty(s)));
            rows.Append($"""
                <tr><td>{FaDate(o.CreatedAt)}</td><td>{H(o.Name)}<br><small>{FaDigits(o.Mobile)}</small></td><td>{Money(o)}</td><td>{Badge(o.Status)}</td>
                <td class="mono">{H(o.BankOrderId ?? "—")}</td><td class="mono">{H(refs.Length > 0 ? refs : "—")}</td><td><a href="/admin/orders/{H(o.Id)}">جزئیات</a></td></tr>
                """);
        }
        if (orders.Count == 0) rows.Append("<tr><td colspan=\"7\" class=\"empty\">هنوز تراکنشی ثبت نشده است.</td></tr>");
        return $$"""
            <section class="stats">
              <div class="card stat"><span>تراکنش‌ها</span><strong>{{FaNum(orders.Count)}}</strong></div>
              <div class="card stat"><span>موفق</span><strong>{{FaNum(paid.Count)}}</strong></div>
              <div class="card stat"><span>جمع دریافتی</span><strong>{{FaNum(paid.Sum(o => o.AmountRial))}} <small>ریال</small></strong></div>
              <div class="card stat"><span>درگاه</span><strong class="mono small">{{(sandbox ? "SANDBOX" : "LIVE")}} · {{H(terminalId)}}</strong><small class="mono">key {{H(keyFp)}}</small></div>
            </section>
            <section class="card">
              <h1>تراکنش‌ها</h1>
              <div class="table-wrap"><table>
                <thead><tr><th>تاریخ</th><th>پرداخت‌کننده</th><th>مبلغ</th><th>وضعیت</th><th>OrderId بانک</th><th>پیگیری / RRN</th><th></th></tr></thead>
                <tbody>{{rows}}</tbody>
              </table></div>
            </section>
            """;
    }

    public static string AdminOrder(Order o)
    {
        var events = string.Concat(o.Events.Select(e =>
            $"<li><time>{FaDate(e.At)}</time> <strong>{H(e.Type)}</strong><pre>{H(e.Data?.ToJsonString(new() { WriteIndented = true, Encoder = System.Text.Encodings.Web.JavaScriptEncoder.UnsafeRelaxedJsonEscaping }) ?? "{}")}</pre></li>"));
        return $$"""
            <section class="card">
              <h1>جزئیات تراکنش {{Badge(o.Status)}}</h1>
              <dl class="summary grid">
                <div><dt>مبلغ</dt><dd>{{Money(o)}} ({{FaNum(o.AmountRial)}} ریال)</dd></div>
                <div><dt>پرداخت‌کننده</dt><dd>{{H(o.Name)}} {{FaDigits(o.Mobile)}}</dd></div>
                <div><dt>OrderId بانک</dt><dd class="mono">{{H(o.BankOrderId ?? "—")}}</dd></div>
                <div><dt>Token</dt><dd class="mono">{{H(o.Token ?? "—")}}</dd></div>
                <div><dt>Trace / RRN</dt><dd class="mono">{{H(o.SystemTraceNo ?? "—")}} / {{H(o.RetrievalRefNo ?? "—")}}</dd></div>
                <div><dt>پیام</dt><dd>{{H(o.Message.Length > 0 ? o.Message : "—")}}</dd></div>
              </dl>
              <h2>رویدادها</h2>
              <ol class="events">{{events}}</ol>
              <a class="btn ghost" href="/admin">بازگشت به فهرست</a>
            </section>
            """;
    }

    public static string Error(string message) =>
        $"<section class=\"card narrow result bad\"><div class=\"icon\">!</div><h1>خطا</h1><p class=\"lead\">{H(message)}</p><a class=\"btn ghost\" href=\"/\">بازگشت</a></section>";

    public static string SandboxBankPage(string token, string orderId, long amount, string terminalId) => $$"""
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
        .hint{font-size:.78rem;color:#667085;margin-top:1rem;line-height:1.8}@media(max-width:720px){.wrap{grid-template-columns:1fr} }
        </style></head><body>
        <div class="sandbox">محیط آزمایشی سداد — هیچ پولی جابه‌جا نمی‌شود</div>
        <div class="wrap">
        <form class="card" method="post" action="/sandbox/VPG/Pay"><h1>اطلاعات کارت</h1>
        <input type="hidden" name="Token" value="{{H(token)}}">
        <label>شماره کارت</label><input name="pan" value="6037 9911 1111 1111" inputmode="numeric">
        <div class="row"><div><label>CVV2</label><input name="cvv2" value="123"></div><div><label>ماه انقضا</label><input name="mm" value="12"></div><div><label>سال انقضا</label><input name="yy" value="08"></div></div>
        <label>رمز دوم (پویا)</label><input name="pin2" value="123456" type="password">
        <div class="btns"><button class="pay" name="action" value="pay">پرداخت</button><button class="cancel" name="action" value="cancel">انصراف</button></div>
        <p class="hint">شبیه‌ساز درگاه: «پرداخت» تراکنش موفق و «انصراف» تراکنش لغوشده را به فروشگاه برمی‌گرداند.</p></form>
        <div class="card"><strong style="color:#0b3b8c">سداد — بانک ملی ایران</strong><dl>
        <dt>مبلغ قابل پرداخت</dt><dd class="amount">{{FaNum(amount)}} ریال</dd>
        <dt>شماره سفارش</dt><dd>{{H(orderId)}}</dd><dt>کد ترمینال</dt><dd>{{H(terminalId)}}</dd></dl></div>
        </div></body></html>
        """;

    public static string AutoPostPage(string action, Dictionary<string, string> fields)
    {
        var inputs = string.Concat(fields.Select(f => $"<input type=\"hidden\" name=\"{H(f.Key)}\" value=\"{H(f.Value)}\">"));
        return $"""
            <!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><title>بازگشت به فروشگاه</title>
            <body onload="document.forms[0].submit()" style="font-family:Tahoma,sans-serif;text-align:center;padding:4rem">
            <p>در حال بازگشت به فروشگاه…</p><form method="post" action="{H(action)}">
            {inputs}
            <noscript><button>بازگشت به فروشگاه</button></noscript></form></body></html>
            """;
    }
}
