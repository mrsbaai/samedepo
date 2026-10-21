from unittest.mock import MagicMock, patch

from samedepo_signer import transactions


def _resp(status_code=200, json=None, text=""):
    response = MagicMock()
    response.status_code = status_code
    response.ok = status_code < 400
    response.json.return_value = json or {}
    response.text = text
    return response


def test_btc_receipt_404_is_unseen_pending():
    with patch.object(transactions.requests, "get", return_value=_resp(404)):
        assert transactions.get_receipt("bitcoin", "a" * 64) == {"status": "pending", "fee": None, "confirmations": 0, "seen": False}


def test_btc_receipt_uses_esplora_without_token():
    with patch.object(transactions.requests, "get", return_value=_resp(404)) as get:
        transactions.get_receipt("bitcoin", "a" * 64)
    assert get.call_args.args[0] == "https://mempool.space/api/tx/" + "a" * 64


def test_btc_receipt_confirmed_maps_fee_and_confirmations():
    tx = _resp(200, {"fee": 1940, "status": {"confirmed": True, "block_height": 100}})
    with patch.object(transactions.requests, "get", side_effect=[tx, _resp(200, text="102")]):
        assert transactions.get_receipt("bitcoin", "a" * 64) == {"status": "confirmed", "fee": "0.00001940", "confirmations": 3}


def test_btc_receipt_unconfirmed_is_pending_and_seen():
    tx = _resp(200, {"fee": 1940, "status": {"confirmed": False}})
    with patch.object(transactions.requests, "get", return_value=tx):
        receipt = transactions.get_receipt("bitcoin", "a" * 64)
    assert receipt["status"] == "pending" and "seen" not in receipt


def test_btc_receipt_server_error_is_none():
    with patch.object(transactions.requests, "get", return_value=_resp(429)):
        assert transactions.get_receipt("bitcoin", "a" * 64) is None


def test_btc_balance_sums_chain_and_mempool():
    data = {
        "chain_stats": {"funded_txo_sum": 59985, "spent_txo_sum": 0},
        "mempool_stats": {"funded_txo_sum": 1000, "spent_txo_sum": 500},
    }
    with patch.object(transactions.keys, "derive_address", return_value="bc1qtest"), \
            patch.object(transactions.requests, "get", return_value=_resp(200, data)) as get:
        assert transactions.get_native_balance("bitcoin", 0) == "0.00060485"
    assert get.call_args.args[0] == "https://mempool.space/api/address/bc1qtest"


def _tron_client(info):
    client = MagicMock()
    client.get_transaction_info.return_value = info
    return client


def test_trc20_native_transfer_without_result_is_confirmed():
    info = {"id": "ab" * 32, "blockNumber": 123, "receipt": {"net_usage": 274}}
    with patch.object(transactions, "_trx_client", return_value=_tron_client(info)):
        assert transactions.get_receipt("usdt_trc20", "a" * 64) == {"status": "confirmed", "fee": "0.00000000", "confirmations": 20}


def test_trc20_reverted_contract_call_is_failed():
    info = {"id": "ab" * 32, "blockNumber": 123, "receipt": {"result": "REVERT"}, "fee": 4156000}
    with patch.object(transactions, "_trx_client", return_value=_tron_client(info)):
        assert transactions.get_receipt("usdt_trc20", "a" * 64)["status"] == "failed"


def test_trc20_successful_contract_call_is_confirmed():
    info = {"id": "ab" * 32, "blockNumber": 123, "receipt": {"result": "SUCCESS"}, "fee": 4156000}
    with patch.object(transactions, "_trx_client", return_value=_tron_client(info)):
        assert transactions.get_receipt("usdt_trc20", "a" * 64) == {"status": "confirmed", "fee": "4.15600000", "confirmations": 20}
