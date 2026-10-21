from unittest.mock import MagicMock, patch

import pytest
from bip_utils import P2WPKHAddrEncoder
from ecdsa import SECP256k1, VerifyingKey
from ecdsa.util import sigdecode_der

from samedepo_signer import transactions, utxo

KEY = bytes.fromhex("11" * 32)
PUB = utxo.public_key(KEY)
SOURCE = P2WPKHAddrEncoder.EncodeKey(PUB, hrp="bc")
SOURCE_SPK = b"\x00\x14" + utxo.hash160(PUB)
DEST_SPK = bytes.fromhex("0014751e76e8199196d454941c45d1b3a323f1433bd6")


@pytest.mark.parametrize("address, spk", [
    ("bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4", "0014751e76e8199196d454941c45d1b3a323f1433bd6"),
    ("bc1p0xlxvlhemja6c4dqv22uapctqupfhlxm9h8z3k2e72q4k9hcz7vqzk5jj0",
     "512079be667ef9dcbbac55a06295ce870b07029bfcdb2dce28d959f2815b16f81798"),
    ("1BvBMSEYstWetqTFn5Au4m4GFg7xJaNVN2", "76a91477bff20c60e522dfaa3350c39b030a5d004e839a88ac"),
    ("3J98t1WpEZ73CNmQviecrnyiWrnqRhWNLy", "a914b472a266d0bd89c13706a4132ccfb16f7c3b9fcb87"),
])
def test_bitcoin_script_pubkeys(address, spk):
    assert utxo.script_pubkey("bitcoin", address).hex() == spk


def test_litecoin_native_segwit_round_trips():
    address = P2WPKHAddrEncoder.EncodeKey(PUB, hrp="ltc")
    assert utxo.script_pubkey("litecoin", address) == SOURCE_SPK


def test_bad_checksum_and_foreign_network_are_rejected():
    with pytest.raises(Exception):
        utxo.script_pubkey("bitcoin", "bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t5")
    with pytest.raises(Exception):
        utxo.script_pubkey("litecoin", "1BvBMSEYstWetqTFn5Au4m4GFg7xJaNVN2")


def _coins(*values):
    return [{"txid": f"{i + 1:064x}", "vout": i, "value": v} for i, v in enumerate(values)]


def test_withdrawal_sends_exact_amount_and_returns_change():
    inputs, outputs = utxo.plan(_coins(100000, 50000), DEST_SPK, SOURCE_SPK, 60282, 282, 2, False)
    assert [c["value"] for c in inputs] == [100000]
    assert outputs == [(DEST_SPK, 60000), (SOURCE_SPK, 39718)]


def test_withdrawal_takes_extra_input_fee_from_change_not_recipient():
    inputs, outputs = utxo.plan(_coins(30000, 30000, 30000), DEST_SPK, SOURCE_SPK, 70282, 282, 2, False)
    assert len(inputs) == 3
    assert outputs[0] == (DEST_SPK, 70000)
    assert 90000 - sum(v for _, v in outputs) == 2 * utxo.vsize(3, [DEST_SPK, SOURCE_SPK])


def test_sweep_takes_fee_from_amount_and_leaves_uncredited_coins():
    inputs, outputs = utxo.plan(_coins(25288, 10000), DEST_SPK, SOURCE_SPK, 25288, 282, 2, True)
    assert [c["value"] for c in inputs] == [25288]
    assert outputs == [(DEST_SPK, 25006)]


def test_dust_leftover_is_folded_into_the_fee():
    _, outputs = utxo.plan(_coins(60500), DEST_SPK, SOURCE_SPK, 60282, 282, 2, False)
    assert outputs == [(DEST_SPK, 60000)]


def test_insufficient_funds_raise():
    with pytest.raises(ValueError, match="insufficient funds"):
        utxo.plan(_coins(1000), DEST_SPK, SOURCE_SPK, 60282, 282, 2, False)


def _parse(raw: bytes):
    """Minimal segwit tx parser (all counts/lengths < 0xfd)."""
    assert raw[:6] == bytes.fromhex("020000000001") and raw[-4:] == bytes(4)
    pos = 6
    n_in = raw[pos]; pos += 1
    inputs = []
    for _ in range(n_in):
        inputs.append(raw[pos:pos + 36]); assert raw[pos + 36] == 0; pos += 41
    n_out = raw[pos]; pos += 1
    outputs = []
    for _ in range(n_out):
        value = int.from_bytes(raw[pos:pos + 8], "little"); length = raw[pos + 8]
        outputs.append((raw[pos + 9:pos + 9 + length], value)); pos += 9 + length
    stripped = raw[:4] + raw[6:pos] + raw[-4:]
    witnesses = []
    for _ in range(n_in):
        assert raw[pos] == 2; pos += 1
        sig = raw[pos + 1:pos + 1 + raw[pos]]; pos += 1 + raw[pos]
        pub = raw[pos + 1:pos + 1 + raw[pos]]; pos += 1 + raw[pos]
        witnesses.append((sig, pub))
    assert pos == len(raw) - 4
    return inputs, outputs, witnesses, stripped


