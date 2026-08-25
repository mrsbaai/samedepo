"""Encrypted seed loading and HD key derivation."""
from __future__ import annotations

import hashlib

from bip_utils import Bip39SeedGenerator, Bip44, Bip44Coins, Bip44Changes, Base58Encoder
from cryptography.fernet import Fernet
from mnemonic import Mnemonic

from samedepo_signer.config import wallet_enc_path, wallet_key_path

_NETWORK_COIN = {
    "bitcoin": Bip44Coins.BITCOIN,
    "usdt_erc20": Bip44Coins.ETHEREUM,
    "usdt_trc20": Bip44Coins.TRON,
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
        "usdt_trc20": "TRON / USDT TRC20",
    }
    label = blocks[network]
    lines = text.splitlines()
    for i, line in enumerate(lines):
        if line.strip() == label:
            return lines[i + 1].strip()
    raise RuntimeError(f"Seed not found for {network}")


def _bip44_account(network: str) -> Bip44:
    seed = _seed_for(network)
    seed_bytes = Bip39SeedGenerator(seed).Generate()
    coin = _NETWORK_COIN[network]
    return Bip44.FromSeed(seed_bytes, coin).Purpose().Coin().Account(0)


def get_xpub(network: str) -> str:
    return _bip44_account(network).PublicKey().ToExtended()


def derive_address(network: str, index: int) -> str:
    change = _bip44_account(network).Change(Bip44Changes.CHAIN_EXT)
    return change.AddressIndex(index).PublicKey().ToAddress()


def derive_private_key(network: str, index: int) -> bytes:
    change = _bip44_account(network).Change(Bip44Changes.CHAIN_EXT)
    return bytes.fromhex(change.AddressIndex(index).PrivateKey().Raw().ToHex())


def derive_wif(network: str, index: int) -> str:
    change = _bip44_account(network).Change(Bip44Changes.CHAIN_EXT)
    return change.AddressIndex(index).PrivateKey().ToWif()


def tron_address_from_eth(eth_address: str) -> str:
    """Convert Ethereum-format public address to Base58 TRON address."""
    payload = bytes.fromhex("41" + eth_address[2:])
    double = hashlib.sha256(hashlib.sha256(payload).digest()).digest()
    return Base58Encoder.CheckEncode(payload + double[:4])


def verify_mnemonic_words(mnemonic: str) -> bool:
    return Mnemonic("english").check(mnemonic)
