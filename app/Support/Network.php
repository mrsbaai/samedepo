<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\NetworkSetting;
use Illuminate\Database\QueryException;
use RuntimeException;

final class Network
{
    public static function all(): array
    {
        return config('networks.networks', []);
    }

    /**
     * Apply the `network_settings.enabled` overrides on top of the current
     * config registry. Runs once at application boot and after any toggle via
     * flush(); a null `enabled` column defers to the config/env flag.
     */
    public static function applyOverrides(): void
    {
        try {
            $overrides = NetworkSetting::query()
                ->whereNotNull('enabled')
                ->pluck('enabled', 'network');
        } catch (QueryException) {
            return;
        }

        if ($overrides->isEmpty()) {
            return;
        }

        $networks = config('networks.networks', []);
        foreach ($overrides as $key => $enabled) {
            if (isset($networks[$key])) {
                $networks[$key]['enabled'] = (bool) $enabled;
            }
        }

        config(['networks.networks' => $networks]);
    }

    /**
     * Re-merge the DB `enabled` overrides after writing a `network_settings`
     * row so the next registry read sees the toggle immediately.
     */
    public static function flush(): void
    {
        self::applyOverrides();
    }

    public static function enabled(): array
    {
        return array_filter(self::all(), fn (array $network): bool => (bool) ($network['enabled'] ?? false));
    }

    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function enabledKeys(): array
    {
        return array_keys(self::enabled());
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    public static function get(string $key): array
    {
        $network = self::all()[$key] ?? null;

        if ($network === null) {
            throw new RuntimeException("Unknown network: {$key}");
        }

        return $network;
    }

    public static function label(string $key): string
    {
        return self::get($key)['label'];
    }

    public static function symbol(string $key): string
    {
        return self::get($key)['symbol'];
    }

    public static function decimals(string $key): int
    {
        return (int) self::get($key)['decimals'];
    }

    public static function family(string $key): string
    {
        return self::get($key)['family'];
    }

    public static function chain(string $key): string
    {
        return self::get($key)['chain'];
    }

    public static function chainLabel(string $key): string
    {
        return config('networks.chains.'.self::chain($key), self::chain($key));
    }

    public static function kind(string $key): string
    {
        return self::get($key)['kind'];
    }

    public static function nativeKey(string $key): string
    {
        if (array_key_exists($key, self::natives())) {
            return $key;
        }

        return self::get($key)['native_key'];
    }

    public static function contract(string $key): ?string
    {
        return self::get($key)['contract'];
    }

    public static function tokenDecimals(string $key): ?int
    {
        $decimals = self::get($key)['token_decimals'];

        return $decimals === null ? null : (int) $decimals;
    }

    public static function confirmations(string $key): int
    {
        return (int) self::get($key)['confirmations'];
    }

    public static function scanInterval(string $key): int
    {
        return (int) self::get($key)['scan_interval'];
    }

    public static function provider(string $key): array
    {
        return self::get($key)['provider'];
    }

    public static function fallbackProvider(string $key): array
    {
        return self::get($key)['fallback_provider'] ?? [];
    }

    public static function explorerTx(string $key): string
    {
        return self::get($key)['explorer_tx'];
    }

    public static function addressGroup(string $key): string
    {
        return self::get($key)['address_group'];
    }

    public static function xpubEnv(string $key): ?string
    {
        return self::get($key)['xpub'] ?? null;
    }

    public static function coingeckoId(string $key): ?string
    {
        $natives = self::natives();

        if (array_key_exists($key, $natives)) {
            return $natives[$key]['coingecko_id'];
        }

        return self::get($key)['coingecko_id'] ?? null;
    }

    public static function nativeSymbol(string $key): string
    {
        if (self::exists($key)) {
            $nativeKey = self::get($key)['native_key'];

            if (self::get($key)['kind'] === 'native' && $nativeKey === $key) {
                return self::get($key)['symbol'];
            }

            return self::natives()[$nativeKey]['symbol'] ?? self::get($key)['symbol'];
        }

        return self::natives()[$key]['symbol'] ?? $key;
    }

    public static function isToken(string $key): bool
    {
        return self::kind($key) === 'token';
    }

    public static function isNative(string $key): bool
    {
        return self::kind($key) === 'native';
    }

    public static function isEvm(string $key): bool
    {
        return self::family($key) === 'evm';
    }

    public static function isStablecoin(string $key): bool
    {
        return self::exists($key) && in_array(self::symbol($key), ['USDT', 'USDC'], true);
    }

    public static function sameChainTokens(string $key): array
    {
        $chain = self::chain($key);

        return array_keys(array_filter(
            self::all(),
            fn (array $network): bool => $network['chain'] === $chain && $network['kind'] === 'token',
        ));
    }

    public static function present(string $key): array
    {
        $network = self::get($key);

        return [
            'key' => $key,
            'label' => $network['label'],
            'symbol' => $network['symbol'],
            'decimals' => (int) $network['decimals'],
            'slug' => $network['slug'],
            'chart_color' => $network['chart_color'] ?? 'zinc-400',
            'icon' => $network['icon'],
            'badge' => $network['kind'] === 'token' ? config('networks.chain_icons.'.$network['chain']) : null,
            'withdrawal_tip' => $network['withdrawal_tip'] ?? null,
        ];
    }

    public static function presentAll(bool $enabledOnly = false): array
    {
        $source = $enabledOnly ? self::enabled() : self::all();

        return array_combine(
            array_keys($source),
            array_map(fn (string $key): array => self::present($key), array_keys($source)),
        );
    }

    /**
     * @return array<int, string> URL slugs for all registered networks.
     */
    public static function slugs(): array
    {
        return array_map(
            fn (string $key): string => self::present($key)['slug'],
            self::keys(),
        );
    }

    /**
     * Example/truncated address for the network's address group (docs only).
     */
    public static function exampleAddress(string $key): ?string
    {
        return config('networks.address_groups.'.self::addressGroup($key).'.example_address');
    }

    public static function valuationKeys(): array
    {
        $keys = self::keys();

        foreach (self::natives() as $nativeKey => $native) {
            $keys[] = $nativeKey;
        }

        return array_values(array_unique($keys));
    }

    public static function natives(): array
    {
        return config('networks.natives', []);
    }

    /**
     * Chain for a network key or a native key (native_eth → ethereum).
     */
    public static function nativeChain(string $key): ?string
    {
        if (self::exists($key)) {
            return self::get($key)['chain'];
        }

        return self::natives()[$key]['chain'] ?? null;
    }

    /**
     * First enabled network key in an address group (one group = one address).
     */
    public static function enabledInGroup(string $addressGroup): ?string
    {
        foreach (self::enabled() as $key => $network) {
            if ($network['address_group'] === $addressGroup) {
                return $key;
            }
        }

        return null;
    }
}
