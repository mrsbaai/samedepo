<?php

use App\Support\CryptoIcon;

test('url resolves a built cryptocurrency icon', function () {
    expect(CryptoIcon::url('usdt'))
        ->toContain('usdt')
        ->toContain('.svg');
});

test('svg returns raw markup for embedding and null for unknown icons', function () {
    expect(CryptoIcon::svg('usdt'))->toContain('<svg')
        ->and(CryptoIcon::svg('does-not-exist'))->toBeNull();
});
