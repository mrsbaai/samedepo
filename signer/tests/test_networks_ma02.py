"""Feature 023 / MA02 — chain-family registry tests."""
from decimal import Decimal
from unittest.mock import MagicMock, patch

import pytest

from samedepo_signer import config, fees, keys, transactions

TEST_MNEMONIC = (
    "abandon abandon abandon abandon abandon abandon "
    "abandon abandon abandon abandon abandon about"
)

NETWORK_KEYS = [
    "bitcoin", "litecoin", "ethereum", "usdt_erc20", "usdc_erc20",
    "usdt_trc20", "usdt_bep20", "usdc_bep20", "bnb",
]


@pytest.fixture(autouse=True)
def _clear_caches():
    fees._fee_cache.clear()
    transactions._W3_CACHE.clear()
    yield


@pytest.fixture
def test_seed(monkeypatch):
    monkeypatch.setattr(keys, "_seed_for", lambda network: TEST_MNEMONIC)


def test_registry_has_all_nine_networks():
    assert sorted(config.NETWORKS.keys()) == sorted(NETWORK_KEYS)
    for key in NETWORK_KEYS:
        entry = config.NETWORKS[key]
        for field in ("family", "kind", "chain", "address_group", "seed_label"):
            assert field in entry, f"{key}.{field} missing"


def test_evm_networks_share_seed_label():
    labels = {config.NETWORKS[k]["seed_label"] for k in
              ("ethereum", "usdt_erc20", "usdc_erc20", "usdt_bep20", "usdc_bep20", "bnb")}
    assert labels == {"ETHEREUM / USDT ERC20"}


def test_network_helper_and_unknown_key():
    assert config.is_token("usdt_bep20")
    assert config.is_native("litecoin")
    assert config.family("usdt_trc20") == "tron"
    with pytest.raises(RuntimeError):
        config.network("doge")


def test_litecoin_derives_ltc1(test_seed):
    address = keys.derive_address("litecoin", 0)
    assert address.startswith("ltc1")
    assert keys.derive_address("litecoin", 0) == address
    assert keys.derive_address("litecoin", 1) != address


def test_litecoin_xpub_is_parseable_by_laravel(test_seed):
    # bip-utils emits the SLIP-132 "zpub" version for BIP84; Laravel's nimiq/xpub
    # accepts that encoding (verified in MA03 against AddressGenerator).
    assert keys.get_xpub("litecoin").startswith("zpub")


def test_eth_and_bsc_derive_same_address(test_seed):
    for index in (0, 3, 7):
        expected = keys.derive_address("ethereum", index)
        assert keys.derive_address("usdt_bep20", index) == expected
        assert keys.derive_address("usdc_bep20", index) == expected
        assert keys.derive_address("usdc_erc20", index) == expected
        assert keys.derive_address("bnb", index) == expected


def test_missing_seed_raises_only_on_use():
    def _seeds(network):
        if network == "litecoin":
            raise RuntimeError("Seed not found for LITECOIN")
        return TEST_MNEMONIC

    with patch.object(keys, "_seed_for", side_effect=_seeds):
        assert keys.derive_address("ethereum", 0).startswith("0x")
        with pytest.raises(RuntimeError):
            keys.derive_address("litecoin", 0)


def _fake_evm_w3(chain_id):
    w3 = MagicMock()
    w3.is_connected.return_value = True
    w3.eth.chain_id = chain_id
    w3.eth.get_transaction_count.return_value = 3
    w3.eth.gas_price = 1_000_000_000
    w3.to_wei.side_effect = lambda v, u: int(Decimal(v) * 10 ** 18)
    contract = MagicMock()
    transfer = MagicMock()
    transfer.estimate_gas.return_value = 40000
    transfer.build_transaction.side_effect = lambda tx: tx
    contract.functions.transfer.return_value = transfer
    w3.eth.contract.return_value = contract
    signed = MagicMock()
    signed.rawTransaction = b"raw"
    w3.eth.account.sign_transaction.return_value = signed
    w3.eth.send_raw_transaction.return_value.hex.return_value = "0xdead"
    return w3, transfer


def _evm_call(network, amount="10", fee="0.00001000", index=0):
    with patch.object(transactions, "_w3", return_value=_fake_evm_w3(config.NETWORKS[network]["chain_id"])[0]) as w3_mock:
        w3, transfer = _fake_evm_w3(config.NETWORKS[network]["chain_id"])
        w3_mock.return_value = w3
        with patch.object(transactions.keys, "derive_address", return_value="0x" + "11" * 20), \
             patch.object(transactions.keys, "derive_private_key", return_value=b"k" * 32), \
             patch.object(transactions.Web3, "to_checksum_address", side_effect=lambda a: a):
            return w3, transfer, transactions._evm_token_transfer(network, index, "0x" + "22" * 20, amount, fee)


