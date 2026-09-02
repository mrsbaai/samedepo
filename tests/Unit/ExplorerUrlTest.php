<?php

use App\Support\ExplorerUrl;

test('explorer url returns correct links per network and type', function () {
    $tx = 'abc123';
    $address = 'xyz789';

    expect(ExplorerUrl::for('tx', 'bitcoin', $tx))
        ->toBe('https://mempool.space/tx/abc123')
        ->and(ExplorerUrl::for('address', 'bitcoin', $address))
        ->toBe('https://mempool.space/address/xyz789')
        ->and(ExplorerUrl::for('tx', 'usdt_trc20', $tx))
        ->toBe('https://tronscan.org/#/transaction/abc123')
        ->and(ExplorerUrl::for('address', 'usdt_trc20', $address))
        ->toBe('https://tronscan.org/#/address/xyz789')
        ->and(ExplorerUrl::for('tx', 'usdt_erc20', $tx))
        ->toBe('https://etherscan.io/tx/abc123')
        ->and(ExplorerUrl::for('address', 'usdt_erc20', $address))
        ->toBe('https://etherscan.io/address/xyz789');
});

test('explorer url returns null for null value or unknown network', function () {
    expect(ExplorerUrl::for('tx', 'bitcoin', null))->toBeNull()
        ->and(ExplorerUrl::for('tx', 'solana', 'abc'))->toBeNull();
});
