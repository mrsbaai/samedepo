<?php

declare(strict_types=1);

namespace App\Services\Blockchain;

use App\Models\Customer;
use App\Models\DepositAddress;
use App\Support\Network;

class CustomerAddressProvisioner
{
    public function __construct(private readonly AddressGenerator $generator) {}

    /**
     * Create deposit addresses for every enabled network the customer is
     * missing. Returns the number of rows created.
     */
    public function provision(Customer $customer): int
    {
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

        $created = 0;

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
                $groupAddresses[$group] = $this->generator->generate($network, $index);
            }

            $row = DepositAddress::query()->firstOrCreate(
                ['customer_id' => $customer->id, 'network' => $network],
                [
                    'address' => $groupAddresses[$group],
                    'derivation_index' => $groupIndexes[$group],
                ]
            );

            if ($row->wasRecentlyCreated) {
                $created++;
            }
        }

        return $created;
    }
}
