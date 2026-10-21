"""Local P2WPKH transaction building and signing for Bitcoin and Litecoin (BIP143)."""
from __future__ import annotations

import hashlib
import math

from bip_utils import Base58Decoder, SegwitBech32Decoder
from Crypto.Hash import RIPEMD160
from ecdsa import SECP256k1, SigningKey
from ecdsa.util import sigencode_der_canonize

HRP = {"bitcoin": "bc", "litecoin": "ltc"}
P2PKH_PREFIXES = {"bitcoin": {0x00}, "litecoin": {0x30}}
P2SH_PREFIXES = {"bitcoin": {0x05}, "litecoin": {0x32, 0x05}}
# Relay floor in sat(litoshi)/vB; a send never pays less than this whatever fee was requested.
MIN_RATE = {"bitcoin": 2, "litecoin": 10}
DUST = 546
SEQUENCE = 0xFFFFFFFD  # opt-in RBF


def dsha256(data: bytes) -> bytes:
    return hashlib.sha256(hashlib.sha256(data).digest()).digest()


def hash160(data: bytes) -> bytes:
    return RIPEMD160.new(hashlib.sha256(data).digest()).digest()


def public_key(private_key: bytes) -> bytes:
    return SigningKey.from_string(private_key, curve=SECP256k1).get_verifying_key().to_string("compressed")


def varint(n: int) -> bytes:
    if n < 0xFD:
        return bytes([n])
    if n <= 0xFFFF:
        return b"\xfd" + n.to_bytes(2, "little")
    return b"\xfe" + n.to_bytes(4, "little")


def script_pubkey(network: str, address: str) -> bytes:
    hrp = HRP[network]
    if address.lower().startswith(hrp + "1"):
        version, program = SegwitBech32Decoder.Decode(hrp, address)
        return bytes([0x50 + version if version else 0, len(program)]) + bytes(program)
    raw = Base58Decoder.CheckDecode(address)
    prefix, digest = raw[0], raw[1:]
    if len(digest) == 20 and prefix in P2PKH_PREFIXES[network]:
        return b"\x76\xa9\x14" + digest + b"\x88\xac"
    if len(digest) == 20 and prefix in P2SH_PREFIXES[network]:
        return b"\xa9\x14" + digest + b"\x87"
    raise ValueError(f"Unsupported {network} address: {address}")


def vsize(inputs: int, output_scripts: list[bytes]) -> int:
    """Upper-bound virtual size for P2WPKH inputs and the given outputs."""
    return 11 + 68 * inputs + sum(9 + len(spk) for spk in output_scripts)


def plan(
    coins: list[dict],
    destination: bytes,
    change: bytes,
    amount: int,
    fee: int,
    min_rate: int,
    fee_from_amount: bool,
) -> tuple[list[dict], list[tuple[bytes, int]]]:
    """Pick inputs and outputs, all values in satoshis.

    ``amount`` includes ``fee``. Withdrawals send exactly ``amount - fee``; any
    fee above that (relay floor, extra inputs) comes out of change. Sweeps
    (``fee_from_amount``) send ``amount`` minus the real fee. Leftover above
    dust returns to the change script.
    """
    selected: list[dict] = []
    total = 0
    for coin in sorted(coins, key=lambda c: c["value"], reverse=True):
        selected.append(coin)
        total += coin["value"]
        real_fee = max(fee, math.ceil(min_rate * vsize(len(selected), [destination, change])))
        send = amount - real_fee if fee_from_amount else amount - fee
        if total >= send + real_fee:
            break
    else:
        raise ValueError(f"insufficient funds: holds {total} sats, needs {amount}")

    if send < DUST:
        raise ValueError(f"amount after fee is dust: {send} sats")

    outputs = [(destination, send)]
    leftover = total - send - real_fee
    if leftover >= DUST:
        outputs.append((change, leftover))
    return selected, outputs


def sign_p2wpkh(coins: list[dict], outputs: list[tuple[bytes, int]], private_key: bytes) -> tuple[str, str]:
    """Return (raw_hex, txid) for a version-2 segwit transaction spending P2WPKH coins."""
    sk = SigningKey.from_string(private_key, curve=SECP256k1, hashfunc=hashlib.sha256)
    pub = sk.get_verifying_key().to_string("compressed")
    script_code = b"\x19\x76\xa9\x14" + hash160(pub) + b"\x88\xac"

    version = (2).to_bytes(4, "little")
    locktime = bytes(4)
    sequence = SEQUENCE.to_bytes(4, "little")
    outpoints = [bytes.fromhex(c["txid"])[::-1] + int(c["vout"]).to_bytes(4, "little") for c in coins]
    serialized_outputs = b"".join(value.to_bytes(8, "little") + varint(len(spk)) + spk for spk, value in outputs)

    hash_prevouts = dsha256(b"".join(outpoints))
    hash_sequence = dsha256(sequence * len(coins))
    hash_outputs = dsha256(serialized_outputs)

    witnesses = []
    for outpoint, coin in zip(outpoints, coins):
        preimage = (
            version + hash_prevouts + hash_sequence + outpoint + script_code
            + int(coin["value"]).to_bytes(8, "little") + sequence + hash_outputs + locktime
            + (1).to_bytes(4, "little")
        )
        signature = sk.sign_digest_deterministic(
            dsha256(preimage), hashfunc=hashlib.sha256, sigencode=sigencode_der_canonize
        ) + b"\x01"
        witnesses.append(b"\x02" + varint(len(signature)) + signature + varint(len(pub)) + pub)

    inputs = varint(len(coins)) + b"".join(outpoint + b"\x00" + sequence for outpoint in outpoints)
    body = inputs + varint(len(outputs)) + serialized_outputs
    txid = dsha256(version + body + locktime)[::-1].hex()
    raw = version + b"\x00\x01" + body + b"".join(witnesses) + locktime
    return raw.hex(), txid
