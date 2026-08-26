"""Fee estimation from public blockchain providers."""
from __future__ import annotations

import json
import math
from decimal import Decimal
from typing import Optional

import requests
from web3 import Web3

from samedepo_signer.config import Config


def _btc() -> Optional[str]:
    if not Config.blockcypher_token:
        return None
    url = f"https://api.blockcypher.com/v1/btc/{Config.blockcypher_network}?token={Config.blockcypher_token}"
    try:
        r = requests.get(url, timeout=15)
        r.raise_for_status()
        data = r.json()
        sat_per_kb = data.get("low_fee_per_kb", data.get("half_hour_fee", 0))
        if not sat_per_kb:
            return None
        # Assume a 140-byte P2WPKH transaction.
        fee_btc = Decimal(sat_per_kb) * Decimal(140) / Decimal(1000) / Decimal(10 ** 8)
        return f"{fee_btc:.8f}"
    except Exception:
        return None


def _eth() -> Optional[str]:
    if not Config.infura_project_id:
        return None
    auth = (Config.infura_project_id, Config.infura_project_secret) if Config.infura_project_secret else None
    url = f"https://{Config.infura_network}.infura.io/v3/{Config.infura_project_id}"
    payload = {
        "jsonrpc": "2.0",
        "method": "eth_gasPrice",
        "params": [],
        "id": 1,
    }
    try:
        r = requests.post(url, json=payload, auth=auth, timeout=15)
        r.raise_for_status()
        wei = int(r.json()["result"], 16)
        eth = Decimal(wei) * Decimal("21000") / Decimal(10 ** 18)
        return f"{eth:.8f}"
    except Exception:
        return None


def _erc20() -> Optional[str]:
    if not Config.infura_project_id:
        return None
    auth = (Config.infura_project_id, Config.infura_project_secret) if Config.infura_project_secret else None
    url = f"https://{Config.infura_network}.infura.io/v3/{Config.infura_project_id}"
    payload = {
        "jsonrpc": "2.0",
        "method": "eth_gasPrice",
        "params": [],
        "id": 1,
    }
    try:
        r = requests.post(url, json=payload, auth=auth, timeout=15)
        r.raise_for_status()
        wei = int(r.json()["result"], 16)
        eth = Decimal(wei) * Decimal("55000") / Decimal(10 ** 18)
        return f"{eth:.8f}"
    except Exception:
        return None


def _tron() -> Optional[str]:
    # TRON USDT TRC-20 fee estimate: a typical transfer costs ~2-8 TRX in energy/bandwidth.
    # We use a conservative fee limit that is lower than the original 13.5 TRX buffer.
    return "10.00000000"


def estimate(network: str, token_transfer: bool = False) -> Optional[str]:
    if network == "bitcoin":
        return _btc()
    if network == "usdt_erc20" and token_transfer:
        return _erc20()
    if network == "usdt_erc20":
        return _eth()
    if network == "usdt_trc20":
        return _tron()
    return None
