<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Models\Customer;
use App\Models\Deposit;
use App\Models\UsdValuation;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Blockchain\OwnerFinanceCalculator;
use App\Support\DepositRow;
use App\Support\ExplorerUrl;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.dashboard.layout', ['title' => 'Admin Website Owner Detail'])]
class WebsiteOwnerDetail extends Component
{
    use WithPagination;

    public string $uiState = 'normal';

    public ?User $ownerRecord = null;

    public string $withdrawalMode = 'approval';

    public ?string $feeOverride = null;

    public bool $showModeModal = false;

    public bool $showFeeModal = false;

    public ?string $successMessage = null;

    public array $growth = [];

    public string $withdrawalStatus = 'all';

    public string $tab = 'customers';

    public function mount(int $owner): void
    {
        $this->uiState = request()->query('state', 'normal');

        if ($this->uiState !== 'error') {
            $this->loadOwner($owner);
        }
    }

    private function loadOwner(int $ownerId): void
    {
        $owner = User::query()
            ->where('role', 'owner')
            ->where('is_admin', false)
            ->find($ownerId);

        if ($owner === null) {
            $this->uiState = 'not-found';

            return;
        }

        $this->ownerRecord = $owner;
        $this->withdrawalMode = $owner->withdrawal_mode ?? 'approval';
        $this->feeOverride = $owner->deposit_fee_override !== null ? (string) $owner->deposit_fee_override : null;
        $this->growth = app(OwnerFinanceCalculator::class)->growth($owner);
    }

    #[Computed]
    public function withdrawalModeLabel(): string
    {
        return $this->withdrawalMode === 'instant' ? 'Instant' : 'Administrator Approval';
    }

    #[Computed]
    public function finance(): array
    {
        if ($this->ownerRecord === null) {
            return [
                'customers_total' => 0,
                'customers_new_30d' => 0,
                'deposits_count' => 0,
                'networks' => [],
                'totals' => [
                    'deposit_volume_usd' => '0.00000000',
                    'withdrawn_usd' => '0.00000000',
                    'revenue_usd' => '0.00000000',
                    'sweep_gas_usd' => '0.00000000',
                    'unrecovered_gas_usd' => '0.00000000',
                    'net_usd' => '0.00000000',
                    'owed_usd' => '0.00000000',
                ],
                'rates_available' => false,
            ];
        }

        return app(OwnerFinanceCalculator::class)->summary($this->ownerRecord);
    }

    #[Computed]
    public function customers(): LengthAwarePaginator
    {
        if ($this->ownerRecord === null) {
            /** @var LengthAwarePaginator $empty */
            $empty = Customer::query()->paginate(10, pageName: 'customersPage');

            return $empty;
        }

        $rates = UsdValuation::query()
            ->whereIn('network', array_keys(DepositRow::NETWORKS))
            ->pluck('conversion_value', 'network');

        return Customer::withoutGlobalScope('owner')
            ->where('user_id', $this->ownerRecord->id)
            ->withCount(['deposits as deposits_count' => fn ($query) => $query->where('status', 'credited')])
            ->withMax(['deposits as last_deposit_at' => fn ($query) => $query->where('status', 'credited')], 'credited_at')
            ->orderByDesc('created_at')
            ->paginate(10, pageName: 'customersPage')
            ->through(function (Customer $customer) use ($rates) {
                $usd = Deposit::withoutGlobalScope('owner')
                    ->where('customer_id', $customer->id)
                    ->where('status', 'credited')
                    ->get(['network', 'gross_amount'])
                    ->reduce(
                        fn (string $carry, Deposit $deposit) => bcadd(
                            $carry,
                            bcmul((string) $deposit->gross_amount, (string) ($rates[$deposit->network] ?? '0'), 8),
                            8
                        ),
                        '0.00000000'
                    );

                return [
                    'id' => $customer->id,
                    'reference' => $customer->customer_reference,
                    'since' => $customer->created_at,
                    'deposits' => $customer->deposits_count,
                    'usd' => $usd,
                    'last' => $customer->last_deposit_at ? Carbon::parse($customer->last_deposit_at) : null,
                ];
            });
    }

    #[Computed]
    public function withdrawals(): LengthAwarePaginator
    {
        if ($this->ownerRecord === null) {
            /** @var LengthAwarePaginator $empty */
            $empty = Withdrawal::query()->paginate(10, pageName: 'withdrawalsPage');

            return $empty;
        }

        return app(OwnerFinanceCalculator::class)
            ->withdrawals($this->ownerRecord)
            ->when($this->withdrawalStatus !== 'all', fn ($query) => $query->where('status', $this->withdrawalStatus))
            ->paginate(10, pageName: 'withdrawalsPage')
            ->through(fn ($withdrawal) => [
                'id' => $withdrawal->id,
                'at' => $withdrawal->created_at,
                'network' => DepositRow::NETWORKS[$withdrawal->network] ?? [
                    'label' => $withdrawal->network,
                    'symbol' => '',
                    'decimals' => 8,
                    'slug' => str_replace('_', '-', $withdrawal->network),
                ],
                'gross' => (string) $withdrawal->gross_amount,
                'fee' => $withdrawal->network_fee !== null ? (string) $withdrawal->network_fee : null,
                'sent' => $withdrawal->amount_sent !== null ? (string) $withdrawal->amount_sent : null,
                'status' => $withdrawal->status,
                'txHash' => $withdrawal->tx_hash,
                'explorerUrl' => ExplorerUrl::for('tx', $withdrawal->network, $withdrawal->tx_hash),
            ]);
    }

    public function updatedWithdrawalStatus(): void
    {
        $this->resetPage('withdrawalsPage');
    }

    public function confirmSaveMode(): void
    {
        $this->showModeModal = true;
    }

    public function saveMode(): void
    {
        $validated = $this->validate([
            'withdrawalMode' => ['required', 'in:instant,approval'],
        ]);

        $this->ownerRecord?->update(['withdrawal_mode' => $validated['withdrawalMode']]);

        $this->showModeModal = false;
        $this->successMessage = "Withdrawal mode updated to {$this->withdrawalModeLabel}.";
    }

    public function confirmSaveFee(): void
    {
        $this->showFeeModal = true;
    }

    public function saveFee(): void
    {
        $validated = $this->validate([
            'feeOverride' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $value = $validated['feeOverride'];
        if ($value === null || $value === '' || (float) $value === 0.0) {
            $this->ownerRecord?->update(['deposit_fee_override' => null]);
            $this->feeOverride = null;
            $this->showFeeModal = false;
            $this->successMessage = 'Fee override removed. This owner will use the platform default fee.';

            return;
        }

        $this->ownerRecord?->update(['deposit_fee_override' => $value]);
        $this->showFeeModal = false;
        $this->successMessage = "Deposit fee override set to {$value}%.";
    }

    public function retry(): void
    {
        $this->successMessage = null;
        $this->uiState = request()->query('state', 'normal');

        if ($this->uiState === 'error' || $this->ownerRecord === null) {
            return;
        }

        $this->loadOwner($this->ownerRecord->id);
    }

    public function render(): mixed
    {
        return view('livewire.admin.website-owner-detail', [
            'networkMeta' => DepositRow::NETWORKS,
            'statusColors' => DepositRow::STATUS_COLORS,
        ]);
    }
}
