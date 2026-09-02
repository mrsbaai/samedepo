<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Models\Customer;
use App\Models\Deposit;
use App\Models\UsdValuation;
use App\Models\User;
use App\Support\DepositRow;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.dashboard.layout', ['title' => 'Admin Customer Detail'])]
class CustomerDetail extends Component
{
    use WithPagination;

    public string $uiState = 'normal';

    public ?User $ownerRecord = null;

    public ?Customer $customerRecord = null;

    public function mount(int $owner, string $reference): void
    {
        $this->uiState = request()->query('state', 'normal');

        $this->ownerRecord = User::query()
            ->where('role', 'owner')
            ->where('is_admin', false)
            ->find($owner);

        if ($this->ownerRecord === null) {
            $this->uiState = 'not-found';

            return;
        }

        $this->customerRecord = Customer::withoutGlobalScope('owner')
            ->where('user_id', $this->ownerRecord->id)
            ->where('customer_reference', $reference)
            ->first();

        if ($this->customerRecord === null) {
            $this->uiState = 'not-found';

            return;
        }
    }

    #[Computed]
    public function errorMessage(): ?string
    {
        return $this->uiState === 'error'
            ? "Couldn't load customer details. Please try again."
            : null;
    }

    #[Computed]
    public function addresses(): array
    {
        if ($this->customerRecord === null) {
            return [];
        }

        return $this->customerRecord->depositAddresses()
            ->withoutGlobalScope('owner')
            ->get()
            ->sortBy(fn ($address) => array_search($address->network, array_keys(DepositRow::NETWORKS), true))
            ->values()
            ->map(function ($address) {
                $meta = DepositRow::NETWORKS[$address->network] ?? [
                    'slug' => str_replace('_', '-', $address->network),
                    'label' => $address->network,
                    'symbol' => '',
                    'decimals' => 8,
                ];

                return [
                    'network' => $address->network,
                    'networkSlug' => $meta['slug'],
                    'networkLabel' => $meta['label'],
                    'symbol' => $meta['symbol'],
                    'address' => $address->address,
                ];
            })
            ->all();
    }

    #[Computed]
    public function deposits(): LengthAwarePaginator
    {
        if ($this->customerRecord === null) {
            /** @var LengthAwarePaginator $empty */
            $empty = Deposit::query()->paginate(15);

            return $empty;
        }

        return Deposit::withoutGlobalScope('owner')
            ->where('customer_id', $this->customerRecord->id)
            ->orderByDesc('detected_at')
            ->paginate(15)
            ->through(fn (Deposit $deposit) => DepositRow::present($deposit));
    }

    #[Computed]
    public function stats(): array
    {
        if ($this->customerRecord === null) {
            return ['count' => 0, 'usd' => '0.00000000'];
        }

        $rates = UsdValuation::query()->whereIn('network', array_keys(DepositRow::NETWORKS))->pluck('conversion_value', 'network');

        $deposits = Deposit::withoutGlobalScope('owner')
            ->where('customer_id', $this->customerRecord->id)
            ->where('status', 'credited')
            ->get(['network', 'gross_amount']);

        $totalUsd = '0.00000000';
        foreach ($deposits as $deposit) {
            $rate = (string) ($rates[$deposit->network] ?? '0');
            $totalUsd = bcadd($totalUsd, bcmul((string) $deposit->gross_amount, $rate, 8), 8);
        }

        return [
            'count' => $deposits->count(),
            'usd' => $totalUsd,
        ];
    }

    public function retry(): void
    {
        $this->uiState = request()->query('state', 'normal');
        $this->resetPage();
    }

    public function render(): mixed
    {
        return view('livewire.admin.customer-detail');
    }
}
