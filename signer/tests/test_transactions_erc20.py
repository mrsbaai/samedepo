from unittest.mock import MagicMock, patch

from samedepo_signer import transactions


def _fake_w3(estimate=None, estimate_raises=False):
    w3 = MagicMock()
    w3.eth.chain_id = 1
    w3.eth.get_transaction_count.return_value = 7
    contract = MagicMock()
    contract.functions.decimals.return_value.call.return_value = 6
    transfer = MagicMock()
    if estimate_raises:
        transfer.estimate_gas.side_effect = ValueError("insufficient funds")
    else:
        transfer.estimate_gas.return_value = estimate
    transfer.build_transaction.side_effect = lambda tx: tx
    contract.functions.transfer.return_value = transfer
    w3.eth.contract.return_value = contract
    signed = MagicMock()
    signed.rawTransaction = b"raw"
    w3.eth.account.sign_transaction.return_value = signed
    w3.eth.send_raw_transaction.return_value.hex.return_value = "0xabc"
    return w3, transfer


def _run(w3):
    with patch.object(transactions, "_w3", return_value=w3), \
         patch.object(transactions.keys, "derive_address", return_value="0x" + "11" * 20), \
         patch.object(transactions.keys, "derive_private_key", return_value=b"k" * 32), \
         patch.object(transactions.Web3, "to_checksum_address", side_effect=lambda a: a):
        return transactions._erc20_transfer(0, "0x" + "22" * 20, "10", "0.00001000")


def test_gas_floor_is_65000_when_estimate_is_lower():
    w3, transfer = _fake_w3(estimate=40000)
    assert _run(w3) == "0xabc"
    assert transfer.build_transaction.call_args[0][0]["gas"] == 65000


def test_gas_uses_1_2x_headroom_when_estimate_is_higher():
    w3, transfer = _fake_w3(estimate=70000)
    _run(w3)
    tx = transfer.build_transaction.call_args[0][0]
    assert tx["gas"] == 84000
    assert tx["gasPrice"] == transactions._to_wei("0.00001000", 84000)


def test_gas_falls_back_to_floor_when_estimate_raises():
    w3, transfer = _fake_w3(estimate_raises=True)
    _run(w3)
    assert transfer.build_transaction.call_args[0][0]["gas"] == 65000


def test_nonce_comes_from_pending_block():
    w3, _ = _fake_w3(estimate=60000)
    _run(w3)
    w3.eth.get_transaction_count.assert_called_with("0x" + "11" * 20, "pending")
