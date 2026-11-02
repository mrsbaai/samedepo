import hashlib
import hmac
import json
import time
from decimal import Decimal
from unittest.mock import MagicMock, patch

import pytest

from samedepo_signer import api as api_module
from samedepo_signer import transactions

SOURCE = "0x" + "11" * 20


@pytest.fixture
def dry_run():
    checks = []
    t1 = transactions._DRY_RUN.set(True)
    t2 = transactions._DRY_RUN_CHECKS.set(checks)
    yield checks
    transactions._DRY_RUN.reset(t1)
    transactions._DRY_RUN_CHECKS.reset(t2)


def _fake_w3():
    w3 = MagicMock()
    w3.eth.chain_id = 1
    w3.eth.gas_price = 1
    w3.eth.get_transaction_count.return_value = 7
    contract = MagicMock()
    transfer = MagicMock()
    transfer.estimate_gas.return_value = 40000
    transfer.build_transaction.side_effect = lambda tx: tx
    contract.functions.transfer.return_value = transfer
    w3.eth.contract.return_value = contract
    signed = MagicMock()
    signed.rawTransaction = b"raw"
    w3.eth.account.sign_transaction.return_value = signed
    w3.keccak.return_value.hex.return_value = "0xdryrun"
    w3.eth.call.return_value = b"\x00" * 31 + b"\x01"
    w3.eth.get_balance.return_value = 10 ** 18
    w3.eth.get_block.return_value = {"baseFeePerGas": 1}
    return w3


def _evm_patches(w3):
    return [
        patch.object(transactions, "_w3", return_value=w3),
        patch.object(transactions.keys, "derive_address", return_value=SOURCE),
        patch.object(transactions.keys, "derive_private_key", return_value=b"k" * 32),
        patch.object(transactions.Web3, "to_checksum_address", side_effect=lambda a: a),
        patch.object(transactions.Account, "recover_transaction", return_value=SOURCE),
    ]


def _stack(patches, fn):
    for p in patches:
        p.start()
    try:
        return fn()
    finally:
        for p in reversed(patches):
            p.stop()


def test_evm_dry_run_never_broadcasts_and_returns_would_be_txid(dry_run):
    w3 = _fake_w3()
    result = _stack(
        _evm_patches(w3),
        lambda: transactions._erc20_transfer(0, "0x" + "22" * 20, "10", "0.00001000"),
    )
    assert result == "0xdryrun"
    w3.eth.send_raw_transaction.assert_not_called()
    assert len(dry_run) >= 5
    assert all(c["ok"] for c in dry_run)
    assert {c["name"] for c in dry_run} >= {"sender", "eth_call", "balance", "base_fee", "nonce", "pending_tx_ahead"}


def test_evm_dry_run_flags_a_reverting_eth_call(dry_run):
    w3 = _fake_w3()
    w3.eth.call.side_effect = ValueError("execution reverted")
    _stack(
        _evm_patches(w3),
        lambda: transactions._erc20_transfer(0, "0x" + "22" * 20, "10", "0.00001000"),
    )
    w3.eth.send_raw_transaction.assert_not_called()
    failed = [c for c in dry_run if not c["ok"]]
    assert [c["name"] for c in failed] == ["eth_call"]


def test_evm_dry_run_flags_token_returning_false(dry_run):
    w3 = _fake_w3()
    w3.eth.call.return_value = b"\x00" * 32
    _stack(
        _evm_patches(w3),
        lambda: transactions._erc20_transfer(0, "0x" + "22" * 20, "10", "0.00001000"),
    )
    assert any(c["name"] == "eth_call" and c["ok"] is False for c in dry_run)


def test_evm_dry_run_flags_pending_tx_ahead(dry_run):
    w3 = _fake_w3()
    w3.eth.get_transaction_count.side_effect = lambda a, tag: 9 if tag == "pending" else 7
    _stack(
        _evm_patches(w3),
        lambda: transactions._erc20_transfer(0, "0x" + "22" * 20, "10", "0.00001000"),
    )
    names = {c["name"]: c["ok"] for c in dry_run}
    assert names["pending_tx_ahead"] is False


