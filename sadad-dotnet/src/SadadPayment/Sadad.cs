// Sadad (Bank Melli) VPG client: PaymentRequest, purchase redirect and Verify.
using System.Globalization;
using System.Net.Http.Json;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Text.Json.Nodes;

namespace SadadPayment;

public sealed class SadadException(string message, string? resCode = null, JsonNode? response = null) : Exception(message)
{
    public string? ResCode { get; } = resCode;
    public JsonNode? Response { get; } = response;
}

public static class SadadSign
{
    /// <summary>Decode the Base64 TerminalKey; Sadad keys are exactly 24 bytes (TripleDES).</summary>
    public static byte[] DecodeKey(string terminalKeyBase64)
    {
        var clean = string.Concat((terminalKeyBase64 ?? "").Where(c => !char.IsWhiteSpace(c)));
        byte[] key;
        try { key = Convert.FromBase64String(clean); }
        catch (FormatException) { throw new SadadException("TerminalKey is not valid Base64."); }
        if (clean.Length == 0) throw new SadadException("TerminalKey is not valid Base64.");
        if (key.Length != 24) throw new SadadException($"TerminalKey must decode to 24 bytes, got {key.Length}.");
        return key;
    }

    /// <summary>SignData = Base64(TripleDES-ECB-PKCS7(UTF8(text), key)) — Sadad's MakeSign.</summary>
    public static string SignData(string text, string terminalKeyBase64)
    {
        using var tdes = TripleDES.Create();
        tdes.Mode = CipherMode.ECB;
        tdes.Padding = PaddingMode.PKCS7;
        tdes.Key = DecodeKey(terminalKeyBase64);
        var bytes = Encoding.UTF8.GetBytes(text);
        using var encryptor = tdes.CreateEncryptor();
        return Convert.ToBase64String(encryptor.TransformFinalBlock(bytes, 0, bytes.Length));
    }

    private static readonly TimeZoneInfo Tehran = FindTehran();

    private static TimeZoneInfo FindTehran()
    {
        try { return TimeZoneInfo.FindSystemTimeZoneById("Asia/Tehran"); }
        catch (Exception) { return TimeZoneInfo.CreateCustomTimeZone("Tehran", TimeSpan.FromMinutes(210), "Tehran", "Tehran"); } // no DST since 2022
    }

    /// <summary>"MM/dd/yyyy hh:mm:ss AM" in Tehran time, as Sadad expects for LocalDateTime.</summary>
    public static string LocalDateTime(DateTimeOffset? at = null) =>
        TimeZoneInfo.ConvertTime(at ?? DateTimeOffset.UtcNow, Tehran).ToString("MM/dd/yyyy hh:mm:ss tt", CultureInfo.InvariantCulture);

    /// <summary>Short, non-reversible fingerprint so logs can show which key is used without revealing it.</summary>
    public static string KeyFingerprint(string terminalKeyBase64) =>
        Convert.ToHexString(SHA256.HashData(DecodeKey(terminalKeyBase64)))[..12].ToLowerInvariant();

    public static string Sha256Hex(string s) => Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes(s))).ToLowerInvariant();
}

public sealed record SadadSettings(string MerchantId, string TerminalId, string TerminalKey, string ApiBaseUrl, string PurchaseBaseUrl, int TimeoutMs = 30000);

public sealed record PaymentRequestResult(string Token, string PaymentUrl, Dictionary<string, string> Debug);

public sealed record VerifyResult(long? Amount, string RetrievalRefNo, string SystemTraceNo, string OrderId, string Description, JsonNode Response);

public sealed class SadadClient
{
    public const string LiveUrl = "https://sadad.shaparak.ir";
    private readonly HttpClient _http;
    public SadadSettings Settings { get; }

    /// <param name="handler">Optional transport; the sandbox passes an in-process handler.</param>
    public SadadClient(SadadSettings settings, HttpMessageHandler? handler = null)
    {
        if (!settings.MerchantId.All(char.IsAsciiDigit) || settings.MerchantId.Length == 0) throw new SadadException("MerchantId must contain digits only.");
        if (!settings.TerminalId.All(char.IsAsciiDigit) || settings.TerminalId.Length == 0) throw new SadadException("TerminalId must contain digits only.");
        SadadSign.DecodeKey(settings.TerminalKey); // fail fast on a malformed key
        Settings = settings with { ApiBaseUrl = settings.ApiBaseUrl.TrimEnd('/'), PurchaseBaseUrl = settings.PurchaseBaseUrl.TrimEnd('/') };
        _http = handler is null ? new HttpClient() : new HttpClient(handler);
        _http.Timeout = TimeSpan.FromMilliseconds(settings.TimeoutMs);
    }