def test_usdt_bep20_builds_chainid_56_and_18_decimals():
    w3, transfer, tx_hash = _evm_call("usdt_bep20")
    assert tx_hash == "0xdead"
    contract = w3.eth.contract.call_args[1]["address"]
    assert contract == "0x55d398326f99059fF775485246999027B3197955"
    transfer_call = w3.eth.contract.return_value.functions.transfer.call_args
    assert transfer_call[0][1] == 10 * 10 ** 18
    tx = transfer.build_transaction.call_args[0][0]
    assert tx["chainId"] == 56


def test_usdc_erc20_uses_6_decimals():
    w3, transfer, _ = _evm_call("usdc_erc20")
    transfer_call = w3.eth.contract.return_value.functions.transfer.call_args
    assert transfer_call[0][1] == 10 * 10 ** 6
    tx = transfer.build_transaction.call_args[0][0]
    assert tx["chainId"] == 1


def test_native_eth_sweep_sends_amount_minus_gas():
    w3, _ = _fake_evm_w3(1)
    w3.eth.gas_price = 1_000_000_000
    with patch.object(transactions, "_w3", return_value=w3), \
         patch.object(transactions.keys, "derive_address", return_value="0x" + "11" * 20), \
         patch.object(transactions.keys, "derive_private_key", return_value=b"k" * 32), \
         patch.object(transactions.Web3, "to_checksum_address", side_effect=lambda a: a):
        assert transactions._evm_native_transfer("ethereum", 0, "0x" + "22" * 20, "0.01", "0", deduct="gas") == "0xdead"
    built = w3.eth.account.sign_transaction.call_args[0][0]
    assert built["gas"] == 21000
    expected_value = int(Decimal("0.01") * 10 ** 18) - 21000 * 1_000_000_000
    assert built["value"] == expected_value


def test_native_eth_withdrawal_sends_amount_minus_fee():
    """Laravel passes amount_sent + fee for native networks; recipient gets amount_sent."""
    w3, _ = _fake_evm_w3(1)
    w3.eth.gas_price = 1_000_000_000
    with patch.object(transactions, "_w3", return_value=w3), \
         patch.object(transactions.keys, "derive_address", return_value="0x" + "11" * 20), \
         patch.object(transactions.keys, "derive_private_key", return_value=b"k" * 32), \
         patch.object(transactions.Web3, "to_checksum_address", side_effect=lambda a: a):
        transactions._evm_native_transfer("ethereum", 0, "0x" + "22" * 20, "0.101", "0.001", deduct="fee")
    built = w3.eth.account.sign_transaction.call_args[0][0]
    assert built["value"] == int(Decimal("0.1") * 10 ** 18)


def test_native_bnb_withdrawal_builds_chainid_56_deduct_fee():
    w3, _ = _fake_evm_w3(56)
    w3.eth.gas_price = 1_000_000_000
    with patch.object(transactions, "_w3", return_value=w3), \
         patch.object(transactions.keys, "derive_address", return_value="0x" + "11" * 20), \
         patch.object(transactions.keys, "derive_private_key", return_value=b"k" * 32), \
         patch.object(transactions.Web3, "to_checksum_address", side_effect=lambda a: a):
        transactions._evm_native_transfer("bnb", 0, "0x" + "22" * 20, "0.101", "0.001", deduct="fee")
    built = w3.eth.account.sign_transaction.call_args[0][0]
    assert built["chainId"] == 56
    assert built["gas"] == 21000
    assert built["value"] == int(Decimal("0.1") * 10 ** 18)


def test_native_eth_sweep_rejects_when_amount_covers_no_gas():
    w3, _ = _fake_evm_w3(1)
    w3.eth.gas_price = 1_000_000_000
    with patch.object(transactions, "_w3", return_value=w3), \
         patch.object(transactions.keys, "derive_address", return_value="0x" + "11" * 20), \
         patch.object(transactions.keys, "derive_private_key", return_value=b"k" * 32), \
         patch.object(transactions.Web3, "to_checksum_address", side_effect=lambda a: a):
        with pytest.raises(ValueError):
            transactions._evm_native_transfer("ethereum", 0, "0x" + "22" * 20, "0.00001", "0", deduct="gas")


def _rpc(wei):
    resp = MagicMock()
    resp.json.return_value = {"result": hex(wei)}
    resp.raise_for_status.return_value = None
    return resp


