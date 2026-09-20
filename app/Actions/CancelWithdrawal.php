<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Balance;
use App\Models\Withdrawal;
use Illuminate\Support\Facades\DB;

final class CancelWithdrawal
{
    public function __invoke(Withdrawal $withdrawal): bool
    {
        if ($withdrawal->status !== 'pending') {
            return false;
        }

        return DB::transaction(function () use ($withdrawal): bool {
            $balance = Balance::query()
                ->withoutGlobalScope('owner')
                ->firstOrCreate(
                    ['user_id' => $withdrawal->user_id, 'network' => $withdrawal->network],
                    ['amount' => 0]
                );

            $balance->update([
                'amount' => bcadd((string) $balance->amount, (string) $withdrawal->gross_amount, 8),
            ]);
            $withdrawal->update(['status' => 'cancelled']);

            return true;
        });
    }
}
