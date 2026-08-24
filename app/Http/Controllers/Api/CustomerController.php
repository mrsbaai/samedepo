<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\StoreCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Models\DepositAddress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CustomerController
{
    private const NETWORKS = ['bitcoin', 'usdt_trc20', 'usdt_erc20'];

    public function store(StoreCustomerRequest $request)
    {
        $reference = $request->validated('reference');

        $customer = Customer::query()
            ->where('user_id', $request->user()->id)
            ->where('customer_reference', $reference)
            ->first();

        $statusCode = 200;

        if ($customer === null) {
            $customer = DB::transaction(function () use ($request, $reference) {
                $created = Customer::create([
                    'user_id' => $request->user()->id,
                    'customer_reference' => $reference,
                ]);

                $this->generateDepositAddresses($created);

                return $created;
            });

            $statusCode = 201;
        }

        return (new CustomerResource($customer->load('depositAddresses')))
            ->response()
            ->setStatusCode($statusCode);
    }

    public function show(Request $request, string $reference)
    {
        $customer = Customer::query()
            ->where('user_id', $request->user()->id)
            ->where('customer_reference', $reference)
            ->with('depositAddresses')
            ->firstOrFail();

        return new CustomerResource($customer);
    }

    private function generateDepositAddresses(Customer $customer): void
    {
        foreach (self::NETWORKS as $network) {
            DepositAddress::query()->updateOrCreate(
                ['customer_id' => $customer->id, 'network' => $network],
                ['address' => Str::uuid().'-'.$network]
            );
        }
    }
}
