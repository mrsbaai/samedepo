"""Encrypted seed loading and HD key derivation."""
from __future__ import annotations

import hashlib

from bip_utils import Bip39SeedGenerator, Bip44, Bip44Changes, Bip84, Base58Encoder
from cryptography.fernet import Fernet
from mnemonic import Mnemonic

from samedepo_signer import config
from samedepo_signer.config import wallet_enc_path, wallet_key_path


def _decrypt_seeds() -> str:
    key = wallet_key_path().read_bytes()
    cipher = Fernet(key)
    return cipher.decrypt(wallet_enc_path().read_bytes()).decode()


def _seed_for(network: str) -> str:
    label = config.network(network)["seed_label"]
    lines = _decrypt_seeds().splitlines()
    for i, line in enumerate(lines):
        if line.strip() == label:
            return lines[i + 1].strip()
    raise RuntimeError(f"Seed not found for {label}")


def _account(network: str):
    entry = config.network(network)
    seed_bytes = Bip39SeedGenerator(_seed_for(network)).Generate()
    coin = config.coin(network)
    if entry["family"] == "utxo":
        return Bip84.FromSeed(seed_bytes, coin).Purpose().Coin().Account(0)
    return Bip44.FromSeed(seed_bytes, coin).Purpose().Coin().Account(0)


def get_xpub(network: str) -> str:
    return _account(network).PublicKey().ToExtended()


def _change(network: str):
    return _account(network).Change(Bip44Changes.CHAIN_EXT)


def derive_address(network: str, index: int) -> str:
    return _change(network).AddressIndex(index).PublicKey().ToAddress()


def derive_private_key(network: str, index: int) -> bytes:
    return bytes.fromhex(_change(network).AddressIndex(index).PrivateKey().Raw().ToHex())


def derive_public_key(network: str, index: int) -> str:
    return _change(network).AddressIndex(index).PublicKey().RawCompressed().ToHex()


def derive_wif(network: str, index: int) -> str:
    return _change(network).AddressIndex(index).PrivateKey().ToWif()


def tron_address_from_eth(eth_address: str) -> str:
    """Convert Ethereum-format public address to Base58 TRON address."""
    payload = bytes.fromhex("41" + eth_address[2:])
    double = hashlib.sha256(hashlib.sha256(payload).digest()).digest()
    return Base58Encoder.CheckEncode(payload + double[:4])


def verify_mnemonic_words(mnemonic: str) -> bool:
    return Mnemonic("english").check(mnemonic)
