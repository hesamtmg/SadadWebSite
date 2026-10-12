"""Settings from environment variables, optionally loaded from a .env file in the project root."""
from __future__ import annotations

import base64
import os
import re
import secrets
import time
from dataclasses import dataclass
from pathlib import Path

from .sadad import SADAD_LIVE_URL

ROOT = Path(__file__).resolve().parent.parent


def _load_dotenv(file: Path) -> None:
    if not file.is_file():
        return
    for line in file.read_text(encoding="utf-8").splitlines():
        m = re.match(r"^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*?)\s*$", line)
        if not m or line.lstrip().startswith("#"):
            continue
        value = m.group(2)
        if len(value) >= 2 and value[0] in "'\"" and value[-1] == value[0]:
            value = value[1:-1]
        os.environ.setdefault(m.group(1), value)  # real env vars win


def _sandbox_key(file: Path) -> str:
    """Create the key exactly once even when several processes start together; everyone reads the winner's key."""
    try:
        fd = os.open(file, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
    except FileExistsError:
        pass
    else:
        with os.fdopen(fd, "w") as f:
            f.write(base64.b64encode(secrets.token_bytes(24)).decode())
    for _ in range(50):
        key = file.read_text().strip()
        if key:
            return key
        time.sleep(0.05)  # the creating process hasn't written it yet
    raise RuntimeError(f"{file} is empty")


@dataclass(frozen=True)
class SadadSettings:
    merchant_id: str
    terminal_id: str
    terminal_key: str
    api_base_url: str
    purchase_base_url: str
    timeout: float = 30


@dataclass(frozen=True)
class Config:
    app_url: str
    mode: str          # "sandbox" | "live"
    currency: str      # "IRT" (Toman, ×10 to Rial) | "IRR"
    data_dir: Path
    admin_user: str
    admin_password: str
    sadad: SadadSettings
    sandbox: SadadSettings

    @property
    def is_sandbox(self) -> bool:
        return self.mode == "sandbox"

    @classmethod
    def load(cls) -> "Config":
        _load_dotenv(ROOT / ".env")

        def env(name: str, fallback: str = "") -> str:
            return (os.environ.get(name) or "").strip() or fallback

        mode = env("SADAD_MODE", "sandbox").lower()
        if mode not in ("sandbox", "live"):
            raise RuntimeError(f'SADAD_MODE must be "sandbox" or "live", got "{mode}"')
        app_url = env("APP_URL", f"http://localhost:{env('PORT', '8000')}").rstrip("/")
        data_dir = Path(env("DATA_DIR", str(ROOT / "data")))
        if not data_dir.is_absolute():
            data_dir = ROOT / data_dir
        data_dir.mkdir(parents=True, exist_ok=True)

        # Sandbox: a random key generated once and kept in data/ (shared by all worker processes),
        # used only with the built-in test bank. Never sent to Sadad.
        sandbox = SadadSettings("000000140000000", "24000000", _sandbox_key(data_dir / "sandbox-key"),
                                "http://sandbox.local", f"{app_url}/sandbox", 10)

        sadad = (
            SadadSettings(env("SADAD_MERCHANT_ID"), env("SADAD_TERMINAL_ID"), env("SADAD_TERMINAL_KEY"),
                          env("SADAD_API_URL", SADAD_LIVE_URL), env("SADAD_PURCHASE_URL", SADAD_LIVE_URL),
                          float(env("SADAD_TIMEOUT", "30")))
            if mode == "live" else sandbox
        )
        return cls(app_url, mode, "IRR" if env("CURRENCY", "IRT").upper() == "IRR" else "IRT", data_dir,
                   env("ADMIN_USER", "admin"), env("ADMIN_PASSWORD"), sadad, sandbox)
