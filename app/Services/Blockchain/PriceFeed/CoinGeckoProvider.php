<?php

declare(strict_types=1);

namespace App\Services\Blockchain\PriceFeed;

use App\Support\Network;
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

        // valuation key → coingecko id, built from the network registry.
        $idByKey = [];
        foreach (Network::valuationKeys() as $key) {
            $id = Network::coingeckoId($key);

            if ($id !== null) {
                $idByKey[$key] = $id;
            }
        }

        $prices = $request->get(config('blockchain.price_feed.url'), [
            'ids' => implode(',', array_unique(array_values($idByKey))),
            'vs_currencies' => 'usd',
        ])->throw()->json();

        $result = [];
        foreach ($idByKey as $key => $id) {
            $result[$key] = $prices[$id]['usd'] ?? 0;
        }

        return $result;
    }
}
