from decimal import Decimal
from unittest.mock import MagicMock, patch

import pytest
from tronpy.exceptions import AddressNotFound

from samedepo_signer import fees


def _rpc(wei: int):
    resp = MagicMock()
    resp.json.return_value = {"result": hex(wei)}
    resp.raise_for_status.return_value = None
    return resp


def test_erc20_fee_uses_65000_gas():
    with patch("samedepo_signer.fees.requests.post", return_value=_rpc(1_000_000_000)):
        assert fees._erc20() == f"{Decimal(1_000_000_000) * 65000 / Decimal(10**18):.8f}"


def test_eth_fee_uses_21000_gas():
    with patch("samedepo_signer.fees.requests.post", return_value=_rpc(1_000_000_000)):
        assert fees._eth() == f"{Decimal(1_000_000_000) * 21000 / Decimal(10**18):.8f}"


@pytest.fixture(autouse=True)
def _clear_tron_caches():
    fees._fee_cache.clear()
    fees._energy_price_cache = None
    yield


def _tron_client(energy_used=64285, account=None, account_error=None):
    client = MagicMock()
    client.trigger_constant_contract.return_value = {"energy_used": energy_used}
    client.get_chain_parameters.return_value = [{"key": "getEnergyFee", "value": 100}]
    if account_error is not None:
        client.get_account.side_effect = account_error
    else:
        client.get_account.return_value = account or {"balance": 1000000}
    return client


def _run_tron(client, **kwargs):
    with patch("samedepo_signer.transactions._trx_client", return_value=client), \
         patch.object(fees.keys, "derive_address", return_value="TSource"):
        return fees.estimate("usdt_trc20", **kwargs)


def test_trc20_holder_estimate():
    client = _tron_client(energy_used=64285)
    assert _run_tron(client, token_transfer=True, destination="TXsUrzCgNz21jm55zL9LDKxnPJEk7kaVna") == "6.77350000"


def test_trc20_estimate_failure_falls_back_to_130k():
    client = _tron_client()
    client.trigger_constant_contract.side_effect = Exception("boom")
    assert _run_tron(client, token_transfer=True, destination="TXsUrzCgNz21jm55zL9LDKxnPJEk7kaVna") == "13.34500000"


def test_trc20_native_without_destination():
    client = _tron_client()
    assert _run_tron(client, token_transfer=False) == "1.27000000"
    client.get_account.assert_not_called()


def test_trc20_native_existing_account():
    client = _tron_client()
    assert _run_tron(client, token_transfer=False, destination="TXsUrzCgNz21jm55zL9LDKxnPJEk7kaVna") == "0.27000000"


def test_trc20_native_new_account():
    client = _tron_client(account_error=AddressNotFound("account not found"))
    assert _run_tron(client, token_transfer=False, destination="TXsUrzCgNz21jm55zL9LDKxnPJEk7kaVna") == "1.27000000"


def test_bitcoin_and_erc20_unchanged():
    with patch.object(fees, "_btc", return_value="0.00001000"):
        assert fees.estimate("bitcoin") == "0.00001000"
    with patch.object(fees, "_erc20", return_value="0.00010000"):
        assert fees.estimate("usdt_erc20", token_transfer=True) == "0.00010000"


def test_second_call_within_60s_uses_cache():
    client = _tron_client(energy_used=64285)
    first = _run_tron(client, token_transfer=True, destination="TXsUrzCgNz21jm55zL9LDKxnPJEk7kaVna")
    second = _run_tron(client, token_transfer=True, destination="TXsUrzCgNz21jm55zL9LDKxnPJEk7kaVna")
    assert first == second == "6.77350000"
    assert client.trigger_constant_contract.call_count == 1
    assert client.get_chain_parameters.call_count == 1


def test_trc20_no_destination_token_transfer_uses_fallback():
    client = _tron_client()
    assert _run_tron(client, token_transfer=True) == "13.34500000"
    client.trigger_constant_contract.assert_not_called()