    public string TerminalId => Settings.TerminalId;
    public string KeyFingerprint => SadadSign.KeyFingerprint(Settings.TerminalKey);

    /// <summary>Step 1: ask Sadad for a payment token. Amount is in Rial.</summary>
    public async Task<PaymentRequestResult> PaymentRequestAsync(string orderId, long amount, string returnUrl)
    {
        if (amount <= 0) throw new SadadException("Amount must be a positive integer (Rial).");
        var signSource = $"{Settings.TerminalId};{orderId};{amount}";
        var payload = new JsonObject
        {
            ["TerminalId"] = Settings.TerminalId,
            ["MerchantId"] = Settings.MerchantId,
            ["Amount"] = amount,
            ["SignData"] = SadadSign.SignData(signSource, Settings.TerminalKey),
            ["ReturnUrl"] = returnUrl,
            ["LocalDateTime"] = SadadSign.LocalDateTime(),
            ["OrderId"] = orderId,
        };
        var debug = new Dictionary<string, string>
        {
            ["signSource"] = signSource,
            ["signSha256"] = SadadSign.Sha256Hex(payload["SignData"]!.GetValue<string>()),
            ["keyFingerprint"] = KeyFingerprint,
            ["localDateTime"] = payload["LocalDateTime"]!.GetValue<string>(),
        };
        var response = await PostAsync("/vpg/api/v0/Request/PaymentRequest", payload);
        var resCode = Str(response, "ResCode");
        var token = Str(response, "Token");
        if (resCode != "0" || token.Length == 0)
        {
            response["debug"] = JsonSerializer.SerializeToNode(debug);
            throw new SadadException(Describe(resCode, Str(response, "Description")), resCode, response);
        }
        return new PaymentRequestResult(token, PaymentUrl(token), debug);
    }

    /// <summary>Step 2: the URL the customer's browser must open.</summary>
    public string PaymentUrl(string token) => $"{Settings.PurchaseBaseUrl}/VPG/Purchase?Token={Uri.EscapeDataString(token)}";

    /// <summary>Step 3: confirm the payment after the customer returns. ResCode "0" means settled.</summary>
    public async Task<VerifyResult> VerifyAsync(string token)
    {
        var response = await PostAsync("/vpg/api/v0/Advice/Verify", new JsonObject
        {
            ["Token"] = token,
            ["SignData"] = SadadSign.SignData(token, Settings.TerminalKey),
        });
        var resCode = Str(response, "ResCode");
        if (resCode != "0") throw new SadadException(Describe(resCode, Str(response, "Description")), resCode, response);
        long? amount = long.TryParse(Str(response, "Amount"), out var a) ? a : null;
        return new VerifyResult(amount, Str(response, "RetrivalRefNo"), Str(response, "SystemTraceNo"), Str(response, "OrderId"), Str(response, "Description"), response);
    }

    private async Task<JsonObject> PostAsync(string path, JsonObject payload)
    {
        HttpResponseMessage res;
        try
        {
            res = await _http.PostAsJsonAsync(Settings.ApiBaseUrl + path, payload);
        }
        catch (Exception ex) when (ex is HttpRequestException or TaskCanceledException)
        {
            throw new SadadException($"ارتباط با سداد برقرار نشد: {ex.Message}");
        }
        var text = await res.Content.ReadAsStringAsync();
        JsonObject? json = null;
        try { json = JsonNode.Parse(text) as JsonObject; } catch (JsonException) { }
        if (!res.IsSuccessStatusCode || json is null)
            throw new SadadException($"پاسخ نامعتبر از سداد دریافت شد [HTTP {(int)res.StatusCode}]");
        return json;
    }

    /// <summary>Sadad returns some fields as numbers and some as strings; read either as text.</summary>
    private static string Str(JsonObject o, string name) => o[name] switch
    {
        null => "",
        JsonValue v when v.TryGetValue<string>(out var s) => s,
        JsonNode n => n.ToJsonString(),
    };

    private string Describe(string resCode, string description)
    {
        var msg = $"{(description.Length > 0 ? description : "خطای نامشخص از سداد")} [ResCode: {(resCode.Length > 0 ? resCode : "no-code")}]";
        if (resCode == "1025")
            msg += $" — TerminalKey با TerminalId {Settings.TerminalId} همخوانی ندارد؛ کلید را دقیقاً مطابق اطلاعات سداد وارد کنید.";
        return msg;
    }
}
