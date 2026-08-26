"""Encrypted seed loading and HD key derivation."""
from __future__ import annotations

import hashlib

from bip_utils import Bip39SeedGenerator, Bip44, Bip44Coins, Bip44Changes, Bip84, Bip84Coins, Base58Encoder
from cryptography.fernet import Fernet
from mnemonic import Mnemonic

from samedepo_signer.config import wallet_enc_path, wallet_key_path

_NETWORK_COIN = {
    "usdt_erc20": (Bip44Coins.ETHEREUM, 0),
    "usdt_trc20": (Bip44Coins.TRON, 0),
    "usdt_base": (Bip44Coins.ETHEREUM, 1),
}


def _decrypt_seeds() -> str:
    key = wallet_key_path().read_bytes()
    cipher = Fernet(key)
    return cipher.decrypt(wallet_enc_path().read_bytes()).decode()


def _seed_for(network: str) -> str:
    text = _decrypt_seeds()
    blocks = {
        "bitcoin": "BITCOIN",
        "usdt_erc20": "ETHEREUM / USDT ERC20",
        "usdt_base": "ETHEREUM / USDT ERC20",
        "usdt_trc20": "TRON / USDT TRC20",
    }
    label = blocks[network]
    lines = text.splitlines()
    for i, line in enumerate(lines):
        if line.strip() == label:
            return lines[i + 1].strip()
    raise RuntimeError(f"Seed not found for {network}")


def _bip84_account():
    seed = _seed_for("bitcoin")
    seed_bytes = Bip39SeedGenerator(seed).Generate()
    return Bip84.FromSeed(seed_bytes, Bip84Coins.BITCOIN).Purpose().Coin().Account(0)


def _bip44_account(network: str):
    seed = _seed_for(network)
    seed_bytes = Bip39SeedGenerator(seed).Generate()
    coin, account = _NETWORK_COIN[network]
    return Bip44.FromSeed(seed_bytes, coin).Purpose().Coin().Account(account)


def get_xpub(network: str) -> str:
    if network == "bitcoin":
        return _bip84_account().PublicKey().ToExtended()
    return _bip44_account(network).PublicKey().ToExtended()


def derive_address(network: str, index: int) -> str:
    if network == "bitcoin":
        change = _bip84_account().Change(Bip44Changes.CHAIN_EXT)
        return change.AddressIndex(index).PublicKey().ToAddress()
    change = _bip44_account(network).Change(Bip44Changes.CHAIN_EXT)
    return change.AddressIndex(index).PublicKey().ToAddress()


def derive_private_key(network: str, index: int) -> bytes:
    if network == "bitcoin":
        change = _bip84_account().Change(Bip44Changes.CHAIN_EXT)
        return bytes.fromhex(change.AddressIndex(index).PrivateKey().Raw().ToHex())
    change = _bip44_account(network).Change(Bip44Changes.CHAIN_EXT)
    return bytes.fromhex(change.AddressIndex(index).PrivateKey().Raw().ToHex())


def derive_public_key(network: str, index: int) -> str:
    if network == "bitcoin":
        change = _bip84_account().Change(Bip44Changes.CHAIN_EXT)
        return change.AddressIndex(index).PublicKey().RawCompressed().ToHex()
    change = _bip44_account(network).Change(Bip44Changes.CHAIN_EXT)
    return change.AddressIndex(index).PublicKey().RawCompressed().ToHex()


def derive_wif(network: str, index: int) -> str:
    if network == "bitcoin":
        change = _bip84_account().Change(Bip44Changes.CHAIN_EXT)
        return change.AddressIndex(index).PrivateKey().ToWif()
    change = _bip44_account(network).Change(Bip44Changes.CHAIN_EXT)
    return change.AddressIndex(index).PrivateKey().ToWif()


def tron_address_from_eth(eth_address: str) -> str:
    """Convert Ethereum-format public address to Base58 TRON address."""
    payload = bytes.fromhex("41" + eth_address[2:])
    double = hashlib.sha256(hashlib.sha256(payload).digest()).digest()
    return Base58Encoder.CheckEncode(payload + double[:4])


def verify_mnemonic_words(mnemonic: str) -> bool:
    return Mnemonic("english").check(mnemonic)
