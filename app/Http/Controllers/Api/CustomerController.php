<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\StoreCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Models\DepositAddress;
use App\Services\Blockchain\AddressGenerator;
use App\Support\Network;
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

                $this->generateDepositAddresses($created);

                return $created;
            });

            $statusCode = 201;
        } else {
            // Lazily backfill addresses for networks enabled after this
            // customer was created.
            DB::transaction(fn () => $this->generateDepositAddresses($customer));
        }

        return (new CustomerResource($customer->load('depositAddresses')))
            ->additional(['status' => $statusCode === 201 ? 'created' : 'existing'])
            ->response()
            ->setStatusCode($statusCode);
    }

    private function generateDepositAddresses(Customer $customer): void
    {
        $generator = app(AddressGenerator::class);
        $nextIndex = (DepositAddress::max('derivation_index') ?? 0) + 1;

        $existing = $customer->depositAddresses()->get();
        $existingNetworks = $existing->pluck('network')->all();

        // One derivation per address group: every network in a group shares
        // the xpub path and therefore the same address/derivation_index.
        $groupIndexes = [];
        $groupAddresses = [];

        foreach ($existing as $row) {
            if (Network::exists($row->network)) {
                $groupIndexes[Network::addressGroup($row->network)] = $row->derivation_index;
                $groupAddresses[Network::addressGroup($row->network)] = $row->address;
            }
        }

        foreach (Network::enabledKeys() as $network) {
            if (in_array($network, $existingNetworks, true)) {
                continue;
            }

            $group = Network::addressGroup($network);

            if (! array_key_exists($group, $groupIndexes)) {
                // Reuse the customer's existing derivation index when they
                // already have rows in another group (one index per customer).
                $index = $existing->first()?->derivation_index ?? $nextIndex;
                $groupIndexes[$group] = $index;
                $groupAddresses[$group] = $generator->generate($network, $index);
            }

            DepositAddress::query()->firstOrCreate(
                ['customer_id' => $customer->id, 'network' => $network],
                [
                    'address' => $groupAddresses[$group],
                    'derivation_index' => $groupIndexes[$group],
                ]
            );
        }
    }
}
