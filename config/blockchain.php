<?php

declare(strict_types=1);

return [
    'price_feed' => [
        'url' => 'https://api.coingecko.com/api/v3/simple/price',
        'api_key' => env('COINGECKO_API_KEY'),
    ],

    // Confirmation thresholds and scan intervals live in the network
    // registry (config/networks.php) — read them via App\Support\Network.

    'provider_backoff' => [
        'base_minutes' => 2,
        'max_minutes' => 60,
    ],

    'tron_energy_price_sun' => env('TRON_ENERGY_PRICE_SUN', 100),

    // fee_limit on TRC20 token sends from the treasury. java-tron caps a tx's
    // usable energy (including delegated) at fee_limit / energyPrice — this is
    // a cap, not a cost, so it must cover the burn-equivalent energy.
    'trc20_fee_limit_trx' => env('TRC20_FEE_LIMIT_TRX', '30'),

    'gas_recovery' => [
        'min_native' => [
            'tron' => env('GAS_RECOVERY_MIN_NATIVE_TRC20', '5'),
            'bsc' => env('GAS_RECOVERY_MIN_NATIVE_BSC', '0.001'),
            'ethereum' => env('GAS_RECOVERY_MIN_NATIVE_ETH', '0.002'),
        ],
        // Native units left behind on the deposit address (covers the recovery
        // tx's own gas on EVM; bandwidth headroom on TRON) and the fee budget
        // passed to the signer for the recovery transfer.
        'leave_native' => [
            'tron' => env('GAS_RECOVERY_LEAVE_NATIVE_TRC20', '0.5'),
            'bsc' => env('GAS_RECOVERY_LEAVE_NATIVE_BSC', '0.0001'),
            'ethereum' => env('GAS_RECOVERY_LEAVE_NATIVE_ETH', '0.0003'),
        ],
        'fee_native' => [
            'tron' => env('GAS_RECOVERY_FEE_NATIVE_TRC20', '0.3'),
            'bsc' => env('GAS_RECOVERY_FEE_NATIVE_BSC', '0.0001'),
            'ethereum' => env('GAS_RECOVERY_FEE_NATIVE_ETH', '0.0003'),
        ],
    ],

    'bitcoin' => [
        'xpub' => env('BLOCKCHAIN_BITCOIN_XPUB'),
        'coin_type' => 0,
    ],
    'litecoin' => [
        'xpub' => env('BLOCKCHAIN_LITECOIN_XPUB'),
        'coin_type' => 2,
    ],
    'usdt_trc20' => [
        'xpub' => env('BLOCKCHAIN_USDT_TRC20_XPUB'),
        'coin_type' => 195,
    ],
    'usdt_erc20' => [
        'xpub' => env('BLOCKCHAIN_USDT_ERC20_XPUB'),
        'coin_type' => 60,
    ],

    'signer' => [
        'url' => env('SIGNER_URL'),
        'api_key' => env('SIGNER_API_KEY'),
    ],

    // Provider drivers and credentials live in the network registry
    // (config/networks.php `provider` entries).
];