def test_evm_dry_run_pending_unavailable_is_ok(dry_run):
    w3 = _fake_w3()
    w3.eth.get_transaction_count.side_effect = lambda a, tag: (
        (_ for _ in ()).throw(ValueError("Internal error")) if tag == "pending" else 7
    )
    # nonce() also falls back to "latest" on the pending error.
    _stack(
        _evm_patches(w3),
        lambda: transactions._erc20_transfer(0, "0x" + "22" * 20, "10", "0.00001000"),
    )
    names = {c["name"]: c["ok"] for c in dry_run}
    assert names["pending_tx_ahead"] is True
    assert names["nonce"] is True


def _resp(status_code=200, json=None, text=""):
    response = MagicMock()
    response.status_code = status_code
    response.ok = status_code < 400
    response.json.return_value = json or []
    response.text = text
    return response


KEY = bytes.fromhex("11" * 32)
PUB = transactions.utxo.public_key(KEY)
from bip_utils import P2WPKHAddrEncoder  # noqa: E402

BTC_SOURCE = P2WPKHAddrEncoder.EncodeKey(PUB, hrp="bc")
SOURCE_SPK = b"\x00\x14" + transactions.utxo.hash160(PUB)
DEST_SPK = b"\x00\x14" + b"\x00" * 20


def _utxo_patches(post_response):
    utxos = [{"txid": "ab" * 32, "vout": 0, "value": 100000}]
    return [
        patch.object(transactions.keys, "derive_address", return_value=BTC_SOURCE),
        patch.object(transactions.keys, "derive_private_key", return_value=KEY),
        patch.object(transactions.utxo, "script_pubkey",
                     side_effect=lambda n, a: SOURCE_SPK if a == BTC_SOURCE else DEST_SPK),
        patch.object(transactions.requests, "get", return_value=_resp(200, utxos)),
        patch.object(transactions.requests, "post", return_value=post_response),
    ]


def test_utxo_dry_run_uses_txs_test_and_never_broadcasts(dry_run):
    post_resp = _resp(200, [{"allowed": True, "vsize": 141, "fees": {"base": 0.00000282}}])
    utxos = [{"txid": "ab" * 32, "vout": 0, "value": 100000}]
    with patch.object(transactions.keys, "derive_address", return_value=BTC_SOURCE), \
         patch.object(transactions.keys, "derive_private_key", return_value=KEY), \
         patch.object(transactions.utxo, "script_pubkey",
                      side_effect=lambda n, a: SOURCE_SPK if a == BTC_SOURCE else DEST_SPK), \
         patch.object(transactions.requests, "get", return_value=_resp(200, utxos)), \
         patch.object(transactions.requests, "post", return_value=post_resp) as post:
        result = transactions.broadcast_withdrawal("bitcoin", 0, "bc1qdest", "0.00050000", "0.00000282")
    assert isinstance(result, str) and len(result) == 64
    assert post.call_args.args[0].endswith("/txs/test")
    assert post.call_args.kwargs["json"][0]  # raw hex
    assert [c["name"] for c in dry_run] == ["testmempoolaccept"]
    assert dry_run[0]["ok"] is True


def test_utxo_dry_run_rejected_tx_fails(dry_run):
    post_resp = _resp(200, [{"allowed": False, "reject-reason": "min relay fee not met", "vsize": 141}])
    _stack(
        _utxo_patches(post_resp),
        lambda: transactions.broadcast_withdrawal("bitcoin", 0, "bc1qdest", "0.00050000", "0.00000282"),
    )
    assert dry_run[0]["ok"] is False
    assert "min relay fee not met" in dry_run[0]["detail"]


def _tron_client(kind="trc20"):
    client = MagicMock()
    client.get_account.return_value = {"balance": 5_000_000}
    client.get_account_resource.return_value = {
        "EnergyLimit": 100000, "EnergyUsed": 0, "freeNetLimit": 600, "freeNetUsed": 0,
    }
    client.provider.make_request.return_value = {
        "result": {"result": True},
        "constant_result": ["0" * 64],
        "energy_used": 64285,
        "transaction": {"ret": [{}]},
    }
    return client


def _signed_tx():
    tx = MagicMock()
    tx.txid = "ab" * 32
    tx.to_json.return_value = {"signature": ["11" * 65]}
    return tx


