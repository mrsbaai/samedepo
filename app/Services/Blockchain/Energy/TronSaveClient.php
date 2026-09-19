<?php

declare(strict_types=1);

namespace App\Services\Blockchain\Energy;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class TronSaveClient
{
    public function userInfo(): ?array
    {
        return $this->request('get', '/v2/user-info');
    }

    public function estimate(string $receiver, int $energy, int $durationSec): ?array
    {
        return $this->request('post', '/v2/estimate-buy-resource', [
            'resourceType' => 'ENERGY',
            'receiver' => $receiver,
            'durationSec' => $durationSec,
            'resourceAmount' => $energy,
            'unitPrice' => 'MEDIUM',
            'options' => ['allowPartialFill' => false],
        ]);
    }

    public function buy(string $receiver, int $energy, int $durationSec, int $maxPriceSun): ?string
    {
        $data = $this->request('post', '/v2/buy-resource', [
            'resourceType' => 'ENERGY',
            'receiver' => $receiver,
            'durationSec' => $durationSec,
            'resourceAmount' => $energy,
            'unitPrice' => 'MEDIUM',
            'options' => [
                'allowPartialFill' => false,
                'preventDuplicateIncompleteOrders' => true,
                'maxPriceAccepted' => $maxPriceSun,
            ],
        ]);

        return $data['orderId'] ?? null;
    }

    public function order(string $id): ?array
    {
        return $this->request('get', "/v2/order/{$id}");
    }

    private function request(string $method, string $path, array $payload = []): ?array
    {
        try {
            $request = Http::baseUrl((string) config('services.tronsave.base_url'))
                ->withHeaders(['apikey' => (string) config('services.tronsave.api_key')])
                ->timeout(15);

            $response = $method === 'get' ? $request->get($path) : $request->post($path, $payload);
        } catch (ConnectionException $exception) {
            Log::warning('tronsave.request_failed', [
                'path' => $path,
                'error' => 'connection_failed: '.$exception->getMessage(),
            ]);

            return null;
        }

        $body = $response->json();

        if (! $response->successful() || ! is_array($body) || ($body['error'] ?? true) !== false) {
            Log::warning('tronsave.request_failed', [
                'path' => $path,
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 1000),
            ]);

            return null;
        }

        return is_array($body['data'] ?? null) ? $body['data'] : null;
    }
}
