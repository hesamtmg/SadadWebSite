"""Orders in SQLite (stdlib): atomic updates across gunicorn workers, no server to install."""
from __future__ import annotations

import json
import secrets
import sqlite3
import time
from contextlib import contextmanager
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Iterable

SCHEMA = """
CREATE TABLE IF NOT EXISTS orders (
    id TEXT PRIMARY KEY,                -- public id used in URLs (not guessable)
    status TEXT NOT NULL,               -- created → pending → verifying → paid | failed | canceled
    amount INTEGER NOT NULL,
    amount_rial INTEGER NOT NULL,
    currency TEXT NOT NULL,
    name TEXT NOT NULL,
    mobile TEXT NOT NULL DEFAULT '',
    description TEXT NOT NULL DEFAULT '',
    bank_order_id TEXT UNIQUE,
    token TEXT,
    message TEXT NOT NULL DEFAULT '',
    system_trace_no TEXT,
    retrieval_ref_no TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    paid_at TEXT
);
CREATE INDEX IF NOT EXISTS orders_token ON orders(token);
CREATE TABLE IF NOT EXISTS events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id TEXT NOT NULL REFERENCES orders(id),
    at TEXT NOT NULL,
    type TEXT NOT NULL,
    data TEXT
);
CREATE TABLE IF NOT EXISTS sandbox_tokens (
    token TEXT PRIMARY KEY, order_id TEXT, amount INTEGER, return_url TEXT, status TEXT, trace TEXT, rrn TEXT
);
"""

ORDER_FIELDS = {"status", "bank_order_id", "token", "message", "system_trace_no", "retrieval_ref_no", "paid_at"}


def now() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="seconds")


class Store:
    def __init__(self, path: Path):
        self.path = str(path)
        # Several worker processes may start at once; retry while another one holds the lock.
        for attempt in range(50):
            conn = sqlite3.connect(self.path, timeout=10)
            try:
                conn.execute("PRAGMA journal_mode=WAL")  # readers don't block the writer
                conn.executescript(SCHEMA)
                break
            except sqlite3.OperationalError as e:
                if "locked" not in str(e) or attempt == 49:
                    raise
                time.sleep(0.1)
            finally:
                conn.close()

    @contextmanager
    def db(self):
        conn = sqlite3.connect(self.path, timeout=10, isolation_level=None)
        conn.row_factory = sqlite3.Row
        try:
            conn.execute("BEGIN IMMEDIATE")
            yield conn
            conn.execute("COMMIT")
        except BaseException:
            if conn.in_transaction:
                conn.execute("ROLLBACK")
            raise
        finally:
            conn.close()

    def create(self, amount: int, amount_rial: int, currency: str, name: str, mobile: str, description: str) -> dict:
        order_id = secrets.token_hex(8)
        ts = now()
        with self.db() as db:
            db.execute(
                "INSERT INTO orders (id, status, amount, amount_rial, currency, name, mobile, description, created_at, updated_at)"
                " VALUES (?, 'created', ?, ?, ?, ?, ?, ?, ?, ?)",
                (order_id, amount, amount_rial, currency, name, mobile, description, ts, ts),
            )
            self._event(db, order_id, "created", {"amount": amount, "amountRial": amount_rial, "currency": currency}, ts)
        return self.get(order_id)

    def get(self, order_id: str) -> dict | None:
        with self.db() as db:
            row = db.execute("SELECT * FROM orders WHERE id = ?", (order_id,)).fetchone()
            return dict(row) if row else None

    def find_by(self, column: str, value: str | None) -> dict | None:
        if column not in ("token", "bank_order_id") or not value:
            return None
        with self.db() as db:
            row = db.execute(f"SELECT * FROM orders WHERE {column} = ? ORDER BY created_at DESC LIMIT 1", (value,)).fetchone()
            return dict(row) if row else None

    def all(self) -> list[dict]:
        with self.db() as db:
            return [dict(r) for r in db.execute("SELECT * FROM orders ORDER BY created_at DESC, rowid DESC")]

    def events(self, order_id: str) -> list[dict]:
        with self.db() as db:
            return [{**dict(r), "data": json.loads(r["data"]) if r["data"] else None}
                    for r in db.execute("SELECT at, type, data FROM events WHERE order_id = ? ORDER BY id", (order_id,))]

    def next_bank_order_id(self) -> str:
        """Numeric OrderId for Sadad, unique per terminal; a new one for every payment attempt."""
        while True:
            candidate = f"{int(time.time() * 1000)}{secrets.randbelow(90) + 10}"
            if not self.find_by("bank_order_id", candidate):
                return candidate

    def update(self, order_id: str, changes: dict[str, Any], event: str | None = None, data: Any = None,
               only_if_status: Iterable[str] | None = None) -> bool:
        """Apply changes atomically. With only_if_status, nothing happens unless the current status is one of them."""
        bad = set(changes) - ORDER_FIELDS
        if bad:
            raise ValueError(f"cannot update {bad}")
        ts = now()
        sets = ", ".join(f"{k} = ?" for k in changes) + ", updated_at = ?"
        params: list[Any] = [*changes.values(), ts, order_id]
        where = "id = ?"
        if only_if_status is not None:
            statuses = list(only_if_status)
            where += f" AND status IN ({','.join('?' * len(statuses))})"
            params += statuses
        with self.db() as db:
            if db.execute(f"UPDATE orders SET {sets} WHERE {where}", params).rowcount == 0:
                return False
            if event:
                self._event(db, order_id, event, data, ts)
            return True

    @staticmethod
    def _event(db: sqlite3.Connection, order_id: str, type_: str, data: Any, ts: str) -> None:
        db.execute("INSERT INTO events (order_id, at, type, data) VALUES (?, ?, ?, ?)",
                   (order_id, ts, type_, json.dumps(data, ensure_ascii=False) if data is not None else None))
