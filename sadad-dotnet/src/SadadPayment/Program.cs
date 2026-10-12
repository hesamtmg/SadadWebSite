// Sadad payment app: form → confirm → Sadad PaymentRequest → bank → callback → Verify → receipt.
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Text.Json.Nodes;
using SadadPayment;

var config = AppConfig.Load();
var bank = config.IsSandbox ? new SandboxBank(config.Sandbox) : null;
SadadClient sadad;
try
{
    sadad = new SadadClient(config.Sadad, bank is null ? null : new SandboxHandler(bank));
}
catch (SadadException ex)
{
    Console.Error.WriteLine($"\nSadad configuration error ({config.Mode} mode): {ex.Message}");
    if (!config.IsSandbox) Console.Error.WriteLine("Set SADAD_MERCHANT_ID, SADAD_TERMINAL_ID and SADAD_TERMINAL_KEY in .env.\n");
    Environment.Exit(1);
    return;
}
var store = new OrderStore(config.DataDir);
const long MinRial = 1000;
const long MaxRial = 5_000_000_000; // 500 million Toman; adjust to your terminal's limit

var builder = WebApplication.CreateBuilder(args);
builder.WebHost.UseUrls($"http://0.0.0.0:{config.Port}");
builder.Logging.AddFilter("Microsoft.AspNetCore", LogLevel.Warning);
var app = builder.Build();

app.Use(async (ctx, next) =>
{
    ctx.Response.Headers["X-Content-Type-Options"] = "nosniff";
    ctx.Response.Headers["Referrer-Policy"] = "same-origin";
    ctx.Response.Headers["X-Frame-Options"] = "DENY";
    await next();
});
app.UseStaticFiles(new StaticFileOptions { RequestPath = "/static", FileProvider = app.Environment.WebRootFileProvider });

void Log(string msg, object? data = null) =>
    Console.WriteLine($"[{DateTimeOffset.UtcNow:O}] {msg} {(data is null ? "" : JsonSerializer.Serialize(data))}");

IResult Page(string title, string body, int status = 200) =>
    Results.Content(Views.Layout(title, body, config.IsSandbox), "text/html; charset=utf-8", Encoding.UTF8, status);

IResult See(string location) => Results.Redirect(location, permanent: false, preserveMethod: false); // 302 → GET

static string AsciiDigits(string? s) => string.Concat((s ?? "").Select(c => c switch
{
    >= '۰' and <= '۹' => (char)('0' + (c - '۰')),
    >= '٠' and <= '٩' => (char)('0' + (c - '٠')),
    _ => c,
}));

static string Field(IFormCollection f, params string[] names) =>
    names.Select(n => f[n].ToString()).FirstOrDefault(v => v.Length > 0) ?? "";

bool CheckAdmin(HttpContext ctx)
{
    if (config.AdminPassword.Length == 0) return true;
    var header = ctx.Request.Headers.Authorization.ToString();
    if (header.StartsWith("Basic ", StringComparison.Ordinal))
    {
        string decoded;
        try { decoded = Encoding.UTF8.GetString(Convert.FromBase64String(header[6..])); } catch (FormatException) { decoded = ""; }
        var sep = decoded.IndexOf(':');
        if (sep > 0)
        {
            static bool Eq(string a, string b) => CryptographicOperations.FixedTimeEquals(Encoding.UTF8.GetBytes(a), Encoding.UTF8.GetBytes(b));
            if (Eq(decoded[..sep], config.AdminUser) && Eq(decoded[(sep + 1)..], config.AdminPassword)) return true;
        }
    }
    ctx.Response.Headers.WWWAuthenticate = "Basic realm=\"Sadad admin\", charset=\"UTF-8\"";
    return false;
}

// ---------------------------------------------------------------- pages

app.MapGet("/", () => Page("پرداخت آنلاین", Views.Home(config.Currency)));
app.MapGet("/health", () => Results.Json(new { ok = true, mode = config.Mode }));

