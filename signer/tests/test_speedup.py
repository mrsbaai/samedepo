from unittest.mock import MagicMock, patch

import pytest
from web3.exceptions import TransactionNotFound

from samedepo_signer import transactions
from samedepo_signer.config import Config

SOURCE = "0x" + "11" * 20
GWEI = 10 ** 9


def _tx(**overrides):
    tx = {
        "blockNumber": None,
        "from": SOURCE,
        "type": 0,
        "gasPrice": GWEI,
        "maxFeePerGas": None,
        "nonce": 5,
        "value": 10 ** 16,
        "gas": 21000,
        "to": "0x" + "22" * 20,
        "input": "0x",
    }
    tx.update(overrides)
    return tx


def _fake_w3(tx=None, gas_price=2 * GWEI, balance=10 ** 18, latest=5, get_raises=None):
    w3 = MagicMock()
    if get_raises is not None:
        w3.eth.get_transaction.side_effect = get_raises
    else:
        w3.eth.get_transaction.return_value = tx
    w3.eth.gas_price = gas_price
    w3.eth.get_transaction_count.return_value = latest
    w3.eth.get_balance.return_value = balance
    signed = MagicMock()
    signed.rawTransaction = b"raw"
    w3.eth.account.sign_transaction.return_value = signed
    w3.eth.send_raw_transaction.return_value.hex.return_value = "0xnewhash"
    return w3


def _run(w3, network="usdt_erc20"):
    with patch.object(transactions, "_w3", return_value=w3), \
         patch.object(transactions.keys, "derive_address", return_value=SOURCE), \
         patch.object(transactions.keys, "derive_private_key", return_value=b"k" * 32), \
         patch.object(transactions.Web3, "to_checksum_address", side_effect=lambda a: a):
        return transactions.speed_up(network, 0, "0x" + "ab" * 32)


def test_mined_tx_reports_mined():
    assert _run(_fake_w3(tx=_tx(blockNumber=123))) == {"status": "mined"}


def test_nonce_consumed_by_another_tx_reports_mined():
    assert _run(_fake_w3(tx=_tx(), latest=6)) == {"status": "mined"}


def test_unknown_tx_reports_not_found():
    w3 = _fake_w3(get_raises=TransactionNotFound("nope"))
    assert _run(w3) == {"status": "not_found"}


def test_tx_from_another_index_rejected():
    w3 = _fake_w3(tx=_tx(**{"from": "0x" + "99" * 20}))
    with pytest.raises(ValueError, match="tx not from index"):
        _run(w3)


def test_eip1559_tx_is_not_replaceable():
    w3 = _fake_w3(tx=_tx(maxFeePerGas=2 * GWEI, gasPrice=None, type=2))
    assert _run(w3) == {"status": "not_replaceable", "reason": "type"}


def test_replaced_uses_125_percent_of_the_higher_price():
    w3 = _fake_w3(tx=_tx(), gas_price=3 * GWEI)
    result = _run(w3)
    assert result["status"] == "replaced"
    assert result["tx_hash"] == "0xnewhash"
    new_tx = w3.eth.account.sign_transaction.call_args[0][0]
    assert new_tx["gasPrice"] == 3 * GWEI * 5 // 4
    assert new_tx["nonce"] == 5
    assert new_tx["to"] == "0x" + "22" * 20
    assert new_tx["value"] == 10 ** 16
    assert new_tx["gas"] == 21000
    assert new_tx["data"] == "0x"
    assert result["gas_price_gwei"] == "3.75"
    assert result["old_gas_price_gwei"] == "1"


def test_old_price_higher_than_network_uses_old_plus_25_percent():
    w3 = _fake_w3(tx=_tx(gasPrice=4 * GWEI), gas_price=GWEI)
    result = _run(w3)
    assert result["status"] == "replaced"
    new_tx = w3.eth.account.sign_transaction.call_args[0][0]
    assert new_tx["gasPrice"] == 4 * GWEI * 5 // 4 + 1


def test_price_cap_bounds_the_new_price():
    old = dict(Config.max_gas_price_gwei)
    Config.max_gas_price_gwei["ethereum"] = 3
    try:
        w3 = _fake_w3(tx=_tx(), gas_price=10 * GWEI)
        result = _run(w3)
    finally:
        Config.max_gas_price_gwei.update(old)
    assert result["status"] == "replaced"
    assert result["gas_price_gwei"] == "3"


def test_cap_at_or_below_110_percent_of_old_is_not_replaceable():
    old = dict(Config.max_gas_price_gwei)
    Config.max_gas_price_gwei["ethereum"] = 20
    try:
        w3 = _fake_w3(tx=_tx(gasPrice=20 * GWEI), gas_price=30 * GWEI)
        result = _run(w3)
    finally:
        Config.max_gas_price_gwei.update(old)
    assert result == {"status": "not_replaceable", "reason": "price_cap"}


def test_insufficient_balance_reports_insufficient_gas():
    w3 = _fake_w3(tx=_tx(), gas_price=2 * GWEI, balance=10 ** 15)
    result = _run(w3)
    assert result["status"] == "insufficient_gas"
    assert "required" in result and "available" in result


def test_evm_receipt_pending_reports_seen_true():
    w3 = MagicMock()
    w3.eth.get_transaction_receipt.side_effect = TransactionNotFound("nope")
    w3.eth.get_transaction.return_value = _tx()
    with patch.object(transactions, "_w3", return_value=w3):
        receipt = transactions.get_receipt("usdt_erc20", "0x" + "ab" * 32)
    assert receipt == {"status": "pending", "fee": None, "confirmations": 0, "seen": True}


def test_evm_receipt_unknown_reports_seen_false():
    w3 = MagicMock()
    w3.eth.get_transaction_receipt.side_effect = TransactionNotFound("nope")
    w3.eth.get_transaction.side_effect = TransactionNotFound("nope")
    with patch.object(transactions, "_w3", return_value=w3):
        receipt = transactions.get_receipt("usdt_erc20", "0x" + "ab" * 32)
    assert receipt == {"status": "pending", "fee": None, "confirmations": 0, "seen": False}
