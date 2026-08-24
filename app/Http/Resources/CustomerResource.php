<?php

declare(strict_types=1);

namespace App\Http\Resources;

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
            ])->values(),
        ];
    }
}
