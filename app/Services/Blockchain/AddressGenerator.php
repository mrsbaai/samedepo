<?php

declare(strict_types=1);

namespace App\Services\Blockchain;

use App\Support\Network;
use Nimiq\XPub;
use RuntimeException;

class AddressGenerator
{
    public function networks(): array
    {
        return Network::keys();
    }

    /**
     * Derive a deposit address for the given network key at the given index.
     * Networks sharing an `address_group` (e.g. all EVM networks) derive from
     * the same xpub path and therefore produce the same address.
     */
    public function generate(string $network, int $index): string
    {
        if (! Network::exists($network)) {
            throw new RuntimeException('Unsupported network: '.$network);
        }

        $group = Network::addressGroup($network);
        $xpubKey = Network::xpubEnv($network);
        $xpub = $xpubKey === null ? null : config($xpubKey);

        if (! is_string($xpub) || $xpub === '') {
            throw new RuntimeException('Missing extended public key configuration for network: '.$network);
        }

        return $this->deriveForGroup($group, $xpub, $index);
    }

    /**
     * Derive a treasury address from an xpub string directly (no config lookup).
     * Used when rotating or recovering treasury wallets.
     */
    public function deriveTreasuryAddress(string $network, int $index, ?string $xpub = null): string
    {
        $xpubKey = Network::xpubEnv($network);
        $xpub ??= $xpubKey === null ? null : config($xpubKey);

        if (! is_string($xpub) || $xpub === '') {
            throw new RuntimeException('Missing extended public key configuration for network: '.$network);
        }

        return $this->deriveForGroup(Network::addressGroup($network), $xpub, $index);
    }

    /**
     * Derive an address from an xpub for one registry address group. The
     * group's `driver` in `networks.address_groups` selects the encoding.
     */
    private function deriveForGroup(string $group, string $xpub, int $index): string
    {
        $meta = config("networks.address_groups.{$group}");
        $derived = fn () => XPub::fromString($xpub)->derive([0, $index]);

        return match ($meta['driver'] ?? null) {
            'xpub' => $derived()->toAddress($meta['to_address']),
            'litecoin_bech32' => $this->litecoinAddress($derived()),
            'tron' => $this->tronAddress($derived()->toAddress('eth')),
            default => throw new RuntimeException('Unsupported address group: '.$group),
        };
    }

    /**
     * BIP84-style Litecoin derivation: compressed pubkey → hash160 →
     * bech32 (witness v0) with the `ltc` human-readable part.
     */
    private function litecoinAddress(XPub $derived): string
    {
        $program = hex2bin(XPub::hash160($derived->K));

        if ($program === false) {
            throw new RuntimeException('Failed to hash the derived Litecoin public key.');
        }

        return \BitWasp\Bech32\encodeSegwit('ltc', 0, $program);
    }

    private function tronAddress(string $eth): string
    {
        // Convert the 20-byte EVM address to a TRON base58check address
        // (0x41 prefix + double-SHA256 checksum).
        $payload = hex2bin('41'.substr($eth, 2));
        $checksum = substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4);

        return self::base58Encode($payload.$checksum);
    }

    private static function base58Encode(string $bytes): string
    {
        $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        $num = '0';
        foreach (str_split($bytes) as $byte) {
            $num = bcadd(bcmul($num, '256', 0), (string) ord($byte), 0);
        }

        $encoded = '';
        while (bccomp($num, '0', 0) > 0) {
            $encoded = $alphabet[(int) bcmod($num, '58')].$encoded;
            $num = bcdiv($num, '58', 0);
        }

        foreach (str_split($bytes) as $byte) {
            if ($byte !== "\0") {
                break;
            }
            $encoded = '1'.$encoded;
        }

        return $encoded;
    }
}
