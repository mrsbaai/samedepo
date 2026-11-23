<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Balance;
use App\Models\Deposit;
use App\Models\DepositAddress;
use App\Models\LedgerEntry;
use App\Models\TreasurySweep;
use App\Models\User;
use App\Services\Blockchain\AddressGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RepairSharedDepositAddresses extends Command
{
    protected $signature = 'deposits:repair-shared-addresses {--apply : Apply the repair plan (default is a dry run)}';

    protected $description = 'Repair deposit addresses shared across owners, reverse duplicate credits, and restrict affected owners';

    /** @var array<int, string> */
    private array $lines = [];

    public function handle(AddressGenerator $generator): int
    {
        $apply = (bool) $this->option('apply');

        $this->report($apply ? 'Mode: APPLY' : 'Mode: DRY RUN (pass --apply to make changes)');

        // Step a — find shared (network, address) groups across customers.
        $groups = DB::table('deposit_addresses')
            ->select('network', 'address')
            ->groupBy('network', 'address')
            ->havingRaw('COUNT(DISTINCT customer_id) > 1')
            ->orderBy('network')
            ->orderBy('address')
            ->get();

        $allRowIds = [];
        $nonKeeperRowIds = [];
        $addressByRowId = [];
        $affectedCustomerIds = collect();

        foreach ($groups as $group) {
            $rows = DB::table('deposit_addresses')
                ->where('network', $group->network)
                ->where('address', $group->address)
                ->orderBy('id')
                ->get();

            $keeper = $rows->first();
            $this->report("Shared group {$group->network} {$group->address}: keeper row #{$keeper->id} (customer {$keeper->customer_id})");

            foreach ($rows as $row) {
                $allRowIds[] = $row->id;
                $addressByRowId[$row->id] = $row->address;

                if ($row->id !== $keeper->id) {
                    $nonKeeperRowIds[] = $row->id;
                    $affectedCustomerIds->push($row->customer_id);
                    $this->report("  non-keeper row #{$row->id} (customer {$row->customer_id})");
                }
            }
        }

        $affectedCustomerIds = $affectedCustomerIds->unique()->sort()->values();

        if ($groups->isEmpty()) {
            $this->report('No shared (network, address) groups found.');
        }

        // Step b — duplicate deposits on shared addresses.
        $duplicates = collect();
        $reversalDebits = []; // "user_id|network" => total credited amount to subtract
        $reversedOwnerIds = collect();

        if ($allRowIds !== []) {
            Deposit::query()
                ->withoutGlobalScopes()
                ->whereIn('deposit_address_id', $allRowIds)
                ->orderBy('id')
                ->get()
                ->groupBy(fn (Deposit $d) => $d->network.'|'.$d->tx_hash)
                ->filter(fn ($group) => $group->count() > 1)
                ->each(function ($group) use ($duplicates) {
                    $group->sortBy('id')->slice(1)->each(fn (Deposit $d) => $duplicates->push($d));
                });

            foreach ($duplicates->sortBy('id') as $dup) {
                $address = $addressByRowId[$dup->deposit_address_id] ?? '?';
                $this->report("Reverse deposit #{$dup->id} (network {$dup->network}, tx {$dup->tx_hash}, status {$dup->status}) on shared address {$address}");

                if ($dup->status === 'reversed') {
                    continue;
                }

                $reversedOwnerIds->push($dup->user_id);

                if ($dup->status === 'credited') {
                    $key = $dup->user_id.'|'.$dup->network;
                    $reversalDebits[$key] = bcadd($reversalDebits[$key] ?? '0', (string) $dup->credited_amount, 8);
                }
            }
        }

        if ($duplicates->isEmpty()) {
            $this->report('No duplicate deposits found.');
        }

        // Step c — write-offs for balances that would go negative.
        $writeOffs = []; // "user_id|network" => amount
        foreach ($reversalDebits as $key => $debit) {
            [$userId, $network] = explode('|', $key);
            $current = (string) (Balance::query()->withoutGlobalScopes()
                ->where('user_id', $userId)->where('network', $network)->value('amount') ?? '0');
            $projected = bcsub($current, $debit, 8);

            if (bccomp($projected, '0', 8) < 0) {
                $writeOffs[$key] = bcmul($projected, '-1', 8);
                $this->report("Write-off for user {$userId} on {$network}: {$writeOffs[$key]} (balance {$current} - {$debit} < 0)");
            }
        }

        // Step d — pending sweeps on non-keeper rows.
        $sweeps = $nonKeeperRowIds === [] ? collect() : TreasurySweep::query()
            ->whereIn('deposit_address_id', $nonKeeperRowIds)
            ->where('status', 'pending')
            ->orderBy('id')
            ->get();

        foreach ($sweeps as $sweep) {
            $this->report("Fail pending sweep #{$sweep->id} on shared address row #{$sweep->deposit_address_id}");
        }

        // Step e — reassign every address of each affected customer to a fresh index.
        $simulatedMax = (int) (DepositAddress::query()->withoutGlobalScopes()->max('derivation_index') ?? 0);
        $reassignments = []; // customer_id => new index
        foreach ($affectedCustomerIds as $customerId) {
            $reassignments[$customerId] = ++$simulatedMax;
            $rows = DB::table('deposit_addresses')->where('customer_id', $customerId)->count();
            $this->report("Reassign customer {$customerId} → new derivation index {$reassignments[$customerId]} ({$rows} address rows)");
        }

        // Step f — restrict withdrawals for owners whose deposits were reversed.
        foreach ($reversedOwnerIds->unique()->sort() as $ownerId) {
            $this->report("Set withdrawal_mode=approval for owner {$ownerId}");
        }

        if ($apply) {
            $this->apply($duplicates, $writeOffs, $sweeps, $reassignments, $reversedOwnerIds->unique()->sort()->values(), $generator);
        }

        $this->report(sprintf(
            'Summary: %d shared groups, %d duplicate deposits, %d write-offs, %d sweeps failed, %d customers reassigned, %d owners restricted.',
            $groups->count(),
            $duplicates->count(),
            count($writeOffs),
            $sweeps->count(),
            count($reassignments),
            $reversedOwnerIds->unique()->count(),
        ));

        $this->writeLog();

        return self::SUCCESS;
    }

    private function apply($duplicates, array $writeOffs, $sweeps, array $reassignments, $reversedOwnerIds, AddressGenerator $generator): void
    {
        DB::transaction(function () use ($duplicates) {
            foreach ($duplicates as $dup) {
                $deposit = Deposit::query()->withoutGlobalScopes()->lockForUpdate()->find($dup->id);

                if ($deposit === null || $deposit->status === 'reversed') {
                    continue;
                }

                if ($deposit->status === 'credited') {
                    $balance = Balance::query()->withoutGlobalScopes()->firstOrCreate(
                        ['user_id' => $deposit->user_id, 'network' => $deposit->network],
                        ['amount' => 0],
                    );

                    $locked = Balance::query()->withoutGlobalScopes()->where('id', $balance->id)->lockForUpdate()->first();
                    $locked->amount = bcsub((string) $locked->amount, (string) $deposit->credited_amount, 8);
                    $locked->save();

                    LedgerEntry::create([
                        'user_id' => $deposit->user_id,
                        'network' => $deposit->network,
                        'amount' => '-'.$deposit->credited_amount,
                        'reason' => 'duplicate_credit_reversal',
                        'deposit_id' => $deposit->id,
                    ]);
                }

                $deposit->status = 'reversed';
                $deposit->save();
            }
        });

        DB::transaction(function () use ($writeOffs) {
            foreach ($writeOffs as $key => $amount) {
                [$userId, $network] = explode('|', $key);

                $locked = Balance::query()->withoutGlobalScopes()
                    ->where('user_id', $userId)->where('network', $network)
                    ->lockForUpdate()->first();

                if ($locked === null || bccomp((string) $locked->amount, '0', 8) >= 0) {
                    continue;
                }

                $writeOff = bcmul((string) $locked->amount, '-1', 8);

                LedgerEntry::create([
                    'user_id' => $userId,
                    'network' => $network,
                    'amount' => $writeOff,
                    'reason' => 'write_off',
                ]);

                $locked->amount = '0';
                $locked->save();
            }
        });

        if ($sweeps->isNotEmpty()) {
            DB::transaction(function () use ($sweeps) {
                TreasurySweep::query()->whereIn('id', $sweeps->pluck('id'))->where('status', 'pending')
                    ->update(['status' => 'failed', 'error_message' => 'Cancelled: shared-address repair']);
            });
        }

        foreach (array_keys($reassignments) as $customerId) {
            Cache::lock('deposit-address-index', 10)->block(10, function () use ($customerId, $generator) {
                DB::transaction(function () use ($customerId, $generator) {
                    $index = ((int) (DepositAddress::query()->withoutGlobalScopes()->max('derivation_index') ?? 0)) + 1;

                    DB::table('deposit_addresses')->where('customer_id', $customerId)->orderBy('id')->get()
                        ->each(function ($row) use ($index, $generator) {
                            DB::table('deposit_addresses')->where('id', $row->id)->update([
                                'derivation_index' => $index,
                                'address' => $generator->generate($row->network, $index),
                            ]);
                        });
                });
            });
        }

        if ($reversedOwnerIds->isNotEmpty()) {
            DB::transaction(function () use ($reversedOwnerIds) {
                User::query()->whereIn('id', $reversedOwnerIds)->update(['withdrawal_mode' => 'approval']);
            });
        }
    }

    private function report(string $line): void
    {
        $this->lines[] = $line;
        $this->line($line);
    }

    private function writeLog(): void
    {
        $path = storage_path('logs/repair-shared-addresses-'.now()->format('Ymd-His').'.log');
        file_put_contents($path, implode(PHP_EOL, $this->lines).PHP_EOL);
        $this->line("Log written to {$path}");
    }
}
