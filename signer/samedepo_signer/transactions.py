"""Transaction signing and broadcasting. This is where private keys are used."""
from __future__ import annotations

import contextvars
import threading
from collections import defaultdict
from decimal import Decimal
from typing import Optional

import requests
from eth_account import Account
from tronpy import Tron
from tronpy.exceptions import AddressNotFound
from tronpy.keys import PrivateKey, Signature, to_hex_address
from tronpy.providers.http import HTTPProvider
from web3 import Web3
from web3.exceptions import TransactionNotFound

from samedepo_signer import config
from samedepo_signer.config import Config
from samedepo_signer.fees import ERC20_GAS_LIMIT
from samedepo_signer import fees
from samedepo_signer import keys
from samedepo_signer import utxo

GAS_HEADROOM = Decimal("1.2")

# Set by the API for a "dry_run" request: the tx is built and signed exactly as
# live, but every send point routes through the *_submit helpers below, which
# validate against the network instead of broadcasting.
_DRY_RUN: contextvars.ContextVar[bool] = contextvars.ContextVar("dry_run", default=False)
_DRY_RUN_CHECKS: contextvars.ContextVar[Optional[list]] = contextvars.ContextVar("dry_run_checks", default=None)


def _check(name: str, ok: bool, detail: str) -> None:
    checks = _DRY_RUN_CHECKS.get()
    if checks is not None:
        checks.append({"name": name, "ok": bool(ok), "detail": str(detail)})


def _evm_dry_run_checks(w3: Web3, raw: bytes, tx: dict, source: str) -> None:
    try:
        recovered = Account.recover_transaction(raw)
        _check("sender", Web3.to_checksum_address(recovered) == Web3.to_checksum_address(source), recovered)
    except Exception as exc:
        _check("sender", False, f"recover failed: {exc}")

    call = {
        "from": source,
        "to": tx.get("to"),
        "data": tx.get("data") or "0x",
        "value": tx.get("value", 0),
        "gas": tx.get("gas"),
        "gasPrice": tx.get("gasPrice"),
    }
    try:
        result = w3.eth.call(call, "latest")
        # Token transfers: empty return (non-compliant ERC20) and decoded True
        # are both fine; decoded False means the transfer would fail.
        ok = len(result) == 0 or int.from_bytes(bytes(result[-32:]), "big") != 0
        _check("eth_call", ok, f"returned {result.hex()[:66] if hasattr(result, 'hex') else result}")
    except Exception as exc:
        _check("eth_call", False, f"reverted: {exc}")

    try:
        balance = int(w3.eth.get_balance(source))
        required = int(tx.get("value", 0)) + int(tx.get("gas", 0)) * int(tx.get("gasPrice", 0))
        _check(
            "balance",
            balance >= required,
            f"required {Decimal(required) / Decimal(10 ** 18):.8f} available {Decimal(balance) / Decimal(10 ** 18):.8f}",
        )
    except Exception as exc:
        _check("balance", False, str(exc))

    try:
        block = w3.eth.get_block("latest")
        base_fee = block.get("baseFeePerGas") if hasattr(block, "get") else getattr(block, "baseFeePerGas", None)
        if base_fee is None:
            _check("base_fee", True, "chain has no base fee")
        else:
            gas_price = int(tx.get("gasPrice", 0))
            _check("base_fee", gas_price >= int(base_fee), f"gasPrice {gas_price} baseFee {base_fee}")
    except Exception as exc:
        _check("base_fee", True, f"unavailable: {exc}")

    try:
        latest = w3.eth.get_transaction_count(source, "latest")
        nonce = tx.get("nonce")
        _check("nonce", nonce == latest, f"nonce {nonce} latest {latest}")
        try:
            pending = w3.eth.get_transaction_count(source, "pending")
            _check(
                "pending_tx_ahead",
                pending <= latest,
                "none" if pending <= latest else f"pending {pending} > latest {latest}",
            )
        except Exception:
            _check("pending_tx_ahead", True, "pending unavailable")
    except Exception as exc:
        _check("nonce", False, str(exc))


