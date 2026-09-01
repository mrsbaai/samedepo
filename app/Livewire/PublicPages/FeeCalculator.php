<?php

declare(strict_types=1);

namespace App\Livewire\PublicPages;

use App\Models\PlatformSettings;
use App\Models\UsdValuation;
use App\Services\Blockchain\Broadcasters\BlockchainBroadcaster;
use App\Services\Blockchain\FeeConverter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.public', ['title' => 'Fee Calculator', 'description' => 'Calculate samedepo deposit and withdrawal fees.'])]
class FeeCalculator extends Component
{
    public string $network = 'usdt_trc20';

    public string $amount = '200';

    private const NETWORKS = [
        'bitcoin' => ['label' => 'Bitcoin', 'symbol' => 'BTC', 'decimals' => 8],
        'usdt_trc20' => ['label' => 'USDT (TRC20)', 'symbol' => 'USDT', 'decimals' => 2],
        'usdt_erc20' => ['label' => 'USDT (ERC20)', 'symbol' => 'USDT', 'decimals' => 2],
    ];

    #[Computed]
    public function networkMeta(): array
    {
        return self::NETWORKS[$this->network] ?? self::NETWORKS['usdt_trc20'];
    }

    #[Computed]
    public function settings(): PlatformSettings
    {
        return PlatformSettings::instance();
    }

    #[Computed]
    public function numericAmount(): string
    {
        return is_numeric($this->amount) && bccomp($this->amount, '0', 8) >= 0 ? $this->amount : '0';
    }

    #[Computed]
    public function depositFeePercent(): string
    {
        $owner = Auth::user();
        $percent = $owner?->role === 'owner' && $owner->deposit_fee_override !== null
            ? $owner->deposit_fee_override
            : $this->settings()->global_deposit_fee_percent;

        return (string) $percent;
    }

    #[Computed]
    public function depositMinimum(): string
    {
        return (string) $this->settings()->{'min_deposit_'.$this->network};
    }

    #[Computed]
    public function belowDepositMinimum(): bool
    {
        return bccomp($this->numericAmount(), $this->depositMinimum(), 8) < 0;
    }

    #[Computed]
    public function depositResults(): array
    {
        $fee = bcmul($this->numericAmount(), bcdiv($this->depositFeePercent(), '100', 8), 8);

        return ['fee' => $fee, 'credited' => bcsub($this->numericAmount(), $fee, 8)];
    }

    #[Computed]
    public function withdrawalMinimumUsd(): string
    {
        return (string) $this->settings()->{'withdrawal_min_usd_'.$this->network};
    }

    #[Computed]
    public function belowWithdrawalMinimum(): bool
    {
        $price = (string) (UsdValuation::query()->where('network', $this->network)->value('conversion_value') ?? '0');

        return bccomp(bcmul($this->numericAmount(), $price, 8), $this->withdrawalMinimumUsd(), 8) < 0;
    }

    #[Computed]
    public function withdrawalEstimate(): ?array
    {
        try {
            $nativeFee = Cache::remember(
                'withdraw-fee-estimate:'.$this->network,
                300,
                fn (): ?string => app(BlockchainBroadcaster::class)->estimateFee($this->network, tokenTransfer: $this->network !== 'bitcoin'),
            );
            $fee = $nativeFee === null ? null : (new FeeConverter)->toNetworkUnits(
                $this->network,
                (new FeeConverter)->bufferedNativeFee($nativeFee),
            );

            if ($fee === null) {
                return null;
            }

            return [
                'network_fee' => $fee,
                'receive' => bccomp($this->numericAmount(), $fee, 8) >= 0
                    ? bcsub($this->numericAmount(), $fee, 8)
                    : '0.00000000',
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    public function formatted(string $amount): string
    {
        return number_format((float) $amount, $this->networkMeta()['decimals']);
    }

    public function render(): mixed
    {
        return view('livewire.public-pages.fee-calculator');
    }
}
