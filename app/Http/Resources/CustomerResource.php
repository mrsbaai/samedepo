<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PlatformSettings;
use App\Support\Network;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'customer_reference' => $this->customer_reference,
            'addresses' => $this->depositAddresses->map(fn ($address) => [
                'network' => $address->network,
                'address' => $address->address,
                'qr' => route('qr', ['address' => $address->address]),
                'minimum_deposit' => $this->minimumForNetwork($address->network),
            ])->values(),
        ];
    }

    private function minimumForNetwork(string $network): string
    {
        $minimum = PlatformSettings::networkSetting($network)->min_deposit;
        $decimals = Network::exists($network) ? Network::decimals($network) : 8;

        return number_format((float) $minimum, $decimals, '.', '');
    }
}