def _evm_submit(w3: Web3, signed, tx: dict, source: str) -> str:
    if not _DRY_RUN.get():
        return w3.eth.send_raw_transaction(signed.rawTransaction).hex()
    _evm_dry_run_checks(w3, signed.rawTransaction, tx, source)
    return w3.keccak(signed.rawTransaction).hex()


def _utxo_submit(base: str, raw: str, txid: str) -> str:
    if not _DRY_RUN.get():
        s = requests.post(f"{base}/tx", data=raw, timeout=30)
        if not s.ok:
            raise ValueError(f"broadcast rejected: {s.text[:300]}")
        return s.text.strip() or txid
    try:
        r = requests.post(f"{base}/txs/test", json=[raw], timeout=30)
    except Exception as exc:
        _check("testmempoolaccept", False, str(exc))
        return txid
    if r.status_code != 200:
        _check("testmempoolaccept", False, f"HTTP {r.status_code}: {r.text[:200]}")
        return txid
    try:
        item = (r.json() or [{}])[0]
    except Exception:
        item = {}
    allowed = bool(item.get("allowed"))
    _check(
        "testmempoolaccept",
        allowed,
        f"allowed={allowed} reject-reason={item.get('reject-reason')} vsize={item.get('vsize')} fees={item.get('fees')}",
    )
    return txid


def _tron_dry_run_checks(client: Tron, signed_tx, source: str, kind: str, ctx: dict) -> None:
    try:
        signature = Signature(bytes.fromhex(signed_tx.to_json()["signature"][0]))
        recovered = signature.recover_public_key_from_msg_hash(bytes.fromhex(signed_tx.txid)).to_base58check_address()
        _check("sender", recovered == source, recovered)
    except Exception as exc:
        _check("sender", False, f"recover failed: {exc}")

    energy_used = None
    if kind == "trc20":
        parameter = to_hex_address(ctx["destination"])[-40:].rjust(64, "0") + f"{ctx['amount_sun']:064x}"
        try:
            resp = client.provider.make_request("wallet/triggerconstantcontract", {
                "visible": True,
                "owner_address": source,
                "contract_address": Config.trongrid_usdt_contract,
                "function_selector": "transfer(address,uint256)",
                "parameter": parameter,
            })
            energy_used = resp.get("energy_used")
            constant = resp.get("constant_result") or [""]
            decoded = int(constant[0], 16) if constant[0] else 0
            ok = bool(resp.get("result", {}).get("result")) and decoded == 1
            _check("constant_call", ok, f"result={resp.get('result', {}).get('result')} constant={decoded} energy_used={energy_used}")
        except Exception as exc:
            _check("constant_call", False, str(exc))

    try:
        account = client.get_account(source)
        balance_sun = int((account or {}).get("balance", 0))
    except Exception:
        balance_sun = 0

    if kind == "trx":
        required = ctx["amount_sun"] + ctx["fee_sun"]
        _check(
            "balance",
            balance_sun >= required,
            f"required {Decimal(required) / Decimal(10 ** 6):.8f} available {Decimal(balance_sun) / Decimal(10 ** 6):.8f}",
        )
    else:
        try:
            resource = client.get_account_resource(source)
        except AddressNotFound:
            resource = {}
        except Exception:
            resource = {}
        free_bandwidth = int(resource.get("freeNetLimit", 0)) - int(resource.get("freeNetUsed", 0))
        energy_available = int(resource.get("EnergyLimit", 0)) - int(resource.get("EnergyUsed", 0))
        short = balance_sun < 400_000 and free_bandwidth < 345
        _check(
            "bandwidth",
            not short,
            f"balance {Decimal(balance_sun) / Decimal(10 ** 6):.8f} TRX, free bandwidth {free_bandwidth}, "
            f"energy available {energy_available}, energy used {energy_used}",
        )


