<?php

declare(strict_types=1);

namespace App\Livewire\PublicPages;

use App\Models\PlatformSettings;
use App\Models\UsdValuation;
use App\Services\Blockchain\Broadcasters\BlockchainBroadcaster;
use App\Services\Blockchain\FeeConverter;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.public', ['title' => 'Withdrawal Fee Calculator', 'description' => 'Estimate the network fee and amount received for a samedepo withdrawal.'])]
class FeeCalculator extends Component
{
    public string $network = 'usdt_trc20';

    public string $amount = '200';

    private const NETWORKS = [
        'bitcoin' => ['label' => 'Bitcoin', 'symbol' => 'BTC', 'decimals' => 8],
        'usdt_trc20' => ['label' => 'USDT (TRC20)', 'symbol' => 'USDT', 'decimals' => 2],
        'usdt_erc20' => ['label' => 'USDT (ERC20)', 'symbol' => 'USDT', 'decimals' => 2],
    ];

    public function updatedNetwork(): void
    {
        if (! isset(self::NETWORKS[$this->network])) {
            $this->network = 'usdt_trc20';
        }
    }

    #[Computed]
    public function networkMeta(): array
    {
        return self::NETWORKS[$this->network];
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
    public function withdrawalMinimumUsd(): string
    {
        return (string) $this->settings()->{'withdrawal_min_usd_'.$this->network};
    }

    #[Computed]
    public function usdPrice(): ?string
    {
        $price = UsdValuation::query()->where('network', $this->network)->value('conversion_value');

        if ($price !== null && bccomp((string) $price, '0', 8) > 0) {
            return (string) $price;
        }

        return $this->network === 'bitcoin' ? null : '1';
    }

    #[Computed]
    public function minimumMessage(): string
    {
        $usd = '$'.number_format((float) $this->withdrawalMinimumUsd(), 2).' USD';
        $native = $this->usdPrice() !== null
            ? ' ('.$this->formatted(bcdiv($this->withdrawalMinimumUsd(), $this->usdPrice(), 8)).' '.$this->networkMeta()['symbol'].')'
            : '';

        return "The minimum withdrawal is {$usd}{$native} for {$this->networkMeta()['label']}.";
    }

    #[Computed]
    public function belowWithdrawalMinimum(): bool
    {
        return $this->usdPrice() !== null
            && bccomp($this->numericAmount(), $this->withdrawalMinimumUsd(), 8) < 0;
    }

    #[Computed]
    public function withdrawalEstimate(): ?array
    {
        // Share the owner/admin cache key so the public calculator and logged-in
        // withdrawal page never show different fee estimates for the same network.
        $nativeFee = Cache::remember(
            'withdraw-fee-estimate:'.$this->network,
            300,
            fn (): string|false => rescue(
                fn () => app(BlockchainBroadcaster::class)->estimateFee($this->network, tokenTransfer: $this->network !== 'bitcoin') ?? false,
                false,
                false,
            ),
        );

        if ($nativeFee === false) {
            return null;
        }

        $networkFeeCrypto = (new FeeConverter)->toNetworkUnits($this->network, (new FeeConverter)->bufferedNativeFee($nativeFee));

        if ($networkFeeCrypto === null || $this->usdPrice() === null) {
            return null;
        }

        $amountCrypto = bcdiv($this->numericAmount(), $this->usdPrice(), 8);

        return [
            'network_fee' => $networkFeeCrypto,
            'receive' => bccomp($amountCrypto, $networkFeeCrypto, 8) >= 0
                ? bcsub($amountCrypto, $networkFeeCrypto, 8)
                : '0.00000000',
        ];
    }

    public function formatted(string $amount): string
    {
        $decimals = $this->networkMeta()['decimals'];

        return number_format((float) bcadd($amount, '0', $decimals), $decimals);
    }

    public function formattedUsd(string $amount): string
    {
        $price = $this->usdPrice();

        if ($price === null) {
            return '$—';
        }

        return '$'.number_format((float) bcadd(bcmul($amount, $price, 8), '0', 2), 2).' USD';
    }

    public function render(): mixed
    {
        return view('livewire.public-pages.fee-calculator');
    }
}
