from unittest.mock import MagicMock, patch

import pytest

from samedepo_signer import fees, transactions
from samedepo_signer.transactions import InsufficientGas


@pytest.fixture(autouse=True)
def _clear_tron_caches():
    fees._fee_cache.clear()
    fees._energy_price_cache = None
    yield


def test_erc20_sweep_raises_insufficient_gas_and_never_tops_up():
    w3 = MagicMock()
    w3.eth.get_balance.return_value = 3_000_000_000_000
    with patch.object(transactions, "_w3", return_value=w3), \
         patch.object(transactions.keys, "derive_address", return_value="0x" + "11" * 20), \
         patch.object(transactions.Web3, "to_checksum_address", side_effect=lambda a: a), \
         patch.object(transactions, "_eth_native_transfer") as topup:
        with pytest.raises(InsufficientGas) as exc:
            transactions._erc20_sweep(2, 0, "19.7", "0.00001000")
    assert exc.value.required == "0.00001000"
    assert exc.value.available == "0.00000300"
    topup.assert_not_called()


def test_erc20_sweep_broadcasts_when_funded():
    w3 = MagicMock()
    w3.eth.get_balance.return_value = 30_000_000_000_000
    with patch.object(transactions, "_w3", return_value=w3), \
         patch.object(transactions.keys, "derive_address", return_value="0x" + "11" * 20), \
         patch.object(transactions.Web3, "to_checksum_address", side_effect=lambda a: a), \
         patch.object(transactions, "_erc20_transfer", return_value="0xhash") as send:
        assert transactions._erc20_sweep(2, 0, "19.7", "0.00001000") == "0xhash"
    send.assert_called_once()


def test_trc20_sweep_raises_insufficient_gas_for_inactive_address():
    from tronpy.exceptions import AddressNotFound
    client = MagicMock()
    client.get_account.side_effect = AddressNotFound()
    client.get_account_resource.side_effect = AddressNotFound()
    with patch.object(transactions, "_trx_client", return_value=client), \
         patch.object(transactions.keys, "derive_address", return_value="Tabc"), \
         patch.object(transactions, "_trc20_transfer") as topup:
        with pytest.raises(InsufficientGas) as exc:
            transactions._trc20_sweep(7, 0, "108", "20.00000000")
    assert exc.value.available == "0.00000000"
    topup.assert_not_called()


def _trc20_client(balance_sun=0, energy_used=64285, **resource):
    client = MagicMock()
    client.get_account.return_value = {"balance": balance_sun}
    client.trigger_constant_contract.return_value = {"energy_used": energy_used}
    client.get_chain_parameters.return_value = [{"key": "getEnergyFee", "value": 100}]
    client.get_account_resource.return_value = resource
    return client


def _run_sweep(client):
    # derive_address must return a valid base58 address: the energy estimate
    # ABI-encodes the destination and falls back to 130k on an invalid one.
    with patch.object(transactions, "_trx_client", return_value=client), \
         patch.object(transactions.keys, "derive_address",
                      return_value="TXsUrzCgNz21jm55zL9LDKxnPJEk7kaVna"), \
         patch.object(transactions, "_trc20_transfer", return_value="txid") as send:
        return send, transactions._trc20_sweep(7, 0, "108", "20.00000000")


def test_trc20_sweep_broadcasts_with_delegated_energy_and_no_trx():
    client = _trc20_client(balance_sun=0, EnergyLimit=70000, EnergyUsed=0,
                           NetLimit=0, NetUsed=0, freeNetLimit=600, freeNetUsed=0)
    send, result = _run_sweep(client)
    assert result == "txid"
    send.assert_called_once()


def test_trc20_sweep_raises_when_no_energy_and_no_trx():
    client = _trc20_client(balance_sun=0, EnergyLimit=0, EnergyUsed=0,
                           NetLimit=0, NetUsed=0, freeNetLimit=0, freeNetUsed=0)
    with pytest.raises(InsufficientGas) as exc:
        _run_sweep(client)
    assert exc.value.required == "6.77350000"
    assert exc.value.available == "0.00000000"


def test_trc20_sweep_required_counts_only_the_energy_shortfall():
    client = _trc20_client(balance_sun=0, EnergyLimit=30000, EnergyUsed=0,
                           NetLimit=0, NetUsed=0, freeNetLimit=600, freeNetUsed=0)
    with pytest.raises(InsufficientGas) as exc:
        _run_sweep(client)
    assert exc.value.required == "3.42850000"


def test_trc20_sweep_required_includes_bandwidth_when_free_used_up():
    client = _trc20_client(balance_sun=0, EnergyLimit=70000, EnergyUsed=0,
                           NetLimit=0, NetUsed=0, freeNetLimit=600, freeNetUsed=600)
    with pytest.raises(InsufficientGas) as exc:
        _run_sweep(client)
    assert exc.value.required == "0.34500000"
