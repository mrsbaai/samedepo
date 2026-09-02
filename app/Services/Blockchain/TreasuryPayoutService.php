<?php

declare(strict_types=1);

namespace App\Services\Blockchain;

use App\Models\GasExpense;
use App\Models\GasPolicy;
use App\Models\PlatformSettings;
use App\Models\TreasuryPayout;
use App\Models\TreasuryWallet;
use App\Models\UsdValuation;
use App\Services\Blockchain\Broadcasters\BlockchainBroadcaster;
use Illuminate\Support\Facades\DB;

class TreasuryPayoutService
{
    public function __construct(
        private readonly BlockchainBroadcaster $broadcaster,
        private ?TreasuryProfitCalculator $profit = null,
    ) {
        $this->profit ??= new TreasuryProfitCalculator;
    }

    public function preview(string $network, string $amount, ?string $destination = null): array
    {
        $settings = PlatformSettings::instance();
        $savedDestination = $settings->{"profit_address_$network"};
        $withdrawable = $this->profit->forNetwork($network)['withdrawable'];
        $result = [
            'fee_native' => null,
            'fee_usd' => null,
            'amount_usd' => null,
            'fee_percent' => null,
            'level' => 'block',
            'message' => null,
            'withdrawable' => $withdrawable,
            'destination' => $savedDestination,
        ];

        if ($savedDestination === null || $savedDestination === '') {
            return $this->blocked($result, 'No profit payout address saved for this network.');
        }

        if ($destination !== null && $destination !== $savedDestination) {
            return $this->blocked($result, 'Destination does not match the saved profit payout address.');
        }

        if (bccomp($amount, $withdrawable, 8) > 0) {
            return $this->blocked($result, 'Amount exceeds withdrawable profit.');
        }

        $result['fee_native'] = $this->broadcaster->estimateFee($network, $network !== 'bitcoin');

        if ($result['fee_native'] === null) {
            return $this->blocked($result, 'Fee estimate unavailable');
        }

        if ($network === 'bitcoin' && bccomp(bcadd($amount, $result['fee_native'], 8), $withdrawable, 8) > 0) {
            return $this->blocked($result, 'Amount plus network fee exceeds withdrawable profit.');
        }

        if ($network !== 'bitcoin') {
            $wallet = TreasuryWallet::query()->where('network', $network)->first();
            $reserve = (string) (GasPolicy::query()->where('network', $network)->value('reserve_threshold') ?? '0');

            if ($wallet?->native_balance === null || bccomp(bcsub((string) $wallet->native_balance, $result['fee_native'], 8), $reserve, 8) < 0) {
                return $this->blocked($result, 'Gas reserve too low for a payout right now.');
            }
        }

        $nativeKey = match ($network) {
            'usdt_trc20' => 'native_trx',
            'usdt_erc20' => 'native_eth',
            default => 'bitcoin',
        };
        $tokenValue = (string) (UsdValuation::query()->where('network', $network)->value('conversion_value') ?? '0');
        $nativeValue = (string) (UsdValuation::query()->where('network', $nativeKey)->value('conversion_value') ?? '0');

        if (bccomp($tokenValue, '0', 8) <= 0 || bccomp($nativeValue, '0', 8) <= 0) {
            return $this->blocked($result, 'USD prices unavailable — try again in a few minutes.');
        }

        $result['amount_usd'] = bcmul($amount, $tokenValue, 8);
        $result['fee_usd'] = bcmul($result['fee_native'], $nativeValue, 8);
        $result['fee_percent'] = bcmul(bcdiv($result['fee_usd'], $result['amount_usd'], 8), '100', 8);
        $warn = (string) $settings->profit_payout_warn_fee_percent;
        $block = (string) $settings->profit_payout_block_fee_percent;

        if (bccomp($result['fee_percent'], $block, 8) >= 0) {
            $feePercent = number_format((float) $result['fee_percent'], 2);
            $blockPercent = rtrim(rtrim(number_format((float) $block, 2), '0'), '.');

            return $this->blocked($result, "Network fee is {$feePercent}% of the amount — above the {$blockPercent}% limit. Wait for more profit to accumulate.");
        }

        $result['level'] = bccomp($result['fee_percent'], $warn, 8) >= 0 ? 'warn' : 'ok';

        return $result;
    }

    public function send(TreasuryPayout $payout): bool
    {
        if ($payout->status !== 'pending') {
            return false;
        }

        $wallet = TreasuryWallet::query()
            ->where('network', $payout->network)
            ->lockForUpdate()
            ->first();

        if ($wallet === null) {
            $payout->update(['status' => 'failed', 'error_message' => 'No treasury wallet for network']);

            return false;
        }

        $preview = $this->preview($payout->network, (string) $payout->amount, $payout->destination_address);

        if ($preview['level'] === 'block') {
            $payout->update(['status' => 'failed', 'error_message' => $preview['message']]);

            return false;
        }

        if (bccomp((string) $payout->amount, (string) $wallet->available_funds, 8) > 0) {
            $payout->update(['status' => 'failed', 'error_message' => 'Amount exceeds available funds']);

            return false;
        }

        $estimatedFeeNative = $preview['fee_native'];
        $payout->network_fee = $estimatedFeeNative;
        $txHash = $this->broadcaster->broadcastPayout($payout);

        if ($txHash === null) {
            $payout->update([
                'status' => 'failed',
                'error_message' => 'Broadcast failed',
                'network_fee' => $estimatedFeeNative,
            ]);

            return false;
        }

        DB::transaction(function () use ($payout, $wallet, $txHash, $estimatedFeeNative): void {
            $treasurySpend = $payout->network === 'bitcoin'
                ? bcadd((string) $payout->amount, $estimatedFeeNative, 8)
                : (string) $payout->amount;
            $wallet->available_funds = bcsub((string) $wallet->available_funds, $treasurySpend, 8);
            $wallet->save();

            $payout->update([
                'status' => 'sent',
                'tx_hash' => $txHash,
                'network_fee' => $estimatedFeeNative,
                'sent_at' => now(),
            ]);
        });

        return true;
    }

    public function poll(): void
    {
        TreasuryPayout::query()
            ->where('status', 'sent')
            ->chunkById(100, function ($payouts): void {
                foreach ($payouts as $payout) {
                    $receipt = $this->broadcaster->getTransactionReceipt(
                        $payout->network,
                        $payout->tx_hash,
                    );

                    if ($receipt === null) {
                        continue;
                    }

                    if ($receipt['status'] === 'confirmed') {
                        $payout->update([
                            'status' => 'confirmed',
                            'confirmed_at' => now(),
                        ]);

                        GasExpense::create([
                            'network' => $payout->network,
                            'tx_hash' => $payout->tx_hash,
                            'amount' => $receipt['fee'] ?? '0.00000000',
                            'expensable_type' => TreasuryPayout::class,
                            'expensable_id' => $payout->id,
                        ]);

                        continue;
                    }

                    if ($receipt['status'] === 'failed') {
                        $payout->update([
                            'status' => 'failed',
                            'error_message' => 'Receipt failed',
                        ]);
                    }
                }
            });
    }

    private function blocked(array $result, string $message): array
    {
        $result['level'] = 'block';
        $result['message'] = $message;

        return $result;
    }
}
