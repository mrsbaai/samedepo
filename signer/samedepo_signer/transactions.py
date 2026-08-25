"""Transaction signing and broadcasting. This is where private keys are used."""
from __future__ import annotations

from decimal import Decimal
from typing import Optional

import requests
from web3 import Web3

from samedepo_signer.config import Config
from samedepo_signer import keys


def _btc() -> Optional[str]:
    # ponytail: bitcoin signing is still stubbed. Use bit+BlockCypher in a follow-up.
    return None


def _to_wei(fee_eth: str, gas: int) -> int:
    """Convert a total ETH fee into a per-gas wei price."""
    fee = Decimal(fee_eth) * Decimal(10 ** 18)
    if fee <= 0:
        raise ValueError("Fee must be positive")
    return int(fee // Decimal(gas))


def _eth_send_native(source_index: int, destination: str, amount_eth: str, fee_eth: str) -> Optional[str]:
    from eth_account import Account

    w3 = _w3()
    if w3 is None:
        return None

    source = Web3.to_checksum_address(keys.derive_address("usdt_erc20", source_index))
    source_private = keys.derive_private_key("usdt_erc20", source_index)

    nonce = w3.eth.get_transaction_count(source, "pending")
    tx = {
        "to": Web3.to_checksum_address(destination),
        "value": w3.to_wei(amount_eth, "ether"),
        "gas": 21000,
        "gasPrice": _to_wei(fee_eth, 21000),
        "nonce": nonce,
        "chainId": 1,
    }
    signed = w3.eth.account.sign_transaction(tx, source_private)
    return w3.eth.send_raw_transaction(signed.rawTransaction).hex()


def _erc20_transfer(source_index: int, destination: str, amount: str, fee_eth: str) -> Optional[str]:
    from eth_account import Account

    w3 = _w3()
    if w3 is None:
        return None

    source = Web3.to_checksum_address(keys.derive_address("usdt_erc20", source_index))
    source_private = keys.derive_private_key("usdt_erc20", source_index)

    contract = w3.eth.contract(address=Web3.to_checksum_address(Config.infura_usdt_contract), abi=ERC20_ABI)
    decimals = contract.functions.decimals().call()
    value = int(Decimal(amount) * (10 ** decimals))

    nonce = w3.eth.get_transaction_count(source, "pending")
    gas = 65000
    gas_price = _to_wei(fee_eth, gas)

    tx = contract.functions.transfer(Web3.to_checksum_address(destination), value).build_transaction({
        "from": source,
        "nonce": nonce,
        "gas": gas,
        "gasPrice": gas_price,
        "chainId": 1,
    })
    signed = w3.eth.account.sign_transaction(tx, source_private)
    return w3.eth.send_raw_transaction(signed.rawTransaction).hex()


def _w3() -> Optional[Web3]:
    if not Config.infura_project_id:
        return None
    auth = (Config.infura_project_id, Config.infura_project_secret) if Config.infura_project_secret else None
    w3 = Web3(Web3.HTTPProvider(
        f"https://{Config.infura_network}.infura.io/v3/{Config.infura_project_id}",
        request_kwargs={"auth": auth} if auth else {}
    ))
    if not w3.is_connected():
        return None
    return w3


def _trc20_transfer(source_index: int, destination: str, amount: str, fee: str) -> Optional[str]:
    from tronpy import Tron
    from tronpy.keys import PrivateKey
    from tronpy.providers.http import HTTPProvider

    client = Tron(provider=HTTPProvider(api_key=Config.trongrid_api_key))
    source = keys.derive_address("usdt_trc20", source_index)
    source_private = PrivateKey(keys.derive_private_key("usdt_trc20", source_index))

    contract = client.get_contract(Config.trongrid_usdt_contract)
    value = int(Decimal(amount) * Decimal(10 ** 6))
    fee_limit = int(Decimal(fee) * Decimal(10 ** 6))

    tx = contract.functions.transfer(destination, value).with_owner(source).fee_limit(fee_limit).build()
    signed_tx = tx.sign(source_private)
    result = signed_tx.broadcast()
    return result.get("txid") or result.get("transaction", {}).get("txID")


def broadcast_withdrawal(network: str, index: int, destination: str, amount: str, fee: str) -> Optional[str]:
    if network == "bitcoin":
        raise NotImplementedError("Bitcoin signing not yet enabled")
    if network == "usdt_erc20":
        return _erc20_transfer(index, destination, amount, fee)
    if network == "usdt_trc20":
        return _trc20_transfer(index, destination, amount, fee)
    raise ValueError(f"Unsupported network: {network}")


def broadcast_sweep(network: str, source_index: int, destination_index: int, amount: str, fee: str) -> Optional[str]:
    if network == "bitcoin":
        raise NotImplementedError("Bitcoin signing not yet enabled")
    # For token sweeps, the destination is the treasury wallet.
    if network == "usdt_erc20":
        destination = keys.derive_address("usdt_erc20", destination_index)
        return _erc20_transfer(source_index, destination, amount, fee)
    if network == "usdt_trc20":
        destination = keys.derive_address("usdt_trc20", destination_index)
        return _trc20_transfer(source_index, destination, amount, fee)
    raise ValueError(f"Unsupported network: {network}")


ERC20_ABI = [
    {
        "constant": True,
        "inputs": [],
        "name": "decimals",
        "outputs": [{"name": "", "type": "uint8"}],
        "type": "function",
    },
    {
        "constant": False,
        "inputs": [
            {"name": "_to", "type": "address"},
            {"name": "_value", "type": "uint256"},
        ],
        "name": "transfer",
        "outputs": [{"name": "", "type": "bool"}],
        "type": "function",
    },
]
