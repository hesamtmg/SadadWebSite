import pytest

from app import create_app
from app.config import Config


@pytest.fixture()
def client(tmp_path, monkeypatch):
    for k, v in {"SADAD_MODE": "sandbox", "DATA_DIR": str(tmp_path), "ADMIN_PASSWORD": "pw", "CURRENCY": "IRT", "APP_URL": "http://localhost"}.items():
        monkeypatch.setenv(k, v)
    return create_app(Config.load()).test_client()
