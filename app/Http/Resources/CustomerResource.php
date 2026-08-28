<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PlatformSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $settings = PlatformSettings::instance();

        return [
            'customer_reference' => $this->customer_reference,
            'addresses' => $this->depositAddresses->map(fn ($address) => [
                'network' => $address->network,
                'address' => $address->address,
                'qr' => route('qr', ['address' => $address->address]),
                'minimum_deposit' => $this->minimumForNetwork($address->network, $settings),
            ])->values(),
        ];
    }

    private function minimumForNetwork(string $network, PlatformSettings $settings): string
    {
        $column = match ($network) {
            'bitcoin' => 'min_deposit_bitcoin',
            'usdt_trc20' => 'min_deposit_usdt_trc20',
            'usdt_erc20' => 'min_deposit_usdt_erc20',
        };

        $decimals = $network === 'bitcoin' ? 8 : 2;

        return number_format((float) $settings->{$column}, $decimals, '.', '');
    }
}
