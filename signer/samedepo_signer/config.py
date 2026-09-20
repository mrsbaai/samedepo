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


class _Coins:
    """Lazy bip-utils coin constants so config imports stay light in tests."""
    _cache = {}

    @classmethod
    def bip84(cls, name: str):
        if name not in cls._cache:
            from bip_utils import Bip84Coins
            cls._cache[name] = getattr(Bip84Coins, name)
        return cls._cache[name]

    @classmethod
    def bip44(cls, name: str):
        if name not in cls._cache:
            from bip_utils import Bip44Coins
            cls._cache[name] = getattr(Bip44Coins, name)
        return cls._cache[name]


def _infura_rpc() -> str:
    return f"https://{Config.infura_network}.infura.io/v3/{Config.infura_project_id}"


EVM_SEED_LABEL = "ETHEREUM / USDT ERC20"

NETWORKS: dict[str, dict] = {
    "bitcoin": {
        "family": "utxo", "kind": "native", "chain": "bitcoin", "address_group": "bitcoin",
        "seed_label": "BITCOIN", "coin": ("bip84", "BITCOIN"),
        "blockcypher_coin": "btc", "esplora_url": "https://mempool.space/api",
        "explorer": "https://mempool.space/tx/{hash}", "native_symbol": "BTC",
    },
    "litecoin": {
        "family": "utxo", "kind": "native", "chain": "litecoin", "address_group": "litecoin",
        "seed_label": "LITECOIN", "coin": ("bip84", "LITECOIN"),
        "blockcypher_coin": "ltc", "esplora_url": "https://litecoinspace.org/api",
        "explorer": "https://litecoinspace.org/tx/{hash}", "native_symbol": "LTC",
    },
    "ethereum": {
        "family": "evm", "kind": "native", "chain": "ethereum", "address_group": "evm",
        "seed_label": EVM_SEED_LABEL, "coin": ("bip44", "ETHEREUM"),
        "rpc": _infura_rpc, "chain_id": 1,
        "explorer": "https://etherscan.io/tx/{hash}", "native_symbol": "ETH",
    },
    "usdt_erc20": {
        "family": "evm", "kind": "token", "chain": "ethereum", "address_group": "evm",
        "seed_label": EVM_SEED_LABEL, "coin": ("bip44", "ETHEREUM"),
        "rpc": _infura_rpc, "chain_id": 1,
        "contract": Config.infura_usdt_contract, "decimals": 6,
        "explorer": "https://etherscan.io/tx/{hash}", "native_symbol": "ETH",
    },
    "usdc_erc20": {
        "family": "evm", "kind": "token", "chain": "ethereum", "address_group": "evm",
        "seed_label": EVM_SEED_LABEL, "coin": ("bip44", "ETHEREUM"),
        "rpc": _infura_rpc, "chain_id": 1,
        "contract": os.environ.get("INFURA_USDC_CONTRACT", "0xA0b86991c6218b36c1d19D4a2e9Eb0cE3606eB48"),
        "decimals": 6,
        "explorer": "https://etherscan.io/tx/{hash}", "native_symbol": "ETH",
    },
    "usdt_trc20": {
        "family": "tron", "kind": "token", "chain": "tron", "address_group": "tron",
        "seed_label": "TRON / USDT TRC20", "coin": ("bip44", "TRON"),
        "contract": Config.trongrid_usdt_contract, "decimals": 6,
        "explorer": "https://tronscan.org/#/transaction/{hash}", "native_symbol": "TRX",
    },
    "usdt_bep20": {
        "family": "evm", "kind": "token", "chain": "bsc", "address_group": "evm",
        "seed_label": EVM_SEED_LABEL, "coin": ("bip44", "ETHEREUM"),
        "rpc": lambda: os.environ.get("BSC_RPC_URL", ""), "chain_id": 56,
        "contract": os.environ.get("BSC_USDT_CONTRACT", "0x55d398326f99059fF775485246999027B3197955"),
        "decimals": 18,
        "explorer": "https://bscscan.com/tx/{hash}", "native_symbol": "BNB",
    },
    "usdc_bep20": {
        "family": "evm", "kind": "token", "chain": "bsc", "address_group": "evm",
        "seed_label": EVM_SEED_LABEL, "coin": ("bip44", "ETHEREUM"),
        "rpc": lambda: os.environ.get("BSC_RPC_URL", ""), "chain_id": 56,
        "contract": os.environ.get("BSC_USDC_CONTRACT", "0x8AC76a51cc950d9822D68b83fE1Ad97B32Cd580d"),
        "decimals": 18,
        "explorer": "https://bscscan.com/tx/{hash}", "native_symbol": "BNB",
    },
    "bnb": {
        "family": "evm", "kind": "native", "chain": "bsc", "address_group": "evm",
        "seed_label": EVM_SEED_LABEL, "coin": ("bip44", "ETHEREUM"),
        "rpc": lambda: os.environ.get("BSC_RPC_URL", ""), "chain_id": 56,
        "explorer": "https://bscscan.com/tx/{hash}", "native_symbol": "BNB",
    },
}


def network(key: str) -> dict:
    try:
        return NETWORKS[key]
    except KeyError:
        raise RuntimeError(f"Unknown network: {key}")


def family(key: str) -> str:
    return network(key)["family"]


def is_token(key: str) -> bool:
    return network(key)["kind"] == "token"


def is_native(key: str) -> bool:
    return network(key)["kind"] == "native"


def chain(key: str) -> str:
    return network(key)["chain"]


def native_symbol(key: str) -> str:
    return network(key)["native_symbol"]


def coin(key: str):
    scheme, name = network(key)["coin"]
    return _Coins.bip84(name) if scheme == "bip84" else _Coins.bip44(name)


def rpc_url(key: str) -> str:
    return network(key)["rpc"]()


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