def test_fee_ethereum_uses_21000_gas():
    with patch("samedepo_signer.fees.requests.post", return_value=_rpc(1_000_000_000)):
        assert fees.estimate("ethereum")["fee"] == f"{Decimal(1_000_000_000) * 21000 / Decimal(10**18):.8f}"


def test_fee_usdc_erc20_uses_token_gas_floor():
    with patch("samedepo_signer.fees.requests.post", return_value=_rpc(1_000_000_000)):
        assert fees.estimate("usdc_erc20", token_transfer=True)["fee"] == f"{Decimal(1_000_000_000) * 65000 / Decimal(10**18):.8f}"


def test_fee_usdt_bep20_hits_bsc_rpc():
    with patch("samedepo_signer.fees.requests.post", return_value=_rpc(1_000_000_000)) as post, \
         patch.dict("os.environ", {"BSC_RPC_URL": "https://bsc.example/rpc"}):
        assert fees.estimate("usdt_bep20", token_transfer=True)["fee"] == f"{Decimal(1_000_000_000) * 65000 / Decimal(10**18):.8f}"
    assert post.call_args[0][0] == "https://bsc.example/rpc"


def test_fee_bnb_uses_21000_gas_on_bsc_rpc():
    with patch("samedepo_signer.fees.requests.post", return_value=_rpc(1_000_000_000)) as post, \
         patch.dict("os.environ", {"BSC_RPC_URL": "https://bsc.example/rpc"}):
        assert fees.estimate("bnb")["fee"] == f"{Decimal(1_000_000_000) * 21000 / Decimal(10**18):.8f}"
    assert post.call_args[0][0] == "https://bsc.example/rpc"


def test_fee_litecoin_esplora_half_hour():
    resp = MagicMock()
    resp.json.return_value = {"halfHourFee": 10}
    resp.raise_for_status.return_value = None
    with patch("samedepo_signer.fees.requests.get", return_value=resp):
        expected = max(Decimal(10) * 141 / Decimal(10 ** 8), Decimal("0.0001"))
        assert fees.estimate("litecoin")["fee"] == f"{expected:.8f}"


def test_fee_litecoin_floors_at_0_0001():
    resp = MagicMock()
    resp.json.return_value = {"halfHourFee": 1}
    resp.raise_for_status.return_value = None
    with patch("samedepo_signer.fees.requests.get", return_value=resp):
        assert fees.estimate("litecoin")["fee"] == "0.00010000"


def test_fee_response_includes_native_symbol():
    from samedepo_signer import api as api_module
    assert config.native_symbol("litecoin") == "LTC"
    assert config.native_symbol("usdt_bep20") == "BNB"
    assert config.native_symbol("usdt_erc20") == "ETH"


def test_bitcoin_native_balance_via_blockcypher():
    resp = MagicMock()
    resp.ok = True
    resp.status_code = 200
    resp.json.return_value = {"balance": 100_000, "final_balance": 150_000}
    with patch.object(transactions.requests, "get", return_value=resp) as get, \
         patch.object(transactions.keys, "derive_address", return_value="bc1qxyz"):
        assert transactions.get_native_balance("bitcoin", 0) == "0.00150000"
    assert "/v1/btc/main/addrs/bc1qxyz/balance" in get.call_args[0][0]


def test_litecoin_native_balance_via_blockcypher():
    resp = MagicMock()
    resp.ok = True
    resp.status_code = 200
    resp.json.return_value = {"final_balance": 500_000}
    with patch.object(transactions.requests, "get", return_value=resp) as get, \
         patch.object(transactions.keys, "derive_address", return_value="ltc1qxyz"):
        assert transactions.get_native_balance("litecoin", 0) == "0.00500000"
    assert "/v1/ltc/main/addrs/ltc1qxyz/balance" in get.call_args[0][0]


def test_utxo_transfer_uses_blockcypher_coin():
    new_resp = MagicMock(ok=True, status_code=200)
    new_resp.json.return_value = {"tosign": ["ab" * 32]}
    send_resp = MagicMock(ok=True, status_code=200)
    send_resp.json.return_value = {"tx": {"hash": "cafe"}}
    with patch.object(transactions.requests, "post", side_effect=[new_resp, send_resp]) as post, \
         patch.object(transactions.keys, "derive_address", return_value="ltc1qsrc"), \
         patch.object(transactions.keys, "derive_private_key", return_value=b"k" * 32), \
         patch.object(transactions.keys, "derive_public_key", return_value="02" + "11" * 32):
        assert transactions._utxo_transfer("litecoin", 0, "ltc1qdst", "1.0", "0.0001") == "cafe"
    assert "/v1/ltc/main/txs/new" in post.call_args_list[0][0][0]
    assert "/v1/ltc/main/txs/send" in post.call_args_list[1][0][0]
