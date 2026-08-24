<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Models\Customer;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.dashboard.layout', ['title' => 'Customer Detail'])]
class CustomerDetail extends Component
{
    public string $uiState = 'normal';

    public $customer;

    /**
     * Network metadata keyed by the DB `network` value.
     */
    private const NETWORKS = [
        'bitcoin' => ['slug' => 'bitcoin', 'label' => 'Bitcoin', 'symbol' => 'BTC'],
        'usdt_trc20' => ['slug' => 'usdt-trc20', 'label' => 'USDT (TRC20)', 'symbol' => 'USDT'],
        'usdt_erc20' => ['slug' => 'usdt-erc20', 'label' => 'USDT (ERC20)', 'symbol' => 'USDT'],
    ];

    public function mount(string $customer): void
    {
        $this->uiState = request()->query('state', 'normal');

        try {
            $model = Customer::withoutGlobalScope('owner')->findOrFail($customer);
        } catch (ModelNotFoundException) {
            abort(404);
        }

        if ($model->user_id !== auth()->id()) {
            abort(403, "You don't have access to this website owner's data.");
        }

        $this->customer = $model;
    }

    #[Computed]
    public function errorMessage(): ?string
    {
        if ($this->uiState !== 'error') {
            return null;
        }

        return "Couldn't load customer details. The request to the customer service failed.";
    }

    #[Computed]
    public function addresses(): array
    {
        return $this->customer->depositAddresses
            ->sortBy(fn ($address) => array_search($address->network, array_keys(self::NETWORKS), true))
            ->values()
            ->map(function ($address) {
                $meta = self::NETWORKS[$address->network] ?? ['slug' => str_replace('_', '-', $address->network), 'label' => $address->network, 'symbol' => ''];

                return [
                    'networkSlug' => $meta['slug'],
                    'networkLabel' => $meta['label'],
                    'symbol' => $meta['symbol'],
                    'address' => $address->address,
                ];
            })
            ->all();
    }

    public function retry(): void
    {
        $this->uiState = 'normal';
    }

    public function render(): mixed
    {
        return view('livewire.dashboard.customer-detail');
    }
}