def _tron_submit(client: Tron, signed_tx, source: str, kind: str, **ctx):
    if not _DRY_RUN.get():
        return signed_tx.broadcast()
    _tron_dry_run_checks(client, signed_tx, source, kind, ctx)
    return {"txid": signed_tx.txid}


class InsufficientGas(Exception):
    def __init__(self, required: str, available: str):
        super().__init__(f"insufficient gas: required {required}, available {available}")
        self.required = required
        self.available = available


_SEND_LOCKS: dict[tuple, threading.Lock] = defaultdict(threading.Lock)
_SEND_LOCKS_GUARD = threading.Lock()


def _send_lock(chain: str, address: str) -> threading.Lock:
    with _SEND_LOCKS_GUARD:
        return _SEND_LOCKS[(chain, address.lower())]


def _to_sats(btc: str) -> int:
    return int(Decimal(btc) * Decimal(10 ** 8))


def _utxo_transfer(
    network: str, source_index: int, destination: str, amount: str, fee: str, fee_from_amount: bool = False
) -> Optional[str]:
    """Build and sign a P2WPKH send locally, broadcast through the Esplora API."""
    entry = config.network(network)
    base = entry["esplora_url"]
    source = keys.derive_address(network, source_index)
    private_key = keys.derive_private_key(network, source_index)
    source_script = utxo.script_pubkey(network, source)
    if source_script != b"\x00\x14" + utxo.hash160(utxo.public_key(private_key)):
        raise RuntimeError("Derived key does not match the source address")
    destination_script = utxo.script_pubkey(network, destination)

    with _send_lock(entry["chain"], source):
        r = requests.get(f"{base}/address/{source}/utxo", timeout=15)
        if not r.ok:
            return None
        coins = [{"txid": c["txid"], "vout": int(c["vout"]), "value": int(c["value"])} for c in r.json()]
        inputs, outputs = utxo.plan(
            coins, destination_script, source_script,
            _to_sats(amount), _to_sats(fee), utxo.MIN_RATE[network], fee_from_amount,
        )
        raw, txid = utxo.sign_p2wpkh(inputs, outputs, private_key)
        return _utxo_submit(base, raw, txid)


