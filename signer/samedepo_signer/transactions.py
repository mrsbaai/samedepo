"""Transaction signing and broadcasting. This is where private keys are used."""
from __future__ import annotations

import hashlib
from decimal import Decimal
from typing import Optional

import requests
from ecdsa import SigningKey, SECP256k1, util
from tronpy import Tron
from tronpy.keys import PrivateKey
from tronpy.providers.http import HTTPProvider
from web3 import Web3

from samedepo_signer.config import Config
from samedepo_signer import keys


def _to_sats(btc: str) -> int:
    return int(Decimal(btc) * Decimal(10 ** 8))


def _btc_transfer(source_index: int, destination: str, amount: str, fee: str) -> Optional[str]:
    if not Config.blockcypher_token:
        return None

    to_send = _to_sats(amount) - _to_sats(fee)
    if to_send <= 0:
        raise ValueError("Amount must be greater than fee")

    fee_sats = _to_sats(fee)
    source = keys.derive_address("bitcoin", source_index)

    new_url = f"https://api.blockcypher.com/v1/btc/{Config.blockcypher_network}/txs/new"
    payload = {
        "inputs": [{"addresses": [source]}],
        "outputs": [{"addresses": [destination], "value": to_send}],
        "fees": fee_sats,
    }
    r = requests.post(new_url, json=payload, params={"token": Config.blockcypher_token}, timeout=30)
    if not r.ok:
        return None

    skeleton = r.json()
    tosign = skeleton.get("tosign") or []
    if not tosign:
        return None

    source_private = keys.derive_private_key("bitcoin", source_index)
    public_key = keys.derive_public_key("bitcoin", source_index)
    sk = SigningKey.from_string(source_private, curve=SECP256k1, hashfunc=hashlib.sha256)

    signatures = []
    pubkeys = []
    for digest in tosign:
        sig = sk.sign_digest(bytes.fromhex(digest), sigencode=util.sigencode_der)
        signatures.append(sig.hex() + "01")
        pubkeys.append(public_key)

    skeleton["signatures"] = signatures
    skeleton["pubkeys"] = pubkeys

    send_url = f"https://api.blockcypher.com/v1/btc/{Config.blockcypher_network}/txs/send"
    s = requests.post(send_url, json=skeleton, params={"token": Config.blockcypher_token}, timeout=30)
    if not s.ok:
        return None

    return s.json().get("tx", {}).get("hash")


