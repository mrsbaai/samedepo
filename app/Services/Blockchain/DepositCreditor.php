<?php

declare(strict_types=1);

namespace App\Services\Blockchain;

use App\Events\DepositBelowMinimum;
use App\Events\DepositCredited;
use App\Events\DepositForfeited;
use App\Models\Balance;
use App\Models\Deposit;
use App\Models\LedgerEntry;
use App\Models\PlatformSettings;
use App\Models\TreasurySweep;
use App\Models\UsdValuation;
use App\Models\User;
use App\Support\Network;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DepositCreditor
{
    public function credit(): void
    {
        Deposit::query()
            ->withoutGlobalScope('owner')
            ->whereIn('status', ['pending', 'below_minimum'])
            ->chunkById(100, function ($deposits): void {
                foreach ($deposits as $deposit) {
                    DB::transaction(function () use ($deposit): void {
                        $this->creditDeposit($deposit->id);
                    });
                }
            });
    }

    public function expire(): void
    {
        Deposit::query()
            ->withoutGlobalScope('owner')
            ->where('status', 'below_minimum')
            ->where('expires_at', '<=', now())
            ->chunkById(100, function ($deposits): void {
                foreach ($deposits as $deposit) {
                    DB::transaction(function () use ($deposit): void {
                        $locked = Deposit::query()
                            ->withoutGlobalScope('owner')
                            ->lockForUpdate()
                            ->find($deposit->id);

                        if ($locked === null || $locked->status !== 'below_minimum') {
                            return;
                        }

                        // A top-up detected before the deadline may still be
                        // confirming (e.g. BTC); it wins the race once confirmed.
                        $topupInFlight = Deposit::query()
                            ->withoutGlobalScope('owner')
                            ->where('deposit_address_id', $locked->deposit_address_id)
                            ->where('status', 'pending')
                            ->where('detected_at', '<', $locked->expires_at)
                            ->exists();

                        if ($topupInFlight) {
                            return;
                        }

                        $locked->update([
                            'status' => 'forfeited',
                            'forfeited_at' => now(),
                        ]);

                        event(new DepositForfeited($locked->fresh()));
                    });
                }
            });
    }

    public function creditManually(Deposit $deposit, User $admin): void
    {
        DB::transaction(function () use ($deposit, $admin): void {
            $locked = Deposit::query()
                ->withoutGlobalScope('owner')
                ->lockForUpdate()
                ->findOrFail($deposit->id);

            if (! in_array($locked->status, ['below_minimum', 'forfeited'], true)) {
                throw new DomainException('Only below-minimum or forfeited deposits can be credited manually.');
            }

            $inFlight = TreasurySweep::query()
                ->whereIn('status', ['pending', 'broadcast'])
                ->where(function ($query) use ($locked): void {
                    $query->where('deposit_id', $locked->id)
                        ->orWhere('deposit_address_id', $locked->deposit_address_id)
                        ->orWhereJsonContains('deposit_ids', $locked->id);
                })
                ->exists();

            if ($inFlight) {
                throw new DomainException('Deposit is part of an in-flight treasury sweep.');
            }

            $this->applyCredit($locked, [
                'manually_credited_by' => $admin->id,
                'manually_credited_at' => now(),
            ]);
        });
    }

    private function creditDeposit(int $depositId): void
    {
        $deposit = Deposit::query()
            ->withoutGlobalScope('owner')
            ->lockForUpdate()
            ->find($depositId);

        if ($deposit === null || ! in_array($deposit->status, ['pending', 'below_minimum'], true)) {
            return;
        }

        if ($deposit->confirmation_count < Network::confirmations($deposit->network)) {
            return;
        }

        // A short stays in the group until its expiry is observed by expire(),
        // but only for payments detected before it expired — a payment that
        // arrived later starts a fresh window instead of reviving the old one.
        $windowStart = $deposit->detected_at ?? now();

        /** @var Collection<int, Deposit> $group */
        $group = Deposit::query()
            ->withoutGlobalScope('owner')
            ->where('deposit_address_id', $deposit->deposit_address_id)
            ->where('status', 'below_minimum')
            ->where('expires_at', '>', $windowStart)
            ->lockForUpdate()
            ->get()
            ->reject(fn (Deposit $member): bool => $member->id === $deposit->id)
            ->push($deposit)
            ->values();

        $total = '0';
        foreach ($group as $member) {
            $total = bcadd($total, (string) $member->gross_amount, 8);
        }

        if (bccomp($total, $this->effectiveMinimum($deposit->network, $group), 8) >= 0) {
            foreach ($group as $member) {
                $this->applyCredit($member);
            }

            return;
        }

        if ($deposit->status !== 'pending') {
            return;
        }

        $expiresAt = $group
            ->reject(fn (Deposit $member): bool => $member->id === $deposit->id)
            ->pluck('expires_at')
            ->filter()
            ->min()
            ?? now()->addDays((int) config('blockchain.short_payment_expiry_days', 7));

        $deposit->update([
            'status' => 'below_minimum',
            'expires_at' => $expiresAt,
        ]);

        event(new DepositBelowMinimum($deposit->fresh()));
    }

    /**
     * @param  Collection<int, Deposit>  $group
     */
    private function effectiveMinimum(string $network, Collection $group): string
    {
        $minimum = (string) PlatformSettings::networkSetting($network)->min_deposit;

        foreach ($group as $member) {
            if ($member->minimum_amount !== null && bccomp((string) $member->minimum_amount, $minimum, 8) < 0) {
                $minimum = (string) $member->minimum_amount;
            }
        }

        return $minimum;
    }

    private function applyCredit(Deposit $deposit, array $extra = []): void
    {
        $settings = PlatformSettings::instance();

        $owner = User::query()->find($deposit->user_id);
        $percent = $owner?->deposit_fee_override ?? $settings->global_deposit_fee_percent;

        $gross = (string) $deposit->gross_amount;
        $fee = $this->calculateFee($gross, (string) $percent);
        $net = bcsub($gross, $fee, 8);

        $balance = Balance::query()
            ->withoutGlobalScope('owner')
            ->firstOrCreate(
                ['user_id' => $deposit->user_id, 'network' => $deposit->network],
                ['amount' => 0]
            );

        $lockedBalance = Balance::query()
            ->withoutGlobalScope('owner')
            ->where('id', $balance->id)
            ->lockForUpdate()
            ->first();

        $lockedBalance->amount = bcadd((string) $lockedBalance->amount, $net, 8);
        $lockedBalance->save();

        $usdRate = UsdValuation::query()->where('network', $deposit->network)->value('conversion_value');

        $deposit->update(array_merge([
            'status' => 'credited',
            'fee_amount' => $fee,
            'credited_amount' => $net,
            'usd_value' => $usdRate === null ? null : number_format((float) bcmul($net, (string) $usdRate, 8), 2, '.', ''),
            'credited_at' => now(),
        ], $extra));

        LedgerEntry::create([
            'user_id' => $deposit->user_id,
            'network' => $deposit->network,
            'amount' => $net,
            'reason' => 'deposit_credit',
            'deposit_id' => $deposit->id,
        ]);

        LedgerEntry::create([
            'user_id' => $deposit->user_id,
            'network' => $deposit->network,
            'amount' => '-'.$fee,
            'reason' => 'fee',
            'deposit_id' => $deposit->id,
        ]);

        event(new DepositCredited($deposit->fresh()));
    }

    private function calculateFee(string $gross, string $percent): string
    {
        return bcmul($gross, bcdiv($percent, '100', 8), 8);
    }
}
