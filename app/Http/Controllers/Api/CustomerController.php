<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\StoreCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Services\Blockchain\CustomerAddressProvisioner;
use Illuminate\Support\Facades\DB;

class CustomerController
{
    /**
     * Return a customer and their permanent deposit addresses, creating them when missing.
     */
    public function show(StoreCustomerRequest $request, string $reference)
    {
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

                app(CustomerAddressProvisioner::class)->provision($created);

                return $created;
            });

            $statusCode = 201;
        } else {
            // Lazily backfill addresses for networks enabled after this
            // customer was created.
            DB::transaction(fn () => app(CustomerAddressProvisioner::class)->provision($customer));
        }

        return (new CustomerResource($customer->load('depositAddresses')))
            ->additional(['status' => $statusCode === 201 ? 'created' : 'existing'])
            ->response()
            ->setStatusCode($statusCode);
    }
}