def test_signed_transaction_has_valid_low_s_bip143_signatures():
    coins = _coins(70000, 40000)
    outputs = [(DEST_SPK, 100000), (SOURCE_SPK, 9000)]
    raw_hex, txid = utxo.sign_p2wpkh(coins, outputs, KEY)
    inputs, parsed_outputs, witnesses, stripped = _parse(bytes.fromhex(raw_hex))

    assert parsed_outputs == outputs
    assert utxo.dsha256(stripped)[::-1].hex() == txid

    # Independent BIP143 digest for each input.
    seq = bytes.fromhex("fdffffff")
    outs = b"".join(v.to_bytes(8, "little") + bytes([len(s)]) + s for s, v in outputs)
    vk = VerifyingKey.from_string(PUB, curve=SECP256k1)
    for outpoint, coin, (sig, pub) in zip(inputs, coins, witnesses):
        assert outpoint == bytes.fromhex(coin["txid"])[::-1] + coin["vout"].to_bytes(4, "little")
        assert pub == PUB and sig[-1] == 0x01
        preimage = (
            bytes.fromhex("02000000") + utxo.dsha256(b"".join(inputs)) + utxo.dsha256(seq * 2) + outpoint
            + b"\x19\x76\xa9\x14" + utxo.hash160(PUB) + b"\x88\xac"
            + coin["value"].to_bytes(8, "little") + seq + utxo.dsha256(outs) + bytes(4) + bytes.fromhex("01000000")
        )
        assert vk.verify_digest(sig[:-1], utxo.dsha256(preimage), sigdecode=sigdecode_der)
        _, s = sigdecode_der(sig[:-1], SECP256k1.order)
        assert s <= SECP256k1.order // 2


def _resp(status_code=200, json=None, text=""):
    response = MagicMock()
    response.status_code = status_code
    response.ok = status_code < 400
    response.json.return_value = json or []
    response.text = text
    return response


def _patched_keys():
    return patch.multiple(
        transactions.keys,
        derive_address=MagicMock(return_value=SOURCE),
        derive_private_key=MagicMock(return_value=KEY),
    )


def test_utxo_sweep_signs_locally_and_broadcasts_to_esplora():
    utxos = [{"txid": "ab" * 32, "vout": 0, "value": 25288, "status": {"confirmed": True}}]
    with _patched_keys(), \
            patch.object(transactions.requests, "get", return_value=_resp(200, utxos)) as get, \
            patch.object(transactions.requests, "post", return_value=_resp(200, text="cd" * 32)) as post:
        assert transactions.broadcast_sweep("bitcoin", 20, 0, "0.00025288", "0.00000282") == "cd" * 32
    assert get.call_args.args[0] == f"https://mempool.space/api/address/{SOURCE}/utxo"
    assert post.call_args.args[0] == "https://mempool.space/api/tx"
    raw = bytes.fromhex(post.call_args.kwargs["data"])
    assert (25006).to_bytes(8, "little") in raw


def test_utxo_broadcast_rejection_surfaces_the_node_error():
    utxos = [{"txid": "ab" * 32, "vout": 0, "value": 25288}]
    with _patched_keys(), \
            patch.object(transactions.requests, "get", return_value=_resp(200, utxos)), \
            patch.object(transactions.requests, "post", return_value=_resp(400, text="min relay fee not met")):
        with pytest.raises(ValueError, match="min relay fee not met"):
            transactions.broadcast_withdrawal(
                "bitcoin", 0, "bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4", "0.00010282", "0.00000282"
            )


def test_utxo_transfer_refuses_a_key_that_does_not_own_the_source():
    with patch.multiple(
        transactions.keys,
        derive_address=MagicMock(return_value="bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4"),
        derive_private_key=MagicMock(return_value=KEY),
    ), patch.object(transactions.requests, "get") as get:
        with pytest.raises(RuntimeError, match="does not match"):
            transactions.broadcast_sweep("bitcoin", 1, 0, "0.0001", "0.000003")
    get.assert_not_called()
