<?php
// JSON-file storage with an exclusive lock around each read-modify-write, safe across Apache/FPM processes.
// Fine for a demo or low volume; use a database in production.
declare(strict_types=1);

namespace Sadad;

final class JsonFile
{
    public function __construct(private readonly string $file)
    {
        if (!is_dir(dirname($file))) mkdir(dirname($file), 0775, true);
    }

    /** Run $fn(&$data) under an exclusive lock; the (possibly modified) data is written back. Returns $fn's result. */
    public function transaction(callable $fn): mixed
    {
        $fh = fopen($this->file, 'c+');
        if ($fh === false) throw new \RuntimeException("Cannot open {$this->file}");
        try {
            flock($fh, LOCK_EX);
            $raw = stream_get_contents($fh);
            $data = $raw ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : [];
            $before = $data;
            $result = $fn($data);
            if ($data !== $before) {
                ftruncate($fh, 0);
                rewind($fh);
                fwrite($fh, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                fflush($fh);
            }
            return $result;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    public function read(): array
    {
        return $this->transaction(fn (array &$data) => $data);
    }
}

final class OrderStore
{
    private JsonFile $file;

    public function __construct(string $dir)
    {
        $this->file = new JsonFile($dir . '/orders.json');
    }

    public function create(array $fields): array
    {
        $now = gmdate('c');
        $order = $fields + [
            'id' => bin2hex(random_bytes(8)), // public id used in URLs (not guessable)
            'status' => 'created',            // created → pending → verifying → paid | failed | canceled
            'bankOrderId' => null, 'token' => null, 'message' => '',
            'systemTraceNo' => null, 'retrievalRefNo' => null, 'paidAt' => null,
            'createdAt' => $now, 'updatedAt' => $now,
            'events' => [['at' => $now, 'type' => 'created', 'data' => ['amount' => $fields['amount'], 'amountRial' => $fields['amountRial'], 'currency' => $fields['currency']]]],
        ];
        $this->file->transaction(function (array &$orders) use ($order) { $orders[$order['id']] = $order; });
        return $order;
    }

    public function get(string $id): ?array
    {
        return $this->file->read()[$id] ?? null;
    }

    public function findBy(string $field, ?string $value): ?array
    {
        if ($value === null || $value === '') return null;
        foreach ($this->file->read() as $o) {
            if (($o[$field] ?? null) === $value) return $o;
        }
        return null;
    }

    /** @return array<int, array> newest first */
    public function all(): array
    {
        $orders = array_values($this->file->read());
        usort($orders, fn ($a, $b) => strcmp($b['createdAt'], $a['createdAt']));
        return $orders;
    }

    /** Numeric OrderId for Sadad, unique per terminal; a new one for every payment attempt. */
    public function nextBankOrderId(): string
    {
        do {
            $id = (string) (int) floor(microtime(true) * 1000) . random_int(10, 99);
        } while ($this->findBy('bankOrderId', $id));
        return $id;
    }

    /**
     * Atomically apply $change(&$order) and log an event. If $change returns false nothing is saved.
     * Returns the updated order, or null when the order is missing or the change was refused.
     */
    public function update(string $id, callable $change, ?string $eventType = null, mixed $data = null): ?array
    {
        return $this->file->transaction(function (array &$orders) use ($id, $change, $eventType, $data) {
            if (!isset($orders[$id])) return null;
            $order = $orders[$id];
            if ($change($order) === false) return null;
            $order['updatedAt'] = gmdate('c');
            if ($eventType !== null) $order['events'][] = ['at' => $order['updatedAt'], 'type' => $eventType, 'data' => $data];
            $orders[$id] = $order;
            return $order;
        });
    }
}
