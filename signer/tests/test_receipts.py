from unittest.mock import MagicMock, patch

from samedepo_signer import transactions


def _resp(status_code=200, json=None):
    response = MagicMock()
    response.status_code = status_code
    response.ok = status_code < 400
    response.json.return_value = json or {}
    return response


def test_btc_receipt_404_is_pending():
    with patch.object(transactions.requests, "get", return_value=_resp(404)):
        assert transactions.get_receipt("bitcoin", "a" * 64) == {"status": "pending", "fee": None, "confirmations": 0}


def test_btc_receipt_confirmed_maps_fee_and_confirmations():
    with patch.object(transactions.requests, "get", return_value=_resp(200, {"confirmations": 3, "fees": 1940})):
        assert transactions.get_receipt("bitcoin", "a" * 64) == {"status": "confirmed", "fee": "0.00001940", "confirmations": 3}


def test_btc_receipt_unconfirmed_is_pending():
    with patch.object(transactions.requests, "get", return_value=_resp(200, {"confirmations": 0, "fees": 1940})):
        assert transactions.get_receipt("bitcoin", "a" * 64)["status"] == "pending"


def test_btc_receipt_server_error_is_none():
    with patch.object(transactions.requests, "get", return_value=_resp(500)):
        assert transactions.get_receipt("bitcoin", "a" * 64) is None


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
