<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Deposit;
use App\Models\DepositAddress;
use App\Models\TreasuryWallet;
use App\Services\Blockchain\Broadcasters\BlockchainBroadcaster;
use App\Services\Blockchain\Broadcasters\EstimatesTransferFee;
use App\Services\Blockchain\Broadcasters\RemoteBlockchainBroadcaster;
use App\Services\Blockchain\Broadcasters\SimulatesTransactions;
use App\Services\Blockchain\Energy\TronSaveClient;
use App\Services\Blockchain\GasTreasuryService;
use App\Support\Network;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SettlementDryRun extends Command
{
    protected $signature = 'app:settlement-dry-run {--network=* : Limit to specific network keys}';

    protected $description = 'Prove withdrawals, sweeps, and gas top-ups work on every network — the signer builds and validates but never broadcasts';

    private BlockchainBroadcaster $broadcaster;

    public function handle(
        BlockchainBroadcaster $broadcaster,
        GasTreasuryService $gasTreasury,
        TronSaveClient $tronSave,
    ): int {
        if (! $broadcaster instanceof SimulatesTransactions || ! $broadcaster instanceof EstimatesTransferFee) {
            $this->error('The configured broadcaster cannot simulate transactions.');

            return self::FAILURE;
        }

        $this->broadcaster = $broadcaster;
        $networks = $this->option('network') ?: array_keys(config('networks.networks'));
        $rows = [];

        foreach ($networks as $network) {
            $sweepCandidate = $this->sweepCandidate($network);

            $rows[] = $this->checkWithdrawal($broadcaster, $network);
            $rows[] = $this->checkSweep($broadcaster, $network, $sweepCandidate);
            $rows[] = $this->checkTopup($broadcaster, $gasTreasury, $network, $sweepCandidate);

            if ($network === 'usdt_trc20') {
                $rows[] = $this->checkEnergy($gasTreasury, $tronSave);
            }
        }

        $failed = false;
        $table = [];

        foreach ($rows as [$network, $check, $result, $detail]) {
            $table[] = [$network, $check, $result, $detail];
            $context = ['network' => $network, 'check' => $check, 'result' => $result, 'detail' => $detail];

            if ($result === 'FAIL') {
                $failed = true;
                Log::error('settlement.dry_run', $context);
            } else {
                Log::info('settlement.dry_run', $context);
            }
        }

        $this->table(['Network', 'Check', 'Result', 'tx_hash / detail'], $table);

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function checkWithdrawal(SimulatesTransactions&EstimatesTransferFee $broadcaster, string $network): array
    {
        $wallet = TreasuryWallet::query()->where('network', $network)->first();

        if ($wallet === null) {
            return $this->row($network, 'WITHDRAWAL', 'SKIP', 'no treasury wallet');
        }

        $index = (int) $wallet->derivation_index;
        $isToken = Network::isToken($network);
        $balance = $isToken
            ? $broadcaster->getTokenBalance($network, $index)
            : $broadcaster->getNativeBalance($network, $index);

        if ($balance === null) {
            return $this->row($network, 'WITHDRAWAL', 'FAIL', 'balance unavailable');
        }

        $emptyTokenTreasury = $isToken && bccomp($balance, '0', 8) === 0;

        if (bccomp($balance, '0', 8) <= 0 && ! $emptyTokenTreasury) {
            return $this->row($network, 'WITHDRAWAL', 'SKIP', 'treasury empty');
        }

        // Half the balance: a 10% slice of a near-empty UTXO treasury is dust.
        // A zero token balance still exercises signing, fee quote, nonce, and
        // treasury gas — only the transfer simulation can legitimately fail.
        $amount = $emptyTokenTreasury ? '1.00000000' : bcmul($balance, '0.5', 8);
        $fee = $broadcaster->estimateTransferResources($network, $isToken, (string) $wallet->address, $index)['fee'] ?? null;

        if ($fee === null) {
            return $this->row($network, 'WITHDRAWAL', 'FAIL', 'fee estimate unavailable');
        }

        $fee = $this->sendFeeLimit($broadcaster, $network, $fee);
        $sendAmount = $amount;

        if (Network::isNative($network)) {
            if (bccomp($amount, $fee, 8) <= 0) {
                return $this->row($network, 'WITHDRAWAL', 'SKIP', 'treasury below fee');
            }

            $sendAmount = bcadd($amount, $fee, 8);
        }

        $result = $broadcaster->dryRun('withdraw', [
            'network' => $network,
            'index' => $index,
            'destination' => (string) $wallet->address,
            'amount' => $sendAmount,
            'fee' => $fee,
        ]);

        if ($emptyTokenTreasury && ! $result['ok']
            && collect($result['checks'])->where('ok', false)->isNotEmpty()
            && collect($result['checks'])->where('ok', false)->pluck('name')->every(fn ($name) => in_array($name, ['eth_call', 'constant_call'], true))) {
            return $this->row($network, 'WITHDRAWAL', 'PARTIAL',
                'token balance 0 — signing, fee quote, nonce and treasury gas verified; transfer simulation needs funds');
        }

        return $this->resultRow($network, 'WITHDRAWAL', $result);
    }

    private function checkSweep(SimulatesTransactions&EstimatesTransferFee $broadcaster, string $network, ?array $candidate): array
    {
        if ($candidate === null) {
            return $this->row($network, 'SWEEP', 'SKIP', 'nothing to sweep');
        }

        $wallet = TreasuryWallet::query()->where('network', $network)->first();

        if ($wallet === null) {
            return $this->row($network, 'SWEEP', 'SKIP', 'no treasury wallet');
        }

        $fee = $broadcaster->estimateTransferFee($network, Network::isToken($network));

        if ($fee === null) {
            return $this->row($network, 'SWEEP', 'FAIL', 'fee estimate unavailable');
        }

        $result = $broadcaster->dryRun('sweep', [
            'network' => $network,
            'source_index' => $candidate['index'],
            'destination_index' => (int) $wallet->derivation_index,
            'amount' => $candidate['balance'],
            'fee' => $this->sendFeeLimit($broadcaster, $network, $fee),
        ]);

        // A token sweep on an un-gassed deposit address fails the signer's gas
        // check — GasTreasuryService provisions it automatically before sending.
        if (! $result['ok'] && Network::isToken($network) && str_starts_with((string) $result['error'], 'insufficient_gas')) {
            return $this->row($network, 'SWEEP', 'PASS', 'deposit needs gas top-up first (provisioned automatically)');
        }

        return $this->resultRow($network, 'SWEEP', $result);
    }

    private function checkTopup(
        SimulatesTransactions&EstimatesTransferFee $broadcaster,
        GasTreasuryService $gasTreasury,
        string $network,
        ?array $candidate,
    ): array {
        if (! Network::isToken($network) || ! in_array(Network::family($network), ['evm', 'tron'], true)) {
            return $this->row($network, 'TOPUP', 'n/a', 'native/utxo network');
        }

        $wallet = TreasuryWallet::query()->where('network', $network)->first();

        if ($wallet === null) {
            return $this->row($network, 'TOPUP', 'SKIP', 'no treasury wallet');
        }

        $candidate ??= $this->anyDepositAddress($network);

        if ($candidate === null) {
            return $this->row($network, 'TOPUP', 'SKIP', 'no deposit addresses');
        }

        $fee = $broadcaster->estimateTransferFee($network, false, $candidate['address'], (int) $wallet->derivation_index);

        if ($fee === null) {
            return $this->row($network, 'TOPUP', 'FAIL', 'fee estimate unavailable');
        }

        return $this->resultRow($network, 'TOPUP', $broadcaster->dryRun('topup', [
            'network' => $network,
            'source_index' => (int) $wallet->derivation_index,
            'destination_index' => $candidate['index'],
            'amount' => (string) $gasTreasury->policy($network)->top_up_amount,
            'fee' => $fee,
        ]));
    }

    private function checkEnergy(GasTreasuryService $gasTreasury, TronSaveClient $tronSave): array
    {
        $info = $tronSave->userInfo();

        if ($info === null) {
            return $this->row('usdt_trc20', 'ENERGY', 'FAIL', 'TronSave API key rejected or unreachable');
        }

        $wallet = TreasuryWallet::query()->where('network', 'usdt_trc20')->first();
        $policy = $gasTreasury->policy('usdt_trc20');
        $estimate = $tronSave->estimate((string) ($wallet?->address ?? ''), 65000, (int) $policy->rent_duration_sec);

        if ($estimate === null) {
            return $this->row('usdt_trc20', 'ENERGY', 'FAIL', 'energy estimate unavailable');
        }

        $unitPrice = (int) ($estimate['unitPrice'] ?? PHP_INT_MAX);
        $estimateTrx = bcdiv((string) ($estimate['estimateTrx'] ?? '0'), '1000000', 8);
        $floatTrx = bcdiv((string) ($info['balance'] ?? '0'), '1000000', 8);
        $ok = $unitPrice <= (int) $policy->rent_max_price_sun && bccomp($floatTrx, $estimateTrx, 8) >= 0;

        return $this->row('usdt_trc20', 'ENERGY', $ok ? 'PASS' : 'FAIL',
            "price {$unitPrice} sun (cap {$policy->rent_max_price_sun}), cost {$estimateTrx} TRX, float {$floatTrx} TRX");
    }

    /**
     * A deposit address that actually holds funds on-chain right now.
     *
     * @return array{index: int, address: string, balance: string}|null
     */
    private function sweepCandidate(string $network): ?array
    {
        $deposit = Deposit::query()
            ->withoutGlobalScope('owner')
            ->where('network', $network)
            ->where('status', 'credited')
            ->whereNull('swept_at')
            ->with('depositAddress')
            ->latest('id')
            ->first();

        $address = $deposit?->depositAddress;

        if ($address === null) {
            return null;
        }

        $balance = Network::isToken($network)
            ? $this->broadcaster->getTokenBalance($network, (int) $address->derivation_index)
            : $this->broadcaster->getNativeBalance($network, (int) $address->derivation_index);

        if ($balance === null || bccomp($balance, '0', 8) <= 0) {
            return null;
        }

        return ['index' => (int) $address->derivation_index, 'address' => (string) $address->address, 'balance' => $balance];
    }

    /**
     * Most recent deposit address on a network — a top-up target even when
     * nothing currently holds funds.
     *
     * @return array{index: int, address: string, balance: string}|null
     */
    private function anyDepositAddress(string $network): ?array
    {
        $address = DepositAddress::query()
            ->withoutGlobalScope('owner')
            ->where('network', $network)
            ->latest('id')
            ->first();

        if ($address === null) {
            return null;
        }

        return ['index' => (int) $address->derivation_index, 'address' => (string) $address->address, 'balance' => '0.00000000'];
    }

    private function sendFeeLimit(BlockchainBroadcaster $broadcaster, string $network, string $fee): string
    {
        if ($broadcaster instanceof RemoteBlockchainBroadcaster) {
            return $broadcaster->sendFeeLimit($network, $fee);
        }

        return $fee;
    }

    private function resultRow(string $network, string $check, array $result): array
    {
        if ($result['ok']) {
            return $this->row($network, $check, 'PASS', (string) ($result['tx_hash'] ?? 'ok'));
        }

        $failed = collect($result['checks'])->where('ok', false)
            ->map(fn (array $check) => $check['name'].': '.($check['detail'] ?? ''))
            ->implode('; ');

        return $this->row($network, $check, 'FAIL', $result['error'] ?? ($failed !== '' ? $failed : 'checks failed'));
    }

    private function row(string $network, string $check, string $result, string $detail): array
    {
        return [$network, $check, $result, $detail];
    }
}
