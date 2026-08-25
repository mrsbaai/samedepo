<?php

declare(strict_types=1);

return [
    'price_feed' => [
        'url' => 'https://api.coingecko.com/api/v3/simple/price',
        'api_key' => env('COINGECKO_API_KEY'),
    ],

    'confirmations' => [
        'bitcoin' => 3,
        'usdt_trc20' => 20,
        'usdt_erc20' => 12,
    ],

    'bitcoin' => [
        'xpub' => env('BLOCKCHAIN_BITCOIN_XPUB'),
        'coin_type' => 0,
    ],
    'usdt_trc20' => [
        'xpub' => env('BLOCKCHAIN_USDT_TRC20_XPUB'),
        'coin_type' => 195,
    ],
    'usdt_erc20' => [
        'xpub' => env('BLOCKCHAIN_USDT_ERC20_XPUB'),
        'coin_type' => 60,
    ],

    'providers' => [
        'bitcoin' => [
            'driver' => 'blockcypher',
            'token' => env('BLOCKCYPHER_TOKEN'),
            'network' => env('BLOCKCYPHER_NETWORK', 'main'),
        ],
        'usdt_trc20' => [
            'driver' => 'trongrid',
            'api_key' => env('TRONGRID_API_KEY'),
            'usdt_contract' => env('TRONGRID_USDT_CONTRACT', 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t'),
        ],
        'usdt_erc20' => [
            'driver' => 'infura',
            'project_id' => env('INFURA_PROJECT_ID'),
            'project_secret' => env('INFURA_PROJECT_SECRET'),
            'network' => env('INFURA_NETWORK', 'mainnet'),
            'usdt_contract' => env('INFURA_USDT_CONTRACT', '0xdAC17F958D2ee523a2206206994597C13D831ec7'),
        ],
    ],
];
