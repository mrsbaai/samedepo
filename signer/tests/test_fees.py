from decimal import Decimal
from unittest.mock import MagicMock, patch

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