app.MapPost("/orders", async (HttpRequest req) =>
{
    var form = await req.ReadFormAsync();
    var values = new Dictionary<string, string> { ["amount"] = form["amount"].ToString(), ["name"] = form["name"].ToString(), ["mobile"] = form["mobile"].ToString(), ["description"] = form["description"].ToString() };
    var amountText = string.Concat(AsciiDigits(form["amount"]).Where(c => c is not (',' or ' ' or '٬' or '،')));
    var name = form["name"].ToString().Trim();
    var mobile = AsciiDigits(form["mobile"]).Replace(" ", "");
    var description = form["description"].ToString().Trim();
    if (name.Length > 80) name = name[..80];
    if (description.Length > 120) description = description[..120];

    string? error = null;
    long amountRial = 0;
    if (!long.TryParse(amountText, out var amount) || amount <= 0) error = "مبلغ را به صورت عدد صحیح وارد کنید.";
    else
    {
        amountRial = config.Currency == "IRT" ? amount * 10 : amount;
        if (amountRial < MinRial) error = $"حداقل مبلغ {Views.FaNum(config.Currency == "IRT" ? MinRial / 10 : MinRial)} {(config.Currency == "IRT" ? "تومان" : "ریال")} است.";
        else if (amountRial > MaxRial) error = "مبلغ بیش از سقف مجاز است.";
        else if (name.Length == 0) error = "نام پرداخت‌کننده را وارد کنید.";
        else if (mobile.Length > 0 && !System.Text.RegularExpressions.Regex.IsMatch(mobile, "^09[0-9]{9}$")) error = "شماره موبایل باید ۱۱ رقم و با ۰۹ شروع شود.";
    }
    if (error != null) return Page("پرداخت آنلاین", Views.Home(config.Currency, error, values), 422);

    var order = store.Create(amount, amountRial, config.Currency, name, mobile, description);
    return See($"/orders/{order.Id}");
});

app.MapGet("/orders/{id:regex(^[a-f0-9]{{16}}$)}", (string id) =>
{
    var order = store.Get(id);
    if (order is null) return Page("یافت نشد", Views.Error("سفارش پیدا نشد."), 404);
    return order.Status == "created" ? Page("تأیید اطلاعات پرداخت", Views.Confirm(order)) : See($"/orders/{id}/result");
});

app.MapGet("/orders/{id:regex(^[a-f0-9]{{16}}$)}/result", (string id) =>
{
    var order = store.Get(id);
    return order is null ? Page("یافت نشد", Views.Error("سفارش پیدا نشد."), 404) : Page("نتیجه پرداخت", Views.Result(order));
});

// ---------------------------------------------------------------- step 1–2: PaymentRequest and redirect to the bank

app.MapPost("/orders/{id:regex(^[a-f0-9]{{16}}$)}/pay", async (string id) =>
{
    var order = store.Get(id);
    if (order is null) return Page("یافت نشد", Views.Error("سفارش پیدا نشد."), 404);
    if (order.Status is not ("created" or "failed" or "canceled")) return See($"/orders/{id}/result");

    var bankOrderId = store.NextBankOrderId(); // fresh OrderId per attempt: Sadad rejects reused ones
    var returnUrl = $"{config.AppUrl}/payment/callback";
    try
    {
        var r = await sadad.PaymentRequestAsync(bankOrderId, order.AmountRial, returnUrl);
        var data = JsonSerializer.SerializeToNode(r.Debug)!.AsObject();
        data["bankOrderId"] = bankOrderId; data["token"] = r.Token; data["returnUrl"] = returnUrl;
        store.Update(id, o => { o.Status = "pending"; o.BankOrderId = bankOrderId; o.Token = r.Token; o.Message = ""; return true; }, "payment_request.ok", data);
        Log("PaymentRequest ok", new { order = id, bankOrderId, amountRial = order.AmountRial });
        return See(r.PaymentUrl);
    }
    catch (SadadException ex)
    {
        var data = new JsonObject { ["bankOrderId"] = bankOrderId, ["resCode"] = ex.ResCode, ["response"] = ex.Response?.DeepClone(), ["message"] = ex.Message };
        store.Update(id, o => { o.Status = "failed"; o.BankOrderId = bankOrderId; o.Message = ex.Message; return true; }, "payment_request.error", data);
        Log("PaymentRequest failed", new { order = id, bankOrderId, ex.ResCode, ex.Message });
        return Page("تأیید اطلاعات پرداخت", Views.Confirm(store.Get(id)!, $"درخواست پرداخت توسط سداد پذیرفته نشد: {ex.Message}"), 502);
    }
});

// ---------------------------------------------------------------- step 3–4: bank callback and Verify

