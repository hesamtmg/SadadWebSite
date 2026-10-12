// Settings from environment variables, optionally loaded from a .env file in the working directory.
using System.Security.Cryptography;

namespace SadadPayment;

public sealed class AppConfig
{
    public required int Port { get; init; }
    public required string AppUrl { get; init; }
    public required string Mode { get; init; }          // "sandbox" | "live"
    public required string Currency { get; init; }      // "IRT" (Toman, ×10 to Rial) | "IRR"
    public required string DataDir { get; init; }
    public required string AdminUser { get; init; }
    public required string AdminPassword { get; init; }
    public required SadadSettings Sadad { get; init; }
    public required SadadSettings Sandbox { get; init; }
    public bool IsSandbox => Mode == "sandbox";

    public static AppConfig Load(IDictionary<string, string?>? overrides = null)
    {
        LoadDotEnv(Path.Combine(Directory.GetCurrentDirectory(), ".env"));
        string Env(string name, string fallback = "") =>
            (overrides != null && overrides.TryGetValue(name, out var o) ? o : Environment.GetEnvironmentVariable(name))?.Trim() is { Length: > 0 } v ? v : fallback;

        var port = int.Parse(Env("PORT", "5080"));
        var mode = Env("SADAD_MODE", "sandbox").ToLowerInvariant();
        if (mode is not ("sandbox" or "live")) throw new InvalidOperationException($"SADAD_MODE must be \"sandbox\" or \"live\", got \"{mode}\"");
        var appUrl = Env("APP_URL", $"http://localhost:{port}").TrimEnd('/');

        // Sandbox: a random key per start, shared only with the built-in test bank. Never sent to Sadad.
        var sandbox = new SadadSettings("000000140000000", "24000000", Convert.ToBase64String(RandomNumberGenerator.GetBytes(24)),
            ApiBaseUrl: "http://sandbox.local", PurchaseBaseUrl: $"{appUrl}/sandbox", TimeoutMs: 10000);

        return new AppConfig
        {
            Port = port,
            AppUrl = appUrl,
            Mode = mode,
            Currency = Env("CURRENCY", "IRT").ToUpperInvariant() == "IRR" ? "IRR" : "IRT",
            DataDir = Path.GetFullPath(Env("DATA_DIR", "data")),
            AdminUser = Env("ADMIN_USER", "admin"),
            AdminPassword = Env("ADMIN_PASSWORD"),
            Sandbox = sandbox,
            Sadad = mode == "live"
                ? new SadadSettings(Env("SADAD_MERCHANT_ID"), Env("SADAD_TERMINAL_ID"), Env("SADAD_TERMINAL_KEY"),
                    Env("SADAD_API_URL", SadadClient.LiveUrl), Env("SADAD_PURCHASE_URL", SadadClient.LiveUrl), int.Parse(Env("SADAD_TIMEOUT_MS", "30000")))
                : sandbox,
        };
    }

    private static void LoadDotEnv(string file)
    {
        if (!File.Exists(file)) return;
        foreach (var raw in File.ReadAllLines(file))
        {
            var line = raw.Trim();
            if (line.Length == 0 || line.StartsWith('#')) continue;
            var eq = line.IndexOf('=');
            if (eq <= 0) continue;
            var key = line[..eq].Trim();
            var value = line[(eq + 1)..].Trim();
            if (value.Length >= 2 && (value[0] == '"' || value[0] == '\'') && value[^1] == value[0]) value = value[1..^1];
            if (Environment.GetEnvironmentVariable(key) is null) Environment.SetEnvironmentVariable(key, value); // real env vars win
        }
    }
}
