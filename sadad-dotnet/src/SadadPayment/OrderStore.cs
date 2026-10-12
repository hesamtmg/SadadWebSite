// Tiny JSON-file order store. Fine for a demo or low volume; use a database in production.
using System.Security.Cryptography;
using System.Text.Json;
using System.Text.Json.Nodes;

namespace SadadPayment;

public sealed class OrderEvent
{
    public required DateTimeOffset At { get; init; }
    public required string Type { get; init; }
    public JsonNode? Data { get; init; }
}

public sealed class Order
{
    public required string Id { get; init; }                 // public id used in URLs (not guessable)
    public string Status { get; set; } = "created";          // created → pending → verifying → paid | failed | canceled
    public long Amount { get; init; }
    public long AmountRial { get; init; }
    public string Currency { get; init; } = "IRT";
    public string Name { get; init; } = "";
    public string Mobile { get; init; } = "";
    public string Description { get; init; } = "";
    public string? BankOrderId { get; set; }
    public string? Token { get; set; }
    public string Message { get; set; } = "";
    public string? SystemTraceNo { get; set; }
    public string? RetrievalRefNo { get; set; }
    public DateTimeOffset CreatedAt { get; init; } = DateTimeOffset.UtcNow;
    public DateTimeOffset UpdatedAt { get; set; } = DateTimeOffset.UtcNow;
    public DateTimeOffset? PaidAt { get; set; }
    public List<OrderEvent> Events { get; init; } = [];
}

public sealed class OrderStore
{
    private static readonly JsonSerializerOptions Json = new() { WriteIndented = true, PropertyNamingPolicy = JsonNamingPolicy.CamelCase };
    private readonly string _file;
    private readonly List<Order> _orders;
    private readonly object _lock = new();

    public OrderStore(string dir)
    {
        Directory.CreateDirectory(dir);
        _file = Path.Combine(dir, "orders.json");
        _orders = File.Exists(_file) ? JsonSerializer.Deserialize<List<Order>>(File.ReadAllText(_file), Json) ?? [] : [];
    }

    private void Save()
    {
        var tmp = _file + ".tmp";
        File.WriteAllText(tmp, JsonSerializer.Serialize(_orders, Json));
        File.Move(tmp, _file, overwrite: true);
    }

    public Order Create(long amount, long amountRial, string currency, string name, string mobile, string description)
    {
        lock (_lock)
        {
            var order = new Order
            {
                Id = Convert.ToHexString(RandomNumberGenerator.GetBytes(8)).ToLowerInvariant(),
                Amount = amount, AmountRial = amountRial, Currency = currency, Name = name, Mobile = mobile, Description = description,
            };
            order.Events.Add(new OrderEvent { At = order.CreatedAt, Type = "created", Data = new JsonObject { ["amount"] = amount, ["amountRial"] = amountRial, ["currency"] = currency } });
            _orders.Add(order);
            Save();
            return order;
        }
    }

    public Order? Get(string id) { lock (_lock) return _orders.FirstOrDefault(o => o.Id == id); }
    public Order? FindByToken(string? token) { lock (_lock) return string.IsNullOrEmpty(token) ? null : _orders.FirstOrDefault(o => o.Token == token); }
    public Order? FindByBankOrderId(string? id) { lock (_lock) return string.IsNullOrEmpty(id) ? null : _orders.FirstOrDefault(o => o.BankOrderId == id); }
    public List<Order> List() { lock (_lock) return [.. _orders.OrderByDescending(o => o.CreatedAt)]; }

    /// <summary>Numeric OrderId for Sadad, unique per terminal; a new one for every payment attempt.</summary>
    public string NextBankOrderId()
    {
        lock (_lock)
        {
            string id;
            do id = $"{DateTimeOffset.UtcNow.ToUnixTimeMilliseconds()}{RandomNumberGenerator.GetInt32(10, 100)}";
            while (_orders.Any(o => o.BankOrderId == id));
            return id;
        }
    }

    /// <summary>Apply changes and append an event atomically. The callback returns false to abort (state unchanged).</summary>
    public bool Update(string id, Func<Order, bool> change, string? eventType = null, JsonNode? data = null)
    {
        lock (_lock)
        {
            var order = _orders.FirstOrDefault(o => o.Id == id);
            if (order is null || !change(order)) return false;
            order.UpdatedAt = DateTimeOffset.UtcNow;
            if (eventType != null) order.Events.Add(new OrderEvent { At = order.UpdatedAt, Type = eventType, Data = data });
            Save();
            return true;
        }
    }
}