def _to_wei(fee_eth: str, gas: int) -> int:
    """Convert a total ETH fee into a per-gas wei price."""
    fee = Decimal(fee_eth) * Decimal(10 ** 18)
    if fee <= 0:
        raise ValueError("Fee must be positive")
    return int(fee // Decimal(gas))


_W3_CACHE: dict[str, Web3] = {}


def _w3(network: str) -> Optional[Web3]:
    chain = config.chain(network)
    if chain in _W3_CACHE:
        return _W3_CACHE[chain]
    url = config.rpc_url(network)
    if not url:
        return None
    if chain == "ethereum":
        if not Config.infura_project_id:
            return None
        auth = (Config.infura_project_id, Config.infura_project_secret) if Config.infura_project_secret else None
        w3 = Web3(Web3.HTTPProvider(url, request_kwargs={"auth": auth} if auth else {}))
    else:
        w3 = Web3(Web3.HTTPProvider(url))
    if not w3.is_connected():
        return None
    _W3_CACHE[chain] = w3
    return w3


def _nonce(w3: Web3, source: str) -> int:
    # Infura intermittently answers the "pending" tag with -32603 Internal
    # error. Sends are serialised by _send_lock, so "latest" is safe; a
    # collision with an unconfirmed tx is rejected by the node and retried.
    try:
        return w3.eth.get_transaction_count(source, "pending")
    except ValueError:
        return w3.eth.get_transaction_count(source, "latest")


def _estimate_erc20_gas(transfer_fn, source: str) -> int:
    try:
        estimate = transfer_fn.estimate_gas({"from": source})
    except Exception:
        return ERC20_GAS_LIMIT
    return max(ERC20_GAS_LIMIT, int(Decimal(estimate) * GAS_HEADROOM))


def _evm_token_transfer(network: str, source_index: int, destination: str, amount: str, fee_native: str) -> Optional[str]:
    w3 = _w3(network)
    if w3 is None:
        return None

    entry = config.network(network)
    source = Web3.to_checksum_address(keys.derive_address(network, source_index))
    source_private = keys.derive_private_key(network, source_index)

    contract = w3.eth.contract(address=Web3.to_checksum_address(entry["contract"]), abi=ERC20_ABI)
    value = int(Decimal(amount) * (10 ** entry["decimals"]))

    with _send_lock(entry["chain"], source):
        transfer = contract.functions.transfer(Web3.to_checksum_address(destination), value)
        gas = _estimate_erc20_gas(transfer, source)
        # The quote is the network price at quote time with no margin; a legacy
        # tx priced under a risen base fee is never mined. Keep 25% headroom.
        gas_price = max(_to_wei(fee_native, gas), w3.eth.gas_price * 5 // 4)
        nonce = _nonce(w3, source)
        tx = transfer.build_transaction({
            "from": source,
            "nonce": nonce,
            "gas": gas,
            "gasPrice": gas_price,
            "chainId": entry["chain_id"],
        })
        signed = w3.eth.account.sign_transaction(tx, source_private)
        return _evm_submit(w3, signed, tx, source)


def _erc20_transfer(source_index: int, destination: str, amount: str, fee_eth: str, network: str = "usdt_erc20") -> Optional[str]:
    return _evm_token_transfer(network, source_index, destination, amount, fee_eth)


def _evm_native_transfer(network: str, source_index: int, destination: str, amount: str, fee: str, *, deduct: str) -> Optional[str]:
    """deduct='fee': value = amount − fee (withdrawals; Laravel passes amount_sent + fee).
    deduct='gas': value = amount − 21000 × gas_price (sweeps; deposit holds exactly amount)."""
    w3 = _w3(network)
    if w3 is None:
        return None

    entry = config.network(network)
    source = Web3.to_checksum_address(keys.derive_address(network, source_index))
    source_private = keys.derive_private_key(network, source_index)

    with _send_lock(entry["chain"], source):
        # Same 25% headroom as token sends: an exact-price tx strands on a base-fee rise.
        gas_price = w3.eth.gas_price * 5 // 4
        value = w3.to_wei(amount, "ether")
        if deduct == "fee":
            value -= w3.to_wei(fee, "ether")
        elif deduct == "gas":
            value -= 21000 * gas_price
        else:
            raise ValueError(f"Invalid deduct mode: {deduct}")
        if value <= 0:
            raise ValueError("Amount must be greater than fee")
        nonce = _nonce(w3, source)
        tx = {
            "to": Web3.to_checksum_address(destination),
            "value": value,
            "gas": 21000,
            "gasPrice": gas_price,
            "nonce": nonce,
            "chainId": entry["chain_id"],
        }
        signed = w3.eth.account.sign_transaction(tx, source_private)
        return _evm_submit(w3, signed, tx, source)


def _eth_native_transfer(source_index: int, destination: str, amount_eth: str, fee_eth: str, network: str = "usdt_erc20") -> Optional[str]:
    w3 = _w3(network)
    if w3 is None:
        return None

    entry = config.network(network)
    source = Web3.to_checksum_address(keys.derive_address(network, source_index))
    source_private = keys.derive_private_key(network, source_index)

    with _send_lock(entry["chain"], source):
        nonce = _nonce(w3, source)
        tx = {
            "to": Web3.to_checksum_address(destination),
            "value": w3.to_wei(amount_eth, "ether"),
            "gas": 21000,
            "gasPrice": max(_to_wei(fee_eth, 21000), w3.eth.gas_price * 5 // 4),
            "nonce": nonce,
            "chainId": entry["chain_id"],
        }
        signed = w3.eth.account.sign_transaction(tx, source_private)
        return _evm_submit(w3, signed, tx, source)


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

    with _send_lock("tron", source):
        tx = client.trx.transfer(source, destination, amount_sun).fee_limit(fee_sun).build().sign(source_private)
        result = _tron_submit(client, tx, source, "trx", amount_sun=amount_sun, fee_sun=fee_sun)
        return result.get("txid") or result.txid


def _trc20_transfer(source_index: int, destination: str, amount: str, fee_trx: str) -> Optional[str]:
    client = _trx_client()
    source = keys.derive_address("usdt_trc20", source_index)
    source_private = PrivateKey(keys.derive_private_key("usdt_trc20", source_index))

    contract = client.get_contract(Config.trongrid_usdt_contract)
    value = _sun(amount)
    fee_sun = _sun(fee_trx)

    with _send_lock("tron", source):
        tx = contract.functions.transfer(destination, value).with_owner(source).fee_limit(fee_sun).build()
        signed_tx = tx.sign(source_private)
        result = _tron_submit(client, signed_tx, source, "trc20", destination=destination, amount_sun=value)
        return result.get("txid") or result.get("transaction", {}).get("txID")


def _trc20_required_sun(client, source: str, destination: str) -> int:
    """TRX the source must burn after its own/delegated resources are used."""
    energy_needed = fees.trc20_energy_estimate(client, source, destination)
    try:
        resource = client.get_account_resource(source)
    except AddressNotFound:
        resource = {}
    energy_available = int(resource.get("EnergyLimit", 0)) - int(resource.get("EnergyUsed", 0))
    bandwidth_available = (int(resource.get("NetLimit", 0)) - int(resource.get("NetUsed", 0))) + (
        int(resource.get("freeNetLimit", 0)) - int(resource.get("freeNetUsed", 0))
    )
    shortfall_energy = max(0, energy_needed - energy_available)
    required = shortfall_energy * fees._energy_price_sun(client)
    if bandwidth_available < fees.TRC20_BANDWIDTH_BYTES:
        required += fees.TRC20_BANDWIDTH_BYTES * fees.TRON_BANDWIDTH_PRICE_SUN
    return required


def _trc20_sweep(source_index: int, destination_index: int, amount: str, fee: str) -> Optional[str]:
    """Sweep TRC20 USDT. Laravel provisions TRX or rents energy; this only signs and broadcasts."""
    client = _trx_client()
    source = keys.derive_address("usdt_trc20", source_index)
    dest = keys.derive_address("usdt_trc20", destination_index)

    try:
        account = client.get_account(source)
        balance_sun = int(account.get("balance", 0)) if account else 0
    except AddressNotFound:
        balance_sun = 0

    required_sun = _trc20_required_sun(client, source, dest)
    if balance_sun < required_sun:
        raise InsufficientGas(
            f"{Decimal(required_sun) / Decimal(10 ** 6):.8f}",
            f"{Decimal(balance_sun) / Decimal(10 ** 6):.8f}",
        )

    return _trc20_transfer(source_index, dest, amount, fee)


def _evm_token_sweep(network: str, source_index: int, destination_index: int, amount: str, fee: str) -> Optional[str]:
    """Sweep an EVM token. Laravel provisions gas; this only signs and broadcasts."""
    w3 = _w3(network)
    if w3 is None:
        return None

    source = Web3.to_checksum_address(keys.derive_address(network, source_index))
    dest = Web3.to_checksum_address(keys.derive_address(network, destination_index))

    required_wei = int(Decimal(fee) * Decimal(10 ** 18))
    balance_wei = w3.eth.get_balance(source)
    if balance_wei < required_wei:
        raise InsufficientGas(
            f"{Decimal(required_wei) / Decimal(10 ** 18):.8f}",
            f"{Decimal(balance_wei) / Decimal(10 ** 18):.8f}",
        )

    return _evm_token_transfer(network, source_index, dest, amount, fee)


def _erc20_sweep(source_index: int, destination_index: int, amount: str, fee: str, network: str = "usdt_erc20") -> Optional[str]:
    """Sweep ERC-20 USDT. Laravel provisions gas; this only signs and broadcasts."""
    w3 = _w3(network)
    if w3 is None:
        return None

    source = Web3.to_checksum_address(keys.derive_address(network, source_index))
    dest = Web3.to_checksum_address(keys.derive_address(network, destination_index))

    required_wei = int(Decimal(fee) * Decimal(10 ** 18))
    balance_wei = w3.eth.get_balance(source)
    if balance_wei < required_wei:
        raise InsufficientGas(
            f"{Decimal(required_wei) / Decimal(10 ** 18):.8f}",
            f"{Decimal(balance_wei) / Decimal(10 ** 18):.8f}",
        )

    return _erc20_transfer(source_index, dest, amount, fee, network)


def broadcast_withdrawal(network: str, index: int, destination: str, amount: str, fee: str) -> Optional[str]:
    entry = config.network(network)
    if entry["family"] == "utxo":
        return _utxo_transfer(network, index, destination, amount, fee)
    if entry["family"] == "evm":
        if config.is_token(network):
            return _evm_token_transfer(network, index, destination, amount, fee)
        return _evm_native_transfer(network, index, destination, amount, fee, deduct="fee")
    if entry["family"] == "tron":
        return _trc20_transfer(index, destination, amount, fee)
    raise ValueError(f"Unsupported network: {network}")


def broadcast_sweep(network: str, source_index: int, destination_index: int, amount: str, fee: str) -> Optional[str]:
    entry = config.network(network)
    if entry["family"] == "utxo":
        destination = keys.derive_address(network, destination_index)
        return _utxo_transfer(network, source_index, destination, amount, fee, fee_from_amount=True)
    if entry["family"] == "evm":
        if config.is_token(network):
            return _evm_token_sweep(network, source_index, destination_index, amount, fee)
        destination = keys.derive_address(network, destination_index)
        return _evm_native_transfer(network, source_index, destination, amount, fee, deduct="gas")
    if entry["family"] == "tron":
        return _trc20_sweep(source_index, destination_index, amount, fee)
    raise ValueError(f"Unsupported network: {network}")


def get_native_balance(network: str, index: int) -> Optional[str]:
    entry = config.network(network)

    if entry["family"] == "utxo":
        address = keys.derive_address(network, index)
        try:
            response = requests.get(f"{entry['esplora_url']}/address/{address}", timeout=15)
        except Exception:
            return None
        if not response.ok:
            return None
        data = response.json()
        sats = sum(
            int(data.get(stats, {}).get("funded_txo_sum", 0)) - int(data.get(stats, {}).get("spent_txo_sum", 0))
            for stats in ("chain_stats", "mempool_stats")
        )
        return f"{Decimal(sats) / Decimal(10 ** 8):.8f}"

    if entry["family"] == "evm":
        w3 = _w3(network)
        if w3 is None:
            return None
        address = keys.derive_address(network, index)
        try:
            wei = w3.eth.get_balance(address)
            return f"{Decimal(wei) / Decimal(10 ** 18):.8f}"
        except Exception:
            return None

    if entry["family"] == "tron":
        client = _trx_client()
        address = keys.derive_address("usdt_trc20", index)
        try:
            account = client.get_account(address)
        except AddressNotFound:
            return "0.00000000"
        except Exception:
            return None
        if not account:
            return "0.00000000"
        return f"{Decimal(account.get('balance', 0)) / Decimal(10 ** 6):.8f}"

    return None


def get_token_balance(network: str, index: int) -> Optional[str]:
    entry = config.network(network)

    if entry["family"] == "evm" and config.is_token(network):
        w3 = _w3(network)
        if w3 is None:
            return None
        try:
            contract = w3.eth.contract(address=Web3.to_checksum_address(entry["contract"]), abi=ERC20_ABI)
            address = Web3.to_checksum_address(keys.derive_address(network, index))
            raw = contract.functions.balanceOf(address).call()
            return f"{Decimal(raw) / Decimal(10 ** entry['decimals']):.8f}"
        except Exception:
            return None

    if entry["family"] == "tron" and config.is_token(network):
        client = _trx_client()
        address = keys.derive_address("usdt_trc20", index)
        try:
            contract = client.get_contract(Config.trongrid_usdt_contract)
            raw = contract.functions.balanceOf(address)
            return f"{Decimal(raw) / Decimal(10 ** 6):.8f}"
        except AddressNotFound:
            return "0.00000000"
        except Exception:
            return None

    return None


def get_tron_resource(index: int) -> Optional[dict]:
    client = _trx_client()
    address = keys.derive_address("usdt_trc20", index)
    try:
        # getaccountresource returns global totals for unknown addresses, so
        # activation is probed with getaccount — it raises AddressNotFound.
        activated = bool(client.get_account(address))
        resource = client.get_account_resource(address)
        return {
            "energy_limit": resource.get("EnergyLimit", 0),
            "energy_used": resource.get("EnergyUsed", 0),
            "bandwidth_limit": resource.get("NetLimit", 0),
            "bandwidth_used": resource.get("NetUsed", 0),
            "free_bandwidth_limit": resource.get("freeNetLimit", 0),
            "free_bandwidth_used": resource.get("freeNetUsed", 0),
            "activated": activated,
        }
    except AddressNotFound:
        return {"energy_limit": 0, "energy_used": 0, "bandwidth_limit": 0, "bandwidth_used": 0,
                "free_bandwidth_limit": 0, "free_bandwidth_used": 0, "activated": False}
    except Exception:
        return None


def _evm_pending_receipt(w3: Web3, tx_hash: str) -> Optional[dict]:
    """Pending receipt plus `seen`: does the network know this tx at all?"""
    try:
        w3.eth.get_transaction(tx_hash)
        seen = True
    except TransactionNotFound:
        seen = False
    except Exception:
        return None
    return {"status": "pending", "fee": None, "confirmations": 0, "seen": seen}


def get_receipt(network: str, tx_hash: str) -> Optional[dict]:
    entry = config.network(network)

    if entry["family"] == "evm":
        w3 = _w3(network)
        if w3 is None:
            return None
        try:
            receipt = w3.eth.get_transaction_receipt(tx_hash)
        except TransactionNotFound:
            return _evm_pending_receipt(w3, tx_hash)
        except Exception:
            return None
        if receipt is None:
            return _evm_pending_receipt(w3, tx_hash)
        gas_price = receipt.effectiveGasPrice if receipt.effectiveGasPrice is not None else receipt.gasPrice
        fee_wei = receipt.gasUsed * gas_price
        fee = f"{Decimal(fee_wei) / Decimal(10 ** 18):.8f}"
        status = {1: "confirmed", 0: "failed"}.get(receipt.status, "pending")
        confirmations = 0
        try:
            current = w3.eth.block_number
            block = receipt.blockNumber if receipt.blockNumber is not None else 0
            confirmations = max(0, current - block + 1)
        except Exception:
            pass
        return {"status": status, "fee": fee, "confirmations": confirmations}

    if entry["family"] == "tron":
        client = _trx_client()
        try:
            info = client.get_transaction_info(tx_hash)
        except Exception:
            return None
        if info is None:
            return {"status": "pending", "fee": None, "confirmations": 0}
        fee_sun = info.get("fee", 0)
        fee = f"{Decimal(fee_sun) / Decimal(10 ** 6):.8f}"
        result = info.get("receipt", {}).get("result")
        if result is None:
            # native TRX transfers (e.g. gas top-ups) have no contract result field
            status = "confirmed" if info.get("blockNumber") else "pending"
        else:
            status = "confirmed" if result == "SUCCESS" else "failed"
        return {"status": status, "fee": fee, "confirmations": 20 if status == "confirmed" else 0}

    if entry["family"] == "utxo":
        base = entry["esplora_url"]
        try:
            response = requests.get(f"{base}/tx/{tx_hash}", timeout=15)
        except Exception:
            return None
        if response.status_code == 404:
            # Unknown to the network: broadcast dropped (or not yet propagated).
            return {"status": "pending", "fee": None, "confirmations": 0, "seen": False}
        if not response.ok:
            return None
        data = response.json()
        fee = f"{Decimal(data.get('fee', 0)) / Decimal(10 ** 8):.8f}"
        status = data.get("status") or {}
        if not status.get("confirmed"):
            return {"status": "pending", "fee": fee, "confirmations": 0}
        confirmations = 1
        try:
            tip = requests.get(f"{base}/blocks/tip/height", timeout=15)
            if tip.ok:
                confirmations = max(1, int(tip.text) - int(status.get("block_height", 0)) + 1)
        except Exception:
            pass
        return {"status": "confirmed", "fee": fee, "confirmations": confirmations}

    return None


def broadcast_topup(network: str, source_index: int, destination_index: int, amount: str, fee: str) -> Optional[str]:
    entry = config.network(network)
    if entry["family"] == "evm":
        destination = keys.derive_address(network, destination_index)
        return _eth_native_transfer(source_index, destination, amount, fee, network)
    if entry["family"] == "tron":
        return _trx_transfer(source_index, keys.derive_address("usdt_trc20", destination_index), amount, fee)
    return None


def speed_up(network: str, index: int, tx_hash: str) -> Optional[dict]:
    """Re-sign a stuck legacy EVM tx at ~125% of the max(old, network) gas price."""
    entry = config.network(network)
    if entry["family"] != "evm":
        raise ValueError("speed-up is only supported on EVM networks")
    w3 = _w3(network)
    if w3 is None:
        return None
    source = Web3.to_checksum_address(keys.derive_address(network, index))
    with _send_lock(entry["chain"], source):
        try:
            tx = w3.eth.get_transaction(tx_hash)
        except TransactionNotFound:
            return {"status": "not_found"}
        if tx.get("blockNumber") is not None:
            return {"status": "mined"}
        if tx.get("from") != source:
            raise ValueError("tx not from index")
        if tx.get("maxFeePerGas") is not None or tx.get("gasPrice") is None:
            return {"status": "not_replaceable", "reason": "type"}
        latest = w3.eth.get_transaction_count(source, "latest")
        if latest > tx["nonce"]:
            return {"status": "mined"}
        old_price = int(tx["gasPrice"])
        new_price = max(old_price * 5 // 4 + 1, w3.eth.gas_price * 5 // 4)
        cap = config.max_gas_price_wei(entry["chain"])
        if new_price > cap:
            new_price = cap
            if cap <= int(old_price * 11 / 10):
                return {"status": "not_replaceable", "reason": "price_cap"}
        required = int(tx["value"]) + int(tx["gas"]) * new_price
        balance = int(w3.eth.get_balance(source))
        if balance < required:
            return {
                "status": "insufficient_gas",
                "required": f"{Decimal(required) / Decimal(10 ** 18):.8f}",
                "available": f"{Decimal(balance) / Decimal(10 ** 18):.8f}",
            }
        new_tx = {
            "to": tx["to"],
            "value": tx["value"],
            "data": tx.get("input") or tx.get("data") or "0x",
            "gas": tx["gas"],
            "gasPrice": new_price,
            "nonce": tx["nonce"],
            "chainId": entry["chain_id"],
        }
        signed = w3.eth.account.sign_transaction(new_tx, keys.derive_private_key(network, index))
        new_hash = _evm_submit(w3, signed, new_tx, source)
        if not new_hash.startswith("0x"):
            new_hash = "0x" + new_hash
        return {
            "status": "replaced",
            "tx_hash": new_hash,
            "gas_price_gwei": str(Decimal(new_price) / Decimal(10 ** 9)),
            "old_gas_price_gwei": str(Decimal(old_price) / Decimal(10 ** 9)),
        }


ERC20_ABI = [
    {
        "constant": True,
        "inputs": [{"name": "_owner", "type": "address"}],
        "name": "balanceOf",
        "outputs": [{"name": "", "type": "uint256"}],
        "type": "function",
    },
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
