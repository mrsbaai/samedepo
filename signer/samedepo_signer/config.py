"""Signer configuration. Secrets are loaded from /etc, never from the repository."""
from __future__ import annotations

import os
import secrets
from pathlib import Path

_ENV_FILE = Path("/etc/samedepo-signer/signer.env")
_WALLET_KEY_FILE = Path("/root/.samedepo-wallet.key")
_WALLET_ENC_FILE = Path("/var/lib/samedepo-signer/wallets.enc")
_API_KEY_FILE = Path("/etc/samedepo-signer/api-key")


def _load_env() -> None:
    if _ENV_FILE.exists():
        for line in _ENV_FILE.read_text().splitlines():
            line = line.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            key, value = line.split("=", 1)
            os.environ.setdefault(key, value)


_load_env()


class Config:
    listen_port = int(os.environ.get("SIGNER_LISTEN_PORT", "8443"))
    allowed_ips = [ip.strip() for ip in os.environ.get("SIGNER_ALLOWED_IPS", "").split(",") if ip.strip()]
    infura_project_id = os.environ.get("INFURA_PROJECT_ID", "")
    infura_project_secret = os.environ.get("INFURA_PROJECT_SECRET", "")
    infura_network = os.environ.get("INFURA_NETWORK", "mainnet")
    blockcypher_token = os.environ.get("BLOCKCYPHER_TOKEN", "")
    blockcypher_network = os.environ.get("BLOCKCYPHER_NETWORK", "main")
    trongrid_api_key = os.environ.get("TRONGRID_API_KEY", "")
    trongrid_usdt_contract = os.environ.get("TRONGRID_USDT_CONTRACT", "TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t")
    infura_usdt_contract = os.environ.get("INFURA_USDT_CONTRACT", "0xdAC17F958D2ee523a2206206994597C13D831ec7")
    manual_approval = os.environ.get("SIGNER_MANUAL_APPROVAL", "true").lower() in ("1", "true", "yes")
    daily_limit = float(os.environ.get("SIGNER_DAILY_LIMIT", "1000"))
    per_tx_limit = float(os.environ.get("SIGNER_PER_TX_LIMIT", "500"))


def wallet_key_path() -> Path:
    return _WALLET_KEY_FILE


def wallet_enc_path() -> Path:
    return _WALLET_ENC_FILE


def api_key_path() -> Path:
    return _API_KEY_FILE


def ensure_api_key() -> str:
    if _API_KEY_FILE.exists():
        return _API_KEY_FILE.read_text().strip()
    key = secrets.token_urlsafe(32)
    _API_KEY_FILE.write_text(key)
    _API_KEY_FILE.chmod(0o600)
    return key
