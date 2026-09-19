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


def test_trc20_token_balance():
    client = MagicMock()
    client.get_contract.return_value.functions.balanceOf.return_value = 13_500_000
    with patch.object(transactions, "_trx_client", return_value=client), \
         patch.object(transactions.keys, "derive_address", return_value="Tabc"):
        assert transactions.get_token_balance("usdt_trc20", 7) == "13.50000000"


def test_trc20_token_balance_inactive_address_is_zero():
    client = MagicMock()
    client.get_contract.return_value.functions.balanceOf.side_effect = AddressNotFound()
    with patch.object(transactions, "_trx_client", return_value=client), \
         patch.object(transactions.keys, "derive_address", return_value="Tabc"):
        assert transactions.get_token_balance("usdt_trc20", 7) == "0.00000000"


def test_tron_resource_includes_free_bandwidth():
    client = MagicMock()
    client.get_account_resource.return_value = {
        "EnergyLimit": 0, "EnergyUsed": 0,
        "NetLimit": 0, "NetUsed": 10,
        "freeNetLimit": 600, "freeNetUsed": 50,
    }
    with patch.object(transactions, "_trx_client", return_value=client), \
         patch.object(transactions.keys, "derive_address", return_value="Tabc"):
        resource = transactions.get_tron_resource(7)
    assert resource["free_bandwidth_limit"] == 600
    assert resource["free_bandwidth_used"] == 50


def test_tron_resource_inactive_address_has_zero_free_bandwidth():
    client = MagicMock()
    client.get_account_resource.side_effect = AddressNotFound()
    with patch.object(transactions, "_trx_client", return_value=client), \
         patch.object(transactions.keys, "derive_address", return_value="Tabc"):
        resource = transactions.get_tron_resource(7)
    assert resource["free_bandwidth_limit"] == 0
    assert resource["free_bandwidth_used"] == 0
