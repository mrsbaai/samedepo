"""Transaction signing and broadcasting. This is where private keys are used."""
from __future__ import annotations

from decimal import Decimal
from typing import Optional

from samedepo_signer.config import Config
from samedepo_signer import keys


def _to_sat(amount: str) -> int:
    return int(Decimal(amount) * Decimal(10 ** 8))


def _from_sat(sat: int) -> str:
    return f"{Decimal(sat) / Decimal(10 ** 8):.8f}"


def _broadcast_bitcoin(index: int, destination: str, amount: str, fee: str) -> Optional[str]:
    # ponytail: use bit library with WIF derived from seed once dependency is installed.
    # For now, we leave this as a stub so the signer can start and the Laravel integration can be tested.
    raise NotImplementedError("Bitcoin signing not yet enabled in this build")


def _broadcast_erc20(index: int, destination: str, amount: str, fee: str) -> Optional[str]:
    from web3 import Web3
    from eth_account import Account

    w3 = Web3(Web3.HTTPProvider(
        f"https://{Config.infura_network}.infura.io/v3/{Config.infura_project_id}",
        request_kwargs={"auth": (Config.infura_project_id, Config.infura_project_secret)} if Config.infura_project_secret else {}
    ))
    if not w3.is_connected():
        return None

    source = keys.derive_address("usdt_erc20", index)
    source_private = keys.derive_private_key("usdt_erc20", index)
    account = Account.from_key(source_private)

    if not Web3.is_address(destination):
        raise ValueError("Invalid destination address")
    to = Web3.to_checksum_address(destination)
    contract = w3.eth.contract(address=Web3.to_checksum_address(Config.infura_usdt_contract), abi=ERC20_ABI)
    decimals = contract.functions.decimals().call()
    value = int(Decimal(amount) * (10 ** decimals))

    nonce = w3.eth.get_transaction_count(Web3.to_checksum_address(source), "pending")
    tx = contract.functions.transfer(to, value).build_transaction({
        "from": Web3.to_checksum_address(source),
        "nonce": nonce,
        "gas": 100000,
        "gasPrice": w3.to_wei(fee, "ether") // 65000 or 1,
    })
    signed = w3.eth.account.sign_transaction(tx, source_private)
    return w3.eth.send_raw_transaction(signed.rawTransaction).hex()


def _broadcast_eth(index: int, destination: str, amount: str, fee: str) -> Optional[str]:
    from web3 import Web3
    from eth_account import Account

    w3 = Web3(Web3.HTTPProvider(
        f"https://{Config.infura_network}.infura.io/v3/{Config.infura_project_id}",
        request_kwargs={"auth": (Config.infura_project_id, Config.infura_project_secret)} if Config.infura_project_secret else {}
    ))
    if not w3.is_connected():
        return None

    source = keys.derive_address("usdt_erc20", index)
    source_private = keys.derive_private_key("usdt_erc20", index)
    account = Account.from_key(source_private)

    if not Web3.is_address(destination):
        raise ValueError("Invalid destination address")
    to = Web3.to_checksum_address(destination)
    value = w3.to_wei(amount, "ether")

    nonce = w3.eth.get_transaction_count(Web3.to_checksum_address(source), "pending")
    tx = {
        "to": to,
        "value": value,
        "gas": 21000,
        "gasPrice": w3.to_wei(fee, "ether"),
        "nonce": nonce,
        "chainId": 1,
    }
    signed = w3.eth.account.sign_transaction(tx, source_private)
    return w3.eth.send_raw_transaction(signed.rawTransaction).hex()


def _broadcast_trc20(index: int, destination: str, amount: str, fee: str) -> Optional[str]:
    from tronpy import Tron
    from tronpy.keys import PrivateKey

    client = Tron(network="mainnet", provider="trongrid", api_key=Config.trongrid_api_key)
    source = keys.derive_address("usdt_trc20", index)
    source_private = keys.derive_private_key("usdt_trc20", index)
    private = PrivateKey(bytes.fromhex(source_private.hex()[2:]) if isinstance(source_private, bytes) else source_private)

    tx = client.trx.transfer(
        from_=source,
        to=destination,
        amount=int(Decimal(amount) * Decimal(10 ** 6))
    ).build()
    return tx.sign(private).broadcast().get("txid")


def broadcast_withdrawal(network: str, index: int, destination: str, amount: str, fee: str) -> Optional[str]:
    if network == "bitcoin":
        return _broadcast_bitcoin(index, destination, amount, fee)
    if network == "usdt_erc20":
        return _broadcast_erc20(index, destination, amount, fee)
    if network == "usdt_trc20":
        return _broadcast_trc20(index, destination, amount, fee)
    raise ValueError(f"Unsupported network: {network}")


def broadcast_sweep(network: str, source_index: int, destination_index: int, amount: str, fee: str) -> Optional[str]:
    destination = keys.derive_address(network, destination_index)
    return broadcast_withdrawal(network, source_index, destination, amount, fee)


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
