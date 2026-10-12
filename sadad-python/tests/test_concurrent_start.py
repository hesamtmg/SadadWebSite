"""Several worker processes starting at once must share one sandbox key and not trip over the DB schema."""
import subprocess
import sys
import time
from pathlib import Path

CODE = """
import os, sys, time
from app.config import Config
from app.store import Store
while time.time() < float(os.environ["START_AT"]):  # line all processes up to collide
    pass
c = Config.load()
Store(c.data_dir / "sadad.db")
print(c.sandbox.terminal_key)
"""


def test_parallel_start_shares_sandbox_key(tmp_path):
    env = {"SADAD_MODE": "sandbox", "DATA_DIR": str(tmp_path), "PATH": "", "PYTHONPATH": str(Path(__file__).parent.parent),
           "START_AT": str(time.time() + 2)}
    procs = [subprocess.Popen([sys.executable, "-c", CODE], env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
             for _ in range(12)]
    results = [p.communicate(timeout=60) for p in procs]
    assert all(p.returncode == 0 for p in procs), [err for _, err in results]
    keys = {out.strip() for out, _ in results}
    assert len(keys) == 1, keys