def _tron_patches(client):
    sig = MagicMock()
    sig.recover_public_key_from_msg_hash.return_value.to_base58check_address.return_value = "Tabc"
    return [
        patch.object(transactions, "_trx_client", return_value=client),
        patch.object(transactions.keys, "derive_address", return_value="Tabc"),
        patch.object(transactions.keys, "derive_private_key", return_value=bytes.fromhex("11" * 32)),
        patch.object(transactions, "Signature", return_value=sig),
        patch.object(transactions, "to_hex_address", return_value="41" + "00" * 20),
    ]


def test_trx_dry_run_never_broadcasts(dry_run):
    client = _tron_client()
    signed_tx = _signed_tx()
    builder = MagicMock()
    client.trx.transfer.return_value.fee_limit.return_value.build.return_value.sign.return_value = signed_tx
    result = _stack(
        _tron_patches(client),
        lambda: transactions._trx_transfer(0, "Tdest", "1.00000000", "0.00000100"),
    )
    assert result == "ab" * 32
    signed_tx.broadcast.assert_not_called()
    names = {c["name"]: c["ok"] for c in dry_run}
    assert names["sender"] is True and names["balance"] is True


def test_trc20_dry_run_constant_call_and_never_broadcasts(dry_run):
    client = _tron_client()
    signed_tx = _signed_tx()
    client.get_contract.return_value.functions.transfer.return_value \
        .with_owner.return_value.fee_limit.return_value.build.return_value.sign.return_value = signed_tx
    result = _stack(
        _tron_patches(client),
        lambda: transactions._trc20_transfer(0, "Tdest", "10.00000000", "30.00000000"),
    )
    assert result == "ab" * 32
    signed_tx.broadcast.assert_not_called()
    names = {c["name"]: c["ok"] for c in dry_run}
    assert names == {"sender": True, "constant_call": True, "bandwidth": True}
    call = client.provider.make_request.call_args
    assert call.args[0] == "wallet/triggerconstantcontract"
    assert call.args[1]["function_selector"] == "transfer(address,uint256)"


def test_trc20_dry_run_constant_call_revert_fails(dry_run):
    client = _tron_client()
    client.provider.make_request.return_value = {
        "result": {"result": True, "message": "REVERT opcode executed"},
        "constant_result": [""],
        "energy_used": 8624,
        "transaction": {"ret": [{"ret": "FAILED"}]},
    }
    signed_tx = _signed_tx()
    client.get_contract.return_value.functions.transfer.return_value \
        .with_owner.return_value.fee_limit.return_value.build.return_value.sign.return_value = signed_tx
    _stack(
        _tron_patches(client),
        lambda: transactions._trc20_transfer(0, "Tdest", "10.00000000", "30.00000000"),
    )
    names = {c["name"]: c["ok"] for c in dry_run}
    assert names["constant_call"] is False


@pytest.fixture
def api_client():
    api_module.app.config["TESTING"] = True
    api_module.Config.allowed_ips = ["127.0.0.1"]
    return api_module.app.test_client()


def _signed_post(client, path, payload):
    body = json.dumps(payload)
    ts = str(int(time.time()))
    sig = hmac.new(api_module.auth.API_KEY.encode(), f"{ts}.{body}".encode(), hashlib.sha256).hexdigest()
    return client.post(path, data=body, content_type="application/json",
                       headers={"X-Signer-Timestamp": ts, "X-Signer-Signature": sig},
                       environ_base={"REMOTE_ADDR": "127.0.0.1"})


def test_withdraw_dry_run_ignores_manual_approval(api_client):
    api_module.Config.manual_approval = True
    try:
        with patch.object(api_module.transactions, "broadcast_withdrawal", return_value="0xdry"):
            response = _signed_post(api_client, "/withdraw", {
                "network": "usdt_erc20", "index": 0, "destination": "0x" + "22" * 20,
                "amount": "5", "fee": "0.00001", "dry_run": True,
            })
    finally:
        api_module.Config.manual_approval = False
    assert response.status_code == 200
    data = response.get_json()["data"]
    assert data["dry_run"] is True
    assert data["ok"] is True
    assert data["tx_hash"] == "0xdry"
    assert data["checks"] == []
