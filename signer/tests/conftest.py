import os
import sys
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

os.environ.setdefault("SIGNER_MANUAL_APPROVAL", "false")
os.environ.setdefault("SIGNER_ALLOWED_IPS", "127.0.0.1")
os.environ.setdefault("INFURA_PROJECT_ID", "test")
os.environ.setdefault("BLOCKCYPHER_TOKEN", "test")

import samedepo_signer.config as config  # noqa: E402

_tmp_key = Path(os.environ.get("TEMP", "/tmp")) / "samedepo-signer-test-api-key"
config._API_KEY_FILE = _tmp_key
if not _tmp_key.exists():
    _tmp_key.write_text("test-api-key")


@pytest.fixture
def api_key() -> str:
    return "test-api-key"
