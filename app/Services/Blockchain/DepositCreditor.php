<?php

declare(strict_types=1);

namespace App\Services\Blockchain;

use App\Events\DepositCredited;
use App\Models\Balance;
use App\Models\Deposit;
use App\Models\LedgerEntry;
use App\Models\PlatformSettings;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DepositCreditor
{
    public function credit(): void
    {
        Deposit::query()
            ->where('status', 'pending')
            ->chunkById(100, function ($deposits): void {
                foreach ($deposits as $deposit) {
                    DB::transaction(function () use ($deposit): void {
                        $this->creditDeposit($deposit);
                    });
                }
            });
    }

    private function creditDeposit(Deposit $deposit): void
    {
        $settings = PlatformSettings::instance();

        if ($this->belowMinimum($deposit, $settings)) {
            $deposit->update(['status' => 'ignored']);

            return;
        }

        $owner = User::query()->find($deposit->user_id);
        $percent = $owner?->deposit_fee_override ?? $settings->global_deposit_fee_percent;

        $gross = (string) $deposit->gross_amount;
        $fee = $this->calculateFee($gross, (string) $percent);
        $net = bcsub($gross, $fee, 8);

        $balance = Balance::query()->firstOrCreate(
            ['user_id' => $deposit->user_id, 'network' => $deposit->network],
            ['amount' => 0]
        );

        $lockedBalance = Balance::query()
            ->where('id', $balance->id)
            ->lockForUpdate()
            ->first();

        $lockedBalance->amount = bcadd((string) $lockedBalance->amount, $net, 8);
        $lockedBalance->save();

        $deposit->update([
            'status' => 'credited',
            'fee_amount' => $fee,
            'credited_amount' => $net,
            'credited_at' => now(),
        ]);

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

    private function belowMinimum(Deposit $deposit, PlatformSettings $settings): bool
    {
        $column = match ($deposit->network) {
            'bitcoin' => 'min_deposit_bitcoin',
            'usdt_trc20' => 'min_deposit_usdt_trc20',
            'usdt_erc20' => 'min_deposit_usdt_erc20',
            'usdt_base' => 'min_deposit_usdt_erc20',
        };

        $minimum = (string) $settings->{$column};

        return bccomp((string) $deposit->gross_amount, $minimum, 8) < 0;
    }

    private function calculateFee(string $gross, string $percent): string
    {
        return bcmul($gross, bcdiv($percent, '100', 8), 8);
    }
}
