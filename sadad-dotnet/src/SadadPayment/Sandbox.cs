// Built-in Sadad sandbox (SADAD_MODE=sandbox): same endpoints and JSON as sadad.shaparak.ir.
// SignData is really checked with the sandbox key, so signing bugs fail here too. Accepts any card.
using System.Collections.Concurrent;
using System.Net;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json.Nodes;

namespace SadadPayment;

public sealed class SandboxBank(SadadSettings creds)
{
    private sealed class Tx
    {
        public required string OrderId { get; init; }
        public required long Amount { get; init; }
        public required string ReturnUrl { get; init; }
        public string Status { get; set; } = "created";
        public string Trace { get; set; } = "";
        public string Rrn { get; set; } = "";
    }

    private readonly ConcurrentDictionary<string, Tx> _tokens = new();
    public SadadSettings Credentials => creds;

    private bool SignOk(JsonNode? sign, string text)
    {
        var expected = Encoding.UTF8.GetBytes(SadadSign.SignData(text, creds.TerminalKey));
        var given = Encoding.UTF8.GetBytes(sign?.GetValue<string>() ?? "");
        return CryptographicOperations.FixedTimeEquals(expected, given);
    }

    /// <summary>The bank's JSON API: PaymentRequest and Verify.</summary>
    public JsonObject HandleApi(string path, JsonObject r)
    {
        if (path.EndsWith("/vpg/api/v0/Request/PaymentRequest", StringComparison.OrdinalIgnoreCase))
        {
            Console.WriteLine($"[sandbox] PaymentRequest OrderId={r["OrderId"]} Amount={r["Amount"]}");
            if (r["TerminalId"]?.ToString() != creds.TerminalId || r["MerchantId"]?.ToString() != creds.MerchantId)
                return new JsonObject { ["ResCode"] = "3", ["Token"] = null, ["Description"] = "پذیرنده کارت فعال نیست." };
            if (!SignOk(r["SignData"], $"{r["TerminalId"]};{r["OrderId"]};{r["Amount"]}"))
                return new JsonObject { ["ResCode"] = "1025", ["Token"] = null, ["Description"] = "اطلاعات SignData ارسالی اشتباه است" };
            if (r["Amount"] is not JsonValue av || !av.TryGetValue<long>(out var amount) || amount < 1000)
                return new JsonObject { ["ResCode"] = "-1", ["Token"] = null, ["Description"] = "مبلغ تراکنش نامعتبر است." };
            var token = Convert.ToHexString(RandomNumberGenerator.GetBytes(12));
            _tokens[token] = new Tx { OrderId = r["OrderId"]!.ToString(), Amount = amount, ReturnUrl = r["ReturnUrl"]?.ToString() ?? "" };
            return new JsonObject { ["ResCode"] = "0", ["Token"] = token, ["Description"] = "عملیات با موفقیت انجام شد" };
        }

        if (path.EndsWith("/vpg/api/v0/Advice/Verify", StringComparison.OrdinalIgnoreCase))
        {
            var token = r["Token"]?.ToString() ?? "";
            Console.WriteLine($"[sandbox] Verify Token={token}");
            if (!SignOk(r["SignData"], token))
                return new JsonObject { ["ResCode"] = "-1", ["Description"] = "اطلاعات SignData ارسالی اشتباه است" };
            if (!_tokens.TryGetValue(token, out var tx) || tx.Status is not ("paid" or "verified"))
                return new JsonObject { ["ResCode"] = "-1", ["Description"] = "تراکنش یافت نشد یا پرداخت نشده است." };
            tx.Status = "verified";
            return new JsonObject
            {
                ["ResCode"] = "0", ["Amount"] = tx.Amount, ["Description"] = "عملیات با موفقیت انجام شد",
                ["RetrivalRefNo"] = tx.Rrn, ["SystemTraceNo"] = tx.Trace, ["OrderId"] = tx.OrderId,
            };
        }

        return new JsonObject { ["ResCode"] = "-1", ["Description"] = "Unknown sandbox endpoint" };
    }

    /// <summary>GET /sandbox/VPG/Purchase?Token=… — the card page the customer sees.</summary>
    public IResult PurchasePage(string token) =>
        _tokens.TryGetValue(token, out var tx) && tx.Status == "created"
            ? Results.Content(Views.SandboxBankPage(token, tx.OrderId, tx.Amount, creds.TerminalId), "text/html; charset=utf-8")
            : Results.Text("توکن نامعتبر یا منقضی است.", statusCode: 400);

    /// <summary>POST /sandbox/VPG/Pay — like the real bank, auto-POSTs OrderId, token and ResCode back to ReturnUrl.</summary>
    public IResult Pay(string token, string action)
    {
        if (!_tokens.TryGetValue(token, out var tx) || tx.Status != "created")
            return Results.Text("توکن نامعتبر یا منقضی است.", statusCode: 400);
        var paid = action == "pay";
        tx.Status = paid ? "paid" : "canceled";
        if (paid)
        {
            tx.Trace = RandomNumberGenerator.GetInt32(100000, 999999).ToString();
            tx.Rrn = RandomNumberGenerator.GetInt32(100000, 999999).ToString() + RandomNumberGenerator.GetInt32(100000, 999999);
        }
        return Results.Content(Views.AutoPostPage(tx.ReturnUrl, new() { ["OrderId"] = tx.OrderId, ["token"] = token, ["ResCode"] = paid ? "0" : "-1" }), "text/html; charset=utf-8");
    }
}

/// <summary>Routes SadadClient's HTTP calls to the in-process sandbox bank (no network, no self-requests).</summary>
public sealed class SandboxHandler(SandboxBank bank) : HttpMessageHandler
{
    protected override async Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken ct)
    {
        var body = request.Content is null ? "{}" : await request.Content.ReadAsStringAsync(ct);
        var reply = bank.HandleApi(request.RequestUri!.AbsolutePath, JsonNode.Parse(body) as JsonObject ?? []);
        return new HttpResponseMessage(HttpStatusCode.OK) { Content = new StringContent(reply.ToJsonString(), Encoding.UTF8, "application/json") };
    }
}
