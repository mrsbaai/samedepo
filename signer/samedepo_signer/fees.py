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
    url = f"https://api.blockcypher.com/v1/btc/{Config.blockcypher_network}/fee?token={Config.blockcypher_token}"
    try:
        r = requests.get(url, timeout=15)
        r.raise_for_status()
        data = r.json()
        sat_per_kb = data.get("medium", data.get("half_hour_fee", 0))
        if not sat_per_kb:
            return None
        return f"{Decimal(sat_per_kb) / Decimal(100000 * 1000):.8f}"
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
        eth = Decimal(wei) * Decimal("65000") / Decimal(10 ** 18)
        return f"{eth:.8f}"
    except Exception:
        return None


def _tron() -> Optional[str]:
    # TRON USDT TRC-20 fee estimate: a typical transfer costs ~13.5 TRX in energy/bandwidth today.
    # We use a fixed safe estimate because precise energy cannot be predicted without account state.
    # ponytail: fixed estimate; query chain parameters when live precision matters.
    return "13.50000000"


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
