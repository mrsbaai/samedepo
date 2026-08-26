<?php

declare(strict_types=1);

namespace App\Services\Blockchain\PriceFeed;

use Illuminate\Support\Facades\Http;

class CoinGeckoProvider implements PriceFeedProvider
{
    public function prices(): array
    {
        $request = Http::acceptJson();
        $apiKey = config('blockchain.price_feed.api_key');

        if ($apiKey) {
            $request = $request->withHeader('x-cg-demo-api-key', $apiKey);
        }

        $prices = $request->get(config('blockchain.price_feed.url'), [
            'ids' => 'bitcoin,tether',
            'vs_currencies' => 'usd',
        ])->throw()->json();

        return [
            'bitcoin' => $prices['bitcoin']['usd'] ?? 0,
            'usdt_trc20' => $prices['tether']['usd'] ?? 0,
            'usdt_erc20' => $prices['tether']['usd'] ?? 0,
            'usdt_base' => $prices['tether']['usd'] ?? 0,
        ];
    }
}
