<?php

declare(strict_types=1);

return [
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
];
