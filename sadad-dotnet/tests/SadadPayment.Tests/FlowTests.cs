// End-to-end over the real HTTP pipeline against the built-in sandbox bank.
using System.Net;
using System.Net.Http.Headers;
using System.Text;
using System.Text.RegularExpressions;
using Microsoft.AspNetCore.Mvc.Testing;
using Xunit;

public sealed class AppFixture : IDisposable
{
    public string DataDir { get; } = Directory.CreateTempSubdirectory("sadad-dotnet-").FullName;
    public WebApplicationFactory<Program> Factory { get; }

    public AppFixture()
    {
        Environment.SetEnvironmentVariable("SADAD_MODE", "sandbox");
        Environment.SetEnvironmentVariable("DATA_DIR", DataDir);
        Environment.SetEnvironmentVariable("ADMIN_PASSWORD", "pw");
        Environment.SetEnvironmentVariable("CURRENCY", "IRT");
        Environment.SetEnvironmentVariable("APP_URL", "http://localhost");
        Factory = new WebApplicationFactory<Program>();
    }

    public void Dispose() { Factory.Dispose(); Directory.Delete(DataDir, true); }
}

public class FlowTests(AppFixture app) : IClassFixture<AppFixture>
{
    private HttpClient Client() => app.Factory.CreateClient(new WebApplicationFactoryClientOptions { AllowAutoRedirect = false });

    private static FormUrlEncodedContent Form(params (string, string)[] f) => new(f.Select(x => new KeyValuePair<string, string>(x.Item1, x.Item2)));

    private static async Task<(string orderPath, string token)> StartOrder(HttpClient c, string amount)
    {
        var created = await c.PostAsync("/orders", Form(("amount", amount), ("name", "علی رضایی"), ("mobile", "09121234567")));
        Assert.Equal(HttpStatusCode.Redirect, created.StatusCode);
        var orderPath = created.Headers.Location!.OriginalString;
        var pay = await c.PostAsync($"{orderPath}/pay", Form());
        Assert.Equal(HttpStatusCode.Redirect, pay.StatusCode);
        var bankUrl = pay.Headers.Location!;
        Assert.Equal("/sandbox/VPG/Purchase", bankUrl.AbsolutePath);
        return (orderPath, System.Web.HttpUtility.ParseQueryString(bankUrl.Query)["Token"]!);
    }

    private static async Task<(string, string)[]> BankAction(HttpClient c, string token, string action)
    {
        var html = await (await c.PostAsync("/sandbox/VPG/Pay", Form(("Token", token), ("action", action)))).Content.ReadAsStringAsync();
        return Regex.Matches(html, "name=\"([^\"]+)\" value=\"([^\"]*)\"").Select(m => (m.Groups[1].Value, m.Groups[2].Value)).ToArray();
    }

    private static async Task<string> Text(HttpClient c, string path) => await (await c.GetAsync(path)).Content.ReadAsStringAsync();

    [Fact]
    public async Task Successful_payment_is_verified_once()
    {
        var c = Client();
        var (orderPath, token) = await StartOrder(c, "250000");
        Assert.Contains("۲٬۵۰۰٬۰۰۰ ریال", await Text(c, $"/sandbox/VPG/Purchase?Token={token}")); // Toman ×10
        var fields = await BankAction(c, token, "pay");
        var cb = await c.PostAsync("/payment/callback", Form(fields));
        Assert.Equal($"{orderPath}/result", cb.Headers.Location!.OriginalString);
        Assert.Contains("پرداخت با موفقیت انجام شد", await Text(c, $"{orderPath}/result"));
        await c.PostAsync("/payment/callback", Form(fields)); // replay
        Assert.Contains("پرداخت با موفقیت انجام شد", await Text(c, $"{orderPath}/result"));
    }

    [Fact]
    public async Task Cancel_then_retry()
    {
        var c = Client();
        var (orderPath, token) = await StartOrder(c, "100000");
        await c.PostAsync("/payment/callback", Form(await BankAction(c, token, "cancel")));
        var result = await Text(c, $"{orderPath}/result");
        Assert.Contains("پرداخت لغو شد", result);
        Assert.Contains("تلاش دوباره", result);
        var retry = await c.PostAsync($"{orderPath}/pay", Form());
        Assert.StartsWith("/sandbox/VPG/Purchase", retry.Headers.Location!.AbsolutePath);
    }

    [Fact]
    public async Task Forged_success_callback_fails_verify()
    {
        var c = Client();
        var (orderPath, token) = await StartOrder(c, "100000");
        var html = await Text(c, $"/sandbox/VPG/Purchase?Token={token}");
        var orderId = Regex.Match(html, "شماره سفارش</dt><dd>(\\d+)").Groups[1].Value;
        await c.PostAsync("/payment/callback", Form(("OrderId", orderId), ("token", token), ("ResCode", "0")));
        Assert.Contains("پرداخت ناموفق بود", await Text(c, $"{orderPath}/result"));
    }

    [Fact]
    public async Task Validation_and_admin_auth()
    {
        var c = Client();
        Assert.Equal((HttpStatusCode)422, (await c.PostAsync("/orders", Form(("amount", "50"), ("name", "x")))).StatusCode);
        Assert.Equal(HttpStatusCode.Unauthorized, (await c.GetAsync("/admin")).StatusCode);
        c.DefaultRequestHeaders.Authorization = new AuthenticationHeaderValue("Basic", Convert.ToBase64String(Encoding.UTF8.GetBytes("admin:pw")));
        var ok = await c.GetAsync("/admin");
        Assert.Equal(HttpStatusCode.OK, ok.StatusCode);
    }
}
