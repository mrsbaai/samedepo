<?php

declare(strict_types=1);

namespace App\Services\Blockchain;

use App\Models\Deposit;
use App\Models\GasExpense;
use App\Models\TreasurySweep;
use App\Models\TreasuryWallet;
use App\Services\Blockchain\Broadcasters\BlockchainBroadcaster;
use Illuminate\Support\Facades\DB;

class TreasurySweepService
{
    public function __construct(
        private readonly BlockchainBroadcaster $broadcaster,
        private ?GasTreasuryService $gasTreasury = null,
    ) {
        $this->gasTreasury ??= new GasTreasuryService($this->broadcaster);
    }

    public function sweep(): void
    {
        Deposit::query()
            ->with('depositAddress')
            ->where('status', 'credited')
            ->whereNull('swept_at')
            ->chunkById(100, function ($deposits): void {
                foreach ($deposits as $deposit) {
                    DB::transaction(function () use ($deposit): void {
                        $this->sweepDeposit($deposit);
                    });
                }
            });
    }

    private function sweepDeposit(Deposit $deposit): void
    {
        $wallet = TreasuryWallet::query()
            ->where('network', $deposit->network)
            ->lockForUpdate()
            ->first();

        if ($wallet === null) {
            return;
        }

        if ($deposit->depositAddress === null) {
            return;
        }

        $sweep = TreasurySweep::query()->firstOrCreate(
            ['deposit_id' => $deposit->id],
            [
                'network' => $deposit->network,
                'amount' => $deposit->gross_amount,
                'status' => 'pending',
            ]
        );

        if ($sweep->status === 'confirmed' || $sweep->status === 'failed') {
            return;
        }

        if ($sweep->tx_hash !== null) {
            $this->pollSweep($sweep, $wallet, $deposit);

            return;
        }

        $tokenNetworks = ['usdt_erc20', 'usdt_trc20'];

        if (in_array($sweep->network, $tokenNetworks, true)) {
            $ready = $this->gasTreasury->ensureGasForSweep(
                $sweep->network,
                (int) $deposit->depositAddress->derivation_index,
                $deposit->depositAddress->address,
            );

            if (! $ready) {
                return;
            }
        }

        $txHash = $this->broadcaster->broadcastSweep($sweep);

        if ($txHash === null) {
            $sweep->update(['error_message' => 'Broadcast failed']);

            return;
        }

        $sweep->update(['tx_hash' => $txHash]);

        $this->pollSweep($sweep, $wallet, $deposit);
    }

    private function pollSweep(TreasurySweep $sweep, TreasuryWallet $wallet, Deposit $deposit): void
    {
        if ($sweep->tx_hash === null) {
            return;
        }

        $receipt = $this->broadcaster->getTransactionReceipt($sweep->network, $sweep->tx_hash);

        if ($receipt === null) {
            return;
        }

        if ($receipt['status'] === 'confirmed') {
            $wallet->available_funds = bcadd((string) $wallet->available_funds, (string) $sweep->amount, 8);
            $wallet->save();

            $sweep->update([
                'status' => 'confirmed',
                'confirmed_at' => now(),
            ]);

            $deposit->update(['swept_at' => now()]);

            GasExpense::create([
                'network' => $sweep->network,
                'tx_hash' => $sweep->tx_hash,
                'amount' => $receipt['fee'] ?? '0.00000000',
                'expensable_type' => TreasurySweep::class,
                'expensable_id' => $sweep->id,
            ]);

            return;
        }

        if ($receipt['status'] === 'failed') {
            $sweep->update([
                'status' => 'failed',
                'error_message' => 'Receipt failed',
            ]);
        }
    }
}
