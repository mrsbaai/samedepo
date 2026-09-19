"""Fee estimation from public blockchain providers."""
from __future__ import annotations

import json
import math
import time
from decimal import Decimal
from typing import Optional

import requests
from tronpy.abi import trx_abi
from tronpy.exceptions import AddressNotFound
from web3 import Web3

from samedepo_signer import config, keys
from samedepo_signer.config import Config

ERC20_GAS_LIMIT = 65000
ETH_GAS_LIMIT = 21000

TRC20_ENERGY_HOLDER_FALLBACK = 130000
TRC20_BANDWIDTH_BYTES = 345
TRX_TRANSFER_BYTES = 270
TRX_ACTIVATION = Decimal("1")
TRON_BANDWIDTH_PRICE_SUN = 1000
SUN_PER_TRX = Decimal(10 ** 6)
_FEE_CACHE_TTL = 60

_fee_cache: dict[tuple, tuple[float, str]] = {}
_energy_price_cache: Optional[tuple[float, int]] = None


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
        eth = Decimal(wei) * Decimal(ETH_GAS_LIMIT) / Decimal(10 ** 18)
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
        eth = Decimal(wei) * Decimal(ERC20_GAS_LIMIT) / Decimal(10 ** 18)
        return f"{eth:.8f}"
    except Exception:
        return None


def _ltc() -> Optional[str]:
    url = f"{config.NETWORKS['litecoin']['esplora_url']}/v1/fees/recommended"
    try:
        r = requests.get(url, timeout=15)
        r.raise_for_status()
        sat_per_vb = Decimal(str(r.json().get("halfHourFee") or 0))
        if sat_per_vb <= 0:
            return None
        # Assume a 141-vbyte P2WPKH transaction.
        fee = sat_per_vb * Decimal(141) / Decimal(10 ** 8)
        return f"{max(fee, Decimal('0.0001')):.8f}"
    except Exception:
        return None


def _evm_gas_price_wei(network: str) -> Optional[int]:
    entry = config.network(network)
    url = config.rpc_url(network)
    if not url:
        return None
    auth = None
    if entry["chain"] == "ethereum" and Config.infura_project_secret:
        auth = (Config.infura_project_id, Config.infura_project_secret)
    payload = {"jsonrpc": "2.0", "method": "eth_gasPrice", "params": [], "id": 1}
    r = requests.post(url, json=payload, auth=auth, timeout=15)
    r.raise_for_status()
    return int(r.json()["result"], 16)


def _evm_token_gas(network: str, source: Optional[str], destination: Optional[str]) -> int:
    if not destination or not source:
        return ERC20_GAS_LIMIT
    try:
        from samedepo_signer.transactions import _w3, ERC20_ABI, GAS_HEADROOM  # lazy: transactions imports fees

        w3 = _w3(network)
        if w3 is None:
            return ERC20_GAS_LIMIT
        contract = w3.eth.contract(
            address=Web3.to_checksum_address(config.network(network)["contract"]), abi=ERC20_ABI
        )
        estimate = contract.functions.transfer(Web3.to_checksum_address(destination), 1).estimate_gas(
            {"from": Web3.to_checksum_address(source)}
        )
        return max(ERC20_GAS_LIMIT, int(Decimal(estimate) * GAS_HEADROOM))
    except Exception:
        return ERC20_GAS_LIMIT


def _evm(
    network: str,
    token_transfer: bool = False,
    destination: Optional[str] = None,
    source_index: Optional[int] = None,
) -> Optional[str]:
    source = None
    if token_transfer and destination:
        try:
            source = keys.derive_address(network, 0 if source_index is None else int(source_index))
        except Exception:
            source = None
    cache_key = ("evm", config.chain(network), token_transfer, source or "", destination or "")
    now = time.monotonic()
    hit = _fee_cache.get(cache_key)
    if hit is not None and now - hit[0] < _FEE_CACHE_TTL:
        return hit[1]
    try:
        gas_price = _evm_gas_price_wei(network)
        if gas_price is None:
            return None
        gas = _evm_token_gas(network, source, destination) if token_transfer else ETH_GAS_LIMIT
    except Exception:
        return None
    fee = f"{Decimal(gas_price) * Decimal(gas) / Decimal(10 ** 18):.8f}"
    _fee_cache[cache_key] = (now, fee)
    return fee


def _energy_price_sun(client) -> int:
    global _energy_price_cache
    now = time.monotonic()
    if _energy_price_cache is not None and now - _energy_price_cache[0] < _FEE_CACHE_TTL:
        return _energy_price_cache[1]
    params = client.get_chain_parameters()
    price = next((int(p["value"]) for p in params if p.get("key") == "getEnergyFee"), 100)
    _energy_price_cache = (now, price)
    return price


def trc20_energy_estimate(client, source: str, destination: Optional[str]) -> int:
    if not destination:
        return TRC20_ENERGY_HOLDER_FALLBACK
    try:
        parameter = trx_abi.encode_single("(address,uint256)", (destination, 1)).hex()
        ret = client.trigger_constant_contract(
            source, Config.trongrid_usdt_contract, "transfer(address,uint256)", parameter
        )
        return int(ret.get("energy_used") or TRC20_ENERGY_HOLDER_FALLBACK)
    except Exception:
        return TRC20_ENERGY_HOLDER_FALLBACK


def _tron_token_fee_sun(client, source: str, destination: Optional[str]) -> int:
    return (
        trc20_energy_estimate(client, source, destination) * _energy_price_sun(client)
        + TRC20_BANDWIDTH_BYTES * TRON_BANDWIDTH_PRICE_SUN
    )


def _tron_native_fee_sun(client, destination: Optional[str]) -> int:
    fee = TRX_TRANSFER_BYTES * TRON_BANDWIDTH_PRICE_SUN
    if destination is None:
        return fee + int(TRX_ACTIVATION * SUN_PER_TRX)
    try:
        client.get_account(destination)
        return fee
    except AddressNotFound:
        return fee + int(TRX_ACTIVATION * SUN_PER_TRX)


def _tron(
    token_transfer: bool = False,
    destination: Optional[str] = None,
    source_index: Optional[int] = None,
) -> Optional[str]:
    source = keys.derive_address("usdt_trc20", 0 if source_index is None else int(source_index))
    cache_key = (token_transfer, source, destination or "")
    now = time.monotonic()
    hit = _fee_cache.get(cache_key)
    if hit is not None and now - hit[0] < _FEE_CACHE_TTL:
        return hit[1]
    try:
        from samedepo_signer.transactions import _trx_client  # lazy: transactions imports fees

        client = _trx_client()
        sun = (
            _tron_token_fee_sun(client, source, destination)
            if token_transfer
            else _tron_native_fee_sun(client, destination)
        )
    except Exception:
        return None
    fee = f"{Decimal(sun) / SUN_PER_TRX:.8f}"
    _fee_cache[cache_key] = (now, fee)
    return fee


def estimate(
    network: str,
    token_transfer: bool = False,
    destination: Optional[str] = None,
    source_index: Optional[int] = None,
) -> Optional[str]:
    if network not in config.NETWORKS:
        return None
    if network == "bitcoin":
        return _btc()
    if network == "litecoin":
        return _ltc()
    if network == "usdt_erc20" and token_transfer:
        return _erc20()
    if network == "usdt_erc20":
        return _eth()
    if network == "usdt_trc20":
        return _tron(token_transfer, destination, source_index)
    if config.family(network) == "evm":
        return _evm(network, token_transfer, destination, source_index)
    return None
