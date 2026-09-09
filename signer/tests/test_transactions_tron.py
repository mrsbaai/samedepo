from unittest.mock import MagicMock, patch

from tronpy.exceptions import AddressNotFound

from samedepo_signer import transactions


def _client(side_effect=None, return_value=None):
    client = MagicMock()
    client.get_account.side_effect = side_effect
    if return_value is not None:
        client.get_account.return_value = return_value
    return client


def test_inactive_tron_address_returns_zero_balance():
    with patch.object(transactions, "_trx_client", return_value=_client(side_effect=AddressNotFound())), \
         patch.object(transactions.keys, "derive_address", return_value="Tabc"):
        assert transactions.get_native_balance("usdt_trc20", 7) == "0.00000000"


def test_network_error_returns_none():
    with patch.object(transactions, "_trx_client", return_value=_client(side_effect=RuntimeError("boom"))), \
         patch.object(transactions.keys, "derive_address", return_value="Tabc"):
        assert transactions.get_native_balance("usdt_trc20", 7) is None


def test_active_tron_address_returns_balance():
    with patch.object(transactions, "_trx_client", return_value=_client(return_value={"balance": 138_700_012})), \
         patch.object(transactions.keys, "derive_address", return_value="Tabc"):
        assert transactions.get_native_balance("usdt_trc20", 0) == "138.70001200"
