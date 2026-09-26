<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Blockchain network registry
    |--------------------------------------------------------------------------
    |
    | Every supported deposit/withdrawal network is defined here. Application
    | code must read network metadata through App\Support\Network instead of
    | hardcoding network keys, labels, decimals, or contract addresses.
    |
    */

    'networks' => [

        'bitcoin' => [
            'label' => 'Bitcoin',
            'symbol' => 'BTC',
            'decimals' => 8,
            'family' => 'utxo',
            'chain' => 'bitcoin',
            'kind' => 'native',
            'native_key' => 'bitcoin',
            'contract' => null,
            'token_decimals' => null,
            'confirmations' => 3,
            'scan_interval' => 15,
            'provider' => [
                'driver' => 'esplora',
                'base_url' => env('MEMPOOL_API_URL', 'https://mempool.space/api'),
            ],
            'fallback_provider' => [
                'driver' => 'esplora',
                'base_url' => env('BITCOIN_FALLBACK_API_URL', 'https://blockstream.info/api'),
            ],
            'coingecko_id' => 'bitcoin',
            'explorer_tx' => 'https://mempool.space/tx/{hash}',
            'address_group' => 'bitcoin',
            'xpub' => 'blockchain.bitcoin.xpub',
            'slug' => 'bitcoin',
            'chart_color' => 'orange-500',
            'icon' => 'btc',
            'withdrawal_tip' => 'Tip: a native SegWit (bc1…) destination gives the smallest transaction and the lowest fee.',
            'enabled' => env('NETWORK_BITCOIN_ENABLED', true),
            'settings' => ['min_deposit' => '0.00010000', 'withdrawal_min_usd' => '100.00', 'sweep_min_usd' => '200.00'],
        ],

        'usdt_trc20' => [
            'label' => 'USDT (TRC20)',
            'symbol' => 'USDT',
            'decimals' => 2,
            'family' => 'tron',
            'chain' => 'tron',
            'kind' => 'token',
            'native_key' => 'native_trx',
            'contract' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',
            'token_decimals' => 6,
            'confirmations' => 20,
            'scan_interval' => 5,
            'provider' => [
                'driver' => 'trongrid',
                'api_key' => env('TRONGRID_API_KEY'),
                'contract' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',
            ],
            'fallback_provider' => [
                'driver' => 'tronscan',
                'base_url' => env('TRONSCAN_API_URL', 'https://apilist.tronscanapi.com'),
                'api_key' => env('TRONSCAN_API_KEY'),
            ],
            'coingecko_id' => 'tether',
            'explorer_tx' => 'https://tronscan.org/#/transaction/{hash}',
            'address_group' => 'tron',
            'xpub' => 'blockchain.usdt_trc20.xpub',
            'slug' => 'usdt-trc20',
            'chart_color' => 'emerald-500',
            'icon' => 'usdt',
            'withdrawal_tip' => 'Tip: sending to an address that already holds USDT uses about half the energy.',
            'enabled' => env('NETWORK_USDT_TRC20_ENABLED', true),
            'settings' => ['min_deposit' => '10.00000000', 'withdrawal_min_usd' => '100.00', 'sweep_min_usd' => '100.00'],
        ],

        'usdt_erc20' => [
            'label' => 'USDT (ERC20)',
            'symbol' => 'USDT',
            'decimals' => 2,
            'family' => 'evm',
            'chain' => 'ethereum',
            'kind' => 'token',
            'native_key' => 'native_eth',
            'contract' => env('NETWORK_USDT_ERC20_CONTRACT', '0xdAC17F958D2ee523a2206206994597C13D831ec7'),
            'token_decimals' => 6,
            'confirmations' => 12,
            'scan_interval' => 5,
            'provider' => [
                'driver' => 'evm_logs',
                'project_id' => env('INFURA_PROJECT_ID'),
                'project_secret' => env('INFURA_PROJECT_SECRET'),
                'infura_network' => env('INFURA_NETWORK', 'mainnet'),
            ],
            'fallback_provider' => [
                'driver' => 'evm_logs',
                'rpc' => env('ETH_FALLBACK_RPC_URL', 'https://ethereum-rpc.publicnode.com'),
                'max_block_range' => (int) env('ETH_FALLBACK_RPC_MAX_BLOCK_RANGE', 1000),
            ],
            'coingecko_id' => 'tether',
            'explorer_tx' => 'https://etherscan.io/tx/{hash}',
            'address_group' => 'evm',
            'xpub' => 'blockchain.usdt_erc20.xpub',
            'slug' => 'usdt-erc20',
            'chart_color' => 'teal-400',
            'icon' => 'usdt',
            'withdrawal_tip' => 'Tip: sending to an address that already holds USDT costs noticeably less gas than to an empty one.',
            'enabled' => env('NETWORK_USDT_ERC20_ENABLED', true),
            'settings' => ['min_deposit' => '10.00000000', 'withdrawal_min_usd' => '100.00', 'sweep_min_usd' => '300.00'],
        ],

        'litecoin' => [
            'label' => 'Litecoin',
            'symbol' => 'LTC',
            'decimals' => 8,
            'family' => 'utxo',
            'chain' => 'litecoin',
            'kind' => 'native',
            'native_key' => 'litecoin',
            'contract' => null,
            'token_decimals' => null,
            'confirmations' => 6,
            'scan_interval' => 15,
            'provider' => [
                'driver' => 'esplora',
                'base_url' => env('BLOCKCHAIN_LITECOIN_BASE_URL', 'https://litecoinspace.org/api'),
            ],
            'fallback_provider' => [
                'driver' => 'blockcypher',
                'base_url' => env('LITECOIN_FALLBACK_API_URL', 'https://api.blockcypher.com/v1/ltc/main'),
                'token' => env('BLOCKCYPHER_TOKEN'),
            ],
            'coingecko_id' => 'litecoin',
            'explorer_tx' => 'https://litecoinspace.org/tx/{hash}',
            'address_group' => 'litecoin',
            'xpub' => 'blockchain.litecoin.xpub',
            'slug' => 'litecoin',
            'chart_color' => 'slate-400',
            'icon' => 'ltc',
            'withdrawal_tip' => 'Tip: a native SegWit (ltc1…) destination gives the smallest transaction and the lowest fee.',
            'enabled' => env('NETWORK_LITECOIN_ENABLED', false),
            'settings' => ['min_deposit' => '0.05000000', 'withdrawal_min_usd' => '100.00', 'sweep_min_usd' => '10.00'],
        ],

        'ethereum' => [
            'label' => 'Ethereum',
            'symbol' => 'ETH',
            'decimals' => 8,
            'family' => 'evm',
            'chain' => 'ethereum',
            'kind' => 'native',
            'native_key' => 'native_eth',
            'contract' => null,
            'token_decimals' => null,
            'confirmations' => 12,
            'scan_interval' => 5,
            'provider' => [
                'driver' => 'etherscan_native',
                'api_key' => env('ETHERSCAN_API_KEY'),
                'chain_id' => 1,
            ],
            'fallback_provider' => [
                'driver' => 'etherscan_native',
                'base_url' => env('ETH_FALLBACK_EXPLORER_URL', 'https://eth.blockscout.com/api'),
                'requires_api_key' => false,
                'chain_id' => 1,
            ],
            'coingecko_id' => 'ethereum',
            'explorer_tx' => 'https://etherscan.io/tx/{hash}',
            'address_group' => 'evm',
            'xpub' => 'blockchain.usdt_erc20.xpub',
            'slug' => 'ethereum',
            'chart_color' => 'indigo-400',
            'icon' => 'eth',
            'withdrawal_tip' => 'Tip: gas is usually cheapest at weekends and outside US business hours.',
            'enabled' => env('NETWORK_ETHEREUM_ENABLED', false),
            'settings' => ['min_deposit' => '0.00500000', 'withdrawal_min_usd' => '100.00', 'sweep_min_usd' => '50.00'],
        ],

        'usdc_erc20' => [
            'label' => 'USDC (ERC20)',
            'symbol' => 'USDC',
            'decimals' => 2,
            'family' => 'evm',
            'chain' => 'ethereum',
            'kind' => 'token',
            'native_key' => 'native_eth',
            'contract' => env('NETWORK_USDC_ERC20_CONTRACT', '0xA0b86991c6218b36c1d19D4a2e9Eb0cE3606eB48'),
            'token_decimals' => 6,
            'confirmations' => 12,
            'scan_interval' => 5,
            'provider' => [
                'driver' => 'evm_logs',
                'project_id' => env('INFURA_PROJECT_ID'),
                'project_secret' => env('INFURA_PROJECT_SECRET'),
                'infura_network' => env('INFURA_NETWORK', 'mainnet'),
            ],
            'fallback_provider' => [
                'driver' => 'evm_logs',
                'rpc' => env('ETH_FALLBACK_RPC_URL', 'https://ethereum-rpc.publicnode.com'),
                'max_block_range' => (int) env('ETH_FALLBACK_RPC_MAX_BLOCK_RANGE', 1000),
            ],
            'coingecko_id' => 'usd-coin',
            'explorer_tx' => 'https://etherscan.io/tx/{hash}',
            'address_group' => 'evm',
            'xpub' => 'blockchain.usdt_erc20.xpub',
            'slug' => 'usdc-erc20',
            'chart_color' => 'blue-500',
            'icon' => 'usdc',
            'withdrawal_tip' => 'Tip: sending to an address that already holds USDC costs noticeably less gas than to an empty one.',
            'enabled' => env('NETWORK_USDC_ERC20_ENABLED', false),
            'settings' => ['min_deposit' => '10.00000000', 'withdrawal_min_usd' => '100.00', 'sweep_min_usd' => '300.00'],
        ],

        'usdt_bep20' => [
            'label' => 'USDT (BEP20)',
            'symbol' => 'USDT',
            'decimals' => 2,
            'family' => 'evm',
            'chain' => 'bsc',
            'kind' => 'token',
            'native_key' => 'native_bnb',
            'contract' => env('NETWORK_USDT_BEP20_CONTRACT', '0x55d398326f99059fF775485246999027B3197955'),
            'token_decimals' => 18,
            'confirmations' => 15,
            'scan_interval' => 5,
            'provider' => [
                'driver' => 'evm_logs',
                'rpc' => env('BSC_RPC_URL'),
                'max_block_range' => (int) env('BSC_RPC_MAX_BLOCK_RANGE', 100),
            ],
            'fallback_provider' => [
                'driver' => 'evm_logs',
                'rpc' => env('BSC_FALLBACK_RPC_URL', 'https://bsc-rpc.publicnode.com'),
                'max_block_range' => (int) env('BSC_RPC_MAX_BLOCK_RANGE', 100),
            ],
            'coingecko_id' => 'tether',
            'explorer_tx' => 'https://bscscan.com/tx/{hash}',
            'address_group' => 'evm',
            'xpub' => 'blockchain.usdt_erc20.xpub',
            'slug' => 'usdt-bep20',
            'chart_color' => 'lime-500',
            'icon' => 'usdt',
            'enabled' => env('NETWORK_USDT_BEP20_ENABLED', false),
            'settings' => ['min_deposit' => '10.00000000', 'withdrawal_min_usd' => '100.00', 'sweep_min_usd' => '10.00'],
        ],

        'usdc_bep20' => [
            'label' => 'USDC (BEP20)',
            'symbol' => 'USDC',
            'decimals' => 2,
            'family' => 'evm',
            'chain' => 'bsc',
            'kind' => 'token',
            'native_key' => 'native_bnb',
            'contract' => env('NETWORK_USDC_BEP20_CONTRACT', '0x8AC76a51cc950d9822D68b83fE1Ad97B32Cd580d'),
            'token_decimals' => 18,
            'confirmations' => 15,
            'scan_interval' => 5,
            'provider' => [
                'driver' => 'evm_logs',
                'rpc' => env('BSC_RPC_URL'),
                'max_block_range' => (int) env('BSC_RPC_MAX_BLOCK_RANGE', 100),
            ],
            'fallback_provider' => [
                'driver' => 'evm_logs',
                'rpc' => env('BSC_FALLBACK_RPC_URL', 'https://bsc-rpc.publicnode.com'),
                'max_block_range' => (int) env('BSC_RPC_MAX_BLOCK_RANGE', 100),
            ],
            'coingecko_id' => 'usd-coin',
            'explorer_tx' => 'https://bscscan.com/tx/{hash}',
            'address_group' => 'evm',
            'xpub' => 'blockchain.usdt_erc20.xpub',
            'slug' => 'usdc-bep20',
            'chart_color' => 'sky-400',
            'icon' => 'usdc',
            'enabled' => env('NETWORK_USDC_BEP20_ENABLED', false),
            'settings' => ['min_deposit' => '10.00000000', 'withdrawal_min_usd' => '100.00', 'sweep_min_usd' => '10.00'],
        ],

        'bnb' => [
            'label' => 'BNB',
            'symbol' => 'BNB',
            'decimals' => 8,
            'family' => 'evm',
            'chain' => 'bsc',
            'kind' => 'native',
            'native_key' => 'native_bnb',
            'contract' => null,
            'token_decimals' => null,
            'confirmations' => 15,
            'scan_interval' => 5,
            'provider' => [
                'driver' => 'nodereal_native',
                'rpc' => env('NODEREAL_BSC_RPC_URL'),
            ],
            'coingecko_id' => 'binancecoin',
            'explorer_tx' => 'https://bscscan.com/tx/{hash}',
            'address_group' => 'evm',
            'xpub' => 'blockchain.usdt_erc20.xpub',
            'slug' => 'bnb',
            'chart_color' => 'yellow-400',
            'icon' => 'bnb',
            'enabled' => env('NETWORK_BNB_ENABLED', false),
            'settings' => ['min_deposit' => '0.01000000', 'withdrawal_min_usd' => '100.00', 'sweep_min_usd' => '10.00'],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Native gas assets
    |--------------------------------------------------------------------------
    |
    | Valuation/gas bookkeeping is keyed by native asset (native_eth,
    | native_trx, native_bnb) so every token on a chain shares one gas float,
    | one treasury float, and one USD conversion rate.
    |
    */

    'natives' => [
        'native_eth' => ['symbol' => 'ETH', 'decimals' => 8, 'coingecko_id' => 'ethereum', 'chain' => 'ethereum'],
        'native_trx' => ['symbol' => 'TRX', 'decimals' => 8, 'coingecko_id' => 'tron', 'chain' => 'tron'],
        'native_bnb' => ['symbol' => 'BNB', 'decimals' => 8, 'coingecko_id' => 'binancecoin', 'chain' => 'bsc'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Address groups
    |--------------------------------------------------------------------------
    |
    | Networks sharing an address_group derive one address from one xpub.
    | `driver` selects the derivation/encoding in AddressGenerator; `to_address`
    | is the nimiq/xpub target for the `xpub` driver. `address_regex` and
    | `address_hint` validate user-supplied addresses (profit payout, QR).
    |
    */

    /*
    | Human-readable chain names for UI badges.
    */
    'chains' => [
        'bitcoin' => 'Bitcoin',
        'litecoin' => 'Litecoin',
        'ethereum' => 'Ethereum',
        'bsc' => 'BSC',
        'tron' => 'TRON',
    ],

    /*
    | Chain badge icons (cryptocurrency-icons set) overlaid on token icons.
    */
    'chain_icons' => [
        'bitcoin' => 'btc',
        'litecoin' => 'ltc',
        'ethereum' => 'eth',
        'bsc' => 'bnb',
        'tron' => 'trx',
    ],

    'address_groups' => [
        'bitcoin' => [
            'driver' => 'xpub',
            'to_address' => 'btc',
            'address_regex' => '/^(bc1[ac-hj-np-z02-9]{25,62}|[13][a-km-zA-HJ-NP-Z1-9]{25,34})$/',
            'address_hint' => 'a Bitcoin address (bc1… or 1…/3…)',
            'example_address' => '1A1z...DivfNa',
        ],
        'litecoin' => [
            'driver' => 'litecoin_bech32',
            'address_regex' => '/^(ltc1[ac-hj-np-z02-9]{25,62}|[LM3][a-km-zA-HJ-NP-Z1-9]{25,34})$/',
            'address_hint' => 'a Litecoin address (ltc1… or L…/M…/3…)',
            'example_address' => 'ltc1q...8f4k',
        ],
        'evm' => [
            'driver' => 'xpub',
            'to_address' => 'eth',
            'address_regex' => '/^0x[a-fA-F0-9]{40}$/',
            'address_hint' => 'a 0x EVM address',
            'example_address' => '0x4f...B9cE1',
        ],
        'tron' => [
            'driver' => 'tron',
            'address_regex' => '/^T[1-9A-HJ-NP-Za-km-z]{33}$/',
            'address_hint' => 'a TRON address starting with T',
            'example_address' => 'TXn9...v4mQ2',
        ],
    ],

];
