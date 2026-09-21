<?php

declare(strict_types=1);

namespace App\Services\Blockchain;

use App\Events\DepositCredited;
use App\Models\Balance;
use App\Models\Deposit;
use App\Models\LedgerEntry;
use App\Models\PlatformSettings;
use App\Models\UsdValuation;
use App\Models\User;
use App\Support\Network;
use Illuminate\Support\Facades\DB;

class DepositCreditor
{
    public function credit(): void
    {
        Deposit::query()
            ->whereIn('status', ['pending', 'ignored'])
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
        $requiredConfirmations = Network::confirmations($deposit->network);

        if ($deposit->confirmation_count < $requiredConfirmations) {
            return;
        }

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

        $usdRate = UsdValuation::query()->where('network', $deposit->network)->value('conversion_value');

        $deposit->update([
            'status' => 'credited',
            'fee_amount' => $fee,
            'credited_amount' => $net,
            'usd_value' => $usdRate === null ? null : number_format((float) bcmul($net, (string) $usdRate, 8), 2, '.', ''),
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
        $minimum = (string) PlatformSettings::networkSetting($deposit->network)->min_deposit;

        return bccomp((string) $deposit->gross_amount, $minimum, 8) < 0;
    }

    private function calculateFee(string $gross, string $percent): string
    {
        return bcmul($gross, bcdiv($percent, '100', 8), 8);
    }
}