def _to_wei(fee_eth: str, gas: int) -> int:
    """Convert a total ETH fee into a per-gas wei price."""
    fee = Decimal(fee_eth) * Decimal(10 ** 18)
    if fee <= 0:
        raise ValueError("Fee must be positive")
    return int(fee // Decimal(gas))


def _w3(network: str = "usdt_erc20") -> Optional[Web3]:
    if not Config.infura_project_id:
        return None
    if network == "usdt_base":
        network_name = Config.infura_base_network
        auth = (Config.infura_project_id, Config.infura_project_secret) if Config.infura_project_secret else None
        url = f"https://{network_name}.infura.io/v3/{Config.infura_project_id}"
    else:
        auth = (Config.infura_project_id, Config.infura_project_secret) if Config.infura_project_secret else None
        url = f"https://{Config.infura_network}.infura.io/v3/{Config.infura_project_id}"
    w3 = Web3(Web3.HTTPProvider(url, request_kwargs={"auth": auth} if auth else {}))
    if not w3.is_connected():
        return None
    return w3


def _erc20_transfer(source_index: int, destination: str, amount: str, fee_eth: str, network: str = "usdt_erc20") -> Optional[str]:
    w3 = _w3(network)
    if w3 is None:
        return None

    contract_address = Config.infura_base_usdt_contract if network == "usdt_base" else Config.infura_usdt_contract
    source = Web3.to_checksum_address(keys.derive_address(network, source_index))
    source_private = keys.derive_private_key(network, source_index)

    contract = w3.eth.contract(address=Web3.to_checksum_address(contract_address), abi=ERC20_ABI)
    decimals = contract.functions.decimals().call()
    value = int(Decimal(amount) * (10 ** decimals))

    nonce = w3.eth.get_transaction_count(source, "latest")
    gas = 55000
    gas_price = _to_wei(fee_eth, gas)

    tx = contract.functions.transfer(Web3.to_checksum_address(destination), value).build_transaction({
        "from": source,
        "nonce": nonce,
        "gas": gas,
        "gasPrice": gas_price,
        "chainId": w3.eth.chain_id,
    })
    signed = w3.eth.account.sign_transaction(tx, source_private)
    return w3.eth.send_raw_transaction(signed.rawTransaction).hex()


def _eth_native_transfer(source_index: int, destination: str, amount_eth: str, fee_eth: str, network: str = "usdt_erc20") -> Optional[str]:
    w3 = _w3(network)
    if w3 is None:
        return None

    source = Web3.to_checksum_address(keys.derive_address(network, source_index))
    source_private = keys.derive_private_key(network, source_index)

    nonce = w3.eth.get_transaction_count(source, "latest")
    tx = {
        "to": Web3.to_checksum_address(destination),
        "value": w3.to_wei(amount_eth, "ether"),
        "gas": 21000,
        "gasPrice": _to_wei(fee_eth, 21000),
        "nonce": nonce,
        "chainId": w3.eth.chain_id,
    }
    signed = w3.eth.account.sign_transaction(tx, source_private)
    return w3.eth.send_raw_transaction(signed.rawTransaction).hex()


def _trx_client() -> Tron:
    return Tron(provider=HTTPProvider(api_key=Config.trongrid_api_key))


def _sun(value: str) -> int:
    return int(Decimal(value) * Decimal(10 ** 6))


def _trx_transfer(source_index: int, destination: str, amount_trx: str, fee_trx: str) -> Optional[str]:
    client = _trx_client()
    source = keys.derive_address("usdt_trc20", source_index)
    source_private = PrivateKey(keys.derive_private_key("usdt_trc20", source_index))

    amount_sun = _sun(amount_trx)
    fee_sun = _sun(fee_trx)

    tx = client.trx.transfer(source, destination, amount_sun).fee_limit(fee_sun).build().sign(source_private).broadcast()
    return tx.get("txid") or tx.txid


def _trc20_transfer(source_index: int, destination: str, amount: str, fee_trx: str) -> Optional[str]:
    client = _trx_client()
    source = keys.derive_address("usdt_trc20", source_index)
    source_private = PrivateKey(keys.derive_private_key("usdt_trc20", source_index))

    contract = client.get_contract(Config.trongrid_usdt_contract)
    value = _sun(amount)
    fee_sun = _sun(fee_trx)

    tx = contract.functions.transfer(destination, value).with_owner(source).fee_limit(fee_sun).build()
    signed_tx = tx.sign(source_private)
    result = signed_tx.broadcast()
    return result.get("txid") or result.get("transaction", {}).get("txID")


def _broadcast_trx(builder, private_key: PrivateKey) -> Optional[str]:
    """Broadcast a tronpy transaction builder and return the txid, or None on failure."""
    try:
        tx = builder.build().sign(private_key).broadcast()
        return tx.get("txid") or tx.txid
    except Exception:
        return None


def _delegate_energy(client: Tron, source: str, treasury_index: int) -> bool:
    """Stake 100 TRX for energy from the treasury and delegate it to the source.
    Returns True only if the delegation transaction is successfully broadcast.
    If delegation fails because nothing has been staked yet, the treasury is
    frozen for energy first; the next retry will then delegate.
    """
    treasury = keys.derive_address("usdt_trc20", treasury_index)
    treasury_private = PrivateKey(keys.derive_private_key("usdt_trc20", treasury_index))
    stake_sun = 100_000_000

    # Try to delegate first. If the treasury has not staked enough energy yet,
    # the broadcast will fail; freeze 100 TRX for energy and let the next retry delegate.
    delegate = client.trx.delegate_resource(treasury, source, stake_sun, "ENERGY", lock=False)
    if _broadcast_trx(delegate.fee_limit(15_000_000), treasury_private):
        return True

    freeze = client.trx.freeze_balance(treasury, stake_sun, "ENERGY")
    _broadcast_trx(freeze.fee_limit(15_000_000), treasury_private)
    return False


def _trc20_sweep(source_index: int, destination_index: int, amount: str, fee: str) -> Optional[str]:
    """Sweep TRC20 USDT. Auto top-up TRX and delegate energy before the token transfer."""
    client = _trx_client()
    source = keys.derive_address("usdt_trc20", source_index)
    dest = keys.derive_address("usdt_trc20", destination_index)
    fee_sun = _sun(fee)
    topup_sun = max(30_000_000, fee_sun * 2)
    min_energy = 70_000

    try:
        account = client.get_account(source)
        if account is None or account.get("balance", 0) < fee_sun:
            _trx_transfer(destination_index, source, str(Decimal(topup_sun) / Decimal(10 ** 6)), fee)
            return None
    except Exception:
        _trx_transfer(destination_index, source, str(Decimal(topup_sun) / Decimal(10 ** 6)), fee)
        return None

    try:
        resource = client.get_account_resource(source)
        available = resource.get("EnergyLimit", 0) - resource.get("EnergyUsed", 0)
    except Exception:
        available = 0

    if available < min_energy:
        if not _delegate_energy(client, source, destination_index):
            return None
        return None

    return _trc20_transfer(source_index, dest, amount, fee)


def _erc20_sweep(source_index: int, destination_index: int, amount: str, fee: str, network: str = "usdt_erc20") -> Optional[str]:
    """Sweep ERC-20 USDT. Auto top-up ETH if the source address is not funded."""
    w3 = _w3(network)
    if w3 is None:
        return None

    source = Web3.to_checksum_address(keys.derive_address(network, source_index))
    dest = Web3.to_checksum_address(keys.derive_address(network, destination_index))
    fee_wei = _to_wei(fee, 65000)

    # Top-up enough ETH to cover this fee plus a small reserve.
    topup_eth = max(Decimal("0.001"), Decimal(fee) * 2)
    try:
        balance = w3.eth.get_balance(source)
    except Exception:
        balance = 0

    if balance < fee_wei:
        _eth_native_transfer(destination_index, source, str(topup_eth), fee, network)
        return None

    return _erc20_transfer(source_index, dest, amount, fee, network)


def broadcast_withdrawal(network: str, index: int, destination: str, amount: str, fee: str) -> Optional[str]:
    if network == "bitcoin":
        return _btc_transfer(index, destination, amount, fee)
    if network == "usdt_erc20":
        return _erc20_transfer(index, destination, amount, fee, "usdt_erc20")
    if network == "usdt_base":
        return _erc20_transfer(index, destination, amount, fee, "usdt_base")
    if network == "usdt_trc20":
        return _trc20_transfer(index, destination, amount, fee)
    raise ValueError(f"Unsupported network: {network}")


def broadcast_sweep(network: str, source_index: int, destination_index: int, amount: str, fee: str) -> Optional[str]:
    if network == "bitcoin":
        destination = keys.derive_address("bitcoin", destination_index)
        return _btc_transfer(source_index, destination, amount, fee)
    if network == "usdt_erc20":
        return _erc20_sweep(source_index, destination_index, amount, fee, "usdt_erc20")
    if network == "usdt_base":
        return _erc20_sweep(source_index, destination_index, amount, fee, "usdt_base")
    if network == "usdt_trc20":
        return _trc20_sweep(source_index, destination_index, amount, fee)
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
