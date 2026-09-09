from unittest.mock import MagicMock, patch

import pytest

from samedepo_signer import transactions
from samedepo_signer.transactions import InsufficientGas


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
    with patch.object(transactions, "_trx_client", return_value=client), \
         patch.object(transactions.keys, "derive_address", return_value="Tabc"), \
         patch.object(transactions, "_trx_transfer") as topup:
        with pytest.raises(InsufficientGas) as exc:
            transactions._trc20_sweep(7, 0, "108", "20.00000000")
    assert exc.value.available == "0.00000000"
    topup.assert_not_called()