async Task<IResult> Callback(HttpRequest req)
{
    IFormCollection form = req.HasFormContentType ? await req.ReadFormAsync() : new FormCollection(req.Query.ToDictionary(q => q.Key, q => q.Value));
    var token = Field(form, "token", "Token").Trim();
    var resCode = Field(form, "ResCode", "rescode").Trim();
    var postedOrderId = AsciiDigits(Field(form, "OrderId", "orderId")).Trim();
    var order = store.FindByToken(token) ?? store.FindByBankOrderId(postedOrderId);
    Log("Callback", new { token, resCode, postedOrderId, found = order != null });

    if (order is null) return Page("خطا", Views.Error("سفارش مربوط به این پرداخت پیدا نشد."), 400);
    var result = $"/orders/{order.Id}/result";

    IResult Fail(string status, string message, JsonObject data)
    {
        data["message"] = message;
        store.Update(order.Id, o => { if (o.Status == "paid") return false; o.Status = status; o.Message = message; return true; }, $"callback.{status}", data);
        return See(result);
    }

    if (order.Status is "paid" or "verifying") return See(result); // refresh / double POST
    if (token.Length > 0 && order.Token != null && token != order.Token) return Fail("failed", "توکن بازگشتی با درخواست اولیه مطابقت ندارد.", new() { ["token"] = token });
    if (postedOrderId.Length > 0 && postedOrderId != order.BankOrderId) return Fail("failed", "شماره سفارش بازگشتی با درخواست اولیه مطابقت ندارد.", new() { ["postedOrderId"] = postedOrderId });
    if (resCode != "0" || token.Length == 0) return Fail("canceled", $"پرداخت در بانک انجام نشد یا توسط شما لغو شد. [ResCode: {(resCode.Length > 0 ? resCode : "نامشخص")}]", new() { ["resCode"] = resCode });

    // Claim the order atomically so two concurrent callbacks never verify twice.
    var claimed = store.Update(order.Id, o => { if (o.Status is not ("pending" or "failed" or "canceled")) return false; o.Status = "verifying"; return true; },
        "callback.ok", new JsonObject { ["resCode"] = resCode, ["token"] = token });
    if (!claimed) return See(result);

    try
    {
        var v = await sadad.VerifyAsync(token);
        if (v.Amount is long amt && amt != order.AmountRial)
            return Fail("failed", "مبلغ تأییدشده با مبلغ سفارش مطابقت ندارد. با پشتیبانی تماس بگیرید.", new() { ["expected"] = order.AmountRial, ["verified"] = amt, ["response"] = v.Response.DeepClone() });
        store.Update(order.Id, o =>
        {
            o.Status = "paid"; o.Message = ""; o.PaidAt = DateTimeOffset.UtcNow;
            o.SystemTraceNo = v.SystemTraceNo; o.RetrievalRefNo = v.RetrievalRefNo;
            return true;
        }, "verify.ok", v.Response.DeepClone());
        Log("Verify ok", new { order = order.Id, trace = v.SystemTraceNo, rrn = v.RetrievalRefNo });
    }
    catch (SadadException ex)
    {
        Log("Verify failed", new { order = order.Id, ex.ResCode, ex.Message });
        return Fail("failed", $"تأیید تراکنش در سداد ناموفق بود: {ex.Message}", new() { ["resCode"] = ex.ResCode, ["response"] = ex.Response?.DeepClone() });
    }
    return See(result);
}

// Sadad posts back here (some setups use GET; accept both).
app.MapPost("/payment/callback", Callback);
app.MapGet("/payment/callback", Callback);

// ---------------------------------------------------------------- admin

app.MapGet("/admin", (HttpContext ctx) => !CheckAdmin(ctx)
    ? Results.Text("Authentication required", statusCode: 401)
    : Page("تراکنش‌ها", Views.Admin(store.List(), config.IsSandbox, sadad.TerminalId, sadad.KeyFingerprint)));

app.MapGet("/admin/orders/{id:regex(^[a-f0-9]{{16}}$)}", (HttpContext ctx, string id) =>
{
    if (!CheckAdmin(ctx)) return Results.Text("Authentication required", statusCode: 401);
    var order = store.Get(id);
    return order is null ? Page("یافت نشد", Views.Error("سفارش پیدا نشد."), 404) : Page("جزئیات تراکنش", Views.AdminOrder(order));
});

// ---------------------------------------------------------------- sandbox bank pages

if (bank is not null)
{
    app.MapGet("/sandbox/VPG/Purchase", (string? Token) => bank.PurchasePage(Token ?? ""));
    app.MapPost("/sandbox/VPG/Pay", async (HttpRequest req) =>
    {
        var form = await req.ReadFormAsync();
        return bank.Pay(form["Token"].ToString(), form["action"].ToString());
    });
}

app.MapFallback(() => Page("یافت نشد", Views.Error("صفحه مورد نظر پیدا نشد."), 404));

app.Lifetime.ApplicationStarted.Register(() =>
{
    Console.WriteLine($"\nSadad payment app on {config.AppUrl}  (port {config.Port})");
    Console.WriteLine($"  mode:       {config.Mode.ToUpperInvariant()}{(config.IsSandbox ? " (built-in test bank, no real money)" : " → " + config.Sadad.ApiBaseUrl)}");
    Console.WriteLine($"  terminal:   {sadad.TerminalId}   merchant: {config.Sadad.MerchantId}   key fingerprint: {sadad.KeyFingerprint}");
    Console.WriteLine($"  callback:   {config.AppUrl}/payment/callback");
    Console.WriteLine($"  admin:      {config.AppUrl}/admin{(config.AdminPassword.Length > 0 ? $" (user \"{config.AdminUser}\")" : " (no password set!)")}\n");
});

app.Run();

public partial class Program; // for WebApplicationFactory in tests
